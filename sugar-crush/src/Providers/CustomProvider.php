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
use SugarCraft\Crush\Providers\Concerns\SessionAffinity;
use SugarCraft\Crush\Tools\Tool;
use SugarCraft\Crush\Tools\ToolCall;
use SugarCraft\Crush\Providers\Concerns\ToolSchema;
use SugarCraft\Crush\Usage;

final readonly class CustomProvider implements ProviderInterface
{
    use ToolSchema;

    use ReasoningExtractor;

    use HttpClientDefaults;

    use SessionAffinity;

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
    ) {}

    public static function openAiCompatible(
        string $name,
        string $baseUrl,
        string $model,
        ?string $apiKey = null,
        bool $supportsStreaming = true,
        bool $supportsFunctionCalling = true,
        ?string $sessionAffinityId = null,
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

        // Only a non-blank assembled prompt earns a wire turn: an empty
        // system message would hand the backend an empty `system` role to
        // reconcile against the real history, so '' is treated like null.
        if ($request->systemPrompt !== null && $request->systemPrompt !== '') {
            $params['messages'] = array_merge(
                [['role' => 'system', 'content' => $request->systemPrompt]],
                $params['messages']
            );
        }

        try {
            // 'headers' here (not client defaults) so the affinity header also
            // rides injected clients - see SessionAffinity::sessionAffinityHeaders().
            // heartbeatOptions() is [] unless E493's caller supplied a
            // progress closure, so the spread is byte-neutral otherwise.
            $response = $this->httpClient->post('chat/completions', [
                'json' => $params,
                'headers' => $this->sessionAffinityHeaders(),
            ] + self::heartbeatOptions($request->onHeartbeat));

            $data = json_decode($response->getBody()->getContents(), true);
            return $this->parseResponse($data);
        } catch (GuzzleException $e) {
            return new CompleteResponse(
                content: '',
                isError: true,
                errorMessage: $e->getMessage(),
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
            // Billing fix (audit-crush-core finding 1), set next to `stream`
            // and NOT in the shared batch body — Sglang's law: the batch
            // request must stay byte-identical. OpenAI-compatible servers
            // that know the flag answer a final zero-choice usage frame;
            // servers that ignore unknown params simply never trigger the
            // capture below, which leaves the turn unreported — the same
            // honest null the batch path gives, never a fabricated count.
            'stream_options' => ['include_usage' => true],
            'extra_body' => ['separate_reasoning' => true],
        ];

        if ($request->tools !== null && $this->supportsFunctionCalling) {
            $params['tools'] = $this->formatTools($request->tools);
        }

        // Only a non-blank assembled prompt earns a wire turn: an empty
        // system message would hand the backend an empty `system` role to
        // reconcile against the real history, so '' is treated like null.
        if ($request->systemPrompt !== null && $request->systemPrompt !== '') {
            $params['messages'] = array_merge(
                [['role' => 'system', 'content' => $request->systemPrompt]],
                $params['messages']
            );
        }

        try {
            $response = $this->httpClient->post('chat/completions', [
                'json' => $params,
                'stream' => true,
                'headers' => $this->sessionAffinityHeaders(),
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
                                errorMessage: $streamError->getMessage(),
                                errorTransient: TransientFailure::isTransient($streamError),
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
                        $hasDelta = isset($data['choices'][0]['delta']);
                        if ($hasDelta) {
                            yield $this->parseChunk($data, $toolCallBuffer);
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
                errorMessage: $e->getMessage(),
                // See complete()'s catch: same reason, and this one can fire
                // after real content chunks have already been yielded, which is
                // what makes the retry decision at the consumer conditional
                // rather than automatic.
                errorTransient: TransientFailure::isTransient($e),
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
                'headers' => $this->sessionAffinityHeaders(),
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

        return Usage::new(
            // The exact expression this replaces: absent-or-null total is 0.
            self::usageInt($usage['total_tokens'] ?? null) ?? 0,
            0.0, // self-hosted, no cost - costPer1kTokens() is a real 0.0 here
            $prompt !== null && $cached !== null ? max(0, $prompt - $cached) : $prompt,
            self::usageInt($usage['completion_tokens'] ?? null),
            $cached,
            null, // no cache-creation field exists on this protocol - never invented
        );
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
