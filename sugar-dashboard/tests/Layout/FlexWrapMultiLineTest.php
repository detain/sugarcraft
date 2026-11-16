<?php

declare(strict_types=1);

namespace SugarCraft\Dashboard\Tests\Layout;

use PHPUnit\Framework\TestCase;
use SugarCraft\Dashboard\Foundation\Item;
use SugarCraft\Dashboard\Layout\FlexLayout;
use SugarCraft\Dashboard\Layout\FlexWrap;

/**
 * Regression pins for finding 035 (candy-dashboard-035): the wrapped row path
 * must treat each item's multi-line render as a full-height cell, so the seam
 * between wrapped rows lands between the last line of one child and the first
 * line of the next. The old code glued whole render() blobs together into one
 * row slot and let seams bleed.
 */
final class FlexWrapMultiLineTest extends TestCase
{
    /**
     * A plain text item: renders exactly what it holds, measures naturally.
     */
    private static function text(string $content): Item
    {
        return new class ($content) implements Item {
            public function __construct(private readonly string $content)
            {
            }

            public function render(): string
            {
                return $this->content;
            }
        };
    }

    public function testWrappedMultiLineChildrenComposeHonestSeams(): void
    {
        $layout = FlexLayout::row([
            self::text("AA\nBB"),
            self::text("CC\nDD"),
        ])
            ->withGap(1)
            ->withWrap(FlexWrap::Wrap)
            ->setSize(4, 4);

        self::assertSame(
            ["AA  ", "BB  ", "CC  ", "DD  "],
            explode("\n", $layout->render()),
            'each two-line child must own its wrapped row as two real lines'
        );
    }

    public function testWrappedSingleLineBytesMatchTheLegacyJoin(): void
    {
        $layout = FlexLayout::row([
            self::text('AAAA'),
            self::text('BBBB'),
        ])
            ->withWrap(FlexWrap::Wrap)
            ->setSize(7, 2);

        self::assertSame(
            ["AAAA   ", "BBBB   "],
            explode("\n", $layout->render()),
            'single-line wrap composition must stay byte-identical to the old join'
        );
    }

    public function testWrappedRowHeightAdvancesPerTallestCell(): void
    {
        $layout = FlexLayout::row([
            self::text("11\n22\n33"),
            self::text('X'),
        ])
            ->withGap(1)
            ->withWrap(FlexWrap::Wrap)
            ->setSize(3, 5);

        self::assertSame(
            ['11 ', '22 ', '33 ', 'X  ', '   '],
            explode("\n", $layout->render()),
            'the first wrapped row is three lines tall (its tallest cell), not one'
        );
    }

    public function testExactFillRowStaysOnTheSameWrappedLine(): void
    {
        $layout = FlexLayout::row([
            self::text('AAAA'),
            self::text('BBBB'),
            self::text('CCCC'),
        ])
            ->withWrap(FlexWrap::Wrap)
            ->setSize(8, 2);

        self::assertSame(
            ['AAAABBBB', 'CCCC    '],
            explode("\n", $layout->render()),
            'an item that exactly fills the remaining width must not wrap early'
        );
    }
}
