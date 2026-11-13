<?php

declare(strict_types=1);

namespace SugarCraft\Style\Bar;

use SugarCraft\Core\Util\Width;

/**
 * A one-row bar of clickable pieces that fits a width budget and answers
 * its own click zones. The shared engine behind a menu-title bar and a
 * window-tab strip.
 *
 * THE LAW (what both twins obeyed without writing it down):
 *
 *  1. MEASURE PLAIN, WRAP MARKED. Every width is taken from the pieces'
 *     PLAIN text. A marked render wraps each zoned piece with the caller's
 *     marker AFTER layout; the marker's sentinels are zero-visible-width
 *     in the painted row but Private-Use "content" to {@see Width}, so a
 *     budget measured on the marked row would shift every later cell. The
 *     painted render and the marked render therefore share one plan and
 *     put every visible cell in the same column.
 *  2. ZONES FROM THE SAME ARITHMETIC. {@see zones()} walks the identical
 *     plan and sums plain widths, so a zone can never cover a cell the
 *     renderer did not paint. Spans are [row, from, to exclusive, id]; a
 *     piece with no id, or one the zone gate refuses (a hostile id), is
 *     painted unmarked and gets no span - "not clickable", never a throw.
 *  3. GREEDY FIT, COLLAPSE THE TAIL. The lead pieces are always kept.
 *     Entries are placed in order while each fits beside the overflow
 *     marker the remainder would need. If every entry fits as is, nothing
 *     collapses and no marker shows. Otherwise the first entry that does
 *     not fit ends placement, the marker is re-measured at its TRUE
 *     dropped count, and trailing placed entries are dropped one by one
 *     until marker and entries fit; a marker that never fits is not shown.
 *     The row is never wider than the budget in painted cells, and a
 *     budget below the lead's width yields the empty row.
 *  4. SEPARATORS. One separator string sits between entries and before the
 *     marker (or after each entry, see {@see withSeparatorAfter()} - the
 *     arithmetic is identical, only placement differs). It is plain and
 *     unzoned.
 *
 * Density (full/icon/terse shapes) stays with the caller: it builds
 * {@see ZoneEntry}s at the density it chose, using {@see width()} to test
 * "does the whole row fit at this density".
 *
 * Immutable; every with*() returns a new instance.
 */
final class ZoneRow
{
    /** @var list<ZonePiece> */
    private array $lead = [];
    /** @var list<ZoneEntry> */
    private array $entries = [];
    private string $separator = ' ';
    private bool $separatorAfter = false;
    /** @var (\Closure(int, list<ZoneEntry>): ?ZoneEntry)|null */
    private ?\Closure $overflow = null;
    /** @var (\Closure(string, string): string)|null */
    private ?\Closure $marker = null;
    /** @var (\Closure(string): bool)|null */
    private ?\Closure $zoneGate = null;

    private function __construct() {}

    public static function new(): self
    {
        return new self();
    }

    /** Pieces that always lead the row and are never dropped (a `[+]` affordance, a leading gap). */
    public function withLead(ZonePiece ...$pieces): self
    {
        return $this->mutate('lead', array_values($pieces));
    }

    /** @param list<ZoneEntry> $entries */
    public function withEntries(array $entries): self
    {
        return $this->mutate('entries', array_values($entries));
    }

    /** Plain separator between entries (default one space). */
    public function withSeparator(string $separator): self
    {
        return $this->mutate('separator', $separator);
    }

    /** Place the separator after each entry instead of before it. */
    public function withSeparatorAfter(bool $after = true): self
    {
        return $this->mutate('separatorAfter', $after);
    }

    /**
     * Builds the overflow entry for a dropped count; return null for "no
     * marker". Receives the entries it would stand in for.
     *
     * @param ?callable(int, list<ZoneEntry>): ?ZoneEntry $overflow
     */
    public function withOverflow(?callable $overflow): self
    {
        return $this->mutate('overflow', $overflow === null ? null : \Closure::fromCallable($overflow));
    }

    /**
     * Wraps a zoned piece's painted text for a marked render, e.g.
     * `Mark::zone(...)`.
     *
     * @param ?callable(string, string): string $marker (id, painted) -> marked
     */
    public function withMarker(?callable $marker): self
    {
        return $this->mutate('marker', $marker === null ? null : \Closure::fromCallable($marker));
    }

    /**
     * Predicate deciding whether an id may carry a zone at all.
     *
     * @param ?callable(string): bool $gate
     */
    public function withZoneGate(?callable $gate): self
    {
        return $this->mutate('zoneGate', $gate === null ? null : \Closure::fromCallable($gate));
    }

    /** Plain width of the lead plus every entry and separator, no collapse. */
    public function width(): int
    {
        $w = $this->leadWidth();
        $sep = Width::string($this->separator);
        foreach ($this->entries as $e) {
            $w += $e->width() + $sep;
        }
        return $w;
    }

