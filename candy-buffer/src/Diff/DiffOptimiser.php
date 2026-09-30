<?php

declare(strict_types=1);

namespace SugarCraft\Buffer\Diff;

use SugarCraft\Buffer\Hyperlink;
use SugarCraft\Buffer\Style;

/**
 * Peephole optimizer over a list of DiffOps.
 *
 * Optimizations applied:
 * 1. Adjacent SetStyleOps → keep only the last one (last-wins SGR).
 * 2. Adjacent SetCellOps whose tail/head state matches by VALUE
 *    (same style and same hyperlink) → merge into one span.
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

        foreach ($ops as $op) {
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
            if ($op instanceof SetCellOp
                && ($buffer === [] || $this->canMergeWithBuffer($op, $bufferStyle, $bufferLink))
            ) {
                // First (or mergeable) span op: seed/extend the buffer. The
                // old shape pushed non-null-style first ops straight through
                // without seeding, so styled spans never began merging at all.
                foreach ($op->cells as $cell) {
                    $buffer[] = $cell;
                }
                if ($op->cells !== []) {
                    $lastCell = $op->cells[count($op->cells) - 1];
                    $bufferStyle = $lastCell->style();
                    $bufferLink = $lastCell->link();
                }
                continue;
            }

            if ($buffer !== []) {
                $out[] = new SetCellOp($buffer);
                $buffer = [];
                $bufferStyle = null;
                $bufferLink = null;
            }

            if ($op instanceof SetCellOp) {
                // Tail state refused the merge: this op starts the next span.
                $buffer = $op->cells;
                if ($op->cells !== []) {
                    $lastCell = $op->cells[count($op->cells) - 1];
                    $bufferStyle = $lastCell->style();
                    $bufferLink = $lastCell->link();
                }
                continue;
            }

            $out[] = $op;
        }

        if ($buffer !== []) {
            $out[] = new SetCellOp($buffer);
        }

        return array_values($out);
    }

    /**
     * A single-cell op merges iff its cell's style and hyperlink match the
     * buffered tail state by VALUE. (bufferLink is the tail cell's Hyperlink
     * object — comparing its url against a string made linked spans never
     * merge, and matching by value keeps output instance-independent.)
     */
    private function canMergeWithBuffer(SetCellOp $op, ?Style $bufferStyle, ?Hyperlink $bufferLink): bool
    {
        if (empty($op->cells)) {
            return true;
        }
        if (count($op->cells) === 1) {
            $first = $op->cells[0];
            return Style::valuesEqual($first->style(), $bufferStyle)
                && Hyperlink::valuesEqual($first->link(), $bufferLink);
        }

        return false;
    }
}
