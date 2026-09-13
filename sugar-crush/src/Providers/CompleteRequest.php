<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Providers;

/**
 * Provider-agnostic completion request.
 *
 * The sampling fields below (`topP`/`topK`/`minP`/`repetitionPenalty`/`stop`)
 * and `extraTemplateKwargs` exist because SGLang's `/v1/chat/completions`
 * route accepts a wider sampling surface than plain OpenAI does, and
 * MiniMax-M2.7 in particular needs `repetition_penalty`/`min_p` to stay
 * coherent across the long agentic tool-loop transcripts sugar-crush
 * produces (crush_feat.md §12 D4). Every field is optional and defaults to
 * null so a provider that has no equivalent knob simply drops it.
 *
 * SAMPLING has no config surface in sugar-crush yet, and no step in the
 * current plan claims one - the pre-existing `temperature`/`maxTokens` fields
 * are in exactly the same position, left unset by every in-tree construction
 * site. Until some step wires them to config, all of those stay
 * caller-supplied knobs that only reach a request when something constructs
 * this DTO with them directly.
 *
 * `$reasoningEffort` is the ONE field on this DTO that is NOT in that
 * position, and the distinction is the point: it has a config surface today -
 * the `reasoningEffort` key on an `sglang` provider block, read by
 * {@see ProviderFactory::createSglang()} - so it reaches a live request even
 * though every in-tree construction site leaves this parameter null. Do not
 * read the paragraph above as covering it.
 *
 * NOTE that `temperature` and `topP` are ALSO no longer purely
 * caller-supplied on the SGLang path, for a different reason: leaving them
 * null there now selects a MODEL-DERIVED default rather than the single
 * hardcoded 0.7 it used to ({@see SglangProvider::defaultTemperature()}).
 * That is a provider-side default, not a config surface.
 *
 * Mirrors SGLang's `SamplingParams` naming (docs.sglang.io sampling_params).
 */
