<?php

declare(strict_types=1);

namespace SugarCraft\Diff\Tests;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use SugarCraft\Diff\Diff;
use SugarCraft\Diff\DiffOptions;

/**
 * Engine-boundary pins from the docs-fix era hardening: the degraded flag
 * reports the cell-budget fallback, list sides refuse embedded newlines
 * fail-fast (a `file()`-without-FILE_IGNORE_NEW_LINES array would otherwise
 * emit marker-less multi-line rows), the ignoreCase fold keeps distinct
 * invalid bytes distinct, and the terminator-only blind spot exposes its
 * facts even though the default diff stays empty.
 */
final class DiffEngineSafetyTest extends TestCase
{
    public function test_the_degraded_flag_reports_the_budget_fallback(): void
    {
        $diff = Diff::compute(
            "a\nb\n",
            "x\ny\n",
            DiffOptions::new()->withMaxLcsCells(1),
        );

        self::assertTrue($diff->degraded, 'a 2x2 middle needs (2+1)*(2+1)=9 cells, over a budget of 1');
        // The fallback output stays a valid whole-middle replace block.
        self::assertStringContainsString("-a\n-b\n+x\n+y\n", $diff->hunkText());
    }

    public function test_an_equal_pair_never_degrades_however_small_the_budget(): void
    {
        // Prefix/suffix trimming empties both middles; the (0+1)*(0+1)=1 cell
        // table is never over any budget that admits work at all, and an
        // empty middle must not be labelled degraded.
        $diff = Diff::compute(
            "a\nb\n",
            "a\nb\n",
            DiffOptions::new()->withMaxLcsCells(0),
        );

        self::assertTrue($diff->isEmpty());
        self::assertFalse($diff->degraded);
    }

    public function test_the_default_path_is_not_degraded(): void
    {
        self::assertFalse(Diff::compute("a\nb\n", "a\nc\n")->degraded);
    }

    public function test_the_guard_counts_the_allocated_table_not_just_n_times_m(): void
    {
        // lcsOps allocates (n+1)*(m+1): a 2x2 middle needs 9 cells, so a
        // budget of exactly 8 degrades even though the old n*m formula
        // (4) would have promised the table.
        self::assertTrue(
            Diff::compute("a\nb\n", "x\ny\n", DiffOptions::new()->withMaxLcsCells(8))->degraded,
        );
        self::assertFalse(
            Diff::compute("a\nb\n", "x\ny\n", DiffOptions::new()->withMaxLcsCells(9))->degraded,
        );
    }

    public function test_a_list_element_with_an_embedded_newline_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/element 0 of the before side/');

        // file() WITHOUT FILE_IGNORE_NEW_LINES: each element would emit a
        // multi-line row the patch format cannot carry.
        Diff::compute(["a\nb\n"], ["c\n"]);
    }

    public function test_the_embedded_newline_refusal_names_its_side_and_index(): void
    {
        try {
            Diff::compute(["ok"], ["fine", "bad\nday"]);
            self::fail('expected the embedded-newline refusal');
        } catch (InvalidArgumentException $e) {
            self::assertStringContainsString('element 1 of the after side', $e->getMessage());
            self::assertStringContainsString('only strings', $e->getMessage());
        }
    }

    public function test_a_non_list_array_names_the_side(): void
    {
        try {
            Diff::compute(['a' => 'x'], ['y']);
            self::fail('expected the list-shape refusal');
        } catch (InvalidArgumentException $e) {
            self::assertStringContainsString('list of strings', $e->getMessage());
            self::assertStringContainsString('(before side)', $e->getMessage());
        }
    }

    public function test_a_non_string_element_names_its_side_and_index(): void
    {
        try {
            Diff::compute(['x'], ['ok', null]);
            self::fail('expected the element-type refusal');
        } catch (InvalidArgumentException $e) {
            self::assertStringContainsString('only strings', $e->getMessage());
            self::assertStringContainsString('element 1 of the after side', $e->getMessage());
        }
    }

    public function test_ignore_case_keeps_distinct_invalid_bytes_distinct(): void
    {
        // mb_strtolower maps every undecodable byte to '?' — folding
        // THROUGH it would judge these two files equal and hide a real
        // change. The byte-wise failover keeps them apart.
        $diff = Diff::compute("\xFFAlpha\n", "\xFEAlpha\n", DiffOptions::new()->withIgnoreCase());

        self::assertFalse($diff->isEmpty(), 'differing invalid bytes must not fold to the same "?"');
    }

    public function test_ignore_case_still_folds_valid_multibyte_text(): void
    {
        self::assertTrue(
            Diff::compute("Äpfel\n", "äpfel\n", DiffOptions::new()->withIgnoreCase())->isEmpty(),
        );
    }

    public function test_a_terminator_only_change_stays_empty_but_exposes_the_facts(): void
    {
        $diff = Diff::compute("a\n", "a");

        // The documented blind spot (default options): the diff is empty —
        // git would surface it with a `\ No newline` rewrite of the row.
        self::assertTrue($diff->isEmpty());
        self::assertTrue($diff->beforeHasFinalNewline);
        self::assertFalse($diff->afterHasFinalNewline);
        // And the inverse shape is just as silent:
        $inverse = Diff::compute("a", "a\n");
        self::assertTrue($inverse->isEmpty());
        self::assertFalse($inverse->beforeHasFinalNewline);
        self::assertTrue($inverse->afterHasFinalNewline);
    }
}
