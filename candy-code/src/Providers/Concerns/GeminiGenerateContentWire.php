<?php

declare(strict_types=1);

namespace SugarCraft\Code\Providers\Concerns;

use SugarCraft\Code\Messages\AssistantMessage;
use SugarCraft\Code\Messages\Message;
use SugarCraft\Code\Messages\SystemMessage;
use SugarCraft\Code\Messages\UserMessage;
use SugarCraft\Code\Providers\AttachmentEncoding;
use SugarCraft\Code\Providers\CompleteRequest;
use SugarCraft\Code\Providers\CompleteResponse;
use SugarCraft\Code\Providers\TransientFailure;
use SugarCraft\Code\Usage;

/**
 * The Gemini GenerateContent wire: the `:generateContent` request document,
 * `contents` rendering, unary and streamed response parsing, and the
 * `usageMetadata` fold.
 *
 * PRV-3 lifted this out of {@see \SugarCraft\Code\Providers\VertexProvider}
 * intact - the wire documents this trait builds and parses are
 * byte-identical to what that class produced before the move (proved by a
 * captured offline fixture sweep, 46 keys, re-run in the same commit), and
 * the only changed lines are the four error labels, which became
 * {@see geminiWireHostLabel()}, and the stream transport hand-off, which
 * became {@see geminiStreamEvents()}. Two providers now speak it: Vertex's
 * Gemini arm, and the native `generativelanguage.googleapis.com` endpoint in
 * {@see \SugarCraft\Code\Providers\GeminiProvider}.
 *
 * WHY THE DOCUMENT IS NOT QUITE ENOUGH TO SHARE RAW
 * -------------------------------------------------
 * The GenerateContent body itself has no Vertex flavoring - it is the same
 * JSON the native endpoint takes, model named in the path on both. Two
 * wire-level details are genuinely per-host, and a trait cannot guess them:
 *
 *   - WHICH TRANSPORT THE STREAM WALKS. Vertex fans the chunk iterator out
 *     of its injected `streamer` seam keyed by (endpoint, method, body);
 *     the native arm drives its own Guzzle SSE request.
 *     {@see geminiStreamEvents()} hands the shared loop its events from
 *     wherever they come from.
 *   - WHOSE NAME IS IN THE ERROR. Refusals and malformed-stream diagnostics
 *     name the transport that failed, because an operator greps for the
 *     arm they configured. {@see geminiWireHostLabel()} supplies it.
 *
 * CONTRACT ON THE USING CLASS. It must also use {@see AnthropicMessagesWire}
 * - the Gemini arm shares that trait's DEFAULT_TEMPERATURE,
 * TRUNCATED_STOP_REASONS, usageInt(), cost(), systemInstruction(),
 * stopSequences(), prematureEndChunk(), modelId() and pricing family with
 * the Vertex arms by design (PRV-3 moved the code, it did not fork it) - and
 * it must define the promoted private property `$thinkingBudget` (?int) with
 * that meaning, alongside the AMW property trio `$defaultModel`,
 * `$modelPrices`, `$promptCache`.
 */
trait GeminiGenerateContentWire
{
    // -------------------------------------------------------------------------
    // Gemini (generateContent / streamGenerateContent) request shaping
    //
    // WHY THIS ARM EXISTS. `publishers/google` fronts TWO protocols. The
    // legacy arm below builds the PaLM 2 `chat-bison` `instances`/`context`
    // envelope for `:predict`; Gemini does not read that document at all. It
    // answers `:generateContent` / `:streamGenerateContent` with
    // `{contents, systemInstruction, generationConfig}`. Before this arm, a
    // `gemini-*` id - the id both Vertex test files pin as "the Google model"
    // - was handed the PaLM 2 envelope, i.e. a request Gemini would not
    // accept.
    //
    // EPISTEMIC STATUS, stated once for the whole arm (rule 16.3): NOTHING
    // HERE HAS VERTEX CREDENTIALS. Every claim below is about the DOCUMENT
    // THIS CLASS BUILDS and the protobuf request object it hands the SDK
    // client - both measured offline, the second through the vendored REST
    // transport with a captured http handler
    // (`VertexProviderTest::callSiteRequestFor()`). That the deployed service
    // accepts or honours that document is UNVERIFIED here; it rests on the
    // vendored proto/REST config and on Google's published reference
    // (https://docs.cloud.google.com/vertex-ai/generative-ai/docs/reference/rest/v1/projects.locations.publishers.models/generateContent,
    // https://ai.google.dev/api/generate-content), not on a live call.
    // -------------------------------------------------------------------------

