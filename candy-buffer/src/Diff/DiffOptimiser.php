<?php

declare(strict_types=1);

namespace SugarCraft\Buffer\Diff;

use SugarCraft\Buffer\Style;

/**
 * Peephole optimizer over a list of DiffOps.
 *
 * Optimizations applied:
 * 1. Adjacent SetStyleOps → keep only the last one (last-wins SGR).
 * 2. Adjacent SetCellOps with the same style → merge into one span.
 * 3. EraseRunOp always overwrites all prior state; no need to
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

        foreach ($ops as $op) {
            if ($op instanceof SetCellOp && $this->canMergeWithBuffer($op, $bufferStyle, $bufferLink)) {
                foreach ($op->cells as $cell) {
                    $buffer[] = $cell;
                }
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
}
