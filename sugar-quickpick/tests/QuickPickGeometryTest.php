<?php

declare(strict_types=1);

namespace SugarCraft\QuickPick\Tests;

use SugarCraft\Core\Util\Width;
use SugarCraft\QuickPick\{QuickPick, FilteredItem, Item, HelpBar, StatusBar};
use SugarCraft\QuickPick\Support\UntrustedText;
use SugarCraft\Style\Border;
use PHPUnit\Framework\TestCase;

/**
 * Pins for the geometry/state cluster (chunk 6):
 * M10 width-cache invalidation on prompt/formatter changes,
 * M11 auto width clamped to the terminal, M12 empty-state row,
 * M13 scroll position marker, mm8 negative offsets, mm9 height floor.
 */
final class QuickPickGeometryTest extends TestCase
{
    /**
     * Render against an empty background so measured rows carry exactly the
     * overlay width (a wide background row would mask frame geometry).
     *
     * @return list<string>
     */
    private function frame(QuickPick $h, array $items, int $bgCols = 200): array
    {
        $rendered = $h->withItems($items)->show()->view(
            \implode("\n", \array_fill(0, 30, ''))
        );

        return \explode("\n", $rendered);
    }

    public function testWidthCacheIsNotStaleAfterPromptChange(): void
    {
        $items = [new FilteredItem(1, 'ab')];
        $base = QuickPick::new($items);

        $narrow = $this->frame($base->setPrompt('> '), $items);
        $wide = $this->frame($base->setPrompt('choose a very long command: '), $items);

        $this->assertGreaterThan(
            Width::of($narrow[0]),
            Width::of($wide[0]),
            'a longer prompt must widen the auto-sized frame (M10)'
        );
    }

    public function testWidthCacheIsNotStaleAfterFormatterChange(): void
    {
        $items = [new FilteredItem(1, 'ab')];
        $bare = QuickPick::new($items);

        $short = $this->frame($bare, $items);
        $long = $this->frame(
            $bare->setItemFormatter(static fn(string $v): string => $v . \str_repeat('x', 40)),
            $items
        );

        $this->assertGreaterThan(Width::of($short[1]), Width::of($long[1]), 'formatter widening must take effect (M10)');
    }

    public function testAutoWidthIsClampedToTerminalColumns(): void
    {
        $h = QuickPick::new([new FilteredItem(1, \str_repeat('w', 300))])->setWindowHeight(4);
        // Empty background rows so rendered frame rows carry the overlay
        // width alone (a wider background line would dominate the measure).
        $lines = \explode("\n", $h->show()->view(\implode("\n", \array_fill(0, 30, ''))));

        // Auto width must clamp to the LIVE terminal width (devbox pty is
        // often 200+ cols — derive, never hardcode the fallback).
        $cols = (new \SugarCraft\Core\Util\Tty(\STDIN))->size()['cols'] ?: 80;
        $expected = \max(20, $cols);

        // The overlay occupies the first 4 frame rows.
        $widest = 0;
        for ($i = 0; $i < 4; $i++) {
            $widest = max($widest, Width::of($lines[$i]));
        }
        $this->assertLessThanOrEqual($cols, $widest, 'auto width must not overflow the terminal (M11)');
        $this->assertSame($expected, $widest);
        $this->assertLessThan(300, $widest, 'natural 304-cell width must be clamped (M11)');
    }

    public function testEmptyResultSetSaysNoMatches(): void
    {
        $h = QuickPick::new(['apple', 'banana'])->show()->type('zzz');
        $this->assertSame(0, $h->itemCount());

        $rendered = $h->view(\str_repeat('.', 40));
        $this->assertStringContainsString('no matches', $rendered, 'zero-match filter must be visible (M12)');
    }

    public function testEmptyStateAbsentWhenMatchesExist(): void
    {
        $h = QuickPick::new(['apple'])->show()->type('a');
        $this->assertStringNotContainsString('no matches', $h->view(\str_repeat('.', 40)));
    }

