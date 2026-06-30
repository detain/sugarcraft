<?php

declare(strict_types=1);

namespace SugarCraft\Testing\Snapshot;

use PHPUnit\Framework\Assert;
use SugarCraft\Buffer\Buffer;

/**
 * Static assertion helpers for TEA snapshot testing.
 *
 * Provides assertGoldenAnsi, assertCellGrid, and assertAnsiEquals
 * for deterministic regression testing of terminal output.
 *
 * @see Mirrors charmbracelet/bubbletea — snapshot testing pattern from issue #1654
 */
final class Assertions
{
    /**
     * Assert that $actual matches the golden file at $goldenPath.
     *
     * If UPDATE_GOLDENS=1 is set in the environment and the golden
     * file does not exist, it is automatically created with $actual
     * as the baseline.
     *
     * If the golden file exists and differs, a diff is shown with
     * ESC sequences printed as readable \x1b[...]m notation.
     *
     * @param string $goldenPath Path to the golden file
     * @param string $actual     Current output bytes to assert
     */
    public static function assertGoldenAnsi(string $goldenPath, string $actual): void
    {
        $existing = GoldenFile::load($goldenPath);

        if ($existing === null) {
            if (getenv('UPDATE_GOLDENS') === '1') {
                GoldenFile::save($goldenPath, $actual);
                // Self-check: we just wrote it, so it matches.
                return;
            }

            Assert::fail("No golden file found at '{$goldenPath}'. Set UPDATE_GOLDENS=1 to create it.");
            return;
        }

        if ($existing === $actual) {
            return;
        }

        // Show readable diff.
        $diff = self::diffAnsi($existing, $actual);
        Assert::fail("Golden file mismatch at '{$goldenPath}'.\n\n{$diff}");
    }

    /**
     * Assert that $expected array matches the Buffer's cell grid.
     *
     * Walks both grids cell-by-cell and produces a per-cell diff
     * highlighting mismatches with row/col coordinates.
     *
     * The $expected format is a 2-D array of cell data:
     *   $expected[row][col] = ['rune' => 'a', 'style' => $style, ...]
     *
     * @param array<int, array<int, array<string, mixed>>> $expected 2-D cell array
     * @param Buffer                                          $actual   Buffer to compare
     */
    public static function assertCellGrid(array $expected, Buffer $actual): void
    {
        $failures = [];

        $expectedHeight = count($expected);
        $expectedWidth = $expectedHeight > 0 ? count($expected[0]) : 0;

        // Check dimensions first.
        if ($expectedWidth !== $actual->width() || $expectedHeight !== $actual->height()) {
            Assert::fail(sprintf(
                "Dimension mismatch: expected %dx%d, got %dx%d",
                $expectedWidth,
                $expectedHeight,
                $actual->width(),
                $actual->height(),
            ));
            return;
        }

        for ($row = 0; $row < $expectedHeight; $row++) {
            for ($col = 0; $col < $expectedWidth; $col++) {
                $expectedCell = $expected[$row][$col] ?? null;
                $actualCell = $actual->cellAt($col, $row);

                if ($expectedCell === null) {
                    continue;
                }

                $cellFailures = [];

                if (isset($expectedCell['rune']) && $expectedCell['rune'] !== $actualCell->rune()) {
                    $cellFailures[] = "rune: expected '{$expectedCell['rune']}', got '{$actualCell->rune()}'";
                }

                if (isset($expectedCell['width']) && $expectedCell['width'] !== $actualCell->width()) {
                    $cellFailures[] = "width: expected {$expectedCell['width']}, got {$actualCell->width()}";
                }

                if ($cellFailures !== []) {
                    $failures[] = "({$col}, {$row}): " . implode('; ', $cellFailures);
                }
            }
        }

        if ($failures !== []) {
            Assert::fail("Cell grid mismatch:\n  " . implode("\n  ", $failures));
        }
    }

    /**
     * Assert that $expected and $actual ANSI byte sequences are identical.
     *
     * On mismatch, produces a readable diff that prints ESC as `\x1b`
     * instead of the raw escape byte so reviewers can read it.
     *
     * @param string $expected Expected bytes
     * @param string $actual   Actual bytes
     */
    public static function assertAnsiEquals(string $expected, string $actual): void
    {
        if ($expected === $actual) {
            return;
        }

        $diff = self::diffAnsi($expected, $actual);
        Assert::fail("ANSI byte mismatch.\n\n{$diff}");
    }

    /**
     * Produce a readable ANSI-aware diff string.
     *
     * ESC sequences are shown as `\x1b[...]m` notation so humans
     * can read the visual difference.
     *
     * @param string $expected
     * @param string $actual
     * @return string
     */
    private static function diffAnsi(string $expected, string $actual): string
    {
        $expectedLines = explode("\n", self::escapeAnsi($expected));
        $actualLines = explode("\n", self::escapeAnsi($actual));

        $maxLines = max(count($expectedLines), count($actualLines));
        $diffLines = [];

        for ($i = 0; $i < $maxLines; $i++) {
            $exp = $expectedLines[$i] ?? '';
            $act = $actualLines[$i] ?? '';

            if ($exp === $act) {
                $diffLines[] = "  {$exp}";
            } else {
                if ($exp !== '') {
                    $diffLines[] = "- {$exp}";
                }
                if ($act !== '') {
                    $diffLines[] = "+ {$act}";
                }
            }
        }

        return implode("\n", $diffLines);
    }

    /**
     * Escape ANSI sequences in a string for human-readable display.
     *
     * Converts ESC bytes to `\x1b` and shows SGR parameters in brackets.
     *
     * @param string $bytes
     * @return string
     */
    private static function escapeAnsi(string $bytes): string
    {
        // First escape raw ESC bytes as \x1b for display.
        $withEscaped = str_replace("\x1b", '\\x1b', $bytes);

        // Then convert ANSI SGR sequences (ESC[...m) to readable \x1b[...m form.
        $result = preg_replace_callback(
            '/\\x1b\\[([0-9;]*)m/',
            static fn (array $m): string => '\\x1b[' . ($m[1] ?: '') . ']m',
            $withEscaped,
        );

        // preg_replace_callback returns null only on error (invalid regex or subject type).
        // With a valid static regex and string input this never happens, but handle it
        // explicitly so intent is clear rather than relying on ?? which is ambiguous here.
        return is_string($result) ? $result : $withEscaped;
    }
}
