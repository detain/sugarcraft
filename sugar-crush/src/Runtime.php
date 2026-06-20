<?php

declare(strict_types=1);

namespace SugarCraft\Crush;

use SugarCraft\Crush\App\App;
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
    public function __construct(
        private ProviderInterface $provider,
        private HookManager $hookManager,
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
            yield new ToolResultMessage(
                $toolCall->id(),
                $result->content(),
                $result->isError(),
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

    private function buildSystemPrompt(App $app): string
    {
        $base = 'You are SugarCrush, an AI coding assistant.';

        if (!empty($app->enabledSkills)) {
            foreach ($app->enabledSkills as $skill) {
                if ($skill instanceof \SugarCraft\Crush\Skills\Skill) {
                    $base .= "\n\n" . $skill->systemPromptContribution();
                }
            }
        }

        return $base;
    }
}
