<?php

declare(strict_types=1);

namespace SugarCraft\QuickPick\Tests;

use SugarCraft\Core\Util\Width;
use SugarCraft\QuickPick\{QuickPick, FilteredItem, Item};
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
        $this->assertMatchesRegularExpression('/↕ showing \d+-\d+ of 12/', $rendered, 'scrolled list must say where it is (M13)');
        $this->assertStringContainsString('item11', $rendered);
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
        // header + separator and lets the pad budget place at least a
        // marker row.
        $h = QuickPick::new(['apple'])->setWindowHeight(1)->show();
        $lines = \explode("\n", $h->view(''));

        $this->assertGreaterThanOrEqual(2, \count($lines));
    }
}
