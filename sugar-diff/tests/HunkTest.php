<?php

declare(strict_types=1);

namespace SugarCraft\Diff\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Diff\Hunk;
use SugarCraft\Diff\Line;
use SugarCraft\Diff\LineKind;

/**
 * Hunk-local pins. The row-total contract (sugar-diff-023): added/removed
 * counts are computed once from the carried rows at construction and stay
 * equal to a fresh per-row walk — the accessors are O(1) values, not queries.
 */
final class HunkTest extends TestCase
{
    public function test_stored_totals_equal_a_fresh_row_walk(): void
    {
        $hunk = new Hunk(1, 4, 1, 5, [
            new Line(LineKind::Equal, 'ctx', 1, 1),
            new Line(LineKind::Delete, 'old', 2, null),
            new Line(LineKind::Insert, 'new', null, 2),
            new Line(LineKind::Insert, 'new2', null, 3),
            new Line(LineKind::Equal, 'ctx2', 3, 4),
        ]);

        $walked = ['removed' => 0, 'added' => 0];
        foreach ($hunk->lines as $line) {
            if ($line->kind === LineKind::Delete) {
                $walked['removed']++;
            } elseif ($line->kind === LineKind::Insert) {
                $walked['added']++;
            }
        }

        self::assertSame($walked['removed'], $hunk->removedCount());
        self::assertSame($walked['added'], $hunk->addedCount());
        self::assertSame($walked['removed'], $hunk->removedTotal);
        self::assertSame($walked['added'], $hunk->addedTotal);
    }

    public function test_a_context_only_hunk_counts_no_changes(): void
    {
        $hunk = new Hunk(1, 2, 1, 2, [
            new Line(LineKind::Equal, 'a', 1, 1),
            new Line(LineKind::Equal, 'b', 2, 2),
        ]);

        self::assertSame(0, $hunk->removedCount());
        self::assertSame(0, $hunk->addedCount());
    }

    public function test_an_empty_hunk_still_reports_zero_totals(): void
    {
        $hunk = new Hunk(0, 0, 1, 0, []);

        self::assertSame(0, $hunk->removedTotal);
        self::assertSame(0, $hunk->addedTotal);
    }
}
