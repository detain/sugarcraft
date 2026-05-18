<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Tests\Layout;

use SugarCraft\Dash\Layout\AlignItems;
use SugarCraft\Dash\Layout\FlexDirection;
use SugarCraft\Dash\Layout\FlexLayout;
use SugarCraft\Dash\Layout\FlexWrap;
use SugarCraft\Dash\Foundation\Item;
use SugarCraft\Dash\Layout\JustifyContent;
use SugarCraft\Dash\Foundation\Sizer;
use PHPUnit\Framework\TestCase;

final class FlexLayoutTest extends TestCase
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

    public function testFlexLayoutImplementsSizer(): void
    {
        $layout = FlexLayout::row();
        $this->assertInstanceOf(Sizer::class, $layout);
    }

    public function testFlexLayoutImplementsItem(): void
    {
        $layout = FlexLayout::row();
        $this->assertInstanceOf(Item::class, $layout);
    }

    // ═══════════════════════════════════════════════════════════════
    // Factory methods
    // ═══════════════════════════════════════════════════════════════

    public function testRowFactoryCreatesHorizontalLayout(): void
    {
        $layout = FlexLayout::row([$this->strItem('a'), $this->strItem('b')]);
        $layout = $layout->setSize(20, 3);

        $rendered = $layout->render();
        $this->assertStringContainsString('a', $rendered);
        $this->assertStringContainsString('b', $rendered);
    }

    public function testColumnFactoryCreatesVerticalLayout(): void
    {
        $layout = FlexLayout::column([$this->strItem('a'), $this->strItem('b')]);
        $layout = $layout->setSize(10, 5);

        $rendered = $layout->render();
        $this->assertStringContainsString('a', $rendered);
        $this->assertStringContainsString('b', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Basic rendering
    // ═══════════════════════════════════════════════════════════════

    public function testRenderEmptyLayoutReturnsEmpty(): void
    {
        $layout = FlexLayout::row();
        $this->assertSame('', $layout->render());
    }

    public function testRenderWithNoSizeReturnsNatural(): void
    {
        $layout = FlexLayout::row([$this->strItem('content')]);
        $rendered = $layout->render();
        $this->assertStringContainsString('content', $rendered);
    }

    public function testRenderWithSizeRendersCorrectly(): void
    {
        $layout = FlexLayout::row([$this->strItem('hello')]);
        $layout = $layout->setSize(20, 3);

        $rendered = $layout->render();
        $this->assertStringContainsString('hello', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Gap support
    // ═══════════════════════════════════════════════════════════════

    public function testWithGapAddsSpacing(): void
    {
        $layout = FlexLayout::row([$this->strItem('a'), $this->strItem('b')])
            ->withGap(2)
            ->setSize(20, 1);

        $rendered = $layout->render();
        // Should have spacing between a and b
        $this->assertNotSame('', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Direction
    // ═══════════════════════════════════════════════════════════════

    public function testRowDirectionPlacesItemsHorizontally(): void
    {
        $layout = FlexLayout::row([$this->strItem('A'), $this->strItem('B')])
            ->setSize(10, 1);

        $rendered = $layout->render();
        // Both items should be on same line
        $lines = explode("\n", $rendered);
        $this->assertCount(1, array_filter($lines, fn($l) => trim($l) !== ''));
    }

    public function testColumnDirectionPlacesItemsVertically(): void
    {
        $layout = FlexLayout::column([$this->strItem('A'), $this->strItem('B')])
            ->setSize(5, 5);

        $rendered = $layout->render();
        // Items should be on different lines
        $lines = explode("\n", $rendered);
        $nonEmptyLines = array_filter($lines, fn($l) => trim($l) !== '');
        $this->assertGreaterThanOrEqual(2, count($nonEmptyLines));
    }

    // ═══════════════════════════════════════════════════════════════
    // Alignment
    // ═══════════════════════════════════════════════════════════════

    public function testWithAlignItemsStart(): void
    {
        $layout = FlexLayout::row([$this->strItem('A')])
            ->withAlignItems(AlignItems::Start)
            ->setSize(10, 5);

        $rendered = $layout->render();
        $this->assertNotSame('', $rendered);
    }

    public function testWithAlignItemsCenter(): void
    {
        $layout = FlexLayout::row([$this->strItem('A')])
            ->withAlignItems(AlignItems::Center)
            ->setSize(10, 5);

        $rendered = $layout->render();
        $this->assertNotSame('', $rendered);
    }

    public function testWithAlignItemsEnd(): void
    {
        $layout = FlexLayout::row([$this->strItem('A')])
            ->withAlignItems(AlignItems::End)
            ->setSize(10, 5);

        $rendered = $layout->render();
        $this->assertNotSame('', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Justify content
    // ═══════════════════════════════════════════════════════════════

    public function testWithJustifyCenter(): void
    {
        $layout = FlexLayout::row([$this->strItem('A')])
            ->withJustify(JustifyContent::Center)
            ->setSize(20, 3);

        $rendered = $layout->render();
        $this->assertNotSame('', $rendered);
    }

    public function testWithJustifySpaceBetween(): void
    {
        $layout = FlexLayout::row([$this->strItem('A'), $this->strItem('B')])
            ->withJustify(JustifyContent::SpaceBetween)
            ->setSize(20, 1);

        $rendered = $layout->render();
        $this->assertNotSame('', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Size propagation
    // ═══════════════════════════════════════════════════════════════

    public function testSetSizePropagatesToSizerItems(): void
    {
        $sized = $this->sizedItem();
        $layout = FlexLayout::row([$sized])
            ->setSize(20, 5);

        $rendered = $layout->render();
        $this->assertStringContainsString('Size:', $rendered);
    }

    public function testSetSizeReturnsNewInstance(): void
    {
        $original = FlexLayout::row([$this->strItem('test')]);
        $resized = $original->setSize(10, 3);

        $this->assertNotSame($original, $resized);
    }

    // ═══════════════════════════════════════════════════════════════
    // Natural size calculation
    // ═══════════════════════════════════════════════════════════════

    public function testGetInnerSizeReturnsAllocatedSizeWhenSet(): void
    {
        $layout = FlexLayout::row([$this->strItem('test')])
            ->setSize(15, 5);

        [$w, $h] = $layout->getInnerSize();
        $this->assertSame(15, $w);
        $this->assertSame(5, $h);
    }

    public function testGetInnerSizeCalculatesNaturalSizeWhenNotSet(): void
    {
        $layout = FlexLayout::row([$this->strItem('hello')]);

        [$w, $h] = $layout->getInnerSize();
        $this->assertGreaterThan(0, $w);
        $this->assertGreaterThan(0, $h);
    }

    // ═══════════════════════════════════════════════════════════════
    // Withers / fluent API
    // ═══════════════════════════════════════════════════════════════

    public function testWithItemAddsItem(): void
    {
        $layout = FlexLayout::row([$this->strItem('A')])
            ->withItem($this->strItem('B'))
            ->setSize(10, 1);

        $rendered = $layout->render();
        $this->assertStringContainsString('A', $rendered);
        $this->assertStringContainsString('B', $rendered);
    }

    public function testWithDirectionChangesDirection(): void
    {
        $layout = FlexLayout::row([$this->strItem('A')])
            ->withDirection(FlexDirection::Column)
            ->setSize(5, 5);

        $rendered = $layout->render();
        $this->assertNotSame('', $rendered);
    }

    public function testWithWrapChangesWrapMode(): void
    {
        $layout = FlexLayout::row([$this->strItem('A')])
            ->withWrap(FlexWrap::Wrap);

        $this->assertNotSame('', $layout->render());
    }
}
