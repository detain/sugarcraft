<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Tui;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\Util\Ansi;
use SugarCraft\Core\Util\Width;
use SugarCraft\Crush\Backend\EchoBackend;
use SugarCraft\Crush\Chat;
use SugarCraft\Crush\Message;
use SugarCraft\Crush\Renderer;
use SugarCraft\Crush\Tui\TextSelection;
use SugarCraft\Mouse\Selection;
use SugarCraft\Mouse\SelectionRange;
use SugarCraft\Sprinkles\Style;

/**
 * The rewire of {@see TextSelection} onto candy-mouse's upstreamed
 * {@see Selection} / {@see SelectionRange} (crush_libs candy-mouse #7).
 *
 * The rewire changed the coordinate base — 1-based terminal cells instead of
 * the old 0-based frame offsets, dropping Chat's `[$col - 1, $row - 1]` step —
 * and nothing else. The sweep below pins "nothing else" against a reference
 * transcription of the pre-rewire 0-based geometry: every anchor in a region,
 * every pointer in and around it, must clamp, span, extract and highlight
 * exactly as before, one cell over.
 */
final class TextSelectionCandyMouseTest extends TestCase
{
    /** The pre-rewire 0-based region: rowFrom, rowTo, colFrom, colTo. */
    private const LEGACY_REGION = [1, 4, 3, 12];

    /** The frame the sweep reads: border chrome, SGR runs, a wide CJK pair, a short row and a blank row. */
    private const LINES = [
        '╭──────────────╮',
        "│  \e[1malpha\e[0m beta  │",
        '│      f();    │',
        '│  日本 omega  │',
        '│  x',
        '╰──────────────╯',
    ];

    public function testEveryGestureMatchesThePreRewireGeometryOneCellOver(): void
    {
        $region = array_map(static fn (int $v): int => $v + 1, self::LEGACY_REGION);
        $style = Style::new()->reverse();
        $checked = 0;

        // Every edge and its neighbours (the region is rows 1-4, cols 3-12),
        // plus interior points: the wide pair at cols 3-6 of row 3, the SGR run
        // on row 1, the short row 4. A full grid is ~4x the cost for no new edge.
        $anchorRows = [0, 1, 2, 3, 4, 5];
        $anchorCols = [2, 3, 4, 5, 7, 11, 12, 13];
        $headRows = [-2, 0, 1, 2, 3, 4, 5, 7];
        $headCols = [-2, 2, 3, 4, 5, 6, 9, 11, 12, 13, 16];

        foreach ($anchorRows as $anchorRow) {
            foreach ($anchorCols as $anchorCol) {
                $legacy = self::legacyAt($anchorCol, $anchorRow, self::LEGACY_REGION);
                $sel = TextSelection::at($anchorCol + 1, $anchorRow + 1, $region);

                if ($legacy === null) {
                    self::assertNull($sel, "press ({$anchorCol},{$anchorRow}) anchored nothing before");
                    continue;
                }
                self::assertNotNull($sel, "press ({$anchorCol},{$anchorRow}) anchored before");

                foreach ($headRows as $headRow) {
                    foreach ($headCols as $headCol) {
                        $old = self::legacyWithHead($legacy, $headCol, $headRow);
                        $new = $sel->withHead($headCol + 1, $headRow + 1);
                        $at = "anchor ({$anchorCol},{$anchorRow}) head ({$headCol},{$headRow})";

                        self::assertSame([$old['headCol'] + 1, $old['headRow'] + 1], [$new->headCol, $new->headRow], "{$at}: clamp");

                        for ($row = -1; $row <= 6; $row++) {
                            $oldSpan = self::legacySpan($old, $row);
                            self::assertSame(
                                $oldSpan === null ? null : [$oldSpan[0] + 1, $oldSpan[1] + 1],
                                $new->span($row + 1),
                                "{$at}: span of row {$row}",
                            );
                        }

                        self::assertSame(self::legacyExtract($old, self::LINES), $new->extract(self::LINES), "{$at}: extract");
                        self::assertSame(
                            self::legacyHighlight($old, self::LINES, $style),
                            $new->highlight(self::LINES, $style),
                            "{$at}: highlight",
                        );
                        $checked++;
                    }
                }
            }
        }

        self::assertGreaterThan(2000, $checked, 'the sweep actually ran');
    }

