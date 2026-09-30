<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Tests\Components\GridTable;

use SugarCraft\Core\Util\Width;
use SugarCraft\Dash\Layout\GridItem;
use SugarCraft\Dash\Layout\GridLayout;
use SugarCraft\Dash\Foundation\Item;
use SugarCraft\Dash\Foundation\Sizer;
use SugarCraft\Dash\Foundation\SizedItem;
use PHPUnit\Framework\TestCase;

final class GridLayoutTest extends TestCase
{
    private function strItem(string $s): Item
    {
        return new class($s) implements Item {
            public function __construct(private readonly string $s) {}
            public function render(): string { return $this->s; }
        };
    }

    private function sizedItem(): Item
    {
        return new class implements Item, SizedItem {
            public int $capturedW = 0;
            public int $capturedH = 0;
            private int $w = 0;
            private int $h = 0;

            public function setSize(int $width, int $height): Sizer
            {
                $clone = clone $this;
                $clone->capturedW = $width;
                $clone->capturedH = $height;
                $clone->w = $width;
                $clone->h = $height;
                return $clone;
            }
            public function render(): string
            {
                return "Size:{$this->w}x{$this->h}";
            }
            public function getInnerSize(): array
            {
                return [$this->w, $this->h];
            }
        };
    }

    // ═══════════════════════════════════════════════════════════════
    // Interface conformance
    // ═══════════════════════════════════════════════════════════════

    public function testGridLayoutImplementsSizer(): void
    {
        $layout = GridLayout::columns(2);
        $this->assertInstanceOf(Sizer::class, $layout);
    }

    public function testGridLayoutImplementsItem(): void
    {
        $layout = GridLayout::columns(2);
        $this->assertInstanceOf(Item::class, $layout);
    }

    // ═══════════════════════════════════════════════════════════════
    // Factory methods
    // ═══════════════════════════════════════════════════════════════

    public function testColumnsFactoryCreatesGridWithColumns(): void
    {
        $layout = GridLayout::columns(3, [
            $this->strItem('A'),
            $this->strItem('B'),
            $this->strItem('C'),
        ]);
        $layout = $layout->setSize(30, 10);

        $rendered = $layout->render();
        $this->assertStringContainsString('A', $rendered);
        $this->assertStringContainsString('B', $rendered);
        $this->assertStringContainsString('C', $rendered);
    }