    public function testScrolledListCarriesPositionMarker(): void
    {
        $items = [];
        for ($i = 0; $i < 12; $i++) {
            $items[] = new FilteredItem($i + 1, "item$i");
        }
        $h = QuickPick::new($items)->setWindowHeight(6)->setWindowWidth(40)->show()->cursorBottom();

        $rendered = $h->view(\str_repeat('.', 40));
        $this->assertStringContainsString('item11', $rendered);

        // -078: the marker used to be pinned by presence regex only, so the
        // top+1 / top+maxShow arithmetic could drift unnoticed. h=6 leaves a
        // 4-row band; the overflow marker reserves one (3 items shown);
        // bottom cursor 11 pins top = min(11-3+1, 12-3) = 9 — the final
        // window of the list.
        $lines = \explode("\n", $rendered);
        $this->assertSame(
            '  ↕ showing 10-12 of 12',
            \rtrim($lines[5]),
            'marker arithmetic pinned exactly at the bottom cursor (M13/-078)'
        );
    }

    public function testNarrowFrameSuppressesMarkerInsteadOfGrowingTheFrame(): void
    {
        // -002: h=3 used to render 4 rows (header + separator + item +
        // marker) and h=2 emitted the inverted range 'showing 2-1 of 3'.
        // A 3-row frame now shows one item, no marker, and exactly its
        // budgeted rows — the background below stays untouched.
        $items = [new FilteredItem(1, 'one'), new FilteredItem(2, 'two'), new FilteredItem(3, 'three')];
        $h = QuickPick::new($items)->setWindowHeight(3)->setWindowWidth(12)->show();

        $lines = \explode("\n", $h->view(\implode("\n", \array_fill(0, 5, ''))));
        $this->assertStringNotContainsString('↕', \implode("\n", $lines), 'marker suppressed while it cannot fit');
        $this->assertStringContainsString('one', $lines[2]);
        $this->assertSame('', $lines[3], 'no overlay row past the 3-row window');
    }

    public function testWindowHeightInvariantAcrossHeightsAndCorpusSizes(): void
    {
        // -002/-078 sweep: heights 1..6 x counts 0..4, now x the S-40 rows
        // dimension (null = live terminal, 4 = shorter than any frozen h,
        // 30 = roomy). The windowed region (floor 2 rows applies to the
        // setter; the row budget may shrink it below the frozen h) must
        // never emit a row past its budget, and any visible range text
        // must read first <= last.
        for ($height = 1; $height <= 6; $height++) {
            for ($count = 0; $count <= 4; $count++) {
                foreach ([null, 4, 30] as $termRows) {
                    $items = [];
                    for ($i = 0; $i < $count; $i++) {
                        $items[] = new FilteredItem($i + 1, "item$i");
                    }
                    $handler = QuickPick::new($items)->setWindowHeight($height)->setWindowWidth(16);
                    if ($termRows !== null) {
                        $handler = $handler->withTtySizeProvider(
                            static fn(): array => ['cols' => 40, 'rows' => $termRows]
                        );
                    }
                    $handler = $handler->show();
                    $lines = \explode("\n", $handler->view(\implode("\n", \array_fill(0, 8, ''))));

                    $budget = $termRows === null
                        ? \max(2, $height)
                        : \min(\max(2, $height), $termRows);
                    foreach (\array_slice($lines, $budget) as $offset => $line) {
                        $this->assertSame(
                            '',
                            $line,
                            "h=$height count=$count rows=$termRows: overlay row leaked past the $budget-row window (line " . ($budget + $offset) . ')'
                        );
                    }

                    foreach ($lines as $line) {
                        if (\preg_match('/↕ showing (\d+)-(\d+) of (\d+)/', $line, $m) !== 1) {
                            continue;
                        }
                        $this->assertGreaterThanOrEqual(1, (int) $m[1], "range start below 1 (h=$height count=$count)");
                        $this->assertLessThanOrEqual(
                            (int) $m[2],
                            (int) $m[1],
                            "inverted range '$m[0]' (h=$height count=$count)"
                        );
                        $this->assertLessThanOrEqual(
                            (int) $m[3],
                            (int) $m[2],
                            "range end past corpus count (h=$height count=$count)"
                        );
                    }
                }
            }
        }
    }

    public function testAutoWidthNeverExceedsLiveTerminal(): void
    {
        // -059: the old clamp was min(computed, max(20, cols)) — on a real
        // terminal narrower than 20 cells the 20-cell ceiling exceeded the
        // screen itself. The rule now is simply min(computed, cols); on a
        // >=20-col host the observable pin is "never wraps the screen, and
        // the degenerate probe's fallback (80) already covers zero width".
        $cols = (new \SugarCraft\Core\Util\Tty(\STDIN))->size()['cols'] ?: 80;
        $h = QuickPick::new(['a'])->setWindowHeight(3)->show();
        $lines = \explode("\n", $h->view(\implode("\n", \array_fill(0, 4, ''))));
        foreach ($lines as $line) {
            $this->assertLessThanOrEqual($cols, Width::of($line), 'auto frame must never exceed the terminal');
        }
    }

