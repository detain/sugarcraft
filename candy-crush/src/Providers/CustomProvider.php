<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Providers;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use SugarCraft\Crush\Messages\Message;
use SugarCraft\Crush\Messages\AssistantMessage;
use SugarCraft\Crush\Messages\UserMessage;
use SugarCraft\Crush\Messages\SystemMessage;
use SugarCraft\Crush\Messages\ToolResultMessage;
use SugarCraft\Crush\Tools\Tool;
use SugarCraft\Crush\Tools\ToolCall;

final readonly class CustomProvider implements ProviderInterface
{
    public function __construct(
        private string $name,
        private string $baseUrl,
        private string $model,
        private ?string $apiKey,
        private Client $httpClient,
        private bool $supportsStreaming,
        private bool $supportsFunctionCalling,
    ) {}

    public static function openAiCompatible(
        string $name,
        string $baseUrl,
        string $model,
        ?string $apiKey = null,
        bool $supportsStreaming = true,
        bool $supportsFunctionCalling = true,
    ): self {
        $headers = [
            'Content-Type' => 'application/json',
        ];

        if ($apiKey !== null) {
            $headers['Authorization'] = 'Bearer ' . $apiKey;
        }

        $client = new Client([
            'base_uri' => $baseUrl,
            'headers' => $headers,
        ]);

        return new self(
            name: $name,
            baseUrl: $baseUrl,
            model: $model,
            apiKey: $apiKey,
            httpClient: $client,
            supportsStreaming: $supportsStreaming,
            supportsFunctionCalling: $supportsFunctionCalling,
        );
    }

    public function name(): string
    {
        return $this->name;
    }

    public function supportsStreaming(): bool
    {
        return $this->supportsStreaming;
    }

    public function supportsFunctionCalling(): bool
    {
        return $this->supportsFunctionCalling;
    }

    public function supportsVision(): bool
    {
        return false;
    }

    public function supportsJsonSchema(): bool
    {
        return false;
    }

    public function contextWindow(): int
    {
        return 128_000;
    }

    public function costPer1kTokens(string $model, string $direction): float
    {
        return 0.0; // Self-hosted, no cost
    }

    public function complete(CompleteRequest $request): CompleteResponse
    {
        $params = [
            'model' => $request->model,
            'messages' => $this->formatMessages($request->messages),
            'temperature' => $request->temperature ?? 0.7,
            'max_tokens' => $request->maxTokens ?? 4096,
        ];

        if ($request->tools !== null && $this->supportsFunctionCalling) {
            $params['tools'] = $this->formatTools($request->tools);
        }

        try {
            $response = $this->httpClient->post('/chat/completions', [
                'json' => $params,
            ]);

            $data = json_decode($response->getBody()->getContents(), true);
            return $this->parseResponse($data);
        } catch (GuzzleException $e) {
            return new CompleteResponse(
                content: '',
                isError: true,
                errorMessage: $e->getMessage(),
            );
        }
    }

    /**
     * @return \Generator<int, CompleteResponse>
     */
    public function completeStream(CompleteRequest $request): \Generator
    {
        if (!$this->supportsStreaming) {
            yield $this->complete($request);
            return;
        }

        $params = [
            'model' => $request->model,
            'messages' => $this->formatMessages($request->messages),
            'temperature' => $request->temperature ?? 0.7,
            'max_tokens' => $request->maxTokens ?? 4096,
            'stream' => true,
        ];

        if ($request->tools !== null && $this->supportsFunctionCalling) {
            $params['tools'] = $this->formatTools($request->tools);
        }

        try {
            $response = $this->httpClient->post('/chat/completions', [
                'json' => $params,
                'stream' => true,
            ]);

            $stream = $response->getBody();
            $buffer = '';

            while (!$stream->eof()) {
                $chunk = $stream->read(8192);
                $buffer .= $chunk;

                // Process complete lines in buffer
                while (($newlinePos = strpos($buffer, "\n")) !== false) {
                    $line = substr($buffer, 0, $newlinePos);
                    $buffer = substr($buffer, $newlinePos + 1);

                    $line = trim($line);
                    if (str_starts_with($line, 'data: ')) {
                        $data = json_decode(substr($line, 6), true);
                        if ($data === null) {
                            // JSON parse failed, skip
                            continue;
                        }
                        if (isset($data['choices'][0]['delta'])) {
                            yield $this->parseChunk($data);
                        }
                        if (isset($data['choices'][0]['finish_reason'])) {
                            // Stream ended
                            return;
                        }
                    }
                }
            }
        } catch (GuzzleException $e) {
            yield new CompleteResponse(
                content: '',
                isError: true,
                errorMessage: $e->getMessage(),
            );
        }
    }

    public function embeddings(EmbeddingsRequest $request): EmbeddingsResponse
    {
        try {
            $response = $this->httpClient->post('/embeddings', [
                'json' => [
                    'model' => $request->model,
                    'input' => $request->input,
                ],
            ]);

            $data = json_decode($response->getBody()->getContents(), true);
            return new EmbeddingsResponse(
                embeddings: array_map(
                    fn($item) => $item['embedding'],
                    $data['data'] ?? []
                )
            );
        } catch (GuzzleException $e) {
            return new EmbeddingsResponse(embeddings: []);
        }
    }

    /**
     * @param array<Message> $messages
     * @return array<array{role: string, content: string}|array{role: string, content: string, tool_calls?: array}|array{role: string, tool_call_id: string, content: string}>
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

    private function parseResponse(array $data): CompleteResponse
    {
        $choice = $data['choices'][0] ?? [];
        $message = $choice['message'] ?? [];

        $toolCalls = null;
        if (isset($message['tool_calls'])) {
            $toolCalls = array_map(
                fn($tc) => ToolCall::fromArray([
                    'id' => $tc['id'],
                    'name' => $tc['function']['name'],
                    'arguments' => is_string($tc['function']['arguments'] ?? '')
                        ? json_decode($tc['function']['arguments'], true) ?? []
                        : ($tc['function']['arguments'] ?? []),
                ]),
                $message['tool_calls']
            );
        }

        return new CompleteResponse(
            content: $message['content'] ?? '',
            reasoning: null,
            toolCalls: $toolCalls,
            tokensUsed: $data['usage']['total_tokens'] ?? 0,
            costUsd: 0.0,
        );
    }

    private function parseChunk(array $data): CompleteResponse
    {
        $delta = $data['choices'][0]['delta'] ?? [];

        return new CompleteResponse(
            content: $delta['content'] ?? '',
            reasoning: null,
            toolCalls: null,
            tokensUsed: 0,
            costUsd: 0.0,
        );
    }
}
