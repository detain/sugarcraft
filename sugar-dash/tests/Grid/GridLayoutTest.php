<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Tests\Grid;

use SugarCraft\Dash\Grid\GridItem;
use SugarCraft\Dash\Grid\GridLayout;
use SugarCraft\Dash\Grid\Item;
use SugarCraft\Dash\Grid\Sizer;
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
        return new class implements Item, Sizer {
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
}