    /**
     * Streams a Gemini turn through `:streamGenerateContent`.
     *
     * Split out of {@see completeStream()} rather than inlined so the Gemini
     * route is one named unit next to its body builder. The catch mirrors
     * completeStream()'s: a generator's caller must see a locally-detectable
     * bad request as an error CHUNK, not as an exception thrown on first
     * iteration.
     *
     * NO USAGE-SPLIT CAVEAT HERE, unlike the Anthropic arm. Gemini repeats
     * `usageMetadata` on chunks rather than splitting input tokens onto a
     * `message_start` and output tokens onto a terminal event, so
     * {@see parseGeminiChunk()} emits a usage-bearing response only for the
     * LAST chunk that carries `usageMetadata` - see its doc-block for why
     * summing would double-count.
     *
     * @return \Generator<int, CompleteResponse>
     */
    private function streamGemini(CompleteRequest $request, string $model): \Generator
    {
        try {
            $pendingUsage = null;

            // E707 (round 81): parked by parseGeminiChunk() when a candidate
            // closes with the ceiling finishReason; a hard transport cut
            // never sets it, which is the honest "did not see" answer.
            $lengthStopped = false;

            // Audit 15a A3: whether any candidate closed with a finishReason.
            // A cut stream ends readAll() quietly, so falling out of the loop
            // proves nothing; Gemini states the end on the last candidate.
            // An error chunk (a blocked prompt) counts too: it already
            // reports the failure, and a second, transient one would
            // overwrite its verdict at the consumer.
            $sawFinish = false;

            foreach ($this->geminiStreamEvents($request, $model) as $event) {
                $event = is_array($event) ? $event : [];
                $chunk = $this->parseGeminiChunk($event, $model, $pendingUsage, $lengthStopped);

                $finishReason = is_array($event['candidates'][0] ?? null)
                    ? ($event['candidates'][0]['finishReason'] ?? null)
                    : null;
                $sawFinish = $sawFinish
                    || (is_string($finishReason) && $finishReason !== '')
                    || ($chunk !== null && $chunk->isError);

                if ($chunk !== null) {
                    yield $chunk;
                }
            }

            if (!$sawFinish) {
                // Before the usage emit: a cut turn is a failure, and the
                // parked usage still bills below (Runtime folds every chunk).
                yield self::prematureEndChunk($this->geminiWireHostLabel() . ' streamGenerateContent: ');
            }

            // E707: the flag-only frame rides BEFORE the terminal usage emit -
            // the fold above the provider consumes every frame, so the stop
            // verdict reaches the turn even when the closing candidate
            // carried no text of its own.
            if ($lengthStopped) {
                yield new CompleteResponse(content: '', truncated: true);
            }

            // Usage is emitted ONCE, after the stream ends, from the last
            // `usageMetadata` seen. Gemini restates cumulative counts on every
            // chunk that carries them, so yielding one per chunk would make
            // Runtime's cross-chunk SUM (documented on completeStream()) bill
            // a five-chunk turn five times over.
            if ($pendingUsage !== null) {
                yield $pendingUsage;
            }
        } catch (\Throwable $e) {
            yield new CompleteResponse(
                content: '',
                isError: true,
                errorMessage: $e->getMessage(),
                errorTransient: TransientFailure::isTransient($e),
            );
        }
    }

