<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tui\Components;

use SugarCraft\Code\Context\ContextCategories;
use SugarCraft\Code\Context\ContextCategory;

/**
 * Everything the context sidebar box needs to draw itself (P-CTX.c) — a
 * snapshot of the attribution model ({@see ContextCategories}) beside the
 * live figures the status bar already shows.
 *
 * The split is deliberate: `categories` is the expensive measurement, taken
 * when a turn settles, while `historyTokens` ticks with every streamed
 * exchange. {@see self::$stale} is the flag that says the two disagree
 * because a turn is mid-flight; {@see messageTokens()} and
 * {@see usedTokens()} then follow the live figure for the Messages row and
 * the total, while every other row keeps its snapshot (plan_comparison.md
 * §6: "While a turn runs, the Messages row and the total follow the live
 * history tokens, the other rows keep the snapshot").
 *
 * Immutable and fluent per the house convention: every `with*()` returns a
 * new instance, and unmeasured legs stay NULL throughout — the pane renders
 * them as the "not measured" wording, never as a fabricated zero.
 */
final readonly class ContextPaneModel
{
    public function __construct(
        /** The attribution snapshot; null until the first measurement lands. */
        public ?ContextCategories $categories = null,
        /** Live history tokens — the same figure the status bar shows. */
        public int $historyTokens = 0,
        /** Context window the percentages are taken against. */
        public int $window = 0,
        /** Collapsed draws the bar and the total; expanded lists the rows. */
        public bool $expanded = false,
        /** The category whose sub-rows are shown in place, if any. */
        public ?ContextCategory $drill = null,
        /** True while the snapshot predates the live turn in flight. */
        public bool $stale = false,
        /** Last-prompt cache hit rate, in whole percents; null = unknown. */
        public ?int $cachePercent = null,
        /** Tokens freed by output pruning; null = no pruning measured. */
        public ?int $prunedTokens = null,
    ) {
    }

    private function mutate(
        ?ContextCategories $categories,
        int $historyTokens,
        int $window,
        bool $expanded,
        ?ContextCategory $drill,
        bool $stale,
        ?int $cachePercent,
        ?int $prunedTokens,
    ): self {
        return new self($categories, $historyTokens, $window, $expanded, $drill, $stale, $cachePercent, $prunedTokens);
    }

    public function withCategories(?ContextCategories $categories): self
    {
        // A fresh snapshot retires the staleness flag by definition: it was
        // taken against the current history.
        return $this->mutate($categories, $this->historyTokens, $this->window, $this->expanded, $this->drill, false, $this->cachePercent, $this->prunedTokens);
    }

    public function withHistoryTokens(int $historyTokens): self
    {
        return $this->mutate($this->categories, $historyTokens, $this->window, $this->expanded, $this->drill, $this->stale, $this->cachePercent, $this->prunedTokens);
    }

    public function withWindow(int $window): self
    {
        return $this->mutate($this->categories, $this->historyTokens, $window, $this->expanded, $this->drill, $this->stale, $this->cachePercent, $this->prunedTokens);
    }

    public function withExpanded(bool $expanded): self
    {
        // Collapsing hides the rows, so a drill open on a row would be
        // invisible state — it clears with the collapse.
        return $this->mutate($this->categories, $this->historyTokens, $this->window, $expanded, $expanded ? $this->drill : null, $this->stale, $this->cachePercent, $this->prunedTokens);
    }

    public function withDrill(?ContextCategory $drill): self
    {
        return $this->mutate($this->categories, $this->historyTokens, $this->window, $this->expanded, $drill, $this->stale, $this->cachePercent, $this->prunedTokens);
    }

    public function withStale(bool $stale): self
    {
        return $this->mutate($this->categories, $this->historyTokens, $this->window, $this->expanded, $this->drill, $stale, $this->cachePercent, $this->prunedTokens);
    }

    public function withCachePercent(?int $cachePercent): self
    {
        return $this->mutate($this->categories, $this->historyTokens, $this->window, $this->expanded, $this->drill, $this->stale, $cachePercent, $this->prunedTokens);
    }

    public function withPrunedTokens(?int $prunedTokens): self
    {
        return $this->mutate($this->categories, $this->historyTokens, $this->window, $this->expanded, $this->drill, $this->stale, $this->cachePercent, $prunedTokens);
    }

    /**
     * The tokens to show on the Messages row: the snapshot figure normally,
     * or the live history less what the snapshot already knew was pruned —
     * the same formula {@see ContextCategories::from()} builds the row from,
     * applied to the live count so the row "follows the live history tokens"
     * mid-turn. NULL while nothing has been measured at all.
     */
    public function messageTokens(): ?int
    {
        if ($this->categories === null) {
            return null;
        }
        $snapshot = $this->categories->tokensOf(ContextCategory::Messages);
        if (!$this->stale || $snapshot === null) {
            return $snapshot;
        }

        return max(0, $this->historyTokens - ($this->prunedTokens ?? 0));
    }

    /**
     * The figure the total line shows — the snapshot's content rows with the
     * Messages leg swapped for {@see messageTokens()}, so the total tracks
     * the live turn while the other categories hold their measurements.
     * NULL only while there is no snapshot at all.
     */
    public function usedTokens(): ?int
    {
        if ($this->categories === null) {
            return null;
        }
        $snapshotMessages = $this->categories->tokensOf(ContextCategory::Messages) ?? 0;
        $liveMessages = $this->messageTokens() ?? 0;

        return max(0, $this->categories->contentTokens() - $snapshotMessages + $liveMessages);
    }

    /** Whole-percent window usage, or NULL when either figure is unmeasured. */
    public function percentOfWindow(): ?int
    {
        $used = $this->usedTokens();
        if ($used === null || $this->window <= 0) {
            return null;
        }

        return (int) round($used * 100 / $this->window);
    }
}
