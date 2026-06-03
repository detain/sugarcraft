<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Providers;

use SugarCraft\Crush\Messages\AssistantMessage;
use SugarCraft\Crush\Messages\Message;
use SugarCraft\Crush\Messages\SystemMessage;
use SugarCraft\Crush\Messages\ToolResultMessage;
use SugarCraft\Crush\Messages\UserMessage;
use SugarCraft\Crush\Tools\ToolCall;

final readonly class ClaudeCodeProvider implements ProviderInterface
{
    public function __construct(
        private ClaudeCodeInvocation $invocation,
        private string $defaultModel = 'claude-sonnet-4-6',
    ) {}

    public function name(): string
    {
        return 'claude-code';
    }

    public function supportsStreaming(): bool
    {
        return true;
    }

    public function supportsFunctionCalling(): bool
    {
        return true;
    }

    public function supportsVision(): bool
    {
        return false;
    }

    public function supportsJsonSchema(): bool
    {
        return true;
    }

    public function contextWindow(): int
    {
        return match ($this->defaultModel) {
            'claude-sonnet-4-6' => 200_000,
            'claude-opus-4-6' => 200_000,
            'claude-sonnet-4-7' => 200_000,
            'claude-opus-4-7' => 200_000,
            'claude-haiku-4-7' => 200_000,
            default => 200_000,
        };
    }

    public function costPer1kTokens(string $model, string $direction): float
    {
        // Claude Code handles its own billing
        return 0.0;
    }

    public function complete(CompleteRequest $request): CompleteResponse
    {
        $prompt = $this->buildPrompt($request->messages);

        $options = [
            'format' => 'json',
            'bare' => true,
            'systemPrompt' => $request->systemPrompt,
        ];

        if ($request->tools !== null) {
            $toolNames = array_map(fn($t) => $t->name(), $request->tools);
            $options['allowedTools'] = implode(',', $toolNames);
        }

        $output = $this->invocation->execute(
            $this->invocation->printModeArgs($prompt, $options)
        );

        return $this->parseJsonResponse($output);
    }

    /**
     * @return \Generator<int, CompleteResponse>
     */
    public function completeStream(CompleteRequest $request): \Generator
    {
        $prompt = $this->buildPrompt($request->messages);

        $options = [
            'format' => 'stream-json',
            'bare' => true,
            'systemPrompt' => $request->systemPrompt,
        ];

        if ($request->tools !== null) {
            $toolNames = array_map(fn($t) => $t->name(), $request->tools);
            $options['allowedTools'] = implode(',', $toolNames);
        }

        $args = $this->invocation->printModeArgs($prompt, $options);

        // Open process directly - cannot use yield inside a closure passed to execute()
        $cmd = array_merge([$this->invocation->claudePath()], $this->invocation->baseArgs(), $args);

        $process = proc_open(
            $cmd,
            [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ],
            $pipes,
            null,
            [
                'ANTHROPIC_API_KEY' => getenv('ANTHROPIC_API_KEY') ?: '',
                'ANTHROPIC_AUTH_TOKEN' => getenv('ANTHROPIC_AUTH_TOKEN') ?: '',
                'ANTHROPIC_BASE_URL' => getenv('ANTHROPIC_BASE_URL') ?: '',
            ]
        );

        if (!is_resource($process)) {
            throw new \RuntimeException('Failed to start Claude Code process');
        }

        fclose($pipes[0]);

        $buffer = '';

        while (!feof($pipes[1])) {
            $chunk = fread($pipes[1], 8192);
            if ($chunk === false) {
                break;
            }
            $buffer .= $chunk;

            // Parse complete JSON objects from buffer
            while (($pos = strpos($buffer, "\n")) !== false) {
                $line = substr($buffer, 0, $pos);
                $buffer = substr($buffer, $pos + 1);

                if (str_starts_with($line, 'data: ')) {
                    $data = json_decode(substr($line, 6), true);
                    if ($data !== null) {
                        yield $this->parseChunk($data);
                    }
                }
            }
        }

        fclose($pipes[1]);
        fclose($pipes[2]);

        $exitCode = proc_close($process);

        if ($exitCode !== 0 && $exitCode !== -1) {
            $errors = stream_get_contents($pipes[2]);
            throw new \RuntimeException("Claude Code exited with code $exitCode: $errors");
        }
    }

    public function embeddings(EmbeddingsRequest $request): EmbeddingsResponse
    {
        // Claude Code doesn't directly support embeddings
        return new EmbeddingsResponse(embeddings: []);
    }

    /**
     * Build a prompt string from messages.
     *
     * @param array<Message> $messages
     */
    private function buildPrompt(array $messages): string
    {
        $parts = [];

        foreach ($messages as $msg) {
            $parts[] = match (true) {
                $msg instanceof UserMessage => "User: {$msg->content()}",
                $msg instanceof AssistantMessage => "Assistant: {$msg->content()}",
                $msg instanceof SystemMessage => "System: {$msg->content()}",
                $msg instanceof ToolResultMessage => "Tool Result: {$msg->content()}",
                default => "User: {$msg->content()}",
            };
        }

        return implode("\n\n", $parts);
    }

    /**
     * Parse a JSON response from Claude Code.
     */
    private function parseJsonResponse(string $output): CompleteResponse
    {
        $data = json_decode($output, true);

        if ($data === null) {
            // On parse failure, return the raw output as content with error indicator
            return new CompleteResponse(
                content: $output,
                reasoning: null,
                toolCalls: null,
                tokensUsed: 0,
                costUsd: 0.0,
            );
        }

        // Check for error in response
        if (isset($data['error'])) {
            $errorMsg = is_string($data['error']) ? $data['error'] : ($data['error']['message'] ?? 'Unknown error');
            return new CompleteResponse(
                content: "[Error: $errorMsg]",
                reasoning: null,
                toolCalls: null,
                tokensUsed: 0,
                costUsd: 0.0,
            );
        }

        return new CompleteResponse(
            content: $data['result'] ?? $data['content'] ?? '',
            reasoning: $data['reasoning'] ?? null,
            toolCalls: $this->parseToolCalls($data['tool_calls'] ?? []),
            tokensUsed: $data['usage']['total_tokens'] ?? 0,
            costUsd: $data['total_cost_usd'] ?? 0.0,
        );
    }

    /**
     * Parse a streaming chunk into a partial CompleteResponse.
     */
    private function parseChunk(array $data): CompleteResponse
    {
        if (isset($data['event']['delta']['type']) && $data['event']['delta']['type'] === 'text_delta') {
            return new CompleteResponse(
                content: $data['event']['delta']['text'] ?? '',
                reasoning: null,
                toolCalls: null,
                tokensUsed: 0,
                costUsd: 0.0,
            );
        }

        return new CompleteResponse(
            content: '',
            reasoning: null,
            toolCalls: null,
            tokensUsed: 0,
            costUsd: 0.0,
        );
    }

    /**
     * Parse tool calls from Claude Code response.
     *
     * @param array<array<string, mixed>> $toolCalls
     * @return array<ToolCall>|null
     */
    private function parseToolCalls(array $toolCalls): ?array
    {
        if (empty($toolCalls)) {
            return null;
        }

        return array_map(function ($tc) {
            return ToolCall::fromArray([
                'id' => $tc['id'] ?? uniqid('tool_'),
                'name' => $tc['name'] ?? $tc['function']['name'] ?? '',
                'arguments' => is_string($tc['arguments'] ?? null)
                    ? json_decode($tc['arguments'], true) ?? []
                    : ($tc['arguments'] ?? []),
            ]);
        }, $toolCalls);
    }
}
