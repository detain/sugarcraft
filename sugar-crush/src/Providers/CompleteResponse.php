<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Providers;

final readonly class CompleteResponse
{
    public function __construct(
        public string $content,
        public ?string $reasoning = null,
        public ?array $toolCalls = null,
        public int $tokensUsed = 0,
        public float $costUsd = 0.0,
        public bool $isError = false,
        public ?string $errorMessage = null,
        /**
         * Whether the failure behind {@see $isError} is worth another attempt
         * — {@see TransientFailure::isTransient()}'s verdict on the live
         * exception, recorded at the catch site while that exception still
         * exists (crush_code.md Phase 5 item 8).
         *
         * This field exists because {@see CustomProvider} and
         * {@see VertexProvider} report a failed call as an error RESPONSE
         * rather than by throwing, and both used to keep only
         * `$e->getMessage()`. Without somewhere to put the verdict, a retry
         * layer above them would have had to re-derive transience by
         * pattern-matching that prose — so the two providers a user is most
         * likely to be running would either never retry or would retry on a
         * substring. Classifying where the exception is still in hand is the
         * same information from its real source.
         *
         * Null means UNCLASSIFIED, which is not the same claim as "permanent"
         * but is treated the same way: {@see
         * TransientFailure::responseIsTransient()} requires an explicit true,
         * because the allow-list rule that governs unrecognised exceptions has
         * to govern unrecognised error responses too.
         *
         * SIX sites set it, and only four of them are catch sites — stated
         * precisely because an earlier version of this docblock said "the two
         * catch sites above", which was wrong on both the count and the
         * mechanism. {@see CustomProvider}'s `complete()` and `completeStream()`
         * catches and {@see VertexProvider}'s two exception catches classify a
         * live `\Throwable` with {@see TransientFailure::isTransient()}. The
         * other two hold no exception at all: `VertexProvider`'s rawPredict
         * error object and its 200-SSE `error` event are structured error
         * payloads on a SUCCESSFUL HTTP response, classified on their Anthropic
         * error `type` by {@see TransientFailure::anthropicErrorIsTransient()}.
         * That second mechanism is the one this field exists for most — an
         * overloaded Anthropic-on-Vertex backend answers 200, so no status and
         * no exception ever exist to carry the verdict.
         *
         * Nothing stops a future provider passing it on a non-error response;
         * `responseIsTransient()`'s `isError` gate is what makes that harmless,
         * not this field's default.
         */
        public ?bool $errorTransient = null,
        /**
         * Whether the server said this response was CUT OFF (§Q7 of the qwen
         * lane, evidence E-32: `finish_reason` `length`/`abort`).
         *
         * Batch: {@see SglangProvider::parseResponse()} sets it from the
         * choice's `finish_reason` — capture only, no field beside it
         * changes. Stream: the §Q7 flush frame (the last
         * {@see SglangProvider::completeStream()} tool-call chunk, emitted
         * when truncated completion left fragments in the reassembly buffer)
         * carries it; every other streamed chunk keeps the default false.
         *
         * FALSE IS NOT A PROOF OF CLEANLINESS. `stop`/`tool_calls` ends
         * never set it, and a provider that does not parse finish_reason at
         * all cannot report what it did not read — the field is sglang's
         * honest statement, not a cross-provider guarantee. Nothing in src/
         * consumes it yet: Runtime folds chunks today without consulting
         * it, and whether a truncated BATCH should re-enter the E-56 retry
         * classification is deliberately a caller-layer decision for the
         * orchestrator, not a silent behaviour change smuggled in on a
         * flag.
         */
        public bool $truncated = false,
        /**
         * The provider's own usage DOCUMENT, split buckets included — the
         * carrier half of the decision E17 was blocked on.
         *
         * WHAT WAS BLOCKING (verbatim from the entry): "A decision on whether
         * CompleteResponse::$tokensUsed is split into prompt/completion and
         * surfaced through Backend." The decision is TAKEN, in this field:
         * the split surfaces through `Backend` as a {@see \SugarCraft\Crush\Usage}
         * rather than as two new flat ints, because `Usage` is where
         * prompt_plan.md P4.S1 defined the bucket identity
         * (`promptTokens = cacheRead + cacheCreation + input`) and P4.S2
         * already routed five providers' parse seams through it — a flat pair
         * here would fork that arithmetic a second time. The old projection
         * fields are untouched and remain authoritative for anything that
         * only reads a total: this is ADDITIVE.
         *
         * NULL IS THE ORDINARY ANSWER TODAY. No construction site in `src/`
         * passes it yet: each split-reading provider still ends its Usage at
         * the `tokensUsed`/`costUsd` projection, and widening `Runtime`'s
         * fold (the two `Usage::reported($response->tokensUsed, …)` sites in
         * `runStreaming()`/`runBatch()`) plus the seven providers' construction
         * sites is the follow-through — deliberately outside the lane that
         * owns this file, so the carrier is published, documented, and
         * unit-tested here with the fold listed rather than silently half-done
         * (same posture {@see $truncated} shipped in). A null means
         * "the split was not carried", never "the split is zero" — see the
         * Usage docblock's "Zero is not the same as unknown".
         *
         * WHY IT EARNS ITS PLACE NOW rather than in the same commit as the
         * fold: Chat's tier calibration
         * ({@see \SugarCraft\Crush\Chat::noteTurnUsageObservation()}) reads
         * `promptTokens()` THROUGH this field and prefers it over the total —
         * the moment the fold lands, the estimator tightens with no further
         * Chat change, and until then the fallback keeps the calibration
         * honest about what it is actually measuring.
         */
        public ?\SugarCraft\Crush\Usage $usage = null,
    ) {}

    /**
     * The provider's counted PROMPT-side tokens, or null when this response
     * does not carry a usage document at all or the document's cache buckets
     * are incomplete.
     *
     * The "resolved accessor" the split needed: one call answers BOTH null
     * shapes — no carrier, and a carrier that could not total the prompt
     * ({@see \SugarCraft\Crush\Usage::promptTokens()}'s three-bucket rule) —
     * so a consumer never re-derives the identity or forgets half of it.
     */
    public function promptTokens(): ?int
    {
        return $this->usage?->promptTokens();
    }
}
