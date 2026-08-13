<?php

declare(strict_types=1);

namespace SugarCraft\Crush;

use SugarCraft\Crush\App\App;
use SugarCraft\Crush\Context\EnvironmentBlock;
use SugarCraft\Crush\Events\ToolFinished;
use SugarCraft\Crush\Events\ToolStarted;
use SugarCraft\Crush\Providers\ProviderInterface;
use SugarCraft\Crush\Providers\CompleteRequest;
use SugarCraft\Crush\Messages\Message;
use SugarCraft\Crush\Messages\AssistantMessage;
use SugarCraft\Crush\Messages\UserMessage;
use SugarCraft\Crush\Messages\ToolResultMessage;
use SugarCraft\Crush\Support\ForkedChild;
use SugarCraft\Crush\Support\ToolIpcFiles;
use SugarCraft\Crush\Tools\CarriesSessionState;
use SugarCraft\Crush\Tools\ParallelSafe;
use SugarCraft\Crush\Tools\Tool;
use SugarCraft\Crush\Tools\ToolCall;
use SugarCraft\Crush\Tools\ToolResult;
use SugarCraft\Crush\Skills\SkillMatcher;
use SugarCraft\Crush\Hooks\HookManager;
use SugarCraft\Crush\Hooks\HookContext;
use SugarCraft\Crush\Hooks\HookResult;

final class Runtime
{
    /**
     * Wall-clock budget for ONE concurrent group in
     * {@see executeConcurrently()}. Past it every child still running is
     * SIGKILLed and reported as a timed-out call.
     *
     * Deliberately under {@see \SugarCraft\Crush\Backend\EngineBackend::COMPLETE_TIMEOUT_SECONDS}
     * (120s of silence): no frame reaches the parent while a group is
     * executing, so a group allowed to outlive that ceiling would have the
     * whole turn SIGKILLed from above instead of the one stuck call being
     * reported as a failure with every sibling's result intact.
     *
     * Public because {@see \SugarCraft\Crush\Backend\EngineBackend} both hands
     * this class its configured deadline and falls back to this value when the
     * operator's is missing or nonsense — one default, named once.
     */
    public const PARALLEL_TOOL_DEADLINE_SECONDS = 90;

    /**
     * Poll interval while waiting on a concurrent group. This loop is inside
     * the forked completion child (or, on the no-fork fallback path, inside a
     * call the caller already treats as blocking), so it is sleeping on
     * nobody's event loop — 2ms just keeps a short group from burning a core.
     */
    private const PARALLEL_TOOL_POLL_MICROSECONDS = 2_000;

    /**
     * Bounded WNOHANG budget for reaping a child we have already SIGKILLed —
     * 20 x 5ms, mirroring {@see \SugarCraft\Crush\Backend\EngineBackend::reapChild()}.
     * A killed child is reaped on the first attempt; the ceiling exists only
     * so a build without ext-posix (nothing to kill with) costs one leaked
     * zombie instead of a permanently wedged turn.
     */
    private const REAP_ATTEMPTS = 20;

    private const REAP_POLL_MICROSECONDS = 5_000;

    /**
     * @param ?EnvironmentBlock $environmentBlock Pre-captured session snapshot; when omitted
     *                                            one is captured lazily on first use and
     *                                            reused for the life of this Runtime.
     * @param bool $parallelToolCalls Whether a same-turn batch may run its
     *                                {@see \SugarCraft\Crush\Tools\ParallelSafe}
     *                                calls concurrently. False forces the
     *                                strictly sequential dispatch this class
     *                                had before crush_code.md Phase 0 item 14 —
     *                                an escape hatch, not a default. Reached
     *                                from a real run through
     *                                `$SUGARCRUSH_DISABLE_PARALLEL_TOOL_CALLS`
     *                                / the `parallelToolCalls` key of
     *                                ~/.sugar-crush/config.json; see
     *                                {@see \SugarCraft\Crush\Backend\EngineBackend::parallelToolCallsEnabled()}.
     * @param int  $parallelToolDeadlineSeconds see {@see PARALLEL_TOOL_DEADLINE_SECONDS};
     *                                configured by `$SUGARCRUSH_PARALLEL_TOOL_DEADLINE`
     *                                / the `parallelToolDeadlineSeconds`
     *                                config key, validated in
     *                                {@see \SugarCraft\Crush\Backend\EngineBackend::parallelToolDeadlineSeconds()}
     */
    public function __construct(
        private ProviderInterface $provider,
        private HookManager $hookManager,
        private ?EnvironmentBlock $environmentBlock = null,
        private bool $parallelToolCalls = true,
        private int $parallelToolDeadlineSeconds = self::PARALLEL_TOOL_DEADLINE_SECONDS,
    ) {}