final readonly class CompleteRequest
{
    /**
     * @param array<mixed>          $messages
     * @param ?array<mixed>         $tools
     * @param string|array|null     $jsonSchema        JSON-Schema for constrained decoding, as a schema array or a pre-encoded JSON string.
     * @param ?float                $topP              Nucleus-sampling mass.
     * @param ?int                  $topK              Top-k candidate cutoff (SGLang extension; -1 disables server-side).
     * @param ?float                $minP              Minimum token probability relative to the top token (SGLang extension).
     * @param ?float                $repetitionPenalty Multiplicative penalty on already-emitted tokens (SGLang extension).
     * @param string|array|null     $stop              Stop string, or list of stop strings.
     * @param ?array<string, mixed> $extraTemplateKwargs Passthrough into the server-side Jinja chat template, e.g. `['enable_thinking' => true]`.
     * @param string|float|null    $reasoningEffort   How much thinking the server should budget for this request. See below.
     * @param ?list<string>        $systemBlocks      The assembled system prompt as ordered text blocks. See below.
     */
    public function __construct(
        public string $model,
        public array $messages,
        public ?array $tools = null,
        public ?string $systemPrompt = null,
        public ?float $temperature = null,
        public ?int $maxTokens = null,
        public string|array|null $jsonSchema = null,
        public ?float $topP = null,
        public ?int $topK = null,
        public ?float $minP = null,
        public ?float $repetitionPenalty = null,
        public string|array|null $stop = null,
        public ?array $extraTemplateKwargs = null,
        /**
         * SGLang's top-level `reasoning_effort` field.
         *
         * A LEVEL NAME or a FLOAT, and both halves of that are the deployed
         * server's own vocabulary rather than any model card's: probed against
         * skynet2 (SGLang, `deepseek-ai/DeepSeek-V4-Flash-0731`) on 2026-08-20,
         * an unknown value is rejected with TWO pydantic errors naming both
         * accepted shapes -
         * `literal['none','minimal','low','medium','high','xhigh','max']` and a
         * `constrained-float`. The DeepSeek-V4-Flash card documents only
         * `low`/`high`/`max`; that is a RECOMMENDATION, and narrowing this
         * field to those three would refuse four values the server serves.
         *
         * Null means "let {@see SglangProvider} decide" - which is not the same
         * as "send nothing". Measured on the same deployment, a request with no
         * `reasoning_effort` at all comes back with `reasoning_content: null`,
         * `reasoning_tokens: 0` and the model's thinking written INLINE into
         * `content` ("Okay, let's break it down carefully..." for a riddle
         * prompt), so an absent effort actively pollutes the assistant text.
         * That is why the provider substitutes a model-derived default rather
         * than passing null through - see
         * {@see SglangProvider::resolveReasoningEffort()}.
         *
         * DOMAIN: `reasoning_effort` is an SGLang `/v1/chat/completions` field.
         * {@see SglangProvider} is the only provider in this repo that reads
         * it; every other provider ignores it, exactly as they ignore
         * `$minP`/`$topK`.
         */
        public string|float|null $reasoningEffort = null,
        /**
         * The assembled system prompt as an ORDERED LIST OF TEXT BLOCKS,
         * landed alongside the flat {@see $systemPrompt} string rather than
         * replacing it.
         *
         * WHY IT EXISTS (P10.S1 / prompt_plan.md §P10.S1, §10 seam 4): an
         * Anthropic-shaped provider expresses its system instruction as a
         * `[{type:text,text:...}]` array and can attach a per-block cache
         * breakpoint to it, but a single flat `?string` cannot express that
         * shape at all. `systemBlocks` carries the block STRUCTURE so the
         * provider that speaks that protocol can build the array; the block
         * PLACEMENT decision (which block gets `cache_control`) is a later
         * step's machinery and is deliberately NOT wired here — this field
         * ships shape only.
         *
         * BYTE FIDELITY, and the join rule that keeps it: each entry is the
         * exact bytes ONE {@see \SugarCraft\Crush\Context\PromptSection} contributes to the
         * assembled prompt, and the boundary between adjacent blocks is the
         * separator `Runtime::assemblePrompt()` already spends. A section
         * whose body does NOT itself open with the inter-layer "\n\n" gets
         * that separator as the LEADING bytes of its block; a section that
         * already carries a leading "\n\n" (the skill layers — see
         * PromptSection::render()) keeps it INSIDE its own block untouched,
         * so no separator is ever doubled. Therefore the ordered
         * concatenation of the block texts reproduces
         * `Runtime::buildSystemPrompt()`'s flat string EXACTLY under the
         * join rule "concatenate the block texts in order" — which is
         * literally `implode('', $systemBlocks) === $systemPrompt`. The two
         * representations are the same bytes cut at block boundaries, never
         * two different renderings.
         *
         * NULL, and only null, means "no structured form was supplied" — the
         * default every existing construction site relies on so none of them
         * had to change. It is not an empty block list: `[]` would be a
         * well-formed-but-empty structure whose concatenation is the empty
         * string, which contradicts a non-empty {@see $systemPrompt}. A
         * provider that has no block-array vocabulary keeps reading
         * {@see $systemPrompt} and transmits exactly as it does today;
         * providers that DO speak it read these blocks INSTEAD of splitting
         * the flat string, which is the whole point of carrying the cut
         * rather than making each provider re-derive it.
         */
        public ?array $systemBlocks = null,
        /**
         * Optional progress heartbeat for a BATCH (non-streaming) completion.
         *
         * WHY (E493): a batch `complete()` blocks inside `curl_exec()` for as
         * long as the server thinks - minutes on a loaded host with a long
         * agentic transcript - and during that window the process looks dead
         * to whoever is watching it. E524 measured the naive remedy and it
         * does not exist: pcntl-dispatched signals cannot interrupt a blocking
         * libcurl read (PHP only runs signal handlers at VM tick points, and
         * there are none inside `curl_exec()`), so a SIGALRM-driven writer
         * gets ZERO beats exactly when they are needed. What DOES fire inside
         * the blocking transfer is libcurl's own progress callback - measured
         * 18 callbacks across a 3s transfer, including at t=1.001s and
         * t=2.002s with no bytes moving - which Guzzle exposes as the
         * `progress` request option. This closure is what {@see
         * \SugarCraft\Crush\Providers\Concerns\HttpClientDefaults::heartbeatOptions()}
         * wires into that option, throttled to at most one call per second.
         *
         * CONTRACT, and each half is load-bearing:
         *   - FAIL-SOFT. The wrapper swallows every Throwable this closure
         *     throws: a broken heartbeat must never fail an in-flight paid
         *     completion. It also must never RETURN truthy-as-abort - Guzzle
         *     discards the curl handler's progress return, but the throttled
         *     wrapper returns void regardless.
         *   - NOT a timeout. Installing a heartbeat arms nothing that kills
         *     or bounds the request (standing ban on new wall-clock kills);
         *     it is telemetry only, and a consumer that receives beats is
         *     observing liveness, not granting one.
         *   - BATCH path only. `completeStream()` is self-announcing - every
         *     SSE frame is a heartbeat - so no provider reads this field on
         *     the streaming path, and leaving it set for one is inert.
         *
         * WHO CAN ACTUALLY DELIVER ONE: SglangProvider and CustomProvider
         * (plain Guzzle, they take request options). Not OpenAIProvider
         * (openai-php's `Chat::create()` exposes no per-request transport
         * options), not BedrockProvider (the AWS SDK owns its curl handles),
         * not VertexProvider (Google SDK owns its transport), not
         * ClaudeCodeProvider (proc_open child, no HTTP transfer) or
         * EchoProvider (no I/O). Those gaps are properties of the SDKs,
         * recorded honestly rather than papered over.
         *
         * WHO CALLS IT: the consumer half landed at round 71 (lane gh).
         * {@see \SugarCraft\Crush\Backend\EngineBackend::runCompleteInChild()}
         * — the forked child of `completeAsync()` — passes a closure that
         * writes one bare `reasoning` frame per beat, threaded through
         * {@see \SugarCraft\Crush\Backend\EngineBackend::complete()} and
         * {@see \SugarCraft\Crush\Runtime::run()}'s `$onHeartbeat` onto this
         * field, so the parent's idle deadline survives a BATCH turn whose
         * transport can fire. No other in-tree caller passes one; a turn with
         * no heartbeat keeps `heartbeatOptions()` answering `[]`, which is the
         * pre-E493 wire byte-for-byte.
         */
        public ?\Closure $onHeartbeat = null,
    ) {}
}