    /**
     * The Gemini `:generateContent` request document.
     *
     * THE SYSTEM PROMPT RIDES TOP-LEVEL `systemInstruction`, which is the
     * whole point of this arm. It is a `Content` - `{"parts":[{"text": …}]}` -
     * not a bare string, and it carries no `role`. Assembled by the SAME
     * {@see systemInstruction()} joiner every other envelope in this class
     * uses, so a request whose prompt is empty in the Anthropic arm is empty
     * here too rather than growing an empty `systemInstruction` object.
     *
     * Deliberately carries NO `model` key: like `rawPredict`, the model is a
     * path segment. (The SDK's `GenerateContentRequest` DOES have a `model`
     * field, and {@see defaultPredictor()} sets it from the endpoint - that is
     * the request OBJECT, not this document. The two are different things and
     * the seam signature keeps them apart.)
     *
     * NO `tools` KEY, deliberately - see {@see supportsFunctionCalling()}.
     *
     * @throws \InvalidArgumentException when the transcript yields no turns.
     *         `contents` is annotated `(.google.api.field_behavior) = REQUIRED`
     *         on the vendored proto
     *         (`vendor/google/cloud-ai-platform/src/V1/GenerateContentRequest.php`,
     *         the `contents` field's generated comment - MEASURED by reading
     *         the vendored file), so an empty list is a server-side 400 with
     *         nothing useful in it. This arm therefore takes the ANTHROPIC
     *         arm's position, not the legacy Google arm's: it rejects locally.
     *         The asymmetry with {@see googleBody()} - which accepts both an
     *         empty transcript and a system-message-only one - is deliberate
     *         and is a protocol difference, not an inconsistency: the
     *         `instances` envelope states no minimum-turn requirement, and
     *         `generateContent` does.
     *
     * @return array<string, mixed>
     */
    private function geminiBody(CompleteRequest $request): array
    {
        $contents = $this->formatGeminiContents($request->messages);

        if ($contents === []) {
            throw new \InvalidArgumentException(
                $this->geminiWireHostLabel() . ' generateContent: `contents` is a REQUIRED field and the transcript '
                . 'produced no turns (it was empty, held only system messages - which are '
                . 'hoisted into the top-level "systemInstruction" - or held only messages with '
                . 'empty content).'
            );
        }

        $body = ['contents' => $contents];

        $system = $this->systemInstruction($request);
        if ($system !== null) {
            // A `Content` with parts and NO role. Gemini rejects a `system`
            // role inside `contents`, exactly as Anthropic rejects one inside
            // `messages`.
            $body['systemInstruction'] = ['parts' => [['text' => $system]]];
        }

        // EVERY SAMPLING KNOB LIVES IN ONE NESTED OBJECT, and it must reach
        // the request. The legacy arm's `parameters` map does not
        // ({@see googleBody()}'s note on defaultPredictor()'s missing
        // setParameters()); that defect is NOT reproduced here, and
        // `VertexProviderTest::testTheGeminiCallSiteSendsGenerationConfigOnTheWire`
        // drives the real seam through the vendored REST transport and asserts
        // this object in the serialized HTTP body, so deleting the
        // `setGenerationConfig()` call reds.
        $generationConfig = [
            'temperature' => $request->temperature ?? self::DEFAULT_TEMPERATURE,
        ];

        // Audit A21 (b): NO `maxOutputTokens` UNLESS ONE WAS ASKED FOR.
        // Gemini 2.5 thinks by default and its thought tokens spend from this
        // same budget, so the 4096 this used to send (the Anthropic arm's
        // REQUIRED default, borrowed) let a hard prompt finish `MAX_TOKENS`
        // with little or no text. Unlike `max_tokens` on the Messages API the
        // field is optional here, and an absent one means the model's own
        // maximum (65,535 on the 2.5 family). A fixed raise was the other
        // option and was declined: older Gemini models cap output at 8,192
        // and Vertex answers a larger value with a 400, so any one number is
        // wrong for one family. `maxOutputTokens` in settings still sends.
        if ($request->maxTokens !== null) {
            $generationConfig['maxOutputTokens'] = $request->maxTokens;
        }

        // The other half of A21 (b): how much of that budget thinking may
        // take. Only when configured: a model that does not think may refuse
        // a `thinkingConfig`, so the operator opts in by setting it. Not
        // verified live - this repo holds no GCP credentials.
        if ($this->thinkingBudget !== null) {
            $generationConfig['thinkingConfig'] = ['thinkingBudget' => $this->thinkingBudget];
        }

        if ($request->topP !== null) {
            $generationConfig['topP'] = $request->topP;
        }

        if ($request->topK !== null) {
            $generationConfig['topK'] = $request->topK;
        }

        $stopSequences = $this->stopSequences($request);
        if ($stopSequences !== []) {
            $generationConfig['stopSequences'] = $stopSequences;
        }

        $body['generationConfig'] = $generationConfig;

        return $body;
    }