    /**
     * Run a completion and handle tool calls.
     *
     * @param ?callable $onEvent Optional tool-lifecycle observer, signature
     *                           `function(ToolStarted|ToolFinished $event): void`.
     *                           Mirrors the `$onToken` plumbing the streaming
     *                           text path already has: the engine's tool calls
     *                           are otherwise invisible to whoever drives it
     *                           (crush_feat.md §1 E1), because only the final
     *                           assistant message survives back out of
     *                           {@see \SugarCraft\Crush\Backend\EngineBackend::complete()}.
     *
     * @param ?callable $onPermissionRequest Optional approver for a
     *                           {@see HookResult::ask()}
     *                           decision, signature
     *                           `function(ToolCall $call, HookResult $ask): bool`
     *                           returning true to permit the call. An ASK is a
     *                           hook deferring to the user (crush_feat.md §1 E2),
     *                           so it needs an owner with a UI; without one this
     *                           Runtime fails the call closed rather than
     *                           guessing. See {@see settleAsk()}.
     *
     * @param ?callable $onToken Optional incremental-text observer, signature
     *                           `function(string $delta): void`, called with
     *                           each fragment of assistant text the moment it
     *                           is parsed off the wire.
     *
     *                           This is what makes streaming real rather than
     *                           merely parsed (crush_code.md Phase 0 item 13):
     *                           {@see runStreaming()} decoded the provider's
     *                           SSE correctly and then re-buffered the WHOLE
     *                           response before yielding a single
     *                           {@see AssistantMessage}, so the caller — and
     *                           through it the TUI — saw the same one-shot
     *                           delivery it would have seen with streaming
     *                           switched off, having paid the full parsing
     *                           cost for nothing.
     *
     *                           Deltas, not a running total: consumers append.
     *                           {@see runBatch()} emits the whole content as
     *                           one delta so a consumer never has to ask
     *                           whether the provider streams.
     *
     * @return \Generator yields CompleteResponse chunks
     */
    public function run(App $app, ?callable $onEvent = null, ?callable $onPermissionRequest = null, ?callable $onToken = null): \Generator
    {
        $messages = $this->buildMessages($app);

        $systemPrompt = $this->buildSystemPrompt($app);

        $request = new CompleteRequest(
            model: $app->model,
            messages: $messages,
            tools: $app->tools ?: null,
            systemPrompt: $systemPrompt,
        );

        // foreach-reyield instead of `yield from`: `yield from` preserves
        // each inner generator's 0-based keys, so the assistant message
        // (key 0) and the first tool-result message (key 0) collide and get
        // collapsed by iterator_to_array(). Re-yielding lets this outer
        // generator hand out fresh sequential keys.
        $inner = $this->provider->supportsStreaming()
            ? $this->runStreaming($request, $app, $onEvent, $onPermissionRequest, $onToken)
            : $this->runBatch($request, $app, $onEvent, $onPermissionRequest, $onToken);

        foreach ($inner as $msg) {
            yield $msg;
        }
    }

    private function runStreaming(CompleteRequest $request, App $app, ?callable $onEvent = null, ?callable $onPermissionRequest = null, ?callable $onToken = null): \Generator
    {
        $buffer = '';
        $toolCalls = [];
        $reasoning = null;

        // Accumulate the whole stream and emit one assistant message when the
        // generator is exhausted. We deliberately do NOT use a tokensUsed>0
        // sentinel to detect completion — real providers stream content with
        // tokensUsed=0 and only report totals at the end (if at all), so a
        // sentinel drops the entire message in production.
        //
        // The buffer stays even now that $onToken forwards each chunk live:
        // the AssistantMessage below is what the agentic loop feeds back to
        // the model on the next step and what lands in the transcript, and
        // that has to be the WHOLE turn. $onToken is an additional live
        // observer of the same bytes, not a replacement for assembling them.
        foreach ($this->provider->completeStream($request) as $response) {
            $buffer .= $response->content;
            // Forwarded before the tool-call/reasoning bookkeeping below so a
            // chunk carrying both text and the start of a tool call still
            // reaches the screen as text first, in wire order.
            if ($onToken !== null && $response->content !== '') {
                $onToken($response->content);
            }
            if ($response->toolCalls !== null) {
                $toolCalls = array_merge($toolCalls, $response->toolCalls);
            }
            if ($response->reasoning !== null && $response->reasoning !== '') {
                $reasoning = ($reasoning ?? '') . $response->reasoning;
            }
        }

        yield new AssistantMessage($buffer, $toolCalls ?: null, $reasoning);

        if ($toolCalls !== []) {
            foreach ($this->executeToolCalls($toolCalls, $app, $onEvent, $onPermissionRequest) as $msg) {
                yield $msg;
            }
        }
    }

    private function runBatch(CompleteRequest $request, App $app, ?callable $onEvent = null, ?callable $onPermissionRequest = null, ?callable $onToken = null): \Generator
    {
        $response = $this->provider->complete($request);

        // One delta carrying the whole reply. A non-streaming provider has no
        // incremental bytes to offer, but the $onToken contract is uniform on
        // purpose: without this the consumer would need its own
        // supportsStreaming() check to know whether to expect any deltas at
        // all, and would silently render nothing for a batch provider.
        if ($onToken !== null && $response->content !== '') {
            $onToken($response->content);
        }

        yield new AssistantMessage(
            $response->content,
            $response->toolCalls,
            $response->reasoning,
        );

        if ($response->toolCalls !== null && $response->toolCalls !== []) {
            foreach ($this->executeToolCalls($response->toolCalls, $app, $onEvent, $onPermissionRequest) as $msg) {
                yield $msg;
            }
        }
    }

