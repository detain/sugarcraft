<?php

declare(strict_types=1);

namespace SugarCraft\QuickPick\Support;

/**
 * Shared auto-width cache for one QuickPick clone family.
 *
 * QuickPick holds this by object handle, so a shallow `clone` shares exactly
 * one memo. That is sound because the computed width depends only on state
 * every width-affecting fluent setter invalidates (prompt, filter, items,
 * ranker, formatter, match style); a sibling that merely re-renders from a
 * cleared memo re-derives the right answer, and one that renders from a
 * still-filled memo gets the same answer its twin measured. The practical
 * payoff is resize handling: the SIGWINCH handler invalidates the memo once
 * and every clone — attached-at-the-time or made later — sees the resize.
 *
 * A cache miss changes no observable state. An empty memo is never rendered
 * stale; it is simply re-computed.
 */
final class WidthMemo
{
    /** @var int|null Memoised content width, or null when it must be re-derived. */
    public ?int $width = null;

    /**
     * Discard the memoised width so the next render re-measures.
     */
    public function invalidate(): void
    {
        $this->width = null;
    }
}