    public function testNegativeWidthIsAutoAndWideWidthClampsToScreen(): void
    {
        // -027: setWindowWidth had zero validation — negatives happened to
        // read as auto downstream but were never normalised, and an
        // over-cols width composed rows that wrapped the screen.
        $items = [new FilteredItem(1, 'apple')];
        $this->assertSame(
            $this->frame(QuickPick::new($items)->setWindowWidth(0), $items),
            $this->frame(QuickPick::new($items)->setWindowWidth(-7), $items),
            'negative widths must normalise to auto (0)'
        );

        $cols = (new \SugarCraft\Core\Util\Tty(\STDIN))->size()['cols'] ?: 80;
        $lines = $this->frame(QuickPick::new($items)->setWindowWidth(100000)->setWindowHeight(3), $items);
        $this->assertSame($cols, Width::of($lines[0]), 'an over-wide frame must clamp to the live columns');
        foreach ($lines as $line) {
            $this->assertLessThanOrEqual($cols, Width::of($line));
        }
    }

    public function testBorderedFrameNeverSlicesItsRunes(): void
    {
        // -027: a bordered outer width of 1-2 used to compute inner=max(1,
        // outer-2) and emit three-cell rows anyway — the border lied about
        // the width it was given. The frame now floors at its
        // border-consumed minimum (two runes + one content cell).
        $h = QuickPick::new(['apple'])
            ->setWindowWidth(2)
            ->setWindowHeight(3)
            ->withBorder(Border::normal())
            ->show();
        $lines = \explode("\n", $h->view(\implode("\n", \array_fill(0, 4, ''))));

        $this->assertSame(3, Width::of($lines[0]), 'bordered frame floors at 3 cells');
        $this->assertSame(3, Width::of($lines[1]));
    }

    public function testOffsetColumnClampsToTerminalRightEdge(): void
    {
        // -063: setOffset(1000, 0) composed 1020+-cell rows. The column is
        // clamped at compose time so the overlay never starts past the
        // screen's right edge.
        $cols = (new \SugarCraft\Core\Util\Tty(\STDIN))->size()['cols'] ?: 80;
        $h = QuickPick::new(['apple'])
            ->setWindowWidth(20)
            ->setWindowHeight(3)
            ->setOffset(1000, 0)
            ->show();
        $lines = \explode("\n", $h->view(\implode("\n", \array_fill(0, 4, ''))));

        $this->assertSame($cols, Width::of($lines[0]), 'extreme offset pins the frame to the right edge');
        foreach ($lines as $line) {
            $this->assertLessThanOrEqual($cols, Width::of($line), 'no composed row may exceed the terminal');
        }
    }

    public function testEmptyCorpusSaysNoItemsDistinctFromNoMatches(): void
    {
        // -028: an empty corpus rendered a blank frame, indistinguishable
        // from a fault. It must say 'no items'; a filter that matched
        // nothing still says 'no matches' — the two states are distinct.
        $empty = QuickPick::new([])->setWindowHeight(4)->setWindowWidth(20)->show();
        $this->assertStringContainsString('no items', $empty->view(\str_repeat('.', 20)));

        $filtered = QuickPick::new(['apple'])->setWindowHeight(4)->setWindowWidth(20)->show()->type('zz');
        $rendered = $filtered->view(\str_repeat('.', 20));
        $this->assertStringContainsString('no matches', $rendered);
        $this->assertStringNotContainsString('no items', $rendered);
    }

    public function testFittingListCarriesNoMarker(): void
    {
        $h = QuickPick::new(['apple', 'banana'])->setWindowHeight(6)->show();
        $this->assertStringNotContainsString('↕', $h->view(\str_repeat('.', 40)));
    }

    public function testNegativeOffsetsClampToOrigin(): void
    {
        $h = QuickPick::new(['apple'])->setWindowWidth(20)->setWindowHeight(4);
        $bg = \str_repeat('.', 40) . "\n" . \str_repeat('.', 40);

        $this->assertSame(
            $h->setOffset(0, 0)->show()->view($bg),
            $h->setOffset(-5, -9)->show()->view($bg),
            'negative offsets must clamp to 0, not smear the composite (mm8)'
        );
    }

