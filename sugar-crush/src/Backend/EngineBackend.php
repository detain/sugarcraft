<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Backend;

use React\EventLoop\Loop;
use React\Promise\Deferred;
use React\Promise\PromiseInterface;
use SugarCraft\Crush\App\App;
use SugarCraft\Crush\Backend;
use SugarCraft\Crush\Context\InstructionFileLoader;
use SugarCraft\Crush\Events\ToolFinished;
use SugarCraft\Crush\Events\ToolStarted;
use SugarCraft\Crush\Hooks\BuiltIn\BashEscapeDenyHook;
use SugarCraft\Crush\Hooks\HookManager;
use SugarCraft\Crush\Hooks\HookRegistry;
use SugarCraft\Crush\Message;
use SugarCraft\Crush\Messages\AssistantMessage;
use SugarCraft\Crush\Messages\Message as TypedMessage;
use SugarCraft\Crush\Messages\SystemMessage;
use SugarCraft\Crush\Messages\ToolResultMessage;
use SugarCraft\Crush\Messages\UserMessage;
use SugarCraft\Crush\Providers\ProviderInterface;
use SugarCraft\Crush\Runtime;
use SugarCraft\Crush\Skills\SkillRegistry;
use SugarCraft\Crush\Tools\ToolResult;

/**
 * Bridges the chat-shell {@see Backend} seam to the full agent engine —
 * a {@see ProviderInterface} driven by the {@see Runtime}, with tools,
 * skills and hooks.
 *
 * This is what makes the merged product *work*: the tested {@see \SugarCraft\Crush\Chat}
 * Model keeps speaking its simple `complete(history): Message` contract,
 * while underneath each turn runs a bounded agentic loop — call the model,
 * execute any tool calls through the hook gate, feed the results back, and
 * repeat until the model stops calling tools (or {@see $maxSteps} is hit).
 *
 * The chassis works in the root {@see Message} value object; the engine
 * works in the typed {@see \SugarCraft\Crush\Messages\Message} hierarchy.
 * Conversion happens here at the seam.
 */
final class EngineBackend implements Backend
{
    /**
     * IDLE ceiling on a forked completion child in {@see completeAsync()} -
     * how long the parent will wait for the NEXT frame from the child, not
     * for the whole turn. It used to be a single wall-clock timer started
     * once for the entire fork, which SIGKILLed any turn whose legitimate
     * multi-step tool work ran past it ("Provider request timed out after
     * 120s" mid-flight, crush_feat.md §1 E1). Now every frame the child
     * streams resets it, so a turn that is making visible progress stays
     * alive indefinitely while a genuinely hung provider still dies.
     */
    private const COMPLETE_TIMEOUT_SECONDS = 120;

    /**
     * Upper bound on a single length-prefixed frame from the child. A frame
     * legitimately carries raw image bytes, so it has to be generous - but a
     * corrupt/truncated header must never make the parent try to buffer an
     * arbitrary length before it notices the stream is garbage.
     */
    private const MAX_FRAME_BYTES = 64 * 1024 * 1024;

    /**
     * @param array<int, \SugarCraft\Crush\Tools\Tool>   $tools
     * @param array<int, \SugarCraft\Crush\Skills\Skill> $skills
     */
    public function __construct(
        private readonly ProviderInterface $provider,
        private readonly string $model,
        private readonly array $tools = [],
        private readonly array $skills = [],
        private readonly ?HookManager $hookManager = null,
        private readonly int $maxSteps = 8,
        private readonly bool $hooksDisabled = false,
        private readonly ?SkillRegistry $skillRegistry = null,
        private readonly ?InstructionFileLoader $instructionLoader = null,
    ) {}

    public static function new(ProviderInterface $provider, string $model): self
    {
        return new self($provider, $model);
    }

    /**
     * @param array<int, \SugarCraft\Crush\Tools\Tool> $tools
     */
    public function withTools(array $tools): self
    {
        return new self($this->provider, $this->model, $tools, $this->skills, $this->hookManager, $this->maxSteps, $this->hooksDisabled, $this->skillRegistry, $this->instructionLoader);
    }

