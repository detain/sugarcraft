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

    /**
     * E707 (round 81): the chat-completions `finish_reason` values that mean
     * the reply hit the OUTPUT ceiling rather than ending on its own. OpenAI's
     * documented terminating set is `stop` / `length` / `tool_calls` /
     * `content_filter`, of which only `length` is a capacity stop;
     * `content_filter` is a safety stop and stays silent here on purpose -
     * surfacing it under the same notice would mislabel a moderation event as
     * a tunable limit. The Sglang port pins the same-shaped guard over its own
     * `length`/`abort` vocabulary.
     *
     * @var list<string>
     */
    private const TRUNCATED_FINISH_REASONS = ['length'];

    /**
     * Built-in USD-per-1K rates, keyed model => [input, output].
     *
     * A model missing here is UNPRICED — {@see costPer1kTokens()} answers
     * null and the turn bills 0.0 with an {@see Usage::$unpricedModel}
     * signal — never guessed. There is deliberately no `default` row: the
     * table used to fabricate $0.01/1k for every unknown model, which
     * invented dollars the provider never billed and quietly blinded E20
     * spend caps against a made-up rate. Figures current as of the October
     * 2026 audit that minted this constant; the OLD gpt-4o row (5/15) was
     * four years stale — the launched 2024 repricing to 2.5/10 per 1M is
     * what the row now carries. Operators price anything else through the
     * user-tier `modelPrices` config map ({@see \SugarCraft\Crush\Config\LayeredSettings}).
     *
     * @var array<string, array{0: float, 1: float}>
     */
    private const PRICE_TABLE = [
        'gpt-4o' => [0.0025, 0.01],
        'gpt-4o-mini' => [0.00015, 0.0006],
        'gpt-4.1' => [0.002, 0.008],
        'gpt-4.1-mini' => [0.0004, 0.0016],
        'gpt-4-turbo' => [0.01, 0.03],
        'gpt-4' => [0.03, 0.06],
        'gpt-3.5-turbo' => [0.0005, 0.0015],
    ];

    /**
     * @param array<string, array{input?: float|int, output?: float|int}> $modelPrices
     *        Operator-declared USD-per-1M rates from the user-tier
     *        `modelPrices` config key, overriding/extending {@see PRICE_TABLE}
     *        (per-1M because that is the unit every price sheet publishes in;
     *        the divide to per-1K happens once in {@see costPer1kTokens()}).
     */
    public function __construct(
        private ClientContract $client,
        private string $defaultModel = 'gpt-4o',
        private array $modelPrices = [],
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

    /**
     * @return null|float USD per 1K tokens, or null when NEITHER the operator
     *         `modelPrices` map nor {@see PRICE_TABLE} names a rate for the
     *         model — the loud-unknown answer required by the billing audit
     *         (no fabricated default, ever).
     */
    public function costPer1kTokens(string $model, string $direction): ?float
    {
        // Operator declaration wins over the built-in row: the point of the
        // config seam is repricing what this file gets wrong or does not know,
        // including a stale shipped row. And once the operator NAMES a model
        // the declaration is authoritative for it — a rate that fails to
        // validate answers unpriced WITHOUT falling back to the shipped row
        // the operator just overrode (review r90: a typo'd override used to
        // silently reprice at the stale number, or worse, at a NEGATIVE one,
        // which Usage::new() floors to a fake-free 0.0 with no unpriced
        // signal at all).
        if (array_key_exists($model, $this->modelPrices)) {
            $entry = $this->modelPrices[$model];
            $declared = is_array($entry) ? ($entry[$direction] ?? null) : null;
            if (!is_numeric($declared)) {
                return null;
            }
            $rate = ((float) $declared) / 1000; // config speaks USD-per-1M

            // Zero is legal (a genuinely free model); sign-flipped or
            // non-finite rates are not, and go the loud unpriced road.
            return $rate >= 0.0 && is_finite($rate) ? $rate : null;
        }

        $row = self::PRICE_TABLE[$model] ?? null;
        if ($row === null) {
            return null;
        }

        return $direction === 'input' ? $row[0] : $row[1];
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

        // Billing fix: the request's model is what the wire billed, so it is
        // what must be priced — `$defaultModel` is this object's fallback
        // NAME, not this call's subject.
        return $this->parseResponse($response, $request->model);
    }

    /**
     * Streams completion responses as a generator of deltas.
     *
     * Each yielded CompleteResponse contains only the delta/content from that chunk.
     * The caller is responsible for accumulating content across chunks.
     *
     * BILLING FIX (audit-crush-core finding 1): the request now sets
     * `stream_options.include_usage` — the same spelling
     * {@see SglangProvider::completeStream()} ships — so the wire sends a
     * final, zero-choice usage frame, and that frame is parsed through the
     * IDENTICAL batch path ({@see parseUsage()}, priced at `$request->model`)
     * and yielded as the terminal {@see CompleteResponse} carrying
     * `tokensUsed`/`costUsd`/`usage`. Before this, EVERY streamed paid turn
     * billed $0 and reported 0 tokens: the E17 calibration had no streamed
     * observation to pair, and E20 spend caps were structurally neutered.
     * Content chunks still carry zeros — compliant per
     * {@see ProviderInterface::completeStream()}'s per-delta law, they sum to
     * nothing and the total rides exactly once on the terminal frame.
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
            // Set next to `stream`, never in a shared builder: the batch
            // request body must stay byte-identical (Sglang's law, and the
            // openai-php createStreamed() path forwards this array verbatim).
            'stream_options' => ['include_usage' => true],
        ];

        if ($request->tools !== null) {
            $params['tools'] = $this->formatTools($request->tools);
        }

        if ($request->systemPrompt !== null) {
            $params['messages'] = array_merge([['role' => 'system', 'content' => $request->systemPrompt]], $params['messages']);
        }

        $stream = $this->client->chat()->createStreamed($params);

        $streamUsage = null;

        foreach ($stream as $chunk) {
            $data = $chunk->toArray();

            // The include_usage terminal frame: usage present, choices empty.
            // Captured, NOT yielded as a delta — the Sglang gate
            // ({@see SglangProvider::completeStream()}) verbatim in shape, so
            // a content-less usage document never masquerades as an empty
            // token chunk.
            if (!isset($data['choices'][0]) && is_array($data['usage'] ?? null)) {
                $streamUsage = $this->parseUsage($data['usage'], $request->model);
                continue;
            }

            yield $this->parseChunk($data);
        }

        if ($streamUsage !== null) {
            yield new CompleteResponse(
                content: '',
                tokensUsed: $streamUsage->totalTokens,
                costUsd: $streamUsage->costUsd,
                usage: $streamUsage,
            );
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

    private function parseResponse(mixed $response, ?string $pricingModel = null): CompleteResponse
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
        // E17: the parsed object rides out on `usage:` with the projections, so
        // prompt/completion/cached buckets reach Runtime instead of stopping
        // at this method.
        $usage = $this->parseUsage(is_array($data['usage'] ?? null) ? $data['usage'] : [], $pricingModel);

        // E707 (round 81): `finish_reason: length` is the wire saying the reply
        // ran into max_tokens mid-sentence. The flag rides out on the carrier;
        // Runtime folds it into the assistant message and Chat surfaces one
        // transcript notice.
        return new CompleteResponse(
            content: $content,
            reasoning: $reasoning,
            toolCalls: $toolCalls,
            tokensUsed: $usage->totalTokens,
            costUsd: $usage->costUsd,
            usage: $usage,
            truncated: in_array($choices['finish_reason'] ?? null, self::TRUNCATED_FINISH_REASONS, true),
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
     * {@see calculateCost()}'s figure for `$pricingModel` (falling back to
     * this provider's default NAME only when the caller passes no request
     * model) — the pricing table is deliberately NOT cache-aware yet (a cache
     * read bills ~0.1x and a 5m write 1.25x the base input price per the
     * §4.16 economics; repricing on the new buckets would silently change
     * every paid turn's figure and is outside this step's Goal) — reported as
     * the follow-up it is. When no rate is on file for the model, `costUsd`
     * stays its honest lower bound 0.0 and {@see Usage::$unpricedModel}
     * carries the name so the transcript notice and the spend-cap disclosure
     * can say WHICH zero it is — the fabricated $0.01/1k fallback this
     * replaces invented a bill the provider never sent.
     *
     * Stream arm: `completeStream()` sends `stream_options.include_usage` and
     * pipes the terminal zero-choice frame's usage document through THIS
     * method, priced at the request model — the batch and stream price paths
     * are one path by construction.
     *
     * @param array<string, mixed> $usage the decoded usage object, straight
     *                                    from `CreateResponse::toArray()`
     * @param ?string              $pricingModel the model THIS call billed —
     *                                           pass the request's model; the
     *                                           null fallback prices at the
     *                                           default NAME and exists only
     *                                           to keep the 1-arg public API
     */
    public function parseUsage(array $usage, ?string $pricingModel = null): Usage
    {
        $prompt = self::usageInt($usage['prompt_tokens'] ?? null);
        $cached = null;
        $details = $usage['prompt_tokens_details'] ?? null;

        if (is_array($details)) {
            $cached = self::usageInt($details['cached_tokens'] ?? null);
        }

        $cost = $this->calculateCost($usage, $pricingModel);

        return Usage::new(
            // The exact expression this replaces: absent-or-null total is 0.
            self::usageInt($usage['total_tokens'] ?? null) ?? 0,
            // Unpriced accounts as 0.0 — the LOWER BOUND — with the name
            // carried beside it so accounting can tell it from a real free
            // call; never as a fabricated rate.
            $cost ?? 0.0,
            $prompt !== null && $cached !== null ? max(0, $prompt - $cached) : $prompt,
            self::usageInt($usage['completion_tokens'] ?? null),
            $cached,
            null, // OpenAI has no cache-creation field - never invented
            unpricedModel: $cost === null ? ($pricingModel ?? $this->defaultModel) : null,
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
     * Delta chunks carry zero usage by design; the turn's one usage figure
     * arrives on the terminal frame — see {@see completeStream()}'s BILLING
     * FIX paragraph.
     *
     * Same `reasoning_content`-dropping caveat as {@see parseResponse()}
     * applies here via `CreateStreamedResponseDelta` - see that method's
     * docblock.
     *
     * @param array<string, mixed> $data the chunk's decoded array form
     */
    private function parseChunk(array $data): CompleteResponse
    {
        $choice = $data['choices'][0] ?? [];
        $delta = $choice['delta'] ?? [];

        [$reasoning, $content] = $this->extractReasoning($delta);

        // E707 (round 81): the chunk that closes a choice carries its
        // `finish_reason` beside the delta - `length` on this wire means the
        // server ran out of output budget mid-reply. The flag rides that same
        // chunk; no extra frame is needed because the stop arrives WITH data
        // the fold above already consumes.
        return new CompleteResponse(
            content: $content,
            reasoning: $reasoning,
            toolCalls: null,
            tokensUsed: 0,
            costUsd: 0.0,
            truncated: in_array($choice['finish_reason'] ?? null, self::TRUNCATED_FINISH_REASONS, true),
        );
    }

    /**
     * The dollar figure for one usage document at one model's rates, or null
     * when either side is unpriced — a half-known bill is not a bill: the
     * completion side arriving free while the input side is unknown would
     * understate every real call.
     *
     * @param array<string, mixed> $usage
     * @param ?string              $model the model to price; null means the
     *                                    legacy one-arg spelling and prices
     *                                    at this object's default model
     */
    private function calculateCost(array $usage, ?string $model = null): ?float
    {
        $model ??= $this->defaultModel;
        $input = $this->costPer1kTokens($model, 'input');
        $output = $this->costPer1kTokens($model, 'output');

        if ($input === null || $output === null) {
            return null;
        }

        $promptTokens = $usage['prompt_tokens'] ?? 0;
        $completionTokens = $usage['completion_tokens'] ?? 0;

        return ($promptTokens * $input + $completionTokens * $output) / 1000;
    }
}
