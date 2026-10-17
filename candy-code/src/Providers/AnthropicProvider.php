<?php

declare(strict_types=1);

namespace SugarCraft\Code\Providers;

use GuzzleHttp\Client;
use SugarCraft\Code\Providers\Concerns\AnthropicMessagesWire;
use SugarCraft\Code\Providers\Concerns\HttpClientDefaults;
use SugarCraft\Code\Providers\Concerns\ToolSchema;

/**
 * The native `api.anthropic.com/v1/messages` arm (ISS-023).
 *
 * Before this class, the `anthropic` type was a `CustomProvider` pointed at
 * Anthropic's OpenAI-COMPATIBILITY endpoint: OpenAI envelopes on the wire,
 * no prompt-cache marks, and pricing/sizing borrowed from the model database.
 * This provider speaks Anthropic's OWN Messages protocol - the same wire
 * document {@see AnthropicMessagesWire} builds for Vertex's `:rawPredict` -
 * which is what makes prompt caching available on the direct API. The
 * engine-side cache-health warnings that `MarksPromptCache` gates light up
 * for this type automatically, because it now implements the interface.
 *
 * AUTHENTICATION. Two headers, not a bearer token: `x-api-key` carries the
 * key, `anthropic-version: 2023-06-01` names the protocol date. Both are set
 * on the shipped client so they reach the wire on every request.
 *
 * THE BASE URL IS AN ANTHROPIC-SDK SHARED ROOT. The configured value (and
 * `ANTHROPIC_BASE_URL`, which the `claude-code` type hands to the `claude`
 * binary) stays the bare API root - the SDKs append `/v1/...` themselves -
 * so {@see messagesEndpoint()} appends `/v1/messages` here, accepting a root
 * already spelled with `/v1`.
 *
 * PRICING AND SIZING ARE SHIPPED HERE, NOT LOOKED UP. Like Vertex, this
 * class carries the family price table and the family context-window table;
 * an unknown model is priced `null` (loud) rather than guessed from the
 * model database. That is a deliberate change from the old compat arm.
 */
