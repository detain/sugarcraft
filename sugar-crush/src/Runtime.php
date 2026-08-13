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
     * @param ?EnvironmentBlock $environmentBlock Pre-captured session snapshot; when omitted
     *                                            one is captured lazily on first use and
     *                                            reused for the life of this Runtime.
     */
    public function __construct(
        private ProviderInterface $provider,
        private HookManager $hookManager,
        private ?EnvironmentBlock $environmentBlock = null,
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
     * @return \Generator yields CompleteResponse chunks
     */
    public function run(App $app, ?callable $onEvent = null, ?callable $onPermissionRequest = null): \Generator
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
            ? $this->runStreaming($request, $app, $onEvent, $onPermissionRequest)
            : $this->runBatch($request, $app, $onEvent, $onPermissionRequest);

        foreach ($inner as $msg) {
            yield $msg;
        }
    }

    private function runStreaming(CompleteRequest $request, App $app, ?callable $onEvent = null, ?callable $onPermissionRequest = null): \Generator
    {
        $buffer = '';
        $toolCalls = [];
        $reasoning = null;

        // Accumulate the whole stream and emit one assistant message when the
        // generator is exhausted. We deliberately do NOT use a tokensUsed>0
        // sentinel to detect completion — real providers stream content with
        // tokensUsed=0 and only report totals at the end (if at all), so a
        // sentinel drops the entire message in production.
        foreach ($this->provider->completeStream($request) as $response) {
            $buffer .= $response->content;
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

    private function runBatch(CompleteRequest $request, App $app, ?callable $onEvent = null, ?callable $onPermissionRequest = null): \Generator
    {
        $response = $this->provider->complete($request);

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
        foreach ($toolCalls as $toolCall) {
            $this->emit($onEvent, ToolStarted::fromCall($toolCall));

            // Find the tool
            $tool = $this->findTool($toolCall->name(), $app);
            if ($tool === null) {
                yield $this->failure($toolCall, "Tool not found: {$toolCall->name()}", $onEvent);
                continue;
            }

            // Create hook context
            $context = new HookContext(
                sessionId: $app->sessionId ?? '',
                toolName: $tool->name(),
                toolArgs: $toolCall->arguments(),
                toolInput: json_encode($toolCall->arguments()) ?: '{}',
                toolOutput: '',
                model: $app->model,
                provider: $app->provider->name(),
                projectRoot: self::projectRoot($app),
            );

            // Run pre-hook. Only a true DENY blocks the call — a MODIFY
            // result is "allowed but with rewritten input", so it must fall
            // through to execution (isAllowed() is false for MODIFY too).
            $hookResult = $this->hookManager->preToolUse($context);

            // An ASK is not a verdict: it is the hook deferring to the user
            // (crush_feat.md §1 E2). It must not resolve silently either way
            // here — it is settled by whoever owns a UI that can put the
            // question, and fails CLOSED when nobody does.
            if ($hookResult->isAsk()) {
                $hookResult = $this->settleAsk($toolCall, $hookResult, $onPermissionRequest);
            }

            if (!$hookResult->isAllowed() && !$hookResult->isModified()) {
                yield $this->failure($toolCall, "Hook denied: {$hookResult->message}", $onEvent);
                continue;
            }

            // A MODIFY hook rewrites the tool input before execution.
            $args = $hookResult->isModified()
                ? (json_decode($hookResult->modifiedInput ?? '', true) ?? $toolCall->arguments())
                : $toolCall->arguments();

            // A throwing tool must cost its own call, not the whole turn.
            // Without this catch the \Throwable escapes this generator, out
            // through Runtime::run(), and is only stopped by
            // EngineBackend::runCompleteInChild()'s outer boundary — which
            // reports a turn-level failure and discards every OTHER tool
            // result plus all assistant content already produced. A model
            // handing Bash a non-string `command` (TypeError out of
            // escapeshellarg()) is enough to trigger it.
            //
            // Scope, precisely: everything from here to the yield below is
            // contained — the tool body, the PostToolUse hook chain, and the
            // ToolFinished emit — each degrading to an annotated result for
            // THIS call. What is NOT contained is anything before it (the
            // PreToolUse chain and settleAsk, which decide whether the call
            // happens at all and so have nothing to degrade to) and the yield
            // itself (a consumer throwing back into this generator is the
            // consumer ending the turn). This is strictly wider than
            // Chat::invokeTool(), which guards only the tool body.
            try {
                $result = $tool->execute($args);
            } catch (\Throwable $e) {
                $result = new ToolResult(
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
            yield new ToolResultMessage(
                $toolCall->id(),
                $result->content(),
                $result->isError(),
                $result->imageBytes(),
                $result->imageProtocol(),
            );
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