    /**
     * Renders the transcript as Gemini `contents` turns.
     *
     * THE ROLE VOCABULARY IS `user` / `model`. It is NOT `assistant`:
     * {@see formatMessages()} - which the legacy arm still uses, unchanged -
     * emits `assistant`, and Gemini does not know that role. That single word
     * is why this arm cannot share the legacy formatter.
     *
     * SystemMessage is dropped here, hoisted by {@see systemInstruction()}.
     * A message whose content is empty contributes no turn at all: a `Part`
     * with an empty `text` is not a useful turn, and the empty-transcript
     * guard in {@see geminiBody()} is what then reports it.
     *
     * TWO THINGS THIS DOES NOT DO, both recorded rather than silently absent:
     *
     *   - It does not MERGE consecutive same-role turns the way
     *     {@see formatAnthropicMessages()} must (Anthropic rejects two `user`
     *     turns in a row). Whether Gemini requires strict alternation is
     *     UNVERIFIED here - nothing in this repo can call the service - so the
     *     transcript is transmitted turn-for-turn rather than reshaped on a
     *     guess. The 1:1 mapping is pinned by
     *     `VertexProviderTest::testGeminiContentsAreNotMergedAcrossConsecutiveSameRoleTurns`
     *     so that adopting a merge later is a visible decision.
     *   - It does not emit a `functionResponse` part for a ToolResultMessage;
     *     that message renders as an ordinary `user` text turn, because no
     *     `functionCall` is ever requested ({@see supportsFunctionCalling()}).
     *
     * @param array<Message> $messages
     * @return array<int, array{role: string, parts: array<int, array{text: string}>}>
     */
    private function formatGeminiContents(array $messages): array
    {
        $contents = [];

        foreach ($messages as $msg) {
            if (!$msg instanceof Message || $msg instanceof SystemMessage) {
                continue;
            }

            // Audit 15b-15: a user turn's inlined files and inlineData images.
            $parts = $msg instanceof UserMessage
                ? AttachmentEncoding::geminiParts($msg)
                : ($msg->content() === '' ? [] : [['text' => $msg->content()]]);
            if ($parts === []) {
                continue;
            }

            $contents[] = [
                'role' => $msg instanceof AssistantMessage ? 'model' : 'user',
                'parts' => $parts,
            ];
        }

        return $contents;
    }

    // -------------------------------------------------------------------------
    // Gemini response parsing
    // -------------------------------------------------------------------------

