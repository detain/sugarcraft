<?php

declare(strict_types=1);

namespace SugarCraft\Diff\Tests\Reader;

use PHPUnit\Framework\TestCase;
use SugarCraft\Diff\Reader\ScanRowKind;
use SugarCraft\Diff\Reader\UnifiedScan;

/**
 * The declared-count half of the walk (the blind-walk fix): hunks end at
 * their `,b`/`,d` budgets, corruption closes a hunk instead of numbering
 * through it, plain GNU (non-git) file headers are recognised, and every
 * row carries its ScanRowKind.
 */
final class UnifiedScanCountsTest extends TestCase
{
    /** @param list<string> $rows @return list<array{0:?int,1:?int}> */
    private static function numbers(array $rows): array
    {
        return array_map(
            static fn ($row): array => [$row->old, $row->new],
            UnifiedScan::lines($rows),
        );
    }

    public function test_a_hunk_closes_at_its_declared_budget(): void
    {
        // One row per side declared, three supplied: the surplus rows are
        // OUTSIDE the hunk, not numbered onto phantom lines.
        self::assertSame(
            [[null, null], [1, null], [null, 1], [null, null], [null, null]],
            self::numbers([
                '@@ -1 +1 @@',
                '-a',
                '+b',
                ' c',
                ' d',
            ]),
        );
    }

    public function test_elided_counts_declare_exactly_one_line_per_side(): void
    {
        self::assertSame(
            [[null, null], [1, 1], [null, null]],
            self::numbers(['@@ -1 +1 @@', ' same', ' trailing']),
        );
    }

    public function test_rows_past_an_exhausted_side_fail_closed(): void
    {
        // Declares one old row, supplies two deletions: the second cannot be
        // an addition-shaped lie, so the hunk is over.
        self::assertSame(
            [[null, null], [1, null], [null, null], [null, null]],
            self::numbers(['@@ -1,1 +1,1 @@', '-a', '-b', '+c']),
        );
    }

    public function test_a_trailing_signature_does_not_number_as_context(): void
    {
        // `-- ` followed by a mail footer after a spent hunk: both outside.
        self::assertSame(
            [[null, null], [1, null], [null, 1], [null, null], [null, null], [null, null]],
            self::numbers([
                '@@ -1 +1 @@',
                '-a',
                '+b',
                '-- ',
                'git@example',
                '',
            ]),
        );
    }

    public function test_only_in_notes_end_the_hunk_that_precedes_them(): void
    {
        // Plain `diff -u` directory output: the Only-in row carries no
        // marker column, so the still-open budget must not make it context.
        self::assertSame(
            [[null, null], [1, null], [null, 1], [null, null]],
            self::numbers([
                '@@ -1 +1 @@',
                '-a',
                '+b',
                'Only in A: extra.txt',
            ]),
        );
    }

    public function test_an_unrecognised_marker_closes_the_hunk_instead_of_self_numbering(): void
    {
        // A tab-indented stray row: not ' ', '-', '+', or '\', so the open
        // budget refuses to number it — and everything after stays outside.
        self::assertSame(
            [[null, null], [1, 1], [null, null], [null, null]],
            self::numbers(["@@ -1,3 +1,3 @@", ' x', "\tnope", ' y']),
        );
    }

    public function test_plain_gnu_diff_headers_reset_the_walk(): void
    {
        $rows = [
            'diff -u A/one.txt B/one.txt',
            '--- A/one.txt',
            '+++ B/one.txt',
            '@@ -1 +1 @@',
            '-a',
            '+b',
            'diff -u A/two.txt B/two.txt',
            '--- A/two.txt',
            '+++ B/two.txt',
            '@@ -1 +1 @@',
            '-c',
            '+d',
        ];

        $headerFlags = UnifiedScan::fileHeaders($rows);
        // First file's three header rows, second file's three header rows.
        self::assertSame(
            [true, true, true, false, false, false, true, true, true, false, false, false],
            $headerFlags,
        );
        // The second file's hunk counts from ITS header, not the first's.
        self::assertSame(
            [[null, null], [1, null], [null, 1]],
            array_slice(self::numbers($rows), 9),
        );
    }

    public function test_every_row_kind_survives_one_walk(): void
    {
        $kinds = array_map(
            static fn ($row): ScanRowKind => $row->kind,
            UnifiedScan::lines([
                'diff --git a/f b/f',
                '--- a/f',
                '+++ b/f',
                '@@ -1 +1 @@',
                '-a',
                '+b',
                'Prose outside',
            ]),
        );

        self::assertSame(
            [
                ScanRowKind::FileHeader,
                ScanRowKind::FileHeader,
                ScanRowKind::FileHeader,
                ScanRowKind::HunkHeader,
                ScanRowKind::Delete,
                ScanRowKind::Insert,
                ScanRowKind::OutsideHunk,
            ],
            $kinds,
        );
    }

    public function test_context_and_annotation_kinds_are_distinguished(): void
    {
        $kinds = array_map(
            static fn ($row): ScanRowKind => $row->kind,
            UnifiedScan::lines([
                '@@ -1 +1 @@',
                ' shared',
                '\\ No newline at end of file',
            ]),
        );

        self::assertSame(
            [ScanRowKind::HunkHeader, ScanRowKind::Context, ScanRowKind::NoNewlineAnnotation],
            $kinds,
        );
    }

    public function test_a_deletion_of_a_diff_prefixed_line_keeps_its_number(): void
    {
        // The marker column protects hunk content: deleting a line that
        // itself begins "diff" must not reset the walk mid-hunk.
        self::assertSame(
            [[null, null], [1, null], [null, 1]],
            self::numbers(['@@ -1 +1 @@', '-diff -u fake header', '+x']),
        );
    }
}
