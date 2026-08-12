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
use SugarCraft\Crush\Providers\ToolCallParser\OpenAiArrayToolCallParser;
use SugarCraft\Crush\Providers\ToolCallParser\ToolCallParserInterface;
use SugarCraft\Crush\Tools\Tool;
use SugarCraft\Crush\Tools\ToolCall;
use SugarCraft\Crush\Providers\Concerns\ToolSchema;

final readonly class SglangProvider implements ProviderInterface
{
    use ToolSchema;

    use ReasoningExtractor;

    /**
     * The literal substring at the heart of the MiniMax-M2.x tool-call
     * truncation bug (crush_feat.md §12 D5): the model's own XML tool-call
     * envelope closes a parameter with this tag, so an argument *value* that
     * contains it terminates the value early inside the server-side parser.
     * Confirmed in both vLLM's parser and MiniMax's hosted API, i.e. it is a
     * protocol-level bug that no client can prevent - only detect.
     */
    private const XML_PARAM_CLOSE_TAG = '</parameter>';

    /**
     * Longest raw `arguments` payload echoed verbatim into a warning before
     * it is elided head+tail. The tail is what matters for a truncation
     * diagnosis (that is where the payload stops), so both ends are kept.
     */
    private const WARNING_EXCERPT_LIMIT = 240;

    /**
     * @param ToolCallParserInterface|null $toolCallParser W1.A6 (§12 D6): the
     *        client-side mirror of SGLang's own `--tool-call-parser` flag.
     *        Left null the provider uses {@see OpenAiArrayToolCallParser} over
     *        {@see argumentDecoder()}, which is the correct strategy for any
     *        server actually launched with that flag - including the confirmed
     *        live deployment. A deployment missing it wants
     *        {@see \SugarCraft\Crush\Providers\ToolCallParser\MinimaxXmlFallbackToolCallParser}
     *        instead, which {@see ProviderFactory::createSglang()} selects from
     *        the `toolCallParser` config key.
     */
    public function __construct(
        private string $baseUrl,
        private string $model,
        private ?string $apiKey,
        private Client $httpClient,
        private ?ToolCallParserInterface $toolCallParser = null,
    ) {}

    public static function openAiCompatible(
        string $baseUrl,
        string $model = 'MiniMax-M2.7',
        ?string $apiKey = null,
        ?ToolCallParserInterface $toolCallParser = null,
    ): self {
        $headers = [
            'Content-Type' => 'application/json',
        ];

        if ($apiKey !== null) {
            $headers['Authorization'] = 'Bearer ' . $apiKey;
        }

        $client = new Client([
            // Guzzle resolves a relative request URI against base_uri per
            // RFC 3986: an absolute-path request URI (leading '/') replaces
            // the whole base path instead of appending to it, silently
            // dropping a base_uri suffix like '/v1'. Trailing-slash the
            // base and use relative (no leading '/') request paths below so
            // '/v1' is preserved instead of producing a 404 at the bare host.
            'base_uri' => rtrim($baseUrl, '/') . '/',
            'headers' => $headers,
        ]);

        return new self($baseUrl, $model, $apiKey, $client, $toolCallParser);
    }

    /**
     * W1.A6 (§12 D6): the truncation-aware `function.arguments` decoder, handed
     * out so a parser built *outside* this class still reports the §12 D5
     * MiniMax `</parameter>` truncation instead of silently degrading to no
     * arguments.
     *
     * {@see ProviderFactory::createSglang()} has to construct the parser before
     * the provider exists, so it cannot borrow a bound instance method - hence
     * a static seam rather than an accessor on a built provider. The decoding
     * itself reads no instance state, so nothing is lost by exposing it.
     *
     * @return \Closure(mixed, string): array<string, mixed>
     */
    public static function argumentDecoder(): \Closure
    {
        return static fn (mixed $raw, string $toolName): array
            => self::decodeToolArguments($raw, $toolName);
    }

    public function name(): string
    {
        return 'sglang';
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
        return false;
    }

    /**
     * True because {@see buildParams()} forwards `CompleteRequest::$jsonSchema`
     * as `response_format.json_schema.schema`, which a live round-trip against
     * the skynet2 SGLang v0.5.16 / MiniMax-M2.7 deployment (2026-08-10)
     * confirmed actually binds output to the schema: the same prompt returns
     * free prose without `response_format` and schema-conforming JSON with it.
     */
    public function supportsJsonSchema(): bool
    {
        return true;
    }

    /**
     * W1.A6 (§12 D8): 196,608 tokens, not the 128,000 hardcoded here before.
     *
     * The figure is not a guess at "what MiniMax-M2.7 supports" - it is the
     * `--context-length 196608` the confirmed skynet2 SGLang launch command
     * actually pins (§12), i.e. the exact point the server starts rejecting or
     * evicting. It replaces a hardcoded 128,000 that was ~68k short.
     *
     * KNOWN GAP, disclosed rather than overstated: nothing in sugar-crush
     * reads `contextWindow()` today. A repo-wide search finds it only on the
     * provider implementations, {@see ProviderInterface} and provider unit
     * tests - `EngineBackend`, `App`, `Runtime` and `AgentManager` never call
     * it. The context-window-budget logic §12 D8 describes does not exist
     * yet, so nothing observable changes from this correction: it makes the
     * reported ceiling right ahead of the consumer rather than fixing a
     * truncation happening now.
     */
    public function contextWindow(): int
    {
        return 196_608;
    }

    public function costPer1kTokens(string $model, string $direction): float
    {
        // SGLANG models are typically self-hosted, low cost
        return 0.0;
    }

    public function complete(CompleteRequest $request): CompleteResponse
    {
        $params = $this->buildParams($request);

        try {
            $response = $this->httpClient->post('chat/completions', [
                'json' => $params,
            ]);

            $data = json_decode($response->getBody()->getContents(), true);

            return $this->parseResponse($data);
        } catch (GuzzleException $e) {
            throw new \RuntimeException('SGLANG request failed: ' . $e->getMessage(), 0, $e);
        }
    }

    public function completeStream(CompleteRequest $request): \Generator
    {
        $params = $this->buildParams($request);
        $params['stream'] = true;

        try {
            $response = $this->httpClient->post('chat/completions', [
                'json' => $params,
                'stream' => true,
            ]);

            $stream = $response->getBody();
            $buffer = '';

            // Accumulates delta.tool_calls[] fragments across chunks, keyed
            // by the OpenAI stream's per-call `index`. Threaded as a local
            // (not an instance property) because SglangProvider is a
            // `final readonly class` per this repo's immutable-value-object
            // convention - a readonly property can't be mutated chunk over
            // chunk, so the buffer lives for the lifetime of this generator
            // call only, exactly matching one completeStream() invocation.
            $toolCallBuffer = [];

            // GuzzleHttp\Psr7\Stream has no readLine() - it implements only
            // the plain PSR-7 StreamInterface. Buffer raw chunks and split on
            // "\n" ourselves (same approach as CustomProvider::completeStream()).
            while (!$stream->eof()) {
                $buffer .= $stream->read(8192);

                while (($newlinePos = strpos($buffer, "\n")) !== false) {
                    $line = trim(substr($buffer, 0, $newlinePos));
                    $buffer = substr($buffer, $newlinePos + 1);

                    if (str_starts_with($line, 'data: ')) {
                        $data = json_decode(substr($line, 6), true);
                        if ($data !== null && isset($data['choices'][0]['delta'])) {
                            yield $this->parseChunk($data, $toolCallBuffer);
                        }
                    }
                }
            }
        } catch (GuzzleException $e) {
            throw new \RuntimeException('SGLANG request failed: ' . $e->getMessage(), 0, $e);
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
     * W1.A3 (§12 D4): builds the full `/v1/chat/completions` body.
     *
     * Before this, the entire request surface was `model`, `messages`,
     * `temperature`, `max_tokens`, `tools` (+ `stream`), so none of SGLang's
     * sampling knobs or route-specific extras were reachable at all and
     * `CompleteRequest::$jsonSchema` was silently dropped on every call.
     *
     * Placement rationale - EVERYTHING is top level, including the three
     * SGLang extras. §12 D4 prescribes wrapping those in `extra_body`, but
     * `extra_body` is an OpenAI *Python SDK* client-side concept: the SDK
     * splices that dict into the top level before it ever hits the wire, so a
     * literal `{"extra_body": {...}}` body is not something SGLang parses.
     * Probed against the live skynet2 SGLang v0.5.16 deployment (2026-08-10):
     * a top-level `chat_template_kwargs: "NOT_A_DICT"` / `separate_reasoning:
     * "NOT_A_BOOL"` is rejected 400 by `ChatCompletionRequest`'s pydantic
     * model (proving both are real top-level fields), while the same garbage
     * nested under `extra_body` returns 200 - i.e. the nested form was being
     * dropped in silence. §12 D4's snippet is corrected in place there.
     *
     * `json_schema` is the one extra that has no top-level home at all: a
     * top-level `json_schema` is not on `ChatCompletionRequest` (a bogus
     * `json_schema: 12345` sails through 200) and does not constrain decoding.
     * The OpenAI-compatible route exposes constrained decoding only through
     * `response_format`, which was verified live to actually bind output to
     * the schema, so that is what the DTO field maps to.
     *
     * Optional knobs are only emitted when the caller actually set them -
     * sending a null/implicit value would override the server's launch-time
     * default rather than defer to it. `null` AND empty are both treated as
     * "defer to server": an empty `stop` list or empty schema is not a
     * meaningful instruction, it is an unset one.
     *
     * Shared by complete() and completeStream() so the two bodies cannot
     * drift apart (they already had byte-identical duplicated bodies), and
     * so §12 D7's RadixAttention prefix-cache stability depends on one
     * serialization path instead of two.
     *
     * @return array<string, mixed>
     */
    private function buildParams(CompleteRequest $request): array
    {
        $this->flagTruncationRiskInLatestToolResults($request->messages);

        $params = [
            'model' => $request->model,
            'messages' => $this->formatMessages($request->messages),
            'temperature' => $request->temperature ?? 0.7,
            'max_tokens' => $request->maxTokens ?? 4096,

            // Pin SGLang's reasoning-splitting behavior explicitly rather than
            // relying on its (currently true) default - see D3/D4: this is
            // what tells a properly-splitting parser (the deployed `minimax`
            // one) to populate `reasoning_content` at all. It's a no-op for
            // `minimax-append-think`, which is exactly why extractReasoning()'s
            // <think>-stripping fallback still matters regardless.
            'separate_reasoning' => true,
        ];

        foreach ([
            'top_p' => $request->topP,
            'top_k' => $request->topK,
            'min_p' => $request->minP,
            'repetition_penalty' => $request->repetitionPenalty,
            'stop' => $request->stop,
            'chat_template_kwargs' => $request->extraTemplateKwargs,
        ] as $key => $value) {
            // Strict comparisons throughout: `0` / `0.0` are meaningful
            // top_k/min_p values and must survive, unlike a falsy filter.
            if ($value !== null && $value !== [] && $value !== '') {
                $params[$key] = $value;
            }
        }

        if ($request->jsonSchema !== null && $request->jsonSchema !== '') {
            // `response_format` wants the schema as a decoded object, so a
            // caller holding pre-encoded JSON is decoded back here rather than
            // shipped as a string the server would reject. JSON_THROW_ON_ERROR
            // because the silent alternative is a `null` schema that disables
            // constrained decoding while the request still returns 200 - the
            // same invisible-failure class §12 D5 exists to eliminate.
            $schema = is_string($request->jsonSchema)
                ? json_decode($request->jsonSchema, true, 512, JSON_THROW_ON_ERROR)
                : $request->jsonSchema;

            // JSON_THROW_ON_ERROR only rejects *syntactically* broken JSON:
            // `'null'`, `'123'` and `'false'` all decode cleanly to scalars and
            // would ship `schema: null` / `schema: 123`, reproducing exactly the
            // 200-with-unconstrained-decoding failure the decode exists to
            // prevent. A JSON Schema is always an object, so a scalar is a
            // caller bug worth an immediate, loud failure.
            if (!is_array($schema)) {
                throw new \InvalidArgumentException(
                    'CompleteRequest::$jsonSchema must decode to a JSON object; got '
                    . get_debug_type($schema)
                );
            }

            // Emptiness is judged AFTER the decode so the two accepted shapes
            // agree: `[]` and its pre-encoded twin `'{}'` are the same caller
            // intent and must both mean "defer to the server", never one
            // silent no-op and one hard throw depending on who encoded it.
            if ($schema !== []) {
                $params['response_format'] = [
                    'type' => 'json_schema',
                    'json_schema' => ['name' => 'response', 'schema' => $schema],
                ];
            }
        }

        if ($request->tools !== null) {
            $params['tools'] = $this->formatTools($request->tools);
        }

        return $params;
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

    /**
     * W1.A6 (§12 D6): tool-call decoding now runs through the injected
     * {@see ToolCallParserInterface} instead of the `tool_calls[]` walk that
     * used to be inlined byte-identically here, in `CustomProvider` and in
     * `OpenAIProvider`. Behaviour for a server-parsed response is unchanged -
     * the default strategy IS that extracted walk - and
     * {@see ProviderFactory::createSglang()} now picks the strategy from the
     * `toolCallParser` config key.
     *
     * KNOWN GAP, still open: this is the batch `complete()` path only.
     * {@see supportsStreaming()} returns true, so the production consumers
     * ({@see \SugarCraft\Crush\Runtime}, {@see \SugarCraft\Crush\Agents\AgentManager})
     * route this provider through `completeStream()` instead, and that path's
     * `parseChunk()`/`resolveStreamedToolCalls()` reassembly builds its own
     * tool calls without consulting the injected parser. Switching
     * `toolCallParser` therefore does not yet affect the live streaming chat
     * loop; threading it through belongs to §12 D2, not D6, and is
     * unscheduled.
     */
    private function parseResponse(array $data): CompleteResponse
    {
        $choice = $data['choices'][0] ?? [];
        $message = $choice['message'] ?? [];

        $toolCalls = $this->resolvedToolCallParser()->parse($message);

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
     * The configured parser, or the default OpenAI-array strategy.
     *
     * Named distinctly from the `$toolCallParser` property on purpose: a
     * resolver called `toolCallParser()` would differ from the nullable raw
     * property by only a pair of parentheses, so a later reader adding a
     * second read could silently reintroduce the null case this method exists
     * to remove.
     *
     * Rebuilt per call rather than memoised because this is a
     * `final readonly class` - a lazily-populated property is not expressible
     * here - and the default is a two-object allocation against a network
     * round-trip, so the cost is noise.
     */
    private function resolvedToolCallParser(): ToolCallParserInterface
    {
        return $this->toolCallParser ?? OpenAiArrayToolCallParser::new(self::argumentDecoder());
    }

    /**
     * Composes two independent per-chunk concerns: W1.A1 (§12 D2) tool-call
     * fragment reassembly via {@see resolveStreamedToolCalls()}, and W1.A2
     * (§12 D3) reasoning/content splitting via {@see extractReasoning()}.
     * Kept as separate methods (rather than one inlined rewrite) so each
     * plan step's logic stays a single, independently reviewable unit.
     *
     * @param array<int, array{id?: ?string, name?: ?string, arguments?: string}> $toolCallBuffer
     */
    private function parseChunk(array $data, array &$toolCallBuffer = []): CompleteResponse
    {
        $delta = $data['choices'][0]['delta'] ?? [];
        $finishReason = $data['choices'][0]['finish_reason'] ?? null;

        $toolCalls = $this->resolveStreamedToolCalls($delta, $finishReason, $toolCallBuffer);

        // W1.A2 (§12 D3), applied per chunk here: Case 1 (delta.reasoning_content
        // present) is unambiguous per chunk. Case 2 (raw <think> markup inline in
        // content, e.g. under minimax-append-think) is a known, accepted
        // limitation - a </think> closer straddling a chunk boundary won't be
        // caught until the fragment containing it arrives whole. Catching that
        // would require buffering the full assembled message before splitting,
        // which belongs where content is reassembled
        // ({@see \SugarCraft\Crush\Runtime::runStreaming()}), not here.
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
     * valid JSON once the call is complete (SGLang's `/v1/chat/completions`
     * SSE docs). Fragments accumulate into `$toolCallBuffer` (by reference,
     * one buffer per completeStream() call - see the call site) until
     * `finish_reason === 'tool_calls'`, at which point the buffered calls
     * are assembled into ToolCall objects and the buffer is drained.
     *
     * Previously this always returned `toolCalls: null`, so a delta chunk
     * carrying only `tool_calls` (no `content`) had its fragments read then
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
                // W1.A4 (§12 D5): a streamed call is the likelier truncation
                // victim of the two - the fragments were concatenated here, so
                // a payload that stops mid-value is exactly what the bug looks
                // like from the client side.
                'arguments' => self::decodeToolArguments(
                    $tc['arguments'] ?? '',
                    (string) ($tc['name'] ?? ''),
                ),
            ]),
            $toolCallBuffer
        );
        $toolCallBuffer = [];

        return $toolCalls;
    }

    /**
     * W1.A4 (§12 D5): decodes a tool call's `arguments` payload, replacing the
     * `json_decode(...) ?? []` that both parse paths used to share.
     *
     * That idiom collapses three very different outcomes - "the model sent no
     * arguments", "the model sent `null`", and "the payload was corrupted in
     * transit" - into one silent empty array, so a tool call truncated by the
     * MiniMax XML-delimiter bug reached the tool registry looking exactly like
     * a well-formed zero-argument call and simply did the wrong thing. The
     * decode result is unchanged; what is new is that the corrupted case now
     * leaves a distinguishable trace in the error log.
     *
     * @param  mixed  $raw      the wire value of `function.arguments` - a JSON
     *                          string on every real SGLang response, but
     *                          tolerated as an already-decoded array because
     *                          some OpenAI-compatible servers pre-decode it
     * @param  string $toolName names the offending call in the warning; the
     *                          arguments alone rarely identify it
     * Static because it reads no instance state and {@see argumentDecoder()}
     * must hand it to a parser built before any provider instance exists.
     *
     * @return array<mixed>
     */
    private static function decodeToolArguments(mixed $raw, string $toolName): array
    {
        if (is_array($raw)) {
            return $raw;
        }

        // An absent or blank payload is how a genuine zero-argument call
        // arrives, not a corruption signal - stay quiet.
        if (!is_string($raw) || trim($raw) === '') {
            return [];
        }

        $decoded = json_decode($raw, true);

        if (json_last_error() === JSON_ERROR_NONE) {
            if (is_array($decoded)) {
                return $decoded;
            }

            // A syntactically valid but non-object payload (`null`, `12`,
            // `"text"`) still can't be tool arguments; ToolCall::fromArray()
            // types them `array`, so a scalar used to blow up loudly as a
            // TypeError. Degrading to `[]` keeps the turn alive, but it must
            // not be the silent downgrade CONTRIBUTING.md forbids - the call
            // is about to run with no arguments at all.
            error_log(sprintf(
                'SglangProvider: tool call "%s" arguments decoded to %s, not an object; '
                . 'defaulting to no arguments. Raw payload: %s',
                $toolName,
                get_debug_type($decoded),
                self::excerpt($raw),
            ));

            return [];
        }

        error_log(self::malformedArgumentsWarning($toolName, $raw));

        return [];
    }

    /**
     * W1.A4 (§12 D5): classifies an `arguments` payload that failed to decode.
     *
     * A payload that stops without ever closing its outermost `{`/`[` is the
     * signature of the MiniMax-M2.x truncation bug - the parser cut the value
     * short, so the JSON simply runs out - and gets a distinguishable
     * "possible MiniMax XML-delimiter truncation" warning. A payload that is
     * malformed yet structurally closed is some other bug and is reported as
     * plain invalid JSON, so the two never get confused in a log trawl.
     */
    private static function malformedArgumentsWarning(string $toolName, string $raw): string
    {
        $trimmed = rtrim($raw);
        $structurallyClosed = str_ends_with($trimmed, '}') || str_ends_with($trimmed, ']');

        if ($structurallyClosed) {
            return sprintf(
                'SglangProvider: tool call "%s" arguments are not valid JSON (%s); '
                . 'defaulting to no arguments. Raw payload: %s',
                $toolName,
                json_last_error_msg(),
                self::excerpt($raw),
            );
        }

        return sprintf(
            'SglangProvider: possible MiniMax XML-delimiter truncation in tool call "%s" - '
            . 'arguments are not valid JSON (%s) and end mid-value without a closing structure%s. '
            . 'This is the known MiniMax-M2.x "%s" tool-call bug (server-side, not fixable '
            . 'client-side); the call is being executed with no arguments. Raw payload: %s',
            $toolName,
            json_last_error_msg(),
            str_contains($raw, self::XML_PARAM_CLOSE_TAG)
                ? sprintf(', and contain the literal "%s"', self::XML_PARAM_CLOSE_TAG)
                : '',
            self::XML_PARAM_CLOSE_TAG,
            self::excerpt($raw),
        );
    }

    /**
     * W1.A4 (§12 D5) part (1): warns when the tool result about to be fed back
     * to the model carries the literal `</parameter>`.
     *
     * File reads, `.tape`/HTML/XML/PHP bodies and Edit/Write echoes are the
     * realistic carriers. Nothing here can prevent the bug - it lives in the
     * server-side parser - but a conversation that has just absorbed that
     * substring is measurably likelier to produce a truncated follow-up call,
     * and saying so up front turns a later mystery into a predicted one.
     *
     * Deliberately only the TRAILING RUN of tool results, not the whole
     * history: the full history is re-serialized on every turn, so flagging
     * every match would re-log the same result for the rest of the session.
     * That trailing run is exactly the batch that just arrived - a turn with
     * n tool calls appends n ToolResultMessages in one go
     * ({@see \SugarCraft\Crush\Backend\EngineBackend::complete()} splats
     * `...$toolResults` onto the history), so scanning only the single last
     * message would miss n-1 of them, and a multi-tool coding turn is
     * precisely where a risky file body shows up.
     *
     * @param array<mixed> $messages
     */
    private function flagTruncationRiskInLatestToolResults(array $messages): void
    {
        $batch = [];
        foreach (array_reverse($messages) as $message) {
            if (!$message instanceof ToolResultMessage) {
                break;
            }
            $batch[] = $message;
        }

        // Restored to arrival order so the log reads in the same order the
        // model will see the results.
        foreach (array_reverse($batch) as $result) {
            $occurrences = substr_count($result->content(), self::XML_PARAM_CLOSE_TAG);
            if ($occurrences === 0) {
                continue;
            }

            error_log(sprintf(
                'SglangProvider: tool result "%s" contains the literal "%s" (%d occurrence(s)) - '
                . 'MiniMax-M2.x truncates tool-call arguments containing that substring, so any '
                . 'follow-up call echoing this content (Edit/Write bodies, XML/HTML/PHP/.tape '
                . 'content) is at elevated risk of silent truncation.',
                $result->toolCallId(),
                self::XML_PARAM_CLOSE_TAG,
                $occurrences,
            ));
        }
    }

    /**
     * Elides a long payload head+tail rather than head-only: the tail is where
     * a truncated payload stops, which is the whole diagnostic signal here.
     */
    private static function excerpt(string $raw): string
    {
        if (strlen($raw) <= self::WARNING_EXCERPT_LIMIT) {
            return $raw;
        }

        $half = intdiv(self::WARNING_EXCERPT_LIMIT, 2);

        return substr($raw, 0, $half) . ' [...] ' . substr($raw, -$half);
    }
}