    public function testWindowHeightFloorKeepsItemBand(): void
    {
        // mm9: below 2 rows nothing could ever render; the floor keeps the
        // header + separator. Since -002 a 2-row frame carries no chrome at
        // all — the marker is suppressed while it cannot fit and the clamp
        // trims anything that would overflow the budget.
        $h = QuickPick::new(['apple'])->setWindowHeight(1)->show();
        $lines = \explode("\n", $h->view(''));

        $this->assertGreaterThanOrEqual(2, \count($lines));
    }

    public function testOverlayLandsAtOffsetColumnOverShortAndEmptyRows(): void
    {
        // S-01/-001: short and empty background rows must not drag the
        // overlay left of its column; the prefix is padded to the exact
        // offset width in display cells.
        $h = QuickPick::new(['apple'])
            ->setWindowWidth(12)
            ->setWindowHeight(3)
            ->setOffset(10, 0)
            ->show();

        $bg = "ab\n\n" . \str_repeat('.', 40);
        $lines = \explode("\n", $h->view($bg));

        $this->assertSame('ab' . \str_repeat(' ', 8) . '> ' . '▏' . \str_repeat(' ', 9), $lines[0]);
        $this->assertSame(\str_repeat(' ', 10) . \str_repeat('─', 12), $lines[1]);

        foreach ($lines as $line) {
            // Frame rows occupy columns 10..21; the long row keeps its
            // untouched suffix, short rows never exceed the segment end.
            $this->assertGreaterThanOrEqual(22, Width::of($line));
        }
        $this->assertSame(22, Width::of($lines[0]), 'a short background must not grow past the frame edge');
        $this->assertSame(22, Width::of($lines[1]));
    }

    public function testWideHeaderNeverExceedsFrameWidth(): void
    {
        // S-01/-003: the header used to be pad-only, so a prompt+filter
        // longer than the frame emitted an over-wide row that reached the
        // terminal whenever no background column was waiting behind it.
        $h = QuickPick::new(['x'])
            ->setWindowWidth(20)
            ->setWindowHeight(3)
            ->setPrompt('a prompt far longer than twenty cells: ')
            ->show();

        $lines = \explode("\n", $h->view(\implode("\n", \array_fill(0, 10, ''))));
        foreach (\array_slice($lines, 0, 3) as $line) {
            $this->assertSame(20, Width::of($line), 'header/separator/item rows clip+pad to the frame width');
        }

        $this->assertSame(
            Width::truncateAnsi('a prompt far longer than twenty cells: ', 20 - Width::of('▏')) . '▏',
            $lines[0],
            'the header clips its text one cell short of the frame to keep the caret at the edge (S-11)'
        );
    }

    public function testChromeRowsOverflowOnNarrowFramesAsDocumented(): void
    {
        // S-01: the marker/no-matches rows keep the documented pad-only
        // stance — on a frame narrower than the text they spill instead of
        // clipping; every OTHER row class stays exactly at frame width.
        $items = [];
        for ($i = 0; $i < 12; $i++) {
            $items[] = new FilteredItem($i + 1, "item$i");
        }
        $scrolled = QuickPick::new($items)
            ->setWindowWidth(8)
            ->setWindowHeight(4)
            ->show()
            ->cursorBottom();

        $lines = \explode("\n", $scrolled->view(\implode("\n", \array_fill(0, 6, ''))));
        $this->assertSame(8, Width::of($lines[0]));
        $this->assertSame(8, Width::of($lines[1]));
        $this->assertSame(8, Width::of($lines[2]));
        $this->assertMatchesRegularExpression('/↕ showing \d+-\d+ of 12/', $lines[3], 'marker text survives in full (pad-only)');
    }