    /**
     * @param array<int, \SugarCraft\Crush\Skills\Skill> $skills
     */
    public function withSkills(array $skills): self
    {
        return new self($this->provider, $this->model, $this->tools, $skills, $this->hookManager, $this->maxSteps, $this->hooksDisabled, $this->skillRegistry, $this->instructionLoader);
    }

    /**
     * Attach the discovered {@see SkillRegistry} (built-in + user + project +
     * foreign-imported skills, see {@see \SugarCraft\Crush\Skills\SkillManager::loadAll()})
     * so it reaches {@see App::$availableSkills} on every {@see complete()}
     * call — the seam {@see \SugarCraft\Crush\Cli\Bootstrap} uses to make
     * skills discovered from ~/.claude/skills, {project}/.claude/skills,
     * {project}/.opencode/skills, and ~/.config/opencode/skills (see {@see
     * \SugarCraft\Crush\Skills\ForeignSkillDiscovery}) actually visible to a
     * real `bin/sugarcrush` run instead of only to their own unit tests.
     */
    public function withSkillRegistry(SkillRegistry $skillRegistry): self
    {
        return new self($this->provider, $this->model, $this->tools, $this->skills, $this->hookManager, $this->maxSteps, $this->hooksDisabled, $skillRegistry, $this->instructionLoader);
    }

    /**
     * Attach the session's shared {@see InstructionFileLoader} so it reaches
     * {@see App::$instructionLoader} on every {@see complete()} call — the
     * seam that makes a repo-root CLAUDE.md/AGENTS.md actually reach the
     * model's system prompt on a real `bin/sugarcrush` run rather than only
     * on an on-touch Read/Edit/Glob of that same directory.
     */
    public function withInstructionLoader(InstructionFileLoader $instructionLoader): self
    {
        return new self($this->provider, $this->model, $this->tools, $this->skills, $this->hookManager, $this->maxSteps, $this->hooksDisabled, $this->skillRegistry, $instructionLoader);
    }

    public function withHooks(HookManager $hookManager): self
    {
        // An explicit hook manager always wins and clears any prior opt-out.
        return new self($this->provider, $this->model, $this->tools, $this->skills, $hookManager, $this->maxSteps, false, $this->skillRegistry, $this->instructionLoader);
    }

    /**
     * Escape hatch for callers that deliberately want an UNGUARDED engine —
     * no built-in hooks, no custom manager. Everything else is safe-by-default
     * (see {@see resolveHookManager()}), so opting out is an explicit choice.
     */
    public function withoutHooks(): self
    {
        return new self($this->provider, $this->model, $this->tools, $this->skills, null, $this->maxSteps, true, $this->skillRegistry, $this->instructionLoader);
    }

    public function withMaxSteps(int $maxSteps): self
    {
        return new self($this->provider, $this->model, $this->tools, $this->skills, $this->hookManager, max(1, $maxSteps), $this->hooksDisabled, $this->skillRegistry, $this->instructionLoader);
    }

    /**
     * Register BashEscapeDenyHook with the given worktree root to prevent Bash
     * commands from referencing paths outside the worktree.
     *
     * This wires the heuristic PreToolUse hook so that Bash commands are
     * checked before execution. Without this, Bash is confined only by the
     * `cd $worktreeRoot` prefix which does NOT prevent escape via `cd /` or
     * `..` traversal within the command string itself.
     *
     * @see \SugarCraft\Crush\Hooks\BuiltIn\BashEscapeDenyHook
     */
    public function withWorktreeRoot(string $worktreeRoot): self
    {
        if ($this->hooksDisabled) {
            return $this;
        }

        $manager = $this->hookManager ?? new HookManager(new HookRegistry());
        $manager->registerBuiltIns();
        $manager->register(new BashEscapeDenyHook($worktreeRoot));

        return new self($this->provider, $this->model, $this->tools, $this->skills, $manager, $this->maxSteps, false, $this->skillRegistry, $this->instructionLoader);
    }

