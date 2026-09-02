<?php

declare(strict_types=1);

namespace SugarCraft\Crush;

/**
 * What one provider call — or a whole turn's worth of them summed — actually
 * cost, in PROVIDER-COUNTED tokens and dollars (crush_code.md Phase 5 item 7).
 * Since prompt_plan.md P4.S1 (backlog E17) it also carries the provider's own
 * token BUCKETS — input/output and the two cache sides — null until a provider
 * reports each one, and never defaulted to a number a provider did not say.
 *
 * Read the unit before using this. Everything the status bar's *context*
 * readout shows ({@see Chat::contextTokens()}, {@see Renderer::contextIndicator()})
 * is a chars/4 ESTIMATE and is deliberately printed with a leading `~`. The
 * numbers here are the provider's own count, arriving on
 * {@see Providers\CompleteResponse::$tokensUsed} / `$costUsd`. The two are
 * different units measuring different things: an estimate of what was SENT
 * versus a count of what was BILLED. They must never be summed, compared, or
 * shown as one figure.
 *
 * ## The split now lives here — parsed at the providers, still uncrossed at the carrier
 *
 * CORRECTED IN PLACE, prompt_plan.md P4.S1 (backlog E17), in the three parts
 * §16.8 rule 42 demands. WHAT IT SAID: this section was titled "Why there is no
 * input/output split here" and argued the class "cannot honestly supply" one.
 * WHAT IS TRUE NOW: the split and its two cache buckets are fields on this
 * class — `inputTokens`, `outputTokens`, `cacheReadTokens`,
 * `cacheCreationTokens`, with {@see promptTokens()} carrying the prompt-side
 * identity `total = cacheRead + cacheCreation + input`. The claim it was built
 * on was half right, and the half that held has now moved: `CompleteResponse`
 * still carries a single `$tokensUsed` and nothing else — that stays true —
 * but "every live construction path in `src/` leaves the buckets null" was
 * P4.S1's truth and is no longer this branch's: prompt_plan.md P4.S2 routed
 * all five split-reading providers' usage documents through these buckets at
 * their `parseUsage` seams. Each parsed instance now ends its life at the
 * provider's own `tokensUsed`/`costUsd` projection, so no Usage that REACHES
 * `Runtime` or `Chat` from a live call carries buckets yet. What remains is
 * widening `CompleteResponse` alone — a later seam, and it is why the
 * enumeration below is kept: it is the record of which provider reads which
 * usage key, the work-list the carrier change plugs into.
 * WHY IT EARNS ITS PLACE: delete the reasoning and the next reader deletes the
 * buckets as vestigial; the reasoning is what marks them load-bearing-ahead.
 *
 * {@see Util\TokenTracker::addUsage()} wants input and output separately, and
 * until the carrier lands this class still cannot honestly SUPPLY them to a
 * live TURN: providers parse the buckets, but `CompleteResponse` carries a
 * single `$tokensUsed` and nothing else, so what arrives across it is a total.
 *
 * FIVE of the seven providers know the split — read the count with its
 * domain, because stale literals of it are what drifted before.
 * {@see Providers\BedrockProvider} reads `usage.inputTokens` /
 * `usage.outputTokens`, {@see Providers\VertexProvider} reads
 * `usage.input_tokens` / `usage.output_tokens`,
 * {@see Providers\OpenAIProvider} reads `usage.prompt_tokens` /
 * `usage.completion_tokens` and prices each side at its own rate in
 * `calculateCost()`, and — since prompt_plan.md P4.S2 routed its family read
 * through the parse seam — {@see Providers\SglangProvider} reads
 * `usage.prompt_tokens` / `usage.completion_tokens`, the same pair
 * {@see Providers\CustomProvider} reads. All five now PARSE the split into
 * the buckets above; none yet CARRIES it, because every one still reports
 * `tokensUsed` as one number. The
 * remaining two ({@see Providers\ClaudeCodeProvider},
 * {@see Providers\EchoProvider}) never had a split to lose: they read
 * `usage.total_tokens` or report 0.
 *
 * That collapse happens at the provider's `CompleteResponse` boundary on every
 * UNARY path — and on Bedrock's and OpenAI's streaming paths too. Vertex's
 * Anthropic stream is the one exception and it matters: it emits input tokens
 * on `message_start` and output tokens on the terminal `message_delta`, as two
 * separate `CompleteResponse`s with `tokensUsed: $usage->inputTokens` and
 * `tokensUsed: $usage->outputTokens` ({@see Providers\VertexProvider::parseAnthropicChunk()}).
 * {@see Runtime}'s streaming path documents that and SUMS them, which is why the
 * figure arriving here is still a total. So the split survives one layer lower
 * than this seam in exactly one case, and is recoverable there without touching
 * any provider — see `Runtime`.
 *
 * Passing `addUsage($total, 0, $cost)` would fabricate a split —
 * `outputTokens()` reporting 0 for a real completion — so the tracker gained
 * {@see Util\TokenTracker::addTotalUsage()} for exactly this shape instead, and
 * it still earns its keep: what crosses that seam today is still a total with
 * null buckets beside it, not a split — the buckets are filled INSIDE each
 * split-reading provider and stop at its carrier. Recovering the real split
 * for callers now means only widening `CompleteResponse`; prompt_plan.md
 * P4.S2 already routed the five providers that know the split through these
 * buckets, and the carrier change is a separate step, not pretended to here.
 *
 * ## Zero is not the same as unknown
 *
 * A streamed turn commonly reports NO usage at all: `Runtime::runStreaming()`'s
 * docblock and {@see Providers\OpenAIProvider}'s both state that chunks carry
 * `tokensUsed=0`, and a self-hosted provider's `costPer1kTokens()` is a
 * genuine 0.0. So `0 tokens / $0.00` is the answer for "nothing was reported"
 * AND for "this really was free", and a readout that prints `$0.0000` cannot
 * tell a user which. This class resolves that by never existing in the first
 * case: {@see reported()} returns null when the provider offered neither a
 * token count nor a cost, and a null `Usage` on a {@see Message} means
 * "nothing reported", not "nothing spent". A cost of exactly 0.0 alongside a
 * positive token count is therefore meaningful — it is a real, free call.
 *
 * The buckets keep the same contract one level down: `cacheReadTokens = 0`
 * means the provider measured zero cache reads, and `cacheReadTokens = null`
 * means it said nothing about cache reads. "Unreported" is therefore exactly
 * `null` for each bucket — there is no third state, and no arithmetic on this
 * class ever turns a null bucket into a zero: {@see promptTokens()} refuses to
 * total across an unreported bucket, {@see plus()} carries a reported bucket
 * through a merge that never measured one, and {@see fromArray()} decodes a
 * frame written before the buckets existed — which simply lacks those keys —
 * to unreported buckets rather than to four zeroes. That last one is why a
 * bucket must be added to BOTH halves of the array pair: the fork boundary's
 * parent cannot receive the object, so a key dropped from `toArray()` makes
 * the async path read that bucket UNREPORTED forever — accounting lost on
 * one side only, with sync mode staying green — while the mirror error,
 * `fromArray()` coercing an absent key to a number, fabricates a measured
 * zero. Both are silent; both are the async side; the pair must stay
 * symmetric.
 */
