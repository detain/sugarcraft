<?php

declare(strict_types=1);

namespace SugarCraft\Code\Providers;

use SugarCraft\Code\Messages\Message;
use SugarCraft\Code\Messages\AssistantMessage;
use SugarCraft\Code\Messages\UserMessage;
use SugarCraft\Code\Messages\SystemMessage;
use SugarCraft\Code\Messages\ToolResultMessage;
use SugarCraft\Code\Providers\Concerns\AnthropicMessagesWire;
use SugarCraft\Code\Providers\Concerns\GeminiGenerateContentWire;
use SugarCraft\Code\Providers\Concerns\HttpClientDefaults;
use SugarCraft\Code\Providers\Concerns\ToolSchema;
use SugarCraft\Code\Usage;

/**
 * Google Vertex AI provider.
 *
 * WHY `rawPredict` AND NOT `predict` FOR CLAUDE
 * ---------------------------------------------
 * Vertex serves two entirely different request protocols under one service:
 *
 *   - Google's own publisher models (`publishers/google/models/*`) take
 *     `:predict` / `:streamPredict` with the Google-shaped envelope
 *     (`{"instances": [...], "parameters": {...}}`).
 *   - Anthropic publisher models (`publishers/anthropic/models/claude-*`) are
 *     NOT wrapped at all. They take `:rawPredict` / `:streamRawPredict` and
 *     the body is the native Anthropic Messages API document -
 *     `anthropic_version`, `messages`, `max_tokens`, `system`, `tools` - with
 *     the model named only in the URL path, never in the body.
 *
 * This class previously sent the Google envelope to `:predict` for every
 * model while templating a `publishers/anthropic` endpoint, i.e. the one
 * combination Vertex does not serve. The failure is NOT a missing route:
 * the vendored REST config binds `:predict` to the wildcard resource pattern
 * `projects/{p}/locations/{l}/publishers/{publisher}/models/{model}` for EVERY
 * publisher (`prediction_service_rest_client_config.php`, `Predict`), so
 * `publishers/anthropic/models/claude-*:predict` resolves fine. What fails is
 * the document: an Anthropic MaaS model does not accept the `instances`
 * wrapper - it only reads the native Messages API body, which `:predict`
 * cannot carry. Hence `:rawPredict`, whose body is passed to the publisher
 * verbatim. Every completion failed before reaching the model.
 *
 * NOT SERVED HERE: Vertex's other MaaS publishers
 * ------------------------------------------------
 * Vertex also fronts `publishers/mistralai`, `publishers/meta` and
 * `publishers/ai21`, which likewise answer on `:rawPredict`. This class does
 * not route them - {@see publisherFor()} is anthropic-or-google, so
 * `mistral-large@2411`, `llama-3.1-405b-instruct-maas` and `jamba-1.5-large`
 * all template `publishers/google` + `:predict` and fail at the server.
 *
 * A publisher lookup table alone would NOT fix that, which is why one is
 * deliberately not added: those models take a THIRD request document (an
 * OpenAI-shaped `{model, messages}` body that, unlike Anthropic's, requires
 * the model id IN the body). Routing them to the right publisher while still
 * sending `instances` - or sending them the Anthropic body, which omits
 * `model` on purpose - would trade a diagnosable 404 for a confusing 400.
 * Supporting them is a feature (a third body shaper), not a routing tweak.
 *
 * Two further bugs went with it, both in the default network seam:
 *
 *   - The client was built as `new PredictionServiceClient(['projectId' => …])`.
 *     `projectId` is not one of the keys `Google\ApiCore\Options\ClientOptions
 *     ::fromArray()` reads, so it was silently dropped - and with no
 *     `apiEndpoint` set the client stayed pointed at the GLOBAL
 *     `aiplatform.googleapis.com`, which does not serve regional publisher
 *     predictions. The endpoint must be `{location}-aiplatform.googleapis.com`.
 *   - `completeStream()` was a stub that yielded one empty chunk, so the
 *     streaming path silently produced an empty answer.
 *
 * The legacy Google `predict` path is kept intact rather than removed - it is
 * simply no longer used for Claude, nor (since the Gemini arm was built) for
 * `gemini-*`. It still serves the PaLM 2 family, whose envelope it really is.
 *
 * `publishers/google` IS TWO PROTOCOLS
 * ------------------------------------
 * The publisher segment does not determine the request document. PaLM 2
 * (`chat-bison@002`, `text-bison@002`) takes `:predict` + `instances`; Gemini
 * takes `:generateContent` / `:streamGenerateContent` + `contents` +
 * `systemInstruction`. Both are `publishers/google`, so {@see publisherFor()}
 * answers `google` for both and the FAMILY predicate {@see isGeminiModel()} is
 * what selects between the two bodies. A `gemini-*` id used to be handed the
 * PaLM 2 envelope; it now gets its own.
 */