    /**
     * @param ?callable $onEvent Tool-lifecycle observer threaded straight into
     *                           {@see Runtime::run()} so every tool call this
     *                           bounded loop makes — including the ones on
     *                           intermediate steps whose messages get folded
     *                           back into $app and never reach the caller — is
     *                           observable while the turn is still running
     *                           (crush_feat.md §1 E1). Without it the caller
     *                           sees only $lastAssistant's text.
     */
    public function complete(array $history, ?callable $onToken = null, ?callable $onEvent = null): Message
    {
        $runtime = new Runtime($this->provider, $this->resolveHookManager());

        $app = App::new($this->provider, $this->model)
            ->withTools($this->tools)
            ->withEnabledSkills($this->skills)
            ->withAvailableSkills($this->skillRegistry ?? new SkillRegistry())
            ->withInstructionLoader($this->instructionLoader)
            ->withMessages($this->toTypedMessages($history));

        $lastAssistant = null;
        $lastImageBytes = null;
        $lastImageProtocol = null;

        // Bounded agentic loop: keep running while the model asks for tools.
        // The Runtime resolves one assistant turn + its tool calls per run();
        // we feed the results back and re-run until the model answers without
        // tools — or we hit the step ceiling (guards against runaway loops,
        // which neither sugar-crush nor candy-crush had).
        for ($step = 0; $step < $this->maxSteps; $step++) {
            $assistant = null;
            $toolResults = [];

            foreach ($runtime->run($app, $onEvent) as $message) {
                if ($message instanceof AssistantMessage) {
                    $assistant = $message;
                } elseif ($message instanceof ToolResultMessage) {
                    $toolResults[] = $message;
                    // Last image-bearing tool result of the whole turn wins -
                    // W1.G2 reachability fix: this is the only point left
                    // with access to the typed ToolResultMessage before only
                    // the root Message survives back to Chat/Renderer.
                    if ($message->hasImage()) {
                        $lastImageBytes = $message->imageBytes();
                        $lastImageProtocol = $message->imageProtocol();
                    }
                }
            }

            if ($assistant !== null) {
                $lastAssistant = $assistant;
            }

            if ($toolResults === []) {
                break; // model answered without calling tools — done
            }

            $app = $app->withMessages([
                ...$app->messages,
                ...($assistant !== null ? [$assistant] : []),
                ...$toolResults,
            ]);
        }

        $content = $lastAssistant?->content() ?? '';
        if ($onToken !== null && $content !== '') {
            $onToken($content);
        }

        // Thread the reasoning ReasoningExtractor already split out (§12 D3)
        // across the typed-Message -> root-Message seam instead of dropping
        // it here - it's the last point in this call path that still has
        // access to $lastAssistant before only the plain-string Message DTO
        // survives back to Chat/Renderer. withImage() does the same for an
        // image-bearing tool result (W1.G2 reachability fix).
        return Message::assistant($content, reasoning: $lastAssistant?->reasoning())
            ->withImage($lastImageBytes, $lastImageProtocol);
    }

