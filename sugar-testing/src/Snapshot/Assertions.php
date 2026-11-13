<?php

declare(strict_types=1);

namespace SugarCraft\Testing\Snapshot;

use InvalidArgumentException;
use PHPUnit\Framework\Assert;
use SugarCraft\CellBuffer\Buffer;
use SugarCraft\Core\Model;
use SugarCraft\Core\Util\Width;
use SugarCraft\Testing\Lang;

/**
 * Static assertion helpers for terminal snapshot testing.
 *
 * Provides assertGoldenAnsi, assertCellGrid, assertAnsiEquals, and
 * assertViewIdempotent for deterministic regression testing of terminal output.
 *
 * Upstream-antecedented (charmbracelet/x/exp/golden, teatest) but
 * first-party hardened — see docs/MATCHUPS.md.
 */
final class Assertions
{
    /**
     * How many mismatching cells a failure message dumps before switching
     * to a totals summary. An 80x24 grid gone wrong is 1,920 lines; the
     * first twenty locate the defect and the summary sizes the rest.
     */
    private const MAX_MISMATCH_REPORTED = 20;

    /** Keys a cell array may carry; anything else is a typo and fails loud. */
    private const CELL_KEYS = ['rune', 'width'];

    /**
     * Assert that a model's view() is idempotent and side-effect free.
     *
     * view() must be a pure projection of model state: two consecutive
     * calls must yield identical output, and the call must not mutate the
     * model. This underpins the render invariants the diff renderer relies
     * on — a view() that changed between frames for the same state would
     * desync the terminal. Intended as a reusable render-purity check across libs.
     *
     * The model-unchanged check uses a shallow clone, so mutation of a
     * nested object reachable by reference is not detected — the primary
     * guarantee is output idempotence.
     *
     * @param Model $model The model whose view() to check
     */
    public static function assertViewIdempotent(Model $model): void
    {
        $before = clone $model;

        $first  = $model->view();
        $second = $model->view();

        Assert::assertEquals(
            $first,
            $second,
            'view() is not idempotent: two consecutive calls returned different output.',
        );
        Assert::assertEquals(
            $before,
            $model,
            'view() mutated the model: model state changed after calling view().',
        );
    }

    /**
     * Assert that $actual matches the golden file at $goldenPath.
     *
     * Comparison is BYTE-EXACT: no SGR stripping, no line-1 header skip
     * (machine-stamped golden headers are a sugar-dash convention — its
     * own comparator strips them; this harness does not, so a golden here
     * must be byte-stable across machines).
     *
     * Re-baselining mode is CREATE-ONLY: when the golden is missing it is
     * written from $actual (and read back as a counted assertion); an
     * EXISTING golden that differs still fails and is never rewritten.
     * Delete the file to re-baseline deliberately. A CI run that starts
     * from a tree where the fixtures dir was never committed therefore
     * cannot silently "pass" by regenerating every baseline — see the
     * README's golden-workflow note.
     *
     * Both `SUGAR_TESTING_UPDATE_GOLDENS=1` and the legacy
     * `UPDATE_GOLDENS=1` arm creation.
     *
     * @param string $goldenPath Path to the golden file
     * @param string $actual     Current output bytes to assert
     */
    public static function assertGoldenAnsi(string $goldenPath, string $actual): void
    {
        $existing = GoldenFile::load($goldenPath);

        if ($existing === null) {
            if (!self::rebaseliningRequested()) {
                Assert::fail(sprintf(
                    "No golden file found at '%s'. Run once with UPDATE_GOLDENS=1 (or SUGAR_TESTING_UPDATE_GOLDENS=1) to create it; creation is one-way — an existing golden is never rewritten.",
                    $goldenPath,
                ));

                return;
            }

            try {
                GoldenFile::create($goldenPath, $actual);

                // Counted self-check: what we just wrote must read back
                // byte-identical (and a losing race winner would surface
                // here as a real failure, not a green side effect).
                Assert::assertSame(
                    $actual,
                    GoldenFile::load($goldenPath) ?? '',
                    sprintf("Golden created at '%s' did not read back byte-identical.", $goldenPath),
                );

                return;
            } catch (GoldenFileExistsException) {
                // Lost a create race against a concurrent run: adopt the
                // file that won and compare against it below, rather than
                // silently keeping ours.
                $existing = GoldenFile::load($goldenPath);
                if ($existing === null) {
                    Assert::fail(sprintf("Golden file '%s' vanished between existence check and read.", $goldenPath));

                    return;
                }
            }
        }

        if ($existing === $actual) {
            return;
        }

        // Show readable diff.
        $diff = self::diffAnsi($existing, $actual);
        Assert::fail(sprintf(
            "Golden file mismatch at '%s'. UPDATE_GOLDENS is create-only: to re-baseline, delete the file and rerun with it set.\n\n%s",
            $goldenPath,
            $diff,
        ));
    }

