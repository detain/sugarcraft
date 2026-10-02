<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Providers;

use Aws\BedrockRuntime\BedrockRuntimeClient;
use Aws\Exception\AwsException;
use SugarCraft\Crush\Messages\Message;
use SugarCraft\Crush\Messages\AssistantMessage;
use SugarCraft\Crush\Messages\UserMessage;
use SugarCraft\Crush\Messages\SystemMessage;
use SugarCraft\Crush\Messages\ToolResultMessage;
use SugarCraft\Crush\Providers\Concerns\HttpClientDefaults;
use SugarCraft\Crush\Usage;

/**
 * Amazon Bedrock provider, speaking the Converse API.
 *
 * WHY THE *RUNTIME* CLIENT AND NOT `Aws\Bedrock\BedrockClient`
 * -----------------------------------------------------------
 * The AWS SDK splits Bedrock across two entirely separate services with two
 * separate API models:
 *
 *   - `bedrock` (2023-04-20, `Aws\Bedrock\BedrockClient`) is the CONTROL
 *     plane - guardrails, evaluation jobs, model customisation, provisioned
 *     throughput. It has no inference operations at all.
 *   - `bedrock-runtime` (2023-09-30, `Aws\BedrockRuntime\BedrockRuntimeClient`)
 *     is the DATA plane, and is the only one that defines `Converse`,
 *     `ConverseStream`, `InvokeModel` and `InvokeModelWithResponseStream`.
 *
 * This class was previously handed a `BedrockClient`, so `converse()` fell
 * through `AwsClient::__call()` into `getCommand()`, which threw
 * `InvalidArgumentException: Operation not found: Converse` - and, because
 * that is not an `AwsException`, it slipped straight past the `catch` below
 * and out of the provider unwrapped. Every Bedrock completion failed, always,
 * before a single byte reached AWS.
 */