    /**
     * Execute one same-turn batch of tool calls and yield their results.
     *
     * The batch is cut into SEGMENTS (see {@see segments()}): a maximal run of
     * {@see \SugarCraft\Crush\Tools\ParallelSafe} calls becomes one concurrent
     * group, and every other call is a barrier executed alone, in place, by
     * exactly the sequential code path this method has always used. That is
     * the whole concurrency-safety rule (crush_code.md Phase 0 item 14), and
     * it buys three guarantees that make it safe to reason about:
     *
     *   - No two mutating calls ever overlap, so two `Edit`s of one file, or
     *     an `Edit` racing a `Read` of the same path, cannot happen.
     *   - A barrier is ordered against BOTH neighbours, so read-after-write
     *     and write-after-read within a turn keep their sequential meaning.
     *   - Everything that CAN overlap is non-mutating by construction, so the
     *     interleaving is unobservable in the results.
     *
     * Whatever the segmentation, results are yielded in the order the provider
     * requested them — the model correlates by id, but a batch replayed in
     * completion order would make the transcript (and every replay of it)
     * nondeterministic for no gain.
     *
     * A run of ONE parallel-safe call is executed sequentially too: forking to
     * run a single call concurrently with nothing is pure cost, and it keeps
     * the overwhelmingly common single-call turn on the identical code path it
     * has always used.
     *
     * @param array<ToolCall> $toolCalls
     * @param ?callable       $onEvent see {@see run()} — every call emits one
     *                                 {@see ToolStarted} and exactly one
     *                                 {@see ToolFinished}, including the
     *                                 unknown-tool and hook-denied branches.
     * @param ?callable       $onPermissionRequest see {@see run()}.
     */
    private function executeToolCalls(
        array $toolCalls,
        App $app,
        ?callable $onEvent = null,
        ?callable $onPermissionRequest = null,
    ): \Generator {
        foreach ($this->segments($toolCalls, $app) as $segment) {
            if (count($segment) === 1) {
                yield $this->executeSequentially($segment[0], $app, $onEvent, $onPermissionRequest);

                continue;
            }

            foreach ($this->executeConcurrently($segment, $app, $onEvent, $onPermissionRequest) as $message) {
                yield $message;
            }
        }
    }

    /**
     * Cut a batch into concurrent groups and barriers — see
     * {@see executeToolCalls()} for the rule and why it is drawn here.
     *
     * @param array<ToolCall> $toolCalls
     * @return list<list<ToolCall>>
     */
    private function segments(array $toolCalls, App $app): array
    {
        $segments = [];
        $group = [];

        foreach ($toolCalls as $toolCall) {
            if ($this->runsConcurrently($toolCall, $app)) {
                $group[] = $toolCall;

                continue;
            }

            if ($group !== []) {
                $segments[] = $group;
                $group = [];
            }
            $segments[] = [$toolCall];
        }

        if ($group !== []) {
            $segments[] = $group;
        }

        return $segments;
    }

    /**
     * Whether THIS call may join a concurrent group.
     *
     * Opt-in and per-instance: a tool that does not implement
     * {@see \SugarCraft\Crush\Tools\ParallelSafe} — every user-supplied tool,
     * every `mcp__*` tool, `Bash`, `Edit` — is a barrier. An unknown tool name
     * is a barrier too, so its "Tool not found" failure keeps being produced
     * by the same branch that has always produced it.
     */
    private function runsConcurrently(ToolCall $toolCall, App $app): bool
    {
        if (!$this->parallelToolCalls || !self::canFork()) {
            return false;
        }

        $tool = $this->findTool($toolCall->name(), $app);

        return $tool instanceof ParallelSafe && $tool->isParallelSafe();
    }

    /**
     * One tool call, start to finish, in this process — the dispatch this
     * class did for every call before concurrency existed, and still the only
     * dispatch a barrier call ever sees.
     */
    private function executeSequentially(
        ToolCall $toolCall,
        App $app,
        ?callable $onEvent,
        ?callable $onPermissionRequest,
    ): ToolResultMessage {
        $this->emit($onEvent, ToolStarted::fromCall($toolCall));

        // Find the tool
        $tool = $this->findTool($toolCall->name(), $app);
        if ($tool === null) {
            return $this->failure($toolCall, "Tool not found: {$toolCall->name()}", $onEvent);
        }

        $context = $this->hookContext($toolCall, $tool, $app);

        [$args, $denial] = $this->gate($toolCall, $context, $onPermissionRequest);
        if ($denial !== null) {
            return $this->failure($toolCall, $denial, $onEvent);
        }

        // A throwing tool must cost its own call, not the whole turn.
        // Without this catch the \Throwable escapes this generator, out
        // through Runtime::run(), and is only stopped by
        // EngineBackend::runCompleteInChild()'s outer boundary — which
        // reports a turn-level failure and discards every OTHER tool
        // result plus all assistant content already produced. A model
        // handing Bash a non-string `command` (TypeError out of
        // escapeshellarg()) is enough to trigger it.
        //
        // Scope, precisely: everything from here to the yield in
        // executeToolCalls() is contained — the tool body, the PostToolUse
        // hook chain, and the ToolFinished emit — each degrading to an
        // annotated result for THIS call. What is NOT contained is anything
        // before it (the PreToolUse chain and settleAsk, which decide whether
        // the call happens at all and so have nothing to degrade to) and the
        // yield itself (a consumer throwing back into the generator is the
        // consumer ending the turn). This is strictly wider than
        // Chat::invokeTool(), which guards only the tool body.
        try {
            $result = $tool->execute($args ?? []);
        } catch (\Throwable $e) {
            $result = self::executionFailure($tool, $toolCall, $e);
        }

        return $this->settle($toolCall, $context, $result, $onEvent);
    }

