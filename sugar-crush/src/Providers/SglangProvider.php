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
use SugarCraft\Crush\Providers\ToolCallParser\OpenAiArrayToolCallParser;
use SugarCraft\Crush\Providers\ToolCallParser\ToolCallParserInterface;
use SugarCraft\Crush\Tools\Tool;
use SugarCraft\Crush\Tools\ToolCall;
use SugarCraft\Crush\Providers\Concerns\ToolSchema;

final readonly class SglangProvider implements ProviderInterface
{
    use ToolSchema;

    use ReasoningExtractor;

    use HttpClientDefaults;

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
     * The model id the confirmed skynet2 deployment serves as of 2026-08-20,
     * read from its own `GET /v1/models` (`data[0].id`). It replaced
     * `MiniMax-M2.7`, which is GONE from that server - every request naming
     * the old id now 404s on the model name - so this is the default
     * {@see openAiCompatible()} hands out when a caller names no model.
     *
     * DOMAIN: this is a DEFAULT, not a restriction. MiniMax-M2.x remains fully
     * supported; naming it explicitly (config `model`, `$SUGARCRUSH_MODEL`, or
     * this factory's `$model` argument) selects every MiniMax-specific
     * behaviour in this class unchanged - see {@see XML_PARAM_CLOSE_TAG},
     * {@see malformedArgumentsWarning()} and
     * {@see \SugarCraft\Crush\Providers\ToolCallParser\MinimaxXmlFallbackToolCallParser}.
     */
    public const DEFAULT_MODEL = 'deepseek-ai/DeepSeek-V4-Flash-0731';

    /**
     * Lowercased substring that identifies the DeepSeek-V4 family in a model
     * id, and therefore the ONE model family whose card-prescribed sampling
     * this class substitutes for its historical defaults.
     *
     * A family token rather than the exact {@see DEFAULT_MODEL} id because the
     * card's sampling advice is stated for DeepSeek-V4-Flash as a model, and
     * the deployed id carries an org prefix and a `-0731` date suffix that a
     * redeploy will change without changing the advice. Deliberately NOT the
     * broader `deepseek`: DeepSeek-V3 and R1 publish DIFFERENT recommended
     * temperatures, so matching the whole vendor would apply V4's numbers to
     * models they were never measured on.
     *
     * WHERE THE LINE ACTUALLY FALLS, measured 2026-08-20 by driving
     * {@see buildParams()} over a list of ids rather than reasoned about,
     * because the paragraph above states a per-GENERATION principle and a
     * substring cannot honour it exactly:
     *
     * - IN, as intended: `deepseek-ai/DeepSeek-V4-Flash-0731`.
     * - IN, beyond what any card was measured on: `DeepSeek-V4.5`,
     *   `deepseek-ai/DeepSeek-V4.1-Flash`, even `deepseek-v40`. This is the
     *   OVER-MATCH and it is accepted, not overlooked. The alternative is a
     *   version parser that would also have to guess, and the two failure
     *   modes are not symmetric: a V4.x point release getting V4-Flash's
     *   sampling is a wrong number on a probably-similar model, whereas a MISS
     *   costs `reasoning_effort` - measured on this deployment to mean the
     *   model's thinking is written into `content` instead of
     *   `reasoning_content`, silently, with nothing logged
     *   ({@see defaultReasoningEffort()}). Erring toward the match is
     *   deliberate. A future V4.x whose card publishes different numbers is the
     *   point to replace the token, and the boundary is pinned by test so that
     *   change cannot be silent.
     * - OUT, as intended: `deepseek-v3`, `deepseek-r1`, `MiniMax-M2.7`.
     * - OUT, and this is the UNDER-MATCH worth knowing about: any id that does
     *   not spell the family out. An SGLang server launched with
     *   `--served-model-name default` (or `local-model`, `dsv4`, `flash`,
     *   `deepseek_v4` - all measured) reports that alias from `/v1/models`, and
     *   an operator who copies it into `model` gets 0.7, no `top_p`, no
     *   `reasoning_effort` and a 196,608 context window while actually talking
     *   to DeepSeek-V4. There is no signal here to detect that from - the id is
     *   all this class is given - so the mitigation is documentation: the
     *   README tells the operator to configure the REAL model id. A one-shot
     *   log on the non-DeepSeek arm was considered and declined, because it
     *   cannot tell an aliased V4 from a genuine MiniMax deployment and would
     *   fire on every legitimate MiniMax run.
     */
    private const DEEPSEEK_V4_FAMILY_TOKEN = 'deepseek-v4';

    /**
     * DeepSeek-V4-Flash's card prescribes `temperature = 1.0` unconditionally.
     * Applied ONLY to that family; see {@see LEGACY_DEFAULT_TEMPERATURE}.
     */
    private const DEEPSEEK_V4_TEMPERATURE = 1.0;

    /**
     * DeepSeek-V4-Flash's card gives `top_p = 0.95` for AGENTIC scenarios and
     * `top_p = 1.0` otherwise. "Agentic" is the card's word, not a measurable
     * one, so {@see defaultTopP()} pins it to something on the request:
     * whether any `tools` were offered. See that method for the argument.
     */
    private const DEEPSEEK_V4_TOP_P_AGENTIC = 0.95;

    private const DEEPSEEK_V4_TOP_P_NON_AGENTIC = 1.0;

    /**
     * The `reasoning_effort` this class sends for the DeepSeek-V4 family when
     * neither the request nor the provider config names one. `max` is the
     * user's explicit instruction for this model, and it is also the level the
     * card's own recommendation set (`low`/`high`/`max`) tops out at.
     */
    private const DEEPSEEK_V4_REASONING_EFFORT = 'max';

    /**
     * The largest input the deployed server will accept for
     * {@see DEFAULT_MODEL}: `max_req_input_len` from its own `/server_info`,
     * read 2026-08-20.
     *
     * WHICH OF TWO NEARLY-IDENTICAL FIGURES THIS IS, because the server
     * publishes both and they differ by six tokens. `GET /v1/models` reports
     * `max_model_len` **1048576** - the model's total window, input plus
     * generated output. `/server_info` reports `max_req_input_len`
     * **1048570** - the ceiling the scheduler actually enforces on a single
     * request's input, and the one that returns an error when exceeded. This
     * constant is the SECOND, for two reasons. It is the limit that can
     * actually reject a request, and {@see ProviderInterface::contextWindow()}
     * states that erring large is the harmful direction: every context tier is
     * a percentage of this number, so overshooting switches the reminder, the
     * automatic compaction and the blocking refusal off rather than firing
     * them early. Six tokens will never decide a compaction, but the two
     * fields will diverge further on a differently-configured deployment, and
     * then the distinction is the whole answer.
     *
     * Note `/server_info` also reports `context_length: null` - this
     * deployment was never launched with an explicit `--context-length`, so
     * the window comes from the model config and no launch command records it.
     * Any doc in this repo that cites a `--context-length` flag for the
     * DeepSeek deployment is describing the MiniMax one it replaced.
     *
     * The history, because it is the argument for re-reading rather than
     * trusting: this slot held **393216** on 2026-08-20, written that day, and
     * was already wrong by the end of it. Then it briefly held 1048576, from
     * `max_model_len`, before `/server_info` showed that was the wrong field.
     *
     * A TRANSCRIBED CONSTANT, NOT A LIVE READ, and the distinction has to be
     * stated because this provider does talk to that endpoint's server and
     * could be misread as reading it. Nothing here fetches `/v1/models`:
     * {@see contextWindow()} is called from render-path code
     * ({@see \SugarCraft\Crush\Chat}'s four context tiers, recomputed per
     * frame), so a synchronous HTTP round trip in it would block the TUI on
     * every redraw. The cost of transcribing is that this figure decays exactly
     * as the 128,000 it replaced did - a redeploy under a different
     * `--context-length` makes it wrong with no local symptom. That is not a
     * hypothetical: it is what happened to the 393216 this line used to hold,
     * within a day of it being written, and nothing in this codebase noticed.
     * Treat the date above as part of the value. Re-verify BOTH endpoints -
     * `/v1/models` alone would have left this constant six tokens high and,
     * more importantly, reading the wrong field. Re-verify with
     * `curl -s https://skynet2.interserver.net/v1/models`, whose
     * `data[0].max_model_len` is this number.
     *
     * Over five times the MiniMax figure below, which is why
     * {@see contextWindow()} had to become model-aware: answering 196,608 for
     * this model would now put every one of
     * {@see \SugarCraft\Crush\Chat}'s four context tiers at under a fifth of
     * the real budget. While this constant was 393216 the same mistake cost
     * half, so the penalty for confusing the two grew when the deployment
     * grew.
     */
    private const DEEPSEEK_V4_CONTEXT_WINDOW = 1_048_570;

    /**
     * The context window this class reports for anything that is NOT the
     * DeepSeek-V4 family - i.e. the MiniMax-M2.7 figure it has always
     * reported, kept because that deployment's `--context-length` was exactly
     * this (§12 D8) and because inventing a different fallback would retune a
     * model nobody asked us to retune.
     *
     * DOMAIN: this is a MiniMax-shaped number serving as the fallback for
     * every non-DeepSeek-V4 model, which is a guess for any third model. 0
     * ("unknown", per {@see ProviderInterface::contextWindow()}) would be the
     * honest answer for a stranger, but it would also newly disable all four
     * context tiers on any MiniMax deployment reaching this arm, so the
     * pre-existing behaviour is preserved rather than improved here.
     */
    private const LEGACY_DEFAULT_CONTEXT_WINDOW = 196_608;

    /**
     * The `temperature` this class has sent since it existed, for any model
     * outside the DeepSeek-V4 family. Kept as the non-DeepSeek default
     * DELIBERATELY: DeepSeek-V4's card says 1.0, and applying that number
     * globally would silently retune MiniMax, whose sampling nobody measured.
     */
    private const LEGACY_DEFAULT_TEMPERATURE = 0.7;

    /**
     * The `reasoning_effort` level names the deployed server accepts.
     *
     * Measured, not read off a card: POSTing `reasoning_effort: "bogus"` to
     * skynet2 on 2026-08-20 returns `{"object":"error", ... code:400}` whose
     * message carries the server's own pydantic literal set,
     * `literal['none','minimal','low','medium','high','xhigh','max']`, plus a
     * second `constrained-float` alternative. DeepSeek-V4-Flash's card names
     * only `low`/`high`/`max`; all seven names were then confirmed to return
     * 200, so the card is a recommendation and this is the validator.
     */
    private const REASONING_EFFORT_LEVELS = [
        'none',
        'minimal',
        'low',
        'medium',
        'high',
        'xhigh',
        'max',
    ];

    /**
     * @param ToolCallParserInterface|null $toolCallParser W1.A6 (§12 D6): the
     *        client-side mirror of SGLang's own `--tool-call-parser` flag.
     *        Left null the provider uses {@see OpenAiArrayToolCallParser} over
     *        {@see argumentDecoder()}, which is the correct strategy for any
     *        server actually launched with that flag - including the confirmed
     *        live deployment, RE-MEASURED 2026-08-20 after it was switched to
     *        {@see DEFAULT_MODEL}: that model returns structured OpenAI
     *        `tool_calls` both non-streaming (`finish_reason: "tool_calls"`,
     *        `function.arguments` a JSON string) and streaming (fragments keyed
     *        by `index`, two parallel calls at 0 and 1), so no new parser class
     *        is needed for it. Worth stating because the DeepSeek-V4-Flash card
     *        ships no Jinja chat template and documents no `--tool-call-parser`,
     *        which would predict raw-text tool calls - the DEPLOYMENT
     *        contradicts the card, and the deployment is what this code talks
     *        to. A deployment missing it wants
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
        /**
         * Deployment-wide `reasoning_effort` default, from the `sglang`
         * provider block's optional `reasoningEffort` key
         * ({@see ProviderFactory::createSglang()}).
         *
         * Sits BETWEEN a per-request value and the model-derived one - see
         * {@see resolveReasoningEffort()} for the full precedence and why the
         * model default exists at all. Validated at construction, not at send
         * time, so a typo in a config file fails when the provider is built
         * rather than on the first completion.
         */
        private string|float|null $reasoningEffort = null,
    ) {
        if ($this->reasoningEffort !== null) {
            self::validatedReasoningEffort($this->reasoningEffort, 'provider config');
        }
    }

    public static function openAiCompatible(
        string $baseUrl,
        string $model = self::DEFAULT_MODEL,
        ?string $apiKey = null,
        ?ToolCallParserInterface $toolCallParser = null,
        string|float|null $reasoningEffort = null,
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
            // Guzzle resolves a relative request URI against base_uri per
            // RFC 3986: an absolute-path request URI (leading '/') replaces
            // the whole base path instead of appending to it, silently
            // dropping a base_uri suffix like '/v1'. Trailing-slash the
            // base and use relative (no leading '/') request paths below so
            // '/v1' is preserved instead of producing a 404 at the bare host.
            'base_uri' => rtrim($baseUrl, '/') . '/',
            'headers' => $headers,
        ]);

        return new self($baseUrl, $model, $apiKey, $client, $toolCallParser, $reasoningEffort);
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
     *
     * Re-verified on the SAME endpoint after it was switched to
     * `deepseek-ai/DeepSeek-V4-Flash-0731` (2026-08-20): "Describe a cat." with
     * a one-integer-property schema returned exactly `{"legs": 4}`. Recorded as
     * a SECOND measurement rather than an edit to the first, because the two
     * dates were two different models and one `true` covering both is a claim
     * about the ROUTE - the OpenAI-compatible `response_format` surface - not
     * about either model.
     */
    public function supportsJsonSchema(): bool
    {
        return true;
    }

    /**
     * W1.A6 (§12 D8), now MODEL-AWARE - and it had to become so, because the
     * single figure it used to return was measured on a model this server no
     * longer runs.
     *
     * TWO figures, each with its own domain:
     *
     * - {@see DEEPSEEK_V4_CONTEXT_WINDOW} = 1,048,570 for the DeepSeek-V4
     *   family. Not a guess and not from a card: it is `max_req_input_len` in
     *   the deployed server's own `/server_info` response, read from skynet2
     *   on 2026-08-20 for `deepseek-ai/DeepSeek-V4-Flash-0731`.
     *
     *   THE FIELD IS THE LOAD-BEARING HALF, not the number. `/server_info`'s
     *   `max_req_input_len` (1048570) is the ceiling the scheduler enforces on
     *   ONE REQUEST'S INPUT - the only one of the two published figures that
     *   returns an error - whereas `GET /v1/models`'s `max_model_len`
     *   (1048576) is the model's TOTAL window, input plus generated output.
     *   This method is the denominator of every context tier and
     *   {@see ProviderInterface::contextWindow()} states that erring LARGE is
     *   the harmful direction, so the enforced input limit is the right
     *   domain. {@see DEEPSEEK_V4_CONTEXT_WINDOW}'s own docblock is the long
     *   form of this argument; this bullet previously contradicted it by
     *   naming both the wrong value (393,216, which the slot held earlier on
     *   the same day) and the wrong field.
     * - {@see LEGACY_DEFAULT_CONTEXT_WINDOW} = 196,608 for everything else.
     *   That is the `--context-length 196608` the MiniMax-M2.7 skynet2 launch
     *   command pinned (§12), which is what this method returned
     *   unconditionally before. It is retained EXACTLY, so a MiniMax
     *   deployment's tiers do not move. That flag is a fact about the MiniMax
     *   deployment ONLY and does not carry across: `/server_info` reports
     *   `context_length: null` for the DeepSeek one, which was never launched
     *   with `--context-length` at all.
     *
     * Judged on `$this->model` - the CONFIGURED model - because this method is
     * handed no request. {@see buildParams()}'s sampling defaults judge on
     * `$request->model` instead, since that is the id the request is addressed
     * to. The two agree whenever the app's model and the provider's model are
     * the same string, which is the normal case; a caller who deliberately
     * completes against a second model on one provider gets that model's
     * sampling and the configured model's window, and that mismatch predates
     * this method being model-aware at all.
     *
     * Read, not decorative (crush_code.md Phase 5 item 4):
     * {@see \SugarCraft\Crush\Backend\EngineBackend} exposes it through
     * {@see \SugarCraft\Crush\Backend\ReportsContextWindow}, and
     * {@see \SugarCraft\Crush\Chat} makes it the budget its four context
     * tiers are percentages of - the 70% reminder, 85% automatic compaction,
     * 95% blocking refusal and the idle-compaction prompt. On the DeepSeek-V4
     * arm those fire at ~733,999 / ~891,284 / ~996,141 estimated tokens; on
     * the legacy arm at ~137,625 / ~167,116 / ~186,777, unchanged.
     *
     * Those three DeepSeek figures are 70/85/95% of 1,048,570 and of nothing
     * else. They are restated here only because they were last written as
     * ~275,251 / ~334,233 / ~373,555 - the same percentages of the superseded
     * 393,216 - and a derived figure left behind after its input moves is this
     * project's signature defect. Recompute them whenever the constant moves.
     * {@see \SugarCraft\Crush\Runtime::shouldPromptIdleCompaction()} reads
     * it too.
     */
    public function contextWindow(): int
    {
        return self::isDeepSeekV4($this->model)
            ? self::DEEPSEEK_V4_CONTEXT_WINDOW
            : self::LEGACY_DEFAULT_CONTEXT_WINDOW;
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

            // The REASSEMBLED content of this one response, and the flag that
            // says the structured path already produced tool calls. Both exist
            // solely for {@see recoverTextualToolCalls()} below; see its
            // docblock for why the seam is here and not in parseChunk().
            // Locals for the same reason $toolCallBuffer is one: this is a
            // `final readonly class`, so per-response state cannot live on a
            // property, and their lifetime is exactly one generator call.
            //
            // MEMORY COST OF THE REASSEMBLY, STATED BECAUSE IT IS A BEHAVIOUR
            // CHANGE: before this seam existed, completeStream() held no copy
            // of the response text at all. It now holds ONE, so peak usage
            // grows by roughly the size of the response - measured at +8.9 MB
            // on a synthetic 8 MB response, chunk count unchanged. That
            // headline figure is an upper bound well past what this deployment
            // can actually produce: 8 MB of text is on the order of 2M tokens,
            // and the cost scales linearly, so an ordinary 500 KB completion
            // costs about 500 KB.
            //
            // It is NOT gated on whether a text-scanning parser is armed, and
            // that is a deliberate decline rather than an oversight. Asking
            // would mean widening {@see ToolCallParserInterface} - a shipped
            // strategy seam with three implementations - with a capability
            // method, or type-switching on the concrete parser, which defeats
            // the point of the seam. One string copy is not worth either.
            $assembledContent = '';
            $sawStructuredToolCalls = false;

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
                            $chunk = $this->parseChunk($data, $toolCallBuffer);

                            $sawStructuredToolCalls = $sawStructuredToolCalls
                                || ($chunk->toolCalls !== null && $chunk->toolCalls !== []);

                            if ($sawStructuredToolCalls) {
                                // Once the structured path has produced a call,
                                // recoverTextualToolCalls() returns null no
                                // matter what the text says - so everything
                                // buffered is provably dead and nothing more
                                // needs buffering. Releasing it here is the
                                // half of the gate that costs no abstraction.
                                $assembledContent = '';
                            } else {
                                $assembledContent .= $chunk->content;
                            }

                            // Yielded UNCHANGED and in wire order. The
                            // recovery below is purely additive - it never
                            // withholds, delays or rewrites a content chunk,
                            // so the streamed-token UX is byte-for-byte what
                            // it was.
                            yield $chunk;
                        }
                    }
                }
            }

            $recovered = $this->recoverTextualToolCalls($assembledContent, $sawStructuredToolCalls);

            if ($recovered !== null) {
                // Empty content on purpose: the text was already streamed
                // above, and {@see \SugarCraft\Crush\Runtime::runStreaming()}
                // appends every chunk's content to its buffer, so repeating
                // it here would duplicate the whole turn in the transcript.
                // `tokensUsed: 0` / `costUsd: 0.0` make
                // {@see \SugarCraft\Crush\Usage::reported()} return null for
                // this chunk, which {@see \SugarCraft\Crush\Usage::sum()}
                // skips - so a recovered turn's usage total is unchanged, and
                // in particular a turn that reported nothing still sums to
                // null rather than to zero.
                yield new CompleteResponse(
                    content: '',
                    reasoning: null,
                    toolCalls: $recovered,
                    tokensUsed: 0,
                    costUsd: 0.0,
                );
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
        $this->flagTruncationRiskInLatestToolResults($request->messages, $request->model);

        $params = [
            'model' => $request->model,
            'messages' => $this->formatMessages($request->messages),
            // Model-aware since DeepSeek-V4 became the default: this used to
            // be a flat `?? 0.7`, which is DeepSeek-V4-Flash's card-prescribed
            // 1.0 minus 0.3. Keyed on $request->model, the id this body is
            // addressed to - see defaultTemperature().
            'temperature' => $request->temperature ?? self::defaultTemperature($request->model),
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
            // The ONE knob in this list with a non-null default, and only for
            // one model family - see defaultTopP(). Everything else below
            // still means "defer to the server's launch-time default" when
            // the caller left it unset.
            'top_p' => $request->topP ?? self::defaultTopP($request->model, $request->tools),
            'top_k' => $request->topK,
            'min_p' => $request->minP,
            'repetition_penalty' => $request->repetitionPenalty,
            'stop' => $request->stop,
            'chat_template_kwargs' => $request->extraTemplateKwargs,
            // Top-level, NOT under chat_template_kwargs. Those two are
            // different mechanisms and the difference matters here:
            // `chat_template_kwargs` feeds a server-side Jinja chat template,
            // and DeepSeek-V4-Flash ships none, so routing effort through it
            // would be silently dropped. `reasoning_effort` is a field on
            // SGLang's own ChatCompletionRequest - proven by the fact that a
            // bogus value is REJECTED 400 by its pydantic model rather than
            // ignored (probed 2026-08-20).
            'reasoning_effort' => $this->resolveReasoningEffort($request),
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
     * True when a model id names the DeepSeek-V4 family.
     *
     * Case-insensitive substring, not equality: the deployed id is
     * `deepseek-ai/DeepSeek-V4-Flash-0731` - vendor prefix, mixed case, dated
     * suffix - and the next redeploy changes the date without changing which
     * card's sampling applies. See {@see DEEPSEEK_V4_FAMILY_TOKEN} for why the
     * token is not the broader `deepseek`.
     *
     * PUBLIC so {@see ProviderFactory::toolCallParser()} can pick this
     * family's default tool-call parser from the SAME predicate that picks its
     * sampling and its context window. A second, independently-spelled family
     * test in the factory would be free to drift from this one, and then two
     * files would disagree about what "DeepSeek-V4" means.
     */
    public static function isDeepSeekV4(string $model): bool
    {
        return str_contains(strtolower($model), self::DEEPSEEK_V4_FAMILY_TOKEN);
    }

    /**
     * The `temperature` to send when the caller named none.
     *
     * TWO domains, and that is the whole reason this is a method rather than a
     * literal: DeepSeek-V4-Flash's card prescribes 1.0 unconditionally, while
     * 0.7 is what this class has always sent and what MiniMax deployments have
     * been running on. Making 1.0 global would retune MiniMax on the strength
     * of a DeepSeek measurement, which is precisely the "a number written next
     * to the wrong model" defect - so the old value stays the default for
     * every model outside that one family.
     */
    private static function defaultTemperature(string $model): float
    {
        return self::isDeepSeekV4($model)
            ? self::DEEPSEEK_V4_TEMPERATURE
            : self::LEGACY_DEFAULT_TEMPERATURE;
    }

    /**
     * The `top_p` to send when the caller named none, or null to send nothing.
     *
     * DeepSeek-V4-Flash's card splits this by scenario: 0.95 for AGENTIC use,
     * 1.0 otherwise. "Agentic" is prose, so it is pinned here to the only
     * agentic signal actually present on a `CompleteRequest`: whether the
     * caller offered the model any TOOLS. A request with tools is a request
     * where the model may act rather than only answer, which is the
     * distinction the card is drawing; a request with none cannot be a tool
     * loop no matter what it asks for. Stated explicitly because 0.95 next to
     * no definition of "agentic" is a number without its domain.
     *
     * That mapping is a JUDGEMENT and is deliberately coarse. Its practical
     * effect, traced rather than assumed - `src/` holds exactly EIGHT
     * `new CompleteRequest(` sites, and only ONE of them can reach this
     * provider at all:
     *
     * - AGENTIC (0.95): {@see \SugarCraft\Crush\Runtime}, the only one of the
     *   eight that builds `messages` out of {@see Message} objects. It passes
     *   `$app->tools ?: null`, so a chat turn carrying tools lands here.
     * - NON-AGENTIC (1.0): the SAME `Runtime` site, reached through a backend
     *   built with no tools. Two exist and both are wired at
     *   {@see \SugarCraft\Crush\Cli\Bootstrap}: `titleBackend()` (the one-shot
     *   session-title call) and `summaryBackend()` (`/compact`'s model-written
     *   exchange summaries). Both come from `Bootstrap::toollessBackend()`,
     *   whose whole contract is a provider with nothing attached, so
     *   `$app->tools` is `[]` and `tools` arrives null. Not taken on trust:
     *   `tests/Cli/BootstrapSpendAndSummaryTest.php` asserts
     *   `$this->privateProperty($backend, 'tools') === []` on both.
     * - CANNOT REACH THIS PROVIDER AT ALL, so not a case either way: the other
     *   seven sites ({@see \SugarCraft\Crush\App\App}'s skill-fork sub-agent
     *   and every {@see \SugarCraft\Crush\Workflows\WorkflowEngine} request)
     *   pass raw `['role' => …, 'content' => …]` arrays, and
     *   {@see formatMessages()} types its callback `Message`, so they
     *   TypeError before any sampling default is consulted. A pre-existing gap
     *   (§12 D2), named here only so a reader counting call sites does not
     *   conclude those are silently classified non-agentic. Note that if it is
     *   ever closed, `WorkflowTask::$tools` defaults to `[]`, so an autonomous
     *   workflow agent would classify as NON-agentic - which is a real wrinkle
     *   in the tools-mean-agentic mapping and the place to revisit it.
     *
     * Compaction is easy to miscount here, so: `/compact`'s MODEL-written
     * summaries go through `summaryBackend()` above and are non-agentic, while
     * {@see \SugarCraft\Crush\Context\ContextCompactor::summarizeExchanges()}
     * is pure string work that never calls a provider and so is not a case at
     * all.
     *
     * Null for every non-DeepSeek-V4 model: this class emitted no `top_p` at
     * all before, and MiniMax has no card figure we measured, so the absent
     * key (server's launch-time default wins) is preserved there.
     *
     * @param ?array<mixed> $tools the request's `tools`, exactly as the DTO
     *        holds it - null AND the empty array both mean "no tools offered"
     */
    private static function defaultTopP(string $model, ?array $tools): ?float
    {
        if (!self::isDeepSeekV4($model)) {
            return null;
        }

        return ($tools !== null && $tools !== [])
            ? self::DEEPSEEK_V4_TOP_P_AGENTIC
            : self::DEEPSEEK_V4_TOP_P_NON_AGENTIC;
    }

    /**
     * The `reasoning_effort` for one request, or null to omit the field.
     *
     * THREE tiers, most specific first:
     *
     * 1. `CompleteRequest::$reasoningEffort` - this one call.
     * 2. The provider's `$reasoningEffort` - the deployment's config key.
     * 3. {@see defaultReasoningEffort()} - derived from the model.
     *
     * Tier 3 exists because omitting the field is NOT neutral. Measured
     * against skynet2 on 2026-08-20 with no `reasoning_effort`:
     * `reasoning_content` came back null, `reasoning_tokens` 0, and the
     * model's thinking was written straight into `content` - a riddle prompt
     * answered with "Okay, let's break it down carefully..." as the assistant
     * text. The same prompt with `max` returned 62 reasoning tokens in
     * `reasoning_content` and a one-line answer in `content`. So an absent
     * effort does not mean "server default, no opinion"; on this model it
     * means the reasoning contaminates the reply the user reads.
     *
     * Note the value is validated even though tiers 2 and 3 were already
     * validated where they were set - tier 1 arrives straight off a
     * caller-built DTO with no validation anywhere else, and one check at the
     * single point of use cannot go out of sync with three sources.
     */
    private function resolveReasoningEffort(CompleteRequest $request): string|float|null
    {
        $effort = $request->reasoningEffort
            ?? $this->reasoningEffort
            ?? self::defaultReasoningEffort($request->model);

        if ($effort === null) {
            return null;
        }

        return self::validatedReasoningEffort($effort, 'CompleteRequest::$reasoningEffort');
    }

    /**
     * The `reasoning_effort` implied by a model id alone.
     *
     * `max` for the DeepSeek-V4 family: the user's explicit instruction for
     * this model, and the top of the card's own recommended set
     * (`low`/`high`/`max`).
     *
     * NULL for every other model, which is the field being omitted entirely -
     * and that asymmetry is deliberate rather than lazy. Two facts about it,
     * kept separate because only one of them is measured:
     *
     * - MEASURED (by absence): `reasoning_effort` appeared nowhere in `src/`,
     *   `bin/`, `tests/` or any config file before this change, so no request
     *   this codebase has ever sent carried one. Omitting it for a
     *   non-DeepSeek-V4 model is therefore the status quo exactly.
     * - NOT MEASURED: what an effort level would do to MiniMax-M2.x. That
     *   deployment is gone from the confirmed server, so there is no way to
     *   find out here. Sending one anyway, on the strength of a DeepSeek
     *   measurement, would be changing another model's behaviour blind - which
     *   is the one thing this change was explicitly not to do.
     */
    private static function defaultReasoningEffort(string $model): ?string
    {
        return self::isDeepSeekV4($model)
            ? self::DEEPSEEK_V4_REASONING_EFFORT
            : null;
    }

    /**
     * Returns `$effort` unchanged, or throws if the server would refuse it.
     *
     * WHAT IS CHECKED, and what deliberately is not:
     *
     * - A STRING must be one of {@see REASONING_EFFORT_LEVELS}. That set is
     *   closed, was read off the server's own pydantic literal, and a typo
     *   (`"maximum"`, `"High "`) is a caller bug worth failing on locally
     *   rather than at HTTP 400 wrapped in a `RuntimeException` from
     *   {@see complete()} - CONTRIBUTING.md's no-silent-failures rule, and the
     *   same reasoning {@see ProviderFactory::toolCallParser()} applies to its
     *   own closed name set.
     * - A FLOAT is forwarded with NO range check, on purpose. The server also
     *   accepts a `constrained-float`, measured on 2026-08-20 as `0.0` through
     *   `0.99` inclusive (`1.0` is rejected with `le: 0.99`). That bound is
     *   SGLang's, not ours, and hardcoding 0.99 here would refuse whatever a
     *   later SGLang widens it to - the exact failure mode narrowing the level
     *   set to the card's three would have been. An out-of-range float still
     *   fails loudly, just at the server, whose 400 names the live bound.
     *
     * $origin names which tier supplied the value, because "unknown
     * reasoning_effort" with no indication of whether it came from a config
     * file or a caller's DTO sends the reader to the wrong file.
     *
     * @throws \InvalidArgumentException When a string is not a known level.
     */
    private static function validatedReasoningEffort(string|float $effort, string $origin): string|float
    {
        if (is_float($effort) || in_array($effort, self::REASONING_EFFORT_LEVELS, true)) {
            return $effort;
        }

        throw new \InvalidArgumentException(sprintf(
            'Unknown reasoning_effort %s from %s; expected one of %s, or a float '
            . '(SGLang accepted 0.0-0.99 inclusive when measured 2026-08-20).',
            var_export($effort, true),
            $origin,
            implode(', ', self::REASONING_EFFORT_LEVELS),
        ));
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
     * tool calls from `delta.tool_calls[]`.
     *
     * THAT IS NO LONGER THE WHOLE STORY, and this paragraph used to end by
     * saying the injected parser "does not yet affect the live streaming chat
     * loop". It does now: {@see recoverTextualToolCalls()} runs the same
     * parser over the reassembled streamed content when - and only when - the
     * structured path produced nothing. So the two paths agree on which parser
     * is in force. What remains asymmetric is WHEN it is consulted: here it is
     * the only decoder, whereas on the streaming path it is a fallback behind
     * `delta.tool_calls[]`.
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
     * Runs the injected parser over the fully reassembled streamed content,
     * closing the §12 D2 gap that made parser selection a batch-path-only
     * setting.
     *
     * THE GAP THIS CLOSES. {@see supportsStreaming()} returns true and both
     * production consumers branch on it ({@see \SugarCraft\Crush\Runtime} and
     * {@see \SugarCraft\Crush\Agents\AgentManager}), so the live TUI chat loop
     * takes `completeStream()`. Until now that path reassembled tool calls
     * itself, in {@see resolveStreamedToolCalls()}, and never consulted
     * {@see ToolCallParser\ToolCallParserInterface} at all - so selecting a
     * text-scanning fallback armed it on the one path nobody takes. A parser
     * wired only into {@see parseResponse()} would have recovered nothing in
     * the live chat, which is precisely the illusion
     * {@see ToolCallParser\MinimaxXmlFallbackToolCallParser}'s docblock warns
     * against.
     *
     * WHY THE SEAM IS HERE, ARGUED AGAINST THE POSITION {@see parseChunk()}
     * STATES. That method's docblock says the equivalent fix for a `</think>`
     * closer straddling a chunk boundary "belongs where content is reassembled
     * ({@see \SugarCraft\Crush\Runtime::runStreaming()}), not here". The first
     * half of that is right and is why this is not in `parseChunk()`: one
     * delta cannot see an envelope split across two of them. The second half
     * does not transfer to THIS fix, for a reason specific to it. Reasoning
     * splitting needs only the text, which `Runtime` has. Tool-call recovery
     * additionally needs `$this->toolCallParser` - per-provider, injected
     * strategy state that `Runtime` neither holds nor should: `Runtime` is
     * provider-agnostic and drives Vertex, Bedrock and Custom through the same
     * loop, so putting a `--tool-call-parser` compensation there would push a
     * MiniMax/DeepSeek-specific concern into every provider's path. So the
     * seam wants the narrowest scope that sees BOTH the whole response and the
     * injected parser, and `completeStream()` is the only one: it owns exactly
     * one response's lifetime and already threads a by-reference buffer for
     * this same reassembly reason. `parseChunk()` sees the parser but not the
     * whole response; `Runtime` sees the whole response but not the parser.
     *
     * NO DOUBLE-EMISSION. `$sawStructuredToolCalls` is the guard: if
     * `delta.tool_calls[]` produced anything at all this response, this
     * returns null and the structured result stands alone. The two paths are
     * therefore mutually exclusive by construction, not by the parsers
     * happening to disagree.
     *
     * COSTS NOTHING ON THE DEFAULT PARSER. {@see OpenAiArrayToolCallParser}
     * reads only `tool_calls`, and the synthesised message below deliberately
     * has no such key, so it returns null immediately. A deployment on the
     * default parser sees no behaviour change whatsoever.
     *
     * KNOWN GAP, deliberately not closed here: the recovered markup is still
     * present in the content that was streamed to the screen and into the
     * transcript, so a user watching a fallback-recovered turn sees the raw
     * envelope. Stripping it would mean withholding content chunks until the
     * envelope is known to be complete, which is the one thing that WOULD
     * degrade the streamed-token UX. {@see parseResponse()} has the identical
     * property on the batch path and always has.
     *
     * @return array<ToolCall>|null
     */
    private function recoverTextualToolCalls(string $content, bool $sawStructuredToolCalls): ?array
    {
        if ($sawStructuredToolCalls || $content === '') {
            return null;
        }

        // Synthesised with NO `tool_calls` key, because its absence is exactly
        // the condition every fallback parser triggers on - handing over an
        // empty array instead would take their delegated fast path and find
        // nothing.
        $calls = $this->resolvedToolCallParser()->parse(['content' => $content]);

        return $calls === [] ? null : $calls;
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
     *
     * DELIBERATELY NOT model-gated, unlike
     * {@see flagTruncationRiskInLatestToolResults()}. That method predicts a
     * failure from an intact payload and so may only speak about models the
     * failure was measured on; this one DIAGNOSES a payload that has already
     * failed to decode, and a broken payload is worth reporting whatever model
     * produced it. What is gated is the CERTAINTY of the attribution: the text
     * says the payload MATCHES THE SIGNATURE of the MiniMax bug rather than
     * asserting it IS that bug, because on any other model - DeepSeek-V4-Flash
     * included, measured 2026-08-20 as not having it - the same shape has some
     * other cause and naming MiniMax as fact would send the reader to the
     * wrong server.
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
            . 'That matches the signature of the known MiniMax-M2.x "%s" tool-call bug '
            . '(server-side, not fixable client-side) - the CAUSE is inferred from the shape, not '
            . 'from the model, so on a non-MiniMax model look for another truncation source; the '
            . 'call is being executed with no arguments. Raw payload: %s',
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
     * MODEL-GATED, and that gate is the whole point of the $model parameter.
     * This is a PREDICTION, not a detection: it fires on a payload that is
     * still intact, purely because a NAMED model is known to mishandle it
     * later. So it may only be asserted about models the mishandling was
     * measured on.
     *
     * - MEASURED on MiniMax-M2.x (§12 D5): the bug this predicts.
     * - MEASURED on `deepseek-ai/DeepSeek-V4-Flash-0731`, live against
     *   skynet2 on 2026-08-20: it does NOT have the bug. A `write_file` call
     *   whose `body` argument was
     *   `<invoke name="x"><parameter name="y">z</parameter></invoke> DONE`
     *   came back through structured `tool_calls` at 64 of 64 bytes, byte
     *   identical, `</parameter>` present, nothing logged. So the DeepSeek-V4
     *   family is skipped outright - warning about it would be a MiniMax
     *   measurement written next to a model that was measured not to share it,
     *   which is exactly the defect this codebase keeps finding.
     * - NOT MEASURED: every other model id. Those still get the warning,
     *   because an unmeasured model is not a model known to be safe - but the
     *   text now NAMES the id it fired for alongside the id the bug was
     *   measured on, so the log line carries its own domain instead of
     *   implying the request went to MiniMax.
     *
     * Judged on `$model`, the request's model, because that is the id this
     * body is addressed to - the same choice {@see defaultTemperature()} and
     * {@see defaultTopP()} make, and for the same reason.
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
     * @param string       $model the request's model id, which decides whether
     *        this prediction is assertable at all - see above
     */
    private function flagTruncationRiskInLatestToolResults(array $messages, string $model): void
    {
        if (self::isDeepSeekV4($model)) {
            return;
        }

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
                . 'content) is at elevated risk of silent truncation. This request is addressed '
                . 'to model "%s"; the bug was measured on MiniMax-M2.x, and DeepSeek-V4-Flash was '
                . 'measured NOT to have it (2026-08-20) and is never warned about.',
                $result->toolCallId(),
                self::XML_PARAM_CLOSE_TAG,
                $occurrences,
                $model,
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