    public function testRangeIsCandyMousesOwnSnapshotOfTheSameGesture(): void
    {
        $sel = TextSelection::at(9, 4, [2, 5, 4, 13])?->withHead(5, 2);
        self::assertNotNull($sel);

        $gesture = Selection::new(2, 5, 4, 13);
        $gesture->begin(4, 9);
        $gesture->dragTo(2, 5);

        self::assertInstanceOf(SelectionRange::class, $sel->range());
        self::assertEquals($gesture->range(), $sel->range());
        self::assertSame([2, 5, 4, 9], [$sel->range()->startRow, $sel->range()->startCol, $sel->range()->endRow, $sel->range()->endCol]);
    }

    public function testMovingTheHeadNeverMovesAnEarlierSnapshot(): void
    {
        $sel = TextSelection::at(6, 3, [2, 5, 4, 13]);
        self::assertNotNull($sel);

        $dragged = $sel->withHead(10, 5);
        $draggedAgain = $dragged->withHead(4, 2);

        self::assertSame([3, 6, 3, 6], [$sel->range()->startRow, $sel->range()->startCol, $sel->range()->endRow, $sel->range()->endCol]);
        self::assertSame([3, 6, 5, 10], [$dragged->range()->startRow, $dragged->range()->startCol, $dragged->range()->endRow, $dragged->range()->endCol]);
        self::assertSame([2, 4, 3, 6], [$draggedAgain->range()->startRow, $draggedAgain->range()->startCol, $draggedAgain->range()->endRow, $draggedAgain->range()->endCol]);
        self::assertSame([10, 5], [$dragged->headCol, $dragged->headRow], 'a later step on a copy leaves this one where it was');
    }

    public function testTheRenderersSelectableRegionIsInOneBasedFrameCells(): void
    {
        Chat::clearTextSelection();
        $chat = (new Chat(
            history: [Message::user("alpha beta gamma\n    indented();\nomega")],
            backend: new EchoBackend(),
        ))->withSize(80, 24);

        Renderer::render($chat);
        $region = Renderer::selectableRegion();
        self::assertNotNull($region);
        [$rowFrom, $rowTo, $colFrom, $colTo] = $region;
        $lines = array_map(static fn (string $line): string => Ansi::strip($line), Renderer::selectableLines());

        // Frame line R is $lines[R - 1]: the rows just outside the region are
        // the shell's top and bottom borders.
        self::assertStringStartsWith('╭', $lines[$rowFrom - 2]);
        self::assertStringStartsWith('╰', $lines[$rowTo]);

        // Cell C of a row is its C-th column: the region's first column is the
        // turn's first text cell, and its last column is the final cell inside
        // the right padding.
        $text = self::rowContaining($lines, 'user>');
        self::assertSame('user>', mb_substr($text, $colFrom - 1, 5));
        self::assertSame('│', mb_substr($text, $colTo + 2, 1));
        self::assertSame('  ', mb_substr($text, $colTo, 2));
    }

    // =========================================================================
    // The pre-rewire 0-based geometry, transcribed from TextSelection as it
    // stood before it delegated to candy-mouse. Reference only.
    // =========================================================================

    /**
     * @param array{0:int,1:int,2:int,3:int} $region
     * @return array{anchorCol:int,anchorRow:int,headCol:int,headRow:int,region:array{0:int,1:int,2:int,3:int}}|null
     */
    private static function legacyAt(int $col, int $row, array $region): ?array
    {
        [$rowFrom, $rowTo, $colFrom, $colTo] = $region;
        if ($rowTo < $rowFrom || $colTo < $colFrom) {
            return null;
        }
        if ($row < $rowFrom || $row > $rowTo || $col < $colFrom || $col > $colTo) {
            return null;
        }

        return ['anchorCol' => $col, 'anchorRow' => $row, 'headCol' => $col, 'headRow' => $row, 'region' => $region];
    }