    /**
     * Execute a group of {@see \SugarCraft\Crush\Tools\ParallelSafe} calls
     * concurrently, one forked child per call, and yield their results in
     * PROVIDER order.
     *
     * Three strictly ordered phases, and the order is the point:
     *
     *  1. Gate every member, in provider order, in THIS process, before a
     *     single child exists. Hook gating must not become a race: a DENY, and
     *     above all an ASK that suspends the batch waiting on a human, has to
     *     be decided while there is still nothing running to bypass it. The
     *     same reason {@see \SugarCraft\Crush\Chat::forkToolCalls()} receives
     *     an already-gated batch. It is also what keeps an ASK working once
     *     the permission prompt becomes a blocking UI: $onPermissionRequest
     *     blocks here, with zero children alive, so a slow answer delays the
     *     group instead of racing it.
     *
     *  2. Fan out. A child that cannot be forked degrades to running its call
     *     in-process, right there — the group just gets narrower.
     *
     *  3. Reap non-blockingly and release results in provider order, letting a
     *     finished prefix through as soon as it is complete rather than
     *     holding the whole group hostage to its slowest member. PostToolUse
     *     therefore runs in provider order too, in the parent, exactly as it
     *     does sequentially — hooks accumulate state and an order that
     *     depended on which `Read` won a race would be untestable.
     *
     * Group width is deliberately UNCAPPED — one child per call, however many
     * the provider asked for. A slot-limited scheduler was considered and
     * rejected: this group carries ONE wall-clock budget, so a call held in a
     * queue would spend that budget waiting and could be killed at the
     * deadline without ever having run, which a cap would have to fix by
     * giving every call its own deadline. That is a materially different
     * design, bought against a pressure the OS already reports honestly — a
     * fork past the process limit returns -1 and that call degrades to running
     * in-process, right where the fan-out loop stands. An operator who does
     * hit trouble has the whole-feature switch (see the constructor's
     * $parallelToolCalls) rather than a width knob nobody can tune blind.
     *
     * Why not reuse {@see \SugarCraft\Crush\Chat::waitForToolChildrenAsync()}:
     * it collects through `Loop::get()` periodic timers and returns a promise.
     * This code runs inside
     * {@see \SugarCraft\Crush\Backend\EngineBackend::runCompleteInChild()}'s
     * forked child, which has no running event loop — only an inherited COPY
     * of the parent TUI's, complete with its stdin read-stream and timers.
     * Driving that here would mean two processes servicing one terminal. The
     * blocking WNOHANG poll below is the correct shape for a child that is
     * already off the parent's loop by construction.
     *
     * @param list<ToolCall> $toolCalls at least two, all parallel-safe
     */
    private function executeConcurrently(
        array $toolCalls,
        App $app,
        ?callable $onEvent,
        ?callable $onPermissionRequest,
    ): \Generator {
        $jobs = [];

        // Phase 1 — gate the whole group before anything is forked.
        foreach ($toolCalls as $toolCall) {
            $this->emit($onEvent, ToolStarted::fromCall($toolCall));

            // Non-null by construction: segments() only groups calls whose
            // tool it resolved AND found parallel-safe. Re-checked rather than
            // asserted because the alternative to a wrong assumption here is a
            // TypeError escaping into EngineBackend's turn-level boundary,
            // which would discard every sibling result.
            $tool = $this->findTool($toolCall->name(), $app);
            if ($tool === null) {
                $jobs[] = [
                    'call' => $toolCall,
                    'tool' => null,
                    'context' => null,
                    'args' => [],
                    'denied' => "Tool not found: {$toolCall->name()}",
                    'pid' => null,
                    'file' => null,
                    'result' => null,
                    'settled' => true,
                ];

                continue;
            }

            $context = $this->hookContext($toolCall, $tool, $app);
            [$args, $denial] = $this->gate($toolCall, $context, $onPermissionRequest);

            $jobs[] = [
                'call' => $toolCall,
                'tool' => $tool,
                'context' => $context,
                'args' => $args ?? [],
                'denied' => $denial,
                'pid' => null,
                'file' => null,
                'result' => null,
                'settled' => $denial !== null,
            ];
        }

        // Phase 2 — fan out.
        foreach ($jobs as $index => $job) {
            if ($job['settled']) {
                continue;
            }

            $file = ToolIpcFiles::reserve(ToolIpcFiles::RUNTIME_PREFIX, 'bin');
            $pid = pcntl_fork();

            if ($pid === -1) {
                // This call only: run it here, same as the no-pcntl path.
                $jobs[$index]['result'] = $this->executeGuarded($job['tool'], $job['call'], $job['args']);
                $jobs[$index]['settled'] = true;

                continue;
            }

            if ($pid === 0) {
                $this->runToolInChild($file, $job['tool'], $job['call'], $job['args']);
            }

            $jobs[$index]['pid'] = $pid;
            $jobs[$index]['file'] = $file;
        }

        // Phase 3 — reap, then release in provider order.
        $deadline = microtime(true) + $this->parallelToolDeadlineSeconds;
        $total = count($jobs);
        $next = 0;

        while ($next < $total) {
            foreach ($jobs as $index => $job) {
                if ($job['settled'] || $job['pid'] === null) {
                    continue;
                }
                $status = 0;
                // Only ever our own pids, never waitpid(-1): Chat's own tool
                // children and BackgroundSessionRunner's workers live in this
                // same process tree and check the pid they get back, so a
                // blind sweep would steal their exit statuses.
                if (pcntl_waitpid($job['pid'], $status, WNOHANG) === $job['pid']) {
                    $jobs[$index]['settled'] = true;
                }
            }

            $released = false;
            while ($next < $total && $jobs[$next]['settled']) {
                yield $this->release($jobs[$next], $onEvent);
                $next++;
                $released = true;
            }

            if ($next >= $total) {
                break;
            }

            if (microtime(true) >= $deadline) {
                foreach ($jobs as $index => $job) {
                    if ($job['settled'] || $job['pid'] === null) {
                        continue;
                    }
                    // A tool that never returns would otherwise wedge the turn
                    // here. It is killed and reported as a failed call; its
                    // siblings' results survive intact.
                    if (function_exists('posix_kill')) {
                        posix_kill($job['pid'], SIGKILL);
                    }
                    self::reapKilled($job['pid']);
                    $jobs[$index]['settled'] = true;
                }

                continue;
            }

            if (!$released) {
                usleep(self::PARALLEL_TOOL_POLL_MICROSECONDS);
            }
        }
    }