final readonly class BedrockProvider implements ProviderInterface
{
    use HttpClientDefaults;

    private const REGION_US = 'us-east-1';
    private const REGION_EU = 'eu-west-1';

    /**
     * An INFERENCE-PROFILE id, not the bare foundation-model id (audit A20).
     *
     * Bedrock serves Claude 4.x on-demand throughput only through a
     * cross-region inference profile (`us.` / `eu.` / `apac.` / `global.` +
     * the model id, or its ARN); the bare `anthropic.claude-sonnet-4-6` this
     * used to name is the shape AWS answers with a ValidationException for
     * on-demand calls. `us.` matches {@see REGION_US}, this class's default
     * region; an operator in an EU region configures `eu.`/`global.` instead.
     * UNVERIFIED-live: no AWS credentials exist for this repo, so the refusal
     * of the bare id and the acceptance of this one rest on AWS's published
     * inference-profile documentation, not on a call.
     *
     * Public so {@see ProviderFactory::defaultConfig()} can source the
     * `bedrock` default from here instead of repeating a literal: that copy
     * still said the bare id after this one moved (audit A20, remaining half).
     */
    public const DEFAULT_MODEL = 'us.anthropic.claude-sonnet-4-6';

    /**
     * Built-in USD-per-1K rates, keyed by the NORMALISED family id
     * ({@see family()}) => [input, output].
     *
     * A family missing here is UNPRICED: {@see costPer1kTokens()} answers
     * null and the turn bills the 0.0 lower bound with
     * {@see Usage::$unpricedModel} set (audit A15). There is deliberately no
     * `default` row - the old `default => 0.01` invented $0.01/1k both ways
     * for every model outside the table, which {@see ProviderInterface}
     * names as the pre-billing-fix bug.
     *
     * Claude rows are Anthropic's per-model list prices as of the October
     * 2026 audit, the same figures {@see VertexProvider}'s table carries.
     * Bedrock is PARTNER-priced, so they are an approximation: AWS's own
     * sheet can differ (regional and cross-region profiles may carry a
     * premium of about 10%). An operator who needs the exact figure declares
     * it in `$modelPrices`. The Llama rows are the figures this table always
     * carried.
     *
     * The old `anthropic.claude-haiku-4-7` row is gone on purpose: no such
     * model exists, so the row priced (and sized) an id nobody can call.
     *
     * @var array<string, array{0: float, 1: float}>
     */
    private const PRICE_TABLE = [
        'anthropic.claude-fable-5-1' => [0.01, 0.05],
        'anthropic.claude-fable-5' => [0.01, 0.05],
        'anthropic.claude-opus-5-5' => [0.004, 0.02],
        'anthropic.claude-opus-5' => [0.005, 0.025],
        'anthropic.claude-opus-4-8' => [0.005, 0.025],
        'anthropic.claude-opus-4-7' => [0.005, 0.025],
        'anthropic.claude-opus-4-6' => [0.005, 0.025],
        'anthropic.claude-opus-4-5' => [0.005, 0.025],
        'anthropic.claude-opus-4-1' => [0.015, 0.075],
        'anthropic.claude-opus-4' => [0.015, 0.075],
        'anthropic.claude-3-opus' => [0.015, 0.075],
        'anthropic.claude-sonnet-5-5' => [0.002, 0.01],
        'anthropic.claude-sonnet-5' => [0.002, 0.01],
        'anthropic.claude-sonnet-4-6' => [0.003, 0.015],
        'anthropic.claude-sonnet-4-5' => [0.003, 0.015],
        'anthropic.claude-sonnet-4' => [0.003, 0.015],
        'anthropic.claude-3-7-sonnet' => [0.003, 0.015],
        'anthropic.claude-3-5-sonnet' => [0.003, 0.015],
        'anthropic.claude-3-sonnet' => [0.003, 0.015],
        'anthropic.claude-haiku-4-5' => [0.001, 0.005],
        'anthropic.claude-3-5-haiku' => [0.0008, 0.004],
        'anthropic.claude-3-haiku' => [0.00025, 0.00125],
        'meta.llama3-70b-instruct' => [0.00065, 0.00275],
        'meta.llama3-8b-instruct' => [0.00022, 0.00088],
    ];

    /**
     * Context windows by normalised family id. Unknown answers 0 - the
     * "unknown" of {@see ProviderInterface::contextWindow()}, so
     * {@see \SugarCraft\Crush\Context\ContextWindow::resolve()} applies its
     * one named fallback instead of this class guessing a denominator (the
     * old `default => 8_192` made Chat's context tiers fire against 8k on a
     * 200k model: auto-compaction after a few messages, every turn).
     *
     * @var array<string, int>
     */
    private const CONTEXT_WINDOWS = [
        'anthropic.claude-fable-5-1' => 1_000_000,
        'anthropic.claude-fable-5' => 1_000_000,
        'anthropic.claude-opus-5-5' => 1_000_000,
        'anthropic.claude-opus-5' => 1_000_000,
        'anthropic.claude-opus-4-8' => 1_000_000,
        'anthropic.claude-opus-4-7' => 1_000_000,
        'anthropic.claude-opus-4-6' => 1_000_000,
        'anthropic.claude-opus-4-5' => 200_000,
        'anthropic.claude-opus-4-1' => 200_000,
        'anthropic.claude-opus-4' => 200_000,
        'anthropic.claude-3-opus' => 200_000,
        'anthropic.claude-sonnet-5-5' => 1_000_000,
        'anthropic.claude-sonnet-5' => 1_000_000,
        'anthropic.claude-sonnet-4-6' => 1_000_000,
        'anthropic.claude-sonnet-4-5' => 200_000,
        'anthropic.claude-sonnet-4' => 200_000,
        'anthropic.claude-3-7-sonnet' => 200_000,
        'anthropic.claude-3-5-sonnet' => 200_000,
        'anthropic.claude-3-sonnet' => 200_000,
        'anthropic.claude-haiku-4-5' => 200_000,
        'anthropic.claude-3-5-haiku' => 200_000,
        'anthropic.claude-3-haiku' => 200_000,
        'meta.llama3-70b-instruct' => 8_192,
        'meta.llama3-8b-instruct' => 8_192,
    ];

    /**
     * The geography prefixes of Bedrock's cross-region inference profiles
     * (`us.anthropic.…`, `global.anthropic.…`). Named rather than "any first
     * segment" so the VENDOR segment (`anthropic.`, `meta.`) is never mistaken
     * for one.
     */
    private const PROFILE_GEO_PREFIX = '/^(?:us|us-gov|eu|apac|global|jp|au|ca|uk)\.(?=[a-z0-9-]+\.)/';

    /**
     * Fallback ceiling for a streaming turn.
     *
     * Converse requires nothing here, but `ConverseStream` without a
     * `maxTokens` inherits whatever per-model default AWS picks, which for
     * some model families is short enough to truncate an agentic reply
     * mid-tool-call.
     */
    private const DEFAULT_STREAM_MAX_TOKENS = 4096;

    private const DEFAULT_TEMPERATURE = 0.7;

    /**
     * E707 (round 81): of the Converse `StopReason` enum this repo vendors
     * (bedrock-runtime 2023-09-30: end_turn, tool_use, max_tokens,
     * stop_sequence, guardrail_intervened, content_filtered,
     * malformed_model_output, malformed_tool_use,
     * model_context_window_exceeded), `max_tokens` alone names the OUTPUT
     * ceiling the operator can raise. `model_context_window_exceeded` is
     * deliberately NOT here: that is the INPUT window, and a notice advising
     * a bigger output budget for it would send the operator to the wrong
     * knob.
     *
     * @var list<string>
     */
    private const TRUNCATED_STOP_REASONS = ['max_tokens'];

    /**
     * @param array<string, array{input?: float|int, output?: float|int}> $modelPrices
     *        Operator-declared USD-per-1M rates, the same shape and rules as
     *        {@see OpenAIProvider}'s `modelPrices`, keyed by the raw model id
     *        or its normalised family; overrides/extends {@see PRICE_TABLE}.
     *        Fed from config by {@see ProviderFactory::createBedrock()}: the
     *        provider block's own `modelPrices`, else the user-tier
     *        `modelPrices` setting (audit A15).
     */
    public function __construct(
        private BedrockRuntimeClient $client,
        private string $region = self::REGION_US,
        private string $defaultModel = self::DEFAULT_MODEL,
        private array $modelPrices = [],
    ) {}

    /**
     * @param array<string, array{input?: float|int, output?: float|int}> $modelPrices see {@see __construct()}
     */
    public static function create(string $region = self::REGION_US, ?string $model = null, array $modelPrices = []): self
    {
        $region = self::resolveRegion($region);

        // Credentials are deliberately left to the SDK's default provider
        // chain (env -> shared ini/profile -> ECS/EC2 metadata), the same
        // chain every other AWS tool on the box resolves through, so an
        // instance role or `AWS_PROFILE` works with no config here. Passing
        // an explicit `credentials` key would *disable* that chain.
        //
        // The AWS SDK talks to Bedrock over Guzzle too, it just takes its
        // transport options as `http` rather than accepting a client. Same
        // policy, same reason - see HttpClientDefaults. Still no total
        // `timeout`: a Bedrock completion is as long-running as any other.
        $client = new BedrockRuntimeClient([
            'region' => $region,
            'version' => 'latest',
            'http' => ['connect_timeout' => self::connectTimeoutSeconds()],
        ]);

        return new self($client, $region, $model ?? self::DEFAULT_MODEL, $modelPrices);
    }

    public function name(): string
    {
        return 'bedrock';
    }

    /**
     * The region this provider's client is bound to.
     *
     * Bedrock model availability is regional - a model id that resolves in
     * `us-east-1` is a `ValidationException` in `eu-west-1` - so the region
     * is part of every failure message this class raises.
     */
    public function region(): string
    {
        return $this->region;
    }

    public function model(): string
    {
        return $this->defaultModel;
    }

    public function supportsStreaming(): bool
    {
        return true;
    }

    public function supportsFunctionCalling(): bool
    {
        return false; // Depends on model
    }

    public function supportsVision(): bool
    {
        return false;
    }

    public function supportsJsonSchema(): bool
    {
        return false;
    }

    /**
     * The configured model's window, looked up by its normalised family
     * (audit A20: an exact match on the raw id sized every real versioned or
     * inference-profile id at 8,192). 0 when the family is unknown.
     */
    public function contextWindow(): int
    {
        return self::CONTEXT_WINDOWS[self::family($this->defaultModel)] ?? 0;
    }

    /**
     * @return null|float USD per 1K tokens, or null when neither the operator
     *         `$modelPrices` map nor {@see PRICE_TABLE} names a rate for the
     *         model (audit A15/A20: no fabricated $0.01 default).
     *
     * Lookup order: the operator's entry for the RAW id, then for the
     * normalised family, then the built-in family row. Once the operator
     * NAMES a model (either spelling) that entry is authoritative, exactly as
     * in {@see OpenAIProvider::costPer1kTokens()}: a rate that fails
     * validation answers unpriced rather than falling back to the shipped row
     * it overrode.
     */
    public function costPer1kTokens(string $model, string $direction): ?float
    {
        if (array_key_exists($model, $this->modelPrices)) {
            return $this->declaredRate($model, $direction);
        }

        $family = self::family($model);
        if (array_key_exists($family, $this->modelPrices)) {
            return $this->declaredRate($family, $direction);
        }

        $row = self::PRICE_TABLE[$family] ?? null;
        if ($row === null) {
            return null;
        }

        return $direction === 'input' ? $row[0] : $row[1];
    }

    /**
     * The table key a Bedrock model id belongs to (audit A20).
     *
     * Real Bedrock ids carry decorations the family does not:
     * `anthropic.claude-sonnet-4-5-20250929-v1:0` (date + version),
     * `us.anthropic.claude-sonnet-4-6` / `global.…` (cross-region inference
     * profile), `arn:aws:bedrock:<region>:<acct>:inference-profile/us.anthropic.…`
     * or `…::foundation-model/anthropic.…` (ARNs), and a provisioned-throughput
     * `:200k` tail. Each is stripped once, in a fixed order (ARN path, geo
     * prefix, `:N`/`:Nk` tails, `-vN`, `-YYYYMMDD`, the `-0` alias); the
     * vendor segment (`anthropic.`) is kept, and the remainder must EQUAL a
     * table key. Exact equality, not a prefix match, is the point: a prefix
     * rule would size and price an unknown `anthropic.claude-sonnet-4-7` as
     * `anthropic.claude-sonnet-4`, a guess presented as a measurement.
     */
    private static function family(string $model): string
    {
        $id = strtolower(trim($model));

        if (str_starts_with($id, 'arn:')) {
            $slash = strrpos($id, '/');
            $id = $slash === false ? $id : substr($id, $slash + 1);
        }

        foreach ([self::PROFILE_GEO_PREFIX, '/(?::\d+k?)+$/', '/-v\d+$/', '/-\d{8}$/', '/-0$/'] as $decoration) {
            $id = (string) preg_replace($decoration, '', $id);
        }

        return $id;
    }

    /**
     * One operator-declared rate, per-1K, for a model the operator NAMED in
     * `$modelPrices`; null when the declaration fails validation. Zero is
     * legal (a genuinely free model); sign-flipped or non-finite rates go the
     * loud unpriced road rather than being floored by {@see Usage} into a
     * fake-free 0.0.
     */
    private function declaredRate(string $key, string $direction): ?float
    {
        $entry = $this->modelPrices[$key];
        $declared = is_array($entry) ? ($entry[$direction] ?? null) : null;
        if (!is_numeric($declared)) {
            return null;
        }

        $rate = ((float) $declared) / 1000; // config speaks USD-per-1M

        return $rate >= 0.0 && is_finite($rate) ? $rate : null;
    }

    public function complete(CompleteRequest $request): CompleteResponse
    {
        $model = $this->modelId($request);

        $params = [
            'modelId' => $model,
            'messages' => $this->conversationTurns($request->messages),
        ];

        $system = $this->systemBlocks($request);
        if ($system !== []) {
            $params['system'] = $system;
        }

        $inference = $this->inferenceConfig($request);
        if ($inference !== []) {
            $params['inferenceConfig'] = $inference;
        }

        try {
            // Converse-shaped params (messages/system/inferenceConfig) require
            // the Converse API on the *runtime* client - see the class
            // docblock. Not the legacy invokeModel body protocol, and not
            // anything on the control-plane client, which has no inference
            // operation to call.
            $result = $this->client->converse($params);
            $data = $result->toArray();

            return $this->parseResponse($data, $model);
        } catch (AwsException $e) {
            // E664 sweep verdict: STAYS a bare \RuntimeException. This wraps an
            // AWS HTTP failure, not a subprocess — no shell exit code to
            // misread. TransientFailure::statusCode() walks the previous chain
            // and AwsException::getStatusCode() is a genuine HTTP status, so
            // the classification the retry path makes is already correct;
            // retyping to ProviderException would only borrow its exit-code
            // vocabulary for a dimension this site never carries.
            throw new \RuntimeException($this->failureMessage('completion', $model, $e), 0, $e);
        }
    }

    /**
     * Streams completion responses as a generator of deltas.
     *
     * Each yielded CompleteResponse contains only the delta/content from that
     * chunk. The caller is responsible for accumulating content across chunks.
     *
     * Only the final `metadata` event carries usage, so every chunk before it
     * reports tokensUsed/costUsd of 0 and the last one reports the turn total
     * with empty content. Callers must therefore accumulate content and read
     * usage independently - which is what `Runtime::runStreaming()` does.
     *
     * @return \Generator<int, CompleteResponse>
     */
    public function completeStream(CompleteRequest $request): \Generator
    {
        $model = $this->modelId($request);

        $params = [
            'modelId' => $model,
            'messages' => $this->conversationTurns($request->messages),
            'inferenceConfig' => $this->inferenceConfig($request) + [
                'maxTokens' => self::DEFAULT_STREAM_MAX_TOKENS,
                'temperature' => self::DEFAULT_TEMPERATURE,
            ],
        ];

        $system = $this->systemBlocks($request);
        if ($system !== []) {
            $params['system'] = $system;
        }

        try {
            // ConverseStream emits an event stream of typed events; each text
            // token arrives as a contentBlockDelta event (not the legacy
            // `completion` field). The SDK's EventParsingIterator hands each
            // one over already shaped as [<eventName> => <payload>].
            $result = $this->client->converseStream($params);
            $stream = $result->get('stream');

            foreach ($stream as $event) {
                yield $this->parseChunk(is_array($event) ? $event : [], $model);
            }
        } catch (AwsException $e) {
            // E664 sweep verdict: same as the complete() site — HTTP-shaped
            // failure, real status via the chained AwsException, no exit-code
            // dimension; the bare throw stays.
            throw new \RuntimeException($this->failureMessage('streaming', $model, $e), 0, $e);
        }
    }

    public function embeddings(EmbeddingsRequest $request): EmbeddingsResponse
    {
        // Use Titan or Cohere for embeddings via Bedrock
        return new EmbeddingsResponse(embeddings: []);
    }

    /**
     * `modelId` is a required URI path segment on Converse, so an empty one
     * would be signed into a malformed URL rather than rejected usefully.
     * Falling back to the configured model keeps a caller that only set the
     * provider's model (and left CompleteRequest::$model empty) working.
     */
    private function modelId(CompleteRequest $request): string
    {
        return $request->model !== '' ? $request->model : $this->defaultModel;
    }

    /**
     * Converse rejects an empty `inferenceConfig` structure, and previously a
     * request with a temperature but no maxTokens silently dropped the
     * temperature - the whole block was gated on maxTokens alone.
     *
     * @return array<string, mixed>
     */
    private function inferenceConfig(CompleteRequest $request): array
    {
        $config = [];

        if ($request->maxTokens !== null) {
            $config['maxTokens'] = $request->maxTokens;
        }

        if ($request->temperature !== null) {
            $config['temperature'] = $request->temperature;
        }

        if ($request->topP !== null) {
            $config['topP'] = $request->topP;
        }

        // Converse takes stop sequences here rather than at the top level.
        if (is_string($request->stop) && $request->stop !== '') {
            $config['stopSequences'] = [$request->stop];
        } elseif (is_array($request->stop) && $request->stop !== []) {
            $config['stopSequences'] = array_values($request->stop);
        }

        return $config;
    }

    /**
     * @param array<Message> $messages
     * @return array<array{role: string, content: array<array{text: string}>}>
     */
    private function formatMessages(array $messages): array
    {
        return array_map(function (Message $msg) {
            $role = match (true) {
                $msg instanceof UserMessage => 'user',
                $msg instanceof AssistantMessage => 'assistant',
                $msg instanceof SystemMessage => 'user', // total over Message types; production hoists SystemMessages to `system` first (see $this->systemBlocks())
                $msg instanceof ToolResultMessage => 'user',
                default => 'user',
            };

            return [
                'role' => $role,
                'content' => [['text' => $msg->content()]],
            ];
        }, $messages);
    }

    /**
     * The `messages` list both request paths send: history SystemMessages
     * hoisted out ({@see withoutSystemMessages()}), every row formatted
     * ({@see formatMessages()}), then reshaped to what Converse validates
     * (audit A16).
     *
     * Converse requires the turns to ALTERNATE user/assistant and rejects a
     * text block that is blank, and formatMessages() - total over Message
     * types, one row per message - guarantees neither. Two everyday paths
     * produce `user, user`: a turn that failed (this provider THROWS on
     * errors, so the user row gets no assistant reply) and
     * {@see \SugarCraft\Crush\Messages\HistorySanitizer} dropping an empty
     * assistant reply; and an empty assistant reply that survives is a blank
     * `{text: ""}` block. Because the whole history is replayed, one such row
     * made every later request in the session invalid.
     *
     * So, as {@see VertexProvider::formatAnthropicMessages()} already does
     * for the same Anthropic-shaped rule: blank (empty or whitespace-only)
     * text blocks are skipped, a row left with no blocks is dropped, and a
     * row whose role matches the previous row's is merged into it, blocks
     * kept in order. The first-turn rule (Converse also wants `user` first)
     * is deliberately NOT patched here: Runtime always opens a history with
     * the user's prompt, and inventing a placeholder user turn would put
     * words in the user's mouth.
     *
     * @param array<Message> $messages
     * @return list<array{role: string, content: list<array{text: string}>}>
     */
    private function conversationTurns(array $messages): array
    {
        $turns = [];

        foreach ($this->formatMessages($this->withoutSystemMessages($messages)) as $row) {
            $blocks = array_values(array_filter(
                $row['content'],
                static fn (array $block): bool => trim($block['text']) !== '',
            ));

            if ($blocks === []) {
                continue;
            }

            $last = array_key_last($turns);
            if ($last !== null && $turns[$last]['role'] === $row['role']) {
                $turns[$last]['content'] = [...$turns[$last]['content'], ...$blocks];

                continue;
            }

            $turns[] = ['role' => $row['role'], 'content' => $blocks];
        }

        return $turns;
    }
    /**
     * Converse has no per-message `system` role: system text only exists in
     * the request-level `system` block list, and `messages` must alternate
     * user/assistant turns. formatMessages() keeps its total contract over
     * Message types (a SystemMessage maps to user, and tests pin that), so a
     * history SystemMessage would sit on the wire as a same-role neighbour
     * of a real user turn - the consecutive-user shape backlog E19 measured.
     * Production therefore filters SystemMessages out of the message list
     * here and hoists their text into the `system` array instead.
     *
     * @param array<Message> $messages
     * @return array<Message>
     */
    private function withoutSystemMessages(array $messages): array
    {
        return array_values(array_filter(
            $messages,
            static fn (Message $msg): bool => !$msg instanceof SystemMessage,
        ));
    }

    /**
     * Builds the Converse `system` block list: the assembled prompt first,
     * then every history SystemMessage's text in message order. The stream
     * path must build exactly the same list as the complete path, so both
     * call sites share this helper.
     *
     * @return array<int, array{text: string}>
     */
    private function systemBlocks(CompleteRequest $request): array
    {
        $blocks = [];

        if ($request->systemPrompt !== null) {
            $blocks[] = ['text' => $request->systemPrompt];
        }

        foreach ($request->messages as $message) {
            if ($message instanceof SystemMessage) {
                $blocks[] = ['text' => $message->content()];
            }
        }

        return $blocks;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function parseResponse(array $data, string $model): CompleteResponse
    {
        $output = $data['output']['message'] ?? [];
        $blocks = is_array($output['content'] ?? null) ? $output['content'] : [];

        $text = '';
        $reasoning = '';

        // A Converse reply is a LIST of content blocks, not one text block:
        // a reasoning-capable model puts `reasoningContent` first, so reading
        // only $content[0]['text'] returns the empty string for exactly the
        // models worth pointing this provider at.
        foreach ($blocks as $block) {
            if (!is_array($block)) {
                continue;
            }

            if (isset($block['text']) && is_string($block['text'])) {
                $text .= $block['text'];
            }

            $thought = $block['reasoningContent']['reasoningText']['text'] ?? null;
            if (is_string($thought)) {
                $reasoning .= $thought;
            }
        }

        // P4.S2: one parsed Usage is the source of every usage number leaving
        // this method; tokensUsed/costUsd keep their exact prior expressions
        // for legitimate wire values (a negative count clamps to 0 per Usage's
        // doctrine, as in SglangProvider::parseResponse()). Since the E17 fold
        // the parsed object itself now LEAVES with them via `usage:`, so the
        // buckets survive past this boundary instead of dying at the projection.
        $usage = $this->parseUsage(is_array($data['usage'] ?? null) ? $data['usage'] : [], $model);

        // E707 (round 81): Converse states its ending in the top-level
        // `stopReason` beside `output`; the ceiling end arms the carrier.
        return new CompleteResponse(
            content: $text,
            reasoning: $reasoning !== '' ? $reasoning : null,
            toolCalls: null,
            tokensUsed: $usage->totalTokens,
            costUsd: $usage->costUsd,
            usage: $usage,
            truncated: in_array($data['stopReason'] ?? null, self::TRUNCATED_STOP_REASONS, true),
        );
    }

    /**
     * Parses a Converse `TokenUsage` object into the provider-counted token
     * BUCKETS (prompt_plan.md P4.S2).
     *
     * CACHE-FIELD FINDING, from the API definition THIS REPO VENDORS: the
     * `bedrock-runtime` 2023-09-30 model defines shape `TokenUsage` with
     * members `inputTokens`, `outputTokens`, `totalTokens`,
     * `cacheReadInputTokens`, `cacheWriteInputTokens`, `cacheDetails`
     * (MEASURED: php-require of
     * vendor/aws/aws-sdk-php/src/data/bedrock-runtime/2023-09-30/api-2.json.php,
     * and `ConverseStreamMetadataEvent.usage` binds the SAME shape — so the
     * unary reply and the terminal stream metadata carry one usage document,
     * parsed here once). This is the provider with the fullest cache story:
     * BOTH sides of the cache are real wire fields, mapping to
     * `cacheReadTokens` and `cacheCreationTokens` (a cache WRITE is what
     * Anthropic-shape calls cache CREATION; the naming difference is the
     * only difference).
     *
     * `inputTokens` maps straight to Usage's `inputTokens` WITHOUT the
     * cache-read subtraction the OpenAI-family parse applies: Bedrock follows
     * the Anthropic-side convention where `inputTokens` already counts only
     * tokens after the last cache breakpoint, so `total = cacheRead +
     * cacheCreation + input` partitions rather than overlaps. The field NAMES
     * and membership are vendored-verified above; that NON-overlap SEMANTICS
     * is Anthropic/Bedrock published-API documentation, not restated in the
     * shape file — labelled UNVERIFIED-locally here so a future reader
     * re-checks it against a live cached response before trusting
     * `Usage::promptTokens()` on this provider.
     *
     * `totalTokens` exists on the wire but the figure `complete()` has always
     * reported is `inputTokens + outputTokens`, and that expression is kept
     * byte-identical: whether Bedrock's own total counts cache is exactly the
     * unverified semantics above, and silently switching what every turn
     * reports as its billable total is a pricing-visible change outside this
     * step's Goal — REPORTED, not done.
     *
     * `cacheDetails` (the 5-minute/1-hour write split) has no Usage bucket to
     * land in — Usage carries two cache sides, not TTL-shaped ones.
     *
     * @param array<string, mixed> $usage the `usage`/`metadata.usage` document
     *                                    from the decoded response; non-array
     *                                    arrives as `[]` via the call sites,
     *                                    keeping the `?? 0` tolerance the
     *                                    inline parse replaced
     */
    public function parseUsage(array $usage, string $model): Usage
    {
        $inputTokens = self::usageInt($usage['inputTokens'] ?? null) ?? 0;
        $outputTokens = self::usageInt($usage['outputTokens'] ?? null) ?? 0;

        // Audit A15: an unpriced model bills the 0.0 lower bound with its
        // name carried, never an invented rate and never a silent zero.
        $cost = $this->cost($model, $inputTokens, $outputTokens);

        return Usage::new(
            // The exact expression this replaces: the sum of the two sides
            // each defaulted to 0 — see the totalTokens paragraph above.
            $inputTokens + $outputTokens,
            $cost ?? 0.0,
            self::usageInt($usage['inputTokens'] ?? null),
            self::usageInt($usage['outputTokens'] ?? null),
            self::usageInt($usage['cacheReadInputTokens'] ?? null),
            self::usageInt($usage['cacheWriteInputTokens'] ?? null),
            unpricedModel: $cost === null ? $model : null,
        );
    }

    /**
     * One usage number as reported: absent OR JSON null stays `null`
     * (unreported — never coerced to a measured zero); anything numeric
     * counts as its int. Non-numeric junk - strings, booleans, arrays,
     * objects - decodes to UNREPORTED, never a counted zero, while numeric
     * strings and floats count as their int (a float count floors,
     * tolerating a buggy provider exactly where the old strict-typed int
     * parameters would have crashed).
     */
    private static function usageInt(mixed $value): ?int
    {
        return $value === null || !is_numeric($value) ? null : (int) $value;
    }

    /**
     * Parses a streaming event into a partial/delta CompleteResponse.
     *
     * This returns only the delta content from this chunk - it does NOT
     * contain accumulated content. The caller must accumulate across chunks.
     *
     * @param array<string, mixed> $data
     */
    private function parseChunk(array $data, string $model): CompleteResponse
    {
        // ConverseStream text tokens arrive as contentBlockDelta events, whose
        // `delta` is a union - text OR reasoningContent OR toolUse.
        $delta = $data['contentBlockDelta']['delta'] ?? [];
        $text = is_string($delta['text'] ?? null) ? $delta['text'] : '';
        $thought = $delta['reasoningContent']['text'] ?? null;

        // Usage lands once, on the terminal metadata event; every earlier
        // event genuinely has none to report. P4.S2: the SAME
        // {@see parseUsage()} reads it here as on the unary path - the
        // vendored API definition binds `ConverseStreamMetadataEvent.usage`
        // to the identical `TokenUsage` shape, so the cache buckets cannot be
        // wired on one arm and missed on the other. An event with no usage
        // parses to an all-unreported Usage whose total is 0 - exactly the
        // zeros this method hardcoded before. Passing that same object on
        // `usage:` (E17) is therefore honest on BOTH arms: an empty document
        // reads as nothing-measured and Runtime's fold falls back to the
        // projection, while the terminal event's cache buckets survive.
        $usage = $this->parseUsage(
            is_array($data['metadata']['usage'] ?? null) ? $data['metadata']['usage'] : [],
            $model,
        );

        // E707 (round 81): ConverseStream closes with a `messageStop` event
        // whose payload carries the same `stopReason` enum the unary answer
        // states - every event already flows through this method, so the
        // verdict attaches to the frame the stop arrives on (empty text, no
        // usage yet if metadata lands separately; the fold consumes all of
        // them together).
        $stopReason = is_array($data['messageStop'] ?? null)
            ? ($data['messageStop']['stopReason'] ?? null)
            : null;

        return new CompleteResponse(
            content: $text,
            reasoning: is_string($thought) && $thought !== '' ? $thought : null,
            toolCalls: null,
            tokensUsed: $usage->totalTokens,
            costUsd: $usage->costUsd,
            usage: $usage,
            truncated: in_array($stopReason, self::TRUNCATED_STOP_REASONS, true),
        );
    }

    /**
     * The dollar cost of one usage document, or null when a side that
     * actually carried tokens has no rate on file.
     *
     * A side with no tokens needs no rate, so an empty stream event (every
     * ConverseStream event before `metadata`) never turns unpriced over
     * tokens it never carried. Null rather than 0.0 so the caller can set
     * {@see Usage::$unpricedModel}; the accounting stays at the 0.0 lower
     * bound. Cache read/write tokens are not priced here: this class sends
     * no cache points, so those buckets are absent or zero today.
     */
    private function cost(string $model, int $inputTokens, int $outputTokens): ?float
    {
        $total = 0.0;

        foreach (['input' => $inputTokens, 'output' => $outputTokens] as $direction => $tokens) {
            if ($tokens <= 0) {
                continue;
            }

            $rate = $this->costPer1kTokens($model, $direction);
            if ($rate === null) {
                return null;
            }

            $total += $tokens * $rate;
        }

        return $total / 1000;
    }

    /**
     * Bedrock's own errors say nothing about which region or model id was
     * asked for, and the overwhelmingly common failure - a model that is not
     * enabled in this account/region - is unreadable without both.
     */
    private function failureMessage(string $stage, string $model, AwsException $e): string
    {
        return sprintf(
            'Bedrock %s failed for model "%s" in %s: %s',
            $stage,
            $model,
            $this->region,
            $e->getMessage(),
        );
    }

    /**
     * An empty region would make the SDK throw on construction rather than
     * fall back, so honour the same `AWS_REGION`/`AWS_DEFAULT_REGION` pair the
     * SDK and the aws CLI read before giving up on the built-in default.
     */
    private static function resolveRegion(string $region): string
    {
        if ($region !== '') {
            return $region;
        }

        return (getenv('AWS_REGION') ?: getenv('AWS_DEFAULT_REGION')) ?: self::REGION_US;
    }
}
