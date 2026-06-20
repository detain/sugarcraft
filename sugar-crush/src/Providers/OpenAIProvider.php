<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Providers;

use OpenAI\Client;
use OpenAI\Responses\Completions\CompletionResponse;
use SugarCraft\Crush\Messages\Message;
use SugarCraft\Crush\Messages\AssistantMessage;
use SugarCraft\Crush\Messages\UserMessage;
use SugarCraft\Crush\Messages\SystemMessage;
use SugarCraft\Crush\Messages\ToolResultMessage;
use SugarCraft\Crush\Tools\Tool;
use SugarCraft\Crush\Tools\ToolCall;

final readonly class OpenAIProvider implements ProviderInterface
{
    public function __construct(
        private Client $client,
        private string $defaultModel = 'gpt-4o',
    ) {}

    public function name(): string
    {
        return 'openai';
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
        return true;
    }

    public function supportsJsonSchema(): bool
    {
        return false;
    }

    public function contextWindow(): int
    {
        return match ($this->defaultModel) {
            'gpt-4o' => 128_000,
            'gpt-4-turbo' => 128_000,
            'gpt-4' => 8_192,
            'gpt-3.5-turbo' => 16_385,
            default => 8_192,
        };
    }

    public function costPer1kTokens(string $model, string $direction): float
    {
        // Approximate pricing (should verify current prices)
        return match ($model) {
            'gpt-4o' => $direction === 'input' ? 0.005 : 0.015,
            'gpt-4-turbo' => $direction === 'input' ? 0.01 : 0.03,
            'gpt-4' => $direction === 'input' ? 0.03 : 0.06,
            'gpt-3.5-turbo' => $direction === 'input' ? 0.0005 : 0.0015,
            default => 0.01,
        };
    }

    public function complete(CompleteRequest $request): CompleteResponse
    {
        $params = [
            'model' => $request->model,
            'messages' => $this->formatMessages($request->messages),
            'temperature' => $request->temperature ?? 0.7,
            'max_tokens' => $request->maxTokens ?? 4096,
        ];

        if ($request->tools !== null) {
            $params['tools'] = $this->formatTools($request->tools);
        }

        if ($request->systemPrompt !== null) {
            $params['messages'] = array_merge(
                [['role' => 'system', 'content' => $request->systemPrompt]],
                $params['messages']
            );
        }

        $response = $this->client->chat()->create($params);

        return $this->parseResponse($response);
    }

    /**
     * Streams completion responses as a generator of deltas.
     *
     * Each yielded CompleteResponse contains only the delta/content from that chunk.
     * The caller is responsible for accumulating content across chunks.
     *
     * Note: tokensUsed and costUsd will be 0 for all chunks - usage data is only
     * available when the stream completes, not per-chunk.
     *
     * @return \Generator<int, CompleteResponse>
     */
    public function completeStream(CompleteRequest $request): \Generator
    {
        $params = [
            'model' => $request->model,
            'messages' => $this->formatMessages($request->messages),
            'temperature' => $request->temperature ?? 0.7,
            'max_tokens' => $request->maxTokens ?? 4096,
            'stream' => true,
        ];

        if ($request->tools !== null) {
            $params['tools'] = $this->formatTools($request->tools);
        }

        $stream = $this->client->chat()->createStreamed($params);

        foreach ($stream as $chunk) {
            yield $this->parseChunk($chunk);
        }
    }

    public function embeddings(EmbeddingsRequest $request): EmbeddingsResponse
    {
        $response = $this->client->embeddings()->create([
            'model' => $request->model,
            'input' => $request->input,
        ]);

        return new EmbeddingsResponse(
            embeddings: array_map(
                fn($item) => $item['embedding'],
                $response->toArray()['data'] ?? []
            )
        );
    }

    /**
     * @param array<Message> $messages
     * @return array<array{role: string, content: string}|array{role: string, content: string, tool_calls: array}|array{role: string, tool_call_id: string, content: string}>
     */
    private function formatMessages(array $messages): array
    {
        return array_map(function (Message $msg) {
            return match (true) {
                $msg instanceof UserMessage => ['role' => 'user', 'content' => $msg->content()],
                $msg instanceof AssistantMessage => array_filter([
                    'role' => 'assistant',
                    'content' => $msg->content(),
                    'tool_calls' => $msg->toolCalls(),
                ]),
                $msg instanceof SystemMessage => ['role' => 'system', 'content' => $msg->content()],
                $msg instanceof ToolResultMessage => [
                    'role' => 'tool',
                    'tool_call_id' => $msg->toolCallId(),
                    'content' => $msg->content(),
                ],
                default => ['role' => 'user', 'content' => $msg->content()],
            };
        }, $messages);
    }

    /**
     * @param array<Tool> $tools
     * @return array<array{type: string, function: array{name: string, description: string, parameters: array}}>
     */
    private function formatTools(array $tools): array
    {
        return array_map(function (Tool $tool) {
            return [
                'type' => 'function',
                'function' => [
                    'name' => $tool->name(),
                    'description' => $tool->description(),
                    'parameters' => $tool->inputSchema(),
                ],
            ];
        }, $tools);
    }

    private function parseResponse(CompletionResponse $response): CompleteResponse
    {
        $choices = $response->toArray()['choices'][0] ?? [];
        $message = $choices['message'] ?? [];

        $toolCalls = null;
        if (isset($message['tool_calls'])) {
            $toolCalls = array_map(
                fn($tc) => ToolCall::fromArray([
                    'id' => $tc['id'],
                    'name' => $tc['function']['name'],
                    'arguments' => json_decode($tc['function']['arguments'], true) ?? [],
                ]),
                $message['tool_calls']
            );
        }

        return new CompleteResponse(
            content: $message['content'] ?? '',
            reasoning: null,
            toolCalls: $toolCalls,
            tokensUsed: $response->usage['total_tokens'] ?? 0,
            costUsd: $this->calculateCost($response),
        );
    }

    /**
     * Parses a streaming chunk into a partial/delta CompleteResponse.
     *
     * This returns only the delta content from this chunk - it does NOT contain
     * accumulated content. The caller must accumulate content across chunks.
     *
     * Note: tokensUsed and costUsd are always 0 for streaming responses because
     * usage data is only available from the final chunk, not per-chunk.
     */
    private function parseChunk(mixed $chunk): CompleteResponse
    {
        $delta = $chunk->toArray()['choices'][0]['delta'] ?? [];

        return new CompleteResponse(
            content: $delta['content'] ?? '',
            reasoning: null,
            toolCalls: null,
            tokensUsed: 0,
            costUsd: 0.0,
        );
    }

    private function calculateCost(CompletionResponse $response): float
    {
        $usage = $response->usage ?? [];
        $promptTokens = $usage['prompt_tokens'] ?? 0;
        $completionTokens = $usage['completion_tokens'] ?? 0;

        return ($promptTokens * $this->costPer1kTokens($this->defaultModel, 'input')
            + $completionTokens * $this->costPer1kTokens($this->defaultModel, 'output')) / 1000;
    }
}
