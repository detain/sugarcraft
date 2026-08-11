<?php

declare(strict_types=1);

namespace SugarCraft\Crush;

use SugarCraft\Crush\App\App;
use SugarCraft\Crush\Context\EnvironmentBlock;
use SugarCraft\Crush\Providers\ProviderInterface;
use SugarCraft\Crush\Providers\CompleteRequest;
use SugarCraft\Crush\Messages\Message;
use SugarCraft\Crush\Messages\AssistantMessage;
use SugarCraft\Crush\Messages\UserMessage;
use SugarCraft\Crush\Messages\ToolResultMessage;
use SugarCraft\Crush\Tools\Tool;
use SugarCraft\Crush\Hooks\HookManager;
use SugarCraft\Crush\Hooks\HookContext;

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
     * @return \Generator yields CompleteResponse chunks
     */
    public function run(App $app): \Generator
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
            ? $this->runStreaming($request, $app)
            : $this->runBatch($request, $app);

        foreach ($inner as $msg) {
            yield $msg;
        }
    }

    private function runStreaming(CompleteRequest $request, App $app): \Generator
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
            foreach ($this->executeToolCalls($toolCalls, $app) as $msg) {
                yield $msg;
            }
        }
    }

    private function runBatch(CompleteRequest $request, App $app): \Generator
    {
        $response = $this->provider->complete($request);

        yield new AssistantMessage(
            $response->content,
            $response->toolCalls,
            $response->reasoning,
        );

        if ($response->toolCalls !== null && $response->toolCalls !== []) {
            foreach ($this->executeToolCalls($response->toolCalls, $app) as $msg) {
                yield $msg;
            }
        }
    }

    /**
     * @param array<ToolCall> $toolCalls
     */
    private function executeToolCalls(array $toolCalls, App $app): \Generator
    {
        foreach ($toolCalls as $toolCall) {
            // Find the tool
            $tool = $this->findTool($toolCall->name(), $app);
            if ($tool === null) {
                yield new ToolResultMessage(
                    $toolCall->id(),
                    "Tool not found: {$toolCall->name()}",
                    isError: true,
                );
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
                projectRoot: getcwd() ?: '',
            );

            // Run pre-hook. Only a true DENY blocks the call — a MODIFY
            // result is "allowed but with rewritten input", so it must fall
            // through to execution (isAllowed() is false for MODIFY too).
            $hookResult = $this->hookManager->preToolUse($context);
            if (!$hookResult->isAllowed() && !$hookResult->isModified()) {
                yield new ToolResultMessage(
                    $toolCall->id(),
                    "Hook denied: {$hookResult->message}",
                    isError: true,
                );
                continue;
            }

            // A MODIFY hook rewrites the tool input before execution.
            $args = $hookResult->isModified()
                ? (json_decode($hookResult->modifiedInput ?? '', true) ?? $toolCall->arguments())
                : $toolCall->arguments();

            $result = $tool->execute($args);

            // Post-hook observes the tool output.
            $this->hookManager->postToolUse($context->withToolOutput($result->content()));

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
     */
    private function environmentSnapshot(App $app): EnvironmentBlock
    {
        return $this->environmentBlock ??= EnvironmentBlock::capture((string) getcwd(), $app->model);
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