    public function testRowsFactoryCreatesGridWithRows(): void
    {
        $layout = GridLayout::rows(2, [
            $this->strItem('A'),
            $this->strItem('B'),
        ]);
        $layout = $layout->setSize(10, 10);

        $rendered = $layout->render();
        $this->assertStringContainsString('A', $rendered);
        $this->assertStringContainsString('B', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Basic rendering
    // ═══════════════════════════════════════════════════════════════

    public function testRenderEmptyLayoutReturnsEmpty(): void
    {
        $layout = GridLayout::columns(2);
        $this->assertSame('', $layout->render());
    }

    public function testRenderWithSizeRendersCorrectly(): void
    {
        $layout = GridLayout::columns(2, [$this->strItem('hello')]);
        $layout = $layout->setSize(20, 10);

        $rendered = $layout->render();
        $this->assertStringContainsString('hello', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Gap support
    // ═══════════════════════════════════════════════════════════════

    public function testWithColumnGapAddsHorizontalSpacing(): void
    {
        $layout = GridLayout::columns(2, [$this->strItem('A'), $this->strItem('B')])
            ->withColumnGap(2)
            ->setSize(20, 5);

        $rendered = $layout->render();
        $this->assertNotSame('', $rendered);
    }

    public function testWithRowGapAddsVerticalSpacing(): void
    {
        $layout = GridLayout::columns(1, [$this->strItem('A'), $this->strItem('B')])
            ->withRowGap(2)
            ->setSize(10, 15);

        $rendered = $layout->render();
        $this->assertNotSame('', $rendered);
    }

    public function testWithGapSetsBothGaps(): void
    {
        $layout = GridLayout::columns(2, [
            $this->strItem('A'),
            $this->strItem('B'),
            $this->strItem('C'),
            $this->strItem('D'),
        ])
            ->withGap(1)
            ->setSize(20, 10);

        $rendered = $layout->render();
        $this->assertNotSame('', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Multi-column layout
    // ═══════════════════════════════════════════════════════════════

    public function testMultipleItemsInColumns(): void
    {
        $layout = GridLayout::columns(3, [
            $this->strItem('A'),
            $this->strItem('B'),
            $this->strItem('C'),
            $this->strItem('D'),
            $this->strItem('E'),
            $this->strItem('F'),
        ])
            ->setSize(30, 10);

        $rendered = $layout->render();
        $this->assertStringContainsString('A', $rendered);
        $this->assertStringContainsString('D', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Size propagation
    // ═══════════════════════════════════════════════════════════════

    public function testSetSizePropagatesToSizerItems(): void
    {
        $sized = $this->sizedItem();
        $layout = GridLayout::columns(1, [$sized])
            ->setSize(20, 5);

        $rendered = $layout->render();
        $this->assertStringContainsString('Size:', $rendered);
    }

    public function testSetSizeReturnsNewInstance(): void
    {
        $original = GridLayout::columns(2, [$this->strItem('test')]);
        $resized = $original->setSize(10, 3);

        $this->assertNotSame($original, $resized);
    }

    // ═══════════════════════════════════════════════════════════════
    // Natural size calculation
    // ═══════════════════════════════════════════════════════════════

    public function testGetInnerSizeReturnsAllocatedSizeWhenSet(): void
    {
        $layout = GridLayout::columns(2, [$this->strItem('test')])
            ->setSize(15, 5);

        [$w, $h] = $layout->getInnerSize();
        $this->assertSame(15, $w);
        $this->assertSame(5, $h);
    }

    public function testGetInnerSizeCalculatesNaturalSizeWhenNotSet(): void
    {
        $layout = GridLayout::columns(2, [$this->strItem('hello')]);

        [$w, $h] = $layout->getInnerSize();
        $this->assertGreaterThan(0, $w);
        $this->assertGreaterThan(0, $h);
    }

    // ═══════════════════════════════════════════════════════════════
    // Withers / fluent API
    // ═══════════════════════════════════════════════════════════════

    public function testWithItemAddsItem(): void
    {
        $layout = GridLayout::columns(2, [$this->strItem('A')])
            ->withItem($this->strItem('B'))
            ->setSize(10, 10);

        $rendered = $layout->render();
        $this->assertStringContainsString('A', $rendered);
        $this->assertStringContainsString('B', $rendered);
    }

    public function testWithItemsSetsItems(): void
    {
        $layout = GridLayout::columns(2)
            ->withItems([$this->strItem('X'), $this->strItem('Y')])
            ->setSize(10, 10);

        $rendered = $layout->render();
        $this->assertStringContainsString('X', $rendered);
        $this->assertStringContainsString('Y', $rendered);
    }

    public function testWithColumnsChangesColumnCount(): void
    {
        $layout = GridLayout::columns(2, [$this->strItem('A'), $this->strItem('B')])
            ->withColumns(1)
            ->setSize(10, 10);

        $rendered = $layout->render();
        $this->assertStringContainsString('A', $rendered);
        $this->assertStringContainsString('B', $rendered);
    }

    public function testWithRowsChangesRowCount(): void
    {
        $layout = GridLayout::columns(1)
            ->withRows(3)
            ->withItems([$this->strItem('A'), $this->strItem('B')])
            ->setSize(10, 10);

        $rendered = $layout->render();
        $this->assertStringContainsString('A', $rendered);
        $this->assertStringContainsString('B', $rendered);
    }

    public function testConstructorClampsZeroColumnsToOne(): void
    {
        $items = [$this->strItem('A'), $this->strItem('B'), $this->strItem('C')];

        // Pre-fix this was a DivisionByZeroError inside the row/col math.
        $hostile = (new GridLayout($items, 0))->setSize(12, 6)->render();

        $this->assertSame(
            GridLayout::columns(1, $items)->setSize(12, 6)->render(),
            $hostile,
        );
    }

    public function testConstructorClampsNegativeColumnsToOne(): void
    {
        $items = [$this->strItem('A'), $this->strItem('B')];

        $this->assertSame(
            GridLayout::columns(1, $items)->render(),
            (new GridLayout($items, -4))->render(),
        );
    }

    public function testConstructorClampsNegativeGapsToZero(): void
    {
        $items = [$this->strItem('A'), $this->strItem('B')];

        // A negative columnGap used to inflate cellWidth at :143/:320.
        $this->assertSame(
            (new GridLayout($items, 2, 0, 0, 0))->setSize(20, 6)->render(),
            (new GridLayout($items, 2, 0, -10, -10))->setSize(20, 6)->render(),
        );
    }

    public function testConstructorClampsNegativeRowsToAuto(): void
    {
        $items = [$this->strItem('A'), $this->strItem('B')];

        // rows:0 means "auto from item count" — a negative must fold there.
        $this->assertSame(
            (new GridLayout($items, 1, 0))->render(),
            (new GridLayout($items, 1, -3))->render(),
        );
    }

    public function testWideCellContentKeepsLaterCellsOnDisplayColumns(): void
    {
        $layout = GridLayout::columns(2, [$this->strItem('日本語'), $this->strItem('ab')])
            ->withColumnGap(1);

        $lines = explode("\n", $layout->render());

        // '日本語' occupies 6 display columns in cell 0, so with a 1-col
        // gap 'ab' starts at column 7 (cell padded to 6 follows). The
        // character-offset splice started it at column 10 because it
        // sliced the composed line by mb_substr characters, not columns.
        $this->assertSame('日本語 ab    ', $lines[0]);
        $this->assertSame(13, Width::string($lines[0]));
    }

    public function testWideCellsAcrossThreeColumnsStayColumnAligned(): void
    {
        $layout = GridLayout::columns(3, [
            $this->strItem('日本'),
            $this->strItem('x'),
            $this->strItem('y'),
        ]);

        $lines = explode("\n", $layout->render());

        // cellWidth = 4 ('日本'); zero gap → starts at columns 0, 4, 8.
        $this->assertSame('日本x   y   ', $lines[0]);
    }
}