    public function testItemFormatterNeverReceivesUnguardedValuesOnAnyPath(): void
    {
        // M8: the auto-width path used to hand the formatter the RAW value
        // while the render path passed the guarded one, so a formatter that
        // echoes or caches its argument could see the untrusted string even
        // though view() rendered a clean frame. Both paths must now deliver
        // byte-identical UntrustedText::guard() output.
        $hostile   = "\x1b[31mred\x1b[0m\x1b]0;evil\x07\u{e000}forged\u{e001}\ttab\nline";
        $received  = [];
        $formatter = static function (string $item) use (&$received): string {
            $received[] = $item;

            return $item;
        };

        $h = QuickPick::new(['plain', $hostile], $formatter)->show();
        // Auto width (windowWidth 0) forces computeWidth() over every item,
        // then the render pass consults the formatter again per visible row.
        $h->view(\implode("\n", \array_fill(0, 30, '')));

        $this->assertNotEmpty($received, 'the formatter must be consulted on the measured paths');
        foreach ($received as $value) {
            $this->assertStringNotContainsString("\x1b", $value, 'formatter must never receive raw escape sequences (M8)');
            $this->assertStringNotContainsString("\u{e000}", $value, 'formatter must never receive forged zone sentinels (M8)');
            $this->assertStringNotContainsString("\u{e001}", $value, 'formatter must never receive forged zone sentinels (M8)');
            $this->assertStringNotContainsString("\t", $value, 'formatter must never receive raw tabs (M8)');
            $this->assertStringNotContainsString("\n", $value, 'formatter must never receive raw newlines (M8)');
        }
        $this->assertContains(
            UntrustedText::guard($hostile),
            $received,
            'the guarded string itself must be exactly what the formatter receives (M8, non-vacuity)'
        );
    }

    public function testFrameSizedForTheWiderSelectedDecoration(): void
    {
        // -061: the old width pass measured every item with isSelected hard-
        // coded false, so a formatter whose selected decoration is WIDER
        // truncated exactly the focused row.
        $items   = [new FilteredItem(1, 'abcdefghij')];
        $h       = QuickPick::new($items)
            ->setItemFormatter(static fn(string $v, bool $sel): string => $sel ? $v . '::' : $v)
            ->show();
        $rows    = $this->frame($h, $items);
        $focused = null;
        foreach ($rows as $row) {
            if (str_contains($row, 'abcdefghij')) {
                $focused = $row;
                break;
            }
        }

        $this->assertNotNull($focused, 'the item row must render');
        $this->assertStringContainsString(
            'abcdefghij::',
            $focused,
            'the selected row keeps both decoration cells — the frame must size for it',
        );
    }

    public function testFilterKeystrokeNeverFormatsAgainForWidth(): void
    {
        // -010: per-item width measurements are cached by item object, so a
        // keystroke that only swaps the survivor set must not consult the
        // formatter for measurement again — only the render path runs.
        $calls     = 0;
        $formatter = static function (string $v) use (&$calls): string {
            ++$calls;
            return $v;
        };

        $h  = QuickPick::new(['alpha', 'bravo', 'charlie'], $formatter)->show();
        $bg = \implode("\n", \array_fill(0, 30, ''));

        $h->view($bg); // warm: 3 render calls + per-item measurement
        $warm  = $calls;
        $typed = $h->type('a');
        $typed->view($bg);

        // Exactly the three visible rows re-rendered; zero width-measurement
        // calls on top (old code added one false-flag pass per survivor).
        $this->assertSame(3, $calls - $warm, 'a re-filtered frame must format once per visible row, no width re-measurements');

        // The composed width still follows the CHEAP header term even though
        // no item is re-measured: a 60-rune prompt must widen the frame.
        // Header term = prompt + filter 'a' (1) + caret '▏' (1) + PAD 5.
        $promptLong = str_repeat('p', 60) . ': ';
        $long       = $typed->setPrompt($promptLong);
        $longRows   = \explode("\n", $long->view($bg));
        $this->assertSame(
            Width::of($promptLong) + 1 + 1 + 5,
            Width::of($longRows[0]),
            'a longer prompt must widen the auto frame from cached item widths',
        );
        $this->assertSame(
            $warm + 6,
            $calls,
            'the widened frame re-ran the render path only — zero width re-measurements',
        );
    }

    public function testFormatterSwapReplacesTheMeasurementTable(): void
    {
        $items = [new FilteredItem(1, 'ab')];
        $a     = QuickPick::new($items)
            ->setItemFormatter(static fn(string $v): string => $v)
            ->show();
        $bg = \implode("\n", \array_fill(0, 30, ''));
        $a->view($bg); // warm measurements with the plain formatter

        $b      = $a->setItemFormatter(static fn(string $v, bool $sel): string => $sel ? $v . \str_repeat('w', 30) : $v);
        $rows   = $this->frame($b, $items);
        $found  = false;
        foreach ($rows as $row) {
            if (str_contains($row, 'ab' . \str_repeat('w', 30))) {
                $found = true;
                break;
            }
        }
        $this->assertTrue($found, 'a wider replacement formatter must re-derive widths, not reuse the swapped-out table');
    }