final readonly class VertexProvider implements ProviderInterface, MarksPromptCache, AcceptsAssistantPrefill, RebindsModel
{
    use ToolSchema;

    /**
     * Pulled in for {@see HttpClientDefaults::connectTimeoutSeconds()} alone -
     * this class builds no Guzzle client of its own (the Google SDK owns the
     * transport), but the connect bound it hands that transport must be the
     * SAME audited, `CANDY_CODE_CONNECT_TIMEOUT`-overridable number every
     * other provider uses, not a second one invented here. See
     * {@see callOptions()} for where it is applied.
     */
    use HttpClientDefaults;

    /**
     * ISS-023: everything about speaking the Anthropic Messages wire - body
     * shaping, cache marks, response and SSE-event parsing, family pricing -
     * lives in {@see AnthropicMessagesWire}, shared with the native
     * {@see AnthropicProvider}. The three hooks below are Vertex's half of
     * that contract: where its body opens, and whose name its diagnostics
     * carry.
     */
    use AnthropicMessagesWire;

    /**
     * PRV-3: the Gemini arm's wire lives in
     * {@see GeminiGenerateContentWire}, shared with the native
     * {@see GeminiProvider}. The two hooks below are Vertex's half of that
     * contract: which transport its stream walks, and whose name its
     * diagnostics carry - the same shape as the AMW hooks above.
     */
    use GeminiGenerateContentWire;

    /**
     * Vertex carries the Anthropic API version in the body (the header
     * `anthropic-version` is a direct-API-only spelling). This is the value
     * every Anthropic-on-Vertex model expects.
     */
    private const ANTHROPIC_VERSION = 'vertex-2023-10-16';

    public const METHOD_PREDICT = 'predict';
    public const METHOD_RAW_PREDICT = 'rawPredict';
    public const METHOD_STREAM_RAW_PREDICT = 'streamRawPredict';

    /**
     * The body key only Vertex takes; the model itself travels in the URL
     * path and a body `model` field is REJECTED here, so nothing else joins
     * the prelude. {@see AnthropicMessagesWire}.
     *
     * @return array<string, mixed>
     */
    protected function anthropicWireBodyPrelude(CompleteRequest $request): array
    {
        return ['anthropic_version' => self::ANTHROPIC_VERSION];
    }

    protected function anthropicWireUnaryLabel(): string
    {
        return 'Vertex rawPredict';
    }

    protected function anthropicWireStreamLabel(): string
    {
        return 'Vertex streamRawPredict';
    }

    /**
     * The "predictor" is the unary network seam. It receives the fully
     * templated publisher endpoint, the Vertex METHOD to invoke
     * (`rawPredict` for Anthropic publisher models, `predict` for Google's)
     * and the already-shaped request body, and returns the decoded response
     * document.
     *
     * Google's PredictionServiceClient is `final` (and SDK/credential bound),
     * so it cannot be mocked directly. Routing the actual transport through
     * this closure lets every other concern - endpoint templating, method
     * selection, message formatting, response parsing, pricing, capability
     * flags and error handling - be exercised with a fake predictor and real
     * assertions, while keeping only the irreducible RPC behind a default
     * seam.
     *
     * @var \Closure(string $endpoint, string $method, array<string, mixed> $body): array<string, mixed>
     */
    private \Closure $predictor;

    /**
     * The streaming counterpart of {@see $predictor}: same inputs, but it
     * yields already-decoded Anthropic SSE events (`message_start`,
     * `content_block_delta`, `message_delta`, …) one at a time. SSE framing
     * lives in the default implementation so tests can feed event arrays
     * straight in.
     *
     * @var \Closure(string $endpoint, string $method, array<string, mixed> $body): iterable<int, array<string, mixed>>
     */
    private \Closure $streamer;

    /**
     * Built-in context-window sizes per normalised family (ISS-073), keyed
     * exactly like {@see PRICE_TABLE}. The Claude rows mirror the numbers
     * BedrockProvider ships for the same families (1M on the current
     * fable/opus-4-6+/sonnet-5 class, 200K on the previous generations); the
     * Gemini rows carry Google's published 1,048,576-token window. A family
     * with no row keeps the 200,000 this method used to answer flat - the
     * status-quo floor, never a fabricated larger budget that would let the
     * compactor overrun a real limit.
     *
     * @var array<string, int>
     */
    private const CONTEXT_WINDOWS = [
        'claude-fable-5-1' => 1_000_000,
        'claude-fable-5' => 1_000_000,
        'claude-opus-5-5' => 1_000_000,
        'claude-opus-5' => 1_000_000,
        'claude-opus-4-8' => 1_000_000,
        'claude-opus-4-7' => 1_000_000,
        'claude-opus-4-6' => 1_000_000,
        'claude-opus-4-5' => 200_000,
        'claude-opus-4-1' => 200_000,
        'claude-opus-4' => 200_000,
        'claude-3-opus' => 200_000,
        'claude-sonnet-5-5' => 1_000_000,
        'claude-sonnet-5' => 1_000_000,
        'claude-sonnet-4-6' => 1_000_000,
        'claude-sonnet-4-5' => 200_000,
        'claude-sonnet-4' => 200_000,
        'claude-3-7-sonnet' => 200_000,
        'claude-3-5-sonnet' => 200_000,
        'claude-3-sonnet' => 200_000,
        'claude-haiku-4-5' => 200_000,
        'claude-3-5-haiku' => 200_000,
        'claude-3-haiku' => 200_000,
        'gemini-2.5-pro' => 1_048_576,
        'gemini-2.5-flash' => 1_048_576,
        'gemini-2.5-flash-lite' => 1_048_576,
        'gemini-2.0-flash' => 1_048_576,
        'gemini-2.0-flash-lite' => 1_048_576,
    ];

    /**
     * The window {@see contextWindow()} answers when neither the operator's
     * override nor a table row names one — the flat value this method
     * returned before the tables existed (ISS-073).
     */
    private const FALLBACK_CONTEXT_WINDOW = 200_000;

    /**
     * @param (callable(string, string, array<string, mixed>): array<string, mixed>)|null $predictor
     *        Unary network seam; when null a real Vertex AI call is wired in.
     * @param (callable(string, string, array<string, mixed>): iterable<int, array<string, mixed>>)|null $streamer
     *        Streaming network seam; when null a real `streamRawPredict` call is wired in.
     * @param array<string, array{input?: float|int, output?: float|int}> $modelPrices
     *        Operator-declared USD-per-1M rates, the same shape and rules as
     *        {@see OpenAIProvider}'s `modelPrices` (per-1M because that is the
     *        unit price sheets publish in; divided to per-1K once in
     *        {@see declaredRate()}). Overrides/extends {@see PRICE_TABLE}.
     *        Fed from config by {@see ProviderFactory::createVertex()}: the
     *        provider block's own `modelPrices`, else the user-tier
     *        `modelPrices` setting (audit A15).
     * @param int|null $thinkingBudget Audit A21 (b): Gemini's
     *        `generationConfig.thinkingConfig.thinkingBudget`, from the
     *        `thinkingBudget` setting ({@see ProviderFactory::createVertex()}).
     *        -1 asks for dynamic thinking, 0 turns it off (Flash models only;
     *        Pro refuses 0), a positive count caps it. Null sends no
     *        `thinkingConfig`, leaving the model's own default. Sent on the
     *        Gemini arm only. CORRECTED BY CTX-1 (§6.3): the Anthropic arm's
     *        extended thinking IS now buildable on this wire — see
     *        {@see anthropicThinkingBudget()} — but this class deliberately
     *        leaves that hook at its null default: the SAME setting number
     *        counts Gemini thinking tokens (where 0/-1/small values are
     *        ordinary) and would not be a valid Anthropic `budget_tokens`,
     *        so feeding both arms from it would make every Gemini user's
     *        value an Anthropic-arm surprise. Anthropic-on-Vertex thinking
     *        is a follow-up with its own key if a user ever asks for it.
     * @param bool $promptCache Audit A15: whether the Anthropic arm marks
     *        prompt-cache breakpoints ({@see CacheBreakpoints}: the system
     *        prompt, the last tool and the conversation tail). From the
     *        `promptCache` setting and `CANDY_CODE_DISABLE_PROMPT_CACHE`
     *        ({@see ProviderFactory::createVertex()}); on by default. Gemini
     *        needs no marks - its 2.5 family caches implicitly - so the flag
     *        does not touch that arm, and the cached tokens it reports are
     *        priced either way.
     *
     * @throws \InvalidArgumentException when $thinkingBudget is below -1
     */
    public function __construct(
        private string $projectId,
        private string $location,
        private string $defaultModel,
        ?callable $predictor = null,
        ?callable $streamer = null,
        private array $modelPrices = [],
        private ?int $thinkingBudget = null,
        private bool $promptCache = true,
        // ISS-073: the operator's `contextWindow` for this model; null for
        // none, in which case the per-family table answers.
        private ?int $contextWindowOverride = null,
    ) {
        if ($thinkingBudget !== null && $thinkingBudget < -1) {
            throw new \InvalidArgumentException(sprintf(
                'VertexProvider thinkingBudget must be -1 (dynamic), 0 (off) or a positive token '
                . 'count, got %d.',
                $thinkingBudget,
            ));
        }

        $this->predictor = $predictor !== null
            ? \Closure::fromCallable($predictor)
            : self::defaultPredictor($location);

        $this->streamer = $streamer !== null
            ? \Closure::fromCallable($streamer)
            : self::defaultStreamer($location);
    }

    /**
     * @param (callable(string, string, array<string, mixed>): array<string, mixed>)|null $predictor
     * @param (callable(string, string, array<string, mixed>): iterable<int, array<string, mixed>>)|null $streamer
     * @param array<string, array{input?: float|int, output?: float|int}> $modelPrices see {@see __construct()}
     * @param int|null $thinkingBudget see {@see __construct()}
     * @param bool $promptCache see {@see __construct()}
     * @param int|null $contextWindowOverride see {@see __construct()}
     */
    public static function create(
        string $projectId,
        string $location = 'us-central1',
        string $model = 'claude-sonnet-4-6',
        ?callable $predictor = null,
        ?callable $streamer = null,
        array $modelPrices = [],
        ?int $thinkingBudget = null,
        bool $promptCache = true,
        ?int $contextWindowOverride = null,
    ): self {
        return new self(
            $projectId,
            $location,
            $model,
            $predictor,
            $streamer,
            $modelPrices,
            $thinkingBudget,
            $promptCache,
            $contextWindowOverride,
        );
    }

    public function name(): string
    {
        return 'vertex';
    }

    /**
     * Capability flags carry no model argument, so they answer for the
     * provider's configured default model. A caller that overrides the model
     * per request into the other family gets the other family's protocol
     * (see {@see complete()}) but these flags will not have moved.
     */
    public function supportsStreaming(): bool
    {
        // `streamRawPredict` is bound for Anthropic publisher models and
        // `streamGenerateContent` for Gemini ones. The PaLM 2 family's
        // `streamPredict`/`serverStreamingPredict` response envelope is still
        // not modelled here, so THAT family reports no streaming - it is the
        // only arm {@see completeStream()} answers by delegating to the unary
        // call.
        return $this->isAnthropicModel($this->defaultModel)
            || $this->isGeminiModel($this->defaultModel);
    }

    /**
     * ANTHROPIC ONLY, AND THAT IS A STATEMENT ABOUT THIS CLASS, NOT ABOUT
     * GEMINI. Gemini on Vertex does support function calling
     * (`tools[].functionDeclarations`, answered with a `functionCall` part),
     * and {@see \Google\Cloud\AIPlatform\V1\GenerateContentRequest::setTools()}
     * is vendored. What is absent is the SHAPER: {@see geminiBody()} builds no
     * `tools` key and {@see parseGeminiResponse()} reads no `functionCall`
     * part, because the step that built the Gemini arm scoped tools out. So
     * the honest flag is false - reporting true would let a caller hand this
     * provider tools it silently drops. Whoever adds the shaper flips this
     * with it; the absence is pinned by
     * `VertexProviderTest::testAGeminiBodyCarriesNoToolsKeyEvenWhenToolsAreOffered`.
     */
    public function supportsFunctionCalling(): bool
    {
        return $this->isAnthropicModel($this->defaultModel);
    }

    /**
     * Audit 15b-15: Claude and Gemini both read images, as `image` blocks
     * ({@see anthropicBlocks()}) and `inlineData` parts
     * ({@see formatGeminiContents()}) - the request-side image block this used
     * to wait for. The legacy PaLM envelope does not, and gets the attachment
     * as named text instead.
     */
    public function supportsVision(): bool
    {
        return $this->isAnthropicModel($this->defaultModel) || $this->isGeminiModel($this->defaultModel);
    }

    /**
     * CTX-2: only the Claude arm of this host speaks the Messages `document`
     * block. The Gemini `inlineData` document path is DECLINED for this
     * slice, so a Gemini model gets the PDF withheld with a notice rather
     * than a part its wire cannot carry.
     */
    public function supportsDocuments(): bool
    {
        return $this->isAnthropicModel($this->defaultModel);
    }

    public function supportsJsonSchema(): bool
    {
        // Anthropic constrains output through tools, not a response schema.
        return false;
    }

    public function contextWindow(): int
    {
        // ISS-073: the operator's override wins; otherwise the family table
        // (the same pricingFamily normalisation the price tables use, so a
        // versioned `claude-sonnet-4-6@20250929` reads the 1M row); otherwise
        // the flat 200K this method answered before tables existed.
        return $this->contextWindowOverride
            ?? self::CONTEXT_WINDOWS[self::pricingFamily($this->defaultModel)]
            ?? self::FALLBACK_CONTEXT_WINDOW;
    }

    public function complete(CompleteRequest $request): CompleteResponse
    {
        $model = $this->modelId($request);
        $anthropic = $this->isAnthropicModel($model);
        $gemini = !$anthropic && $this->isGeminiModel($model);

        // THREE routes, not two. `publishers/google` is two protocols (see
        // METHOD_GENERATE_CONTENT), so the old anthropic-or-else binary sent
        // every Gemini id an `instances` envelope the model does not read.
        $method = match (true) {
            $anthropic => self::METHOD_RAW_PREDICT,
            $gemini => self::METHOD_GENERATE_CONTENT,
            default => self::METHOD_PREDICT,
        };

        try {
            // Inside the try: endpointFor(), anthropicBody() and geminiBody()
            // all reject locally-detectable bad input, and a caller expects
            // those the same way it expects a transport failure - as an error
            // CompleteResponse.
            $endpoint = $this->endpointFor($model);
            $body = match (true) {
                $anthropic => $this->anthropicBody($request, stream: false),
                $gemini => $this->geminiBody($request),
                default => $this->googleBody($request),
            };

            $data = ($this->predictor)($endpoint, $method, $body);

            return match (true) {
                $anthropic => $this->parseAnthropicResponse($data, $model),
                $gemini => $this->parseGeminiResponse($data, $model),
                default => $this->parseResponse($data),
            };
        } catch (\Throwable $e) {
            return new CompleteResponse(
                content: '',
                isError: true,
                // 2.7-1a: classified while the exception (status, body) still
                // exists, and carried in the text — see ContextOverflow::describe().
                errorMessage: ContextOverflow::matches($e) ? ContextOverflow::describe($e->getMessage()) : $e->getMessage(),
                // 2.7-1b: the same verdict as a field, so the turn loop's
                // overflow recovery reads it rather than the prose.
                errorContextOverflow: ContextOverflow::matches($e),
                // Classified here, where the exception still exists: this
                // provider reports a failure as a response rather than by
                // throwing, so the verdict has to be carried rather than
                // re-derived from the message downstream. Note the catch is
                // \Throwable, so it also sees endpointFor()/anthropicBody()'s
                // own \InvalidArgumentException for locally-bad input -
                // TransientFailure's allow-list classifies that as permanent,
                // which is what stops a malformed request being retried three
                // times. See CompleteResponse::$errorTransient.
                errorTransient: TransientFailure::isTransient($e),
            );
        }
    }

    /**
     * Streams an Anthropic-on-Vertex turn through `:streamRawPredict`.
     *
     * Each yielded CompleteResponse carries only that event's delta; callers
     * accumulate.
     *
     * USAGE LANDS TWICE HERE, and that is NOT the contract
     * {@see BedrockProvider::completeStream()} has: Anthropic-on-Vertex reports
     * input tokens on `message_start` and output tokens on the terminal
     * `message_delta`, so {@see parseAnthropicChunk()} emits two usage-bearing
     * responses per turn — `tokensUsed: $usage->inputTokens` on the first and
     * `tokensUsed: $usage->outputTokens` on the last, each priced on its own side of
     * the rate table. A consumer that read only the final chunk would bill the
     * turn for its output and none of its input; {@see \SugarCraft\Code\Runtime}
     * therefore SUMS across chunks rather than taking the last, and says so.
     * Bedrock really does land its usage once, on the terminal metadata event.
     *
     * @return \Generator<int, CompleteResponse>
     */
    public function completeStream(CompleteRequest $request): \Generator
    {
        $model = $this->modelId($request);

        if ($this->isGeminiModel($model) && !$this->isAnthropicModel($model)) {
            yield from $this->streamGemini($request, $model);

            return;
        }

        if (!$this->isAnthropicModel($model)) {
            // The PaLM 2 family has no rawPredict stream and its
            // `serverStreamingPredict` envelope is not modelled here. Yielding
            // the unary result once keeps a caller that only ever calls
            // completeStream() working instead of handing it the empty chunk
            // this method used to return for every model. Gemini no longer
            // reaches this branch - it has a real stream, above.
            yield $this->complete($request);

            return;
        }

        // Accumulates `input_json_delta` fragments for in-flight `tool_use`
        // blocks, keyed by the stream's content-block index. A local rather
        // than a property because this class is `final readonly` - the buffer
        // lives exactly as long as one completeStream() call.
        $toolCallBuffer = [];
        $thinkingBuffer = [];

        try {
            // Endpoint templating and body shaping are inside the try because
            // both now reject locally-detectable bad input (a model id that is
            // a resource path, an empty transcript). A caller of a generator
            // should see those the same way it sees a transport failure - as an
            // error chunk - not as an exception thrown on first iteration.
            $endpoint = $this->endpointFor($model);
            $body = $this->anthropicBody($request, stream: true);

            // Audit 15a A3: whether the stream reached its terminal event. A
            // transport cut ends the SDK's readAll() quietly, so falling out
            // of the loop proves nothing; `message_stop` (or the
            // `message_delta` carrying `stop_reason`, which precedes it) is
            // the protocol saying the message is whole. An error chunk counts
            // too: it already reports the failure, and a second, transient
            // one would overwrite its verdict at the consumer.
            $sawTerminal = false;

            foreach (($this->streamer)($endpoint, self::METHOD_STREAM_RAW_PREDICT, $body) as $event) {
                $event = is_array($event) ? $event : [];
                $chunk = $this->parseAnthropicChunk($event, $model, $toolCallBuffer, $thinkingBuffer);

                $type = $event['type'] ?? null;
                $sawTerminal = $sawTerminal
                    || $type === 'message_stop'
                    || ($type === 'message_delta' && ($event['delta']['stop_reason'] ?? null) !== null)
                    || ($chunk !== null && $chunk->isError);

                if ($chunk !== null) {
                    yield $chunk;
                }
            }

            if (!$sawTerminal) {
                yield self::prematureEndChunk('Vertex streamRawPredict: ');
            }
        } catch (\Throwable $e) {
            yield new CompleteResponse(
                content: '',
                isError: true,
                // 2.7-1a: classified while the exception (status, body) still
                // exists, and carried in the text — see ContextOverflow::describe().
                errorMessage: ContextOverflow::matches($e) ? ContextOverflow::describe($e->getMessage()) : $e->getMessage(),
                // 2.7-1b: the same verdict as a field, so the turn loop's
                // overflow recovery reads it rather than the prose.
                errorContextOverflow: ContextOverflow::matches($e),
                // See complete()'s catch. This catch sits OUTSIDE the chunk
                // loop, so it can fire after real deltas have already been
                // yielded - which is precisely the case
                // Runtime::runStreaming() must not blindly retry.
                errorTransient: TransientFailure::isTransient($e),
            );
        }
    }

    public function embeddings(EmbeddingsRequest $request): EmbeddingsResponse
    {
        return new EmbeddingsResponse(embeddings: []);
    }

    /**
     * Builds the Vertex AI publisher-model endpoint for a given model id.
     *
     * The publisher segment is derived from the model family: routing a
     * Gemini id under `publishers/anthropic` (which this method used to do
     * unconditionally) is a 404 at Vertex.
     *
     * @throws \InvalidArgumentException when the model id cannot be a single
     *         resource segment. `$model` is interpolated as the LAST path
     *         segment of a resource name, so a value that is itself a path
     *         double-nests silently - a configured
     *         `publishers/anthropic/models/claude-3-5-sonnet`, or a tuned
     *         model's own `projects/…/models/…` resource name, would be
     *         templated into `…/publishers/anthropic/models/projects/…`. An
     *         empty id templates a trailing `…/models/` with no segment at
     *         all. Both are locally detectable, so they are rejected here
     *         rather than shipped to Vertex as a malformed resource name.
     */
    public function endpointFor(string $model): string
    {
        if ($model === '') {
            throw new \InvalidArgumentException(
                'Vertex model id is empty: set a model on the request or on the provider. '
                . 'An empty id would be templated into a resource name ending in "/models/".'
            );
        }

        if (str_contains($model, '/')) {
            throw new \InvalidArgumentException(sprintf(
                'Vertex model id %s is a resource path, not a model id. Pass only the last '
                . 'segment (e.g. "claude-3-5-sonnet-v2@20241022"); this provider templates the '
                . 'project, location and publisher around it.',
                var_export($model, true),
            ));
        }

        return sprintf(
            'projects/%s/locations/%s/publishers/%s/models/%s',
            $this->projectId,
            $this->location,
            $this->publisherFor($model),
            $model,
        );
    }

    /**
     * Gemini models are `publishers/google` - the SAME publisher as PaLM 2 -
     * but a DIFFERENT protocol, so this predicate selects the request
     * document and the RPC, never the endpoint. {@see publisherFor()} is
     * unchanged by it and still answers `google` for both families;
     * `endpointFor('gemini-1.5-pro-002')` returns the identical resource name
     * it returned before this arm existed.
     *
     * Matched on the family name for the same reason {@see isAnthropicModel()}
     * is: Vertex ids carry a version suffix (`gemini-1.5-pro-002`,
     * `gemini-2.0-flash-001`) and an exact-id list would go stale on every
     * model release.
     *
     * DELIBERATELY NARROW. Everything else under `publishers/google` -
     * `chat-bison@002`, `text-bison@002`, `code-bison`, the `medlm` family -
     * keeps the legacy `instances`/`:predict` route, which is the envelope
     * those models really do take. This predicate does not try to be "is a
     * modern Google model"; it names one family whose wire protocol is known.
     */
    public function isGeminiModel(string $model): bool
    {
        return str_contains(strtolower($model), 'gemini');
    }

    private function publisherFor(string $model): string
    {
        return $this->isAnthropicModel($model) ? self::PUBLISHER_ANTHROPIC : self::PUBLISHER_GOOGLE;
    }

    // -------------------------------------------------------------------------
    // Gemini (generateContent / streamGenerateContent) - the two per-host
    // hooks. PRV-3 moved the wire itself (body shaping, contents rendering,
    // response and chunk parsing, usageMetadata folding) intact into
    // {@see Concerns\GeminiGenerateContentWire}, shared with the native
    // GeminiProvider. What cannot live in a shared trait is which transport
    // the stream walks and whose name the diagnostics carry; those stayed,
    // as the two hooks below.
    // -------------------------------------------------------------------------

    /**
     * The name every Gemini-arm diagnostic carries. Byte-identical to the
     * literal this arm emitted before the PRV-3 extraction.
     */
    protected function geminiWireHostLabel(): string
    {
        return 'Vertex';
    }

    /**
     * The Vertex stream: the injected streamer seam, addressed through the
     * regional publisher endpoint, body from the trait's geminiBody().
     *
     * @return iterable<int, array<string,mixed>>
     */
    protected function geminiStreamEvents(CompleteRequest $request, string $model): iterable
    {
        return ($this->streamer)(
            $this->endpointFor($model),
            self::METHOD_STREAM_GENERATE_CONTENT,
            $this->geminiBody($request),
        );
    }


    // -------------------------------------------------------------------------
    // Google publisher models (legacy `predict` path, unchanged in shape)
    // -------------------------------------------------------------------------

    /**
     * The Google-shaped `:predict` envelope. Retained for `publishers/google`
     * models, which really do take `instances`/`parameters`.
     *
     * THE ASSEMBLED SYSTEM PROMPT RIDES `instances[0].context`. This envelope
     * has no per-message `system` role, and {@see formatMessages()} maps every
     * message it does not recognise - SystemMessage included - to `user`, so
     * before this method read {@see systemInstruction()} the whole assembled
     * prompt was dropped outright for every `publishers/google` model, on the
     * unary path AND on {@see completeStream()}, which delegates here. A
     * history SystemMessage fared no better: it reached the model as an
     * ordinary user turn.
     *
     * `context` IS THE STANDING-INSTRUCTION FIELD - OF THE PaLM 2 `chat-bison`
     * ENVELOPE, WHICH IS THE ONE THIS METHOD BUILDS, and naming the model
     * family is the part that used to be missing. The instance shape is
     * `{"content": ..., "context": ..., "examples": [...], "messages":
     * [{"author": ..., "content": ...}]}`. The leading instance-level
     * `content` is NOT the per-message one and was omitted from this summary
     * until a second reader re-derived the struct; it is unused by this
     * method, which sends `context` + `messages`. CORROBORATED at code level
     * by an independent
     * raw-REST Go implementation of this same `:predict` endpoint,
     * uber/go-vertex-ai `types.go` (MEASURED 2026-08-29 from
     * https://raw.githubusercontent.com/uber/go-vertex-ai/main/types.go): its
     * `inputInstances` struct carries the JSON tags `json:"content"`,
     * `json:"context"`, `json:"examples,omitempty"` and `json:"messages"`
     * (RE-MEASURED 2026-08-29 by a third reader: `Content string` is the
     * struct's FIRST field and was missing from this list), its `payload`
     * struct is `{instances, parameters}` - this envelope exactly - and its
     * README names the model: "chat-bison is a large language model ... The
     * PaLM 2 for chat".
     *
     * WHAT THIS PARAGRAPH USED TO SAY, AND WHY THE CITATION MOVED (rule 42).
     * It used to cite "Design chat prompts" at
     * https://cloud.google.com/vertex-ai/generative-ai/docs/chat/chat-prompts
     * and name no model family at all. That page is NO LONGER THE
     * LOAD-BEARING CITATION for anything in this doc-block - the Go
     * implementation above is - and the reason is epistemic, not editorial.
     *
     * WHAT IS AGREED, ACROSS THREE INDEPENDENT READERS. The old URL
     * 301-redirects. All three measured that.
     *
     * WHAT IS UNVERIFIED (16.3). What the destination RENDERS is disputed and
     * this doc-block must not pretend otherwise. A review reported it as "a
     * navigation index with none of the described content"; a re-measurement
     * reported a live 200 article; a third reader (2026-08-29) also got an
     * article body, containing "Design chat prompts", a "Context
     * (recommended)" section describing `context` as what you "use ... to
     * customize the behavior of the chat model", and a
     * "You are Captain Barktholomew ..." example - though as a
     * best-practices TABLE ROW, not as the worked `"context": ...` JSON
     * snippet an earlier revision of this paragraph described. That third
     * reader also measured a SECOND hop the earlier note did not record: the
     * `docs.cloud.google.com/vertex-ai/...` URL itself redirects again, to
     * https://docs.cloud.google.com/gemini-enterprise-agent-platform/models/chat/chat-prompts,
     * which is a plausible mechanism for two fetchers landing on different
     * documents. Two of three readers reproduce the article; one does not.
     * Under 16.3 a claim two readers cannot both reproduce is NOT MEASURED,
     * so the page's current content is recorded here as UNVERIFIED. An
     * earlier revision of this paragraph called the navigation-index report
     * FALSE; that verdict is WITHDRAWN - it was one reader's fetch asserted
     * over another's, which is not a measurement.
     *
     * WHY IT DOES NOT MATTER TO THE CLAIM. Where the readers who did see an
     * article agree, the page describes `context` but shows a Gemini-shaped
     * message example (`"contents": [{"role": ..., "parts": {"text": ...}}]`)
     * and carries neither `chat-bison` nor `PaLM`. So even on the reading
     * MOST favourable to it, that page does not identify the WIRE SHAPE this
     * method sends. The `instances`/`context` envelope is established by the
     * Go struct above, which every reader can re-derive from a single raw
     * file, and nothing in this doc-block now rests on the disputed page.
     * Either way this is DOCUMENTED, not measured on the wire: nothing here
     * has Vertex credentials, so no live call confirms this deployment
     * honours it.
     *
     * OBSERVED, PRE-EXISTING, AND DELIBERATELY NOT FIXED: that same authority
     * spells the message key `author` - its `ChatMessage` struct is
     * `Author string json:"author"` / `Content string json:"content"` -
     * while {@see formatMessages()} emits `role`. That is a SECOND defect in
     * this envelope, older than the system-prompt hoist above it and
     * independent of it. It is left standing because correcting it would
     * change the body pinned by the existing green
     * `VertexProviderTest::testCompleteSelectsPredictAndTheInstancesEnvelopeForGoogleModels`,
     * which the step that wrote this paragraph is not permitted to touch.
     * Recorded so the next reader finds it rather than re-discovers it.
     *
     * A GEMINI MODEL ID NO LONGER REACHES THIS METHOD - BUILT, NOT DEFERRED.
     * This paragraph used to end "a feature decision for the user, not a fix -
     * and is deliberately not taken here". THAT SENTENCE IS NOW FALSE and is
     * rewritten rather than deleted, because the decision it deferred is the
     * one that was subsequently taken: the user was offered three options for
     * this arm and chose to build the `:generateContent` route. It is
     * {@see geminiBody()} / {@see parseGeminiResponse()} /
     * {@see streamGemini()}, selected by {@see isGeminiModel()} in
     * {@see complete()} and {@see completeStream()}.
     *
     * The diagnosis that paragraph recorded stands unchanged and is why the
     * arm exists: `gemini-1.5-pro-002`, the id both Vertex test files pinned
     * as "the Google model", is not served by `instances`/`context` at all.
     * Gemini on Vertex answers `:generateContent` / `:streamGenerateContent`
     * and takes its standing instruction in a top-level `systemInstruction`
     * object (MEASURED 2026-08-29, verbatim from
     * https://ai.google.dev/api/generate-content: "systemInstruction object
     * (Content) Optional. Developer set system instruction(s). Currently, text
     * only."; see also
     * https://docs.cloud.google.com/vertex-ai/generative-ai/docs/reference/rest/v1/projects.locations.publishers.models/generateContent).
     *
     * WHAT STILL ROUTES HERE, AND WHY THIS ARM STAYS. The PaLM 2 family -
     * `chat-bison@002`, `text-bison@002`, `code-bison` - genuinely does take
     * the envelope this method builds, and {@see isGeminiModel()} is
     * deliberately narrow so those ids keep it. This arm is not deprecated,
     * not stubbed and not scheduled for removal; the Gemini split took a
     * family OUT of it, it did not replace it. Everything else this doc-block
     * records about the envelope - the `author`-vs-`role` defect, the dropped
     * `parameters`, the empty and system-only transcript routes - is
     * unaffected and still open.
     *
     * `publishers/mistralai`, `publishers/meta` and `publishers/ai21` remain
     * unrouted, exactly as the class doc-block above states.
     *
     * A SYSTEM-MESSAGE-ONLY TRANSCRIPT NOW YIELDS AN EMPTY `messages` LIST,
     * which is a NEW route introduced by the dedup below and is pinned rather
     * than guarded. MEASURED 2026-08-29:
     * `messages: [SystemMessage('only'), SystemMessage('this')]` with
     * `systemPrompt: 'asm'` produces
     * `{"instances":[{"messages":[],"context":"asm\n\nonly\n\nthis"}],"parameters":{...}}`.
     * BEFORE the dedup the same input produced two `role: user` turns carrying
     * the instruction text twice over. The Anthropic arm REJECTS this exact
     * input with a named \InvalidArgumentException (the guard at the top of
     * {@see anthropicBody()},
     * pinned by
     * `VertexProviderTest::testCompleteRejectsASystemOnlyTranscriptLocally`)
     * because the Messages API requires at least one turn. This arm does not,
     * and that asymmetry is deliberate: the `instances` envelope states no
     * such requirement, and
     * `VertexProviderTest::testGoogleModelsStillAcceptAnEmptyTranscript`
     * records the no-guard position for the empty transcript already. The new
     * system-only route is pinned by
     * `VertexProviderTest::testAGoogleSystemMessageOnlyTranscriptYieldsAnEmptyMessagesList`.
     *
     * HALF OF THE BODY THIS METHOD BUILDS NEVER REACHES THE WIRE. OBSERVED,
     * PRE-EXISTING, AND DELIBERATELY NOT FIXED HERE. The `parameters` map
     * below (`temperature`, `maxOutputTokens`) is DISCARDED:
     * {@see defaultPredictor()}'s non-`rawPredict` branch builds its
     * `PredictRequest` with `->setEndpoint()` and
     * `->setInstances(...)` only and never calls `setParameters()`
     * (inside `defaultPredictor()`), so the sampling knobs are dropped
     * before the request is sent. This is a SEPARATE defect from the one this
     * method's `context` hoist fixes, it predates that fix, and repairing it
     * is a different step (1.10: reported, not repaired).
     *
     * THE `context` HOIST IS UNAFFECTED, and that is why this note is a note
     * and not a blocker: `context` lives INSIDE `instances`, and
     * {@see toProtobufValues()} merges each
     * instance from arbitrary JSON via `mergeFromJsonString()`, so any key
     * added to the instance - `context` included - does survive to the wire.
     * Epistemic status: the `setParameters()` absence is MEASURED by reading
     * the seam; that the deployed service would honour `parameters` if it
     * were sent is UNVERIFIED, since nothing here has Vertex credentials.
     *
     * @return array{instances: array<int, array<string, mixed>>, parameters: array<string, mixed>}
     */
    private function googleBody(CompleteRequest $request): array
    {
        $instance = [
            'messages' => $this->formatMessages($this->withoutSystemMessages($request->messages)),
        ];

        $context = $this->systemInstruction($request);
        if ($context !== null) {
            $instance['context'] = $context;
        }

        return [
            'instances' => [$instance],
            'parameters' => [
                'temperature' => $request->temperature ?? self::DEFAULT_TEMPERATURE,
                'maxOutputTokens' => $request->maxTokens ?? self::DEFAULT_MAX_TOKENS,
            ],
        ];
    }

    /**
     * A SystemMessage hoisted into `context` must not ALSO stay in
     * `messages`, where {@see formatMessages()}'s `default => 'user'` arm
     * would render it a second time as a user turn.
     *
     * Deliberately a second copy of {@see BedrockProvider::withoutSystemMessages()}
     * rather than a shared trait: the only honest home for one copy is a new
     * file under `src/Providers/Concerns/`, and adding a file to `src/` reds
     * four exact-cardinality assertions in BuiltInToolCorpusTest plus a
     * doc-block in RepoMapBlock.php - out of this step's declared scope. The
     * two bodies are byte-identical today, and NOTHING IN THE SUITE WOULD
     * NOTICE IF THEY DRIFTED - nor even if this `{@see}` stopped naming a real
     * method, which is stronger than the weaker claim that used to stand here.
     * That claim was "`DuplicatedTestHelperDriftTest` reads `tests/` only";
     * true, but not the binding limit. MEASURED 2026-08-29: replacing
     * `BedrockProvider::withoutSystemMessages()` above with a method name that
     * does not exist leaves `SymbolCitationDriftTest` GREEN. Reproduce with
     * `vendor/bin/phpunit tests/SymbolCitationDriftTest.php`, mutated and
     * unmutated; the VERDICT is the durable fact and the command is how you
     * re-derive it. (A literal `OK (7 tests, 2924 assertions)` used to stand
     * here. Rule 42, corrected not deleted: that figure was already stale when
     * written - it is the count at `master` and at this branch's first commit,
     * taken before the second commit added citations to the matrix test, and
     * the count at this commit is different again. Per 16.2 a number no test
     * derives rots, so the figure is dropped rather than re-pinned at a value
     * that would rot the same way.) The census stays green either way because
     * it only validates a
     * citation whose target contains `SugarCraft\Code\Tests\` or whose class
     * short-name ends in `Test` (`SymbolCitationDriftTest::looksLikeATestSymbol()`,
     * the alphabet) - and every `{@see}` in this
     * paragraph names a PRODUCTION symbol, which that file states is
     * deliberately out of its scope. So the drift guard here is this sentence
     * and a reader, not an instrument.
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
     * @param array<Message> $messages
     * @return array<array{role: string, content: string}>
     */
    private function formatMessages(array $messages): array
    {
        return array_map(function (Message $msg) {
            return [
                'role' => match (true) {
                    $msg instanceof UserMessage => 'user',
                    $msg instanceof AssistantMessage => 'assistant',
                    default => 'user',
                },
                // Audit 15b-15: the legacy PaLM envelope is text only, so an
                // attachment rides as text - files inlined, images named.
                'content' => $msg instanceof UserMessage ? $msg->textOnly() : $msg->content(),
            ];
        }, $messages);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function parseResponse(array $data): CompleteResponse
    {
        // `predict` answers with a list of predictions; the seam hands the
        // first one over already unwrapped, but a caller that passes the whole
        // envelope through is tolerated.
        if (isset($data['predictions'][0]) && is_array($data['predictions'][0])) {
            $data = $data['predictions'][0];
        }

        // E707, deliberately NOT widened: the legacy PaLM envelope documents
        // no stop/finish field this parser could read, so a ceiling stop here
        // stays unobservable rather than guessed from token counts. This
        // answer keeps truncated=false - the field's contract is "the wire
        // said so", never "the wire might have meant it".
        return new CompleteResponse(
            content: $data['content'] ?? $data['text'] ?? '',
            reasoning: $data['reasoning'] ?? null,
            toolCalls: null,
            tokensUsed: 0,
            costUsd: 0.0,
        );
    }

    // -------------------------------------------------------------------------
    // Default network seams
    // -------------------------------------------------------------------------

    /**
     * Default unary seam: performs a real Vertex AI call.
     *
     * Lazily constructs the SDK client on first use so unit tests that inject
     * their own predictor never touch the Google client (which is `final` and
     * needs credentials).
     *
     * `$client` is the one seam THIS closure has, and it exists because the
     * RPC invocations below are the only place {@see callOptions()} is ever
     * handed to the SDK - a test that re-derives the GAX stack by hand proves
     * nothing about whether these call sites still pass it. Nothing in
     * production supplies it; when it is null {@see sdkClient()} builds the
     * real, credential-bound client per call exactly as before.
     *
     * @return \Closure(string, string, array<string, mixed>): array<string, mixed>
     */
    private static function defaultPredictor(string $location, ?object $client = null): \Closure
    {
        return static function (string $endpoint, string $method, array $body) use ($location, $client): array {
            $client ??= self::sdkClient($location);

            if ($method === self::METHOD_RAW_PREDICT) {
                $requestClass = 'Google\\Cloud\\AIPlatform\\V1\\RawPredictRequest';
                self::requireClasses($requestClass);

                /** @var object $req */
                $req = (new $requestClass())
                    ->setEndpoint($endpoint)
                    ->setHttpBody(self::httpBody($body));

                // rawPredict answers with an HttpBody whose data is the native
                // Anthropic response document, verbatim.
                $response = $client->rawPredict($req, self::callOptions());

                return json_decode((string) $response->getData(), true) ?? [];
            }

            if ($method === self::METHOD_GENERATE_CONTENT) {
                $response = $client->generateContent(
                    self::generateContentRequest($endpoint, $body),
                    self::callOptions(),
                );

                // GenerateContentResponse is a protobuf message, not an
                // HttpBody: round-tripping it through its own JSON printer is
                // what gives parseGeminiResponse() the camelCase document the
                // published REST reference describes (`candidates`,
                // `usageMetadata`, `promptFeedback`).
                return json_decode($response->serializeToJsonString(), true) ?? [];
            }

            $requestClass = 'Google\\Cloud\\AIPlatform\\V1\\PredictRequest';
            self::requireClasses($requestClass);

            /** @var object $req */
            $req = (new $requestClass())
                ->setEndpoint($endpoint)
                ->setInstances(self::toProtobufValues($body['instances'] ?? []));

            $response = $client->predict($req, self::callOptions());

            foreach ($response->getPredictions() as $prediction) {
                // Protobuf Value -> associative array.
                return json_decode($prediction->serializeToJsonString(), true) ?? [];
            }

            return [];
        };
    }

    /**
     * Default streaming seam: `:streamRawPredict`, whose chunks are Anthropic
     * SSE frames. The frames are reassembled here (a single event can straddle
     * two chunks) and yielded already decoded.
     *
     * `$client` is the same call-site test seam {@see defaultPredictor()}
     * documents - and it matters most here, since this is the RPC whose 60s
     * cap would bound a WHOLE STREAM.
     *
     * @return \Closure(string, string, array<string, mixed>): \Generator<int, array<string, mixed>>
     */
    private static function defaultStreamer(string $location, ?object $client = null): \Closure
    {
        return static function (string $endpoint, string $method, array $body) use ($location, $client): \Generator {
            if ($method === self::METHOD_STREAM_GENERATE_CONTENT) {
                $client ??= self::sdkClient($location);

                // NO SSE FRAMING ON THIS ARM. `streamRawPredict` hands back raw
                // HttpBody chunks that this class has to reassemble itself
                // ({@see decodeSseStream()}); `streamGenerateContent` is a
                // declared server-streaming RPC of a typed message, so the
                // vendored transport does the framing and readAll() yields
                // whole GenerateContentResponses.
                foreach ($client->streamGenerateContent(
                    self::generateContentRequest($endpoint, $body),
                    self::callOptions(),
                )->readAll() as $chunk) {
                    yield json_decode($chunk->serializeToJsonString(), true) ?? [];
                }

                return;
            }

            $requestClass = 'Google\\Cloud\\AIPlatform\\V1\\StreamRawPredictRequest';
            self::requireClasses($requestClass);

            $client ??= self::sdkClient($location);

            /** @var object $req */
            $req = (new $requestClass())
                ->setEndpoint($endpoint)
                ->setHttpBody(self::httpBody($body));

            // Only the RPC itself stays in the closure; the framing runs in
            // decodeSseStream(), which takes raw byte chunks and so can be
            // driven offline with real assertions.
            yield from self::decodeSseStream(
                (static function () use ($client, $req): \Generator {
                    foreach ($client->streamRawPredict($req, self::callOptions())->readAll() as $chunk) {
                        yield (string) $chunk->getData();
                    }
                })()
            );
        };
    }

    /**
     * Per-call options for every PredictionService RPC this class makes.
     *
     * WHY THIS EXISTS: THE SDK SHIPS A 60s TOTAL REQUEST TIMEOUT
     * ----------------------------------------------------------
     * Not configuring a timeout does not mean there isn't one. The GAPIC
     * client config `prediction_service_client_config.json` sets
     * `timeout_millis: 60000` on `Predict`, `RawPredict` AND
     * `StreamRawPredict`; `RetrySettings::load()` folds that into
     * `noRetriesRpcTimeoutMillis`, and `RetryMiddleware::__invoke()` stamps it
     * onto every call as `timeoutMillis` unless the option is already set.
     * `RestTransport::getCallOptions()` then divides it by 1000 into Guzzle's
     * `timeout` - a TOTAL request timeout - and `GrpcTransport` turns it into a
     * call deadline. So the default really is: abort any completion at 60s,
     * and on `streamRawPredict` abort the WHOLE STREAM at 60s, truncating a
     * long agentic turn mid-answer. That is never acceptable for an LLM call;
     * a 40k-token transcript at `max_tokens: 8192` routinely runs past it.
     *
     * WHY THE RETRY SETTINGS AND NOT `timeoutMillis`
     * ----------------------------------------------
     * The obvious lever - passing `['timeoutMillis' => 0]`, since
     * RetryMiddleware's guard is `!isset($options['timeoutMillis'])` - is
     * transport-dependent and dangerous. Under REST, 0 reaches Guzzle as
     * `timeout: 0`, which curl reads as "no timeout": correct. Under gRPC the
     * SAME 0 becomes `timeout: 0` MICROseconds, i.e. a deadline of *now* -
     * every call would fail instantly. And no finite value is defensible
     * either: any cap is a blanket total-request timeout by another name.
     *
     * Zeroing the two RetrySettings timeouts instead makes RetryMiddleware
     * take NEITHER branch, so `timeoutMillis` stays null and no transport ever
     * emits a timeout key at all - Guzzle falls back to its own default of no
     * timeout, gRPC to `Timeval::infFuture()`. Correct on both transports, and
     * it is the absence of a deadline rather than a very large one.
     *
     * BOTH fields have to be zeroed. `RawPredict`/`Predict` carry retry names
     * in the config so they get `initialRpcTimeoutMillis: 0` from
     * `no_retry_params`, but `StreamRawPredict` has none and is built from
     * `RetrySettings::constructDefault()`, whose `initialRpcTimeoutMillis` is
     * 20000. Zeroing only `noRetriesRpcTimeoutMillis` would drop the stream
     * from a 60s cap to a 20s one - worse than leaving it alone.
     *
     * A CONNECT timeout is a different thing and is still set: it bounds only
     * TCP/TLS establishment, which either succeeds in seconds or is not going
     * to. It rides through `transportOptions.restOptions`, which
     * `RestTransport::getCallOptions()` seeds its Guzzle options from, and the
     * value comes from {@see HttpClientDefaults::connectTimeoutSeconds()} so
     * this provider moves with the rest of the library (and with
     * `CANDY_CODE_CONNECT_TIMEOUT`) instead of pinning its own number. gRPC has
     * no per-call equivalent (it uses channel backoff), so the key is simply
     * inert there.
     *
     * @return array<string, mixed>
     */
    private static function callOptions(): array
    {
        return [
            'retrySettings' => [
                'noRetriesRpcTimeoutMillis' => 0,
                'initialRpcTimeoutMillis' => 0,
            ],
            'transportOptions' => [
                'restOptions' => ['connect_timeout' => self::connectTimeoutSeconds()],
            ],
        ];
    }

    /**
     * Builds the regional PredictionServiceClient.
     *
     * `apiEndpoint` is the load-bearing option: publisher-model predictions
     * are served by `{location}-aiplatform.googleapis.com`, never by the
     * global default the client would otherwise use. `projectId` - which this
     * used to pass - is not a key `ClientOptions::fromArray()` reads at all,
     * so it was dropped on the floor; the project is already carried in the
     * endpoint resource name.
     *
     * Credentials are deliberately left to the SDK's own ADC chain
     * (`GOOGLE_APPLICATION_CREDENTIALS` -> gcloud config -> metadata server),
     * the same chain every other Google tool on the box resolves through.
     *
     * NO TIMEOUT IS SET HERE ON PURPOSE - and that is not the same as there
     * being none. The SDK's own client config imposes a 60s TOTAL request
     * timeout on `Predict`, `RawPredict` and `StreamRawPredict`; it is
     * overridden per call in {@see callOptions()}, which is also where the
     * mechanism and the reasoning live. Nothing about client construction can
     * turn it off, so do not look for it here.
     */
    private static function sdkClient(string $location): object
    {
        $clientClass = 'Google\\Cloud\\AIPlatform\\V1\\Client\\PredictionServiceClient';
        self::requireClasses($clientClass);

        return new $clientClass([
            'apiEndpoint' => sprintf('%s-aiplatform.googleapis.com', $location),
        ]);
    }

    /**
     * The AIPlatform SDK is a soft dependency of the offline test path - the
     * injected seams never touch it - so its absence has to fail loudly here
     * rather than as an "undefined class" deep inside a closure.
     */
    private static function requireClasses(string ...$classes): void
    {
        foreach ($classes as $class) {
            if (!class_exists($class)) {
                throw new \RuntimeException(
                    'Vertex AI prediction requires google/cloud-ai-platform; inject a predictor for offline use.'
                );
            }
        }
    }

    /**
     * Wraps a request document as the `HttpBody` `rawPredict` takes. The
     * content type matters: Vertex routes the body to the publisher verbatim.
     *
     * @param array<string, mixed> $body
     */
    private static function httpBody(array $body): object
    {
        $bodyClass = 'Google\\Api\\HttpBody';
        self::requireClasses($bodyClass);

        /** @var object $httpBody */
        $httpBody = new $bodyClass();
        $httpBody->setContentType('application/json');
        $httpBody->setData((string) json_encode($body));

        return $httpBody;
    }

    /**
     * Builds the `GenerateContentRequest` both Gemini RPCs take, from the
     * array document {@see geminiBody()} shapes.
     *
     * ONE BUILDER FOR BOTH SEAMS on purpose: the unary and the streaming RPC
     * take the IDENTICAL request message, so a field wired into one and
     * forgotten in the other is a class of bug this shape cannot have.
     *
     * EVERY FIELD IS SET, AND THAT IS THE POINT. The legacy `:predict` branch
     * of {@see defaultPredictor()} builds its `PredictRequest` with
     * `setEndpoint()` and `setInstances()` and NEVER CALLS `setParameters()`,
     * so `temperature`/`maxOutputTokens` are dropped before the request goes
     * out - a real, pre-existing defect in that arm, left standing there
     * because repairing it is a different step. It is not repeated here:
     * `generationConfig` and `systemInstruction` are set explicitly below and
     * `VertexProviderTest::testTheGeminiCallSiteSendsGenerationConfigOnTheWire`
     * asserts both in the serialized HTTP body of a request driven through the
     * real vendored transport, so removing either setter reds.
     *
     * `setModel()`, not `setEndpoint()`: on `GenerateContentRequest` the
     * publisher resource name is the `model` field, and the vendored REST
     * config binds the `:generateContent` URI template to `getModel`
     * (`prediction_service_rest_client_config.php`, `GenerateContent`).
     *
     * @param array<string, mixed> $body
     */
    private static function generateContentRequest(string $endpoint, array $body): object
    {
        $requestClass = 'Google\\Cloud\\AIPlatform\\V1\\GenerateContentRequest';
        $contentClass = 'Google\\Cloud\\AIPlatform\\V1\\Content';
        $configClass = 'Google\\Cloud\\AIPlatform\\V1\\GenerationConfig';

        self::requireClasses($requestClass, $contentClass, $configClass);

        $contents = is_array($body['contents'] ?? null) ? $body['contents'] : [];

        /** @var object $req */
        $req = (new $requestClass())
            ->setModel($endpoint)
            ->setContents(array_map(
                static fn (array $content): object => self::protobufMessage($contentClass, $content),
                array_values(array_filter($contents, 'is_array')),
            ));

        if (is_array($body['systemInstruction'] ?? null)) {
            $req->setSystemInstruction(self::protobufMessage($contentClass, $body['systemInstruction']));
        }

        if (is_array($body['generationConfig'] ?? null)) {
            $req->setGenerationConfig(self::protobufMessage($configClass, $body['generationConfig']));
        }

        return $req;
    }

    /**
     * One protobuf sub-message merged from its array fragment.
     *
     * `mergeFromJsonString()` rather than a field-by-field setter chain, for
     * the same reason {@see toProtobufValues()} uses it: the array shape is
     * already the protocol's own JSON spelling, so re-typing every field here
     * would add a second place for a key to be misspelled. It is STRICT - an
     * unknown field throws - which is the desired failure: a typo in
     * {@see geminiBody()} fails loudly at the seam rather than silently
     * dropping the key on the wire.
     *
     * @param array<string, mixed> $fragment
     */
    private static function protobufMessage(string $class, array $fragment): object
    {
        /** @var object $message */
        $message = new $class();
        $message->mergeFromJsonString((string) json_encode($fragment));

        return $message;
    }

    /**
     * Wraps each instance map in a protobuf Value so it satisfies the
     * PredictRequest instances field. Kept tiny and isolated so the array-shape
     * building stays testable without the SDK.
     *
     * @param array<int, array<string, mixed>> $instances
     * @return array<int, object>
     */
    private static function toProtobufValues(array $instances): array
    {
        $valueClass = 'Google\\Protobuf\\Value';

        return array_map(static function (array $instance) use ($valueClass): object {
            /** @var object $value */
            $value = new $valueClass();
            $value->mergeFromJsonString((string) json_encode($instance));

            return $value;
        }, $instances);
    }
}