final readonly class Usage
{
    private function __construct(
        /** Provider-counted tokens, input and output together. Never negative. */
        public int $totalTokens,
        /**
         * Dollars, as the provider reported or as its own `costPer1kTokens()`
         * table computed. Exactly 0.0 is legitimate for a self-hosted or
         * shell-out provider; see the class docblock on why that is
         * distinguishable from "unknown" only by this object's existence.
         */
        public float $costUsd,
        /**
         * Prompt tokens the provider counted AFTER the last cache breakpoint —
         * the Anthropic-shaped `input_tokens`, deliberately NOT the whole
         * prompt. Null until a provider reports it; 0 means it reported zero.
         * Never negative (a negative is a provider bug, accounted as zero).
         */
        public ?int $inputTokens = null,
        /** Completion tokens. Never part of {@see promptTokens()} — see it. */
        public ?int $outputTokens = null,
        /** Tokens read from the provider's prompt cache. */
        public ?int $cacheReadTokens = null,
        /** Tokens written to the provider's prompt cache. */
        public ?int $cacheCreationTokens = null,
    ) {}

    public static function new(
        int $totalTokens = 0,
        float $costUsd = 0.0,
        ?int $inputTokens = null,
        ?int $outputTokens = null,
        ?int $cacheReadTokens = null,
        ?int $cacheCreationTokens = null,
    ): self {
        return new self(
            max(0, $totalTokens),
            max(0.0, $costUsd),
            self::clampBucket($inputTokens),
            self::clampBucket($outputTokens),
            self::clampBucket($cacheReadTokens),
            self::clampBucket($cacheCreationTokens),
        );
    }

    /**
     * The usage a provider call actually reported, or null when it reported
     * nothing measurable.
     *
     * Null rather than a zero-valued instance, because the whole point of the
     * distinction is that a caller can tell the two apart — see the class
     * docblock. Non-positive inputs are clamped rather than rejected: a
     * negative count is a provider bug, and failing a turn over it would be
     * worse than accounting it as zero.
     *
     * The buckets widen the SAME question, not a new one: a frame whose total
     * and cost are both zero but which reports a bucket HAS said something
     * measurable — a free self-hosted call that still counted cache reads is
     * as real as a free call that counted tokens — so it returns a Usage.
     * Callers that only know a total pass only a total, and every bucket
     * arrives null (unreported), never a fabricated zero.
     */
    public static function reported(
        int $totalTokens,
        float $costUsd,
        ?int $inputTokens = null,
        ?int $outputTokens = null,
        ?int $cacheReadTokens = null,
        ?int $cacheCreationTokens = null,
    ): ?self {
        if (
            $totalTokens <= 0
            && $costUsd <= 0.0
            && $inputTokens === null
            && $outputTokens === null
            && $cacheReadTokens === null
            && $cacheCreationTokens === null
        ) {
            return null;
        }

        return self::new($totalTokens, $costUsd, $inputTokens, $outputTokens, $cacheReadTokens, $cacheCreationTokens);
    }

    /**
     * This usage plus $other's — the operation an agentic TURN needs, because
     * one turn is N provider calls, not one.
     *
     * {@see Backend\EngineBackend::complete()} runs a bounded loop of up to
     * `$maxSteps` provider calls, each with its own usage, and a readout
     * showing the LAST step's figure while calling it the turn's would
     * under-report every turn that used a tool. This is what makes the turn
     * total a sum over steps.
     */
    public function plus(self $other): self
    {
        return new self(
            $this->totalTokens + $other->totalTokens,
            $this->costUsd + $other->costUsd,
            self::plusBucket($this->inputTokens, $other->inputTokens),
            self::plusBucket($this->outputTokens, $other->outputTokens),
            self::plusBucket($this->cacheReadTokens, $other->cacheReadTokens),
            self::plusBucket($this->cacheCreationTokens, $other->cacheCreationTokens),
        );
    }

    /**
     * One bucket of {@see plus()}: two reported buckets sum; one reported and
     * one not keeps the reported figure; two unreported stay unreported.
     *
     * A half-known sum carried as a number is the same choice the turn-total
     * machinery already makes for a step that reported nothing — {@see sum()}
     * skips nulls rather than nulling the total, "preserving the 'nothing
     * reported' answer" only when NO step reported. The surviving figure is a
     * lower bound for the merge (the unreported step may have read more from
     * cache, and said nothing); collapsing a measured 300 to null would throw
     * away the 300 the provider DID measure, which is the failure this class's
     * whole docblock argues against. What must never happen is the third
     * option — treating the unreported half as a zero *claim*: that is what
     * `promptTokens()` refuses across a null bucket.
     */
    private static function plusBucket(?int $own, ?int $theirs): ?int
    {
        if ($own === null) {
            return $theirs;
        }

        if ($theirs === null) {
            return $own;
        }

        return $own + $theirs;
    }

    /**
     * The prompt-side total, the identity the cache-bucket split exists to make
     * computable: `promptTokens = cacheRead + cacheCreation + input`
     * (prompt_plan.md P4.S1, backlog E17; prompt_expand.md §9.14).
     *
     * TWO DELIBERATE OMISSIONS, each pinned by a test so the next reader
     * does not "fix" them.
     *
     * 1. `outputTokens` is NOT in here. This is the count of what was SENT and
     *    billed as prompt — what the 95% context tier must stop estimating with
     *    chars/4 — and what the model wrote is not part of what was sent.
     *    {@see $totalTokens} remains the provider's own billable total and is a
     *    different figure measured over a different span; this accessor derives
     *    nothing from it and it derives nothing from this accessor.
     * 2. `inputTokens` alone is NOT the prompt either — that is the flip side of
     *    the same fact. On an Anthropic-shaped usage object `input_tokens`
     *    counts only the tokens AFTER the last cache breakpoint, so anything
     *    that was cache-read or cache-created has to be added back for the sum
     *    to be the prompt.
     *
     * Null unless ALL THREE member buckets were reported: a missing bucket does
     * not silently become 0 where null is the truth, so a provider that said
     * nothing about one bucket voids the total that bucket would have fed —
     * see the class docblock's "Zero is not the same as unknown".
     */
    public function promptTokens(): ?int
    {
        if ($this->inputTokens === null || $this->cacheReadTokens === null || $this->cacheCreationTokens === null) {
            return null;
        }

        return $this->inputTokens + $this->cacheReadTokens + $this->cacheCreationTokens;
    }

    /**
     * The usage with a reported/unreported input bucket set. Passing null
     * clears it back to unreported; the other buckets ride along untouched —
     * that is what the paired `bool $xSet` sentinels on {@see mutate()} are
     * for (repo convention; canonical `candy-sprinkles/src/Style.php`).
     */
    public function withInputTokens(?int $tokens): self
    {
        return $this->mutate(inputTokens: $tokens, inputTokensSet: true);
    }

    /** As {@see withInputTokens()}, for the completion side. */
    public function withOutputTokens(?int $tokens): self
    {
        return $this->mutate(outputTokens: $tokens, outputTokensSet: true);
    }

    /** As {@see withInputTokens()}, for the cache-read bucket. */
    public function withCacheReadTokens(?int $tokens): self
    {
        return $this->mutate(cacheReadTokens: $tokens, cacheReadTokensSet: true);
    }

    /** As {@see withInputTokens()}, for the cache-creation bucket. */
    public function withCacheCreationTokens(?int $tokens): self
    {
        return $this->mutate(cacheCreationTokens: $tokens, cacheCreationTokensSet: true);
    }

    /**
     * Immutable fluent base — every `with*()` returns a new instance through
     * here, changing only the buckets whose sentinel says "the caller passed
     * this one". Values are clamped on the way in exactly as {@see new()}
     * clamps: a negative count is a provider bug accounted as zero, but null
     * (unreported) is NOT a negative and must survive as null.
     */
    private function mutate(
        ?int $inputTokens = null,
        bool $inputTokensSet = false,
        ?int $outputTokens = null,
        bool $outputTokensSet = false,
        ?int $cacheReadTokens = null,
        bool $cacheReadTokensSet = false,
        ?int $cacheCreationTokens = null,
        bool $cacheCreationTokensSet = false,
    ): self {
        return new self(
            $this->totalTokens,
            $this->costUsd,
            $inputTokensSet ? self::clampBucket($inputTokens) : $this->inputTokens,
            $outputTokensSet ? self::clampBucket($outputTokens) : $this->outputTokens,
            $cacheReadTokensSet ? self::clampBucket($cacheReadTokens) : $this->cacheReadTokens,
            $cacheCreationTokensSet ? self::clampBucket($cacheCreationTokens) : $this->cacheCreationTokens,
        );
    }

    /** A negative bucket is a provider bug accounted as zero; null is "unreported" and is not a number to clamp. */
    private static function clampBucket(?int $tokens): ?int
    {
        return $tokens === null ? null : max(0, $tokens);
    }

    /**
     * Sum of a list of per-call usages, or null when the list is empty or
     * holds only nulls — preserving the "nothing reported" answer rather than
     * turning it into a zero.
     *
     * @param list<?self> $usages
     */
    public static function sum(array $usages): ?self
    {
        $total = null;
        foreach ($usages as $usage) {
            if ($usage === null) {
                continue;
            }
            $total = $total === null ? $usage : $total->plus($usage);
        }

        return $total;
    }

    /**
     * Plain-array form for the {@see Backend\EngineBackend::completeAsync()}
     * fork boundary, whose parent unserializes with `allowed_classes => false`
     * and so cannot receive the object itself — same rule
     * {@see Backend\EngineBackend::encodeEvent()} follows for tool events.
     *
     * EVERY field of the object is on the wire, including the four buckets and
     * their null-ness: a bucket carried across here as null must come back as
     * UNREPORTED, not as zero, or the fork path silently rewrites the one
     * distinction this class exists to keep.
     *
     * @return array{totalTokens:int,costUsd:float,inputTokens:?int,outputTokens:?int,cacheReadTokens:?int,cacheCreationTokens:?int}
     */
    public function toArray(): array
    {
        return [
            'totalTokens' => $this->totalTokens,
            'costUsd' => $this->costUsd,
            'inputTokens' => $this->inputTokens,
            'outputTokens' => $this->outputTokens,
            'cacheReadTokens' => $this->cacheReadTokens,
            'cacheCreationTokens' => $this->cacheCreationTokens,
        ];
    }

    /**
     * Rebuild from {@see toArray()}, tolerating anything that is not the shape
     * it wrote: the payload has crossed a socket, and a corrupt frame must
     * cost the turn its accounting, not the turn itself.
     *
     * Tolerating a shape it did not write includes the shape it wrote BEFORE
     * the buckets existed: missing bucket keys decode to unreported (`null`),
     * never to zeroes. A value under a bucket key that is neither `null` nor
     * an int is not a frame this method's counterpart emits — the whole frame
     * is refused, on the same doctrine as the malformed `totalTokens` beside it.
     *
     * @param mixed $raw
     */
    public static function fromArray(mixed $raw): ?self
    {
        if (!is_array($raw)) {
            return null;
        }

        $tokens = $raw['totalTokens'] ?? null;
        $cost = $raw['costUsd'] ?? null;
        if (!is_int($tokens) || !(is_float($cost) || is_int($cost))) {
            return null;
        }

        $buckets = [];
        foreach (['inputTokens', 'outputTokens', 'cacheReadTokens', 'cacheCreationTokens'] as $key) {
            $value = $raw[$key] ?? null;
            // Absent or null decodes to UNREPORTED. That is the whole tolerance
            // rule, and the shape it tolerates is not hypothetical: a frame
            // written before the buckets existed simply lacks the keys, and
            // reading that as four zeroes would cross the socket turning "the
            // provider said nothing" into "the cache was never read".
            // Anything else that is not a plain int is a frame this method did
            // not write — refused whole, per the docblock above.
            if ($value !== null && !is_int($value)) {
                return null;
            }

            $buckets[$key] = $value;
        }

        return self::reported(
            $tokens,
            (float) $cost,
            $buckets['inputTokens'],
            $buckets['outputTokens'],
            $buckets['cacheReadTokens'],
            $buckets['cacheCreationTokens'],
        );
    }
}