    /**
     * Convenience wrapper over {@see assertGoldenAnsi()} for the standard
     * layout: the golden lives in a `fixtures` directory next to the test
     * file that asserts it.
     *
     * `__DIR__` (or __DIR__ . '/..' from a tests/Sub/ file) is passed as
     * $testFileDir, so the same call shape works from any nesting depth:
     *
     * ```php
     * Assertions::golden(__DIR__, 'render-basic.txt', $actual);
     * ```
     *
     * The comparison semantics — byte-exact, create-only under the re-
     * baselining env vars, never rewrite-on-diff — are inherited from
     * assertGoldenAnsi() unchanged.
     *
     * @param string $testFileDir the test file's directory (a `fixtures`
     *                            subtree is derived from it)
     * @param string $name        golden file name inside fixtures
     * @param string $actual      current output bytes to assert
     */
    public static function golden(string $testFileDir, string $name, string $actual): void
    {
        self::assertGoldenAnsi(rtrim($testFileDir, '/') . '/fixtures/' . $name, $actual);
    }

    /**
     * Assert that $expected matches the Buffer's cell grid — EVERY cell.
     *
     * The grid is parsed at the boundary and must be well-formed and
     * complete; anything that could silently skip a comparison is a hard
     * error, because a test lib that passes without asserting is worse
     * than one that fails:
     *
     *  - the outer array must be a list of rows, and there must be exactly
     *    one row per buffer row (null rows are allowed — they skip that
     *    whole row — but the slot must exist);
     *  - each row is either a string (expanded cluster-by-cluster into
     *    rune+width cells, continuations included) or a list with exactly
     *    one entry per buffer column;
     *  - a cell is null (explicit don't-care wildcard), a single
     *    narrow-width grapheme string (compares the rune), or an array
     *    carrying only the keys 'rune' (string) and/or 'width' (0|1|2).
     *    Unknown keys — 'run', 'char', 'style', ... — throw immediately;
     *    so do empty arrays and 'rune' => null.
     *
     * Ragged rows never compare as implicit wildcards: a row that spans
     * fewer or more cells than the buffer width fails with its coordinate
     * in the message. At least one non-wildcard cell must be compared.
     *
     * @param array<int, mixed> $expected 2-D grid: rows of string|null|array cells (or string rows)
     * @param Buffer            $actual   Buffer to compare
     *
     * @throws InvalidArgumentException when the grid shape is malformed
     */
    public static function assertCellGrid(array $expected, Buffer $actual): void
    {
        $width  = $actual->width();
        $height = $actual->height();

        if (!array_is_list($expected)) {
            throw new InvalidArgumentException(
                'assertCellGrid(): $expected must be a list of rows keyed 0..n-1; a grid with gaps or string keys silently drops rows.',
            );
        }

        if (count($expected) !== $height) {
            Assert::fail(sprintf(
                'Dimension mismatch: expected %d rows, got %d. The grid must carry one row per buffer row (use a null row to skip one).',
                count($expected),
                $height,
            ));

            return;
        }

        // Parse don't validate: after this call every row is a complete list
        // of exactly $width entries, each null or a trusted ['rune'|'width'] shape.
        $grid = [];
        foreach ($expected as $rowIndex => $row) {
            $grid[$rowIndex] = $row === null
                ? array_fill(0, $width, null)
                : self::parseGridRow($row, $rowIndex, $width);
        }

        $failures = [];
        $compared = 0;

        for ($row = 0; $row < $height; $row++) {
            for ($col = 0; $col < $width; $col++) {
                $expectedCell = $grid[$row][$col];

                if ($expectedCell === null) {
                    continue; // explicit don't-care wildcard
                }

                $actualCell  = $actual->cellAt($col, $row);
                $cellFailures = [];

                if (array_key_exists('rune', $expectedCell) && $expectedCell['rune'] !== $actualCell->rune()) {
                    $cellFailures[] = sprintf("rune: expected '%s', got '%s'", $expectedCell['rune'], $actualCell->rune());
                }

                if (array_key_exists('width', $expectedCell) && $expectedCell['width'] !== $actualCell->width()) {
                    $cellFailures[] = sprintf('width: expected %d, got %d', $expectedCell['width'], $actualCell->width());
                }

                if ($cellFailures !== []) {
                    $failures[] = sprintf('(%d, %d): %s', $col, $row, implode('; ', $cellFailures));
                }

                $compared++;
            }
        }

        if ($compared === 0) {
            Assert::fail(
                'assertCellGrid(): every expected cell was a null wildcard — the grid compared zero cells and asserts nothing. Give at least one cell a rune or width.',
            );

            return;
        }

        if ($failures !== []) {
            $shown   = array_slice($failures, 0, self::MAX_MISMATCH_REPORTED);
            $summary = sprintf(
                '  (%d of %d cells compared; %d skipped as wildcards)',
                $compared,
                $width * $height,
                $width * $height - $compared,
            );

            if (count($failures) > count($shown)) {
                $shown[] = sprintf('  ... and %d more mismatching cells', count($failures) - count($shown));
            }

            Assert::fail("Cell grid mismatch:\n  " . implode("\n  ", $shown) . "\n" . $summary);

            return;
        }

        // A passing grid still counts: one real assertion over the
        // comparison tally, so "Risky: no assertions" can never be the
        // only tell that a grid quietly compared nothing.
        Assert::assertGreaterThan(
            0,
            $compared,
            'assertCellGrid(): compared no cells.',
        );
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
     * True when either the namespaced or the legacy re-baselining env var is armed.
     *
     * The bare `UPDATE_GOLDENS` spelling is a deprecated legacy alias: it
     * stays accepted through v1 so existing one-off re-baseline invocations
     * keep working, and is scheduled for removal in v2.
     */
    private static function rebaseliningRequested(): bool
    {
        // TODO(2027-05): drop the legacy UPDATE_GOLDENS alias read.
        return getenv('SUGAR_TESTING_UPDATE_GOLDENS') === '1'
            || getenv('UPDATE_GOLDENS') === '1';
    }

    /**
     * Parse one expected row into exactly $width trusted cells.
     *
     * @param mixed $row      string row | list of cells
     * @param int   $rowIndex for the error messages
     * @param int   $width    buffer width the row must span
     *
     * @return array<int, array{rune?: string, width?: int}|null>
     */
    private static function parseGridRow(mixed $row, int $rowIndex, int $width): array
    {
        if (is_string($row)) {
            return self::expandStringRow($row, $rowIndex, $width);
        }

        if (!is_array($row)) {
            throw new InvalidArgumentException(sprintf(
                'assertCellGrid(): row %d is %s; rows must be strings, cell lists, or null.',
                $rowIndex,
                get_debug_type($row),
            ));
        }

        if (!array_is_list($row)) {
            throw new InvalidArgumentException(sprintf(
                'assertCellGrid(): row %d has non-sequential keys (e.g. %s); an associative row silently leaves columns un-compared — list cells 0..%d.',
                $rowIndex,
                implode(', ', array_map(strval(...), array_slice(array_keys($row), 0, 3))),
                $width - 1,
            ));
        }

        if (count($row) !== $width) {
            throw self::raggedRowError($rowIndex, count($row), $width);
        }

        return array_map(self::parseGridCell(...), $row);
    }

    /**
     * Expand a plain string row into rune+width cells, cluster by cluster.
     *
     * Wide clusters claim their neighbour cell as a ['rune'=>'','width'=>0]
     * continuation, so a string row asserts the SAME facts an explicit
     * array row would — including widths.
     *
     * @return array<int, array{rune: string, width: int}>
     */
    private static function expandStringRow(string $row, int $rowIndex, int $width): array
    {
        // \X matches one extended grapheme cluster; false on invalid UTF-8.
        $clusters = preg_match_all('/\X/u', $row);

        if ($clusters === false) {
            throw new InvalidArgumentException(Lang::t('assertions.row_not_utf8', ['row' => $rowIndex]));
        }

        preg_match_all('/\X/u', $row, $matches);

        $built = [];
        foreach ($matches[0] as $cluster) {
            $cellWidth = max(Width::of($cluster), 1);
            $built[]   = ['rune' => $cluster, 'width' => $cellWidth];
            for ($skip = 1; $skip < $cellWidth; $skip++) {
                $built[] = ['rune' => '', 'width' => 0];
            }
        }

        if (count($built) !== $width) {
            throw self::raggedRowError($rowIndex, count($built), $width, sprintf('string row expands to %d cells', count($built)));
        }

        return $built;
    }

    /**
     * Normalize one cell to a trusted shape, or throw with a teaching message.
     *
     * @return array{rune?: string, width?: int}|null
     */
    private static function parseGridCell(mixed $cell): ?array
    {
        if ($cell === null) {
            return null;
        }

        if (is_string($cell)) {
            if ($cell === '') {
                throw new InvalidArgumentException(
                    "assertCellGrid(): an empty-string cell asserts nothing. Use ' ' for a blank cell, ['rune' => '', 'width' => 0] for a wide-rune continuation, or null to skip the position.",
                );
            }

            $clusters = preg_match_all('/\X/u', $cell);
            if ($clusters === false || $clusters !== 1) {
                throw new InvalidArgumentException(sprintf(
                    "assertCellGrid(): string cell '%s' is %d grapheme clusters; a string cell is exactly one narrow cell — use ['rune' => ..., 'width' => N] for a wide rune (plus the continuation cell after it).",
                    $cell,
                    $clusters === false ? -1 : $clusters,
                ));
            }

            if (Width::of($cell) !== 1) {
                throw new InvalidArgumentException(sprintf(
                    "assertCellGrid(): string cell '%s' is %d columns wide; use ['rune' => '%s', 'width' => %d] and follow it with ['rune' => '', 'width' => 0].",
                    $cell,
                    Width::of($cell),
                    $cell,
                    Width::of($cell),
                ));
            }

            return ['rune' => $cell];
        }

        if (!is_array($cell)) {
            throw new InvalidArgumentException(sprintf(
                'assertCellGrid(): cell of type %s is not a string, array, or null wildcard.',
                get_debug_type($cell),
            ));
        }

        if ($cell === []) {
            throw new InvalidArgumentException(
                'assertCellGrid(): an empty array cell asserts nothing; use null to skip the position.',
            );
        }

        foreach ($cell as $key => $value) {
            if (!in_array($key, self::CELL_KEYS, true)) {
                throw new InvalidArgumentException(sprintf(
                    "assertCellGrid(): unknown cell key '%s' (allowed: %s) — a typo'd key silently skipped its check.",
                    strval($key),
                    implode(', ', self::CELL_KEYS),
                ));
            }

            if ($key === 'rune') {
                if (!is_string($value)) {
                    throw new InvalidArgumentException(
                        "assertCellGrid(): 'rune' => " . ($value === null ? 'null' : get_debug_type($value)) . ' is not a string; to skip the position make the whole cell null, not just its rune.',
                    );
                }

                continue;
            }

            // 'width'
            if (!is_int($value) || $value < 0 || $value > 2) {
                throw new InvalidArgumentException(sprintf(
                    "assertCellGrid(): 'width' => %s is invalid; cell widths are 0 (continuation), 1, or 2.",
                    is_int($value) ? (string) $value : get_debug_type($value),
                ));
            }
        }

        return $cell;
    }

    /**
     * @param string|null $how extra phrasing for the counted width source
     */
    private static function raggedRowError(int $rowIndex, int $got, int $width, ?string $how = null): InvalidArgumentException
    {
        return new InvalidArgumentException(sprintf(
            'assertCellGrid(): row %d covers %d cells but the buffer is %d wide%s — ragged rows used to leave their tails never-compared, so the grid must state every column (pad with null wildcards to skip positions).',
            $rowIndex,
            $got,
            $width,
            $how === null ? '' : " ({$how})",
        ));
    }

    /**
     * Produce a readable ANSI-aware diff string.
     *
     * ESC sequences are shown as `\x1b[...]m` notation and other control
     * bytes are escaped, so humans can read the visual difference without
     * the terminal itself acting on the dump. Lines are numbered and a
     * missing line is shown as such — an empty-string placeholder must
     * never make a deleted or whitespace-only line look identical.
     *
     * @param string $expected
     * @param string $actual
     * @return string
     */
    private static function diffAnsi(string $expected, string $actual): string
    {
        $expectedLines = explode("\n", self::escapeAnsi($expected));
        $actualLines   = explode("\n", self::escapeAnsi($actual));

        $maxLines  = max(count($expectedLines), count($actualLines));
        $diffLines = [];

        if (count($expectedLines) !== count($actualLines)) {
            $diffLines[] = sprintf(
                '  line count differs: expected %d, got %d',
                count($expectedLines),
                count($actualLines),
            );
        }

        for ($i = 0; $i < $maxLines; $i++) {
            $number = sprintf('%4d |', $i + 1);

            if ($i >= count($expectedLines)) {
                $diffLines[] = $number . '+ ' . $actualLines[$i];

                continue;
            }

            if ($i >= count($actualLines)) {
                $diffLines[] = $number . '- ' . $expectedLines[$i];

                continue;
            }

            $exp = $expectedLines[$i];
            $act = $actualLines[$i];

            if ($exp === $act) {
                $diffLines[] = $number . '  ' . $exp;

                continue;
            }

            $diffLines[] = $number . '- ' . $exp;
            $diffLines[] = $number . '+ ' . $act;
        }

        return implode("\n", $diffLines);
    }

    /**
     * Escape control bytes in a string for human-readable display.
     *
     * ESC becomes `\x1b`, CR/BEL/DEL and every other C0 control except
     * newline and tab become `\xNN`, so a failure dump cannot be reprogrammed
     * by escape sequences or cursor movement arriving as ESC-led or C0 bytes.
     * The 8-bit C1 range (0x80-0x9F) is not covered: those bytes pass through
     * raw, which a latin-1-decoding terminal could still interpret as a
     * single-byte CSI.
     *
     * @param string $bytes
     * @return string
     */
    private static function escapeAnsi(string $bytes): string
    {
        $withEscaped = str_replace("\x1b", '\\x1b', $bytes);

        // Tab (\x09) and newline (\x0a) survive so the dump stays laid out;
        // CR, BEL, NUL, SGR-adjacent controls and DEL become \xNN.
        $result = preg_replace_callback(
            '/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/',
            static fn (array $m): string => '\x' . strtoupper(bin2hex($m[0])),
            $withEscaped,
        );

        if (!is_string($result)) {
            return $withEscaped;
        }

        return $result;
    }
}