    /**
     * Run the PreToolUse chain for one call and report either the arguments to
     * execute it with or the reason it must not run.
     *
     * Identical decisions in both dispatch paths, which is the point of it
     * being one method: only a true DENY blocks (a MODIFY is "allowed, with
     * rewritten input", and isAllowed() is false for it too), and an ASK is
     * not a verdict — it is the hook deferring to the user (crush_feat.md §1
     * E2), settled by whoever owns a UI that can put the question and failing
     * CLOSED when nobody does. This method BLOCKS on that answer, which is
     * what keeps an asking hook meaningful once the prompt becomes real UI:
     * in a concurrent group it is called during phase 1, before any child
     * exists, so nothing can run past a question that has not been answered.
     *
     * @return array{0: ?array<string, mixed>, 1: ?string} [arguments, denial reason]
     */
    private function gate(ToolCall $toolCall, HookContext $context, ?callable $onPermissionRequest): array
    {
        $hookResult = $this->hookManager->preToolUse($context);

        if ($hookResult->isAsk()) {
            $hookResult = $this->settleAsk($toolCall, $hookResult, $onPermissionRequest);
        }

        if (!$hookResult->isAllowed() && !$hookResult->isModified()) {
            return [null, "Hook denied: {$hookResult->message}"];
        }

        // A MODIFY hook rewrites the tool input before execution.
        return [
            $hookResult->isModified()
                ? (json_decode($hookResult->modifiedInput ?? '', true) ?? $toolCall->arguments())
                : $toolCall->arguments(),
            null,
        ];
    }

    /**
     * Turn one settled job into its result message: collect whatever the child
     * produced, run PostToolUse, emit {@see ToolFinished}.
     *
     * @param array<string, mixed> $job
     */
    private function release(array $job, ?callable $onEvent): ToolResultMessage
    {
        if ($job['denied'] !== null) {
            return $this->failure($job['call'], (string) $job['denied'], $onEvent);
        }

        $result = $job['result'] ?? $this->collectChildResult($job);

        return $this->settle($job['call'], $job['context'], $result, $onEvent);
    }

    /**
     * The tail every executed call shares: PostToolUse, {@see ToolFinished},
     * and the {@see ToolResultMessage} the model sees.
     */
    private function settle(
        ToolCall $toolCall,
        HookContext $context,
        ToolResult $result,
        ?callable $onEvent,
    ): ToolResultMessage {
        // Post-hook observes the tool output. HookRegistry::executeHooks()
        // calls $hook->execute() bare, so a ScriptHook whose script is
        // missing, or a PHP hook with a bug, throws straight through — and
        // a hook is OBSERVABILITY, not the answer. The tool already ran
        // and its output is valid, so the failure is reported alongside
        // that output rather than replacing it or discarding the turn.
        try {
            $this->hookManager->postToolUse($context->withToolOutput($result->content()));
        } catch (\Throwable $e) {
            $result = self::annotate($result, sprintf(
                '[PostToolUse hook failed: %s: %s]',
                $e::class,
                $e->getMessage(),
            ));
        }

        // A listener that throws is a UI bug. It must not take the turn's
        // other tool results down with it, and the model still needs this
        // result regardless of whether anything managed to render it.
        try {
            $this->emit($onEvent, ToolFinished::fromResult($toolCall, $result));
        } catch (\Throwable $e) {
            $result = self::annotate($result, sprintf(
                '[ToolFinished listener failed: %s: %s]',
                $e::class,
                $e->getMessage(),
            ));
        }

        // Echo the ORIGINAL tool-call id: the model correlates a result
        // to its request by this id, and the tool itself never sees it.
        // imageBytes/imageProtocol thread an image-bearing ToolResult
        // (e.g. Doctor's capability swatch) through to EngineBackend
        // (W1.G2 reachability fix) instead of being dropped here.
        return new ToolResultMessage(
            $toolCall->id(),
            $result->content(),
            $result->isError(),
            $result->imageBytes(),
            $result->imageProtocol(),
        );
    }

