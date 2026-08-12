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
use SugarCraft\Crush\Providers\Concerns\ReasoningExtractor;
use SugarCraft\Crush\Tools\Tool;
use SugarCraft\Crush\Tools\ToolCall;
use SugarCraft\Crush\Providers\Concerns\ToolSchema;

final readonly class CustomProvider implements ProviderInterface
{
    use ToolSchema;

    use ReasoningExtractor;

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
            // See SglangProvider::openAiCompatible() - Guzzle's RFC 3986
            // relative-resolution drops a base_uri path suffix (e.g. '/v1')
            // when the request URI starts with '/'. Trailing-slash the base
            // and keep request paths below relative (no leading '/').
            'base_uri' => rtrim($baseUrl, '/') . '/',
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

    public static function openAiCompatibleFromEnv(
        string $name,
        string $baseUrl,
        string $model,
        string $apiKeyEnvVar = 'CUSTOM_PROVIDER_API_KEY',
        bool $supportsStreaming = true,
        bool $supportsFunctionCalling = true,
    ): self {
        $apiKey = getenv($apiKeyEnvVar) ?: null;

        return self::openAiCompatible(
            name: $name,
            baseUrl: $baseUrl,
            model: $model,
            apiKey: $apiKey,
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
            // Pin the reasoning-splitting flag explicitly rather than relying
            // on a self-hosted backend's default (see SglangProvider - a
            // CustomProvider instance frequently points at the same class of
            // OpenAI-compatible self-hosted server). A no-op for any parser
            // that doesn't understand it, and a no-op for `minimax-append-think`
            // specifically, which is exactly why extractReasoning()'s
            // <think>-stripping fallback below still matters regardless.
            'extra_body' => ['separate_reasoning' => true],
        ];

        if ($request->tools !== null && $this->supportsFunctionCalling) {
            $params['tools'] = $this->formatTools($request->tools);
        }

        try {
            $response = $this->httpClient->post('chat/completions', [
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
            'extra_body' => ['separate_reasoning' => true],
        ];

        if ($request->tools !== null && $this->supportsFunctionCalling) {
            $params['tools'] = $this->formatTools($request->tools);
        }

        try {
            $response = $this->httpClient->post('chat/completions', [
                'json' => $params,
                'stream' => true,
            ]);

            $stream = $response->getBody();
            $buffer = '';

            // Accumulates delta.tool_calls[] fragments across chunks, keyed
            // by the OpenAI stream's per-call `index`. Threaded as a local
            // (not an instance property) because CustomProvider is a
            // `final readonly class` per this repo's immutable-value-object
            // convention - a readonly property can't be mutated chunk over
            // chunk, so the buffer lives for the lifetime of this generator
            // call only, exactly matching one completeStream() invocation.
            $toolCallBuffer = [];

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
                            yield $this->parseChunk($data, $toolCallBuffer);
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
            $response = $this->httpClient->post('embeddings', [
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
                    'tool_calls' => $this->formatToolCalls($msg->toolCalls() ?? []),
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
                    'parameters' => $this->normalizeToolSchema($tool->inputSchema()),
                ],
            ];
        }, $tools);
    }

    /**
     * KNOWN GAP, still open: §12 D6 extracted this `tool_calls[]` walk into
     * {@see ToolCallParser\ToolCallParserInterface}, but W1.A6 converted only
     * {@see SglangProvider::parseResponse()} onto it. This provider and
     * {@see OpenAIProvider} still carry their own byte-identical copies, so
     * the duplication D6 exists to remove survives in two of three providers.
     * Moving them over - and relocating the truncation-aware
     * `decodeToolArguments()`/`malformedArgumentsWarning()` decoder into the
     * `ToolCallParser` namespace so all three share it rather than reaching it
     * through {@see SglangProvider::argumentDecoder()}'s static seam - is a
     * follow-up outside W1.A6's file scope and is unscheduled.
     */
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

        [$reasoning, $content] = $this->extractReasoning($message);

        return new CompleteResponse(
            content: $content,
            reasoning: $reasoning,
            toolCalls: $toolCalls,
            tokensUsed: $data['usage']['total_tokens'] ?? 0,
            costUsd: 0.0,
        );
    }

    /**
     * Composes two independent per-chunk concerns: W1.A1 (§12 D2) tool-call
     * fragment reassembly via {@see resolveStreamedToolCalls()}, and W1.A2
     * (§12 D3) reasoning/content splitting via {@see extractReasoning()}.
     * Kept as separate methods (rather than one inlined rewrite) so each
     * plan step's logic stays a single, independently reviewable unit - see
     * SglangProvider::parseChunk(), which mirrors this split byte-for-byte.
     *
     * @param array<int, array{id?: ?string, name?: ?string, arguments?: string}> $toolCallBuffer
     */
    private function parseChunk(array $data, array &$toolCallBuffer = []): CompleteResponse
    {
        $delta = $data['choices'][0]['delta'] ?? [];
        $finishReason = $data['choices'][0]['finish_reason'] ?? null;

        $toolCalls = $this->resolveStreamedToolCalls($delta, $finishReason, $toolCallBuffer);

        // W1.A2 (§12 D3), applied per chunk here - see
        // SglangProvider::parseChunk()'s docblock for the same per-chunk
        // chunk-boundary caveat on Case 2 (<think> stripping), which applies
        // identically here.
        [$reasoning, $content] = $this->extractReasoning($delta);

        return new CompleteResponse(
            content: $content,
            reasoning: $reasoning,
            toolCalls: $toolCalls,
            tokensUsed: 0,
            costUsd: 0.0,
        );
    }

    /**
     * W1.A1 (§12 D2): mirrors the OpenAI streaming tool-call shape -
     * `delta.tool_calls[]` arrives as successive fragments keyed by `index`,
     * with `function.arguments` streamed as string pieces that only form
     * valid JSON once the call is complete. Fragments accumulate into
     * `$toolCallBuffer` (by reference, one buffer per completeStream() call
     * - see the call site) until `finish_reason === 'tool_calls'`, at which
     * point the buffered calls are assembled into ToolCall objects and the
     * buffer is drained.
     *
     * Previously this always returned `toolCalls: null` - byte-for-byte the
     * same bug as SglangProvider::parseChunk() - so a delta chunk carrying
     * only `tool_calls` (no `content`) had its fragments read then
     * discarded here every time - `completeStream()` could never deliver a
     * tool call, only `complete()` (non-streaming) could.
     *
     * @param array<string, mixed> $delta
     * @param array<int, array{id?: ?string, name?: ?string, arguments?: string}> $toolCallBuffer
     * @return ?array<int, ToolCall>
     */
    private function resolveStreamedToolCalls(array $delta, ?string $finishReason, array &$toolCallBuffer): ?array
    {
        foreach ($delta['tool_calls'] ?? [] as $tc) {
            $idx = $tc['index'] ?? 0;
            $toolCallBuffer[$idx]['id'] ??= $tc['id'] ?? null;
            $toolCallBuffer[$idx]['name'] ??= $tc['function']['name'] ?? null;
            $toolCallBuffer[$idx]['arguments'] =
                ($toolCallBuffer[$idx]['arguments'] ?? '') . ($tc['function']['arguments'] ?? '');
        }

        if ($finishReason !== 'tool_calls' || $toolCallBuffer === []) {
            return null;
        }

        $toolCalls = array_map(
            fn (array $tc): ToolCall => ToolCall::fromArray([
                'id' => $tc['id'] ?? '',
                'name' => $tc['name'] ?? '',
                'arguments' => json_decode($tc['arguments'] ?? '{}', true) ?? [],
            ]),
            $toolCallBuffer
        );
        $toolCallBuffer = [];

        return $toolCalls;
    }
}
