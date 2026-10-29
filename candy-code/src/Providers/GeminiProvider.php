<?php

declare(strict_types=1);

namespace SugarCraft\Code\Providers;

use GuzzleHttp\Client;
use SugarCraft\Code\Providers\Concerns\AnthropicMessagesWire;
use SugarCraft\Code\Providers\Concerns\GeminiGenerateContentWire;
use SugarCraft\Code\Providers\Concerns\HttpClientDefaults;
use SugarCraft\Code\Providers\Concerns\ToolSchema;

/**
 * The native `generativelanguage.googleapis.com` Gemini arm (PRV-3).
 *
 * Before this class the ONLY way to reach a Gemini model was through
 * Vertex — `publishers/google/models/{model}:generateContent` behind ADC
 * credentials and a project id. Google's own first-party API speaks the
 * IDENTICAL GenerateContent document for a plain API key; the difference
 * is transport and authentication, never the wire document. That is why
 * this class composes the exact traits Vertex's Gemini arm was built on:
 * {@see GeminiGenerateContentWire} (lifted out of VertexProvider intact by
 * PRV-3 — body shaping, response/chunk parsing, `usageMetadata` folding,
 * all fixture-proved byte-identical) riding on {@see AnthropicMessagesWire}
 * for the shared constants, pricing family and helpers the arm reuses.
 * A prompt behaves identically on `vertex` (gemini model) and `gemini`;
 * one fewer reason to route a user through GCP's auth stack.
 *
 * AUTHENTICATION. One header, not a bearer token: `x-goog-api-key` carries
 * the key (Google's documented spelling; the `?key=` query form is the
 * legacy one this deliberately does not use, so the secret never lands in
 * access logs that record URLs). Set on the shipped client, so every
 * request carries it.
 *
 * THE BASE URL IS AN SDK SHARED ROOT. The configured value stays the bare
 * API root; {@see generateEndpoint()} appends the versioned path —
 * accepting a root already spelled `/v1` or `/v1beta` the way
 * {@see AnthropicProvider::messagesEndpoint()} accepts a `/v1` root. The
 * model travels in the path (`models/{model}:generateContent`), never in
 * the body — exactly like Vertex, and for the same reason.
 *
 * STREAMING IS `?alt=sse`. Without it the endpoint answers
 * `application/json` with ONE giant array of response objects streamed as
 * text — unparseable incrementally. With it the answer is Server-Sent
 * Events: one `data:` line per complete GenerateContentResponse, no event
 * names, no `[DONE]` sentinel, usage restated cumulatively on later
 * frames. The SSE framing is the same decoder the Vertex Anthropic arm
 * uses ({@see AnthropicMessagesWire} `decodeSseStream`); what is genuinely
 * new here is only WHICH documents arrive per line, and the shared
 * `parseGeminiChunk()` already knows those.
 *
 * PRICING AND SIZING ARE SHIPPED HERE, NOT LOOKED UP — the same doctrine
 * {@see AnthropicProvider} states: the family rows below are the Gemini
 * half of the tables the PRV-3 extraction left in the shared traits, and
 * an unknown model prices `null` (loud) rather than guessed.
 */
