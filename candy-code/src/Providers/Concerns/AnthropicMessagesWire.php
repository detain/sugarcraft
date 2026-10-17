<?php

declare(strict_types=1);

namespace SugarCraft\Code\Providers\Concerns;

use SugarCraft\Code\Messages\AssistantMessage;
use SugarCraft\Code\Messages\Message;
use SugarCraft\Code\Messages\SystemMessage;
use SugarCraft\Code\Messages\ToolResultMessage;
use SugarCraft\Code\Messages\UserMessage;
use SugarCraft\Code\Providers\AttachmentEncoding;
use SugarCraft\Code\Providers\CacheBreakpoints;
use SugarCraft\Code\Providers\CompleteRequest;
use SugarCraft\Code\Providers\CompleteResponse;
use SugarCraft\Code\Providers\ContextOverflow;
use SugarCraft\Code\Providers\ProviderStreamException;
use SugarCraft\Code\Providers\TransientFailure;
use SugarCraft\Code\Tools\Tool;
use SugarCraft\Code\Tools\ToolCall;
use SugarCraft\Code\Usage;

/**
 * The Anthropic Messages wire: request shaping, response parsing, streamed
 * event decoding, family pricing and prompt-cache marking.
 *
 * ISS-023 lifted this out of {@see \SugarCraft\Code\Providers\VertexProvider}
 * intact - the wire document is byte-identical to what that class built
 * before the move; only three labels changed shape, into the hooks below.
 * Two providers now speak it: Vertex's `:rawPredict` arm, and the native
 * `api.anthropic.com/v1/messages` endpoint in
 * {@see \SugarCraft\Code\Providers\AnthropicProvider}.
 *
 * WHY THE DOCUMENT IS NOT QUITE ENOUGH TO SHARE RAW
 * -------------------------------------------------
 * "Native Anthropic Messages body, passed through verbatim" is Vertex's own
 * description of `:rawPredict`, so the body itself has no Vertex flavoring -
 * but two wire-level details are genuinely per-host, and a trait cannot
 * guess them:
 *
 *   - WHERE THE MODEL TRAVELS. Vertex templates the model into the URL path
 *     and rejects a body `model` field, sending `anthropic_version` (the
 *     Vertex-spelled protocol marker, NOT Anthropic's own date string) as the
 *     body's first key. The native endpoint takes `model` in the body, names
 *     the protocol version in the `anthropic-version` REQUEST HEADER, and has
 *     no `anthropic_version` body key at all. So each host's opening keys are
 *     produced by {@see anthropicWireBodyPrelude()}, merged FIRST, and the
 *     shared builder below never mentions either spelling.
 *   - WHOSE NAME IS IN THE ERROR. Refusals and malformed-stream diagnostics
 *     name the transport that failed, because an operator greps for the arm
 *     they configured. {@see anthropicWireUnaryLabel()} and
 *     {@see anthropicWireStreamLabel()} supply it.
 *
 * CONTRACT ON THE USING CLASS. It must define the promoted private properties
 * `$defaultModel` (string), `$modelPrices` (array) and `$promptCache` (bool)
 * with those meanings - traits read them directly, exactly as they did when
 * this code lived inline.
 */
trait AnthropicMessagesWire
{
    /**
     * The Gemini pair. A `publishers/google` model is NOT one protocol: the
     * PaLM 2 family answers `:predict` with the `instances` envelope, while
     * Gemini answers `:generateContent` / `:streamGenerateContent` with a
     * `contents` document. Both live under the same publisher, so the
     * publisher segment cannot select the protocol - the model family has to.
     * See {@see isGeminiModel()}.
     */
    public const METHOD_GENERATE_CONTENT = 'generateContent';
    public const METHOD_STREAM_GENERATE_CONTENT = 'streamGenerateContent';

    private const PUBLISHER_ANTHROPIC = 'anthropic';
    private const PUBLISHER_GOOGLE = 'google';