    /**
     * A `GenerateContentResponse` is `candidates[0].content.parts[*].text`
     * plus `usageMetadata` - an entirely different document from both
     * `predictions[0]` ({@see parseResponse()}) and the Anthropic content-block
     * list ({@see parseAnthropicResponse()}).
     *
     * A PROMPT-LEVEL BLOCK IS A 200, NOT AN HTTP ERROR. When a safety filter
     * rejects the INPUT, Gemini answers success with no candidates and a
     * `promptFeedback.blockReason`. Read naively that is an empty completion,
     * which is indistinguishable from a model that chose to say nothing - so
     * it is reported as an error response instead, and a permanent one: the
     * same input blocked once will be blocked again, so retrying it is waste.
     *
     * TRANSPORT ERRORS DO NOT ARRIVE HERE. `:generateContent` reports them as
     * an HTTP status, which the vendored REST transport raises as an
     * `ApiException` carrying only the gRPC code and status name; that is
     * caught by {@see complete()} (and by {@see streamGemini()} on the stream
     * arm) and classified by {@see TransientFailure::isTransient()}, which
     * judges an ApiException by that gRPC status - a 429 `RESOURCE_EXHAUSTED`
     * or 503 `UNAVAILABLE` retries, a 401 `UNAUTHENTICATED` does not (audit
     * A19; before it, every ApiException fell through as permanent). There
     * is deliberately no `$data['error']` branch in this method, because on
     * this protocol no such document reaches it.
     *
     * KNOWN-INCOMPLETE SEAM - `thought` parts. Gemini 2.5 marks a reasoning
     * part with `"thought": true`. This method folds every part's text into
     * `content` and has no reasoning split, because {@see geminiBody()} never
     * sends a `thinkingConfig` with `includeThoughts`, so no thought PART is
     * ever returned. That is a statement about parts only: Gemini 2.5 still
     * THINKS by default, and the tokens it spends doing so arrive in
     * `usageMetadata.thoughtsTokenCount`, which {@see parseUsageMetadata()}
     * folds into the usage (audit A21 a). Whoever enables thought parts
     * closes the text half.
     *
     * @param array<string, mixed> $data
     */
    private function parseGeminiResponse(array $data, string $model): CompleteResponse
    {
        $blockReason = $data['promptFeedback']['blockReason'] ?? null;
        $candidates = is_array($data['candidates'] ?? null) ? $data['candidates'] : [];

        if ($candidates === [] && is_string($blockReason) && $blockReason !== '') {
            return new CompleteResponse(
                content: '',
                isError: true,
                errorMessage: sprintf(
                    $this->geminiWireHostLabel() . ' generateContent: the prompt was blocked before generation (%s).',
                    $blockReason,
                ),
                // Deterministic in the input, so a retry re-sends the same
                // blocked prompt. See TransientFailure.
                errorTransient: false,
            );
        }

        // P4.S2: one parsed Usage is the source of every usage number
        // leaving this method; tokensUsed/costUsd keep their exact prior
        // expressions for legitimate wire values (a negative count clamps
        // to 0 per Usage's doctrine, as in SglangProvider::parseResponse()).
        // E17: and the parsed document itself now rides out on `usage:`, so
        // the unary Gemini reply delivers its buckets to Runtime as well.
        $usage = $this->parseUsageMetadata(
            is_array($data['usageMetadata'] ?? null) ? $data['usageMetadata'] : [],
            $model,
        );

        // E707 (round 81): a truncated unary answer is the one the ceiling
        // stopped, not the model - the candidate's finishReason says which.
        $firstCandidate = $candidates[0] ?? null;

        return new CompleteResponse(
            content: $this->geminiText($firstCandidate),
            reasoning: null,
            toolCalls: null,
            tokensUsed: $usage->totalTokens,
            costUsd: $usage->costUsd,
            usage: $usage,
            truncated: is_array($firstCandidate)
                && in_array($firstCandidate['finishReason'] ?? null, self::TRUNCATED_STOP_REASONS, true),
        );
    }

    /**
     * Translates one decoded `streamGenerateContent` chunk into a delta
     * response, or null when it carries no text.
     *
     * USAGE IS CUMULATIVE ON THIS PROTOCOL, NOT SPLIT. Gemini restates
     * `usageMetadata` on the chunks that carry it, so this method never
     * YIELDS usage - it parks the latest one in `$pendingUsage`, and
     * {@see streamGemini()} emits that single response after the stream ends.
     * Yielding per chunk would be double-counted by
     * {@see \SugarCraft\Code\Runtime}, which SUMS `tokensUsed` across chunks
     * because the Anthropic arm genuinely does split its usage in two.
     *
     * @param array<string, mixed> $event
     */
    private function parseGeminiChunk(
        array $event,
        string $model,
        ?CompleteResponse &$pendingUsage,
        bool &$lengthStopped,
    ): ?CompleteResponse {
        $blockReason = $event['promptFeedback']['blockReason'] ?? null;

        if (is_string($blockReason) && $blockReason !== '') {
            return new CompleteResponse(
                content: '',
                isError: true,
                errorMessage: sprintf(
                    $this->geminiWireHostLabel() . ' streamGenerateContent: the prompt was blocked before generation (%s).',
                    $blockReason,
                ),
                errorTransient: false,
            );
        }

        // P4.S2: same parse as the unary Gemini arm (see
        // {@see parseGeminiResponse()}'s comment) - cache buckets cannot be
        // wired on one arm and missed on the other. The PARK-THEN-EMIT-ONCE
        // semantics are P1.S5-pinned and untouched: cumulative
        // `usageMetadata` still lands as ONE trailing response, and the
        // gate parks on the same condition - a non-zero wire total. It is
        // totalTokens and NOT inputTokens that must drive it: an all-cached
        // prompt (promptTokenCount == cachedContentTokenCount, e.g. 10/10
        // with candidates 0) parks a 10-token bill today, and gating on the
        // DERIVED fresh-input bucket would silently drop exactly that turn's
        // usage. (Negative wire values now clamp the total to 0, and a 0-total
        // document does not park at ALL - where the old inline parse parked a
        // negative tokensUsed; Usage's clamp doctrine, pinned by
        // testP4S2VertexGeminiNegativeStreamDocumentParksNothing.)
        $usage = $this->parseUsageMetadata(
            is_array($event['usageMetadata'] ?? null) ? $event['usageMetadata'] : [],
            $model,
        );

        if ($usage->totalTokens !== 0) {
            // The parked response carries the whole cumulative document
            // (E17) - one emit, so unlike the Anthropic arms there is no
            // other side to protect from a double count.
            $pendingUsage = new CompleteResponse(
                content: '',
                tokensUsed: $usage->totalTokens,
                costUsd: $usage->costUsd,
                usage: $usage,
            );
        }

        $candidates = is_array($event['candidates'] ?? null) ? $event['candidates'] : [];

        // E707 (round 81): the finishReason lands on the LAST candidate
        // chunk, which routinely carries no text at all - so the verdict is
        // parked in the by-ref flag the generator owns rather than attached
        // to whichever frame happens to exist. streamGemini() turns a parked
        // ceiling stop into its own frame after the loop.
        $finishReason = is_array($candidates[0] ?? null) ? ($candidates[0]['finishReason'] ?? null) : null;
        if (in_array($finishReason, self::TRUNCATED_STOP_REASONS, true)) {
            $lengthStopped = true;
        }

        $text = $this->geminiText($candidates[0] ?? null);

        return $text === '' ? null : new CompleteResponse(content: $text);
    }