    /**
     * Decide the row for `$cols` (null = unbounded). Null when `$cols` is
     * below the lead's width: nothing can be painted.
     */
    public function plan(?int $cols): ?ZonePlan
    {
        $total = count($this->entries);
        if ($cols === null) {
            return new ZonePlan($this->entries, [], null);
        }
        $used = $this->leadWidth();
        if ($cols < $used) {
            return null;
        }
        $sep = Width::string($this->separator);
        if ($this->width() <= $cols) {
            return new ZonePlan($this->entries, [], null);
        }

        $placed = [];
        $costs = [];
        $dropped = 0;
        for ($i = 0; $i < $total; $i++) {
            $cost = $this->entries[$i]->width() + $sep;
            $left = $total - $i - 1;
            $tail = $left > 0 ? $this->overflowCost($left, array_slice($this->entries, $i + 1)) : 0;
            if ($used + $cost + $tail > $cols) {
                $dropped = $total - $i;
                break;
            }
            $placed[] = $this->entries[$i];
            $costs[] = $cost;
            $used += $cost;
        }

        $show = $dropped > 0;
        while (
            $show && $placed !== []
            && $used + $this->overflowCost($total - count($placed), array_slice($this->entries, count($placed))) > $cols
        ) {
            array_pop($placed);
            $used -= array_pop($costs);
        }
        $overflow = null;
        if ($show) {
            $rest = array_slice($this->entries, count($placed));
            $overflow = $this->overflowEntry(count($rest), $rest);
            if ($overflow === null || $used + $overflow->width() + $sep > $cols) {
                $overflow = null;
            }
        }

        return new ZonePlan($placed, array_slice($this->entries, count($placed)), $overflow);
    }

    /** The painted (`$marked` false) or zone-wrapped (`$marked` true) row; '' when nothing fits. */
    public function render(?int $cols, bool $marked): string
    {
        $plan = $this->plan($cols);
        if ($plan === null) {
            return '';
        }
        $out = '';
        foreach ($this->segments($plan) as [$piece, $sepOnly]) {
            if ($sepOnly !== null) {
                $out .= $sepOnly;
                continue;
            }
            $painted = $piece->painted();
            $out .= $marked && $this->zoneable($piece) && $this->marker !== null
                ? ($this->marker)((string) $piece->id, $painted)
                : $painted;
        }
        return $out;
    }

    /**
     * Click spans of the row at `$cols`, same arithmetic as {@see render()}.
     *
     * @return list<array{0: int, 1: int, 2: int, 3: string}> [row, from, to exclusive, id]
     */
    public function zones(?int $cols): array
    {
        $plan = $this->plan($cols);
        if ($plan === null) {
            return [];
        }
        $zones = [];
        $col = 0;
        foreach ($this->segments($plan) as [$piece, $sepOnly]) {
            $w = $sepOnly !== null ? Width::string($sepOnly) : $piece->width();
            if ($sepOnly === null && $this->zoneable($piece)) {
                $zones[] = [0, $col, $col + $w, (string) $piece->id];
            }
            $col += $w;
        }
        return $zones;
    }

    /**
     * Flat walk of lead, entries, separators and overflow in paint order.
     *
     * @return list<array{0: ?ZonePiece, 1: ?string}> a piece, or a bare separator string
     */
    private function segments(ZonePlan $plan): array
    {
        $out = [];
        foreach ($this->lead as $p) {
            $out[] = [$p, null];
        }
        $items = $plan->placed;
        if ($plan->overflow !== null) {
            $items[] = $plan->overflow;
        }
        foreach ($items as $entry) {
            if (!$this->separatorAfter) {
                $out[] = [null, $this->separator];
            }
            foreach ($entry->pieces as $p) {
                $out[] = [$p, null];
            }
            if ($this->separatorAfter) {
                $out[] = [null, $this->separator];
            }
        }
        return $out;
    }

    private function zoneable(ZonePiece $p): bool
    {
        return $p->id !== null && ($this->zoneGate === null || ($this->zoneGate)($p->id));
    }

    private function leadWidth(): int
    {
        $w = 0;
        foreach ($this->lead as $p) {
            $w += $p->width();
        }
        return $w;
    }

    /** @param list<ZoneEntry> $standingFor */
    private function overflowEntry(int $count, array $standingFor): ?ZoneEntry
    {
        return $this->overflow === null ? null : ($this->overflow)($count, $standingFor);
    }

    /** @param list<ZoneEntry> $standingFor */
    private function overflowCost(int $count, array $standingFor): int
    {
        $e = $this->overflowEntry($count, $standingFor);
        return $e === null ? 0 : $e->width() + Width::string($this->separator);
    }

    private function mutate(string $prop, mixed $value): self
    {
        $c = clone $this;
        $c->$prop = $value;
        return $c;
    }
}