final readonly class GeminiProvider implements ProviderInterface, RebindsModel, ShapesReasoningEffort
{
    use ToolSchema;
    use HttpClientDefaults;
    use AnthropicMessagesWire;
    use GeminiGenerateContentWire;

    /**
     * The model a default `gemini` launch names. Read by
     * {@see ProviderFactory::defaultConfig()} rather than repeated there as
     * a literal — the SglangProvider doctrine: the id must not be able to
     * drift between the two files.
     */
    public const DEFAULT_MODEL = 'gemini-2.5-flash';

    /**
     * Context windows by normalised family, the Gemini rows of the table
     * Vertex carries (ISS-073's doctrine reproduced for the native host):
     * Google's published window for the whole 2.x family is 1,048,576
     * tokens. A family with no row keeps the 200,000 status-quo floor —
     * never a fabricated larger budget that would let the compactor
     * overrun a real limit.
     *
     * @var array<string, int>
     */
    private const CONTEXT_WINDOWS = [
        'gemini-2.5-pro' => 1_048_576,
        'gemini-2.5-flash' => 1_048_576,
        'gemini-2.5-flash-lite' => 1_048_576,
        'gemini-2.0-flash' => 1_048_576,
        'gemini-2.0-flash-lite' => 1_048_576,
    ];

    /** The floor for a family not (yet) in the table. */
    private const FALLBACK_CONTEXT_WINDOW = 200_000;

    private \Closure $http;
    private \Closure $httpStream;
    private Client $httpClient;

    /**
     * @param (callable(string, array<string, mixed>): array<string, mixed>)|null $http
     *        Unary seam: receives the absolute `:generateContent` endpoint
     *        URL and the request document, returns the decoded response
     *        document. Injected by tests; null builds the request through
     *        the shared Guzzle client.
     * @param (callable(string, array<string, mixed>, ?\Closure): iterable<int, array<string, mixed>>)|null $httpStream
     *        Streaming counterpart: same inputs plus the request's optional
     *        heartbeat (the two-parameter seam stays legal — the extra
     *        positional argument is simply ignored), yields decoded
     *        GenerateContentResponse documents.
     * @param array<string, array{0: float, 1: float}|array<string, mixed>> $modelPrices Operator rate overrides, raw-id or family keyed.
     * @param int|null $thinkingBudget Gemini `thinkingConfig.thinkingBudget`
     *        (audit A21 (b), the same value the factory already parses for
     *        vertex): -1 asks for dynamic thinking, 0 turns it off (Flash
     *        models only; Pro refuses 0), a positive count caps it. Null
     *        sends no `thinkingConfig`, leaving the model's own default.
     *        The constructor is the trust boundary: below -1 is refused
     *        HERE, loudly, exactly as VertexProvider's constructor refuses
     *        it there.
     */
    public function __construct(
        private string $apiKey,
        private string $baseUrl = 'https://generativelanguage.googleapis.com',
        private string $defaultModel = self::DEFAULT_MODEL,
        ?callable $http = null,
        ?callable $httpStream = null,
        private array $modelPrices = [],
        private ?int $thinkingBudget = null,
        // ISS-073: the operator's `contextWindow` for this model; null for
        // none, in which case the per-family table answers.
        private ?int $contextWindowOverride = null,
        // The AMW property trio, third leg. The Gemini wire reads no cache
        // marks — 2.5-family caching is implicit and billed through
        // `usageMetadata.cachedContentTokenCount`, which the shared
        // `parseUsageMetadata()` folds — so this flag selects nothing on
        // this arm. It is a constructor parameter (a declared property
        // cannot default inside a readonly class) purely so the composed
        // traits' named contract holds on every instance.
        private bool $promptCache = true,
    ) {
        if ($thinkingBudget !== null && $thinkingBudget < -1) {
            throw new \InvalidArgumentException(sprintf(
                'GeminiProvider thinkingBudget must be -1 (dynamic), 0 (off) or a positive token '
                . 'count, got %d.',
                $thinkingBudget,
            ));
        }

        $this->httpClient = self::guzzleClient([
            'base_uri' => rtrim($baseUrl, '/') . '/',
            'headers' => [
                'Content-Type' => 'application/json',
                'x-goog-api-key' => $apiKey,
            ],
        ]);

        // The seams are stored as Closures over $this->httpClient so the
        // client's shared connect-timeout policy (HttpClientDefaults) is the
        // single transport configuration; a call site that injects a seam
        // replaces the transport wholesale, exactly like Vertex's predictor.
        $this->http = $http !== null
            ? \Closure::fromCallable($http)
            /** @param array<string, mixed> $body */
            : function (string $endpoint, array $body): array {
                $response = $this->httpClient->post($endpoint, ['json' => $body]);

                return json_decode((string) $response->getBody(), true) ?? [];
            };

        $this->httpStream = $httpStream !== null
            ? \Closure::fromCallable($httpStream)
            /** @param array<string, mixed> $body */
            : function (string $endpoint, array $body, ?\Closure $onHeartbeat = null): \Generator {
                $response = $this->httpClient->post($endpoint, [
                    'json' => $body,
                    'stream' => true,
                ] + self::heartbeatOptions($onHeartbeat));

                if ($onHeartbeat !== null) {
                    // Headers landed: deliver one fail-soft beat, so the wait
                    // for the first BODY frame starts from a freshly-reset
                    // idle clock rather than from whenever dialing began.
                    try {
                        ($onHeartbeat)();
                    } catch (\Throwable) {
                        // Fail-soft by contract - see CompleteRequest::$onHeartbeat.
                    }
                }

                $stream = $response->getBody();
                $chunks = (static function () use ($stream): \Generator {
                    while (!$stream->eof()) {
                        yield $stream->read(8192);
                    }
                })();

                yield from self::decodeSseStream($chunks);
            };
    }

    /**
     * @param array<string, mixed> $config
     */
    public static function create(array $config): self
    {
        return new self(
            $config['apiKey'],
            $config['baseUrl'] ?? 'https://generativelanguage.googleapis.com',
            $config['model'] ?? self::DEFAULT_MODEL,
        );
    }

    public function name(): string
    {
        return 'gemini';
    }

    public function shapesEffortFor(string $model): bool
    {
        // The tier spends `thinkingConfig.thinkingBudget`, and the family
        // table in {@see ThinkingCapability} says where that block is
        // accepted at all: gemini-1.* never answered it, and the 2.0 Flash
        // line only answers a configured budget or an explicit off switch —
        // a tier word on those ids would be silently dropped, so the gate
        // refuses it out loud instead.
        return ThinkingCapability::supportsThinking($model);
    }

    /**
     * Absolute GenerateContent URL for a configured root: the root keeps
     * its documented meaning (the bare API root, `/v1` or `/v1beta`
     * suffix tolerated), and the versioned, model-templated path is
     * appended here rather than being left to the client. The model is a
     * PATH SEGMENT, never a body key — the same rule Vertex's arm states
     * in {@see GeminiGenerateContentWire}'s geminiBody().
     */
    public function generateEndpoint(string $model, bool $stream = false): string
    {
        $root = rtrim($this->baseUrl, '/');

        if (!str_ends_with(strtolower($root), '/v1') && !str_ends_with(strtolower($root), '/v1beta')) {
            $root .= '/v1beta';
        }

        return $root . '/models/' . rawurlencode($model) . ($stream ? ':streamGenerateContent?alt=sse' : ':generateContent');
    }

    public function supportsStreaming(): bool
    {
        // `:streamGenerateContent?alt=sse` is a first-class method on this
        // API for every model family it serves.
        return true;
    }

    public function supportsFunctionCalling(): bool
    {
        // Honest flag, the position Vertex's arm documents: this wire
        // COULD carry `tools`, but the arm builds no tool shapes and no
        // `functionCall`/`functionResponse` part is ever parsed, so a
        // model asking to call a tool would have its request silently
        // dropped. Claiming the capability without the plumbing is the
        // bug; refusing it is the truth.
        return false;
    }

    public function supportsVision(): bool
    {
        // Audit 15b-15 on the wire: inline images ride `inlineData` parts
        // built by the shared AttachmentEncoding::geminiParts().
        return true;
    }

    public function supportsDocuments(): bool
    {
        // C-TOOL-2.e: a well-formed PDF rides an `application/pdf`
        // `inlineData` part built by the shared
        // AttachmentEncoding::geminiParts(); a forged or oversized one is
        // named in the text, never inlined.
        return true;
    }

    public function supportsJsonSchema(): bool
    {
        // PRV-3 (3/3): the shared arm emits the native
        // `responseSchema` + `responseMimeType` pair, so this host honestly
        // claims structured output - feeding AUT-4's honesty regime, which
        // asks the flag instead of assuming the wire.
        return true;
    }

    public function contextWindow(): int
    {
        // The doctrine Vertex's ISS-073 table states: operator override
        // first, then the normalised family row, then the status-quo
        // floor. `pricingFamily()` is the shared normaliser the price
        // tables use, so a versioned `gemini-2.5-flash-001` reads the 1M
        // row exactly as its price does.
        return $this->contextWindowOverride
            ?? self::CONTEXT_WINDOWS[self::pricingFamily($this->defaultModel)]
            ?? self::FALLBACK_CONTEXT_WINDOW;
    }

    public function complete(CompleteRequest $request): CompleteResponse
    {
        $model = $this->modelId($request);

        try {
            // Inside the try: the body builder rejects locally-detectable
            // bad input (an empty transcript), and a caller expects that
            // the same way it expects a transport failure - as an error
            // response. The rejection message names 'Gemini generateContent'
            // through the trait's host-label hook.
            $body = $this->geminiBody($request);
            $data = ($this->http)($this->generateEndpoint($model), $body);

            return $this->parseGeminiResponse($data, $model);
        } catch (\Throwable $e) {
            return new CompleteResponse(
                content: '',
                isError: true,
                // Classified while the exception (status, body) still
                // exists, and carried in the text - see ContextOverflow::describe().
                // PRV-3 CRITICAL-2: without a Google JSON envelope the unwrapper
                // returns null and the message is exactly what it was before.
                errorMessage: ContextOverflow::matches($e)
                    ? ContextOverflow::describe($e->getMessage())
                    : (self::vertexJsonHttpErrorBody($e) ?? $e->getMessage()),
                errorContextOverflow: ContextOverflow::matches($e),
                // Same verdict as a field, so the turn loop's overflow
                // recovery reads it rather than the prose. See
                // CompleteResponse::$errorTransient.
                errorTransient: TransientFailure::isTransient($e),
            );
        }
    }

    /**
     * Streams a turn through `POST models/{model}:streamGenerateContent?alt=sse`.
     *
     * The whole loop - cumulative-usage park, E707 length-stop frame, the
     * `sawFinish` premature-end guard - is the shared arm's
     * {@see GeminiGenerateContentWire} `streamGemini()`, the same one
     * Vertex drives; only {@see geminiStreamEvents()} differs, and that is
     * the point of PRV-3.
     *
     * @return \Generator<int, CompleteResponse>
     */
    public function completeStream(CompleteRequest $request): \Generator
    {
        yield from $this->streamGemini($request, $this->modelId($request));
    }

    public function embeddings(EmbeddingsRequest $request): EmbeddingsResponse
    {
        return new EmbeddingsResponse(embeddings: []);
    }

    /**
     * This class never builds an Anthropic Messages document - both entry
     * points go through the Gemini arm only - so the AMW prelude hook is
     * unreachable. It fails loudly rather than inventing a body shape no
     * transport here would send.
     *
     * @return array<string, mixed>
     */
    protected function anthropicWireBodyPrelude(CompleteRequest $request): array
    {
        throw new \LogicException('GeminiProvider never speaks the Anthropic Messages wire');
    }

    protected function anthropicWireUnaryLabel(): string
    {
        return 'Gemini generateContent';
    }

    protected function anthropicWireStreamLabel(): string
    {
        return 'Gemini streamGenerateContent';
    }

    protected function geminiWireHostLabel(): string
    {
        return 'Gemini';
    }

    /**
     * The native stream: the injected (or Guzzle-backed) SSE seam against
     * the `?alt=sse` endpoint. Body through the shared geminiBody(), events
     * decoded by the transport closure; the heartbeat rides the seam the
     * same way it rides AnthropicProvider's.
     *
     * @return iterable<int, array<string,mixed>>
     */
    protected function geminiStreamEvents(CompleteRequest $request, string $model): iterable
    {
        return ($this->httpStream)(
            $this->generateEndpoint($model, stream: true),
            $this->geminiBody($request),
            $request->onHeartbeat,
        );
    }

    /**
     * The trait's spread-based rebinder cannot be used here: this class
     * holds an `httpClient` property that is not a constructor parameter,
     * so the named spread would reject it. Rebuilding positionally keeps
     * every transport seam, price override, thinking budget and sizing
     * override intact across a model switch.
     */
    public function withModel(string $model): static
    {
        return new self(
            $this->apiKey,
            $this->baseUrl,
            $model,
            $this->http,
            $this->httpStream,
            $this->modelPrices,
            $this->thinkingBudget,
            $this->contextWindowOverride,
            $this->promptCache,
        );
    }
}