final readonly class AnthropicProvider implements ProviderInterface, MarksPromptCache, AcceptsAssistantPrefill, RebindsModel
{
    use ToolSchema;
    use HttpClientDefaults;
    use AnthropicMessagesWire;

    /** The protocol date Anthropic's Messages API expects in the `anthropic-version` header. */
    public const HEADER_ANTHROPIC_VERSION = '2023-06-01';

    /**
     * Context windows by pricing family (ISS-073's table, claude rows only -
     * the native endpoint speaks no other family). Mirrors
     * {@see BedrockProvider::CONTEXT_WINDOWS} without the route prefixes.
     */
    private const CONTEXT_WINDOWS = [
        'claude-fable-5-1' => 1_000_000,
        'claude-fable-5' => 1_000_000,
        'claude-opus-5-5' => 1_000_000,
        'claude-opus-5' => 1_000_000,
        'claude-opus-4-8' => 1_000_000,
        'claude-opus-4-7' => 1_000_000,
        'claude-opus-4-6' => 1_000_000,
        'claude-sonnet-5-5' => 1_000_000,
        'claude-sonnet-5' => 1_000_000,
        'claude-sonnet-4-6' => 1_000_000,
        'claude-opus-4-5' => 200_000,
        'claude-opus-4-1' => 200_000,
        'claude-opus-4' => 200_000,
        'claude-3-opus' => 200_000,
        'claude-sonnet-4-5' => 200_000,
        'claude-sonnet-4' => 200_000,
        'claude-3-7-sonnet' => 200_000,
        'claude-3-5-sonnet' => 200_000,
        'claude-3-sonnet' => 200_000,
        'claude-haiku-4-5' => 200_000,
        'claude-3-5-haiku' => 200_000,
        'claude-3-haiku' => 200_000,
    ];

    /** The floor for a claude id whose family is not (yet) in the table. */
    private const FALLBACK_CONTEXT_WINDOW = 200_000;

    private \Closure $http;
    private \Closure $httpStream;
    private Client $httpClient;

    /**
     * @param (callable(string, array<string, mixed>): array<string, mixed>)|null $http
     *        Unary seam: receives the absolute endpoint URL and the request
     *        document, returns the decoded response document. Injected by
     *        tests; null builds the request through the shared Guzzle client.
     * @param (callable(string, array<string, mixed>): iterable<int, array<string, mixed>>)|null $httpStream
     *        Streaming counterpart: same inputs, yields decoded SSE events.
     * @param array<string, array{0: float, 1: float}|array<string, mixed>> $modelPrices Operator rate overrides, raw-id or family keyed.
     */
    public function __construct(
        private string $apiKey,
        private string $baseUrl = 'https://api.anthropic.com',
        private string $defaultModel = 'claude-sonnet-4-6',
        ?callable $http = null,
        ?callable $httpStream = null,
        private array $modelPrices = [],
        private bool $promptCache = true,
        private ?int $contextWindowOverride = null,
    ) {
        $this->httpClient = self::guzzleClient([
            'base_uri' => rtrim($baseUrl, '/') . '/',
            'headers' => [
                'Content-Type' => 'application/json',
                'x-api-key' => $apiKey,
                'anthropic-version' => self::HEADER_ANTHROPIC_VERSION,
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
                $response = $this->httpClient->post($this->messagesEndpoint(), ['json' => $body]);

                return json_decode((string) $response->getBody(), true) ?? [];
            };

        $this->httpStream = $httpStream !== null
            ? \Closure::fromCallable($httpStream)
            /** @param array<string, mixed> $body */
            : function (string $endpoint, array $body): \Generator {
                $response = $this->httpClient->post($this->messagesEndpoint(), [
                    'json' => $body,
                    'stream' => true,
                ]);
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
            $config['baseUrl'] ?? 'https://api.anthropic.com',
            $config['model'] ?? 'claude-sonnet-4-6',
        );
    }

    public function name(): string
    {
        return 'anthropic';
    }

    /**
     * Absolute Messages URL for a configured root: the root keeps its
     * documented meaning (the bare API root, `/v1`-suffix tolerated), and
     * `/messages` is appended here rather than being left to the client.
     */
    public function messagesEndpoint(?string $baseUrl = null): string
    {
        $root = rtrim($baseUrl ?? $this->baseUrl, '/');

        return (str_ends_with(strtolower($root), '/v1') ? $root : $root . '/v1') . '/messages';
    }

    public function supportsStreaming(): bool
    {
        // The Messages protocol streams for every model this host serves.
        return true;
    }

    public function supportsFunctionCalling(): bool
    {
        return $this->isAnthropicModel($this->defaultModel);
    }

    public function supportsVision(): bool
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
        // the flat 200K every pre-table provider answered.
        return $this->contextWindowOverride
            ?? self::CONTEXT_WINDOWS[self::pricingFamily($this->defaultModel)]
            ?? self::FALLBACK_CONTEXT_WINDOW;
    }

    public function complete(CompleteRequest $request): CompleteResponse
    {
        $model = $this->modelId($request);

        try {
            // Inside the try: the body builder rejects locally-detectable bad
            // input (an empty transcript), and a caller expects that the same
            // way it expects a transport failure - as an error response.
            $body = $this->anthropicBody($request, stream: false);
            $data = ($this->http)($this->messagesEndpoint(), $body);

            return $this->parseAnthropicResponse($data, $model);
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
                // re-derived from the message downstream. See
                // CompleteResponse::$errorTransient.
                errorTransient: TransientFailure::isTransient($e),
            );
        }
    }

    /**
     * Streams a turn through `POST /v1/messages` with `stream: true`.
     *
     * Each yielded CompleteResponse carries only that event's delta; callers
     * accumulate. USAGE LANDS TWICE here (`message_start` carries the input
     * side, the terminal `message_delta` the output side), the same split
     * {@see AnthropicMessagesWire} documents for Vertex - Runtime SUMS across
     * chunks rather than taking the last.
     *
     * @return \Generator<int, CompleteResponse>
     */
    public function completeStream(CompleteRequest $request): \Generator
    {
        $model = $this->modelId($request);

        // Accumulates `input_json_delta` fragments for in-flight `tool_use`
        // blocks, keyed by the stream's content-block index. A local rather
        // than a property because this class is `final readonly`.
        $toolCallBuffer = [];

        try {
            // The endpoint and body are inside the try because both can
            // reject locally-detectable bad input; a generator's caller must
            // see that as an error CHUNK, not an exception on first iteration.
            $body = $this->anthropicBody($request, stream: true);

            // Whether the stream reached its terminal event. A transport cut
            // ends the read quietly, so falling out of the loop proves
            // nothing; `message_stop` (or the `message_delta` carrying
            // `stop_reason`, which precedes it) is the protocol saying the
            // message is whole. An error chunk counts too: it already reports
            // the failure, and a second, transient one would overwrite its
            // verdict at the consumer.
            $sawTerminal = false;

            foreach (($this->httpStream)($this->messagesEndpoint(), $body) as $event) {
                $event = is_array($event) ? $event : [];
                $chunk = $this->parseAnthropicChunk($event, $model, $toolCallBuffer);

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
                yield self::prematureEndChunk($this->anthropicWireStreamLabel() . ': ');
            }
        } catch (\Throwable $e) {
            yield new CompleteResponse(
                content: '',
                isError: true,
                errorMessage: ContextOverflow::matches($e) ? ContextOverflow::describe($e->getMessage()) : $e->getMessage(),
                errorContextOverflow: ContextOverflow::matches($e),
                // This catch sits OUTSIDE the chunk loop, so it can fire
                // after real deltas have already been yielded - which is
                // precisely the case Runtime::runStreaming() must not blindly
                // retry.
                errorTransient: TransientFailure::isTransient($e),
            );
        }
    }

    public function embeddings(EmbeddingsRequest $request): EmbeddingsResponse
    {
        return new EmbeddingsResponse(embeddings: []);
    }

    /**
     * The Messages API names the model IN THE BODY - unlike Vertex's
     * `:rawPredict`, which templates it into the resource name and rejects a
     * body `model` field. The protocol date rides the `anthropic-version`
     * header instead of a body key.
     */
    protected function anthropicWireBodyPrelude(CompleteRequest $request): array
    {
        return ['model' => $this->modelId($request)];
    }

    protected function anthropicWireUnaryLabel(): string
    {
        return 'Anthropic Messages';
    }

    protected function anthropicWireStreamLabel(): string
    {
        return 'Anthropic Messages';
    }

    /**
     * The trait's spread-based rebinder cannot be used here: this class holds
     * a `httpClient` property that is not a constructor parameter, so the
     * named spread would reject it. Rebuilding positionally keeps every
     * transport seam, price override, cache flag and sizing override intact
     * across a model switch.
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
            $this->promptCache,
            $this->contextWindowOverride,
        );
    }
}
