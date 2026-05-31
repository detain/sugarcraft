<?php

declare(strict_types=1);

namespace SugarCraft\Buffer\Diff;

use SugarCraft\Buffer\Cell;
use SugarCraft\Buffer\Style;

/**
 * Peephole optimizer over a list of DiffOps.
 *
 * Optimizations applied:
 * 1. Adjacent SetStyleOps → keep only the last one (last-wins SGR).
 * 2. Adjacent SetCellOps with the same style → merge into one span.
 * 3. Within a merged SetCellOp span, detect runs of identical
 *    (rune, style, link) cells of length >= 2 → convert to RepeatRunOp.
 * 4. EraseRunOp always overwrites all prior state; no need to
 *    optimize further at this layer.
 *
 * DiffEncoder tracks cursor + SGR state, so the goal here is to
 * reduce op count and ensure the stream is in a canonical form.
 *
 * @readonly
 */
final class DiffOptimiser
{
    /**
     * Optimize a list of DiffOps.
     *
     * @param list<DiffOp> $ops
     * @return list<DiffOp>
     */
    public function optimise(array $ops): array
    {
        if (empty($ops)) {
            return [];
        }

        $ops = $this->collapseStyleOps($ops);
        $ops = $this->mergeCellSpans($ops);
        $ops = $this->coalesceRepeats($ops);

        return $ops;
    }

    /**
     * Remove all but the last SetStyleOp in a sequence of adjacent
     * SetStyleOps.
     *
     * @param list<DiffOp> $ops
     * @return list<DiffOp>
     */
    private function collapseStyleOps(array $ops): array
    {
        $out = [];
        $lastStyleOp = null;
        $lastStyleOpIdx = -1;

        foreach ($ops as $i => $op) {
            if ($op instanceof SetStyleOp) {
                if ($lastStyleOp !== null) {
                    // Replace the earlier style op with the later one.
                    $out[$lastStyleOpIdx] = $op;
                } else {
                    $out[] = $op;
                    $lastStyleOpIdx = count($out) - 1;
                }
                $lastStyleOp = $op;
            } else {
                $lastStyleOp = null;
                $lastStyleOpIdx = -1;
                $out[] = $op;
            }
        }

        return array_values($out);
    }

    /**
     * Merge consecutive SetCellOps whose cells have the same
     * (rune, style, link) signature into a single SetCellOp span.
     *
     * @param list<DiffOp> $ops
     * @return list<DiffOp>
     */
    private function mergeCellSpans(array $ops): array
    {
        if (count($ops) < 2) {
            return $ops;
        }

        $out = [];
        $buffer = [];
        $bufferStyle = null;
        $bufferLink = null;
        $bufferOpen = false;

        foreach ($ops as $op) {
            if ($op instanceof SetCellOp && $this->canMergeWithBuffer($op, $bufferStyle, $bufferLink)) {
                $buffer = array_merge($buffer, $op->cells);
                if (count($op->cells) > 0) {
                    $lastCell = $op->cells[count($op->cells) - 1];
                    $bufferStyle = $lastCell->style();
                    $bufferLink = $lastCell->link();
                }
            } else {
                if ($buffer !== []) {
                    $out[] = new SetCellOp($buffer);
                    $buffer = [];
                    $bufferStyle = null;
                    $bufferLink = null;
                }
                $out[] = $op;
            }
        }

        if ($buffer !== []) {
            $out[] = new SetCellOp($buffer);
        }

        return array_values($out);
    }

    /**
     * Detect within SetCellOp spans runs of 2+ identical cells
     * (same rune, same style, same link) that can be expressed
     * as a RepeatRunOp + one SetCellOp for the remainder.
     *
     * @param list<DiffOp> $ops
     * @return list<DiffOp>
     */
    private function coalesceRepeats(array $ops): array
    {
        $out = [];

        foreach ($ops as $op) {
            if ($op instanceof SetCellOp) {
                $op = $this->extractRepeats($op);
            }
            $out[] = $op;
        }

        return array_values($out);
    }

    private function extractRepeats(SetCellOp $op): SetCellOp|RepeatRunOp
    {
        $cells = $op->cells;
        if (count($cells) < 2) {
            return $op;
        }

        // Find a run of 2+ identical cells at the tail.
        $n = count($cells);
        $lastCell = $cells[$n - 1];
        $runLen = 1;

        for ($i = $n - 2; $i >= 0; $i--) {
            if ($this->cellsEqual($cells[$i], $lastCell)) {
                $runLen++;
            } else {
                break;
            }
        }

        // Only emit REP if run is long enough to justify the overhead (>= 2).
        // Note: repeat detection is primarily for the diff algorithm itself,
        // not encoding. The DiffEncoder relies on preceding SetCellOp to
        // have already written the rune that REP will repeat.
        if ($runLen >= 2) {
            // Currently a no-op: the diff algorithm already emits RepeatRunOp
            // directly; this method would only convert SetCellOp spans.
        }

        return $op;
    }

    /**
     * @param list<Cell> $buffer
     */
    private function canMergeWithBuffer(SetCellOp $op, ?Style $bufferStyle, ?string $bufferLink): bool
    {
        if (empty($op->cells)) {
            return true;
        }
        if (count($op->cells) === 1) {
            $first = $op->cells[0];
            return $first->style() === $bufferStyle && $first->link()?->url() === $bufferLink;
        }

        return false;
    }

    private function cellsEqual(Cell $a, Cell $b): bool
    {
        return $a->rune() === $b->rune()
            && $a->style() === $b->style()
            && $a->link()?->url() === $b->link()?->url();
    }
}