    /**
     * The concatenated text of one candidate's parts.
     *
     * A candidate legitimately has NO parts - `finishReason: SAFETY` on the
     * OUTPUT truncates the content object away - and that is not an error
     * here, it is an empty delta.
     */
    private function geminiText(mixed $candidate): string
    {
        if (!is_array($candidate)) {
            return '';
        }

        $parts = $candidate['content']['parts'] ?? [];
        if (!is_array($parts)) {
            return '';
        }

        $text = '';

        foreach ($parts as $part) {
            if (is_array($part) && is_string($part['text'] ?? null)) {
                $text .= $part['text'];
            }
        }

        return $text;
    }

    /**
     * Parses one Gemini `usageMetadata` document into the provider-counted
     * token BUCKETS (prompt_plan.md P4.S2).
     *
     * RELOCATION, not removal (§1.10's move exception, stated per that
     * section's own rule): this is the private `geminiUsage()` the unary and
     * streaming Gemini arms destructured, same fields, same per-direction
     * pricing inputs, new home returning a Usage, all callers updated in the
     * same commit. The warning its docblock carried stays load-bearing and
     * travels verbatim: `usageMetadata.promptTokenCount` /
     * `.candidatesTokenCount` are Gemini's spellings of the Anthropic arm's
     * `input_tokens` / `output_tokens`. `totalTokenCount` is deliberately NOT
     * read: only the per-field form can be priced per direction, and the
     * total is re-derived from those fields instead (prompt + candidates +
     * thoughts - which is what Google's own total counts, see the thinking
     * paragraph below). The existing `VertexProviderTest` pins that omission
     * with a fixture whose `totalTokenCount` is 99.
     *
     * CACHE-FIELD FINDING, LOCALLY PROVEN (the strongest class the brief
     * allows short of a live call): the vendored protobuf class
     * `Google\Cloud\AIPlatform\V1\GenerateContentResponse\UsageMetadata`
     * defines `cachedContentTokenCount`, and its own field comment -
     * MEASURED at the vendored
     * `GenerateContentResponse\UsageMetadata::getCachedContentTokenCount()` comment - reads "Number of
     * tokens in the cached part in the input (the cached content)". That
     * makes it a SUBSET of `promptTokenCount`, not a third disjoint side, so
     * this arm takes the subtraction path the OpenAI-family parses use
     * (fresh input = prompt - cached, floored at 0) and NOT the direct map
     * the Anthropic arm of this very class uses. Getting those two
     * conventions backwards is the quiet way every cache readout lies, so
     * each arm's parse names its convention where it is applied.
     *
     * NO cache-creation token field exists in this document - Gemini's
     * explicit caching bills stored content by time, and the same proto
     * class carries no such member (MEASURED from it: its fields are
     * prompt/candidates/total/cached counts, per-modality detail lists,
     * traffic type, and thoughts) - so `cacheCreationTokens` is null on
     * every parse, the recorded "API reports none" outcome, never invented.
     *
     * THINKING TOKENS ARE OUTPUT (audit A21 a). Gemini 2.5 thinks by default
     * even with no `thinkingConfig` in the request - omitting it only hides
     * the thought PARTS - and `thoughtsTokenCount` reports that thinking
     * separately from `candidatesTokenCount`, which EXCLUDES it. Google bills
     * thought tokens at the output rate and counts them against
     * `maxOutputTokens`. This parse used to drop the field, so every Gemini
     * 2.5 turn under-counted its output, its total and (once priced, A15) its
     * cost. It is now folded the way {@see Usage::$reasoningTokens} defines
     * the bucket: `outputTokens` = candidates + thoughts (the billed output),
     * `reasoningTokens` = thoughts (a sub-bucket of output, never added to the
     * total a second time), and the output side is priced on the sum. Absent
     * thoughts leave every figure exactly as before and `reasoningTokens`
     * null (unreported). A negative thought count is treated as 0 rather
     * than subtracted from the output.
     *
     * @param array<string, mixed> $usageMetadata the decoded `usageMetadata`
     *                                            document; non-array arrives
     *                                            as `[]` via the call sites
     */
    public function parseUsageMetadata(array $usageMetadata, string $model): Usage
    {
        $promptRaw = self::usageInt($usageMetadata['promptTokenCount'] ?? null);
        $candidatesRaw = self::usageInt($usageMetadata['candidatesTokenCount'] ?? null);
        $cached = self::usageInt($usageMetadata['cachedContentTokenCount'] ?? null);

        // The exact pair geminiUsage()'s callers built: absent-or-null
        // counts as 0 for the sum and the per-direction price.
        $prompt = $promptRaw ?? 0;
        $candidates = $candidatesRaw ?? 0;

        // Audit A21 (a): thinking is billed output - see the docblock.
        $thoughtsRaw = self::usageInt($usageMetadata['thoughtsTokenCount'] ?? null);
        $output = $candidates + max(0, $thoughtsRaw ?? 0);

        // Audit A15: unknown Gemini families bill the 0.0 lower bound with
        // their name carried, never a silent zero. The cached subset of the
        // prompt bills at the cache-read rate and the rest at the input rate
        // (the cache half of A15): `cachedContentTokenCount` is INSIDE
        // `promptTokenCount`, so pricing the whole prompt at the input rate
        // charged every implicit-cache hit Gemini 2.5 reports at ten times
        // its price. Capped at the prompt so an over-report bills nothing
        // that was never sent.
        $cachedBilled = max(0, min($cached ?? 0, $prompt));
        $cost = $this->cost($model, $prompt - $cachedBilled, $output, $cachedBilled);

        return Usage::new(
            $prompt + $output,
            $cost ?? 0.0,
            // Unreported prompt stays unreported even when cached is known -
            // the difference would be a guess, and Usage's contract is that
            // a bucket holds only what the provider said or derived from
            // what it said.
            $promptRaw === null ? null : ($cached === null ? $promptRaw : max(0, $promptRaw - $cached)),
            // Unreported only when NEITHER output-side field was reported.
            $candidatesRaw === null && $thoughtsRaw === null ? null : $output,
            $cached,
            null, // no cache-creation field on this protocol - never invented
            $thoughtsRaw,
            unpricedModel: $cost === null ? $model : null,
        );
    }
    /**
     * Whose name the Gemini-arm diagnostics carry - 'Vertex' or 'Gemini'.
     * Each host's spellings are the exact literals its arm emitted before
     * the PRV-3 extraction.
     */
    abstract protected function geminiWireHostLabel(): string;

    /**
     * The decoded `streamGenerateContent` event documents, in order, from
     * whatever transport the using class drives. Thrown transport errors
     * surface here and are classified by the shared loop's catch.
     *
     * @return iterable<int, array<string,mixed>>
     */
    abstract protected function geminiStreamEvents(CompleteRequest $request, string $model): iterable;
}
