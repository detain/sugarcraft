<?php

declare(strict_types=1);

namespace SugarCraft\Crush;

/**
 * What one provider call — or a whole turn's worth of them summed — actually
 * cost, in PROVIDER-COUNTED tokens and dollars (crush_code.md Phase 5 item 7).
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
 * ## Why there is no input/output split here
 *
 * {@see Util\TokenTracker::addUsage()} wants input and output separately, and
 * this class cannot honestly supply them: `CompleteResponse` carries a single
 * `$tokensUsed` total and nothing else.
 *
 * THREE of the seven providers know the split and throw it away — read the
 * count with its domain, because a fourth literal of it is what drifted last
 * time. {@see Providers\BedrockProvider} reads `usage.inputTokens` /
 * `usage.outputTokens`, {@see Providers\VertexProvider} reads
 * `usage.input_tokens` / `usage.output_tokens`, and
 * {@see Providers\OpenAIProvider} reads `usage.prompt_tokens` /
 * `usage.completion_tokens` and prices each side at its own rate in
 * `calculateCost()` — then all three report `tokensUsed` as one number. The
 * remaining four ({@see Providers\ClaudeCodeProvider},
 * {@see Providers\CustomProvider}, {@see Providers\SglangProvider},
 * {@see Providers\EchoProvider}) never had a split to lose: they read
 * `usage.total_tokens` or report 0.
 *
 * That collapse happens BEFORE the response leaves the provider on every UNARY
 * path — and on Bedrock's and OpenAI's streaming paths too. Vertex's stream is
 * the one exception and it matters: it emits input tokens on `message_start` and
 * output tokens on the terminal `message_delta`, as two separate
 * `CompleteResponse`s with `tokensUsed: $inputTokens` and
 * `tokensUsed: $outputTokens` ({@see Providers\VertexProvider::parseAnthropicChunk()}).
 * {@see Runtime}'s streaming path documents that and SUMS them, which is why the
 * figure arriving here is still a total. So the split survives one layer lower
 * than this seam in exactly one case, and is recoverable there without touching
 * any provider — see `Runtime`.
 *
 * Passing `addUsage($total, 0, $cost)` would fabricate a split —
 * `outputTokens()` reporting 0 for a real completion — so the tracker gained
 * {@see Util\TokenTracker::addTotalUsage()} for exactly this shape instead.
 * Recovering the real split means widening `CompleteResponse` and the three
 * providers that already know it; that is a separate change and is not pretended
 * to here.
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
    ) {}

    public static function new(int $totalTokens = 0, float $costUsd = 0.0): self
    {
        return new self(max(0, $totalTokens), max(0.0, $costUsd));
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
     */
    public static function reported(int $totalTokens, float $costUsd): ?self
    {
        if ($totalTokens <= 0 && $costUsd <= 0.0) {
            return null;
        }

        return self::new($totalTokens, $costUsd);
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
        return new self($this->totalTokens + $other->totalTokens, $this->costUsd + $other->costUsd);
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
     * @return array{totalTokens:int,costUsd:float}
     */
    public function toArray(): array
    {
        return ['totalTokens' => $this->totalTokens, 'costUsd' => $this->costUsd];
    }

    /**
     * Rebuild from {@see toArray()}, tolerating anything that is not the shape
     * it wrote: the payload has crossed a socket, and a corrupt frame must
     * cost the turn its accounting, not the turn itself.
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

        return self::reported($tokens, (float) $cost);
    }
}
