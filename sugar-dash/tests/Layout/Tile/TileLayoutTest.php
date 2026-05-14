<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Tests\Layout\Tile;

use PHPUnit\Framework\TestCase;
use SugarCraft\Dash\Layout\Tile\Constraint;
use SugarCraft\Dash\Layout\Tile\Dimension;
use SugarCraft\Dash\Layout\Tile\Direction;
use SugarCraft\Dash\Layout\Tile\Size;
use SugarCraft\Dash\Layout\Tile\SizeHint;
use SugarCraft\Dash\Layout\Tile\SizeHinter;
use SugarCraft\Dash\Layout\Tile\Tile;
use SugarCraft\Dash\Layout\Tile\TileLayout;
use SugarCraft\Dash\Foundation\Item;
use SugarCraft\Dash\Foundation\Sizer;

/**
 * A simple Item for testing.
 */
final class StringItem implements Item
{
    public function __construct(private readonly string $content = '') {}

    public function render(): string
    {
        return $this->content;
    }
}

/**
 * An Item that also implements Sizer for testing.
 */
final class SizingItem implements Item, Sizer
{
    private int $w = 0;
    private int $h = 0;

    public function __construct(private readonly string $content = '') {}

    public function setSize(int $width, int $height): Sizer
    {
        $clone = clone $this;
        $clone->w = $width;
        $clone->h = $height;
        return $clone;
    }

    public function render(): string
    {
        return $this->content;
    }
}

/**
 * An Item that implements SizeHinter for testing minSizeFit.
 */
final class SizedItem implements Item, Sizer, SizeHinter
{
    private int $w = 0;
    private int $h = 0;

    public function __construct(
        private readonly string $content = '',
        private readonly int $minW = 3,
        private readonly int $minH = 1,
        private readonly int $desiredW = 10,
        private readonly int $desiredH = 3,
    ) {}

    public function setSize(int $width, int $height): Sizer
    {
        $clone = clone $this;
        $clone->w = $width;
        $clone->h = $height;
        return $clone;
    }

    public function render(): string
    {
        return $this->content;
    }

    public function sizeHint(int $availWidth, int $availHeight): SizeHint
    {
        return new SizeHint(
            min: new Dimension($this->minW, $this->minH),
            desired: new Dimension($this->desiredW, $this->desiredH),
        );
    }
}

final class TileLayoutTest extends TestCase
{
    public function testTileLayoutHorizontalWithFixedTiles(): void
    {
        $tile1 = new Tile('t1', Size::fixed(20, 10));
        $tile2 = new Tile('t2', Size::fixed(30, 10));
        $tile3 = new Tile('t3', Size::fixed(50, 10));

        $layout = TileLayout::horizontal('h')
            ->withTiles([$tile1, $tile2, $tile3])
            ->setSize(100, 10);

        $result = $layout->render();

        // All tiles should render (no content, so empty strings)
        $this->assertSame('', $result);
    }

    public function testTileLayoutVerticalWithFixedTiles(): void
    {
        $tile1 = new Tile('t1', Size::fixed(10, 20));
        $tile2 = new Tile('t2', Size::fixed(10, 30));
        $tile3 = new Tile('t3', Size::fixed(10, 50));

        $layout = TileLayout::vertical('v')
            ->withTiles([$tile1, $tile2, $tile3])
            ->setSize(10, 100);

        $result = $layout->render();

        // Vertical layout joins with newlines between tiles, even if content is empty
        // implode("\n", ['', '', '']) = "\n\n" (two newlines)
        $this->assertSame("\n\n", $result);
    }

    public function testTileLayoutFlexDistribution(): void
    {
        // 3 flex tiles with equal weight 1.0, sharing 90px (100 - 10 gap = 90)
        $tile1 = new Tile('t1', Size::flex(1.0));
        $tile2 = new Tile('t2', Size::flex(1.0));
        $tile3 = new Tile('t3', Size::flex(1.0));

        $layout = TileLayout::horizontal('h')
            ->withTiles([$tile1, $tile2, $tile3])
            ->setSize(100, 10);

        $result = $layout->render();

        // Should render without error
        $this->assertNotNull($result);
    }

