<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Providers;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\RequestException;
use SugarCraft\Crush\Diagnostics\RuntimeNoticeSink;
use SugarCraft\Crush\Messages\Message;
use SugarCraft\Crush\Messages\AssistantMessage;
use SugarCraft\Crush\Messages\UserMessage;
use SugarCraft\Crush\Messages\SystemMessage;
use SugarCraft\Crush\Messages\ToolResultMessage;
use SugarCraft\Crush\Providers\Concerns\HttpClientDefaults;
use SugarCraft\Crush\Providers\Concerns\ReasoningExtractor;
use SugarCraft\Crush\Providers\Concerns\ReassemblesStreamedToolCalls;
use SugarCraft\Crush\Providers\ToolCallParser\DsmlToolCallParser;
use SugarCraft\Crush\Providers\ToolCallParser\OpenAiArrayToolCallParser;
use SugarCraft\Crush\Providers\ToolCallParser\EnvelopeAware;
use SugarCraft\Crush\Providers\ToolCallParser\EnvelopeHoldBack;
use SugarCraft\Crush\Providers\ToolCallParser\TextualRecovery;
use SugarCraft\Crush\Providers\ToolCallParser\ToolCallParserInterface;
use SugarCraft\Crush\Providers\ToolCallParser\ToolParameterTypes;
use SugarCraft\Crush\Providers\ToolCallParser\ToolSchemaAware;
use SugarCraft\Crush\Tools\Tool;
use SugarCraft\Crush\Tools\ToolCall;
use SugarCraft\Crush\Providers\Concerns\SessionAffinity;
use SugarCraft\Crush\Providers\Concerns\ToolSchema;
use SugarCraft\Crush\Usage;
use SugarCraft\Crush\Util\TokenEstimate;