    /** `max_tokens` is REQUIRED by the Anthropic Messages API - there is no server-side default to fall back to. */
    private const DEFAULT_MAX_TOKENS = 4096;
    private const DEFAULT_TEMPERATURE = 0.7;
    /**
     * E707 (round 81): the two vocabularies this class routes between for the
     * one event each - a reply stopped by the OUTPUT ceiling. Anthropic
     * publishers say `max_tokens` in `stop_reason`; Gemini publishers say
     * `MAX_TOKENS` in a candidate's `finishReason`. Both strings sit in one
     * set because the membership test is exact-case and they cannot collide.
     * Deliberately absent: `refusal`/`SAFETY`/`RECITATION` ends (a filter cut
     * is not a ceiling to raise) and `stop_sequence` (the model chose to
     * stop). The legacy PaLM `predict` route is absent for a different reason
     * - its documented answer carries no stop field to read; see
     * parseResponse()'s note.
     *
     * @var list<string>
     */
    private const TRUNCATED_STOP_REASONS = ['max_tokens', 'MAX_TOKENS'];
    /**
     * Built-in USD-per-1K list rates, keyed by normalised model FAMILY
     * ({@see pricingFamily()}) => [input, output] (audit A15).
     *
     * WHY A TABLE AND NOT 0.0: `costPer1kTokens()` used to answer a flat
     * `0.0` "placeholder" for every model, so a paid Claude turn reported a
     * confident `$0.0000` with no {@see Usage::$unpricedModel} beside it - the
     * "free" reading of zero, not the "unknown" one - and neither the
     * EngineBackend mid-turn spend cap nor Chat's pre-flight cap could ever
     * trip on this provider. A family missing here is now UNPRICED (null), the
     * loud lower bound, never guessed.
     *
     * The figures are the publishers' GLOBAL-endpoint list rates as of the
     * October 2026 audit (Anthropic's per-model list price, which Vertex
     * charges on its global endpoint; Google's Gemini rates for prompts up
     * to 200k tokens). They are an APPROXIMATION in two known directions:
     * Vertex regional endpoints can carry a premium over the global rate, and
     * a Gemini prompt over 200k tokens bills a higher tier. Both under-state,
     * which is the safe side for a lower-bound readout; an operator who needs
     * the exact figure declares it in `$modelPrices`.
     *
     * Retired families (Claude 2, PaLM, Gemini 1.x) and anything newer than
     * this table (`gemini-3*`, …) are deliberately absent: unpriced, not
     * priced at a neighbour's rate.
     *
     * @var array<string, array{0: float, 1: float}>
     */
    private const PRICE_TABLE = [
        'claude-fable-5-1' => [0.01, 0.05],
        'claude-fable-5' => [0.01, 0.05],
        'claude-opus-5-5' => [0.004, 0.02],
        'claude-opus-5' => [0.005, 0.025],
        'claude-opus-4-8' => [0.005, 0.025],
        'claude-opus-4-7' => [0.005, 0.025],
        'claude-opus-4-6' => [0.005, 0.025],
        'claude-opus-4-5' => [0.005, 0.025],
        'claude-opus-4-1' => [0.015, 0.075],
        'claude-opus-4' => [0.015, 0.075],
        'claude-3-opus' => [0.015, 0.075],
        'claude-sonnet-5-5' => [0.002, 0.01],
        'claude-sonnet-5' => [0.002, 0.01],
        'claude-sonnet-4-6' => [0.003, 0.015],
        'claude-sonnet-4-5' => [0.003, 0.015],
        'claude-sonnet-4' => [0.003, 0.015],
        'claude-3-7-sonnet' => [0.003, 0.015],
        'claude-3-5-sonnet' => [0.003, 0.015],
        'claude-3-sonnet' => [0.003, 0.015],
        'claude-haiku-4-5' => [0.001, 0.005],
        'claude-3-5-haiku' => [0.0008, 0.004],
        'claude-3-haiku' => [0.00025, 0.00125],
        'gemini-2.5-pro' => [0.00125, 0.01],
        'gemini-2.5-flash' => [0.0003, 0.0025],
        'gemini-2.5-flash-lite' => [0.0001, 0.0004],
        'gemini-2.0-flash' => [0.00015, 0.0006],
        'gemini-2.0-flash-lite' => [0.000075, 0.0003],
    ];
    /**
     * Built-in USD-per-1K CACHE rates, keyed by the same normalised family as
     * {@see PRICE_TABLE} => [read, write] (audit A15, the cache half).
     *
     * Claude: Anthropic's published multipliers on the family's own input
     * rate - a cache READ (`cache_read_input_tokens`) bills at 0.1x, a cache
     * WRITE (`cache_creation_input_tokens`, the default 5-minute TTL this
     * class asks for) at 1.25x. Claude 3 Haiku is the one family whose
     * published pair is not the multiplier ($0.03 / $0.30 per 1M on a $0.25
     * input), so its row carries those figures. Claude 3 Sonnet has no row:
     * it never offered prompt caching, and {@see NO_PROMPT_CACHE_FAMILIES}
     * keeps breakpoints off it.
     *
     * Gemini: the cached-input column of Google's sheet - 0.1x input on the
     * 2.5 family, 0.25x on 2.0 Flash. The write side is null because the
     * protocol reports no write count (implicit caching is free to write;
     * explicit caching bills STORAGE by the hour, which no per-request usage
     * document can carry). 2.0 Flash-Lite publishes no cached rate.
     *
     * A family with no row here bills its cache tokens at its own INPUT rate
     * ({@see cacheCostPer1kTokens()}): never a discount nobody published, and
     * never unpriced when the input rate is known.
     *
     * @var array<string, array{0: float, 1: ?float}>
     */
    private const CACHE_PRICE_TABLE = [
        'claude-fable-5-1' => [0.001, 0.0125],
        'claude-fable-5' => [0.001, 0.0125],
        'claude-opus-5-5' => [0.0004, 0.005],
        'claude-opus-5' => [0.0005, 0.00625],
        'claude-opus-4-8' => [0.0005, 0.00625],
        'claude-opus-4-7' => [0.0005, 0.00625],
        'claude-opus-4-6' => [0.0005, 0.00625],
        'claude-opus-4-5' => [0.0005, 0.00625],
        'claude-opus-4-1' => [0.0015, 0.01875],
        'claude-opus-4' => [0.0015, 0.01875],
        'claude-3-opus' => [0.0015, 0.01875],
        'claude-sonnet-5-5' => [0.0002, 0.0025],
        'claude-sonnet-5' => [0.0002, 0.0025],
        'claude-sonnet-4-6' => [0.0003, 0.00375],
        'claude-sonnet-4-5' => [0.0003, 0.00375],
        'claude-sonnet-4' => [0.0003, 0.00375],
        'claude-3-7-sonnet' => [0.0003, 0.00375],
        'claude-3-5-sonnet' => [0.0003, 0.00375],
        'claude-haiku-4-5' => [0.0001, 0.00125],
        'claude-3-5-haiku' => [0.00008, 0.001],
        'claude-3-haiku' => [0.00003, 0.0003],
        'gemini-2.5-pro' => [0.000125, null],
        'gemini-2.5-flash' => [0.00003, null],
        'gemini-2.5-flash-lite' => [0.00001, null],
        'gemini-2.0-flash' => [0.0000375, null],
    ];
    /**
     * Anthropic families on Vertex that never offered prompt caching, so a
     * `cache_control` mark is not sent to them (an unsupported model's answer
     * to one is not something this repo can measure without credentials, and
     * the safe reading of "unknown" is not to ask). Claude 3 Sonnet is the one
     * retired family Vertex served without it - retired enough that ISS-025
     * removed it from being this provider's factory default id.
     *
     * @var list<string>
     */
    private const NO_PROMPT_CACHE_FAMILIES = ['claude-3-sonnet'];
    /**
     * Whether this provider was built to mark prompt-cache breakpoints (the
     * `promptCache` setting). Whether a given request carries them also
     * depends on its model - see {@see marksPromptCache()}.
     */
    public function promptCache(): bool
    {
        return $this->promptCache;
    }
    public function model(): string
    {
        return $this->defaultModel;
    }
    /**
     * This provider answering for $model (roadmap 4.1-1): the capability
     * flags below read the configured id, so a model switch through
     * {@see \SugarCraft\Code\Backend\EngineBackend::withModel()} rebinds
     * them here instead of leaving them the old model's.
     */
    public function withModel(string $model): static
    {
        return new self(...array_merge(get_object_vars($this), ['defaultModel' => $model]));
    }
    /**
     * @return null|float USD per 1K tokens, or null when neither the operator
     *         `$modelPrices` map nor {@see PRICE_TABLE} names a rate for the
     *         model - the loud-unknown answer {@see ProviderInterface} asks
     *         for (audit A15: this used to be a flat placeholder `0.0`).
     *
     * Lookup order: the operator's entry for the RAW id, then the operator's
     * entry for the normalised family, then the built-in family row. Once
     * the operator NAMES a model (either spelling) that entry is
     * authoritative, exactly as in {@see OpenAIProvider::costPer1kTokens()}:
     * a rate that fails validation answers unpriced rather than falling back
     * to the shipped row it overrode.
     */
    public function costPer1kTokens(string $model, string $direction): ?float
    {
        if (array_key_exists($model, $this->modelPrices)) {
            return $this->declaredRate($model, $direction);
        }

        $family = self::pricingFamily($model);
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
     * The price-table key a Vertex model id belongs to.
     *
     * Vertex ids carry release decorations the family does not:
     * `claude-sonnet-4-5@20250929`, `claude-3-5-sonnet-v2@20241022`,
     * `claude-opus-4-0`, `gemini-2.5-flash-001`, and a configured id may even
     * be a resource path. Each decoration is stripped ONCE, in a fixed order
     * (path, `@version`, `-vN[:M]`, `-YYYYMMDD`, a three-digit Gemini
     * revision, the `-0` alias), and the remainder must then EQUAL a table
     * key. Exact equality, not a prefix match, is the point: a prefix rule
     * would price an unknown `claude-sonnet-4-7` at the `claude-sonnet-4`
     * row, which is a guess presented as a measurement.
     */
    private static function pricingFamily(string $model): string
    {
        $id = strtolower(trim($model));

        $slash = strrpos($id, '/');
        if ($slash !== false) {
            $id = substr($id, $slash + 1);
        }

        $at = strpos($id, '@');
        if ($at !== false) {
            $id = substr($id, 0, $at);
        }

        foreach (['/-v\d+(?::\d+)?$/', '/-\d{8}$/', '/-\d{3}$/', '/-0$/'] as $decoration) {
            $id = (string) preg_replace($decoration, '', $id);
        }

        return $id;
    }
    /**
     * One operator-declared rate, per-1K, for a model the operator NAMED in
     * `$modelPrices`; null when the declaration fails validation. Same rule
     * as {@see OpenAIProvider}'s: zero is legal (a genuinely free model),
     * sign-flipped or non-finite rates go the loud unpriced road rather than
     * being floored by {@see Usage} into a fake-free 0.0.
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
    /**
     * USD per 1K CACHE tokens for `$model` (audit A15, the cache half): `read`
     * prices `cache_read_input_tokens` / Gemini's `cachedContentTokenCount`,
     * `write` prices `cache_creation_input_tokens`. Null only when the rate it
     * resolves to is unknown or fails validation - the same loud unpriced road
     * as {@see costPer1kTokens()}.
     *
     * Lookup order mirrors costPer1kTokens(): an operator entry (raw id, then
     * family) is authoritative - its `cached` / `cacheWrite` rate when it
     * declares one, else its own `input` rate, because a named model's entry
     * replaces the built-in row and its cache discount together (exactly
     * {@see OpenAIProvider}'s `cached` rule). Then the built-in
     * {@see CACHE_PRICE_TABLE} row; then the family's input rate. Billing a
     * cache token at the input rate when no cache rate is on file overstates a
     * read and understates a write, which is the honest cost of not inventing
     * a figure; an operator who needs the exact one declares it.
     *
     * @param 'read'|'write' $kind
     */
    public function cacheCostPer1kTokens(string $model, string $kind): ?float
    {
        $declaredKey = $kind === 'read' ? 'cached' : 'cacheWrite';

        foreach ([$model, self::pricingFamily($model)] as $key) {
            if (!array_key_exists($key, $this->modelPrices)) {
                continue;
            }

            $entry = $this->modelPrices[$key];

            return is_array($entry) && array_key_exists($declaredKey, $entry)
                ? $this->declaredRate($key, $declaredKey)
                : $this->declaredRate($key, 'input');
        }

        $row = self::CACHE_PRICE_TABLE[self::pricingFamily($model)] ?? null;
        $rate = $row === null ? null : ($kind === 'read' ? $row[0] : $row[1]);

        return $rate ?? $this->costPer1kTokens($model, 'input');
    }
    /**
     * Does a request for `$model` carry prompt-cache breakpoints? Only on the
     * Anthropic arm (the one wire here that spells `cache_control`), only
     * with the `promptCache` setting on, and never for a family in
     * {@see NO_PROMPT_CACHE_FAMILIES}. An empty id is the configured default,
     * as on the wire ({@see MarksPromptCache}).
     */
    public function marksPromptCache(string $model): bool
    {
        $model = $model !== '' ? $model : $this->defaultModel;

        return $this->promptCache
            && $this->isAnthropicModel($model)
            && !in_array(self::pricingFamily($model), self::NO_PROMPT_CACHE_FAMILIES, true);
    }
    /**
     * Anthropic publisher models are the `claude-*` family; Vertex ids append
     * a version suffix (`claude-3-5-sonnet-v2@20241022`), so match on the
     * family name rather than an exact id list that would go stale on every
     * model release.
     */
    public function isAnthropicModel(string $model): bool
    {
        return str_contains(strtolower($model), 'claude');
    }
    /**
     * Roadmap 2.7-2: the Anthropic Messages API (rawPredict) continues a
     * trailing assistant turn as a prefill, so a cut-off Claude reply is asked
     * for its exact rest. Gemini and the legacy route take no prefill and are
     * asked with a user row instead. A Claude model that refuses prefill
     * outright answers with an error naming it, which
     * {@see ReplyContinuation::rejectsPrefill()} turns into the same fallback.
     */
    public function acceptsAssistantPrefill(string $model): bool
    {
        return $this->isAnthropicModel($model !== '' ? $model : $this->defaultModel);
    }
    /**
     * The model is a URI path segment on both `predict` and `rawPredict`, so
     * an empty one would be templated into a malformed endpoint rather than
     * rejected usefully. Falling back to the configured model keeps a caller
     * that only set the provider's model working.
     */
    private function modelId(CompleteRequest $request): string
    {
        return $request->model !== '' ? $request->model : $this->defaultModel;
    }
    // -------------------------------------------------------------------------
    // Anthropic (rawPredict / streamRawPredict) request shaping
    // -------------------------------------------------------------------------
    /**
     * The native Anthropic Messages API document the wire expects.
     *
     * The host-specific opening keys come from
     * {@see anthropicWireBodyPrelude()}: Vertex must NOT carry a `model` key
     * (it templates the model into the URL and rejects the body field), while
     * the native endpoint requires one - the shared builder below stays out
     * of that decision.
     *
     * @throws \InvalidArgumentException when the transcript yields no turns.
     * @return array<string, mixed>
     */
    private function anthropicBody(CompleteRequest $request, bool $stream): array
    {
        $messages = $this->formatAnthropicMessages($request->messages);

        if ($messages === []) {
            // `messages` must hold at least one turn - an empty list is a
            // server-side 400 with no useful detail. The condition is entirely
            // local (an empty transcript, or one that is nothing but
            // SystemMessages, which systemInstruction() hoists out), so say so
            // here instead of burning a round trip on an opaque error.
            throw new \InvalidArgumentException(sprintf(
                '%s: the Anthropic Messages API requires at least one user or '
                . 'assistant turn, but the transcript produced none (it was empty, or held only '
                . 'system messages - which are hoisted into the top-level "system" field).',
                $this->anthropicWireUnaryLabel()
            ));
        }

        $body = array_merge($this->anthropicWireBodyPrelude($request), [
            'messages' => $messages,
            'max_tokens' => $request->maxTokens ?? self::DEFAULT_MAX_TOKENS,
            'temperature' => $request->temperature ?? self::DEFAULT_TEMPERATURE,
        ]);

        $system = $this->systemInstruction($request);
        if ($system !== null) {
            // P10.S1: with a structured block list on the request, the one
            // protocol here that speaks a block-array `system` expresses it
            // that way; without one, the field is the joined string, exactly
            // as every request this class built before the field existed.
            $body['system'] = $request->systemBlocks !== null && $request->systemBlocks !== []
                ? $this->systemBlockArray($request)
                : $system;
        }

        if ($request->topP !== null) {
            $body['top_p'] = $request->topP;
        }

        if ($request->topK !== null) {
            $body['top_k'] = $request->topK;
        }

        $stopSequences = $this->stopSequences($request);
        if ($stopSequences !== []) {
            $body['stop_sequences'] = $stopSequences;
        }

        if ($request->tools !== null && $request->tools !== []) {
            $body['tools'] = $this->formatAnthropicTools($request->tools);
        }

        if ($this->marksPromptCache($this->modelId($request))) {
            $body = $this->withCacheBreakpoints($body);
        }

        if ($stream) {
            // `streamRawPredict` still needs the body to opt into SSE; without
            // it Vertex answers with one buffered document.
            $body['stream'] = true;
        }

        return $body;
    }
    /**
     * Marks the body's prompt-cache breakpoints through {@see CacheBreakpoints}
     * (audit A15) - the system prompt, the last tool and the conversation
     * tail, re-derived from scratch on every request, which is every step of
     * an agentic turn.
     *
     * CacheBreakpoints reads system as a `role: system` turn inside
     * `messages`, while this wire carries it as the top-level `system` field
     * (a system turn in `messages` is a 400). So the system blocks ride in as
     * the first turn and are lifted back out afterwards - the hazard the
     * class records (its mark would be lost on the joined-string arm) is
     * closed by always handing it, and taking back, the BLOCK form: a string
     * system becomes one text block, the same bytes.
     *
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    private function withCacheBreakpoints(array $body): array
    {
        $system = $body['system'] ?? null;
        $systemBlocks = is_string($system) ? [['type' => 'text', 'text' => $system]] : $system;

        $turns = $body['messages'];
        if (is_array($systemBlocks) && $systemBlocks !== []) {
            array_unshift($turns, ['role' => 'system', 'content' => $systemBlocks]);
        }

        $marked = (new CacheBreakpoints())->apply($turns, $body['tools'] ?? []);
        $messages = array_values($marked['messages']);

        if (is_array($systemBlocks) && $systemBlocks !== []) {
            $body['system'] = array_shift($messages)['content'];
        }

        $body['messages'] = $messages;

        if (isset($body['tools'])) {
            $body['tools'] = array_values($marked['tools']);
        }

        return $body;
    }
    /**
     * The assembled system instruction for EITHER envelope this class builds.
     *
     * Anthropic takes the system prompt as a TOP-LEVEL field - a `system`
     * role inside `messages` is a 400. Google's `instances` envelope has no
     * system role at all and carries the instruction in `instances[0].context`
     * ({@see googleBody()}). Both hoist, both join the same way, so there is
     * ONE joiner: any SystemMessage in the transcript is lifted out here and
     * joined onto the request's own systemPrompt, assembled prompt first, in
     * message order.
     *
     * P10.S1 ADDED A SHAPING TWIN, not a second joiner:
     * {@see systemBlockArray()} applies the SAME hoist predicate to the SAME
     * transcript when the request carries
     * {@see CompleteRequest::$systemBlocks}, cutting the blocks per block
     * instead of one joined string. The two are byte-faithful to each other by
     * construction, and
     * `ProviderRequestResponseTest::testVertexAnthropicSystemBlockArrayFoldsToTheExactBytesTheStringArmTransmits`
     * says so on the wire. The Google and Gemini arms still read only this
     * method, so a block-carrying request and a blockless one of the same
     * bytes transmit the same `context` / `systemInstruction` text there.
     *
     * WAS NAMED `anthropicSystem()` until the Google arm was fixed to
     * transmit at all - the prefix described the only caller it then had, not
     * what it computes.
     *
     * An empty-string systemPrompt and an empty-content SystemMessage each
     * contribute nothing, so a request carrying only those yields `null` and
     * neither envelope grows an empty system field. That guard
     * (`!== null && !== ''`) is the one Sglang, Custom and this class already
     * use; OpenAI and Bedrock check only `!== null`. This method keeps the
     * stricter of the two rather than inventing a third.
     */
    private function systemInstruction(CompleteRequest $request): ?string
    {
        $parts = [];

        if ($request->systemPrompt !== null && $request->systemPrompt !== '') {
            $parts[] = $request->systemPrompt;
        }

        foreach ($request->messages as $msg) {
            if ($msg instanceof SystemMessage && $msg->content() !== '') {
                $parts[] = $msg->content();
            }
        }

        return $parts === [] ? null : implode("\n\n", $parts);
    }
    /**
     * The top-level Anthropic `system` field as a per-block text array.
     *
     * One `['type' => 'text', 'text' => ...]` entry per
     * {@see CompleteRequest::$systemBlocks} element, in order, each text
     * verbatim: the blocks already carry their own inter-layer separators
     * as leading bytes (that is the DTO's cut), so nothing is joined here.
     * The non-empty block list stands in for the flat `systemPrompt` part
     * of {@see systemInstruction()}; every hoisted SystemMessage still
     * rides along — as one trailing block each, prefixed by the joiner's
     * single "\n\n" — so for any request whose blocks fold to its flat
     * string, concatenating these block texts reproduces exactly the
     * string the same request transmits with the blocks absent. The
     * `instances[0].context` and Gemini `systemInstruction` arms keep
     * taking that joined string unchanged (plan P10.S1: the block array
     * exists for the one protocol that can spell it).
     *
     * @return list<array{type: string, text: string}>
     */
    private function systemBlockArray(CompleteRequest $request): array
    {
        $blocks = [];

        foreach ($request->systemBlocks as $block) {
            $blocks[] = ['type' => 'text', 'text' => $block];
        }

        foreach ($request->messages as $msg) {
            if ($msg instanceof SystemMessage && $msg->content() !== '') {
                $blocks[] = ['type' => 'text', 'text' => "\n\n" . $msg->content()];
            }
        }

        return $blocks;
    }
    /**
     * @return array<int, string>
     */
    private function stopSequences(CompleteRequest $request): array
    {
        if (is_string($request->stop)) {
            return $request->stop === '' ? [] : [$request->stop];
        }

        if (is_array($request->stop)) {
            return array_values(array_filter($request->stop, static fn ($s): bool => is_string($s) && $s !== ''));
        }

        return [];
    }
    /**
     * Renders the transcript as Anthropic content-block turns.
     *
     * Two rules the Google `instances` shape never had to honour:
     *   - SystemMessage is dropped here (hoisted by {@see systemInstruction()}).
     *   - Consecutive same-role turns are merged. Anthropic rejects two user
     *     turns in a row, and a tool-calling loop produces exactly that
     *     (assistant tool_use, then one ToolResultMessage per call, all of
     *     which are `user` turns).
     *
     * @param array<Message> $messages
     * @return array<int, array{role: string, content: array<int, array<string, mixed>>}>
     */
    private function formatAnthropicMessages(array $messages): array
    {
        $turns = [];

        foreach ($messages as $msg) {
            if (!$msg instanceof Message || $msg instanceof SystemMessage) {
                continue;
            }

            $blocks = $this->anthropicBlocks($msg);
            if ($blocks === []) {
                // An empty text block is a 400; a turn with nothing in it is
                // simply not a turn.
                continue;
            }

            $role = $msg instanceof AssistantMessage ? 'assistant' : 'user';
            $last = array_key_last($turns);

            if ($last !== null && $turns[$last]['role'] === $role) {
                $turns[$last]['content'] = array_merge($turns[$last]['content'], $blocks);

                continue;
            }

            $turns[] = ['role' => $role, 'content' => $blocks];
        }

        return $turns;
    }
    /**
     * @return array<int, array<string, mixed>>
     */
    private function anthropicBlocks(Message $msg): array
    {
        if ($msg instanceof ToolResultMessage) {
            $block = [
                'type' => 'tool_result',
                'tool_use_id' => $msg->toolCallId(),
                'content' => $msg->content(),
            ];

            if ($msg->isError()) {
                $block['is_error'] = true;
            }

            return [$block];
        }

        // Audit 15b-15: a user turn's inlined files and its image blocks.
        if ($msg instanceof UserMessage) {
            return AttachmentEncoding::anthropicBlocks($msg);
        }

        $blocks = [];

        if ($msg->content() !== '') {
            $blocks[] = ['type' => 'text', 'text' => $msg->content()];
        }

        // No `thinking` block is emitted here - the other half of the dormant
        // extended-thinking seam documented on parseAnthropicResponse(). It is
        // absent rather than removed: there is no signature to replay yet
        // because no request ever asks for extended thinking.
        if ($msg instanceof AssistantMessage) {
            foreach ($msg->toolCalls() ?? [] as $call) {
                $block = $this->toolUseBlock($call);
                if ($block !== null) {
                    $blocks[] = $block;
                }
            }
        }

        return $blocks;
    }
    /**
     * Assistant tool calls have to be replayed back to Anthropic as `tool_use`
     * blocks or the matching `tool_result` has nothing to attach to.
     *
     * {@see ToolCall} keeps its state behind bare accessors on private
     * properties, so handing the object to `json_encode()` emits `{}` - the
     * same trap {@see Concerns\ToolSchema::formatToolCalls()} documents for
     * the OpenAI shape. Pre-shaped arrays (a transcript replayed from disk,
     * in either this library's or OpenAI's spelling) are translated too.
     *
     * @return ?array<string, mixed>
     */
    private function toolUseBlock(mixed $call): ?array
    {
        if ($call instanceof ToolCall) {
            return $this->toolUseFrom($call->id(), $call->name(), $call->arguments());
        }

        if (!is_array($call)) {
            return null;
        }

        $name = $call['name'] ?? $call['function']['name'] ?? null;
        if (!is_string($name) || $name === '') {
            return null;
        }

        $arguments = $call['arguments'] ?? $call['input'] ?? $call['function']['arguments'] ?? [];
        if (is_string($arguments)) {
            // OpenAI streams `function.arguments` as a JSON string.
            $arguments = json_decode($arguments, true) ?? [];
        }

        return $this->toolUseFrom(
            is_string($call['id'] ?? null) ? $call['id'] : '',
            $name,
            is_array($arguments) ? $arguments : [],
        );
    }
    /**
     * @param array<string, mixed> $arguments
     * @return array<string, mixed>
     */
    private function toolUseFrom(string $id, string $name, array $arguments): array
    {
        return [
            'type' => 'tool_use',
            'id' => $id,
            'name' => $name,
            // PHP cannot tell an empty map from an empty list, and `input: []`
            // is rejected as not-an-object - see Concerns\ToolSchema.
            'input' => $arguments === [] ? new \stdClass() : $arguments,
        ];
    }
    /**
     * Anthropic declares tools flat (`name`/`description`/`input_schema`),
     * not inside OpenAI's `{type: function, function: {...}}` wrapper.
     *
     * @param array<mixed> $tools
     * @return array<int, array<string, mixed>>
     */
    private function formatAnthropicTools(array $tools): array
    {
        $formatted = [];

        foreach ($tools as $tool) {
            if ($tool instanceof Tool) {
                $formatted[] = [
                    'name' => $tool->name(),
                    'description' => $tool->description(),
                    'input_schema' => $this->normalizeToolSchema($tool->inputSchema()),
                ];

                continue;
            }

            // Already-shaped declarations pass through untouched.
            if (is_array($tool)) {
                $formatted[] = $tool;
            }
        }

        return $formatted;
    }
    // -------------------------------------------------------------------------
    // Anthropic response parsing
    // -------------------------------------------------------------------------
    /**
     * @param array<string, mixed> $data
     */
    private function parseAnthropicResponse(array $data, string $model): CompleteResponse
    {
        if (isset($data['error'])) {
            $message = $data['error']['message'] ?? sprintf('%s returned an error', $this->anthropicWireUnaryLabel());

            $message = is_string($message) ? $message : sprintf('%s returned an error', $this->anthropicWireUnaryLabel());

            return new CompleteResponse(
                content: '',
                isError: true,
                // 2.7-1a: an overflow is named in the text, the only verdict
                // channel an error response has (see ContextOverflow::describe()).
                errorMessage: ContextOverflow::errorObjectOverflows($data['error'])
                    ? ContextOverflow::describe($message)
                    : $message,
                // A rawPredict error arrives as a 200 carrying an error object,
                // not as an HTTP status, so this is the only place its
                // transience is visible. See
                // TransientFailure::TRANSIENT_ANTHROPIC_ERROR_TYPES.
                errorTransient: TransientFailure::anthropicErrorIsTransient($data['error']),
                errorContextOverflow: ContextOverflow::errorObjectOverflows($data['error']),
            );
        }

        $text = '';
        $reasoning = '';
        $toolCalls = [];

        // An Anthropic reply is a LIST of content blocks, not one text field:
        // a thinking-enabled model puts a `thinking` block first, and a
        // tool-calling turn ends in one `tool_use` block per call.
        //
        // KNOWN-INCOMPLETE SEAM - extended thinking round-trip. A `thinking`
        // block carries a `signature` alongside its text, and when extended
        // thinking is on Anthropic requires the assistant's thinking blocks
        // (signature included) to be replayed verbatim in the next request or
        // it rejects the turn. This method folds thinking text into
        // `reasoning` and drops the signature, and {@see anthropicBlocks()}
        // has no `thinking` case to replay it with - CompleteResponse has
        // nowhere to carry a signature, and AssistantMessage nowhere to store
        // one. Nothing is broken today because {@see anthropicBody()} never
        // sends a `thinking` parameter, so no signed block is ever produced.
        // Whoever enables extended thinking has to close BOTH halves: keep the
        // signature on the way in, and emit the block on the way out.
        foreach ($this->contentBlocks($data) as $block) {
            $type = $block['type'] ?? null;

            if ($type === 'text' && is_string($block['text'] ?? null)) {
                $text .= $block['text'];
            } elseif (($type === 'thinking' || $type === 'redacted_thinking')
                && is_string($block['thinking'] ?? null)) {
                $reasoning .= $block['thinking'];
            } elseif ($type === 'tool_use') {
                $toolCalls[] = ToolCall::fromArray([
                    'id' => is_string($block['id'] ?? null) ? $block['id'] : '',
                    'name' => is_string($block['name'] ?? null) ? $block['name'] : '',
                    'arguments' => is_array($block['input'] ?? null) ? $block['input'] : [],
                ]);
            }
        }

        // P4.S2: one parsed Usage is the source of every usage number leaving
        // this method; tokensUsed/costUsd keep their exact prior expressions
        // for legitimate wire values (a negative count clamps to 0 per
        // Usage's doctrine, as in SglangProvider::parseResponse()).
        // E17: the whole parsed document also leaves on `usage:` — the unary
        // reply's input/output/cache buckets now cross into Runtime.
        $usage = $this->parseAnthropicUsage(
            is_array($data['usage'] ?? null) ? $data['usage'] : [],
            $model,
        );

        // E707 (round 81): the unary Anthropic answer states its own ending.
        return new CompleteResponse(
            content: $text,
            reasoning: $reasoning !== '' ? $reasoning : null,
            toolCalls: $toolCalls === [] ? null : $toolCalls,
            tokensUsed: $usage->totalTokens,
            costUsd: $usage->costUsd,
            usage: $usage,
            truncated: in_array($data['stop_reason'] ?? null, self::TRUNCATED_STOP_REASONS, true),
        );
    }
    /**
     * Parses a native Anthropic Messages-API `usage` object into the
     * provider-counted token BUCKETS (prompt_plan.md P4.S2). Used by the
     * unary `:rawPredict` reply AND by both usage-bearing events of
     * {@see parseAnthropicChunk()} — one document shape, one parse, so the
     * streaming arm cannot drift from the non-streaming one.
     *
     * CACHE-FIELD FINDING — UNVERIFIED-DOCUMENTED, the honest label the
     * fallback source requires: `cache_read_input_tokens` and
     * `cache_creation_input_tokens` are Anthropic's published Messages-API
     * field names (and the same pair Bedrock's vendored `TokenUsage` shape
     * carries, modulo AWS's camelCase spelling — see
     * {@see BedrockProvider::parseUsage()}). This repo vendors no Anthropic
     * SDK, and Vertex's `rawPredict` passes the native document through
     * verbatim, so these names could not be pinned against a local artifact
     * or a live probe (no Vertex credentials exist for this plan); they are
     * NOT invented, they are the documented ones, and the UNVERIFIED label
     * travels with every fixture built on them.
     *
     * The two-bucket cache story is complete here, matching the §4.15 model:
     * `input_tokens` already counts only what FOLLOWS the last breakpoint
     * (Anthropic-shape semantics, same convention note as Bedrock's parse),
     * so no subtraction — `total = cacheRead + cacheCreation + input` is a
     * partition on this wire. No cache fields on an uncached response decode
     * to UNREPORTED (null), never to a measured zero: Anthropic omits the
     * cache members entirely when no cache breakpoint was used, which is
     * exactly what "said nothing" means to Usage.
     *
     * The unary `tokensUsed` stays `input + output`, exactly as before:
     * whether Anthropic's own view of a turn's billable total includes cache
     * tokens is a pricing-visible question outside this step's Goal,
     * REPORTED, not changed. The DOLLAR figure does include them (audit A15,
     * the cache half): since this class marks breakpoints, most of a long
     * agentic prompt arrives as cache reads, and a cost that priced only
     * `input_tokens` would bill the turn for the few tokens after the last
     * breakpoint. Reads and writes are priced at {@see cacheCostPer1kTokens()}.
     *
     * @param array<string, mixed> $usage the native usage document; non-array
     *                                    arrives as `[]` via the call sites
     */
    public function parseAnthropicUsage(array $usage, string $model): Usage
    {
        $inputTokens = self::usageInt($usage['input_tokens'] ?? null) ?? 0;
        $outputTokens = self::usageInt($usage['output_tokens'] ?? null) ?? 0;
        $cacheRead = self::usageInt($usage['cache_read_input_tokens'] ?? null);
        $cacheCreation = self::usageInt($usage['cache_creation_input_tokens'] ?? null);

        // Audit A15: an unknown model bills the 0.0 lower bound WITH its name
        // on the carrier, so the transcript notice and the spend-cap
        // disclosure can tell "unpriced" from "free".
        $cost = $this->cost($model, $inputTokens, $outputTokens, $cacheRead ?? 0, $cacheCreation ?? 0);

        return Usage::new(
            // The exact expression this replaces: the sum of the two sides
            // each defaulted to 0 (see the tokensUsed paragraph above).
            $inputTokens + $outputTokens,
            $cost ?? 0.0,
            self::usageInt($usage['input_tokens'] ?? null),
            self::usageInt($usage['output_tokens'] ?? null),
            $cacheRead,
            $cacheCreation,
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
     * @param array<string, mixed> $data
     * @return array<int, array<string, mixed>>
     */
    private function contentBlocks(array $data): array
    {
        $content = $data['content'] ?? [];

        if (!is_array($content)) {
            return [];
        }

        return array_values(array_filter($content, 'is_array'));
    }
    /**
     * Translates one decoded Anthropic SSE event into a delta response, or
     * null when the event carries nothing a caller can use (`ping`,
     * `content_block_start` for a tool call, …).
     *
     * @param array<string, mixed> $event
     * @param array<int, array{id?: string, name?: string, json?: string}> $toolCallBuffer
     */
    private function parseAnthropicChunk(array $event, string $model, array &$toolCallBuffer): ?CompleteResponse
    {
        $type = $event['type'] ?? null;

        // A `tool_use` block announces its id/name up front and then streams
        // its arguments as `input_json_delta` fragments that are only valid
        // JSON once the block closes - so the call has to be buffered until
        // `content_block_stop`, exactly as the OpenAI shape is buffered by
        // ReassemblesStreamedToolCalls::reassembleStreamedToolCalls(), the
        // trait CustomProvider and SglangProvider share.
        if ($type === 'content_block_start') {
            $block = $event['content_block'] ?? [];

            if (is_array($block) && ($block['type'] ?? null) === 'tool_use') {
                $index = (int) ($event['index'] ?? 0);
                $toolCallBuffer[$index] = [
                    'id' => is_string($block['id'] ?? null) ? $block['id'] : '',
                    'name' => is_string($block['name'] ?? null) ? $block['name'] : '',
                    'json' => '',
                ];
            }

            return null;
        }

        if ($type === 'content_block_delta') {
            $delta = $event['delta'] ?? [];
            $deltaType = is_array($delta) ? ($delta['type'] ?? null) : null;

            if ($deltaType === 'input_json_delta') {
                $index = (int) ($event['index'] ?? 0);
                if (isset($toolCallBuffer[$index])) {
                    $toolCallBuffer[$index]['json'] .= (string) ($delta['partial_json'] ?? '');
                }

                return null;
            }

            $text = is_string($delta['text'] ?? null) ? $delta['text'] : '';
            $thought = is_string($delta['thinking'] ?? null) ? $delta['thinking'] : '';

            if ($text === '' && $thought === '') {
                return null;
            }

            return new CompleteResponse(
                content: $text,
                reasoning: $thought !== '' ? $thought : null,
            );
        }

        if ($type === 'content_block_stop') {
            $index = (int) ($event['index'] ?? 0);
            $buffered = $toolCallBuffer[$index] ?? null;

            if ($buffered === null) {
                return null;
            }

            unset($toolCallBuffer[$index]);

            $json = $buffered['json'];

            // A tool that takes no arguments streams no `input_json_delta` at
            // all, so an EMPTY buffer legitimately means "called with {}".
            // A non-empty buffer that will not decode means the opposite: the
            // fragments were truncated (a dropped chunk, a stream cut short).
            // Those two must not collapse onto the same value. Coercing a
            // failed decode to `[]` - which is what this used to do - hands the
            // agentic loop a well-formed zero-argument call, so a destructive
            // tool runs with its arguments silently missing instead of the turn
            // being reported as broken.
            $arguments = $json === '' ? [] : json_decode($json, true);

            // `json_decode(..., true)` flattens a JSON object and a JSON LIST
            // onto the same PHP array, so an is_array() test alone accepts
            // `[1,2]` as an argument map while the error text below says the
            // buffer has to decode to an object. The two cases that must stay
            // accepted both look like a list to `array_is_list()` - the empty
            // buffer handled above, and an explicit `{}`, which also decodes to
            // `[]` - so the only sound discriminator left is the source text: a
            // document that decoded without error is an object iff it opens
            // with `{`.
            if (!is_array($arguments) || ($json !== '' && !str_starts_with(ltrim($json), '{'))) {
                // E27(b): the two shapes this branch catches must not share one
                // verdict. A document that will not DECODE is a stream cut short
                // mid-tool-call - transport, and the retry can recover it. A
                // document that decodes cleanly but is not an object was emitted
                // COMPLETE and is malformed by construction - retrying cannot
                // fix it and triples the cost. `json_last_error()` is exactly
                // that discriminator, so the message and the transient flag are
                // derived from one read of it.
                $cutShortMidToolCall = json_last_error() !== JSON_ERROR_NONE;

                return new CompleteResponse(
                    content: '',
                    isError: true,
                    errorMessage: sprintf(
                        '%s: tool call %s (%s) streamed argument JSON that does '
                        . 'not decode to an object (%s); %s Buffered fragment: %s',
                        $this->anthropicWireStreamLabel(),
                        var_export($buffered['name'], true),
                        $buffered['id'] === '' ? 'no id' : $buffered['id'],
                        $cutShortMidToolCall
                            ? json_last_error_msg()
                            // Reaching here WITH an array means it decoded to a
                            // list; get_debug_type() would just say "array".
                            : 'decoded to ' . (is_array($arguments) ? 'a JSON list' : get_debug_type($arguments)),
                        $cutShortMidToolCall
                            ? 'the call was truncated, not argumentless.'
                            : 'the document decoded completely; the model emitted a malformed call, not a truncated one.',
                        var_export(self::truncate($json), true),
                    ),
                    errorTransient: $cutShortMidToolCall,
                );
            }

            return new CompleteResponse(
                content: '',
                toolCalls: [ToolCall::fromArray([
                    'id' => $buffered['id'],
                    'name' => $buffered['name'],
                    'arguments' => $arguments,
                ])],
            );
        }

        // Usage arrives split across the stream: input tokens on
        // `message_start`, output tokens on the terminal `message_delta`.
        // P4.S2 reads both events' usage documents through the SAME
        // {@see parseAnthropicUsage()} the unary arm uses - `message_start`
        // is where Anthropic-shape caches report `cache_read_input_tokens`
        // and `cache_creation_input_tokens`, and a bucket wired only on the
        // non-streaming arm would read zero on every live stream (the
        // step-brief's named failure mode). The PER-DELTA shape these two
        // returns emit is P1.S5's contract and is untouched: each event
        // still bills exactly its own side and the 0-gates still hold.
        // Since the E17 fold the buckets ride the wire too - each event's
        // carrier document names ONLY its own side's tokens, never the whole
        // parsed document (see ACCOUNTING OWNERSHIP below), so the summed
        // turn still cannot double-bill. The `message_start` gate counts the
        // cache buckets as input-side tokens (audit A15, the cache half):
        // once this class marked breakpoints, a start whose whole prompt was
        // a cache hit - `input_tokens` 0 beside a positive cache read - is a
        // real, billed event, and the old input-only gate dropped it, cost
        // and buckets together. A start with nothing on any input-side
        // bucket still yields nothing, as before.
        //
        // ACCOUNTING OWNERSHIP, split on purpose (review-2): the PARSE owns
        // the document - the Usage parseAnthropicUsage() returns prices the
        // WHOLE usage document it was handed, and that is correct for the
        // unary arm, which bills one document once. The EMIT sites below own
        // P1.S5's per-delta event shape, so each prices ITS OWN side
        // explicitly - `cost($model, $inputTokens, 0)` on `message_start`,
        // `cost($model, 0, $outputTokens)` on `message_delta` - never the
        // composed whole-document cost. Anthropic's published streaming docs
        // show a `message_start` usage can carry `output_tokens` as it
        // progresses (UNVERIFIED-documented; this repo ships no Anthropic SDK
        // to check against), and a cumulative `message_delta` can carry
        // input - a both-sided document handed to a whole-document price
        // would bill the same side twice across the summed turn. Since audit
        // A15 priced this provider the split is observable through cost too
        // (VertexPricingTest pins each event's per-side dollar figure and its
        // unpriced signal); before that, costPer1kTokens() was a placeholder
        // `return 0.0` and only the tokensUsed side could be pinned. The E17 carriers make the
        // same ownership visible beside the cost: the start carrier reports
        // only the input-side buckets, the delta carrier only the output
        // side, whatever extra fields a stray document may have carried.
        if ($type === 'message_start') {
            $usage = $this->parseAnthropicUsage(
                is_array($event['message']['usage'] ?? null) ? $event['message']['usage'] : [],
                $model,
            );

            $input = $usage->inputTokens ?? 0;
            $cacheRead = $usage->cacheReadTokens ?? 0;
            $cacheCreation = $usage->cacheCreationTokens ?? 0;

            if ($input === 0 && $cacheRead === 0 && $cacheCreation === 0) {
                return null;
            }

            $startCost = $this->cost($model, $input, 0, $cacheRead, $cacheCreation);

            return new CompleteResponse(
                content: '',
                tokensUsed: $input,
                costUsd: $startCost ?? 0.0,
                usage: Usage::new(
                    $input,
                    $startCost ?? 0.0,
                    $usage->inputTokens,
                    null,
                    $usage->cacheReadTokens,
                    $usage->cacheCreationTokens,
                    unpricedModel: $startCost === null ? $model : null,
                ),
            );
        }

        if ($type === 'message_delta') {
            // E707 (round 81): on this wire the terminal `message_delta` is
            // where `delta.stop_reason` lands, so the stream's capacity stop
            // IS observable - unlike a hard transport cut. The flag rides the
            // same frame the usage lands on; a ceiling stop that somehow
            // arrives with no billable output still yields a flag-only frame
            // rather than the null that would swallow it.
            $stopReason = is_array($event['delta'] ?? null)
                ? ($event['delta']['stop_reason'] ?? null)
                : null;
            $truncated = in_array($stopReason, self::TRUNCATED_STOP_REASONS, true);

            $usage = $this->parseAnthropicUsage(
                is_array($event['usage'] ?? null) ? $event['usage'] : [],
                $model,
            );

            if ($usage->outputTokens === null || $usage->outputTokens === 0) {
                return $truncated ? new CompleteResponse(content: '', truncated: true) : null;
            }

            $deltaCost = $this->cost($model, 0, $usage->outputTokens);

            return new CompleteResponse(
                content: '',
                tokensUsed: $usage->outputTokens,
                costUsd: $deltaCost ?? 0.0,
                usage: Usage::new(
                    $usage->outputTokens,
                    $deltaCost ?? 0.0,
                    null,
                    $usage->outputTokens,
                    unpricedModel: $deltaCost === null ? $model : null,
                ),
                truncated: $truncated,
            );
        }

        if ($type === 'error') {
            $message = $event['error']['message'] ?? sprintf('%s returned an error', $this->anthropicWireStreamLabel());

            $message = is_string($message) ? $message : sprintf('%s returned an error', $this->anthropicWireStreamLabel());

            return new CompleteResponse(
                content: '',
                isError: true,
                errorMessage: ContextOverflow::errorObjectOverflows($event['error'] ?? null)
                    ? ContextOverflow::describe($message)
                    : $message,
                // THE case this classification exists for: an overloaded
                // Anthropic-on-Vertex backend does not answer 503, it opens a
                // successful 200 SSE stream and puts
                // `{"type":"overloaded_error"}` in an `error` event. Reading
                // only HTTP statuses and exceptions would leave the provider's
                // most common transient failure unretried.
                errorTransient: TransientFailure::anthropicErrorIsTransient($event['error'] ?? null),
                errorContextOverflow: ContextOverflow::errorObjectOverflows($event['error'] ?? null),
            );
        }

        return null;
    }
    /**
     * The isError chunk both stream arms yield for a stream that ended before
     * its terminal signal (audit 15a A3) - this class's convention for a
     * failure, carrying {@see ProviderStreamException::prematureEnd()}'s
     * message and TRANSIENT verdict so the two arms and the OpenAI-shaped
     * providers cannot word or classify the cut differently.
     */
    private static function prematureEndChunk(string $prefix): CompleteResponse
    {
        $cut = ProviderStreamException::prematureEnd($prefix);

        return new CompleteResponse(
            content: '',
            isError: true,
            errorMessage: $cut->getMessage(),
            errorTransient: TransientFailure::isTransient($cut),
        );
    }
    /**
     * A truncated tool-call buffer can hold an entire file body, so the
     * fragment quoted back in an error message is bounded - the point is to
     * identify WHICH call broke, not to reproduce it.
     */
    private static function truncate(string $value, int $limit = 120): string
    {
        return strlen($value) <= $limit ? $value : substr($value, 0, $limit) . '…';
    }
    /**
     * USD for one priced side or both, or null when a side that BILLED tokens
     * has no rate on file (audit A15).
     *
     * A side with no tokens needs no rate: the streaming Anthropic arm prices
     * `message_start` as `cost($model, $in, 0)` and `message_delta` as
     * `cost($model, 0, $out)`, and a model with only one declared direction
     * must not turn the other event unpriced over tokens it never carried.
     * Null rather than 0.0 so every caller can set
     * {@see Usage::$unpricedModel} - the accounting itself stays at the 0.0
     * lower bound.
     *
     * Cache tokens are priced at their own rates (audit A15, the cache half;
     * {@see cacheCostPer1kTokens()}): `$cacheReadTokens` and
     * `$cacheWriteTokens` are DISJOINT from `$inputTokens` on every caller -
     * Anthropic's `input_tokens` counts only what follows the last
     * breakpoint, and the Gemini arm subtracts its cached subset before
     * calling. They used to be left out: harmless while this class sent no
     * breakpoints, wrong once it does, since a cached agentic prompt is
     * mostly reads.
     */
    private function cost(
        string $model,
        int $inputTokens,
        int $outputTokens,
        int $cacheReadTokens = 0,
        int $cacheWriteTokens = 0,
    ): ?float {
        $total = 0.0;

        $sides = [
            [$inputTokens, fn (): ?float => $this->costPer1kTokens($model, 'input')],
            [$outputTokens, fn (): ?float => $this->costPer1kTokens($model, 'output')],
            [$cacheReadTokens, fn (): ?float => $this->cacheCostPer1kTokens($model, 'read')],
            [$cacheWriteTokens, fn (): ?float => $this->cacheCostPer1kTokens($model, 'write')],
        ];

        foreach ($sides as [$tokens, $rateOf]) {
            if ($tokens <= 0) {
                continue;
            }

            $rate = $rateOf();
            if ($rate === null) {
                return null;
            }

            $total += $tokens * $rate;
        }

        return $total / 1000;
    }
    // -------------------------------------------------------------------------
    // Per-host hooks the shared wire delegates to (see trait docblock)
    // -------------------------------------------------------------------------

    /**
     * The body keys that are the host's own, merged before the shared fields
     * so they lead the document (key order is load-bearing for the goldens
     * that pin the transmitted bytes).
     *
     * @return array<string, mixed>
     */
    abstract protected function anthropicWireBodyPrelude(CompleteRequest $request): array;

    /** The transport name quoted in unary (non-streamed) diagnostics. */
    abstract protected function anthropicWireUnaryLabel(): string;

    /** The transport name quoted in streamed diagnostics. */
    abstract protected function anthropicWireStreamLabel(): string;
}
