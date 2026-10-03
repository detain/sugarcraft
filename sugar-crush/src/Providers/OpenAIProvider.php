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
use SugarCraft\Crush\Providers\Concerns\ReassemblesStreamedToolCalls;
use SugarCraft\Crush\Tools\Tool;
use SugarCraft\Crush\Tools\ToolCall;
use SugarCraft\Crush\Providers\Concerns\ToolSchema;
use SugarCraft\Crush\Usage;

final readonly class OpenAIProvider implements ProviderInterface
{
    use ToolSchema;

    use ReasoningExtractor;

    use ReassemblesStreamedToolCalls;

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
     * Audit A14: built-in USD-per-1K rates for CACHED prompt tokens
     * (`prompt_tokens_details.cached_tokens`), from OpenAI's published
     * cached-input column (per 1M: gpt-4o 1.25, gpt-4o-mini 0.075, gpt-4.1
     * 0.50, gpt-4.1-mini 0.10).
     *
     * A PRICE_TABLE model missing here has no prompt caching (gpt-4-turbo,
     * gpt-4, gpt-3.5-turbo), so its cached tokens bill at the full input rate
     * — a discount is never guessed. Keyed separately rather than as a third
     * PRICE_TABLE column so the two-direction row shape stays what
     * {@see costPer1kTokens()} and its callers read.
     *
     * @var array<string, float>
     */
    private const CACHED_INPUT_TABLE = [
        'gpt-4o' => 0.00125,
        'gpt-4o-mini' => 0.000075,
        'gpt-4.1' => 0.0005,
        'gpt-4.1-mini' => 0.0001,
    ];

    /**
     * Audit 15b-15 residual: the model families whose chat-completions
     * endpoint reads `image_url` parts, matched as the exact id or the id
     * followed by `-` (so dated snapshots like `gpt-4o-2024-08-06` and the
     * `-mini`/`-nano` siblings ride along). Anything not listed - `gpt-4`,
     * `gpt-3.5-turbo`, a model released after this table - answers no
     * vision, so an attached image goes out as the named text placeholder
     * with its "not seen" notice instead of failing the turn on a 400. The
     * provider block's `supportsVision` key overrides the table either way.
     *
     * @var list<string>
     */
    private const VISION_MODEL_FAMILIES = [
        'gpt-4o',
        'chatgpt-4o',
        'gpt-4-turbo',
        'gpt-4.1',
        'gpt-4.5',
        'gpt-5',
        'o1',
        'o3',
        'o4-mini',
    ];

    /**
     * Text-only members of a {@see VISION_MODEL_FAMILIES} family, matched the
     * same way and checked first: the `o1-mini`/`o1-preview`/`o3-mini`
     * reasoning models, the pre-vision `gpt-4-turbo-preview` aliases, and
     * gpt-4o's audio/realtime variants.
     *
     * @var list<string>
     */
    private const TEXT_ONLY_MODELS = [
        'o1-mini',
        'o1-preview',
        'o3-mini',
        'gpt-4-turbo-preview',
        'gpt-4o-audio-preview',
        'gpt-4o-realtime-preview',
        'gpt-4o-mini-audio-preview',
        'gpt-4o-mini-realtime-preview',
    ];

    /**
     * @param array<string, array{input?: float|int, output?: float|int, cached?: float|int}> $modelPrices
     *        Operator-declared USD-per-1M rates from the user-tier
     *        `modelPrices` config key, overriding/extending {@see PRICE_TABLE}
     *        (per-1M because that is the unit every price sheet publishes in;
     *        the divide to per-1K happens once in {@see declaredRate()}). The
     *        optional `cached` rate prices cache-hit prompt tokens; an entry
     *        without it bills them at its own `input` rate (see
     *        {@see cachedInputPer1k()}).
     * @param int|null $contextWindowOverride Audit A13: the operator's own
     *        window for the configured model, from the `contextWindow`
     *        setting ({@see ProviderFactory::createOpenAI()}). It replaces
     *        the built-in table in {@see contextWindow()} - the table cannot
     *        know a model released after it was written, and before this an
     *        unknown model could only fall to ContextWindow's generic
     *        fallback. Null keeps the table.
     *
     * @param bool|null $supportsVision Audit 15b-15 residual: the `openai`
     *        provider block's `supportsVision` key. Null leaves the answer to
     *        {@see VISION_MODEL_FAMILIES}; a bool overrides it, for a model
     *        the table does not know.
     *
     * @throws \InvalidArgumentException when the override is not positive
     */
    public function __construct(
        private ClientContract $client,
        private string $defaultModel = 'gpt-4o',
        private array $modelPrices = [],
        private ?int $contextWindowOverride = null,
        private ?bool $supportsVision = null,
    ) {
        if ($contextWindowOverride !== null && $contextWindowOverride < 1) {
            throw new \InvalidArgumentException(sprintf(
                'OpenAIProvider contextWindow must be a positive token count, got %d.',
                $contextWindowOverride,
            ));
        }
    }

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

    /**
     * Whether the configured model reads image parts: the provider block's
     * override when set, else {@see VISION_MODEL_FAMILIES} minus
     * {@see TEXT_ONLY_MODELS}. Before this every model answered yes, so an
     * image sent to a text-only model failed the turn with the API's error.
     */
    public function supportsVision(): bool
    {
        if ($this->supportsVision !== null) {
            return $this->supportsVision;
        }

        $model = strtolower($this->defaultModel);
        $inFamily = static function (string $family) use ($model): bool {
            return $model === $family || str_starts_with($model, $family . '-');
        };

        foreach (self::TEXT_ONLY_MODELS as $textOnly) {
            if ($inFamily($textOnly)) {
                return false;
            }
        }

        foreach (self::VISION_MODEL_FAMILIES as $family) {
            if ($inFamily($family)) {
                return true;
            }
        }

        return false;
    }

    public function supportsJsonSchema(): bool
    {
        return false;
    }

    /**
     * Every {@see PRICE_TABLE} model is sized here (audit A13: gpt-4o-mini
     * and the gpt-4.1 pair were priced but fell to an 8,192 default, so
     * Chat's context tiers fired after a few messages on 128k / 1M models).
     * An unknown model answers 0 — "unknown" per
     * {@see ProviderInterface::contextWindow()} — so
     * {@see \SugarCraft\Crush\Context\ContextWindow::resolve()} applies its
     * one named fallback instead of this file guessing a denominator.
     *
     * A configured `contextWindow` (the constructor's override) answers
     * first, for any model: the operator's figure is the narrower statement.
     */
    public function contextWindow(): int
    {
        if ($this->contextWindowOverride !== null) {
            return $this->contextWindowOverride;
        }

        return match ($this->defaultModel) {
            'gpt-4o' => 128_000,
            'gpt-4o-mini' => 128_000,
            'gpt-4.1' => 1_047_576,
            'gpt-4.1-mini' => 1_047_576,
            'gpt-4-turbo' => 128_000,
            'gpt-4' => 8_192,
            'gpt-3.5-turbo' => 16_385,
            default => 0,
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
            return $this->declaredRate($model, $direction);
        }

        $row = self::PRICE_TABLE[$model] ?? null;
        if ($row === null) {
            return null;
        }

        return $direction === 'input' ? $row[0] : $row[1];
    }

    /**
     * One operator-declared rate, per-1K, for a model the operator NAMED in
     * `modelPrices`; null when the declaration fails validation.
     */
    private function declaredRate(string $model, string $key): ?float
    {
        $entry = $this->modelPrices[$model];
        $declared = is_array($entry) ? ($entry[$key] ?? null) : null;
        if (!is_numeric($declared)) {
            return null;
        }
        $rate = ((float) $declared) / 1000; // config speaks USD-per-1M

        // Zero is legal (a genuinely free model); sign-flipped or
        // non-finite rates are not, and go the loud unpriced road.
        return $rate >= 0.0 && is_finite($rate) ? $rate : null;
    }

    /**
     * USD per 1K CACHED prompt tokens, given the model's already-resolved
     * input rate; null only when the operator declared a `cached` rate that
     * fails validation (the same loud road as a broken input/output rate).
     *
     * With no published discount on file the answer is the full input rate:
     * billing a cache hit like any other prompt token can only overstate,
     * never invent a saving. An operator entry is authoritative for its model
     * (review r90), so a named model WITHOUT a `cached` key does not borrow
     * the built-in discount of the row it replaced — that discount was
     * quoted against a different input rate.
     */
    private function cachedInputPer1k(string $model, float $inputRate): ?float
    {
        if (array_key_exists($model, $this->modelPrices)) {
            $entry = $this->modelPrices[$model];
            if (!is_array($entry) || !array_key_exists('cached', $entry)) {
                return $inputRate;
            }

            return $this->declaredRate($model, 'cached');
        }

        return self::CACHED_INPUT_TABLE[$model] ?? $inputRate;
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

        // X-31a: `delta.tool_calls[]` fragments, buffered across chunks until
        // the stream declares the calls complete (see
        // {@see ReassemblesStreamedToolCalls}). A local, not a property: this
        // is a readonly class and the buffer lives exactly one stream.
        $toolCallBuffer = [];

        // The LAST non-null finish_reason, read after the loop to decide how
        // fragments a stream left buffered are flushed (audit 15a A4's rule).
        $streamFinishReason = null;

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

            $finishReason = $data['choices'][0]['finish_reason'] ?? null;
            $streamFinishReason = is_string($finishReason) ? $finishReason : $streamFinishReason;

            yield $this->parseChunk($data, $toolCallBuffer);
        }

        // Fragments still buffered were never declared complete: the stream
        // ended on `stop`, on `length`, or with no finish at all. Flushed
        // decode-or-drop, BEFORE the usage carrier so the bill stays the
        // stream's last event; an `error` end flushes nothing.
        if ($toolCallBuffer !== [] && $streamFinishReason !== 'error') {
            $truncated = $streamFinishReason === null
                || in_array($streamFinishReason, self::TRUNCATED_FINISH_REASONS, true);
            $flushed = self::flushStreamedToolCalls($toolCallBuffer, $truncated, $streamFinishReason, 'OpenAIProvider');

            if ($flushed !== null) {
                yield new CompleteResponse(
                    content: '',
                    toolCalls: $flushed,
                    tokensUsed: 0,
                    costUsd: 0.0,
                    truncated: $truncated,
                );
            }
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
                // Audit 15b-15: inlined files, plus image_url parts when an
                // image was attached (only ever handed to a vision provider).
                $msg instanceof UserMessage => ['role' => 'user', 'content' => AttachmentEncoding::openAiContent($msg)],
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
                    // `is_array()` rather than `?? []`: a payload decoding to
                    // a scalar (`12`, `"text"`) used to reach ToolCall's
                    // `array $arguments` and die as a TypeError mid-turn.
                    'arguments' => is_string($tc['function']['arguments'] ?? null)
                        ? (is_array($decoded = json_decode($tc['function']['arguments'], true)) ? $decoded : [])
                        : ($tc['function']['arguments'] ?? []),
                    // Audit A11, as on the Custom and Sglang paths: a broken
                    // payload is refused by Runtime, never run as `[]`.
                    'argumentsError' => ToolCall::argumentsErrorFor($tc['function']['arguments'] ?? null),
                    // Audit A23: replayed verbatim in history, so `{}` stays
                    // `{}` (see ToolCall::rawArguments()).
                    'rawArguments' => $tc['function']['arguments'] ?? null,
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
     * model), and that figure is CACHE-AWARE (audit A14): the cached part of
     * `prompt_tokens` bills at the model's cached-input rate, the fresh
     * remainder at the input rate — the same split the buckets below draw.
     * OpenAI bills no cache write, so there is no write premium to add.
     * When no rate is on file for the model, `costUsd` stays its honest
     * lower bound 0.0 and {@see Usage::$unpricedModel} carries the name so
     * the transcript notice and the spend-cap disclosure
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
     * Tool calls (X-31a): this chunk's `delta.tool_calls[]` fragments go into
     * $toolCallBuffer, and the chunk whose `finish_reason` is `tool_calls`
     * carries every call they assembled. Until X-31a this hard-coded
     * `toolCalls: null`, and since {@see supportsStreaming()} is always true
     * no OpenAI tool call ever reached Runtime.
     *
     * @param array<string, mixed> $data the chunk's decoded array form
     * @param array<int, array{id?: ?string, name?: ?string, arguments?: string}> $toolCallBuffer
     */
    private function parseChunk(array $data, array &$toolCallBuffer = []): CompleteResponse
    {
        $choice = $data['choices'][0] ?? [];
        $delta = is_array($choice['delta'] ?? null) ? $choice['delta'] : [];
        $finishReason = $choice['finish_reason'] ?? null;

        $toolCalls = $this->reassembleStreamedToolCalls($delta, is_string($finishReason) ? $finishReason : null, $toolCallBuffer);

        [$reasoning, $content] = $this->extractReasoning($delta);

        // E707 (round 81): the chunk that closes a choice carries its
        // `finish_reason` beside the delta - `length` on this wire means the
        // server ran out of output budget mid-reply. The flag rides that same
        // chunk; no extra frame is needed because the stop arrives WITH data
        // the fold above already consumes.
        return new CompleteResponse(
            content: $content,
            reasoning: $reasoning,
            toolCalls: $toolCalls,
            tokensUsed: 0,
            costUsd: 0.0,
            truncated: in_array($finishReason, self::TRUNCATED_FINISH_REASONS, true),
        );
    }

    /**
     * The dollar figure for one usage document at one model's rates, or null
     * when any rate the document needs is unpriced — a half-known bill is not
     * a bill: the completion side arriving free while the input side is
     * unknown would understate every real call.
     *
     * The figure is `(fresh × input + cached × cachedInput + completion ×
     * output) / 1000`, where `cached` is
     * `prompt_tokens_details.cached_tokens` and `fresh` the rest of
     * `prompt_tokens` — the wire's prompt count INCLUDES the cached prefix,
     * so billing all of it at the input rate (the pre-A14 formula)
     * overstated long agentic sessions, whose prompts are mostly cache hits,
     * by up to ~2x and tripped `/budget` caps early. Cached is capped at the
     * prompt so a server over-reporting it cannot bill tokens never sent. A
     * model with no cached rate on file bills cached at the full input rate
     * ({@see cachedInputPer1k()}).
     *
     * Counts read through {@see usageInt()}, so a junk member (a nested
     * array, a non-numeric string) prices as unreported rather than crashing
     * the arithmetic.
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

        $promptTokens = self::usageInt($usage['prompt_tokens'] ?? null) ?? 0;
        $completionTokens = self::usageInt($usage['completion_tokens'] ?? null) ?? 0;
        $details = $usage['prompt_tokens_details'] ?? null;
        $cachedTokens = is_array($details) ? (self::usageInt($details['cached_tokens'] ?? null) ?? 0) : 0;
        $cachedTokens = max(0, min($cachedTokens, $promptTokens));

        // Only consult the cached rate when there is something to price at
        // it: a broken operator `cached` declaration should not void a turn
        // that had no cache hits, whose bill is fully known without it.
        $cachedRate = $cachedTokens > 0 ? $this->cachedInputPer1k($model, $input) : $input;
        if ($cachedRate === null) {
            return null;
        }

        return (
            ($promptTokens - $cachedTokens) * $input
            + $cachedTokens * $cachedRate
            + $completionTokens * $output
        ) / 1000;
    }
}
