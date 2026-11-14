<?php

declare(strict_types=1);

namespace SugarCraft\Dashboard\Tests\Layout;

use PHPUnit\Framework\TestCase;
use SugarCraft\Dashboard\Foundation\Item;
use SugarCraft\Dashboard\Layout\GridLayout;
use SugarCraft\Dashboard\Layout\RatioGrid\GridItem;
use SugarCraft\Dashboard\Layout\RatioGrid\RatioGrid;
use SugarCraft\Dashboard\Layout\Tile\Direction;
use SugarCraft\Dashboard\Layout\Tile\Size;
use SugarCraft\Dashboard\Layout\Tile\Tile;
use SugarCraft\Dashboard\Layout\Tile\TileLayout;

/**
 * D-16 (finding sugar-dashboard-016): the layout wither family rebuilt
 * via `new self(...)`, silently dropping the setSize allocation that
 * lives outside the constructor — innerSize collapsed and render shrank
 * to a handful of characters after a single withGap. Every wither now
 * re-applies the dims (the Bar.php in-lib exemplar); these pins prove
 * the box survives each one.
 */
final class LayoutWitherCarryTest extends TestCase
{
    /**
     * A plain fixed-content item — enough to keep render non-empty.
     */
    private static function item(string $text): Item
    {
        return new class($text) implements Item {
            public function __construct(private readonly string $text) {}

            public function render(): string
            {
                return $this->text;
            }
        };
    }

    public function testGridLayoutWithersCarryTheAllocation(): void
    {
        $items = [self::item('alpha'), self::item('beta'), self::item('gamma'), self::item('delta')];
        $grid = (new GridLayout($items, 2, 2, 1, 1))->setSize(40, 10);
        $baseline = $grid->innerSize();
        $this->assertSame([40, 10], $baseline);
        $this->assertNotSame('', $grid->render());

        $cases = [
            'withItem' => $grid->withItem(self::item('epsilon')),
            'withItems' => $grid->withItems($items),
            'withColumns' => $grid->withColumns(3),
            'withRows' => $grid->withRows(2),
            'withColumnGap' => $grid->withColumnGap(2),
            'withRowGap' => $grid->withRowGap(2),
            'withGap' => $grid->withGap(2),
            'withTheme' => $grid->withTheme(\SugarCraft\Dashboard\Foundation\Theme::dark()),
        ];

        foreach ($cases as $label => $carried) {
            $this->assertSame($baseline, $carried->innerSize(), "$label dropped the allocation");
            $this->assertNotSame('', $carried->render(), "$label rendered empty after dropping dims");
            $this->assertGreaterThan(4, strlen($carried->render()), "$label render collapsed past a stub");
        }
    }

    public function testUnsizedGridLayoutWithersStayUnsized(): void
    {
        $grid = new GridLayout([self::item('x')], 1, 1);
        $natural = $grid->innerSize();
        $this->assertSame([1, 1], $natural, 'one 1-cell item, unsized, is the natural box itself');
        $this->assertSame($natural, $grid->withGap(3)->innerSize(), 'an unsized grid must not gain phantom dims');
    }

    public function testTileLayoutWithersCarryTheAllocation(): void
    {
        $tiles = [
            new Tile('a', new Size(20, 10), self::item('left')),
            new Tile('b', new Size(20, 10), self::item('right')),
        ];
        $layout = (new TileLayout('root', Direction::Horizontal, $tiles))->setSize(40, 10);
        $baseline = $layout->innerSize();
        $this->assertSame([40, 10], $baseline);
        $rendered = $layout->render();
        $this->assertNotSame('', $rendered);

        $cases = [
            'withTiles' => $layout->withTiles($tiles),
            'withTile' => $layout->withTile(new Tile('c', new Size(10, 10), self::item('more'))),
            'withDirection' => $layout->withDirection(Direction::Vertical),
            'withGap' => $layout->withGap(2),
            'withTheme' => $layout->withTheme(\SugarCraft\Dashboard\Foundation\Theme::dark()),
        ];

        foreach ($cases as $label => $carried) {
            $this->assertSame($baseline, $carried->innerSize(), "$label dropped the allocation");
            $this->assertNotSame('', $carried->render(), "$label rendered empty after dropping dims");
        }
    }

    public function testRatioGridWithCellCarriesTheAllocation(): void
    {
        $ratio = RatioGrid::new(2, 2)->setSize(30, 12);
        $baseline = $ratio->innerSize();
        $this->assertSame([30, 12], $baseline);

        $carried = $ratio->withCell(0, 0, GridItem::full(self::item('cell')));

        $this->assertSame($baseline, $carried->innerSize(), 'withCell wiped the grid allocation');
        $this->assertNotSame('', $carried->render(), 'withCell rendered an empty grid');
    }
}