    public function testRowBudgetShrinksAWindowedFrameToTheTerminal(): void
    {
        // -029: terminal rows were never consulted — a frozen h=10 emitted
        // 10 rows on a 5-row screen and the overflow scrolled off nobody
        // could reach. The budget now clamps to the screen, and the window
        // math (band, marker reservation) follows the SHRUNK number.
        $items = [];
        for ($i = 0; $i < 12; $i++) {
            $items[] = new FilteredItem($i + 1, "item$i");
        }
        $pick = QuickPick::new($items)
            ->setWindowHeight(10)
            ->setWindowWidth(16)
            ->withTtySizeProvider(static fn(): array => ['cols' => 40, 'rows' => 5])
            ->show();

        $lines = \explode("\n", $pick->view(''));
        $this->assertCount(5, $lines, 'a 5-row screen spare exactly 5 frame rows');

        // 5-row budget: header + separator leave a 3-row band; the marker
        // reserves one, so 2 items and the honest range survive. The marker
        // row is pad-only chrome and overflows the 16-cell frame verbatim.
        $this->assertSame(
            '  ↕ showing 1-2 of 12',
            \rtrim($lines[4]),
            'the marker arithmetic follows the shrunk band, not the frozen h'
        );
    }

    public function testRowBudgetIsShrinkOnlyNeverGrowth(): void
    {
        // Resize UP must recover toward the frozen height but never past it:
        // the host's setWindowHeight stays the maximum, mirroring the
        // antecedent's model re-clamp.
        $tall = QuickPick::new(['a', 'b', 'c', 'd'])
            ->setWindowHeight(10)
            ->setWindowWidth(16)
            ->withTtySizeProvider(static fn(): array => ['cols' => 40, 'rows' => 20])
            ->show();
        $this->assertCount(10, \explode("\n", $tall->view('')), 'a roomy screen restores the frozen 10, not 20');

        $tiny = $tall->setWindowHeight(3);
        $this->assertCount(3, \explode("\n", $tiny->view('')), 'a shrunk h stays shrunk on a big screen');
    }

    public function testRowBudgetReservesBorderAndBarChrome(): void
    {
        // The rows appended AFTER the windowed region (border top+bottom,
        // visible help/status bars) come off the screen before the window
        // is sized, so the total frame fits: 6 rows with a border = 4
        // windowed + 2 frame rows; 7 rows with two bars = 5 + 2.
        $bordered = QuickPick::new(['a', 'b', 'c'])
            ->setWindowHeight(10)
            ->setWindowWidth(16)
            ->withBorder(Border::normal())
            ->withTtySizeProvider(static fn(): array => ['cols' => 40, 'rows' => 6])
            ->show();
        $this->assertCount(6, \explode("\n", $bordered->view('')), 'bordered frame fits a 6-row screen exactly');

        $barred = QuickPick::new(['a', 'b', 'c'])
            ->setWindowHeight(10)
            ->setWindowWidth(16)
            ->withHelpBar(new HelpBar(['ctrl-q' => 'quit']))
            ->withStatusBar(new StatusBar('ready'))
            ->withTtySizeProvider(static fn(): array => ['cols' => 40, 'rows' => 7])
            ->show();
        $this->assertCount(7, \explode("\n", $barred->view('')), 'window + two visible bars fit a 7-row screen exactly');
    }

    public function testRowBudgetFloorsAtTheTwoRowMinimumFrame(): void
    {
        // A screen too short for anything honest still gets the two-row
        // minimum (header + separator): clamping to 1 would render an
        // unusable stub instead of a slightly overflowing minimal frame.
        $pick = QuickPick::new(['a', 'b'])
            ->setWindowHeight(10)
            ->setWindowWidth(16)
            ->withTtySizeProvider(static fn(): array => ['cols' => 40, 'rows' => 1])
            ->show();
        $this->assertCount(2, \explode("\n", $pick->view('')), 'the documented floor survives a 1-row screen');
    }
}