    /**
     * Runs {@see complete()} - one or more real, blocking provider HTTP calls
     * plus any tool execution the agentic loop drives - in a forked child so
     * the caller's event loop (the TUI's render/input loop) never blocks on
     * it. Without this, `Program`'s `futureTick()`-scheduled Cmd execution
     * calls this method's factory closure directly on the loop: the old
     * implementation wrapped a *synchronous* {@see complete()} call in a
     * `React\Promise\Promise` whose executor runs immediately (that's the
     * Promise constructor's contract, not deferred), so the "async" call was
     * really just a blocking one wearing a Promise - the whole terminal
     * froze (no spinner animation, no keystrokes, no Ctrl+C) for the full
     * duration of every provider round-trip. Forking moves that blocking
     * work off the loop entirely; the parent only watches a non-blocking
     * socket via {@see Loop::addReadStream()} for the result, so rendering
     * and input keep flowing while a turn is in flight - same rationale as
     * {@see \SugarCraft\Crush\Chat::executeToolsParallel()}'s R14b fork fix,
     * extended here to cross the ReactPHP loop boundary rather than just
     * fanning out sibling tool calls.
     *
     * The child does not batch: it writes each {@see ToolStarted}/{@see
     * ToolFinished} as its own length-prefixed frame the moment the event
     * fires, and the final result as the last frame. The parent drains
     * whatever whole frames have arrived on every readable edge and hands
     * each event straight to $onEvent, so a turn running eight rounds of
     * tools renders them as they happen instead of showing nothing but a
     * "thinking" spinner until the very end (crush_feat.md §1 E1).
     */
    public function completeAsync(array $history, ?callable $onToken = null, ?CancellationToken $cancellation = null, ?callable $onEvent = null): PromiseInterface
    {
        $deferred = new Deferred();

        if ($cancellation?->isCancelled() === true) {
            $deferred->reject(new \RuntimeException('Request cancelled'));

            return $deferred->promise();
        }

        if (!function_exists('pcntl_fork') || !function_exists('pcntl_waitpid')) {
            return $this->completeAsyncBlocking($history, $onToken, $deferred, $onEvent);
        }

        $sockets = @stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        if ($sockets === false) {
            return $this->completeAsyncBlocking($history, $onToken, $deferred, $onEvent);
        }

        [$parentSocket, $childSocket] = $sockets;
        $pid = pcntl_fork();

        if ($pid === -1) {
            fclose($parentSocket);
            fclose($childSocket);

            return $this->completeAsyncBlocking($history, $onToken, $deferred, $onEvent);
        }

        if ($pid === 0) {
            $this->runCompleteInChild($childSocket, $history);
        }

        fclose($childSocket);
        stream_set_blocking($parentSocket, false);

        $loop = Loop::get();
        $buffer = '';
        $settled = false;
        $result = null;

        // $timeoutTimer/$cancelTimer are assigned below, AFTER $teardown is
        // built (each timer's own callback needs to call $teardown) - they're
        // captured by reference here specifically so $teardown still sees
        // the real TimerInterface once addTimer()/addPeriodicTimer() below
        // assign into these same variables. $timeoutTimer is additionally
        // REPLACED on every frame by $resetTimeout, which is the whole point
        // of the idle-timeout change.
        $timeoutTimer = null;
        $cancelTimer = null;

        // Shared teardown for the failure ways this can end (timeout,
        // cancellation): stop watching the socket, cancel BOTH timers
        // (critical for $cancelTimer, a periodic timer that would otherwise
        // keep polling forever after settling via a different path), kill and
        // reap the child so it never zombies.
        $teardown = function (string $rejectMessage) use (&$settled, $loop, $parentSocket, $pid, $deferred, &$timeoutTimer, &$cancelTimer): void {
            if ($settled) {
                return;
            }
            $settled = true;
            $loop->removeReadStream($parentSocket);
            if (is_resource($parentSocket)) {
                fclose($parentSocket);
            }
            if ($timeoutTimer !== null) {
                $loop->cancelTimer($timeoutTimer);
            }
            if ($cancelTimer !== null) {
                $loop->cancelTimer($cancelTimer);
            }
            if (function_exists('posix_kill')) {
                posix_kill($pid, SIGKILL);
            }
            $status = 0;
            pcntl_waitpid($pid, $status);
            $deferred->reject(new \RuntimeException($rejectMessage));
        };

        // The success path: the child either delivered its result frame or
        // hung up. Same cleanup as $teardown minus the kill (the child is
        // already on its way out), then settle from whatever result frame
        // arrived - a child that died before writing one is still a failure.
        $finalize = function () use (&$settled, &$result, $loop, $parentSocket, $pid, $deferred, $onToken, &$timeoutTimer, &$cancelTimer): void {
            if ($settled) {
                return;
            }
            $settled = true;
            $loop->removeReadStream($parentSocket);
            if (is_resource($parentSocket)) {
                fclose($parentSocket);
            }
            if ($timeoutTimer !== null) {
                $loop->cancelTimer($timeoutTimer);
            }
            if ($cancelTimer !== null) {
                $loop->cancelTimer($cancelTimer);
            }
            $status = 0;
            pcntl_waitpid($pid, $status);
            $this->settleFromResultFrame($result, $deferred, $onToken);
        };

        // Restart the idle clock. Called once up front and again for every
        // frame the child streams, so the ceiling measures silence rather
        // than total turn length.
        $resetTimeout = function () use (&$settled, $loop, &$timeoutTimer, $teardown): void {
            if ($settled) {
                return;
            }
            if ($timeoutTimer !== null) {
                $loop->cancelTimer($timeoutTimer);
            }
            $timeoutTimer = $loop->addTimer(self::COMPLETE_TIMEOUT_SECONDS, static function () use ($teardown): void {
                $teardown('Provider request timed out after ' . self::COMPLETE_TIMEOUT_SECONDS . 's without progress');
            });
        };
        $resetTimeout();

        // Escape-Escape abort (see Chat::update()'s Escape handling): the
        // cancellation flag can flip at any point after this call returns,
        // long after the closures below were built, so it has to be polled
        // rather than checked once up front.
        $cancelTimer = $cancellation === null ? null : $loop->addPeriodicTimer(0.1, function () use ($cancellation, $teardown): void {
            if ($cancellation->isCancelled()) {
                $teardown('Request cancelled');
            }
        });

        $loop->addReadStream($parentSocket, function ($stream) use (&$buffer, &$result, $onEvent, $finalize, $resetTimeout): void {
            $chunk = fread($stream, 65536);
            if ($chunk === '' || $chunk === false) {
                $finalize();

                return;
            }

            $buffer .= $chunk;
            foreach (self::drainFrames($buffer) as $frame) {
                // Progress of any kind pushes the idle deadline out.
                $resetTimeout();

                if (($frame['kind'] ?? null) === 'result') {
                    $result = $frame;
                    // The result frame is the last one by construction, so
                    // settle now rather than waiting for the child's EOF -
                    // one less round-trip of latency on every turn.
                    $finalize();

                    return;
                }

                $event = self::decodeEvent($frame);
                if ($event !== null && $onEvent !== null) {
                    $onEvent($event);
                }
            }
        });

        return $deferred->promise();
    }