    /**
     * @param array{anchorCol:int,anchorRow:int,headCol:int,headRow:int,region:array{0:int,1:int,2:int,3:int}} $sel
     * @return array{anchorCol:int,anchorRow:int,headCol:int,headRow:int,region:array{0:int,1:int,2:int,3:int}}
     */
    private static function legacyWithHead(array $sel, int $col, int $row): array
    {
        [$rowFrom, $rowTo, $colFrom, $colTo] = $sel['region'];
        if ($row < $rowFrom) {
            [$row, $col] = [$rowFrom, $colFrom];
        } elseif ($row > $rowTo) {
            [$row, $col] = [$rowTo, $colTo];
        }

        return ['headCol' => max($colFrom, min($colTo, $col)), 'headRow' => $row] + $sel;
    }

    /**
     * @param array{anchorCol:int,anchorRow:int,headCol:int,headRow:int,region:array{0:int,1:int,2:int,3:int}} $sel
     * @return array{0:int,1:int,2:int,3:int}
     */
    private static function legacyOrdered(array $sel): array
    {
        $headFirst = $sel['headRow'] < $sel['anchorRow']
            || ($sel['headRow'] === $sel['anchorRow'] && $sel['headCol'] < $sel['anchorCol']);

        return $headFirst
            ? [$sel['headRow'], $sel['headCol'], $sel['anchorRow'], $sel['anchorCol']]
            : [$sel['anchorRow'], $sel['anchorCol'], $sel['headRow'], $sel['headCol']];
    }

    /**
     * @param array{anchorCol:int,anchorRow:int,headCol:int,headRow:int,region:array{0:int,1:int,2:int,3:int}} $sel
     * @return array{0:int,1:int}|null
     */
    private static function legacySpan(array $sel, int $row): ?array
    {
        [$startRow, $startCol, $endRow, $endCol] = self::legacyOrdered($sel);
        if ($row < $startRow || $row > $endRow) {
            return null;
        }
        [, , $colFrom, $colTo] = $sel['region'];

        return [$row === $startRow ? $startCol : $colFrom, $row === $endRow ? $endCol : $colTo];
    }

    /**
     * @param array{anchorCol:int,anchorRow:int,headCol:int,headRow:int,region:array{0:int,1:int,2:int,3:int}} $sel
     * @param list<string> $lines
     */
    private static function legacyExtract(array $sel, array $lines): string
    {
        [$startRow, , $endRow] = self::legacyOrdered($sel);
        $rows = [];
        for ($row = $startRow; $row <= $endRow; $row++) {
            [$from, $to] = self::legacySpan($sel, $row) ?? [0, -1];
            $plain = Ansi::strip($lines[$row] ?? '');
            $rows[] = rtrim(Width::takeAnsi(Width::dropAnsi($plain, $from), $to - $from + 1), " \t");
        }
        while ($rows !== [] && trim((string) $rows[0]) === '') {
            array_shift($rows);
        }
        while ($rows !== [] && trim((string) end($rows)) === '') {
            array_pop($rows);
        }

        return implode("\n", $rows);
    }

    /**
     * @param array{anchorCol:int,anchorRow:int,headCol:int,headRow:int,region:array{0:int,1:int,2:int,3:int}} $sel
     * @param list<string> $lines
     * @return list<string>
     */
    private static function legacyHighlight(array $sel, array $lines, Style $style): array
    {
        [$startRow, , $endRow] = self::legacyOrdered($sel);
        for ($row = $startRow; $row <= $endRow; $row++) {
            if (!isset($lines[$row])) {
                continue;
            }
            [$from, $to] = self::legacySpan($sel, $row) ?? [0, -1];
            $cells = $to - $from + 1;
            if ($cells <= 0) {
                continue;
            }
            $line = $lines[$row];
            $head = Width::truncateAnsi($line, $from);
            $pad = str_repeat(' ', max(0, $from - Width::string($head)));
            $run = Ansi::strip(Width::takeAnsi(Width::dropAnsi($line, $from), $cells));
            $run .= str_repeat(' ', max(0, $cells - Width::string($run)));
            $lines[$row] = $head . Ansi::reset() . $pad . $style->render($run) . Ansi::reset() . Width::dropAnsi($line, $from + $cells);
        }

        return $lines;
    }

    /**
     * @param list<string> $lines
     */
    private static function rowContaining(array $lines, string $needle): string
    {
        foreach ($lines as $line) {
            if (str_contains($line, $needle)) {
                return $line;
            }
        }

        self::fail("'{$needle}' is not on the frame");
    }
}
