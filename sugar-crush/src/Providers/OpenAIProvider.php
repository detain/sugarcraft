<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Providers;

use OpenAI\Contracts\ClientContract;
use SugarCraft\Crush\Messages\Message;
use SugarCraft\Crush\Messages\AssistantMessage;
use SugarCraft\Crush\Messages\UserMessage;
use SugarCraft\Crush\Messages\SystemMessage;
use SugarCraft\Crush\Messages\ToolResultMessage;
use SugarCraft\Crush\Providers\Concerns\ReasoningExtractor;
use SugarCraft\Crush\Tools\Tool;
use SugarCraft\Crush\Tools\ToolCall;
use SugarCraft\Crush\Providers\Concerns\ToolSchema;
use SugarCraft\Crush\Usage;

final readonly class OpenAIProvider implements ProviderInterface
{
    use ToolSchema;

    use ReasoningExtractor;

    public function __construct(
        private ClientContract $client,
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

        if ($request->systemPrompt !== null) {
            $params['messages'] = array_merge([['role' => 'system', 'content' => $request->systemPrompt]], $params['messages']);
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

    private function parseResponse(mixed $response): CompleteResponse
    {
        $data = $response->toArray();
        $choices = $data['choices'][0] ?? [];
        $message = $choices['message'] ?? [];

        $toolCalls = null;
        if (isset($message['tool_calls'])) {
            $toolCalls = array_map(
                fn($tc) => ToolCall::fromArray([
                    'id' => $tc['id'],
                    'name' => $tc['function']['name'],
                    'arguments' => is_string($tc['function']['arguments'] ?? null)
                        ? json_decode($tc['function']['arguments'], true) ?? []
                        : ($tc['function']['arguments'] ?? []),
                ]),
                $message['tool_calls']
            );
        }

        // NOTE: `$data` comes from openai-php's `CreateResponse::toArray()`,
        // whose `CreateResponseMessage` DTO only carries role/content/
        // function_call/tool_calls (vendor/openai-php/client/src/Responses/
        // Chat/CreateResponseMessage.php) - a `reasoning_content` field on
        // the raw HTTP JSON is silently dropped before this method ever sees
        // it. extractReasoning()'s Case 1 is therefore permanently inert
        // here; only Case 2 (`<think>` markup left inline in `content`,
        // which the DTO does preserve) can ever fire for this provider. That
        // is a real limitation of routing through the typed SDK client
        // rather than raw JSON (as SglangProvider/CustomProvider do) - fixing
        // it would mean bypassing ClientContract entirely, out of scope here.
        [$reasoning, $content] = $this->extractReasoning($message);

        // P4.S2: one parsed Usage is the source of every usage number leaving
        // this method; tokensUsed/costUsd keep their exact prior expressions
        // for legitimate wire values (a negative count clamps to 0 per Usage's
        // doctrine, as in SglangProvider::parseResponse()).
        // The is_array() guard widens tolerance by one step the old code had
        // anyway - a non-array `usage` once crashed calculateCost()'s typed
        // array parameter AFTER parseResponse had built everything else;
        // it now decodes to an all-unreported Usage, the same doctrine
        // Usage::fromArray() applies at the fork boundary ("a corrupt frame
        // costs the turn its accounting, not the turn itself").
        $usage = $this->parseUsage(is_array($data['usage'] ?? null) ? $data['usage'] : []);

        return new CompleteResponse(
            content: $content,
            reasoning: $reasoning,
            toolCalls: $toolCalls,
            tokensUsed: $usage->totalTokens,
            costUsd: $usage->costUsd,
        );
    }

    /**
     * Parses OpenAI's `usage` object into the provider-counted token BUCKETS
     * (prompt_plan.md P4.S2).
     *
     * CACHE-FIELD FINDING, measured from the SDK THIS REPO VENDORS:
     * `OpenAI\Responses\Chat\CreateResponseUsage` documents
     * `prompt_tokens_details?:array{cached_tokens:int}` and materialises it as
     * `CreateResponseUsagePromptTokensDetails::$cachedTokens` - so the cache
     * READ bucket exists on the real API and is read here when present. The
     * SDK's `toArray()` emits `prompt_tokens_details` ONLY when the server
     * sent details at all, and its member constructor coerces
     * details-present-but-`cached_tokens`-absent to 0 - a measured zero, not
     * a claim, because a response carrying prompt-token-details without that
     * member IS OpenAI reporting no cache reads.
     *
     * The protocol has NO cache-creation field (OpenAI's prompt caching is
     * implicit and bills no separately-counted write), so
     * `cacheCreationTokens` is null on every parse - recorded as the
     * legitimate "API reports none" outcome the step text demands, never
     * invented.
     *
     * `prompt_tokens` COUNTS the cached prefix (OpenAI's published API docs
     * describe `cached_tokens` as the cached PART of `prompt_tokens`; the
     * vendored DTO pins only the key's shape), and Usage's `inputTokens`
     * means "what follows the last cache breakpoint", so when both are
     * reported the fresh-input bucket is the difference, floored at 0.
     *
     * `completion_tokens` is NULLABLE in the vendored DTO's own return shape
     * (`completion_tokens: int|null`), and an explicit null is UNREPORTED,
     * not a measured zero - the distinction {@see Usage} exists to keep.
     *
     * Cost: `costUsd` on the returned Usage is exactly
     * {@see calculateCost()}'s figure — the pricing table is deliberately NOT
     * cache-aware yet (a cache read bills ~0.1x and a 5m write 1.25x the base
     * input price per the §4.16 economics; repricing on the new buckets would
     * silently change every paid turn's figure and is outside this step's
     * Goal) — reported as the follow-up it is.
     *
     * Stream arm: `completeStream()` never receives a usage object as coded —
     * no `stream_options.include_usage` is ever sent, and this provider's
     * `parseChunk()` yields hardcoded zeros. The vendored
     * `CreateStreamedResponse` CAN carry `?CreateResponseUsage` on a final
     * chunk when the flag IS set, so the carrier exists; the request and the
     * read do not. Wiring it is reported, out of this seam.
     *
     * @param array<string, mixed> $usage the decoded usage object, straight
     *                                    from `CreateResponse::toArray()`
     */
    public function parseUsage(array $usage): Usage
    {
        $prompt = self::usageInt($usage['prompt_tokens'] ?? null);
        $cached = null;
        $details = $usage['prompt_tokens_details'] ?? null;

        if (is_array($details)) {
            $cached = self::usageInt($details['cached_tokens'] ?? null);
        }

        return Usage::new(
            // The exact expression this replaces: absent-or-null total is 0.
            self::usageInt($usage['total_tokens'] ?? null) ?? 0,
            $this->calculateCost($usage),
            $prompt !== null && $cached !== null ? max(0, $prompt - $cached) : $prompt,
            self::usageInt($usage['completion_tokens'] ?? null),
            $cached,
            null, // OpenAI has no cache-creation field - never invented
        );
    }

    /**
     * One usage number as reported: absent OR JSON null stays `null`
     * (unreported — the DTO emits explicit nulls, and one must not coerce to
     * a measured zero); anything numeric counts as its int. Non-numeric junk
     * - strings, booleans, arrays, objects - decodes to UNREPORTED, never a
     * counted zero, while numeric strings and floats count as their int (a
     * float count floors, tolerating a buggy provider exactly where the old
     * strict-typed int parameters would have crashed).
     */
    private static function usageInt(mixed $value): ?int
    {
        return $value === null || !is_numeric($value) ? null : (int) $value;
    }

    /**
     * Parses a streaming chunk into a partial/delta CompleteResponse.
     *
     * This returns only the delta content from this chunk - it does NOT contain
     * accumulated content. The caller must accumulate content across chunks.
     *
     * Note: tokensUsed and costUsd are always 0 for streaming responses because
     * usage data is only available from the final chunk, not per-chunk.
     *
     * Same `reasoning_content`-dropping caveat as {@see parseResponse()}
     * applies here via `CreateStreamedResponseDelta` - see that method's
     * docblock.
     */
    private function parseChunk(mixed $chunk): CompleteResponse
    {
        $delta = $chunk->toArray()['choices'][0]['delta'] ?? [];

        [$reasoning, $content] = $this->extractReasoning($delta);

        return new CompleteResponse(
            content: $content,
            reasoning: $reasoning,
            toolCalls: null,
            tokensUsed: 0,
            costUsd: 0.0,
        );
    }

    /**
     * @param array<string, mixed> $usage
     */
    private function calculateCost(array $usage): float
    {
        $promptTokens = $usage['prompt_tokens'] ?? 0;
        $completionTokens = $usage['completion_tokens'] ?? 0;

        return ($promptTokens * $this->costPer1kTokens($this->defaultModel, 'input')
            + $completionTokens * $this->costPer1kTokens($this->defaultModel, 'output')) / 1000;
    }
}