    /**
     * The forked child's half of {@see completeAsync()}: run the real
     * (blocking) engine loop in isolation, streaming each tool event back
     * over the socket as its own frame as it fires and the outcome as the
     * final frame, then exit. Never returns.
     *
     * @param array<int, Message> $history
     */
    private function runCompleteInChild($childSocket, array $history): never
    {
        try {
            // This is a forked child, so invoking the caller's callback
            // in-process would write into a copy of its state and vanish on
            // exit - the event has to cross the socket. It goes out
            // IMMEDIATELY rather than into an end-of-turn batch, because the
            // batch is exactly what made a multi-tool turn look like a silent
            // "thinking" spinner (and what made the parent's single
            // wall-clock timer kill turns that were in fact making progress).
            $message = $this->complete($history, null, static function (ToolStarted|ToolFinished $event) use ($childSocket): void {
                self::writeFrame($childSocket, self::encodeEvent($event));
            });
            // imageBytes/imageProtocol survive this fork boundary too - PHP's
            // serialize()/unserialize() (unlike JSON) round-trip arbitrary
            // binary strings natively, so no base64 step is needed here the
            // way Chat::storeToolResult()'s JSON-over-temp-file IPC needs one
            // (W1.G2 reachability fix).
            $payload = [
                'kind' => 'result',
                'ok' => true,
                'content' => $message->content,
                'reasoning' => $message->reasoning,
                'imageBytes' => $message->imageBytes,
                'imageProtocol' => $message->imageProtocol,
            ];
        } catch (\Throwable $e) {
            $payload = ['kind' => 'result', 'ok' => false, 'error' => $e->getMessage()];
        }

        self::writeFrame($childSocket, $payload);
        fclose($childSocket);
        \SugarCraft\Crush\Support\ForkedChild::exitNow(0);
    }