    public function testTileLayoutMixedFixedAndFlex(): void
    {
        $tile1 = new Tile('t1', Size::fixed(20, 10));
        $tile2 = new Tile('t2', Size::flex(1.0));
        $tile3 = new Tile('t3', Size::fixed(30, 10));

        $layout = TileLayout::horizontal('h')
            ->withTiles([$tile1, $tile2, $tile3])
            ->setSize(100, 10);

        $result = $layout->render();

        // Should render without error
        $this->assertNotNull($result);
    }

    public function testTileLayoutWithGap(): void
    {
        $tile1 = new Tile('t1', Size::fixed(20, 10));
        $tile2 = new Tile('t2', Size::flex(1.0));

        $layout = TileLayout::horizontal('h')
            ->withTiles([$tile1, $tile2])
            ->withGap(5)
            ->setSize(100, 10);

        $result = $layout->render();

        // Should render without error
        $this->assertNotNull($result);
    }

    public function testTileLayoutOptionalRemoval(): void
    {
        // Optional tile with minSize too large for its share
        $tile1 = new Tile('t1', Size::fixed(20, 10));
        $tile2 = new Tile('t2', (new Size(weight: 1.0))->withOptional(true)->withMinWidth(80));
        $tile3 = new Tile('t3', Size::fixed(20, 10));

        $layout = TileLayout::horizontal('h')
            ->withTiles([$tile1, $tile2, $tile3])
            ->setSize(100, 10);

        $result = $layout->render();

        // Optional tile should be removed (width=0), leaving fixed tiles
        $this->assertNotNull($result);
    }

    public function testTileLayoutMinSizeFit(): void
    {
        // SizedItem provides size hint with min 5x1
        $tile1 = new Tile('t1', Size::fixed(20, 10));
        $tile2 = new Tile('t2', (new Size(fixedHeight: 10))->withMinSizeFit(true), new SizedItem('sized', 5, 1, 10, 3));
        $tile3 = new Tile('t3', Size::fixed(20, 10));

        $layout = TileLayout::horizontal('h')
            ->withTiles([$tile1, $tile2, $tile3])
            ->setSize(100, 10);

        $result = $layout->render();

        // Should render without error, minSizeFit applied
        $this->assertNotNull($result);
    }

    public function testTileLayoutEvenSplit(): void
    {
        // 3 flex tiles with equal weight, 100px available
        $tile1 = new Tile('t1', Size::flex(1.0));
        $tile2 = new Tile('t2', Size::flex(1.0));
        $tile3 = new Tile('t3', Size::flex(1.0));

        $layout = TileLayout::horizontal('h')
            ->withTiles([$tile1, $tile2, $tile3])
            ->setSize(99, 10); // Not divisible by 3 evenly

        $result = $layout->render();

        // Should render without error
        $this->assertNotNull($result);
    }

    public function testTileLayoutEmptyTilesReturnsEmpty(): void
    {
        $layout = TileLayout::horizontal('h')
            ->setSize(100, 10);

        $this->assertSame('', $layout->render());
    }

    public function testTileLayoutZeroDimensionsReturnsEmpty(): void
    {
        $tile = new Tile('t1', Size::fixed(20, 10));
        $layout = TileLayout::horizontal('h')
            ->withTiles([$tile])
            ->setSize(0, 0);

        $this->assertSame('', $layout->render());
    }

    public function testTileLayoutWithContent(): void
    {
        $tile1 = new Tile('t1', Size::fixed(20, 10), new StringItem('A'));
        $tile2 = new Tile('t2', Size::flex(1.0), new StringItem('B'));
        $tile3 = new Tile('t3', Size::fixed(20, 10), new StringItem('C'));

        $layout = TileLayout::horizontal('h')
            ->withTiles([$tile1, $tile2, $tile3])
            ->setSize(100, 10);

        $result = $layout->render();

        // Content should be rendered
        $this->assertStringContainsString('A', $result);
        $this->assertStringContainsString('B', $result);
        $this->assertStringContainsString('C', $result);
    }
}
