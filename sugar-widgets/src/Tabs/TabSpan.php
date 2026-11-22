<?php

declare(strict_types=1);

namespace SugarCraft\Widgets\Tabs;

/**
 * The cells one tab occupies on the rendered bar — the hit-testing half of
 * {@see Tabs::spans()}.
 *
 * Columns are 0-based and relative to the first cell of the bar (the host
 * adds the bar's own screen column). `end` is exclusive, so a tab covers
 * `start <= col < end`. A tab clipped by the bar width reports only its
 * visible cells. `closeStart` is the first cell of the close affordance
 * on a closable bar, or null when the bar is not closable or the
 * affordance was clipped away.
 *
 * Small value object with no `with*()` setters (dated exemption
 * 2026-11-22): it is produced by {@see Tabs::spans()} and never edited.
 */
final class TabSpan
{
    public function __construct(
        public readonly int $index,
        public readonly int $start,
        public readonly int $end,
        public readonly ?int $closeStart = null,
    ) {}

    /** Number of visible cells the tab covers. */
    public function width(): int
    {
        return $this->end - $this->start;
    }

    /** Whether bar column `$col` falls on this tab (label, badge or close affordance). */
    public function contains(int $col): bool
    {
        return $col >= $this->start && $col < $this->end;
    }

    /** Whether bar column `$col` falls on this tab's close affordance. */
    public function onClose(int $col): bool
    {
        return $this->closeStart !== null && $col >= $this->closeStart && $col < $this->end;
    }
}