final readonly class SglangProvider implements ProviderInterface, ReportsServedModel
{
    use ToolSchema;

    use ReasoningExtractor;

    use HttpClientDefaults;

    use SessionAffinity;

    /**
     * Streamed tool-call fragment buffering and assembly, shared with
     * CustomProvider and OpenAIProvider since X-31a. The end-of-stream flush
     * stays {@see flushTruncatedToolCalls()}: its drop diagnostics are this
     * provider's own (head+tail excerpts, the malformed-payload taxonomy),
     * not the trait's.
     */
    use ReassemblesStreamedToolCalls;

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
     * Audit A11: the fate clause for a call whose arguments did not decode on
     * a declared-complete end. The call is still emitted, carrying
     * {@see \SugarCraft\Crush\Tools\ToolCall::argumentsError()}, and Runtime
     * answers it with that error without running the tool - so the warning
     * must not say the call runs with no arguments, which it no longer does.
     */
    private const REFUSED_FATE = 'the call is NOT executed - the model is sent the JSON error '
        . 'as the tool result instead, so it can resend the call';

    /**
     * Upper bound on how many tool-call ids {@see $truncationRiskWarned}
     * remembers. See {@see flagTruncationRiskInLatestToolResults()} for why
     * the set is bounded and why forgetting the oldest id is harmless.
     */
    private const TRUNCATION_RISK_WARNED_CAP = 1024;

    /**
     * Audit C2(b): the tool-call ids (or, for an id-less result, a content
     * key) the `</parameter>` truncation-risk warning has already been logged
     * for, keyed by id in insertion order so the oldest entry is the first
     * key. Values are unused (`true`).
     *
     * A mutable object behind a property assigned once in the constructor
     * because this is a `final readonly class`: a readonly class cannot
     * declare a static property, and a method-local `static` would be shared
     * by EVERY instance - a test (or a rebuilt provider) reusing an id like
     * `call_read` would then be silenced by another instance's history.
     *
     * @var \ArrayObject<string, true>
     */
    private \ArrayObject $truncationRiskWarned;

    /**
     * Audit 15a A18: the once-per-provider memo for {@see serverInfo()} -
     * key `loaded` once the loader has run (success OR failure, so a dead
     * server is asked once, not on every frame), key `info` holding the
     * {@see SglangServerInfo} or null, and key `reportedServedModel` holding
     * a served name a forked turn child reported ({@see noteServedModel()},
     * audit 15b-35). An object behind a once-assigned
     * property for the same `final readonly class` reason as
     * {@see $truncationRiskWarned}.
     *
     * @var \ArrayObject<string, mixed>
     */
    private \ArrayObject $serverInfoMemo;

    /**
     * §Q8 (E-56): longest server-authored error text kept when it is lifted
     * out of an error body for display. Measured SGLang bodies (E-40/E-10)
     * are one short sentence, so this only exists to stop a pathological
     * server that echoes a whole request into `message` from walling the
     * Chat pane - the exact readability failure this extraction fixes.
     * Aliased so an in-stream error frame (audit A2) is clipped identically.
     */
    private const ERROR_MESSAGE_DISPLAY_LIMIT = ProviderStreamException::MESSAGE_DISPLAY_LIMIT;

    /**
     * §Q7 (qwen.md; E-32): the `finish_reason` values that mean "this
     * response was cut off", on both arms. E-32 measured `stop` and
     * `tool_calls` as the clean ends this deployment serves; `length` and
     * `abort` are the truncating ends, and are the only strings that mark
     * the buffered tool-call flush frame in {@see completeStream()} as
     * truncated or set {@see CompleteResponse::$truncated} in
     * {@see parseResponse()}. Membership no longer decides WHETHER the
     * stream flushes: since audit 15a A4 a clean `stop`-style end flushes
     * its leftover fragments too, unflagged, and only `error` - deliberately
     * NOT in this set, it names Q8's error-body surfacing - skips the flush.
     * A stream that closes with `[DONE]` but no `finish_reason` counts as
     * truncated - that is the `null` arm at the call site, not a member of
     * this list. A stream that dies before BOTH (hard cut) does not reach the
     * flush at all: it throws {@see ProviderStreamException::prematureEnd()}
     * (audit 15a A3).
     *
     * @var list<string>
     */
    private const TRUNCATED_FINISH_REASONS = ['length', 'abort'];

    /**
     * The model a generic `sglang` launch asks for when nobody named one, and
     * the STATIC FALLBACK behind the served model (audit A26).
     *
     * WHAT SKYNET2 SERVES, re-read 2026-10-02 from its own `GET /model_info`:
     * `served_model_name: "Qwen/Qwen3.8-Flash-Next-FP8"`, `tool_call_parser:
     * "qwen3_coder"` (fixture `tests/fixtures/sglang-model-info-qwen3.8.json`).
     * This constant named `deepseek-ai/DeepSeek-V4-Flash-0731` until then -
     * the model that server ran from 2026-08-20, itself the replacement of
     * `MiniMax-M2.7` - and its docblock still said skynet2 served it, so every
     * launch relying on the default asked for a model the server no longer
     * had and, since A18's discovery, drew the family-mismatch notice too.
     *
     * NOT THE WHOLE ANSWER ANY MORE, because a third transcription would go
     * stale exactly as the first two did. When discovery is armed
     * ({@see ProviderFactory::createSglang()} arms it) and a request names
     * THIS id - which is what "no model configured" looks like, since
     * {@see ProviderFactory::defaultConfig()} stamps it - the request is
     * addressed to the model the server reports serving instead
     * ({@see addressedToServedModel()}), with that model's sampling,
     * reasoning-effort and tool-call-parser defaults. This id is what is sent
     * only when the server could not be asked or named no model. A request
     * naming any OTHER id - `model` in a provider block, `--model`,
     * `$SUGARCRUSH_MODEL` - is sent as named.
     *
     * DOMAIN: this is a DEFAULT, not a restriction. DeepSeek-V4 and
     * MiniMax-M2.x remain fully supported; naming either explicitly selects
     * every family-specific behaviour in this class unchanged - see
     * {@see DEEPSEEK_V4_FAMILY_TOKEN}, {@see XML_PARAM_CLOSE_TAG},
     * {@see malformedArgumentsWarning()} and
     * {@see \SugarCraft\Crush\Providers\ToolCallParser\MinimaxXmlFallbackToolCallParser}.
     */
    public const DEFAULT_MODEL = 'Qwen/Qwen3.8-Flash-Next-FP8';

    /**
     * Lowercased substring that identifies the DeepSeek-V4 family in a model
     * id, and therefore the ONE model family whose card-prescribed sampling
     * this class substitutes for its historical defaults.
     *
     * A family token rather than one exact id because the
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
     * The largest input the deployed server accepted for
     * `deepseek-ai/DeepSeek-V4-Flash-0731` (then {@see DEFAULT_MODEL}):
     * `max_req_input_len` from its own `/server_info`, read 2026-08-20.
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
     * Lowercased substring that identifies the Qwen3.8 family in a model id,
     * and therefore the family whose deployed-server figures this class
     * substitutes for the legacy defaults (qwen.md Q2).
     *
     * A family token rather than the exact id, for the same reasons as
     * {@see DEEPSEEK_V4_FAMILY_TOKEN}: the served ids carry an org prefix
     * and a `-Flash-Next` suffix a redeploy can change without changing
     * which server's caps the figures below transcribe, and the bare alias
     * `Qwen3.8-Flash-Next` (qwen.md E-70) must match too.
     * Deliberately NOT the broader `qwen`: Qwen3-235B, Qwen2.5 and every
     * other generation were never measured against this deployment, and the
     * Qwen figures below are transcriptions of THIS family's server -
     * matching the whole vendor would apply them to models whose limits are
     * unknown here. The failure modes stay asymmetric the same way the
     * DeepSeek token argues: a MISS costs only the model-aware window and
     * the effort default (legacy arm, status quo), an OVER-MATCH would
     * report a window some other Qwen server may refuse to hold.
     */
    private const QWEN3_NEXT_FAMILY_TOKEN = 'qwen3.8';

    /**
     * The `reasoning_effort` this class sends for the Qwen3.8 family when
     * neither the request nor the provider config names one.
     *
     * `xhigh` is doubly the safe top: it is the chat template's own default
     * AND the top of the template's accepted set `xhigh|medium|low`
     * (qwen.md E-40). DeepSeek's {@see DEEPSEEK_V4_REASONING_EFFORT} value
     * `max` deliberately does NOT carry over - sglang's pydantic enum is
     * wider than the template's, so `max` passes validation and then 400s AT
     * the template on every thinking-on request (E-41).
     */
    private const QWEN3_NEXT_REASONING_EFFORT = 'xhigh';

    /**
     * The single-request input ceiling the Qwen3.8 deployment enforces:
     * `max_req_input_len` from its own `/server_info`, read 2026-09-01
     * (qwen.md E-71). This is the field that actually rejects a request -
     * the server runs `allow_auto_truncate=false`, so an over-long input is
     * a hard error, not a silent trim (E-71).
     *
     * A TRANSCRIBED CONSTANT, NOT A LIVE READ, and it decays the way
     * {@see DEEPSEEK_V4_CONTEXT_WINDOW}'s ancestors did: a redeploy under
     * different flags makes it wrong with no local symptom, so re-verify
     * `https://skynet2.interserver.net/server_info` whenever the compaction
     * tiers start looking wrong. Kept as its own constant rather than only
     * baked into the derived {@see QWEN3_NEXT_CONTEXT_WINDOW}, because the
     * later compaction math in this plan needs the RAW ceiling beside the
     * safe window.
     *
     * DECAYED, AND KEPT AS IS ON PURPOSE (audit 15a A18): the same server
     * reported `max_req_input_len` **999,994** on 2026-10-02. That figure is
     * now READ LIVE ({@see SglangServerInfo}) whenever the server answers, so
     * this transcription only ever applies when it cannot be asked - and then
     * the older, smaller number is the safe one to fall back on, because a
     * window that errs small only compacts earlier.
     */
    private const QWEN3_NEXT_MAX_REQUEST_INPUT_LEN = 748_602;

    /**
     * The context window this class reports for the Qwen3.8 family:
     * `min(1_000_000, 748_602 − 4096)` = **744_506**.
     *
     * THE ARITHMETIC, because a bare number invites "just use the model
     * length" - the three inputs, each cited:
     * - 1,000,000: `context_length` the server publishes (YaRN 4.0 over the
     *   native 262,144; qwen.md E-71);
     * - 748,602: {@see QWEN3_NEXT_MAX_REQUEST_INPUT_LEN} - the ceiling the
     *   scheduler enforces on ONE REQUEST'S INPUT (E-71), not the total
     *   window. Which field, and why, is the argument
     *   {@see DEEPSEEK_V4_CONTEXT_WINDOW}'s docblock makes at length; it
     *   applies verbatim here.
     * - 4,096: output headroom - what was this provider's flat default
     *   `max_tokens` when the figure was derived (E-50; since audit 15a A18
     *   the default is derived per request, {@see defaultMaxTokens()}, and
     *   4,096 survives as {@see CONTEXT_WINDOW_OUTPUT_HEADROOM} and as the
     *   floor {@see MIN_DEFAULT_MAX_TOKENS}). Input and generated output share
     *   the scheduling budget (`max_total_num_tokens` 748,608, E-71), so a
     *   request whose input sat at 748,602 could not generate anything.
     *
     * WHY CONSERVATIVE: with `allow_auto_truncate=false` an over-long
     * request hard-errors (E-71), and every context tier in Chat is a
     * percentage of this number - erring LARGE turns reminder, compaction
     * and refusal OFF, which {@see ProviderInterface::contextWindow()} names
     * as the harmful direction (E-60 is exactly that tier math run with the
     * wrong denominator). Erring small costs only an earlier compaction.
     * The raw constants stay exposed so the margin can be re-tuned without
     * re-deriving the evidence.
     *
     * FALLBACK ONLY since audit 15a A18: {@see contextWindow()} derives the
     * same formula from the live `/server_info` first (995,898 on
     * 2026-10-02) and reaches this constant only when discovery is not armed
     * or failed.
     */
    private const QWEN3_NEXT_CONTEXT_WINDOW = 744_506;

    /**
     * The context window this class reports for anything that is neither
     * the DeepSeek-V4 nor the Qwen3.8 family - i.e. the MiniMax-M2.7 figure
     * it has always reported, kept because that deployment's
     * `--context-length` was exactly this (§12 D8) and because inventing a
     * different fallback would retune a model nobody asked us to retune.
     *
     * DOMAIN: this is a MiniMax-shaped number serving as the fallback for
     * every model outside the two families with measured figures, which is
     * a guess for any other model. 0
     * ("unknown", per {@see ProviderInterface::contextWindow()}) would be the
     * honest answer for a stranger, but it would also newly disable all four
     * context tiers on any MiniMax deployment reaching this arm, so the
     * pre-existing behaviour is preserved rather than improved here.
     */
    private const LEGACY_DEFAULT_CONTEXT_WINDOW = 196_608;

    /**
     * Audit 15a A18 (revised, user decision 2026-10-02): the largest
     * `max_tokens` this class sends when the caller named none - the user's
     * recommended output cap for current large-context models.
     *
     * WHY THE OLD FLAT 4096 HAD TO GO: SGLang counts generated REASONING tokens
     * against `max_tokens`, so a max-effort think on DeepSeek-V4 (or an
     * `xhigh` one on Qwen3.8) could spend the whole 4096 thinking and end with
     * `finish_reason: length` and an empty reply - known #28's "thinking-only
     * reply ends the turn", on the default configuration.
     *
     * A cap, not the value sent: {@see defaultMaxTokens()} sends
     * `min(this, the room the prompt leaves)`.
     */
    private const DEFAULT_OUTPUT_TOKEN_CAP = 262_144;

    /**
     * What this class sent for every request before A18, and still sends for
     * a model it knows nothing about when the server could not be asked: the
     * conservative default for a window nobody measured.
     */
    private const LEGACY_DEFAULT_MAX_TOKENS = 4096;

    /**
     * The fewest output tokens {@see defaultMaxTokens()} will ask for. Below
     * this the estimated room is noise - the prompt is within the safety
     * margin of the window, where Chat's 95% tier should already have refused
     * the turn - and the request goes out with this floor so an over-long
     * prompt fails LOUDLY with the server's own context-length 400 (surfaced
     * by {@see errorBodyMessage()}), rather than a zero or negative budget.
     */
    private const MIN_DEFAULT_MAX_TOKENS = 4096;

    /**
     * The smallest slack {@see defaultMaxTokens()} leaves between the
     * estimated prompt and the window. Covers the chat template's own framing
     * tokens, which no client-side estimate sees.
     */
    private const PROMPT_SAFETY_MARGIN_FLOOR = 8192;

    /**
     * The proportional half of that slack, as a divisor: a quarter of the
     * estimate. {@see \SugarCraft\Crush\Util\TokenEstimate} is chars/4 on
     * ASCII, and real tokenizers spend nearer one token per three characters
     * on code and JSON, so an estimate can run ~25% short. Overshooting the
     * window is a hard 400 on a server with `allow_auto_truncate=false`;
     * undershooting costs only output room on a prompt already near the limit.
     */
    private const PROMPT_SAFETY_MARGIN_DIVISOR = 4;

    /**
     * Output headroom subtracted from a DISCOVERED `max_req_input_len` when
     * {@see contextWindow()} derives the input budget from it - the same 4,096
     * the transcribed {@see QWEN3_NEXT_CONTEXT_WINDOW} was derived with, kept
     * so the live and the fallback windows follow one formula.
     */
    private const CONTEXT_WINDOW_OUTPUT_HEADROOM = 4096;

    /**
     * Fallback TOTAL windows (prompt + output) for {@see defaultMaxTokens()}
     * when discovery failed, keyed by the same family tokens as everything
     * else here. Transcribed, and decaying like every other transcription in
     * this class - they are only read when the server could not be asked.
     *
     * - DeepSeek-V4: `max_model_len` 1,048,576 from `/v1/models`, 2026-08-20
     *   (see {@see DEEPSEEK_V4_CONTEXT_WINDOW} for why that differs from the
     *   input ceiling by six).
     * - Qwen3.8: `context_length` 1,000,000 from `/server_info`, unchanged
     *   between the 2026-09-01 and 2026-10-02 reads.
     */
    private const DEEPSEEK_V4_TOTAL_WINDOW = 1_048_576;

    private const QWEN3_NEXT_TOTAL_WINDOW = 1_000_000;

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
     * The `reasoning_effort` values Qwen3.8's CHAT TEMPLATE accepts when the
     * effort reaches it through `chat_template_kwargs` - measured against
     * skynet2 2026-09-03 (qwen.md E-40): an invalid name 400s with the
     * template's own raise "Unexpected reasoning effort X. Supported types
     * are xhigh (default), medium, and low."
     *
     * Deliberately NARROWER than {@see REASONING_EFFORT_LEVELS}: that set is
     * the server's pydantic enum for the TOP-LEVEL field, which the Qwen
     * route stops using (qwen.md §Q4). `xhigh` is both the template's default
     * and this family's tier-3 config default
     * ({@see QWEN3_NEXT_REASONING_EFFORT}).
     */
    private const QWEN3_NEXT_TEMPLATE_EFFORTS = ['low', 'medium', 'xhigh'];

    /**
     * Translations from wider pydantic-level names to the nearest
     * template-vocabulary value, applied by {@see sanitizeEffortForTemplate()}
     * so the effort a deployment or caller already names keeps its INTENT
     * instead of 400-ing at the template (qwen.md E-41: `high`/`max` pass
     * pydantic and then fail the template check whenever thinking is on).
     *
     * `high`/`max` collapse UP to `xhigh` (the template's strongest setting,
     * matching what those names ask for), `minimal` collapses DOWN to `low`
     * (its weakest). Neither pair is an equality the template enforces - it
     * is a judgement, recorded here rather than buried in a switch.
     * `none` is NOT in this map: it has no template effort-word; it means
     * "stop thinking", handled by the enable_thinking arm of the sanitizer.
     */
    private const QWEN3_NEXT_EFFORT_TEMPLATE_ALIASES = [
        'minimal' => 'low',
        'high' => 'xhigh',
        'max' => 'xhigh',
    ];

    /**
     * @param ToolCallParserInterface|null $toolCallParser W1.A6 (§12 D6): the
     *        client-side mirror of SGLang's own `--tool-call-parser` flag.
     *        Left null the provider uses {@see OpenAiArrayToolCallParser} over
     *        {@see argumentDecoder()} (wrapped in DSML when it adopts a served
     *        DeepSeek-V4, see {@see resolvedToolCallParser()}), which is the correct strategy for any
     *        server actually launched with that flag - including the confirmed
     *        live deployment, RE-MEASURED 2026-08-20 after it was switched to
     *        `deepseek-ai/DeepSeek-V4-Flash-0731`: that model returns structured OpenAI
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
        /**
         * Deployment-wide `chat_template_kwargs`, from the `sglang` provider
         * block's optional `templateKwargs` key
         * ({@see ProviderFactory::createSglang()}).
         *
         * Merged UNDER the per-request DTO's `$extraTemplateKwargs`, per key,
         * at send time - see {@see mergedTemplateKwargs()} for the precedence
         * and why null/empty mean "carry nothing" rather than "clear". The
         * shape (associative array of string keys) is enforced at the config
         * parse seam ({@see ProviderFactory::configuredTemplateKwargs()});
         * values are template-side business and travel untouched.
         *
         * `[]` (the default) reproduces the pre-config wire exactly: the
         * merged array is empty and the existing empty-knob filter still
         * drops `chat_template_kwargs` from the body.
         *
         * @param array<string, mixed> $extraTemplateKwargs
         */
        private array $extraTemplateKwargs = [],
        /**
         * Raw session id for cache-affinity routing, held on this readonly
         * instance and hashed per request at send time — the wire never
         * carries the raw value. `null` (the default) means no affinity
         * header at all, byte-identical to the pre-P10.S4 request. Why
         * hashed, why the full digest, and the consumer contract for keeping
         * the id current live in the {@see SessionAffinity} trait docblock.
         */
        private ?string $sessionAffinityId = null,
        /**
         * Audit 15a A18: reads the live server's limits
         * ({@see SglangServerInfo::discover()}), run at most ONCE per
         * provider by {@see serverInfo()}. Null (the default) means "never
         * ask", which keeps a directly-constructed provider - every test, every
         * embedder - free of network I/O beyond the completions it is asked
         * for, and leaves the transcribed per-family figures in charge.
         * {@see ProviderFactory::createSglang()} arms it.
         *
         * @var (\Closure(): ?SglangServerInfo)|null
         */
        private ?\Closure $serverInfoLoader = null,
        /**
         * Audit 15b-15: the `sglang` provider block's `supportsVision` key -
         * true/false overrides what discovery says, null (the default) defers
         * to {@see SglangServerInfo::$imageUnderstanding}. See
         * {@see supportsVision()}.
         */
        private ?bool $supportsVision = null,
    ) {
        if ($this->reasoningEffort !== null) {
            self::validatedReasoningEffort($this->reasoningEffort, 'provider config');
        }

        $this->truncationRiskWarned = new \ArrayObject();
        $this->serverInfoMemo = new \ArrayObject();
    }

    /**
     * @param bool $discoverServerInfo Audit 15a A18: arm a once-per-provider
     *        read of the server's `/model_info` + `/server_info`
     *        ({@see SglangServerInfo::discover()}). Off by default so this
     *        factory stays I/O-free at construction AND afterwards unless a
     *        caller opts in; {@see ProviderFactory::createSglang()} opts in
     *        unless the config block says `"discoverServerInfo": false`.
     */
    public static function openAiCompatible(
        string $baseUrl,
        string $model = self::DEFAULT_MODEL,
        ?string $apiKey = null,
        ?ToolCallParserInterface $toolCallParser = null,
        string|float|null $reasoningEffort = null,
        array $extraTemplateKwargs = [],
        ?string $sessionAffinityId = null,
        bool $discoverServerInfo = false,
        ?bool $supportsVision = null,
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

        $serverInfoLoader = $discoverServerInfo
            ? static fn (): ?SglangServerInfo => SglangServerInfo::discover($baseUrl, $apiKey)
            : null;

        return new self(
            $baseUrl,
            $model,
            $apiKey,
            $client,
            $toolCallParser,
            $reasoningEffort,
            $extraTemplateKwargs,
            $sessionAffinityId,
            $serverInfoLoader,
            $supportsVision,
        );
    }

    /**
     * Audit 15a A18: what the server said about itself, read once per
     * provider and memoised - null when no loader is armed or the read failed.
     *
     * LAZY, AND CALLED FROM TWO PLACES ON PURPOSE. Construction must stay
     * I/O-free (a provider is built for `doctor`, the model picker and a
     * dozen tests that never complete anything). The first caller is
     * normally {@see contextWindow()} on the TUI's first frame, in the PARENT
     * process - which matters, because every turn runs in a `pcntl_fork()`ed
     * child: a read that happened only inside a turn would die with the child
     * and the parent's context tiers would never see it. Reading in the
     * parent once means every later child inherits the memo. The request
     * path ({@see buildParams()}) is the second caller, for `-p` runs that
     * never render a frame. A failed read is memoised too, so an unreachable
     * server costs one bounded attempt
     * ({@see SglangServerInfo::DISCOVERY_TIMEOUT_SECONDS}), not one per frame.
     */
    public function serverInfo(): ?SglangServerInfo
    {
        if ($this->serverInfoLoader === null) {
            return null;
        }

        if (!isset($this->serverInfoMemo['loaded'])) {
            $this->serverInfoMemo['loaded'] = true;

            try {
                $info = ($this->serverInfoLoader)();
            } catch (\Throwable) {
                $info = null;
            }

            $this->serverInfoMemo['info'] = $info instanceof SglangServerInfo ? $info : null;

            if ($info instanceof SglangServerInfo) {
                $this->warnAboutServerMismatch($info);
            }
        }

        $info = $this->serverInfoMemo['info'] ?? null;

        return $info instanceof SglangServerInfo ? $info : null;
    }

    /**
     * The two discovered facts that mean this provider is about to behave
     * wrongly, surfaced ONCE (discovery runs once) on both channels of
     * {@see RuntimeNoticeSink::warn()}.
     *
     * 1. A model FAMILY mismatch. Sampling, reasoning-effort placement, the
     *    default tool-call parser and the fallback window are all keyed on the
     *    CONFIGURED id; if the server serves another family every one of them
     *    is the wrong family's (DeepSeek's top-level `reasoning_effort: max`
     *    400s on every thinking-on Qwen3.8 request, qwen.md E-41). Judged by
     *    family, not by string, so `Qwen/Qwen3.8-Flash-Next` configured
     *    against `Qwen/Qwen3.8-Flash-Next-FP8` served - the repo's own
     *    dev-sglang block today - stays quiet.
     * 2. A server that reports `tool_call_parser: null` while this provider
     *    has no textual fallback armed: every tool call then arrives as raw
     *    markup in `content` and the agent silently does nothing.
     */
    private function warnAboutServerMismatch(SglangServerInfo $info): void
    {
        $served = $info->servedModelName;
        // Audit A26: a provider built on the DEFAULT id adopts the served
        // model ({@see addressedToServedModel()}), so there is no configured
        // id for the served one to contradict. Before, the generic default
        // drew this notice against skynet2 on every launch that relied on it.
        if ($served !== null
            && !$this->adoptsServedModel()
            && self::modelFamily($served) !== self::modelFamily($this->model)
        ) {
            RuntimeNoticeSink::warn(sprintf(
                'SGLang server %s serves "%s" but the configured model is "%s"; '
                . 'sampling, reasoning effort and the default tool-call parser follow the '
                . 'configured id, so set "model" to the served one.',
                SglangServerInfo::rootUrl($this->baseUrl),
                $served,
                $this->model,
            ));
        }

        if ($info->reportsToolCallParser
            && $info->toolCallParser === null
            && !$this->parserFor($served) instanceof EnvelopeAware
        ) {
            RuntimeNoticeSink::warn(sprintf(
                'SGLang server %s was launched without --tool-call-parser, so tool calls arrive '
                . 'as raw text; set "toolCallParser" to "dsml" or "minimax-xml-fallback" to '
                . 'recover them, or relaunch the server with the parser for its model.',
                SglangServerInfo::rootUrl($this->baseUrl),
            ));
        }
    }

    /**
     * The family a model id belongs to, for {@see warnAboutServerMismatch()}:
     * the same two substring predicates every family default here uses, so
     * "same family" cannot mean something different in the notice than in
     * the behaviour it warns about.
     */
    private static function modelFamily(string $model): string
    {
        return match (true) {
            self::isDeepSeekV4($model) => self::DEEPSEEK_V4_FAMILY_TOKEN,
            self::isQwen3Next($model) => self::QWEN3_NEXT_FAMILY_TOKEN,
            // Every other id is ONE bucket: two unknown-family ids carry the
            // same (legacy) behaviour, so a mere spelling difference between
            // them - an org prefix, a quantisation suffix - is not worth a
            // notice. An alias like `default` configured against a served
            // DeepSeek-V4 still differs, which is the under-match the
            // DEEPSEEK_V4_FAMILY_TOKEN docblock said nothing could detect.
            default => 'other',
        };
    }

    /**
     * Audit A26: whether this provider treats the server's own model as the
     * one to talk to - discovery armed AND built on {@see DEFAULT_MODEL},
     * which is the id {@see ProviderFactory::defaultConfig()} stamps when
     * nobody named a model. A provider built on any other id was given a
     * model and keeps it.
     */
    private function adoptsServedModel(): bool
    {
        return $this->serverInfoLoader !== null && $this->model === self::DEFAULT_MODEL;
    }

    /**
     * Audit 15b-35: the served model this provider adopts, for the TUI's
     * labels - read from what {@see serverInfo()} has ALREADY memoised (the
     * parent's first frame normally loads it, via {@see contextWindow()}),
     * else from what a forked turn child reported ({@see noteServedModel()}).
     * Never runs the loader: see {@see ReportsServedModel::servedModel()}.
     *
     * A name discovered here wins over a reported one, because it is this
     * process's own reading of the server; the reported one only fills the
     * gap a parent that never (or unsuccessfully) asked would otherwise show.
     */
    public function servedModel(): ?string
    {
        if (!$this->adoptsServedModel()) {
            return null;
        }

        $info = $this->serverInfoMemo['info'] ?? null;
        $discovered = $info instanceof SglangServerInfo ? $info->servedModelName : null;
        if (is_string($discovered) && $discovered !== '') {
            return $discovered;
        }

        $reported = $this->serverInfoMemo['reportedServedModel'] ?? null;

        return is_string($reported) && $reported !== '' ? $reported : null;
    }

    /**
     * Audit 15b-35: remember the served name a forked turn child discovered,
     * so the parent's labels can show it. Kept apart from the `info` memo on
     * purpose: it is a label fact, and it neither marks discovery as done nor
     * changes what {@see addressedToServedModel()} sends.
     */
    public function noteServedModel(string $servedModel): void
    {
        if ($servedModel === '' || !$this->adoptsServedModel()) {
            return;
        }

        $this->serverInfoMemo['reportedServedModel'] = $servedModel;
    }

    /**
     * Audit A26: `$request` re-addressed to the model the server reports
     * serving, when this provider {@see adoptsServedModel()} and the request
     * names the default id - otherwise `$request` itself.
     *
     * ON THE REQUEST, NOT ONLY IN THE BODY'S `model` FIELD, because every
     * family default in {@see buildParams()} (temperature, `top_p`, reasoning
     * effort and where it is placed, the output cap) and the Qwen content
     * cosmetics judge `$request->model`: re-addressing the wire field alone
     * would send DeepSeek's top-level `reasoning_effort: max` to a served
     * Qwen3.8 if the fallback id were ever DeepSeek again (the 400 qwen.md
     * E-41 measured). The request is rebuilt from its own public fields
     * because {@see CompleteRequest} is a `readonly` value with no wither.
     *
     * The served name is read through the memoised {@see serverInfo()}, so
     * this costs no request beyond the one discovery already makes; when the
     * server could not be asked, the default id is sent as before.
     */
    private function addressedToServedModel(CompleteRequest $request): CompleteRequest
    {
        if ($request->model !== self::DEFAULT_MODEL || !$this->adoptsServedModel()) {
            return $request;
        }

        $served = $this->serverInfo()?->servedModelName;
        if ($served === null || $served === '' || $served === $request->model) {
            return $request;
        }

        return new CompleteRequest(...array_replace(get_object_vars($request), ['model' => $served]));
    }

    /**
     * The parser used when the operator named none, chosen from the MODEL:
     * DSML wrapped around the OpenAI-array parser for the DeepSeek-V4 family,
     * the OpenAI-array parser alone otherwise. The one definition of that
     * rule - {@see ProviderFactory::createSglang()} asks it at build time for
     * a configured model, {@see resolvedToolCallParser()} at request time for
     * an adopted served one. {@see ProviderFactory}'s private
     * `defaultToolCallParserFor()` docblock carries the argument for arming
     * DSML by default on that family.
     */
    public static function defaultToolCallParserFor(string $model): ToolCallParserInterface
    {
        $openAi = OpenAiArrayToolCallParser::new(self::argumentDecoder());

        return self::isDeepSeekV4($model) ? DsmlToolCallParser::new($openAi) : $openAi;
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

    /**
     * Audit 15b-15: whether an attached image may go out as an `image_url`
     * part. The provider block's `supportsVision` wins when set; otherwise the
     * SERVER answers - `/model_info`'s `has_image_understanding`, read by the
     * same memoised discovery as the context window - and a server that did
     * not say (discovery off, failed, or an older SGLang) counts as no
     * vision. Erring that way costs an image its pixels, never the turn: the
     * image becomes a named text placeholder and the user is told
     * ({@see \SugarCraft\Crush\Backend\EngineBackend::toTypedMessages()}),
     * where a guessed "yes" would send a text-only model a part its chat
     * template rejects.
     */
    public function supportsVision(): bool
    {
        if ($this->supportsVision !== null) {
            return $this->supportsVision;
        }

        return $this->serverInfo()?->imageUnderstanding === true;
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
     * AND NOW LIVE FIRST (audit 15a A18, 2026-10-02). When a discovery loader
     * is armed ({@see ProviderFactory::createSglang()} arms it) and the server
     * answered, the window is DERIVED from what it reported:
     * `min(context_length, max_req_input_len − 4096)`
     * ({@see SglangServerInfo::inputWindow()}, headroom
     * {@see CONTEXT_WINDOW_OUTPUT_HEADROOM}) - the same formula the Qwen3.8
     * constant below was derived with, over today's figures instead of
     * 2026-09-01's. On skynet2 that is `min(1,000,000, 999,994 − 4,096)` =
     * **995,898**, where the transcription still says 744,506. The first call
     * performs the read (bounded by
     * {@see SglangServerInfo::DISCOVERY_TIMEOUT_SECONDS}, once per provider -
     * see {@see serverInfo()} for why here and not at construction).
     *
     * The three transcribed figures below are what remains when there is no
     * loader or the server could not be asked. They are FALLBACKS now, and
     * the Qwen3.8 one is knowingly stale (conservative: erring small only
     * compacts earlier):
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
     *
     * - {@see QWEN3_NEXT_CONTEXT_WINDOW} = 744,506 for the Qwen3.8 family
     *   (qwen.md Q2): NOT the nominal 1,000,000 model length but the
     *   CONSERVATIVE effective-input cap `min(1_000_000, 748_602 − 4096)`,
     *   because `allow_auto_truncate=false` makes an over-long request a
     *   hard error (E-71) - this arm rides under the enforced input ceiling
     *   so the tiers fire early rather than the server refusing the request.
     *   That constant's docblock is the long form of the arithmetic.
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
     * the Qwen3.8 arm at ~521,154 / ~632,830 / ~707,280; on the legacy arm
     * at ~137,625 / ~167,116 / ~186,777, unchanged.
     *
     * Those three figures per arm are 70/85/95% of 1,048,570, of 744,506 and
     * of 196,608 respectively and of nothing else. The DeepSeek set was last
     * written as
     * ~275,251 / ~334,233 / ~373,555 - the same percentages of the superseded
     * 393,216 - and a derived figure left behind after its input moves is this
     * project's signature defect. Recompute them whenever the constant moves.
     * {@see \SugarCraft\Crush\Runtime::shouldPromptIdleCompaction()} reads
     * it too.
     */
    public function contextWindow(): int
    {
        $discovered = $this->serverInfo()?->inputWindow(self::CONTEXT_WINDOW_OUTPUT_HEADROOM);
        if ($discovered !== null) {
            return $discovered;
        }

        if (self::isDeepSeekV4($this->model)) {
            return self::DEEPSEEK_V4_CONTEXT_WINDOW;
        }

        if (self::isQwen3Next($this->model)) {
            return self::QWEN3_NEXT_CONTEXT_WINDOW;
        }

        return self::LEGACY_DEFAULT_CONTEXT_WINDOW;
    }

    public function costPer1kTokens(string $model, string $direction): float
    {
        // SGLANG models are typically self-hosted, low cost
        return 0.0;
    }

    public function complete(CompleteRequest $request): CompleteResponse
    {
        $request = $this->addressedToServedModel($request);
        $params = $this->buildParams($request);

        try {
            // 'headers' here (not client defaults) so the affinity header also
            // rides injected clients - see SessionAffinity::sessionAffinityHeaders().
            // heartbeatOptions() is [] unless E493's caller supplied a
            // progress closure, so the spread is byte-neutral otherwise.
            $response = $this->httpClient->post('chat/completions', [
                'json' => $params,
                'headers' => $this->sessionAffinityHeaders($request->sessionId),
            ] + self::heartbeatOptions($request->onHeartbeat));

            $data = json_decode($response->getBody()->getContents(), true);

            return $this->parseResponse(
                $data,
                $this->contentThinkingEnabled($request),
                $this->toolCallParserFor($request),
            );
        } catch (GuzzleException $e) {
            // §Q8 (qwen.md; E-56): surface the server's own `error.message`
            // when the response carries one instead of Guzzle's raw-body dump
            // (GuzzleException messages are the request/status line plus the
            // body clipped to ~2KB - unreadable in Chat). Prefix, exception
            // class, code 0 and the previous-chain $e are byte-stable, so
            // TransientFailure::isTransient() classification (400 permanent,
            // 5xx/408/429 retried) is untouched.
            throw new \RuntimeException(
                'SGLANG request failed: ' . (self::errorBodyMessage($e) ?? $e->getMessage()),
                0,
                $e
            );
        }
    }

    public function completeStream(CompleteRequest $request): \Generator
    {
        $request = $this->addressedToServedModel($request);
        $params = $this->buildParams($request);
        $params['stream'] = true;
        // §Q6 (qwen.md; E-27/E-30/E-55): ask for the usage this deployment
        // knows. OpenAI-compatible servers emit NO usage object on a bare
        // stream; with `include_usage` they append exactly one terminal
        // `{"choices":[],"usage":{...}}` frame before `[DONE]` (measured live
        // 2026-09-04, tests/fixtures/qwen-usage-stream.txt). Set HERE, next to
        // `stream`, not inside buildParams(): buildParams is shared with
        // complete(), and the batch body must stay byte-identical to pre-§Q6.
        $params['stream_options'] = ['include_usage' => true];

        try {
            $response = $this->httpClient->post('chat/completions', [
                'json' => $params,
                'stream' => true,
                'headers' => $this->sessionAffinityHeaders($request->sessionId),
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

            // Audit 15a A9: the parser that recovery uses, carrying THIS
            // request's tool schemas - resolved once per stream, not per
            // chunk, and the same resolution complete() uses, so the batch
            // and streaming paths type a recovered argument identically.
            $toolCallParser = $this->toolCallParserFor($request);

            // Audit 15a A8: the HOLD-BACK. A parser that recovers calls from
            // markup in the text names the literals that can open an
            // envelope; from the first byte that could be one, content stops
            // being yielded and waits here, because a painted chunk cannot be
            // retracted once the envelope turns out to be a recovered call.
            // The end of the stream decides: a recovered envelope is cut, any
            // other held text is released verbatim. {@see EnvelopeHoldBack}
            // states what that costs. The default OpenAI-array parser names
            // no markers, so its stream is not held at all. Locals for the
            // same `final readonly class` reason as $toolCallBuffer.
            $holdBackMarkers = $toolCallParser instanceof EnvelopeAware ? $toolCallParser->envelopeMarkers() : [];
            $heldContent = '';
            $emittedLength = 0;
            $envelopeOpened = false;

            // The usage document from the §Q6 terminal frame, held (not
            // yielded) until the stream is drained, then emitted once as the
            // final chunk below. Last-write-wins on purpose: the protocol
            // sends exactly one such frame (E-30), but a second - buggy or
            // keepalive-shaped - must not double-bill the turn, because
            // Runtime SUMS per-chunk usage across the stream. A local for the
            // same `final readonly class` reason as $toolCallBuffer above.
            $streamUsage = null;

            // §Q9: the thinking gate (request-scoped kwargs — never recoverable
            // from a single frame) and the first-content cursor, threaded by ref
            // into parseChunk(). Locals, not properties: final readonly class,
            // lifetime exactly one generator call — same reason as $toolCallBuffer.
            $contentThinkingOn = $this->contentThinkingEnabled($request);
            $seenFirstContent = false;

            // The LAST non-null `finish_reason` seen on any data frame, read
            // after the loop by the §Q7 flush guard. Captured per frame here
            // rather than inside parseChunk() because parseChunk() is also a
            // reflection-tested unit (SglangProviderTruncationGuardTest) and
            // the end-of-stream decision belongs to the generator. A frame
            // without the key - or with it null, which is what every
            // pre-finish chunk carries on this wire - keeps the previous
            // value via `??`; the terminal frame is the one that speaks.
            // `matched_stop` is deliberately never consulted: E-32 measured
            // it as ignorable noise that can ride a `tool_calls` finish.
            $streamFinishReason = null;

            // Audit 15a A3: whether the `data: [DONE]` sentinel arrived. With
            // $streamFinishReason it is how the end of the loop tells a
            // stream the server CLOSED from one the transport CUT - Guzzle's
            // StreamHandler reports a dropped connection as a plain eof().
            $sawDone = false;

            // GuzzleHttp\Psr7\Stream has no readLine() - it implements only
            // the plain PSR-7 StreamInterface. Buffer raw chunks and split on
            // "\n" ourselves (same approach as CustomProvider::completeStream()).
            while (!$stream->eof()) {
                $buffer .= $stream->read(8192);

                while (($newlinePos = strpos($buffer, "\n")) !== false) {
                    $line = trim(substr($buffer, 0, $newlinePos));
                    $buffer = substr($buffer, $newlinePos + 1);

                    // Audit 15a A5: `data:` with or without the one optional
                    // space - the spec's reading, which some gateways rely on.
                    $payload = SseData::value($line);
                    if ($payload !== null) {
                        $sawDone = $sawDone || $payload === '[DONE]';
                        $data = json_decode($payload, true);
                        // Audit 15a A2: an error raised after the 200 went out
                        // (context overflow, abort, OOM) arrives as an SSE
                        // frame, not a status. It matches neither branch
                        // below, so it used to be dropped and the turn ended
                        // as a "success" holding whatever had streamed. Stop
                        // reading and throw - this provider's convention -
                        // with a verdict TransientFailure reads directly.
                        $streamError = ProviderStreamException::fromErrorEvent($data, 'SGLANG request failed: ');
                        if ($streamError !== null) {
                            $stream->close();

                            throw $streamError;
                        }
                        // §Q7: read on EVERY decoded frame, before the delta
                        // gate - a finish frame the delta branch skips (no
                        // `delta` key at all) still has to be seen, or a
                        // clean `stop` end would masquerade as a hard cut
                        // and arm the flush. `[DONE]` decodes to null and
                        // the whole chain short-circuits to the previous
                        // value.
                        $streamFinishReason = $data['choices'][0]['finish_reason'] ?? $streamFinishReason;
                        // Audit 15a A4: a finish frame may carry `"delta":
                        // null` (or no delta at all). It still has to reach
                        // parseChunk(), because a `tool_calls` finish is what
                        // drains the fragment buffer through the normal
                        // assembly path - skipping it used to lose every
                        // streamed call on that shape.
                        $hasDelta = $data !== null && isset($data['choices'][0]['delta']);
                        $isBareFinish = !$hasDelta
                            && is_array($data['choices'][0] ?? null)
                            && isset($data['choices'][0]['finish_reason']);
                        if ($hasDelta || $isBareFinish) {
                            $chunk = $this->parseChunk($data, $toolCallBuffer, $contentThinkingOn, $seenFirstContent);

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

                                // A8: by the same argument nothing held can
                                // be an envelope this turn acts on, so it is
                                // released now, ahead of this chunk's own
                                // text, and holding stops for the rest of
                                // the stream.
                                $visible = $heldContent . $chunk->content;
                                $heldContent = '';
                                $holdBackMarkers = [];
                            } else {
                                $assembledContent .= $chunk->content;

                                if ($envelopeOpened) {
                                    // Appended without a rescan, so a long
                                    // envelope (a `write` of a large file)
                                    // stays linear.
                                    $heldContent .= $chunk->content;
                                    $visible = '';
                                } else {
                                    [$visible, $heldContent, $envelopeOpened] = EnvelopeHoldBack::split(
                                        $heldContent . $chunk->content,
                                        $holdBackMarkers,
                                    );
                                }
                            }

                            $emittedLength += \strlen($visible);

                            // Yielded in wire order; only its TEXT can be
                            // held back (A8), never its reasoning or calls,
                            // and a chunk whose text is entirely held is
                            // still yielded, just as an empty-content delta
                            // always was - Runtime announces every
                            // content-less chunk as progress, so a long held
                            // envelope keeps the turn's idle deadline reset
                            // instead of looking like a stall. A delta-less
                            // finish frame carries no text, so it is yielded
                            // only when it assembled calls - otherwise it
                            // would be a new, empty chunk the stream never
                            // used to produce.
                            if ($hasDelta || $chunk->toolCalls !== null) {
                                yield $visible === $chunk->content ? $chunk : self::withContent($chunk, $visible);
                            }
                        } elseif ($data !== null && !isset($data['choices'][0]) && is_array($data['usage'] ?? null)) {
                            // §Q6 (E-27's other half): the terminal
                            // zero-choice usage frame the `include_usage`
                            // request arm above produces. The delta gate
                            // used to DROP this line; it is now kept for the
                            // single final yield below - parsed through the
                            // SAME parseUsage seam as the batch path, so the
                            // two arms cannot disagree about the wire.
                            // Narrow on purpose: `choices` must be absent or
                            // empty AND `usage` must be an array, so the
                            // review-5 phantom-empty-chunk shape survives -
                            // a zero-choice frame with no usage document
                            // (keepalive, or `"usage":null` as the captured
                            // DeepSeek stream emits mid-delta) yields nothing.
                            $streamUsage = $this->parseUsage($data['usage']);
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
            // not an answer - "The fix is to chan" used to come back as a
            // final, untruncated reply. Throw a TRANSIENT failure, this
            // provider's convention: Runtime retries it while nothing has
            // reached the screen and surfaces it otherwise. It deliberately
            // precedes the §Q7 flush below - a tool call salvaged from a
            // dropped connection must not execute when a retry yields the
            // whole, intended call.
            if ($streamFinishReason === null && !$sawDone) {
                $stream->close();

                throw ProviderStreamException::prematureEnd('SGLANG request failed: ');
            }

            // §Q7 (E-32) + audit 15a A4: the silent-loss window. The
            // structured path assembles only on `finish_reason: "tool_calls"`,
            // so a buffer still holding fragments here was never emitted.
            // Two kinds of end leave it that way:
            //
            // - TRUNCATED (§Q7): the turn was cut off (`length`/`abort`), or
            //   the server closed with `[DONE]` but never sent a finish frame
            //   (`null`; a stream with neither threw above).
            // - CLEAN BUT UNDECLARED (A4): `stop` - or any other reason that
            //   is neither truncating nor `tool_calls` - after tool-call
            //   deltas. vLLM historically, and some SGLang tool_choice /
            //   reasoning-parser combinations and proxies, end a tool-call
            //   stream this way. qwen.md §Q7(c) used to pin `stop` as
            //   byte-unchanged, i.e. the call stayed lost; A4 supersedes that,
            //   because a model's tool request vanishing into an empty reply
            //   is the worse failure.
            //
            // Both flush best-effort through the same decode-or-drop rule:
            // args that decode to a complete JSON object are emitted,
            // everything else is dropped with a warning naming it (never
            // half-decoded) - on a clean end the server never said the call
            // was finished, so an undecodable payload is no safer to run than
            // a cut one. The flush is a no-op after a `tool_calls` end, which
            // drains the buffer mid-stream. `error` is the one end that does
            // NOT flush: the server is reporting that its own generation
            // failed, so nothing it streamed is vouched for, and the error
            // surface belongs to Q8/A2 rather than to tool execution.
            $streamEndedTruncated = $streamFinishReason === null
                || in_array($streamFinishReason, self::TRUNCATED_FINISH_REASONS, true);
            $flushedToolCalls = $toolCallBuffer !== [] && $streamFinishReason !== 'error'
                ? self::flushTruncatedToolCalls(
                    $toolCallBuffer,
                    $streamEndedTruncated ? null : $streamFinishReason,
                )
                : null;

            if ($flushedToolCalls !== null) {
                // The structured path DID produce calls after all - which
                // disarms recoverTextualToolCalls() below exactly as a
                // mid-stream assembly would, so the flushed call and a text
                // envelope parsed out of the same turn can never both
                // execute.
                $sawStructuredToolCalls = true;
            }

            $recovery = $this->recoverTextualToolCalls($assembledContent, $sawStructuredToolCalls, $toolCallParser);
            $recovered = $recovery?->calls();

            if ($recovered !== null) {
                // Audit 15a A8: the content is what was held back with every
                // recovered envelope cut out - normally nothing at all, or
                // the prose a model wrote after its call. What was yielded
                // above stays as it was ({@see TextualRecovery::
                // contentWithoutEnvelopes()} never reaches back past
                // $emittedLength), and {@see \SugarCraft\Crush\Runtime::
                // runStreaming()} appends every chunk's content to its
                // buffer, so the turn's text ends up as the prose without
                // the markup - painted once, and sent back to the model
                // once, as the structured call alone.
                // `tokensUsed: 0` / `costUsd: 0.0` make
                // {@see \SugarCraft\Crush\Usage::reported()} return null for
                // this chunk, which {@see \SugarCraft\Crush\Usage::sum()}
                // skips - so a recovered turn's usage total is unchanged, and
                // in particular a turn that reported nothing still sums to
                // null rather than to zero.
                yield new CompleteResponse(
                    content: $recovery->contentWithoutEnvelopes($assembledContent, $emittedLength),
                    reasoning: null,
                    toolCalls: $recovered,
                    tokensUsed: 0,
                    costUsd: 0.0,
                );
            } elseif ($heldContent !== '') {
                // A8: what was held was not a recovered envelope after all -
                // prose quoting the markup, a `<` that opened nothing, a
                // trailing blank line. Released verbatim, ahead of the flush,
                // truncation and usage frames, so the transcript holds every
                // byte the model wrote, in order.
                yield new CompleteResponse(content: $heldContent);
            }

            if ($flushedToolCalls !== null) {
                // §Q7/A4 flush frame: shaped exactly like the recovery frame
                // above - empty content (the fragments streamed as tool
                // deltas, not text; repeating anything here would double the
                // transcript), zero billing (usage arrives on the terminal
                // chunk below, and `Usage::sum()` skips zero-chunks), and
                // `truncated` stating what the wire said: true after a cut
                // end, so a consumer can tell a flushed call from a cleanly
                // assembled one, and false after a clean `stop`-style end
                // (A4) - that stream was not cut, and flagging it would arm
                // the length-stop fold for a reply that ended normally.
                // Riding BEFORE the §Q6 terminal usage chunk keeps the bill
                // the stream's last event (that yield's docblock states the
                // ordering rule) and mirrors wire order, where finish
                // precedes the usage frame (E-26's channel order).
                yield new CompleteResponse(
                    content: '',
                    reasoning: null,
                    toolCalls: $flushedToolCalls,
                    tokensUsed: 0,
                    costUsd: 0.0,
                    truncated: $streamEndedTruncated,
                );
            } elseif (in_array($streamFinishReason, self::TRUNCATED_FINISH_REASONS, true)) {
                // E707 (round 81): the other half of the same truth. A
                // `length`/`abort` end with NOTHING left to flush used to
                // lose the stop signal entirely - the text chunks that
                // streamed were indistinguishable from a clean turn by the
                // time they reached the fold. One flag-only frame (empty
                // content, zero billing, same inertness argument as the flush
                // above) states it. A `[DONE]` with NO finish frame is NOT
                // included: the flag's contract is what the wire said, and a
                // hard cut with neither already threw (audit 15a A3).
                yield new CompleteResponse(content: '', truncated: true);
            }

            if ($streamUsage !== null) {
                // §Q6 final chunk: the streamed result carries the usage as a
                // trailing usage-only CompleteResponse - content '', so the
                // transcript buffer is untouched, and `Runtime::runStreaming()`
                // already folds one of these per-chunk into the turn's
                // Usage::sum() (the same channel Vertex's message_start /
                // message_delta pair bills through; the E456 comment there
                // names "usage-only" as an expected chunk shape). Emitted
                // last, after any recovery chunk, so the bill is the stream's
                // terminal event and `[DONE]` stays inert. The parsed Usage
                // rides out WHOLE on `usage:` since the E17 fold - buckets
                // incl. reasoning_tokens (fixture: 57/28/85/25) reach Runtime
                // through this same usage-only channel; `tokensUsed`/`costUsd`
                // stay the exact projections they were, both for older
                // carrier readers and as the fold's fallback figure. A stream
                // that reports nothing never sets $streamUsage, so this yield
                // is skipped and the turn still sums to null, not zero.
                yield new CompleteResponse(
                    content: '',
                    reasoning: null,
                    toolCalls: null,
                    tokensUsed: $streamUsage->totalTokens,
                    costUsd: $streamUsage->costUsd,
                    usage: $streamUsage,
                );
            }
        } catch (GuzzleException $e) {
            // §Q8 (qwen.md; E-56): surface the server's own `error.message`
            // when the response carries one instead of Guzzle's raw-body dump
            // (GuzzleException messages are the request/status line plus the
            // body clipped to ~2KB - unreadable in Chat). Prefix, exception
            // class, code 0 and the previous-chain $e are byte-stable, so
            // TransientFailure::isTransient() classification (400 permanent,
            // 5xx/408/429 retried) is untouched.
            throw new \RuntimeException(
                'SGLANG request failed: ' . (self::errorBodyMessage($e) ?? $e->getMessage()),
                0,
                $e
            );
        }
    }

    /**
     * Audit A17: a transport failure or a 2xx body that is not an embeddings
     * payload THROWS instead of returning an empty list. The old silent `[]`
     * made an outage (model not loaded, 5xx, refused connection) look exactly
     * like "no results", so a semantic-search consumer degraded without ever
     * telling the user why. The wrap mirrors complete()'s: the server's own
     * `error.message` when it sent one, code 0, and the Guzzle exception as
     * previous so TransientFailure::isTransient() still classifies 5xx/429/
     * connect as transient and 400 as permanent. A present-but-empty
     * `data: []` remains a legitimate empty result.
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
                'SGLANG embeddings request failed: ' . (self::errorBodyMessage($e) ?? $e->getMessage()),
                0,
                $e
            );
        }

        return new EmbeddingsResponse(
            embeddings: self::embeddingVectors(
                json_decode($response->getBody()->getContents(), true),
                'SGLANG',
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
            // formatMessages() owns the single leading system row: the
            // assembled prompt (Runtime::buildSystemPrompt()'s seven layers,
            // arriving on CompleteRequest::$systemPrompt) joined with any
            // history SystemMessages — see its docblock for why the merge
            // lives there (Q5/E-10) and why '' counts as unset here, the same
            // convention as the optional-knob filter below.
            //
            // $request->model rides along so the AssistantMessage arm can
            // replay `reasoning_content` on tool-call rows for the families
            // whose templates read it back (step 0.1, see formatMessages()).
            'messages' => $this->formatMessages($request->messages, $request->systemPrompt, $request->model),
            // Model-aware since DeepSeek-V4 became the default: this used to
            // be a flat `?? 0.7`, which is DeepSeek-V4-Flash's card-prescribed
            // 1.0 minus 0.3. Keyed on $request->model, the id this body is
            // addressed to - see defaultTemperature().
            'temperature' => $request->temperature ?? self::defaultTemperature($request->model),
            // Placeholder for the key's POSITION only: the default needs the
            // finished body to estimate the prompt, so it is filled in just
            // before the return - see defaultMaxTokens() (audit 15a A18).
            // An explicit value (`maxOutputTokens`) is sent untouched.
            'max_tokens' => $request->maxTokens ?? self::LEGACY_DEFAULT_MAX_TOKENS,

            // Pin SGLang's reasoning-splitting behavior explicitly rather than
            // relying on its (currently true) default - see D3/D4: this is
            // what tells a properly-splitting parser (the deployed `minimax`
            // one) to populate `reasoning_content` at all. It's a no-op for
            // `minimax-append-think`, which is exactly why extractReasoning()'s
            // <think>-stripping fallback still matters regardless.
            'separate_reasoning' => true,
        ];

        // Q4 (qwen.md §Q4): the Qwen3.8 family routes its effort INTO
        // chat_template_kwargs, sanitized to the template's own vocabulary,
        // and stops sending the top-level field. Everything else — resolve
        // (request > config > model), validate, place — is shared by both
        // families, so the tiers keep exactly one implementation.
        $effort = $this->resolveReasoningEffort($request);
        $isQwen = self::isQwen3Next($request->model);
        $effortTemplateKwargs = [];
        if ($isQwen) {
            // "Thinking on" is whatever the two caller-facing kwargs layers
            // declare (config then DTO, Q3's merge); the template's own
            // default is ON, and only an explicit false counts as OFF — see
            // sanitizeEffortForTemplate() for why sanitizing does NOT switch
            // off with it.
            $declaredKwargs = $this->mergedTemplateKwargs($request);
            $effortTemplateKwargs = self::sanitizeEffortForTemplate(
                $effort,
                ($declaredKwargs['enable_thinking'] ?? true) !== false,
            );
        }

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
            // Config kwargs merged with the per-request DTO's, with the
            // sanitized Qwen effort between them - see the merge method for
            // the precedence and its sentinel semantics.
            'chat_template_kwargs' => $this->mergedTemplateKwargs($request, $effortTemplateKwargs),
            // For every family EXCEPT Qwen this is top-level, NOT under
            // chat_template_kwargs. Those two are different mechanisms and
            // the difference matters here: `chat_template_kwargs` feeds a
            // server-side Jinja chat template, and DeepSeek-V4-Flash ships
            // none, so routing effort through it would be silently dropped.
            // `reasoning_effort` is a field on SGLang's own
            // ChatCompletionRequest - proven by the fact that a bogus value
            // is REJECTED 400 by its pydantic model rather than ignored
            // (probed 2026-08-20). Qwen3.8's deployment DOES ship such a
            // template and reads effort there (qwen.md E-42), while its
            // pydantic-side check is the noisy wider enum (E-41) — so Qwen
            // sends the sanitized value through kwargs instead and this
            // entry goes null, which the filter below turns into the key
            // being absent (qwen.md §Q4: never a template-invalid effort).
            'reasoning_effort' => $isQwen ? null : $effort,
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

        if ($request->maxTokens === null) {
            $params['max_tokens'] = $this->defaultMaxTokens($request->model, $params);
        }

        return $params;
    }

    /**
     * Audit 15a A18 (revised, user decision 2026-10-02): the `max_tokens` to
     * send when the caller named none -
     * `min(262144, total_window − estimated_prompt − safety_margin)`.
     *
     * WHERE THE WINDOW COMES FROM, in order:
     * 1. the live server ({@see serverInfo()}): `context_length`, else
     *    `max_req_input_len` ({@see SglangServerInfo::totalWindow()}) - for
     *    ANY model, since the server's own answer needs no family table;
     * 2. the per-family fallback table, keyed on the id this request is
     *    addressed to (the same key the sampling defaults use):
     *    {@see DEEPSEEK_V4_TOTAL_WINDOW}, {@see QWEN3_NEXT_TOTAL_WINDOW};
     * 3. otherwise the pre-A18 {@see LEGACY_DEFAULT_MAX_TOKENS}, unclamped: a
     *    model whose window nobody measured keeps the conservative 4096
     *    rather than a guess at 262,144.
     *
     * WHY CLAMP AT ALL rather than always sending the cap: prompt and output
     * share the window, and SGLang refuses (HTTP 400, "Requested token count
     * exceeds the model's maximum context length") a request whose input plus
     * `max_tokens` overruns it - so a flat 262,144 would break every turn of a
     * session past ~740k tokens on a 1M window, long before the input itself
     * is too big. The prompt is estimated from the finished request body
     * ({@see estimatedPromptTokens()}), with slack for the estimate's error
     * ({@see PROMPT_SAFETY_MARGIN_FLOOR}, {@see PROMPT_SAFETY_MARGIN_DIVISOR})
     * and a floor ({@see MIN_DEFAULT_MAX_TOKENS}) for a prompt already at the
     * edge.
     *
     * The settings key `maxOutputTokens` still wins outright: it arrives as
     * `$request->maxTokens` and this method is never called.
     *
     * @param array<string, mixed> $params the otherwise-finished request body
     */
    private function defaultMaxTokens(string $model, array $params): int
    {
        $total = $this->serverInfo()?->totalWindow() ?? match (true) {
            self::isDeepSeekV4($model) => self::DEEPSEEK_V4_TOTAL_WINDOW,
            self::isQwen3Next($model) => self::QWEN3_NEXT_TOTAL_WINDOW,
            default => null,
        };

        if ($total === null) {
            return self::LEGACY_DEFAULT_MAX_TOKENS;
        }

        $estimate = self::estimatedPromptTokens($params);
        $margin = max(self::PROMPT_SAFETY_MARGIN_FLOOR, intdiv($estimate, self::PROMPT_SAFETY_MARGIN_DIVISOR));
        $room = $total - $estimate - $margin;

        return max(self::MIN_DEFAULT_MAX_TOKENS, min(self::DEFAULT_OUTPUT_TOKEN_CAP, $room));
    }

    /**
     * The prompt side of {@see defaultMaxTokens()}: a script-weighted estimate
     * ({@see TokenEstimate}) over the JSON of what the server will tokenise -
     * the messages (system row included) and the tool schemas, which the chat
     * template renders into the prompt too. Measured over the WIRE form rather
     * than the message objects so nothing the template sees is missed; JSON's
     * quoting and escaping only push the estimate up, the safe direction.
     *
     * @param array<string, mixed> $params
     */
    private static function estimatedPromptTokens(array $params): int
    {
        $json = json_encode(
            ['messages' => $params['messages'] ?? [], 'tools' => $params['tools'] ?? []],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE,
        );

        return TokenEstimate::ofText(is_string($json) ? $json : '');
    }

    /**
     * Merges the deployment-wide config kwargs
     * ({@see ProviderFactory::createSglang()}) with the per-request DTO's
     * `$extraTemplateKwargs` into the single `chat_template_kwargs` value
     * {@see buildParams()} emits.
     *
     * PRECEDENCE (qwen.md §Q4 collision mandate, stated here because this is
     * the seam it lands on), lowest first: config kwargs < the SANITIZED
     * Qwen effort (`$sanitizedEffortKwargs`) < per-request DTO kwargs.
     * So a deployment that parked a `reasoning_effort` in its config kwargs
     * LOSES to the template-valid value this class computed — the sanitized
     * one is what the served template can actually parse — while an
     * EXPLICIT per-request kwarg overrides BOTH, which keeps Q3's rule that
     * caller-named template keys travel verbatim (the open-vocabulary
     * decision: the template's key space is the server's business, not a
     * closed enum we get to police). The order is three array_merge
     * arguments, so the "who wins" story and the code cannot disagree.
     *
     * Merging stays PER KEY: array_merge's right-hand side overwrites exactly
     * the string keys it carries, so a request flipping `enable_thinking`
     * cannot silently drop a deployment's `preserve_thinking` - a whole-body
     * override would make every per-call template tweak destructive of the
     * others, which no caller asked for.
     *
     * SENTINELS: null AND [] on the DTO both mean "this request names no
     * keys", the same unset convention the optional-knob filter applies to
     * every other field. Consequently the merge is empty - and the existing
     * `$value !== []` filter keeps `chat_template_kwargs` off the wire
     * entirely - exactly when ALL contributing layers are empty: an unwired
     * config, a non-Qwen model (whose $sanitizedEffortKwargs is always []),
     * and an unwired request still reproduce today's byte-identical body.
     *
     * @param array<string, mixed> $sanitizedEffortKwargs the middle layer:
     *        {@see sanitizeEffortForTemplate()}'s output, [] for every model
     *        outside the Qwen3.8 family (which is also the default, so the
     *        Q3-era two-layer callers, e.g. buildParams' thinking probe,
     *        merge config+DTO only).
     * @return array<string, mixed>
     */
    private function mergedTemplateKwargs(CompleteRequest $request, array $sanitizedEffortKwargs = []): array
    {
        return array_merge(
            $this->extraTemplateKwargs,
            $sanitizedEffortKwargs,
            $request->extraTemplateKwargs ?? [],
        );
    }

    /**
     * Translates a resolved `reasoning_effort` into the Qwen3.8
     * `chat_template_kwargs` entries the deployed template can actually
     * parse (qwen.md §Q4; vocabulary E-40, forwarding behaviour E-41,
     * kwargs-reach E-42, thinking switch E-22).
     *
     * THE MAPPING, each leg measured rather than guessed:
     * - `low`/`medium`/`xhigh` pass through — the template's whole set.
     * - `high`/`max` → `xhigh`, `minimal` → `low` — the wider pydantic names
     *   keep their intent instead of 400-ing at the template (E-41); see
     *   {@see QWEN3_NEXT_EFFORT_TEMPLATE_ALIASES} for the judgement.
     * - `none` → drop effort, emit `enable_thinking: false` (when thinking
     *   is on) — the template has no effort-word for "don't think";
     *   `enable_thinking:false` is its switch, and with thinking off the
     *   effort check is skipped and reasoning comes back null (E-22). When
     *   `$thinkingOn` is false the config/DTO already carries the false, so
     *   the sanitizer contributes nothing — same wire value, no redundant
     *   write through the middle layer.
     * - null (only reachable for models without a tier-3 default) → nothing.
     * - ANYTHING ELSE — including a float — throws
     *   {@see InvalidArgumentException} at BUILD time, before the request
     *   leaves: fail-fast over a guaranteed template 400. Floats are the
     *   pydantic side-channel (`constrained-float`, measured 2026-08-20,
     *   top-level field only); under `chat_template_kwargs` they skip
     *   pydantic and hit the template's string-compare raise (E-42), and no
     *   float→level mapping was ever measured, so refusing is the only
     *   honest option.
     *
     * WHY SANITIZING DOES NOT SWITCH OFF WITH `$thinkingOn`: E-41 measured
     * the template SKIPPING its effort check when thinking is off, so an
     * unmapped `high` would survive on the wire — but §Q4's goal is
     * "never send a template-invalid effort", unconditionally, and a
     * deployment that flips thinking back on must not have its request
     * quietly change shape. Mapping regardless is the conservative arm;
     * pinned by testATemplateInvalidEffortIsStillMappedWhenThinkingIsExplicitlyOff.
     *
     * WHY NO NOTICE FOR THE MAPPINGS: {@see RuntimeNoticeSink::warn()} is
     * this class's channel for response-side DATA CORRUPTION the caller
     * cannot otherwise see (truncated tool arguments); a config that names
     * `max` is deterministic, intended vocabulary translation — warning on it
     * would fire on EVERY request of a stable deployment, training readers to
     * ignore the sink. The loud channel stays the build-time throw for
     * values that have NO valid translation. (kwargs-only return shape per
     * that judgement — the spec's "optional notice" resolves to "none, and
     * here is why".)
     *
     * Pure: no I/O, no state — ($effort, $thinkingOn) fully determine the
     * result, so the matrix above is exhaustively testable in isolation.
     *
     * @param  string|float|null $effort the RESOLVED effort — precedence
     *         (request > config > model) is the caller's job
     *         ({@see resolveReasoningEffort()}, untouched by §Q4), pydantic
     *         validation already paid there.
     * @param  bool $thinkingOn whether the effective kwargs declare thinking
     *         on (buildParams derives it: merged config→DTO
     *         `enable_thinking`, absent = the template's default = true).
     * @return array<string, mixed> the kwargs the sanitizer contributes,
     *         [] when it contributes none.
     * @throws \InvalidArgumentException When no template-valid translation
     *         exists (see above).
     */
    private static function sanitizeEffortForTemplate(string|float|null $effort, bool $thinkingOn): array
    {
        if ($effort === null) {
            return [];
        }

        if ($effort === 'none') {
            return $thinkingOn ? ['enable_thinking' => false] : [];
        }

        $templateEffort = is_string($effort)
            ? (self::QWEN3_NEXT_EFFORT_TEMPLATE_ALIASES[$effort] ?? $effort)
            : $effort;

        if (!in_array($templateEffort, self::QWEN3_NEXT_TEMPLATE_EFFORTS, true)) {
            throw new \InvalidArgumentException(sprintf(
                'reasoning_effort %s has no Qwen3.8 chat-template translation; the template accepts %s '
                . '(pydantic names minimal/high/max are mapped automatically, "none" disables thinking instead) '
                . '— floats are a top-level pydantic alternative the template never sees as kwargs (qwen.md E-40/E-41/E-42).',
                var_export($effort, true),
                implode(', ', self::QWEN3_NEXT_TEMPLATE_EFFORTS),
            ));
        }

        return ['reasoning_effort' => $templateEffort];
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
     * True when a model id names the Qwen3.8 family - the ids the skynet2
     * deployment serves as `Qwen/Qwen3.8-Flash-Next` and the bare alias
     * `Qwen3.8-Flash-Next` (qwen.md E-70).
     *
     * Case-insensitive substring, the SAME shape as {@see isDeepSeekV4()};
     * see {@see QWEN3_NEXT_FAMILY_TOKEN} for why the token is `qwen3.8` and
     * not the broader `qwen`.
     *
     * PUBLIC for parity with {@see isDeepSeekV4()} and for the same reason:
     * one family test behind this class's window, effort and (from Q3/Q4 of
     * the qwen.md plan) kwargs routing, so a later consumer never spells a
     * second, independently-drifting substring match for "Qwen3.8".
     */
    public static function isQwen3Next(string $model): bool
    {
        return str_contains(strtolower($model), self::QWEN3_NEXT_FAMILY_TOKEN);
    }

    /**
     * True when $model's chat template reads `reasoning_content` back on an
     * assistant tool-call row, so {@see formatMessages()} must replay it.
     *
     * Composed from the two family predicates rather than a third substring,
     * for the reason {@see isDeepSeekV4()} is public: one family test per
     * family. MiniMax-M2.x stays OUT until its template's handling of the
     * field is measured: an id outside both families gets the pre-0.1 wire,
     * byte for byte.
     */
    private static function replaysReasoning(string $model): bool
    {
        return self::isDeepSeekV4($model) || self::isQwen3Next($model);
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
     * WHAT IT RETURNS decides the VALUE; where the value is PLACED is
     * family-dependent and belongs to {@see buildParams()}: top-level for
     * every family but Qwen3.8, whose resolved value is sanitized into
     * `chat_template_kwargs` instead (qwen.md §Q4 -
     * {@see sanitizeEffortForTemplate()}).
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
     * `xhigh` for the Qwen3.8 family (qwen.md Q2): the chat template's own
     * default and the top of ITS accepted set `xhigh|medium|low` (E-40).
     * DeepSeek's `max` does not carry over - it passes sglang's wider
     * pydantic enum and then 400s at the template on every thinking-on
     * request (E-41), which is exactly what config.dev.json's old `"max"`
     * walked into. Naming `xhigh` rather than omitting the field changes
     * nothing on the wire's BEHAVIOUR - the template applies `xhigh` when
     * effort is absent (E-40) - but records the deployment's intent in the
     * one tier every later request reads, and mirrors what Q1's config key
     * already pins explicitly. (Q4 moved this family's value from the
     * top-level field into `chat_template_kwargs`, where the template reads
     * it - {@see sanitizeEffortForTemplate()} - the tier-3 default itself is
     * unchanged.)
     *
     * NULL for every remaining model, which is the field being omitted
     * entirely -
     * and that asymmetry is deliberate rather than lazy. Two facts about it,
     * kept separate because only one of them is measured:
     *
     * - MEASURED (by absence): `reasoning_effort` appeared nowhere in `src/`,
     *   `bin/`, `tests/` or any config file before this change, so no request
     *   this codebase has ever sent carried one. Omitting it for a
     *   model outside the two named families is therefore the status quo
     *   exactly.
     * - NOT MEASURED: what an effort level would do to MiniMax-M2.x. That
     *   deployment is gone from the confirmed server, so there is no way to
     *   find out here. Sending one anyway, on the strength of a DeepSeek
     *   measurement, would be changing another model's behaviour blind - which
     *   is the one thing this change was explicitly not to do.
     */
    private static function defaultReasoningEffort(string $model): ?string
    {
        if (self::isDeepSeekV4($model)) {
            return self::DEEPSEEK_V4_REASONING_EFFORT;
        }

        if (self::isQwen3Next($model)) {
            return self::QWEN3_NEXT_REASONING_EFFORT;
        }

        return null;
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
     * Formats the transcript AND owns the ONE system row the body may carry.
     *
     * WHY the merge lives here and not beside the prompt prepend: the fix has
     * to cover requests with no systemPrompt at all - the title one-shot
     * (Chat.php :7490) sends a lone history SystemMessage with a null prompt,
     * and notice storms (E-13) stack multiple history rows behind whatever
     * prompt exists. Both inputs are in hand only here, so this method is the
     * single collection point; buildParams() is its only caller with a prompt,
     * and complete()/completeStream() share buildParams(), so both wire paths
     * inherit one seam.
     *
     * THE RULE (E-10, measured on the deployed Qwen template): a system row
     * at index > 0 - or a second system row anywhere - is an HTTP 400
     * "System message must be at the beginning.", and even where a server
     * tolerates it, only messages[0]'s system content is ever rendered. So
     * the body carries at most ONE `system` row, at index 0: the
     * request-level assembled prompt FIRST, then the history SystemMessages
     * that precede the first non-system row, non-empty contents joined with
     * "\n\n", empty-string rows dropped. That joiner and empty-drop rule
     * conform to VertexProvider::systemInstruction() (E-11); sugar-crush
     * points its baseUrl straight at the server, so it cannot lean on
     * opencode's out-of-band merge proxy (E-12).
     *
     * IN PLACE, NOT HOISTED (step 1.A-1). Until 1.A-1 every history system
     * row was hoisted into that leading row, so each cancellation marker,
     * compaction notice or queued-prompt notice rewrote message 0 and voided
     * the RadixAttention prefix for the whole conversation. A system row
     * BEHIND the first non-system row now stays where it happened, as a
     * user-role `<system-notice>` row — the placement rule and its reasons
     * live on {@see placeSystemRows()}, which {@see CustomProvider} shares.
     *
     * Non-system rows are untouched, in order. A single-system-at-index-0
     * history and a prompt-only request both produce byte-identical output to
     * the pre-Q5 prepend block - which is what lets the E-14 pins
     * (SglangProviderTest's formatMessages legs, MatrixTest's Sglang rows)
     * survive. (Historical note carried from that block: this provider once
     * never read $systemPrompt at all - the whole prompt silently dropped
     * every turn, prompt_expand.md §1.1. It must stay read.)
     *
     * REASONING REPLAY (step 0.1): an assistant row that carries tool calls
     * also carries its `reasoning_content` when $model names a family whose
     * chat template renders that field back ({@see replaysReasoning()}).
     * Both deployed families (DeepSeek-V4, Qwen3.8) interleave thinking with
     * tool calls inside one turn and expect the thinking of the in-flight
     * tool-call steps to be re-sent; dropping it both degrades the next
     * step's reasoning and breaks the RadixAttention prefix at that row,
     * because the template renders the row differently from how the server
     * generated it. Rows without tool calls are left alone: the templates
     * discard reasoning on the final answer of a finished exchange, so
     * sending it there would only add bytes. Scope is intra-turn by
     * construction - across turns EngineBackend rebuilds bare assistant rows
     * with no reasoning (structured replay is step 1.B-2).
     *
     * @param array<Message> $messages
     * @param string|null $systemPrompt the request-level assembled prompt; '' counts as unset.
     * @param string|null $model the id the body is addressed to; null disables the reasoning replay.
     * @return array<array{role: string, content: string}|array{role: string, content: string, tool_calls: array}|array{role: string, tool_call_id: string, content: string}>
     */
    private function formatMessages(array $messages, ?string $systemPrompt = null, ?string $model = null): array
    {
        $replayReasoning = $model !== null && self::replaysReasoning($model);

        // The typed callback stays: raw-array histories (the WorkflowEngine
        // gap named at defaultTopP()'s docblock) must keep TypeErroring here
        // exactly as before.
        $rows = array_map(function (Message $msg) use ($replayReasoning) {
            return match (true) {
                // Audit 15b-15: inlined files, plus image_url parts when an
                // image was attached (only ever handed to a vision provider).
                $msg instanceof UserMessage => ['role' => 'user', 'content' => AttachmentEncoding::openAiContent($msg)],
                // array_filter drops a null/'' reasoning_content exactly as
                // it drops an empty content or tool_calls.
                $msg instanceof AssistantMessage => array_filter([
                    'role' => 'assistant',
                    'content' => $msg->content(),
                    'reasoning_content' => $replayReasoning && ($msg->toolCalls() ?? []) !== []
                        ? $msg->reasoning()
                        : null,
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

        return self::placeSystemRows($rows, $systemPrompt);
    }

    /**
     * The one placement rule for system content on an OpenAI-shaped wire
     * (step 1.A-1), shared with {@see CustomProvider}: rows already formatted
     * by a provider's `formatMessages()`, the request-level prompt, out comes
     * the body's `messages` list.
     *
     *  - ONE leading `system` row: the prompt first, then every history system
     *    row that comes BEFORE the first non-system row (a launch notice, the
     *    title one-shot's instruction), non-empty contents joined with
     *    "\n\n". Omitted when all of that is empty.
     *  - Every LATER system row stays IN PLACE as a user-role
     *    `<system-notice>` row ({@see systemNoticeContent()}).
     *  - Empty system rows are dropped wherever they sit.
     *
     * WHY IN PLACE. Hoisting a mid-history row (a cancellation marker, a
     * compaction or context-tier notice, a queued-prompt notice) into
     * message 0 rewrote the FIRST message whenever one arrived, so every such
     * notice voided the cache prefix for the whole conversation. Left where it
     * happened, it changes only the bytes from its own position on. And it
     * cannot stay a `system` row there: the deployed Qwen template answers a
     * system row at index > 0 with HTTP 400 "System message must be at the
     * beginning." (E-10), and templates that tolerate one render only
     * messages[0]'s system content — so a user-role notice, fenced and
     * labelled as the harness speaking, is the shape every template accepts
     * and renders.
     *
     * @param list<array<string, mixed>> $rows
     * @return list<array<string, mixed>>
     */
    public static function placeSystemRows(array $rows, ?string $systemPrompt): array
    {
        $leading = [];
        if ($systemPrompt !== null && $systemPrompt !== '') {
            $leading[] = $systemPrompt;
        }

        $placed = [];
        $inLeadingRun = true;
        foreach ($rows as $row) {
            if (($row['role'] ?? null) !== 'system') {
                $inLeadingRun = false;
                $placed[] = $row;

                continue;
            }

            $content = \is_string($row['content'] ?? null) ? $row['content'] : '';
            if ($content === '') {
                continue;
            }

            if ($inLeadingRun) {
                $leading[] = $content;
            } else {
                $placed[] = ['role' => 'user', 'content' => self::systemNoticeContent($content)];
            }
        }

        if ($leading !== []) {
            array_unshift($placed, ['role' => 'system', 'content' => implode("\n\n", $leading)]);
        }

        return $placed;
    }

    /**
     * A mid-history system row's content as the user-role notice that
     * carries it: fenced in `<system-notice>` so the model reads it as the
     * harness speaking, not the user. The notice's own fence name is
     * neutralised inside the payload (same terminator rule as
     * {@see \SugarCraft\Crush\Context\PromptFence::escape()}), so quoted
     * text — a queued prompt, an error message — cannot close it early.
     */
    public static function systemNoticeContent(string $content): string
    {
        $escaped = preg_replace('~<(?=/?system-notice(?:[\s/>]|\z))~i', '&lt;', $content);
        if ($escaped === null) {
            throw new \RuntimeException(
                'SglangProvider::systemNoticeContent(): PCRE failure (' . preg_last_error_msg() . ') while escaping a notice',
            );
        }

        return "<system-notice>\n" . $escaped . "\n</system-notice>";
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
     * `parseChunk()`/`reassembleStreamedToolCalls()` reassembly builds its own
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
    private function parseResponse(
        array $data,
        bool $thinkingOn = false,
        ?ToolCallParserInterface $toolCallParser = null,
    ): CompleteResponse {
        $choice = $data['choices'][0] ?? [];
        $message = $choice['message'] ?? [];

        // `$toolCallParser` is complete()'s request-scoped parser (audit 15a
        // A9, {@see toolCallParserFor()}); null only for a caller with no
        // request in hand, which gets the schema-less parser.
        $toolCallParser ??= $this->resolvedToolCallParser();

        if ($toolCallParser instanceof EnvelopeAware) {
            // Audit 15a A8, batch half: a call recovered from markup in the
            // text is cut out of that text before anything reads it, so the
            // assistant message holds the prose and the structured call -
            // not the call twice. Cut from the RAW content, which is what the
            // spans index, before reasoning extraction shifts any offset.
            $recovery = $toolCallParser->recover($message);
            $toolCalls = $recovery->calls();

            if ($recovery->spans() !== [] && is_string($message['content'] ?? null)) {
                $message['content'] = $recovery->contentWithoutEnvelopes($message['content']);
            }
        } else {
            $toolCalls = $toolCallParser->parse($message);
        }

        [$reasoning, $content] = $this->extractReasoning($message);

        // §Q9 batch half: the whole message IS the first content, so the cursor
        // is a throwaway; wire model read from the frame (§Q6 per-frame precedent).
        $seenFirstContentBatch = false;
        $content = self::applyQwenContentCosmetics(
            $content,
            $data['model'] ?? null,
            $thinkingOn,
            $choice['finish_reason'] ?? null,
            $seenFirstContentBatch,
        );

        // P4.S2: every usage number on the way out comes from ONE parsed Usage,
        // so this site and any future carrier cannot disagree about what the
        // server said. tokensUsed/costUsd keep their exact prior expressions
        // for every wire value a provider legitimately sends; a NEGATIVE wire
        // count now clamps to 0 (Usage's stated doctrine for provider bugs)
        // where it used to pass through — no test pinned the pass-through, and
        // UsageWiringTest pins the clamp. The E17 fold sends that one parsed
        // object on the carrier too — one source of truth for the split as
        // well as for the two projections.
        $usage = $this->parseUsage(is_array($data['usage'] ?? null) ? $data['usage'] : []);

        // §Q7 (E-32): `finish_reason` was read nowhere on the batch arm, so a
        // response the server itself declared cut off (`length`/`abort` - the
        // TRUNCATED_FINISH_REASONS set, same list that flags the stream flush)
        // reached the tool layer indistinguishable from a clean one. The
        // parse is capture-only: no argument here changes shape (the batch
        // message arrives whole-or-absent, not fragmented), the flag just
        // says "whatever this is, the turn did not end on its own terms".
        // `stop`, `tool_calls` and a missing finish_reason all stay false,
        // which is the byte-unchanged `stop` promise of §Q7(c). Surfacing is
        // the whole deliverable - see CompleteResponse::$truncated for who
        // may consume it later (E-56 retry context is a CALLER decision, not
        // this parse's).
        $truncated = in_array($choice['finish_reason'] ?? null, self::TRUNCATED_FINISH_REASONS, true);

        return new CompleteResponse(
            content: $content,
            reasoning: $reasoning,
            toolCalls: $toolCalls,
            tokensUsed: $usage->totalTokens,
            costUsd: $usage->costUsd,
            usage: $usage,
            truncated: $truncated,
        );
    }

    /**
     * Parses this endpoint's `usage` object into the provider-counted token
     * BUCKETS (prompt_plan.md P4.S2). The single place this provider reads
     * usage fields, so {@see parseResponse()} and any later carrier agree by
     * construction about what the server actually said.
     *
     * THE SHAPE, MEASURED ON THE LIVE DEPLOYMENT (skynet2 sglang, 2026-09-02):
     * `{"prompt_tokens":N,"total_tokens":N,"completion_tokens":N,`
     * `"prompt_tokens_details":null,"reasoning_tokens":N}` — the
     * `prompt_tokens_details` KEY is on the wire, its VALUE has been null on
     * every response, including a re-sent 1,258-token prefix the server's
     * radix cache cannot miss: this deployment is not launched with cache
     * reporting, so it reports NO cache fields. This parse therefore invents
     * nothing: `cached_tokens` is read only if a server populates it, and the
     * member's shape is this repo's own vendored OpenAI-compatible DTO
     * (`OpenAI\Responses\Chat\CreateResponseUsagePromptTokensDetails`).
     * Absent-or-null details decodes to UNREPORTED (`null`), never to a
     * fabricated zero — the distinction {@see Usage}'s "Zero is not the same
     * as unknown" exists to keep.
     *
     * `prompt_tokens` on this family COUNTS the cached prefix — OpenAI's
     * published API docs describe `cached_tokens` as the cached PART of
     * `prompt_tokens`; the vendored DTO pins the key's shape but says nothing
     * about subset semantics, so this subtraction is DOCUMENTED, not
     * locally-proven (the Gemini arm's identical subset rule at
     * {@see VertexProvider::parseUsageMetadata()} IS locally-proven from the
     * vendored proto) — and
     * `inputTokens` in Usage means "what FOLLOWS the last cache breakpoint",
     * so when both are reported the fresh-input bucket is the difference,
     * floored at 0. That keeps `prompt + completion = total` consistent with
     * Usage's prompt-side identity on the fields this wire does report; the
     * identity accessor itself stays refused while `cacheCreationTokens` is
     * unreported, exactly as Usage intends.
     *
     * There is NO cache-creation field anywhere in this protocol, so
     * `cacheCreationTokens` is null on every parse — an API that reports no
     * cache fields is the legitimate outcome the step text records, not a gap
     * to paper over. `reasoning_tokens` is flat here (NOT under
     * `completion_tokens_details`). CORRECTED IN PLACE by qwen.md §Q6: what
     * this seam first recorded as "a separate seam, deliberately not done
     * here" is now done — the flat key is carried in
     * {@see \SugarCraft\Crush\Usage::$reasoningTokens}, a completion-side
     * SUBSET the server's own `total_tokens` already counts (never re-added).
     *
     * NOTE FOR THE STREAM ARMS — also corrected in place by §Q6: the P4.S2
     * measurement (no `stream_options` in executable code; zero-choice chunk
     * dropped by the delta gate) still described this file on 2026-09-02 and
     * no longer does. {@see completeStream()} now sends
     * `stream_options.include_usage` and accepts the one zero-choice
     * `usage`-bearing frame that flag produces (E-30), parsing it through
     * THIS method; the parsed total surfaces as the stream's terminal
     * chunk. What P4.S2 deferred is still deferred one layer up: only
     * `tokensUsed`/`costUsd` cross the `CompleteResponse` seam today — the
     * buckets (reasoning included) stop at this parse until the carrier
     * widening {@see \SugarCraft\Crush\Usage}'s class docblock names.
     *
     * @param array<string, mixed> $usage the decoded `usage` object. A non-array
     *                                    is handed over as `[]` by the call
     *                                    site, keeping the `?? 0` tolerance the
     *                                    inline parse this method replaces had.
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
            0.0, // self-hosted, no cost - complete() always reported literal 0.0
            $prompt !== null && $cached !== null ? max(0, $prompt - $cached) : $prompt,
            self::usageInt($usage['completion_tokens'] ?? null),
            $cached,
            null, // no cache-creation field exists on this protocol - never invented
            // §Q6 (E-31): the flat reasoning key this family reports on every
            // usage document; unreported (null) on wires that omit it, never
            // coerced to a zero - same usageInt doctrine as every other field.
            self::usageInt($usage['reasoning_tokens'] ?? null),
        );
    }

    /**
     * One usage number as the provider reported it: absent OR JSON null stays
     * `null` (unreported - the OpenAI-compatible DTOs this family copies do
     * emit explicit nulls, e.g. `completion_tokens`, and coercing one to a
     * measured zero is the exact lie Usage forbids); anything numeric counts
     * as its int. Non-numeric junk - strings, booleans, arrays, objects -
     * decodes to UNREPORTED, never a counted zero, while numeric strings and
     * floats count as their int (a float count floors, tolerating a buggy
     * provider exactly where the old strict-typed int parameters would have
     * crashed).
     */
    private static function usageInt(mixed $value): ?int
    {
        return $value === null || !is_numeric($value) ? null : (int) $value;
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
        return $this->parserFor(
            $this->toolCallParser === null && $this->adoptsServedModel()
                ? $this->serverInfo()?->servedModelName
                : null,
        );
    }

    /**
     * The configured parser; failing that, audit A26's choice for an ADOPTED
     * served model (`$servedModel`, when this provider adopts one), so a
     * server that turns out to serve DeepSeek-V4 still gets the DSML safety
     * net {@see ProviderFactory::createSglang()} arms for a configured one;
     * failing that, the OpenAI-array default this method always returned.
     */
    private function parserFor(?string $servedModel): ToolCallParserInterface
    {
        if ($this->toolCallParser !== null) {
            return $this->toolCallParser;
        }

        if ($servedModel !== null && $this->adoptsServedModel()) {
            return self::defaultToolCallParserFor($servedModel);
        }

        return OpenAiArrayToolCallParser::new(self::argumentDecoder());
    }

    /**
     * The resolved parser, told the declared parameter types of the tools
     * THIS request offers when it can use them (audit 15a A9).
     *
     * A text-scanning fallback such as
     * {@see ToolCallParser\MinimaxXmlFallbackToolCallParser} receives every
     * parameter value as raw text; without the schema it can only guess, and
     * its old guess turned the JSON text of a composer.json `Write` into a PHP
     * array. The types live on the request, not on this provider, so they
     * are attached per request to an immutable copy - the provider-held
     * parser is never mutated, and a later request offering different tools
     * cannot inherit this one's schema.
     *
     * `instanceof ToolSchemaAware` is a CAPABILITY check, not the
     * type-switch on the concrete parser that {@see completeStream()}'s
     * buffering comment declines: no concrete class is named, and a parser
     * that cannot use a schema (the default OpenAI-array one, whose
     * server-decoded JSON is already typed) is returned untouched, so the
     * default path builds no type map at all.
     */
    private function toolCallParserFor(CompleteRequest $request): ToolCallParserInterface
    {
        $parser = $this->resolvedToolCallParser();

        if (!$parser instanceof ToolSchemaAware) {
            return $parser;
        }

        return $parser->withParameterTypes(ToolParameterTypes::fromTools($request->tools));
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
     * itself, in {@see reassembleStreamedToolCalls()}, and never consulted
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
     * THE MARKUP DOES NOT STAY IN THE TEXT (audit 15a A8). This docblock used
     * to record, as a known gap, that the recovered envelope was still in the
     * content streamed to the screen and into the transcript - so the user saw
     * raw markup, and the next request carried every call twice: once as that
     * text, once as the structured call the chat template renders back into
     * the same markup. An {@see EnvelopeAware} parser now reports where each
     * envelope sits, completeStream() holds text back from the first byte
     * that could open one ({@see EnvelopeHoldBack}), and the spans returned
     * here are cut out of what was held. {@see parseResponse()} cuts them on
     * the batch path the same way.
     *
     * Null when nothing was recovered, so the caller releases what it held.
     */
    private function recoverTextualToolCalls(
        string $content,
        bool $sawStructuredToolCalls,
        ToolCallParserInterface $toolCallParser,
    ): ?TextualRecovery {
        if ($sawStructuredToolCalls || $content === '') {
            return null;
        }

        // Synthesised with NO `tool_calls` key, because its absence is exactly
        // the condition every fallback parser triggers on - handing over an
        // empty array instead would take their delegated fast path and find
        // nothing.
        $message = ['content' => $content];
        $recovery = $toolCallParser instanceof EnvelopeAware
            ? $toolCallParser->recover($message)
            : TextualRecovery::new($toolCallParser->parse($message));

        return $recovery->calls() === null || $recovery->calls() === [] ? null : $recovery;
    }

    /**
     * `$chunk` with its text replaced by the part the A8 hold-back lets
     * through. Every other field is carried over as it was.
     */
    private static function withContent(CompleteResponse $chunk, string $content): CompleteResponse
    {
        return new CompleteResponse(
            content: $content,
            reasoning: $chunk->reasoning,
            toolCalls: $chunk->toolCalls,
            tokensUsed: $chunk->tokensUsed,
            costUsd: $chunk->costUsd,
            isError: $chunk->isError,
            errorMessage: $chunk->errorMessage,
            errorTransient: $chunk->errorTransient,
            truncated: $chunk->truncated,
            usage: $chunk->usage,
        );
    }

    /**
     * Composes two independent per-chunk concerns: W1.A1 (§12 D2) tool-call
     * fragment reassembly via {@see reassembleStreamedToolCalls()}, and W1.A2
     * (§12 D3) reasoning/content splitting via {@see extractReasoning()}.
     * Kept as separate methods (rather than one inlined rewrite) so each
     * plan step's logic stays a single, independently reviewable unit.
     *
     * @param array<int, array{id?: ?string, name?: ?string, arguments?: string}> $toolCallBuffer
     */
    private function parseChunk(array $data, array &$toolCallBuffer = [], bool $thinkingOn = false, bool &$seenFirstContent = false): CompleteResponse
    {
        // Audit 15a A4: a finish frame may say `"delta": null`; it is still
        // parsed (its finish_reason drains the tool-call buffer), as an empty
        // delta.
        $delta = is_array($data['choices'][0]['delta'] ?? null) ? $data['choices'][0]['delta'] : [];
        $finishReason = $data['choices'][0]['finish_reason'] ?? null;

        // W1.A1 (§12 D2) fragment reassembly through the trait shared with
        // CustomProvider and OpenAIProvider (X-31a). The fragments are
        // buffered FIRST (a null finish only accumulates) so the raw payloads
        // are still in hand when the server declares the calls complete: the
        // trait decodes them quietly, and W1.A4's truncation-aware diagnostics
        // ({@see decodeToolArguments()}, MiniMax-specific) are this provider's
        // to emit. The `arguments` both decodes produce are identical - the
        // object, else [] - and an undecodable payload still rides as
        // `argumentsError` (audit A11) with its raw string (A23).
        //
        // ON ANY OTHER END the buffer is intentionally left intact (§Q7/A4):
        // abandoning it was E-32's silent-loss bug, and
        // {@see flushTruncatedToolCalls()} drains it at the generator's
        // terminal seam for every end except `error`.
        $this->reassembleStreamedToolCalls($delta, null, $toolCallBuffer);
        $declared = $finishReason === 'tool_calls' ? $toolCallBuffer : [];
        $toolCalls = $this->reassembleStreamedToolCalls([], $finishReason, $toolCallBuffer);
        foreach ($declared as $fragment) {
            self::decodeToolArguments($fragment['arguments'] ?? '', (string) ($fragment['name'] ?? ''));
        }

        // W1.A2 (§12 D3), applied per chunk here: Case 1 (delta.reasoning_content
        // present) is unambiguous per chunk. Case 2 (raw <think> markup inline in
        // content, e.g. under minimax-append-think) is a known, accepted
        // limitation - a </think> closer straddling a chunk boundary won't be
        // caught until the fragment containing it arrives whole. Catching that
        // would require buffering the full assembled message before splitting,
        // which belongs where content is reassembled
        // ({@see \SugarCraft\Crush\Runtime::runStreaming()}), not here.
        [$reasoning, $content] = $this->extractReasoning($delta);

        // §Q9 (E-21/E-25/E-26): the Qwen-only content cosmetics, applied to the
        // extracted text before it crosses the CompleteResponse seam. `$data['model']`
        // (the id the server used for THIS frame) decides family, so a DeepSeek
        // stream — even one that happens to share the SSE harness — is skipped in
        // the helper. `$thinkingOn` and `$seenFirstContent` are threaded from
        // completeStream() because neither the per-chunk kwargs nor "which delta is
        // first" is recoverable from a single chunk in isolation.
        $content = self::applyQwenContentCosmetics($content, $data['model'] ?? null, $thinkingOn, $finishReason, $seenFirstContent);

        return new CompleteResponse(
            content: $content,
            reasoning: $reasoning,
            toolCalls: $toolCalls,
            tokensUsed: 0,
            costUsd: 0.0,
        );
    }

    /**
     * §Q9 (E-21/E-25/E-26) — cosmetic correctness of the Qwen content channel.
     * Both rules are FAMILY-SCOPED to Qwen so the DeepSeek batch and stream paths
     * stay byte-for-byte what they were (the spec's "avoid touching DeepSeek
     * behavior" clause): the guard returns the content untouched for any other
     * family, and `$wireModel` (never the configured `$this->model`) is what
     * decides family — it is the id the server generated these bytes with,
     * mirroring how §Q6 reads usage per-frame.
     *
     *  (a) E-21: with thinking ON the first content delta (and the non-stream
     *      `message.content`) opens with a literal whitespace run — measured as a
     *      "\n\n" prefix. Trim that run from the FIRST content delta of a turn
     *      only. Every later delta flows through unchanged, so the stray newline
     *      deltas E-26 places BETWEEN parallel call groups keep flowing. The
     *      `$seenFirstContent` carry flag is what makes "first" survive the
     *      stateless-per-chunk boundary of parseChunk(); batch passes a throwaway.
     *      Gated on `$thinkingOn` as well as family: with `enable_thinking:false`
     *      E-22 measured the prefix simply never occurs, so trimming would be
     *      wrong (and a real leading-space answer would be mangled).
     *  (b) E-24/E-26: a content that is nothing but whitespace arriving on a
     *      `finish_reason:"tool_calls"` chunk is the model thinking out loud, not
     *      an answer, so emit nothing. Independent of `$thinkingOn` on purpose —
     *      the spec words (b) against the "(trimmed) content", and a whitespace-
     *      only content is meaningless for the family whether or not thinking ran.
     *
     * @param  bool  $seenFirstContent  by-ref turn cursor (only consulted by (a))
     */
    private static function applyQwenContentCosmetics(string $content, mixed $wireModel, bool $thinkingOn, ?string $finishReason, bool &$seenFirstContent): string
    {
        if (!is_string($wireModel) || !self::isQwen3Next($wireModel)) {
            return $content;
        }

        if ($thinkingOn && !$seenFirstContent && $content !== '') {
            $seenFirstContent = true;
            $content = ltrim($content);
        }

        if ($finishReason === 'tool_calls' && $content !== '' && trim($content) === '') {
            $content = '';
        }

        return $content;
    }

    /**
     * §Q9 (a) gate — the request-side half of the Qwen thinking test. Mirrors
     * buildBody()'s derivation exactly: the template's own default is ON, so only
     * an explicit `enable_thinking:false` counts as OFF. Kept separate from
     * applyQwenContentCosmetics() because the kwargs are request-scoped and never
     * echoed on the response, while the family gate is per-frame.
     */
    private function contentThinkingEnabled(CompleteRequest $request): bool
    {
        return ($this->mergedTemplateKwargs($request)['enable_thinking'] ?? true) !== false;
    }


    /**
     * §Q7 (qwen.md; E-32): the truncation twin of
     * {@see reassembleStreamedToolCalls()}'s `tool_calls` assembly. Called by
     * {@see completeStream()} once per stream, whenever fragments are still
     * buffered at the end - after a truncated end (see
     * `TRUNCATED_FINISH_REASONS`) and, since audit 15a A4, after a clean end
     * that never declared its calls (`stop` and the like; `$cleanEndReason`
     * names it). Only `error` ends skip it - see the call site.
     *
     * A4 DOES NOT LOOSEN THE RULE BELOW for clean ends: the server finished
     * the stream but never said the calls were complete, so an argument
     * payload that does not decode is exactly as unsafe to execute as a cut
     * one, and drops the same way. The one difference is the EMPTY payload:
     * on a clean end every fragment the server meant to send arrived, so an
     * opener with no argument deltas IS a zero-argument call - the reading
     * {@see decodeToolArguments()} gives a blank payload on a `tool_calls`
     * finish - and is emitted with `[]` rather than dropped. The warnings'
     * fate clauses name the end that actually happened, so a clean end is
     * never reported as a truncation.
     *
     * THE RULE, AND WHY IT DIFFERS FROM THE CLEAN-FINISH RULE: on a
     * `tool_calls` finish the server declares every call complete, so
     * W1.A4's doctrine there is "decode, and if the payload still broke,
     * keep the call alive with empty arguments and say so". On a truncated
     * end the server has declared the opposite - the turn was cut - so an
     * argument payload that does not decode is not "a call that ran with
     * wrong arguments", it is a call that was never finished, and executing
     * it is the exact silent-corruption failure W1.A4 exists to stop.
     * Complete-JSON args are therefore EMITTED; anything else is DROPPED -
     * never emitted half-decoded - and each drop names itself through
     * {@see malformedArgumentsWarning()}, reused with a fate clause saying
     * "dropped" instead of "executed", so the transcript shows what happened
     * to every victim. An empty payload drops too: on the E-26 fragment
     * shape, an opener whose first argument delta never arrived is
     * indistinguishable from a genuine zero-argument call, and on a cut
     * stream the safe reading of "indistinguishable" is "not provably
     * complete".
     *
     * FAMILY-AGNOSTIC BY CONSTRUCTION: this reads only the OpenAI-shaped
     * fragment buffer, which Qwen, DeepSeek, and MiniMax-structured streams
     * all produce (E-26/E-32); no model predicate gates it, mirroring
     * {@see malformedArgumentsWarning()}'s "diagnose, don't attribute"
     * stance. `matched_stop` values differ across the family (248046 vs 1)
     * and are never consulted here or by the flush guard.
     *
     * @param array<int, array{id?: ?string, name?: ?string, arguments?: mixed}> $toolCallBuffer
     * @param ?string $cleanEndReason null for a truncated end (the §Q7
     *                                default); otherwise the clean
     *                                `finish_reason` the stream closed with
     * @return ?list<ToolCall> null when NOTHING survived - every payload was
     *                         incomplete - so the caller yields no frame
     *                         (warnings already tell the story); an empty
     *                         array is not a possible return.
     */
    private static function flushTruncatedToolCalls(array $toolCallBuffer, ?string $cleanEndReason = null): ?array
    {
        $droppedFate = $cleanEndReason === null
            ? 'the call is being DROPPED, not executed, because the stream '
                . 'was truncated before its arguments completed'
            : sprintf(
                'the call is being DROPPED, not executed, because the stream ended '
                    . 'with finish_reason "%s" without declaring its tool calls complete '
                    . 'and these arguments are not a complete JSON object',
                $cleanEndReason,
            );
        $calls = [];

        foreach ($toolCallBuffer as $tc) {
            $name = (string) ($tc['name'] ?? '');
            $raw = $tc['arguments'] ?? '';

            if (is_array($raw)) {
                // Pre-decoded server payload (the tolerance W1.A4 documents):
                // already structured, nothing ran off the end of it.
                $calls[] = ToolCall::fromArray([
                    'id' => $tc['id'] ?? '',
                    'name' => $name,
                    'arguments' => $raw,
                ]);
                continue;
            }

            $rawString = is_string($raw) ? $raw : '';

            if (trim($rawString) === '' && $cleanEndReason !== null) {
                // A4: nothing was cut, so no argument delta is missing - this
                // is a genuine zero-argument call (see the docblock).
                $calls[] = ToolCall::fromArray([
                    'id' => $tc['id'] ?? '',
                    'name' => $name,
                    'arguments' => [],
                ]);
                continue;
            }

            if (trim($rawString) === '') {
                // §Q7/E-32: deliberately NOT malformedArgumentsWarning() - that
                // formatter infers the cause from the payload's shape, and an
                // empty shape has none; reusing it would also print a stale
                // json_last_error_msg() because this arm never runs a decode.
                RuntimeNoticeSink::warn(sprintf(
                    'SglangProvider: tool call "%s" arguments never streamed (empty payload); '
                    . 'this is not malformed JSON - nothing arrived to parse - so %s.',
                    $name,
                    $droppedFate,
                ));
                continue;
            }

            $decoded = json_decode($rawString, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                RuntimeNoticeSink::warn(self::malformedArgumentsWarning($name, $rawString, $droppedFate));
                continue;
            }

            if (!is_array($decoded)) {
                // Same taxonomy as decodeToolArguments()'s non-object arm,
                // opposite outcome - decodeToolArguments() degrades to []
                // because a clean finish said the call is real; here the
                // stream said it is not, so the call goes.
                RuntimeNoticeSink::warn(sprintf(
                    'SglangProvider: tool call "%s" arguments decoded to %s, not an object; '
                    . 'dropping the %s call instead of executing it. Raw payload: %s',
                    $name,
                    get_debug_type($decoded),
                    $cleanEndReason === null ? 'truncated' : 'undeclared',
                    self::excerpt($rawString),
                ));
                continue;
            }

            $calls[] = ToolCall::fromArray([
                'id' => $tc['id'] ?? '',
                'name' => $name,
                'arguments' => $decoded,
                // Audit A23: replayed verbatim in history.
                'rawArguments' => $rawString,
            ]);
        }

        return $calls === [] ? null : $calls;
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
     * BOTH OF THIS METHOD'S DIAGNOSTICS ARE ON THE MID-SESSION TRANSCRIPT SEAM
     * (E192), and the rule that put them there is the one the two tool-call
     * parsers' class doc-blocks state: a notice goes to
     * {@see \SugarCraft\Crush\Diagnostics\RuntimeNoticeSink::warn()} if and
     * only if the emitter did not produce what the caller asked for. It did
     * not. The model supplied an `arguments` payload this method cannot turn
     * into one - `read()` with no `path`, `write()` with no `content` - which
     * is the unrecoverable shape
     * {@see \SugarCraft\Crush\Providers\ToolCallParser\DsmlToolCallParser}
     * refuses outright rather than fire. The decode result is still `[]`
     * (W1.A4 keeps the turn alive), but since audit A11 the call no longer
     * RUNS with it: every caller also stamps
     * {@see \SugarCraft\Crush\Tools\ToolCall::argumentsErrorFor()} onto the
     * call, and Runtime returns that error to the model as the tool result.
     * The seam row is the user's half of the same story.
     *
     * THE ZERO-ARGUMENT CASE ABOVE IS DELIBERATELY NOT ON THE SEAM. An absent
     * or blank payload is how a genuine zero-argument call arrives, so there is
     * nothing to report — that arm returns before either warning.
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
            RuntimeNoticeSink::warn(sprintf(
                'SglangProvider: tool call "%s" arguments decoded to %s, not an object; '
                . '%s. Raw payload: %s',
                $toolName,
                get_debug_type($decoded),
                $decoded === null ? 'defaulting to no arguments' : self::REFUSED_FATE,
                self::excerpt($raw),
            ));

            return [];
        }

        RuntimeNoticeSink::warn(self::malformedArgumentsWarning($toolName, $raw, self::REFUSED_FATE));

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
     *
     * §Q7 added the $fate clause: it replaces only the final "what happens
     * to the call" sentence, per branch. The flush passes a "dropped, not
     * executed" fate because that is what §Q7 does with an incomplete
     * payload. Audit A11 made it required: the W1.A4 default ("defaulting to
     * no arguments") stopped being true once a declared-complete call with
     * broken arguments became an error result instead of a `[]` run, so
     * {@see decodeToolArguments()} now passes {@see REFUSED_FATE}.
     */
    private static function malformedArgumentsWarning(string $toolName, string $raw, string $fate): string
    {
        $trimmed = rtrim($raw);
        $structurallyClosed = str_ends_with($trimmed, '}') || str_ends_with($trimmed, ']');

        if ($structurallyClosed) {
            return sprintf(
                'SglangProvider: tool call "%s" arguments are not valid JSON (%s); %s. Raw payload: %s',
                $toolName,
                json_last_error_msg(),
                $fate,
                self::excerpt($raw),
            );
        }

        return sprintf(
            'SglangProvider: possible MiniMax XML-delimiter truncation in tool call "%s" - '
            . 'arguments are not valid JSON (%s) and end mid-value without a closing structure%s. '
            . 'That matches the signature of the known MiniMax-M2.x "%s" tool-call bug '
            . '(server-side, not fixable client-side) - the CAUSE is inferred from the shape, not '
            . 'from the model, so on a non-MiniMax model look for another truncation source; %s. '
            . 'Raw payload: %s',
            $toolName,
            json_last_error_msg(),
            str_contains($raw, self::XML_PARAM_CLOSE_TAG)
                ? sprintf(', and contain the literal "%s"', self::XML_PARAM_CLOSE_TAG)
                : '',
            self::XML_PARAM_CLOSE_TAG,
            $fate,
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
     * DELIBERATELY LEFT ON `error_log()` WHEN E192 MOVED THIS CLASS'S OTHER TWO
     * DIAGNOSTICS TO THE SEAM, and the routing rule is what decides it rather
     * than a judgement about how interesting the line is. The rule asks whether
     * the emitter produced what the caller asked for; here it did. Nothing
     * failed, nothing was refused, nothing was silently downgraded — this
     * PREDICTS that a LATER call echoing this content may be truncated. A seam
     * row is a `Role::System` message re-sent to the model on every subsequent
     * turn, and this one fires once per matching tool result in the trailing
     * batch, so a multi-tool turn over XML-ish files would put several
     * speculative rows in the conversation and keep paying for them for the
     * rest of the session. `error_log()` is unclipped, costs no tokens, and is
     * what a log trawl wants from a prediction.
     *
     * RATE-LIMITED TO ONCE PER TOOL-CALL ID (audit C2(b)). The trailing-run
     * rule above stops a result being re-flagged on LATER turns, but not on a
     * re-issue of the SAME history: this runs from {@see buildParams()} on
     * every request, and {@see \SugarCraft\Crush\Runtime}'s transient-failure
     * retries (streaming and batch) and any other re-send of the same tail
     * hand it the same trailing batch again. Each re-send re-logged the same
     * ~500-byte line for the same tool call, and while `error_log()` is fd 2
     * in the TUI, each copy painted over the frame. The prediction is about
     * the CONTENT the model has absorbed, so saying it once per result is the
     * whole signal; repeats add nothing.
     *
     * - Keyed on the tool-call id ALONE, not id+model: the same result
     *   re-sent to a different request model is still the same cause, and
     *   the first line already names the model it was addressed to. An
     *   empty id falls back to a hash of the content, so an id-less result
     *   is still logged once rather than on every request.
     * - Remembered per provider INSTANCE ({@see $truncationRiskWarned}).
     *   Measured by reading, not assumed: {@see
     *   \SugarCraft\Crush\Backend\EngineBackend} holds one provider for the
     *   session and every wither passes that same instance on; each turn's
     *   {@see \SugarCraft\Crush\Runtime} is built around it, so the retries
     *   above all reach this one object. A provider switch builds a new
     *   provider, and so starts with an empty memory. Under
     *   {@see \SugarCraft\Crush\Backend\EngineBackend::completeAsync()} each
     *   turn runs in a `pcntl_fork()` child, whose additions die with it -
     *   which is enough, because a tool-call id only ever TRAILS within the
     *   turn that produced it: the next turn's history ends in the user's new
     *   prompt, so the result is no longer in the scanned run.
     * - Bounded at {@see TRUNCATION_RISK_WARNED_CAP} entries, dropping the
     *   oldest when full: a very long in-process session must not grow this
     *   without limit, and forgetting a long-gone id risks at most one
     *   repeated line if that exact batch ever trailed again.
     *
     * The DeepSeek-V4 return stays first, so a skipped model never consumes
     * an id: the same result later sent to a warned model is still warned.
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
            // Step 1.A-1: the `<turn-context>` row Runtime appends behind the
            // results is harness metadata, not the end of the batch.
            if (\SugarCraft\Crush\Context\TurnContextBlock::isTurnContext($message)) {
                continue;
            }
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

            if (!$this->firstTruncationRiskWarningFor($result)) {
                continue;
            }

            error_log(sprintf(
                'sugarcrush: SglangProvider: tool result "%s" contains the literal "%s" (%d occurrence(s)) - '
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
     * Records `$result` in {@see $truncationRiskWarned} and answers whether
     * this is the first time - i.e. whether the warning should be logged. The
     * key and the eviction policy are explained on
     * {@see flagTruncationRiskInLatestToolResults()}.
     */
    private function firstTruncationRiskWarningFor(ToolResultMessage $result): bool
    {
        $id = $result->toolCallId();
        $key = $id !== '' ? 'id:' . $id : 'content:' . hash('xxh128', $result->content());

        if (isset($this->truncationRiskWarned[$key])) {
            return false;
        }

        if (count($this->truncationRiskWarned) >= self::TRUNCATION_RISK_WARNED_CAP) {
            // ArrayObject keeps insertion order, so the first key is the
            // oldest remembered id. Read inside the loop, unset after it, so
            // the set is never modified under a live iterator.
            $oldest = null;
            foreach ($this->truncationRiskWarned as $oldest => $_) {
                break;
            }
            unset($this->truncationRiskWarned[$oldest]);
        }

        $this->truncationRiskWarned[$key] = true;

        return true;
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

    /**
     * §Q8 (qwen.md; E-56): lifts the server's own clean error sentence out of
     * a failed SGLANG HTTP response, or returns null when there is nothing
     * clean to show.
     *
     * WHY: with `http_errors` on (the shipped default) every 4xx/5xx surfaces
     * as a Guzzle RequestException whose getMessage() is the request/status
     * line plus the RAW response body clipped to ~2KB, and Chat renders
     * exactly that in {@see \SugarCraft\Crush\Chat::scheduleBackendCompletion()}'s
     * rejection handler - so before this extraction the user
     * read `Client error: ... {"object":"error","message":"Unexpected...`
     * JSON garbage instead of the one sentence the server wrote for them.
     *
     * Body shapes tried in order: FLAT `message` FIRST because that is the
     * MEASURED SGLang error object (E-40:
     * `{"object":"error","message":"Unexpected reasoning effort X. ...","type":"BadRequest","code":400}`;
     * E-10: `{"object":"error","message":"System message must be at the
     * beginning.", ...}`), then the OpenAI-style NESTED `error.message` the
     * spec names, for servers that speak that dialect instead.
     *
     * CLASSIFICATION SAFETY: callers keep the identical
     * `RuntimeException($prefix . $text, 0, $e)` wrap - only $text's source
     * changes. TransientFailure::isTransient() walks getPrevious() to the
     * Guzzle exception's status code, so 400 stays permanent and 5xx/408/429
     * stay retried (pinned by TransientFailureTest's sglang-shape cases).
     *
     * Fail-safe by design: no response (connect/TLS failures), unreadable or
     * empty body, non-JSON body, and absent/blank/non-string message keys all
     * return null, so the catch sites fall back to the pre-Q8
     * `$e->getMessage()` byte-for-byte.
     */
    private static function errorBodyMessage(GuzzleException $e): ?string
    {
        if (!$e instanceof RequestException || !$e->hasResponse()) {
            return null;
        }

        try {
            $body = (string) $e->getResponse()->getBody();
        } catch (\Throwable) {
            // Consumed/closed stream: the old Guzzle-message surface wins.
            return null;
        }

        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            return null;
        }

        foreach ([$decoded['message'] ?? null, $decoded['error']['message'] ?? null] as $candidate) {
            if (!is_string($candidate)) {
                continue;
            }

            $message = trim($candidate);
            if ($message === '') {
                continue;
            }

            if (strlen($message) > self::ERROR_MESSAGE_DISPLAY_LIMIT) {
                // Byte ceiling guards runaway echo; mb_substr keeps the cut codepoint-safe.
                $message = mb_substr($message, 0, self::ERROR_MESSAGE_DISPLAY_LIMIT) . ' [truncated]';
            }

            return $message;
        }

        return null;
    }
}