    /**
     * Write one length-prefixed frame to the child's end of the socket.
     *
     * The 4-byte big-endian prefix is what lets the parent tell frames apart
     * in a byte stream that has no other structure: a serialized payload can
     * contain any byte, so there is no delimiter to scan for, and a single
     * fwrite() is not guaranteed to be atomic or complete - hence the loop.
     * A dead parent (fwrite failing or making no progress) ends the write
     * rather than spinning; the child is about to exit anyway.
     *
     * @param resource             $socket
     * @param array<string, mixed> $frame
     */
    private static function writeFrame($socket, array $frame): void
    {
        $body = serialize($frame);
        $out = pack('N', strlen($body)) . $body;
        $total = strlen($out);

        for ($written = 0; $written < $total;) {
            $n = @fwrite($socket, substr($out, $written));
            if ($n === false || $n === 0) {
                return;
            }
            $written += $n;
        }
    }

    /**
     * Pull every COMPLETE frame out of the parent's read buffer, leaving any
     * trailing partial frame in $buffer for the next readable edge - a frame
     * can and does span two reads once a tool result carries image bytes.
     *
     * A frame whose declared length is nonsensical means the stream is no
     * longer parseable, so the buffer is dropped rather than misinterpreted;
     * the turn then fails via the missing-result path instead of resolving
     * with garbage.
     *
     * @return list<array<string, mixed>>
     */
    private static function drainFrames(string &$buffer): array
    {
        $frames = [];

        while (strlen($buffer) >= 4) {
            $header = unpack('N', substr($buffer, 0, 4));
            $length = is_array($header) ? (int) ($header[1] ?? 0) : 0;
            if ($length <= 0 || $length > self::MAX_FRAME_BYTES) {
                $buffer = '';
                break;
            }
            if (strlen($buffer) < 4 + $length) {
                break;
            }

            $body = substr($buffer, 4, $length);
            $buffer = substr($buffer, 4 + $length);

            // allowed_classes => false: a hostile/corrupt payload must never
            // be able to instantiate anything (see encodeEvent()).
            $decoded = @unserialize($body, ['allowed_classes' => false]);
            if (is_array($decoded)) {
                $frames[] = $decoded;
            }
        }

        return $frames;
    }

    /**
     * Settle $deferred from the child's final result frame - resolving with
     * the real content (firing $onToken once, matching {@see complete()}'s
     * own one-shot-at-the-end semantics) or rejecting with its error message.
     * A null frame (child crashed before writing one) is reported as a
     * failure rather than silently resolving empty.
     *
     * @param ?array<string, mixed> $data
     */
    private function settleFromResultFrame(?array $data, Deferred $deferred, ?callable $onToken): void
    {
        if ($data === null) {
            $deferred->reject(new \RuntimeException('Provider worker process exited without a result'));

            return;
        }

        if (($data['ok'] ?? false) !== true) {
            $deferred->reject(new \RuntimeException((string) ($data['error'] ?? 'Provider worker process failed')));

            return;
        }

        $content = (string) ($data['content'] ?? '');
        if ($onToken !== null && $content !== '') {
            $onToken($content);
        }

        $reasoning = $data['reasoning'] ?? null;
        $imageBytes = $data['imageBytes'] ?? null;
        $imageProtocol = $data['imageProtocol'] ?? null;
        $deferred->resolve(
            Message::assistant($content, reasoning: is_string($reasoning) ? $reasoning : null)
                ->withImage(
                    is_string($imageBytes) ? $imageBytes : null,
                    is_string($imageProtocol) ? $imageProtocol : null,
                )
        );
    }

    /**
     * Flatten one tool event for the fork payload.
     *
     * Plain nested arrays, not the objects themselves, because the parent
     * unserializes with `allowed_classes => false` (a hostile/corrupt payload
     * must never be able to instantiate anything) - so the objects are rebuilt
     * on the other side by {@see decodeEvent()} instead of round-tripped.
     *
     * @return array<string, mixed>
     */
    private static function encodeEvent(ToolStarted|ToolFinished $event): array
    {
        if ($event instanceof ToolStarted) {
            return [
                'kind' => 'started',
                'id' => $event->toolCallId,
                'name' => $event->toolName,
                'arguments' => $event->arguments,
            ];
        }

        return [
            'kind' => 'finished',
            'id' => $event->toolCallId,
            'name' => $event->toolName,
            'content' => $event->result->content(),
            'isError' => $event->result->isError(),
            'durationMs' => $event->result->durationMs(),
            'imageBytes' => $event->result->imageBytes(),
            'imagePath' => $event->result->imagePath(),
            'imageProtocol' => $event->result->imageProtocol(),
            'diff' => $event->result->diff(),
        ];
    }