    /**
     * The forked child's half of one concurrent tool call: run it, write the
     * outcome, exit. Never returns.
     *
     * The same throwing-tool guarantee the sequential path gives, enforced on
     * the far side of the fork: a tool that throws produces this call's error
     * result and nothing else. A child that dies without writing at all
     * (fatal error, OOM, SIGKILL) is caught by {@see collectChildResult()}
     * instead, so the failure is still confined to its own call.
     *
     * The payload is written 0600 and renamed into place — see
     * {@see ToolIpcFiles::write()} for both halves of why (the mode, and the
     * atomicity a SIGKILL mid-write would otherwise cost).
     *
     * @param array<string, mixed> $args
     */
    private function runToolInChild(string $file, Tool $tool, ToolCall $toolCall, array $args): never
    {
        $result = $this->executeGuarded($tool, $toolCall, $args);

        $payload = [
            'result' => self::encodeResult($result),
            // Announce-once marks the tool set while running in here would
            // otherwise die with this process — see Tools\CarriesSessionState.
            'state' => $tool instanceof CarriesSessionState ? $tool->exportSessionState() : null,
        ];

        ToolIpcFiles::write($file, serialize($payload));

        ForkedChild::exitNow(0);
    }

    /**
     * Read back one child's payload, merge any session state it accumulated
     * into THIS process's tool instance, and reconstruct the result.
     *
     * A missing or unparseable payload means the child was killed at the
     * deadline or died before finishing — reported as this call's error, never
     * silently dropped.
     *
     * @param array<string, mixed> $job
     */
    private function collectChildResult(array $job): ToolResult
    {
        $file = (string) $job['file'];
        $raw = is_file($file) ? @file_get_contents($file) : false;
        ToolIpcFiles::discard($file);

        // allowed_classes => false: this payload crossed a process boundary,
        // so decoding it must never be able to instantiate anything (same rule
        // EngineBackend::drainFrames() follows).
        $decoded = ($raw === false || $raw === '')
            ? false
            : @unserialize($raw, ['allowed_classes' => false]);

        if (!is_array($decoded) || !is_array($decoded['result'] ?? null)) {
            return new ToolResult(
                toolCallId: $job['call']->id(),
                content: sprintf(
                    'Error: %s produced no result: killed at the %ds parallel-tool deadline, or it died before finishing',
                    $job['call']->name(),
                    $this->parallelToolDeadlineSeconds,
                ),
                isError: true,
            );
        }

        $result = self::decodeResult($decoded['result'], $job['call']);

        if (is_array($decoded['state'] ?? null) && $job['tool'] instanceof CarriesSessionState) {
            // Guarded for the same reason settle() guards PostToolUse and the
            // ToolFinished listener: a merge is BOOKKEEPING, not the answer.
            // CarriesSessionState's contract says an unknown or malformed key
            // must never be fatal, but nothing enforces that caller-side, and
            // an escaping \Throwable here does far more than lose one mark —
            // it aborts this generator mid-group, so the children after this
            // one are never reaped and their payloads never unlinked, and it
            // lands in EngineBackend::runCompleteInChild()'s turn-level
            // boundary, which discards every sibling result. A failed merge
            // costs exactly this tool's announce-once mark: the WORST case is
            // that a nested CLAUDE.md is emitted a second time later in the
            // session.
            try {
                $job['tool']->mergeSessionState($decoded['state']);
            } catch (\Throwable $e) {
                $result = self::annotate($result, sprintf(
                    '[Session-state merge failed: %s: %s]',
                    $e::class,
                    $e->getMessage(),
                ));
            }
        }

        return $result;
    }

    /**
     * {@see Tool::execute()} with the throwing-tool guarantee applied — the
     * one place that turns a \Throwable into this call's own error result, so
     * the in-process and forked paths cannot word it differently.
     *
     * @param array<string, mixed> $args
     */
    private function executeGuarded(Tool $tool, ToolCall $toolCall, array $args): ToolResult
    {
        try {
            return $tool->execute($args);
        } catch (\Throwable $e) {
            return self::executionFailure($tool, $toolCall, $e);
        }
    }

