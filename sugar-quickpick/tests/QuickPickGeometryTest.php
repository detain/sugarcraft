<?php

declare(strict_types=1);

namespace SugarCraft\QuickPick\Tests;

use SugarCraft\Core\Util\Width;
use SugarCraft\QuickPick\{QuickPick, FilteredItem, Item};
use SugarCraft\QuickPick\Support\UntrustedText;
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
        // -002/-078 sweep: heights 1..6 x counts 0..4. The windowed region
        // (floor 2 rows applies to the setter) must never emit a row past
        // its budget, and any visible range text must read first <= last.
        for ($height = 1; $height <= 6; $height++) {
            for ($count = 0; $count <= 4; $count++) {
                $items = [];
                for ($i = 0; $i < $count; $i++) {
                    $items[] = new FilteredItem($i + 1, "item$i");
                }
                $handler = QuickPick::new($items)->setWindowHeight($height)->setWindowWidth(16)->show();
                $lines = \explode("\n", $handler->view(\implode("\n", \array_fill(0, 8, ''))));

                $budget = \max(2, $height);
                foreach (\array_slice($lines, $budget) as $offset => $line) {
                    $this->assertSame(
                        '',
                        $line,
                        "h=$height count=$count: overlay row leaked past the $budget-row window (line " . ($budget + $offset) . ')'
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

        $this->assertSame('ab' . \str_repeat(' ', 8) . '> ' . \str_repeat(' ', 10), $lines[0]);
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
            Width::padRight(Width::truncateAnsi('a prompt far longer than twenty cells: ', 20), 20),
            $lines[0],
            'the header keeps its left-most 20 cells'
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
}
