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
use SugarCraft\Crush\Providers\Concerns\HttpClientDefaults;
use SugarCraft\Crush\Providers\Concerns\ReasoningExtractor;
use SugarCraft\Crush\Providers\Concerns\ReassemblesStreamedToolCalls;
use SugarCraft\Crush\Providers\Concerns\SessionAffinity;
use SugarCraft\Crush\Tools\Tool;
use SugarCraft\Crush\Tools\ToolCall;
use SugarCraft\Crush\Providers\Concerns\ToolSchema;
use SugarCraft\Crush\Usage;

final readonly class CustomProvider implements ProviderInterface, RebindsModel
{
    use ToolSchema;

    use ReasoningExtractor;

    use HttpClientDefaults;

    use SessionAffinity;

    /**
     * Streamed tool-call reassembly and the end-of-stream flush (audits W1.A1,
     * 15a A4, A11, A23), shared with OpenAIProvider and SglangProvider since
     * X-31a instead of the copy this class carried.
     */
    use ReassemblesStreamedToolCalls;

    /**
     * The `temperature` sent when neither the request nor the `temperature`
     * setting names one — what this class has always sent.
     */
    public const DEFAULT_TEMPERATURE = 0.7;

    /**
     * Roadmap N-P4a: the operator's sampling temperature for this provider,
     * read per request ({@see temperature()}), so a save applies from the
     * next turn. `custom` only, like `extraBody`: SGLang keeps its per-family
     * defaults, which a model card prescribes and a global value would undo.
     */
    private const TEMPERATURE_CONFIG_KEY = 'temperature';

    /** The range every OpenAI-compatible server accepts for `temperature`. */
    public const MAX_TEMPERATURE = 2.0;

    /**
     * E707 (round 81): the `finish_reason` strings on this OpenAI-compatible
     * wire that mark a capacity cut rather than a clean end. `length` is the
     * protocol's own; `abort` is what the vLLM/SGLang-family servers this
     * generic provider is routinely pointed at emit for the same event, the
     * same pair the sglang port pins for its deployment. `tool_calls` and
     * `stop` stay silent; `content_filter` is a safety end mislabeled by any
     * "raise the ceiling" advice, so it is excluded deliberately.
     *
     * @var list<string>
     */
    private const TRUNCATED_FINISH_REASONS = ['length', 'abort'];

    /**
     * Audit 15a A10: the body keys complete()/completeStream() write
     * themselves. An `$extraBody` entry naming one is rejected at
     * construction rather than silently overriding (or being overridden by)
     * the provider's own value - either outcome would ship a request the
     * caller didn't ask for.
     *
     * @var list<string>
     */
    private const RESERVED_BODY_KEYS = [
        'model',
        'messages',
        'temperature',
        'max_tokens',
        'stream',
        'stream_options',
        'tools',
    ];

    public function __construct(
        private string $name,
        private string $baseUrl,
        private string $model,
        private ?string $apiKey,
        private Client $httpClient,
        private bool $supportsStreaming,
        private bool $supportsFunctionCalling,
        /**
         * Raw session id for cache-affinity routing, hashed per request at
         * send time - the wire never carries the raw value. `null` (the
         * default) means no affinity header, byte-identical to the pre-P10.S4
         * request. Why hashed and the consumer contract live in the
         * {@see SessionAffinity} trait docblock.
         */
        private ?string $sessionAffinityId = null,
        /**
         * Audit 15a A10: server-specific request fields, merged into the TOP
         * LEVEL of every chat/completions body.
         *
         * `extra_body` is an OpenAI *Python SDK* client-side argument: the SDK
         * splices that dict into the JSON body before it hits the wire, so the
         * server only ever sees its keys at the top level. This provider used
         * to send a literal `"extra_body": {"separate_reasoning": true}` key,
         * which strict OpenAI-compatible servers (api.openai.com, hosted
         * gateways) answer with a 400 on every request, while lenient ones
         * (SGLang - see SglangProvider::buildParams()) drop it unparsed, so
         * the flag never reached anyone. Merging top-level here is what the
         * SDK does.
         *
         * The default is empty, not `['separate_reasoning' => true]`: this
         * class also fronts the `anthropic` type and arbitrary custom servers,
         * and a strict server rejects an unknown top-level field just as it
         * rejected `extra_body`. Opt in per server that understands the key.
         * This parameter is the seam a future per-provider config key would
         * feed; nothing wires one yet.
         *
         * @var array<string, mixed>
         */
        private array $extraBody = [],
        /**
         * Audit 15b-15: whether this endpoint's model reads `image_url`
         * content parts. Off by default because a custom server is an unknown
         * model and a strict text-only one answers an image part with a 400;
         * the `custom` provider block's `supportsVision` key turns it on, and
         * the `anthropic` type (whose OpenAI-compatibility endpoint reads
         * base64 `image_url` parts) is built with it on. When false, an
         * attached image reaches the model as a named text placeholder and the
         * user gets a transcript notice ({@see \SugarCraft\Crush\Backend\EngineBackend::toTypedMessages()}).
         */
        private bool $supportsVision = false,
        /**
         * Roadmap 5.13a: the model database consulted for the window and the
         * rates when the operator's own settings say nothing. Null (the
         * default, and every direct construction) keeps the pre-5.13a
         * answers: a 128,000-token window and a real $0.
         */
        private ?ModelMetadata $modelMetadata = null,
        /**
         * Roadmap 5.13a: the operator's `modelPrices` (USD per 1M tokens per
         * model, the {@see OpenAIProvider} shape). Naming a model makes the
         * declaration authoritative for it, as there.
         *
         * @var array<string, mixed>
         */
        private array $modelPrices = [],
        /** Roadmap 5.13a: the operator's `contextWindow` for this model; null for none. */
        private ?int $contextWindowOverride = null,
    ) {
        foreach (array_keys($extraBody) as $key) {
            if (!is_string($key) || $key === '') {
                throw new \InvalidArgumentException(sprintf(
                    'CustomProvider extraBody keys must be non-empty strings, got %s.',
                    var_export($key, true),
                ));
            }
            if ($key === 'extra_body') {
                throw new \InvalidArgumentException(
                    'CustomProvider extraBody must not contain an "extra_body" key: extra_body is an OpenAI '
                    . 'Python SDK argument the SDK flattens into the body, and sent literally it is an unknown '
                    . 'field strict servers reject. Pass its inner keys (e.g. ["separate_reasoning" => true]) '
                    . 'directly instead.',
                );
            }
            if (in_array($key, self::RESERVED_BODY_KEYS, true)) {
                throw new \InvalidArgumentException(sprintf(
                    'CustomProvider extraBody must not set "%s": the provider writes that body key itself.',
                    $key,
                ));
            }
        }
    }

    /**
     * @param array<string, mixed> $extraBody Top-level server-specific body
     *        fields; see the constructor's `$extraBody` for the contract.
     * @param array<string, mixed> $modelPrices Roadmap 5.13a; see the
     *        constructor's `$modelPrices`.
     */
    public static function openAiCompatible(
        string $name,
        string $baseUrl,
        string $model,
        ?string $apiKey = null,
        bool $supportsStreaming = true,
        bool $supportsFunctionCalling = true,
        ?string $sessionAffinityId = null,
        array $extraBody = [],
        bool $supportsVision = false,
        ?ModelMetadata $modelMetadata = null,
        array $modelPrices = [],
        ?int $contextWindowOverride = null,
    ): self {
        $headers = [
            'Content-Type' => 'application/json',
        ];

        if ($apiKey !== null) {
            $headers['Authorization'] = 'Bearer ' . $apiKey;
        }

        // guzzleClient() (not `new Client`) so this provider inherits the
        // shared connect-timeout policy - see HttpClientDefaults.
        $client = self::guzzleClient([
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
            sessionAffinityId: $sessionAffinityId,
            extraBody: $extraBody,
            supportsVision: $supportsVision,
            modelMetadata: $modelMetadata,
            modelPrices: $modelPrices,
            contextWindowOverride: $contextWindowOverride,
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
        return $this->supportsVision;
    }

    public function supportsJsonSchema(): bool
    {
        return false;
    }

    /**
     * This provider answering for $model (roadmap N-P3b remainder): the
     * window and the rates below read the configured id, so a model switch
     * through {@see \SugarCraft\Crush\Backend\EngineBackend::withModel()}
     * rebinds it here instead of leaving them the old model's.
     */
    public function withModel(string $model): static
    {
        return new self(...array_merge(get_object_vars($this), ['model' => $model]));
    }

    /**
     * Roadmap 5.13a: the operator's `contextWindow` setting, else the model
     * database's figure for this model ({@see ModelMetadata}), else 128,000 —
     * the fixed answer this method gave for every model before, kept as the
     * last resort for a self-hosted id no database lists.
     */
    public function contextWindow(): int
    {
        return $this->contextWindowOverride
            ?? $this->modelMetadata?->contextWindow($this->model)
            ?? 128_000;
    }

    /**
     * Roadmap 5.13a: the operator's `modelPrices` row, else the model
     * database's rate, else a real 0.0 — a self-hosted model no database
     * lists costs nothing to run, which is a measurement, not a shrug (see
     * {@see ProviderInterface::costPer1kTokens()}). The database matters for
     * the `anthropic` type and for hosted OpenAI-compatible gateways, which
     * this class also fronts and which do bill.
     *
     * A model the operator NAMED is answered from that row alone, as in
     * {@see OpenAIProvider::costPer1kTokens()}: a rate that fails validation
     * is null (unpriced, loudly), never silently re-priced at the database's
     * figure the operator overrode.
     */
    public function costPer1kTokens(string $model, string $direction): ?float
    {
        if (array_key_exists($model, $this->modelPrices)) {
            $entry = $this->modelPrices[$model];
            $declared = is_array($entry) ? ($entry[$direction] ?? null) : null;
            if (!is_numeric($declared)) {
                return null;
            }
            $rate = ((float) $declared) / 1000; // config speaks USD-per-1M

            return $rate >= 0.0 && is_finite($rate) ? $rate : null;
        }

        return $this->modelMetadata?->costPer1kTokens($model, $direction) ?? 0.0;
    }

    /**
     * The temperature for a request that names none: the `temperature`
     * setting when it is a finite number in `0..`{@see MAX_TEMPERATURE},
     * else {@see DEFAULT_TEMPERATURE}. Out-of-range values fall back rather
     * than being clamped, the doctrine every numeric setting follows — a
     * server would reject them, and a silently different number is one the
     * operator never chose.
     */
    private static function temperature(): float
    {
        $raw = self::settingsConfig()[self::TEMPERATURE_CONFIG_KEY] ?? null;
        if (\is_string($raw)) {
            $raw = is_numeric($raw) ? (float) $raw : null;
        }

        if ((\is_int($raw) || \is_float($raw)) && is_finite((float) $raw) && $raw >= 0 && $raw <= self::MAX_TEMPERATURE) {
            return (float) $raw;
        }

        return self::DEFAULT_TEMPERATURE;
    }

    public function complete(CompleteRequest $request): CompleteResponse
    {
        $params = [
            'model' => $request->model,
            // Step 1.A-1: formatMessages() owns the single leading system row
            // (the prompt, then any leading history system rows; '' counts as
            // unset, so an empty prompt earns no wire turn) and renders later
            // system rows in place - see SglangProvider::placeSystemRows().
            'messages' => $this->formatMessages($request->messages, $request->systemPrompt),
            'temperature' => $request->temperature ?? self::temperature(),
            'max_tokens' => $request->maxTokens ?? 4096,
            // No reasoning-splitting flag by default (audit 15a A10): the old
            // literal `extra_body` wrapper was never parsed by SGLang (the
            // self-hosted class of server a CustomProvider instance frequently
            // points at - see SglangProvider) and is a 400 on strict servers.
            // A deployment that understands `separate_reasoning` opts in
            // through the `$extraBody` constructor parameter (merged
            // top-level by withExtraBody()).
            // Either way extractReasoning()'s <think>-stripping fallback in
            // parseResponse() is what handles reasoning on this provider -
            // a parser such as `minimax-append-think` inlines it regardless.
        ];

        if ($request->tools !== null && $this->supportsFunctionCalling) {
            $params['tools'] = $this->formatTools($request->tools);
        }


        try {
            // 'headers' here (not client defaults) so the affinity header also
            // rides injected clients - see SessionAffinity::sessionAffinityHeaders().
            // heartbeatOptions() is [] unless E493's caller supplied a
            // progress closure, so the spread is byte-neutral otherwise.
            $response = $this->httpClient->post('chat/completions', [
                'json' => $this->withExtraBody($params),
                'headers' => $this->sessionAffinityHeaders($request->sessionId),
            ] + self::heartbeatOptions($request->onHeartbeat));

            $data = json_decode($response->getBody()->getContents(), true);
            return $this->parseResponse($data);
        } catch (GuzzleException $e) {
            return new CompleteResponse(
                content: '',
                isError: true,
                errorMessage: self::failureText($e),
                // Classified here, where the exception still exists: this
                // provider reports a failure as a response rather than by
                // throwing, so the verdict has to be carried rather than
                // re-derived from the message downstream. See
                // CompleteResponse::$errorTransient.
                errorTransient: TransientFailure::isTransient($e),
                // Roadmap 2.7-1b: the overflow verdict rides as a field too,
                // not only in the text (CompleteResponse::$errorContextOverflow).
                errorContextOverflow: ContextOverflow::matches($e),
            );
        }
    }

    /**
     * The text of a transport failure's error response, and — roadmap 2.7-1a —
     * the overflow verdict inside it.
     *
     * This provider reports a failure as an `isError` {@see CompleteResponse},
     * which has no field for "the prompt did not fit"; the verdict is decided
     * here, while the exception still carries its status and whole body
     * ({@see ContextOverflow::matches()}), and rides in the text via
     * {@see ContextOverflow::describe()} so it is still recognisable once the
     * exception is gone. For an overflow the server's own message is used
     * rather than Guzzle's status-line-plus-clipped-body dump (the reading
     * SglangProvider settled on, E-56); every other failure keeps
     * `$e->getMessage()` byte for byte.
     */
    private static function failureText(GuzzleException $e): string
    {
        if (!ContextOverflow::matches($e)) {
            return $e->getMessage();
        }

        return ContextOverflow::describe(SglangProvider::errorBodyMessage($e) ?? $e->getMessage());
    }

    /**
     * Audit 15a A10: the one place `$extraBody` reaches the wire, shared by
     * complete() and completeStream() so the two bodies cannot drift. The
     * constructor already rejected every key the provider writes itself;
     * the `+` union additionally keeps the provider's own value should a
     * new body key be added without updating RESERVED_BODY_KEYS.
     *
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function withExtraBody(array $params): array
    {
        return $params + $this->extraBody;
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
            // Step 1.A-1: formatMessages() owns the single leading system row
            // (the prompt, then any leading history system rows; '' counts as
            // unset, so an empty prompt earns no wire turn) and renders later
            // system rows in place - see SglangProvider::placeSystemRows().
            'messages' => $this->formatMessages($request->messages, $request->systemPrompt),
            'temperature' => $request->temperature ?? self::temperature(),
            'max_tokens' => $request->maxTokens ?? 4096,
            'stream' => true,
            // Billing fix (audit-crush-core finding 1), set next to `stream`
            // and NOT in the shared batch body — Sglang's law: the batch
            // request must stay byte-identical. OpenAI-compatible servers
            // that know the flag answer a final zero-choice usage frame;
            // servers that ignore unknown params simply never trigger the
            // capture below, which leaves the turn unreported — the same
            // honest null the batch path gives, never a fabricated count.
            'stream_options' => ['include_usage' => true],
        ];

        if ($request->tools !== null && $this->supportsFunctionCalling) {
            $params['tools'] = $this->formatTools($request->tools);
        }


        try {
            $response = $this->httpClient->post('chat/completions', [
                'json' => $this->withExtraBody($params),
                'stream' => true,
                'headers' => $this->sessionAffinityHeaders($request->sessionId),
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

            // Terminal usage of this stream, captured from the include_usage
            // frame the server sends AFTER the finish_reason frame — the
            // reason the loop below no longer returns at `finish_reason`.
            $streamUsage = null;

            // Audit 15a A3: the two signals that a stream was CLOSED rather
            // than CUT - Guzzle's StreamHandler reports a dropped connection
            // as a plain eof(), so without them the two look identical.
            $sawFinish = false;
            $sawDone = false;

            // Audit 15a A4: the LAST non-null `finish_reason`, read after the
            // loop to decide how leftover tool-call fragments are flushed.
            $streamFinishReason = null;

            while (!$stream->eof()) {
                $chunk = $stream->read(8192);
                $buffer .= $chunk;

                // Process complete lines in buffer
                while (($newlinePos = strpos($buffer, "\n")) !== false) {
                    $line = substr($buffer, 0, $newlinePos);
                    $buffer = substr($buffer, $newlinePos + 1);

                    $line = trim($line);
                    // Audit 15a A5: `data:` with or without the one optional
                    // space - the spec's reading, which some gateways rely on.
                    $payload = SseData::value($line);
                    if ($payload !== null) {
                        $sawDone = $sawDone || $payload === '[DONE]';
                        $data = json_decode($payload, true);
                        if ($data === null) {
                            // JSON parse failed, skip — this is also the path
                            // the `data: [DONE]` sentinel takes, and reading
                            // past it to EOF is what lets the usage frame that
                            // OpenAI-compatible servers place before (or
                            // around) it reach the fold.
                            continue;
                        }
                        // Audit 15a A2: an error raised after the 200 went out
                        // (context overflow, abort, OOM) arrives as an SSE
                        // frame and matched neither branch below, so the turn
                        // used to end as a "success". Report it the way this
                        // provider reports every failure - one isError chunk
                        // - and stop reading; Runtime retries it only when
                        // transient and nothing was emitted yet, else throws.
                        $streamError = ProviderStreamException::fromErrorEvent($data);
                        if ($streamError !== null) {
                            $stream->close();

                            yield new CompleteResponse(
                                content: '',
                                isError: true,
                                errorMessage: $streamError->contextOverflow
                                    ? ContextOverflow::describe($streamError->getMessage())
                                    : $streamError->getMessage(),
                                errorTransient: TransientFailure::isTransient($streamError),
                                errorContextOverflow: $streamError->contextOverflow,
                            );

                            return;
                        }
                        // E707 (round 81): read the stop value BEFORE the
                        // delta gate - the Sglang law that a truncating end
                        // can arrive on a frame carrying no content of its
                        // own. When it does, a flag-only frame tells the fold
                        // the reply was cut; when the closing frame also
                        // carries a delta, parseChunk() rides the flag on it.
                        $finishReason = $data['choices'][0]['finish_reason'] ?? null;
                        $streamFinishReason = $finishReason ?? $streamFinishReason;
                        $hasDelta = isset($data['choices'][0]['delta']);
                        if ($hasDelta) {
                            yield $this->parseChunk($data, $toolCallBuffer);
                        } elseif ($finishReason !== null && is_array($data['choices'][0] ?? null)) {
                            // Audit 15a A4: a finish frame with `"delta":
                            // null` (or none) must still run through
                            // parseChunk(), because a `tool_calls` finish is
                            // what drains the fragment buffer - skipping it
                            // lost every streamed call on that shape. It
                            // carries no text, so it is yielded only when it
                            // assembled calls; a truncating end's flag still
                            // rides the flag-only frame below.
                            $finishChunk = $this->parseChunk($data, $toolCallBuffer);
                            if ($finishChunk->toolCalls !== null) {
                                yield $finishChunk;
                            }
                        } elseif (!isset($data['choices'][0]) && is_array($data['usage'] ?? null)) {
                            // The include_usage terminal frame: usage present,
                            // choices empty. Captured, not yielded — the Sglang
                            // gate in shape, so a content-less usage document
                            // never masquerades as an empty token chunk.
                            $streamUsage = $this->parseUsage($data['usage']);
                        }
                        if ($finishReason !== null) {
                            $sawFinish = true;
                            // Stop seen. NOT a return: on this wire the usage
                            // document arrives on the frame(s) AFTER the one
                            // that carries finish_reason, and the pre-fix
                            // return here discarded it — every streamed Custom
                            // turn calibrated against nothing.
                            if (!$hasDelta && in_array($finishReason, self::TRUNCATED_FINISH_REASONS, true)) {
                                yield new CompleteResponse(content: '', truncated: true);
                            }
                        }
                    }
                }
            }

            // A server that closes after its last frame without the "\n" the
            // loop splits on leaves that frame in $buffer; it can still be the
            // sentinel, and a clean end must not be mistaken for a cut.
            $sawDone = $sawDone || SseData::isDone($buffer);

            // Audit 15a A3: EOF with neither a `finish_reason` nor `[DONE]` is
            // a cut connection (proxy idle-timeout, server restart, reset),
            // and the half-finished text used to stand as the whole answer.
            // Reported as this provider reports every failure - one isError
            // chunk, TRANSIENT - so Runtime retries while nothing has reached
            // the screen and surfaces the cut otherwise.
            if (!$sawFinish && !$sawDone) {
                $stream->close();
                $cut = ProviderStreamException::prematureEnd();

                yield new CompleteResponse(
                    content: '',
                    isError: true,
                    errorMessage: $cut->getMessage(),
                    errorTransient: TransientFailure::isTransient($cut),
                );

                return;
            }

            // Audit 15a A4: fragments still buffered here were never emitted -
            // the stream ended on `stop` (vLLM historically, some proxies and
            // parser combinations end a tool-call stream that way), on a
            // truncating reason, or on `[DONE]` with no finish frame at all.
            // Flush them best-effort, riding BEFORE the usage carrier so the
            // bill stays the stream's last event. `error` is the one end that
            // does not flush: the server disowned its own generation.
            if ($toolCallBuffer !== [] && $streamFinishReason !== 'error') {
                $streamEndedTruncated = $streamFinishReason === null
                    || in_array($streamFinishReason, self::TRUNCATED_FINISH_REASONS, true);
                $flushed = self::flushStreamedToolCalls($toolCallBuffer, $streamEndedTruncated, $streamFinishReason, 'CustomProvider');

                if ($flushed !== null) {
                    // Empty content: the fragments streamed as tool deltas,
                    // not text. `truncated` states what the wire said, so a
                    // clean `stop` end is not mistaken for a length stop.
                    yield new CompleteResponse(
                        content: '',
                        toolCalls: $flushed,
                        tokensUsed: 0,
                        costUsd: 0.0,
                        truncated: $streamEndedTruncated,
                    );
                }
            }

            if ($streamUsage !== null) {
                // One terminal carrier with the whole stream's counted usage —
                // per ProviderInterface::completeStream()'s "emit each total
                // exactly once, on the terminal chunk" law, and byte-for-byte
                // the shape SglangProvider's fold consumes. Cost is this
                // provider's real 0.0 (self-hosted); the TOKENS are what the
                // E17 calibration was missing on every streamed turn.
                yield new CompleteResponse(
                    content: '',
                    tokensUsed: $streamUsage->totalTokens,
                    costUsd: $streamUsage->costUsd,
                    usage: $streamUsage,
                );
            }
        } catch (GuzzleException $e) {
            yield new CompleteResponse(
                content: '',
                isError: true,
                errorMessage: self::failureText($e),
                // See complete()'s catch: same reason, and this one can fire
                // after real content chunks have already been yielded, which is
                // what makes the retry decision at the consumer conditional
                // rather than automatic.
                errorTransient: TransientFailure::isTransient($e),
                errorContextOverflow: ContextOverflow::matches($e),
            );
        }
    }

    /**
     * Audit A17: a transport failure or a 2xx body that is not an embeddings
     * payload THROWS instead of returning an empty list. The old silent `[]`
     * made an outage look exactly like "no results", so a semantic-search
     * consumer degraded without ever telling the user why. The message
     * carries `$e->getMessage()` (the same text complete() reports as
     * errorMessage), code 0, and the Guzzle exception as previous so
     * TransientFailure::isTransient() still classifies 5xx/429/connect as
     * transient and 400 as permanent. A present-but-empty `data: []` remains
     * a legitimate empty result.
     *
     * @throws \RuntimeException on transport failure or a malformed payload
     */
    public function embeddings(EmbeddingsRequest $request): EmbeddingsResponse
    {
        try {
            $response = $this->httpClient->post('embeddings', [
                'json' => [
                    'model' => $request->model,
                    'input' => $request->input,
                ],
                'headers' => $this->sessionAffinityHeaders(),
            ]);
        } catch (GuzzleException $e) {
            throw new \RuntimeException(
                sprintf('%s embeddings request failed: %s', $this->name, $e->getMessage()),
                0,
                $e
            );
        }

        return new EmbeddingsResponse(
            embeddings: self::embeddingVectors(
                json_decode($response->getBody()->getContents(), true),
                $this->name,
            )
        );
    }

    /**
     * Extracts the `data[*].embedding` vectors from a decoded
     * `/v1/embeddings` body, refusing anything that is not that shape (audit
     * A17) - a non-JSON body, a missing or non-list `data`, or an item with
     * no array `embedding` used to collapse silently into `[]`.
     *
     * @return list<array<mixed>>
     * @throws \RuntimeException when the payload is not an embeddings response
     */
    private static function embeddingVectors(mixed $decoded, string $label): array
    {
        if (!is_array($decoded)) {
            throw new \RuntimeException("{$label} embeddings response is not a JSON object");
        }

        $data = $decoded['data'] ?? null;
        if (!is_array($data) || !array_is_list($data)) {
            throw new \RuntimeException("{$label} embeddings response has no `data` list");
        }

        $vectors = [];
        foreach ($data as $index => $item) {
            if (!is_array($item) || !is_array($item['embedding'] ?? null)) {
                throw new \RuntimeException("{$label} embeddings response item {$index} has no `embedding` array");
            }
            $vectors[] = $item['embedding'];
        }

        return $vectors;
    }

    /**
     * Formats the transcript and places its system content (step 1.A-1).
     *
     * Before 1.A-1 the request-level prompt was prepended by complete() and
     * completeStream() and every history SystemMessage stayed a `system` row
     * where it sat - so a cancellation marker or compaction notice produced a
     * `system` row at index > 0, which the Qwen-family templates this class
     * is pointed at answer with HTTP 400 "System message must be at the
     * beginning." (E-10), and a launch notice produced a SECOND leading
     * system row. Now the placement is
     * {@see SglangProvider::placeSystemRows()}'s, shared on purpose so the
     * two OpenAI-shaped self-hosted providers cannot disagree: one leading
     * system row (the prompt, then the history system rows ahead of the first
     * non-system row), every later system row in place as a user-role
     * `<system-notice>`, empty system rows dropped.
     *
     * @param array<Message> $messages
     * @param ?string $systemPrompt the request-level assembled prompt; '' counts as unset.
     * @return list<array<string, mixed>>
     */
    private function formatMessages(array $messages, ?string $systemPrompt = null): array
    {
        return SglangProvider::placeSystemRows(array_map(function (Message $msg) {
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
        }, array_values($messages)), $systemPrompt);
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
                        ? (is_array($decoded = json_decode($tc['function']['arguments'], true)) ? $decoded : [])
                        : ($tc['function']['arguments'] ?? []),
                    // Audit A11: an undecodable payload still yields `[]`
                    // arguments, but the call now says so, and Runtime answers
                    // the model with the JSON error instead of running the tool.
                    'argumentsError' => ToolCall::argumentsErrorFor($tc['function']['arguments'] ?? null),
                    // Audit A23: replayed verbatim in history, so `{}` stays
                    // `{}` (see ToolCall::rawArguments()).
                    'rawArguments' => $tc['function']['arguments'] ?? null,
                ]),
                $message['tool_calls']
            );
        }

        [$reasoning, $content] = $this->extractReasoning($message);

        // P4.S2: one parsed Usage is the source of every usage number leaving
        // this method; tokensUsed/costUsd keep their exact prior expressions
        // for legitimate wire values (a negative count clamps to 0 per Usage's
        // doctrine, as in SglangProvider::parseResponse()). The E17 fold hands
        // the parsed object itself to the carrier so the split outlives this
        // parse instead of collapsing into the two projections below.
        $usage = $this->parseUsage(is_array($data['usage'] ?? null) ? $data['usage'] : []);

        // E707 (round 81): a `finish_reason` naming the output ceiling means
        // the text stopped mid-thought at the server's budget; arm the
        // carrier so the fold above the provider can surface it once.
        return new CompleteResponse(
            content: $content,
            reasoning: $reasoning,
            toolCalls: $toolCalls,
            tokensUsed: $usage->totalTokens,
            costUsd: $usage->costUsd,
            usage: $usage,
            truncated: in_array($choice['finish_reason'] ?? null, self::TRUNCATED_FINISH_REASONS, true),
        );
    }

    /**
     * Parses the OpenAI-compatible `usage` object into the provider-counted
     * token BUCKETS (prompt_plan.md P4.S2). This provider speaks the same
     * family as {@see SglangProvider::parseUsage()}, whose docblock carries
     * the live-deployment evidence (2026-09-02 skynet2 probes) and the three
     * decisions it pins, all of which apply here with the same force:
     *
     * - `prompt_tokens_details.cached_tokens` is read ONLY when the upstream
     *   server populates it (the key's shape is this repo's own vendored
     *   OpenAI DTO; the probed deployment sends the key with a null value and
     *   reports no cache fields at all). Absent-or-null decodes to UNREPORTED,
     *   never to a fabricated zero.
     * - `inputTokens` is `prompt_tokens - cached_tokens` when both are
     *   reported — this family's `prompt_tokens` COUNTS the cached prefix,
     *   and Usage's `inputTokens` means "what follows the last cache
     *   breakpoint".
     * - the protocol has no cache-creation field; `cacheCreationTokens` is
     *   null on every parse and nothing is invented for it.
     *
     * What differs by deployment is the point: CustomProvider fronts any
     * self-hosted OpenAI-compatible server (vLLM and SGLang both populate
     * `prompt_tokens_details.cached_tokens` when their prefix caching is
     * launched with reporting on), so parse-if-reported is the honest
     * behavior, and the as-received no-cache shape is pinned by test.
     *
     * Stream arms: like Sglang, this provider never REQUESTS streamed usage
     * (no `stream_options` on the wire, and the `choices[0].delta` gate in
     * {@see completeStream()} drops the zero-choice terminal chunk such a
     * request would produce), so NO usage object reaches any parse on the
     * stream path today — recorded, not papered over; wiring it is a reported
     * follow-up outside this seam.
     *
     * @param array<string, mixed> $usage the decoded `usage` object; non-array
     *                                    arrives as `[]` via the call site,
     *                                    keeping the tolerance of the inline
     *                                    `?? 0` this replaces.
     */
    public function parseUsage(array $usage): Usage
    {
        $prompt = self::usageInt($usage['prompt_tokens'] ?? null);
        $cached = null;
        $details = $usage['prompt_tokens_details'] ?? null;

        if (is_array($details)) {
            $cached = self::usageInt($details['cached_tokens'] ?? null);
        }

        $completion = self::usageInt($usage['completion_tokens'] ?? null);
        [$cost, $unpriced] = $this->usageCost($prompt ?? 0, $cached ?? 0, $completion ?? 0);

        return Usage::new(
            // The exact expression this replaces: absent-or-null total is 0.
            self::usageInt($usage['total_tokens'] ?? null) ?? 0,
            $cost,
            $prompt !== null && $cached !== null ? max(0, $prompt - $cached) : $prompt,
            $completion,
            $cached,
            null, // no cache-creation field exists on this protocol - never invented
            unpricedModel: $unpriced,
        );
    }

    /**
     * Roadmap 5.13a: the dollar figure for one usage document, priced at this
     * provider's model through {@see costPer1kTokens()} — 0.0 for a model
     * nothing prices, which is the pre-5.13a answer for every model.
     *
     * The formula is {@see OpenAIProvider}'s: the wire's prompt count
     * INCLUDES the cached prefix, so cached tokens bill at the cache-read
     * rate (the operator's `cached` key, else the database's, else the input
     * rate — never an invented discount) and the rest at the input rate.
     * Cached is capped at the prompt. A rate the operator declared and broke
     * makes the whole bill unknown: 0.0 plus the model's name as the
     * {@see Usage::$unpricedModel} signal.
     *
     * @return array{0: float, 1: ?string}
     */
    private function usageCost(int $prompt, int $cached, int $completion): array
    {
        $input = $this->costPer1kTokens($this->model, 'input');
        $output = $this->costPer1kTokens($this->model, 'output');
        if ($input === null || $output === null) {
            return [0.0, $this->model];
        }

        $cached = max(0, min($cached, $prompt));
        $cachedRate = $input;
        if ($cached > 0) {
            $entry = $this->modelPrices[$this->model] ?? null;
            $cachedRate = is_array($entry) && array_key_exists('cached', $entry)
                ? $this->costPer1kTokens($this->model, 'cached')
                : (array_key_exists($this->model, $this->modelPrices)
                    ? $input
                    : ($this->modelMetadata?->costPer1kTokens($this->model, 'cached') ?? $input));
            if ($cachedRate === null) {
                return [0.0, $this->model];
            }
        }

        return [(($prompt - $cached) * $input + $cached * $cachedRate + $completion * $output) / 1000, null];
    }

    /**
     * One usage number as reported: absent OR JSON null stays `null`
     * (unreported — an explicit null must not coerce to a measured zero);
     * anything numeric counts as its int. Non-numeric junk - strings,
     * booleans, arrays, objects - decodes to UNREPORTED, never a counted
     * zero, while numeric strings and floats count as their int (a float
     * count floors, tolerating a buggy provider exactly where the old
     * strict-typed int parameters would have crashed).
     */
    private static function usageInt(mixed $value): ?int
    {
        return $value === null || !is_numeric($value) ? null : (int) $value;
    }

    /**
     * Composes two independent per-chunk concerns: W1.A1 (§12 D2) tool-call
     * fragment reassembly via {@see reassembleStreamedToolCalls()}, and W1.A2
     * (§12 D3) reasoning/content splitting via {@see extractReasoning()}.
     * Kept as separate methods (rather than one inlined rewrite) so each
     * plan step's logic stays a single, independently reviewable unit - see
     * SglangProvider::parseChunk(), which mirrors this split byte-for-byte.
     *
     * @param array<int, array{id?: ?string, name?: ?string, arguments?: string}> $toolCallBuffer
     */
    private function parseChunk(array $data, array &$toolCallBuffer = []): CompleteResponse
    {
        // Audit 15a A4: `"delta": null` on a finish frame parses as empty.
        $delta = is_array($data['choices'][0]['delta'] ?? null) ? $data['choices'][0]['delta'] : [];
        $finishReason = $data['choices'][0]['finish_reason'] ?? null;

        // W1.A1 (§12 D2) fragment reassembly, shared with Sglang/OpenAI since
        // X-31a: see ReassemblesStreamedToolCalls::reassembleStreamedToolCalls().
        $toolCalls = $this->reassembleStreamedToolCalls($delta, $finishReason, $toolCallBuffer);

        // W1.A2 (§12 D3), applied per chunk here - see
        // SglangProvider::parseChunk()'s docblock for the same per-chunk
        // chunk-boundary caveat on Case 2 (<think> stripping), which applies
        // identically here.
        [$reasoning, $content] = $this->extractReasoning($delta);

        // E707 (round 81): this provider already decodes `finish_reason` for
        // tool-call assembly; the same local arms the carrier when the end
        // names the output ceiling, so the closing delta chunk carries the
        // truth and no extra frame is needed on that shape.
        return new CompleteResponse(
            content: $content,
            reasoning: $reasoning,
            toolCalls: $toolCalls,
            tokensUsed: 0,
            costUsd: 0.0,
            truncated: in_array($finishReason, self::TRUNCATED_FINISH_REASONS, true),
        );
    }

}