    private static function executionFailure(Tool $tool, ToolCall $toolCall, \Throwable $e): ToolResult
    {
        return new ToolResult(
            toolCallId: $toolCall->id(),
            content: sprintf(
                'Error: %s failed with %s: %s',
                $tool->name(),
                $e::class,
                $e->getMessage(),
            ),
            isError: true,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private static function encodeResult(ToolResult $result): array
    {
        // Flattened to plain scalars rather than serializing the object: the
        // parent decodes with allowed_classes => false. serialize() (unlike
        // JSON) round-trips raw binary natively, so imageBytes needs no
        // base64 step the way Chat's JSON-over-temp-file IPC does.
        return [
            'toolCallId' => $result->toolCallId(),
            'content' => $result->content(),
            'isError' => $result->isError(),
            'durationMs' => $result->durationMs(),
            'imageBytes' => $result->imageBytes(),
            'imagePath' => $result->imagePath(),
            'imageProtocol' => $result->imageProtocol(),
            'diff' => $result->diff(),
        ];
    }

    /**
     * @param array<string, mixed> $encoded
     */
    private static function decodeResult(array $encoded, ToolCall $toolCall): ToolResult
    {
        return new ToolResult(
            toolCallId: is_string($encoded['toolCallId'] ?? null) ? $encoded['toolCallId'] : $toolCall->id(),
            content: (string) ($encoded['content'] ?? ''),
            isError: (bool) ($encoded['isError'] ?? false),
            durationMs: is_int($encoded['durationMs'] ?? null) ? $encoded['durationMs'] : null,
            imageBytes: is_string($encoded['imageBytes'] ?? null) ? $encoded['imageBytes'] : null,
            imagePath: is_string($encoded['imagePath'] ?? null) ? $encoded['imagePath'] : null,
            imageProtocol: is_string($encoded['imageProtocol'] ?? null) ? $encoded['imageProtocol'] : null,
            diff: is_string($encoded['diff'] ?? null) ? $encoded['diff'] : null,
        );
    }

    /**
     * The {@see HookContext} both dispatch paths gate on.
     */
    private function hookContext(ToolCall $toolCall, Tool $tool, App $app): HookContext
    {
        return new HookContext(
            sessionId: $app->sessionId ?? '',
            toolName: $tool->name(),
            toolArgs: $toolCall->arguments(),
            toolInput: json_encode($toolCall->arguments()) ?: '{}',
            toolOutput: '',
            model: $app->model,
            provider: $app->provider->name(),
            projectRoot: self::projectRoot($app),
        );
    }

    /**
     * Whether this build can fan a group out at all. Without pcntl every
     * segment is a barrier and the batch runs exactly as it did before
     * concurrency existed — a capability gap, reported by behaviour rather
     * than hidden behind a fabricated result.
     */
    private static function canFork(): bool
    {
        return function_exists('pcntl_fork') && function_exists('pcntl_waitpid');
    }

    /**
     * Collect a child we have just SIGKILLed, over a bounded WNOHANG window.
     *
     * Never an unflagged `pcntl_waitpid()`: `posix_kill()` is guarded because
     * ext-posix is not guaranteed, and in exactly that build there is nothing
     * to kill the child with — a blocking wait would then hang the turn
     * forever on the tool that was already refusing to finish.
     */
    private static function reapKilled(int $pid): void
    {
        $status = 0;
        for ($attempt = 0; $attempt < self::REAP_ATTEMPTS; $attempt++) {
            if (pcntl_waitpid($pid, $status, WNOHANG) !== 0) {
                return;
            }
            usleep(self::REAP_POLL_MICROSECONDS);
        }
    }

    /**
     * Settle a {@see HookResult::ask()} into an
     * ALLOW or a DENY by putting the question to $onPermissionRequest.
     *
     * Fails CLOSED when no approver is wired: an unanswered ASK is not
     * permission (see {@see HookResult::permitsExecution()}),
     * and a Runtime driven by a head-less caller must not run a call the hook
     * chain explicitly refused to decide on its own. The denial says so in as
     * many words rather than reporting it as a hook DENY, because the hook
     * denied nothing — nobody was there to answer.
     *
     * @param ?callable $onPermissionRequest see {@see run()}
     */
    private function settleAsk(
        ToolCall $toolCall,
        HookResult $ask,
        ?callable $onPermissionRequest,
    ): HookResult {
        if ($onPermissionRequest === null) {
            return HookResult::deny(
                "Permission required and no approver is attached to this run: {$ask->message}",
            );
        }

        // `=== true`, never a (bool) cast: only a literal true is a grant.
        // A cast would turn ANY truthy return into permission, and the
        // obvious wiring for this seam is Chat handing over an approver that
        // returns a PermissionReply — every case of which, Reject included,
        // is a truthy object. That is exactly how ForeignAgentPresetRegistry
        // silently granted tool access earlier in this build.
        return $this->hookManager->resolveAsk($ask, $onPermissionRequest($toolCall, $ask) === true);
    }

    /**
     * Terminate one tool call that never reached (or never survived) the tool
     * itself — an unknown name, or a pre-hook DENY.
     *
     * The synthetic error {@see ToolResult} exists so {@see ToolFinished}
     * always carries a result: a consumer rendering the running→done
     * transition would otherwise need a third, result-less shape for exactly
     * the two cases a user most wants explained.
     */
    private function failure(ToolCall $toolCall, string $message, ?callable $onEvent): ToolResultMessage
    {
        $this->emit($onEvent, ToolFinished::fromResult(
            $toolCall,
            new ToolResult(toolCallId: $toolCall->id(), content: $message, isError: true),
        ));

        return new ToolResultMessage($toolCall->id(), $message, isError: true);
    }

    /**
     * Append a side-channel note to a {@see ToolResult} without disturbing it.
     *
     * `isError` is deliberately left alone: a hook or a renderer falling over
     * says nothing about whether the tool succeeded, and flipping the flag
     * would tell the model to retry a call that already worked. Every other
     * field is copied through because {@see ToolResult} is readonly and its
     * image/diff payloads are what {@see \SugarCraft\Crush\Backend\EngineBackend}
     * renders.
     */
    private static function annotate(ToolResult $result, string $note): ToolResult
    {
        $content = $result->content();

        return new ToolResult(
            toolCallId: $result->toolCallId(),
            content: $content === '' ? $note : $content . "\n\n" . $note,
            isError: $result->isError(),
            durationMs: $result->durationMs(),
            imageBytes: $result->imageBytes(),
            imagePath: $result->imagePath(),
            imageProtocol: $result->imageProtocol(),
            diff: $result->diff(),
        );
    }

    private function emit(?callable $onEvent, ToolStarted|ToolFinished $event): void
    {
        if ($onEvent !== null) {
            $onEvent($event);
        }
    }

    private function findTool(string $name, App $app): ?Tool
    {
        foreach ($app->tools as $tool) {
            if ($tool->name() === $name) {
                return $tool;
            }
        }
        return null;
    }

    private function buildMessages(App $app): array
    {
        $messages = [];

        foreach ($app->messages as $msg) {
            if ($msg instanceof Message) {
                $messages[] = $msg;
            }
        }

        return $messages;
    }

    /**
     * Assemble the system prompt for a turn.
     *
     * Root CLAUDE.md/AGENTS.md and the config-driven forced-instruction
     * globs are folded in here because this is the only place a whole-session
     * instruction can reach the model: InstructionFileLoader's on-touch
     * loadForPath() path only fires once the agent happens to open a file in
     * that subtree, so before this wiring a repo-root AGENTS.md had zero
     * effect on a session that never touched the root directory.
     *
     * Each document is fenced in <project-instructions> so the model can tell
     * project convention from the assistant's own base prompt.
     *
     * The <env> block goes first, ahead of any project instruction, so the
     * model knows where it is (cwd, git state, platform, model, date) before
     * it reads conventions that talk about paths relative to that cwd.
     */
    private function buildSystemPrompt(App $app): string
    {
        $base = 'You are SugarCrush, an AI coding assistant.';

        $base .= "\n\n" . $this->environmentSnapshot($app)->render();

        if ($app->instructionLoader !== null) {
            $docs = [
                ...$app->instructionLoader->loadRoot(),
                ...$app->instructionLoader->loadForced(),
            ];

            foreach ($docs as $doc) {
                if (trim($doc) === '') {
                    continue;
                }

                $base .= "\n\n<project-instructions>\n" . $doc . "\n</project-instructions>";
            }
        }

        if (!empty($app->enabledSkills)) {
            foreach ($app->enabledSkills as $skill) {
                if ($skill instanceof \SugarCraft\Crush\Skills\Skill) {
                    $base .= "\n\n" . $skill->systemPromptContribution();
                }
            }
        }

        // Level-1 metadata for every DISCOVERED skill (name + description
        // only), distinct from the full bodies the explicitly-enabled skills
        // above contribute. Without this listing the Skill tool is a tool the
        // model has no reason to call, so a populated registry would still be
        // un-auto-triggerable (crush_feat.md section 7 E1/E2 Strategy A).
        // Empty registry => empty string, so nothing changes for a session
        // that discovered no skills.
        $base .= (new SkillMatcher())->listForPrompt($app->availableSkills);

        return $base;
    }

    /**
     * Resolve the environment snapshot folded into every system prompt.
     *
     * Memoized on the Runtime rather than re-captured per call: render()
     * shells out to git three times, and buildSystemPrompt() runs once per
     * step of the agentic loop. A snapshot is also the semantics the block
     * documents — a point-in-time capture, not live-polled state — so the
     * same instance must be reused once taken. An owner that already holds a
     * session-wide snapshot injects it through the constructor instead.
     *
     * Captured at {@see projectRoot()}, not at the process directory: the
     * "Working directory"/"Is directory a git repo" lines this renders are
     * what orient the model, and on a `--root <lib>` run they must name the
     * directory the tools are jailed to.
     */
    private function environmentSnapshot(App $app): EnvironmentBlock
    {
        return $this->environmentBlock ??= EnvironmentBlock::capture(self::projectRoot($app), $app->model);
    }

    /**
     * The directory this turn is rooted at: the App's configured
     * {@see App::$root} (`--root`), falling back to the process directory
     * for an App that was never given one.
     *
     * Single seam for both consumers — the environment block the model reads
     * and the {@see HookContext::$projectRoot} every PreToolUse/PostToolUse
     * hook gates on — because the whole defect in crush_code.md Phase 0
     * item 6 was those two disagreeing with the tools' own root.
     */
    private static function projectRoot(App $app): string
    {
        return $app->root ?? (getcwd() ?: '');
    }

    /**
     * Determine whether to prompt the user about idle-session compaction.
     *
     * Returns true when:
     *   - The session has been idle for more than 3600 seconds (1 hour), AND
     *   - The token count exceeds 100,000
     *
     * This is a pure check — the actual offer to compact is handled in
     * Chat.php based on this check.
     *
     * @param App $app The application state (provides lastActivityAt for idle check)
     * @param int $tokenCount Current estimated token count in the conversation
     */
    public function shouldPromptIdleCompaction(App $app, int $tokenCount): bool
    {
        if ($tokenCount <= 100000) {
            return false;
        }

        if ($app->lastActivityAt === null) {
            return false;
        }

        $idleSeconds = time() - $app->lastActivityAt->getTimestamp();

        return $idleSeconds > 3600;
    }
}
