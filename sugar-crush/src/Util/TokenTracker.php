<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Util;

/**
 * Tracks token usage and cost across API calls.
 * Mirrors upstream provider usage tracking patterns.
 *
 * A running MUTABLE accumulator by design, not an immutable value object: it
 * is fed once per settled turn by {@see \SugarCraft\Crush\Chat::update()} and
 * read by the status bar's spend readout, and it is carried by object identity
 * through `Chat::mutate()` for the same reason `$liveToolEvents` is - a fresh
 * instance per keystroke would reset the session's total.
 *
 * ## Three buckets, because providers report two different shapes
 *
 * {@see addUsage()} takes input and output SEPARATELY, which FIVE of the seven
 * providers know the split for — and none of them SUPPLIES it across the seam:
 * {@see \SugarCraft\Crush\Providers\CompleteResponse} carries one
 * `$tokensUsed` total, and every split-reading provider collapses its parsed
 * buckets into it before the response leaves. "Could ever supply" was this
 * file's pre-P4.S2 framing and is obsolete: prompt_plan.md P4.S2 routed the
 * parses through {@see \SugarCraft\Crush\Usage}'s buckets, so the collapse
 * moved from the provider's parse to its carrier boundary — Bedrock, Vertex
 * and OpenAI always had a split to collapse, Sglang and Custom gained theirs
 * at that seam — and OpenAI's remains the easy one to miss because it reads
 * `prompt_tokens`/`completion_tokens` and prices the two sides separately
 * before reporting only `total_tokens`. {@see \SugarCraft\Crush\Usage} carries
 * the full enumeration with the array keys each one reads, and
 * {@see \SugarCraft\Crush\Tests\UsageTest} derives the count from the
 * provider sources rather than trusting either docblock. Calling
 * `addUsage($total, 0, $cost)` to work around that would report a real
 * completion as having produced zero output tokens, so {@see addTotalUsage()}
 * exists for the un-split shape and keeps its tokens in their own bucket. The
 * rule the accessors follow:
 *
 *  - {@see inputTokens()} / {@see outputTokens()} cover ONLY the calls whose
 *    provider reported a split.
 *  - {@see unsplitTokens()} covers the calls that reported a total only.
 *  - {@see totalTokens()} covers all of them, which is why it is the figure a
 *    session total should be read from.
 *  - {@see totalCost()} covers all of them; cost never had a split.
 *
 * All of these are PROVIDER-COUNTED tokens. They are a different unit from the
 * chars/4 estimate the context readout shows with a leading `~` - see
 * {@see \SugarCraft\Crush\Usage} - and must not be summed with it.
 */
final class TokenTracker
{
    private int $inputTokens = 0;
    private int $outputTokens = 0;
    private int $unsplitTokens = 0;
    private float $totalCost = 0.0;

    /**
     * Add usage from a single API call.
     */
    public function addUsage(int $input, int $output, float $cost): void
    {
        $this->inputTokens += $input;
        $this->outputTokens += $output;
        $this->totalCost += $cost;
    }

    /**
     * Add usage from a call whose provider reported a TOTAL only, with no
     * input/output split - which is every provider on this codebase's live
     * paths (see the class docblock).
     *
     * Kept out of {@see $inputTokens}/{@see $outputTokens} on purpose: those
     * two are the halves a provider actually named, and folding an unsplit
     * total into either of them would make {@see inputTokens()} answer a
     * question nothing asked.
     */
    public function addTotalUsage(int $total, float $cost): void
    {
        $this->unsplitTokens += $total;
        $this->totalCost += $cost;
    }

    /**
     * Every provider-counted token this tracker has seen: the reported halves
     * plus the calls that only ever reported a total.
     */
    public function totalTokens(): int
    {
        return $this->inputTokens + $this->outputTokens + $this->unsplitTokens;
    }

    /**
     * Input token count, over the calls whose provider reported a split. Zero
     * is the honest answer for a session of {@see addTotalUsage()} calls - it
     * means "no provider named an input half", not "no input was sent".
     */
    public function inputTokens(): int
    {
        return $this->inputTokens;
    }

    /**
     * Output token count, over the calls whose provider reported a split. Read
     * {@see inputTokens()}'s note on what zero means here.
     */
    public function outputTokens(): int
    {
        return $this->outputTokens;
    }

    /**
     * Tokens from calls that reported a total without a split - the bucket
     * {@see inputTokens()} and {@see outputTokens()} deliberately exclude.
     */
    public function unsplitTokens(): int
    {
        return $this->unsplitTokens;
    }

    /**
     * Total cost in dollars.
     */
    public function totalCost(): float
    {
        return $this->totalCost;
    }

    /**
     * Reset all counters.
     */
    public function reset(): void
    {
        $this->inputTokens = 0;
        $this->outputTokens = 0;
        $this->unsplitTokens = 0;
        $this->totalCost = 0.0;
    }

    /**
     * Human-readable summary, as `/budget` prints it.
     *
     * The unsplit bucket is named separately rather than folded into either
     * half: on every provider path in this repo it is the ONLY non-zero token
     * figure, and the two-figure form alone would report `0 in / 0 out` for a
     * session that really did spend tokens. The segment is omitted entirely
     * when the bucket is empty, so a split-reporting caller's summary keeps the
     * exact wording (and byte length) it always had.
     */
    public function summary(): string
    {
        $split = sprintf(
            'Tokens: %d in / %d out',
            $this->inputTokens,
            $this->outputTokens
        );
        if ($this->unsplitTokens > 0) {
            $split .= sprintf(' + %d unsplit', $this->unsplitTokens);
        }

        return $split . sprintf(' | Cost: $%.4f', $this->totalCost);
    }
}