    /**
     * Rebuild a tool event flattened by {@see encodeEvent()}, or null when the
     * entry is not a shape this version wrote (a partial write, or a payload
     * from a mismatched build) - one unrecognizable event is skipped rather
     * than failing the whole turn.
     *
     * @param array<string, mixed> $encoded
     */
    private static function decodeEvent(array $encoded): ToolStarted|ToolFinished|null
    {
        $id = is_string($encoded['id'] ?? null) ? $encoded['id'] : null;
        $name = is_string($encoded['name'] ?? null) ? $encoded['name'] : null;
        if ($id === null || $name === null) {
            return null;
        }

        if (($encoded['kind'] ?? null) === 'started') {
            return new ToolStarted($id, $name, is_array($encoded['arguments'] ?? null) ? $encoded['arguments'] : []);
        }

        if (($encoded['kind'] ?? null) !== 'finished') {
            return null;
        }

        return new ToolFinished($id, $name, new ToolResult(
            toolCallId: $id,
            content: (string) ($encoded['content'] ?? ''),
            isError: (bool) ($encoded['isError'] ?? false),
            durationMs: is_int($encoded['durationMs'] ?? null) ? $encoded['durationMs'] : null,
            imageBytes: is_string($encoded['imageBytes'] ?? null) ? $encoded['imageBytes'] : null,
            imagePath: is_string($encoded['imagePath'] ?? null) ? $encoded['imagePath'] : null,
            imageProtocol: is_string($encoded['imageProtocol'] ?? null) ? $encoded['imageProtocol'] : null,
            diff: is_string($encoded['diff'] ?? null) ? $encoded['diff'] : null,
        ));
    }

    /**
     * Fallback for an environment without pcntl/stream_socket_pair support:
     * the old synchronous-under-a-Promise behaviour. Blocks the caller for
     * the duration of the request instead of freezing the whole program
     * silently - a real capability gap, not a bug to hide.
     */
    private function completeAsyncBlocking(array $history, ?callable $onToken, Deferred $deferred, ?callable $onEvent = null): PromiseInterface
    {
        try {
            // No fork here, so tool events reach the caller LIVE on this path
            // (mid-turn, as each call starts/ends) rather than replayed.
            $deferred->resolve($this->complete($history, $onToken, $onEvent));
        } catch (\Throwable $e) {
            $deferred->reject($e);
        }

        return $deferred->promise();
    }

    /**
     * Resolve the hook manager that gates every tool call this turn.
     *
     * Safe-by-default: a backend constructed without an explicit
     * {@see withHooks()} call still registers the built-in hooks
     * ({@see \SugarCraft\Crush\Hooks\BuiltIn\ProtectFilesHook},
     * {@see \SugarCraft\Crush\Hooks\BuiltIn\ConfirmRemoveHook},
     * {@see \SugarCraft\Crush\Hooks\BuiltIn\AuditHook}) so Bash/Edit/Write
     * tools never run unguarded. Callers opt out explicitly via
     * {@see withoutHooks()}.
     */
    private function resolveHookManager(): HookManager
    {
        if ($this->hookManager !== null) {
            return $this->hookManager;
        }

        $manager = new HookManager(new HookRegistry());
        if (!$this->hooksDisabled) {
            $manager->registerBuiltIns();
        }

        return $manager;
    }

    /**
     * Convert the chassis's root Message history into the engine's typed
     * message hierarchy.
     *
     * @param array<int, Message> $history
     * @return array<int, TypedMessage>
     */
    private function toTypedMessages(array $history): array
    {
        $out = [];
        foreach ($history as $msg) {
            $out[] = match ($msg->role->value) {
                'user' => new UserMessage($msg->content),
                'assistant' => new AssistantMessage($msg->content),
                default => new SystemMessage($msg->content),
            };
        }

        return $out;
    }
}
