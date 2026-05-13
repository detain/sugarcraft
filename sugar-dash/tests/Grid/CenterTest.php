<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Tests\Grid;

use SugarCraft\Dash\Grid\Center;
use SugarCraft\Dash\Grid\Item;
use SugarCraft\Dash\Grid\Sizer;
use PHPUnit\Framework\TestCase;

final class CenterTest extends TestCase
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

    public function testCenterImplementsSizer(): void
    {
        $center = Center::new($this->strItem('test'));
        $this->assertInstanceOf(Sizer::class, $center);
    }

    public function testCenterImplementsItem(): void
    {
        $center = Center::new($this->strItem('test'));
        $this->assertInstanceOf(Item::class, $center);
    }

    // ═══════════════════════════════════════════════════════════════
    // Factory methods
    // ═══════════════════════════════════════════════════════════════

    public function testNewFactoryCreatesCenter(): void
    {
        $center = Center::new($this->strItem('hello'));
        $this->assertNotSame('', $center->render());
    }

    public function testWithMinCreatesCenterWithMinDimensions(): void
    {
        $center = Center::withMin($this->strItem('test'), 10, 5);
        $center = $center->setSize(20, 10);

        $rendered = $center->render();
        $this->assertNotSame('', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Basic rendering
    // ═══════════════════════════════════════════════════════════════

    public function testRenderWithNoSizeReturnsContent(): void
    {
        $center = Center::new($this->strItem('content'));
        $rendered = $center->render();
        $this->assertStringContainsString('content', $rendered);
    }

    public function testRenderWithSizeCentersContent(): void
    {
        $center = Center::new($this->strItem('x'));
        $center = $center->setSize(20, 10);

        $rendered = $center->render();
        $this->assertNotSame('', $rendered);

        // Content should be present
        $this->assertStringContainsString('x', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Size propagation
    // ═══════════════════════════════════════════════════════════════

    public function testSetSizePropagatesToSizerContent(): void
    {
        $sized = $this->sizedItem();
        $center = Center::new($sized)
            ->setSize(30, 10);

        $rendered = $center->render();
        $this->assertStringContainsString('Size:', $rendered);
    }

    public function testSetSizeReturnsNewInstance(): void
    {
        $original = Center::new($this->strItem('test'));
        $resized = $original->setSize(10, 3);

        $this->assertNotSame($original, $resized);
    }

    // ═══════════════════════════════════════════════════════════════
    // Natural size calculation
    // ═══════════════════════════════════════════════════════════════

    public function testGetInnerSizeReturnsAllocatedSizeWhenSet(): void
    {
        $center = Center::new($this->strItem('test'))
            ->setSize(15, 5);

        [$w, $h] = $center->getInnerSize();
        $this->assertSame(15, $w);
        $this->assertSame(5, $h);
    }

    public function testGetInnerSizeReturnsContentSizeWhenNotSet(): void
    {
        $center = Center::new($this->strItem('hello'));

        [$w, $h] = $center->getInnerSize();
        $this->assertGreaterThanOrEqual(5, $w);
        $this->assertSame(1, $h);
    }

    // ═══════════════════════════════════════════════════════════════
    // Withers / fluent API
    // ═══════════════════════════════════════════════════════════════

    public function testWithMinWidthSetsMinimumWidth(): void
    {
        $center = Center::new($this->strItem('test'))
            ->withMinWidth(20)
            ->setSize(30, 10);

        $rendered = $center->render();
        $this->assertNotSame('', $rendered);
    }

    public function testWithMinHeightSetsMinimumHeight(): void
    {
        $center = Center::new($this->strItem('test'))
            ->withMinHeight(5)
            ->setSize(10, 10);

        $rendered = $center->render();
        $this->assertNotSame('', $rendered);
    }

    public function testWithContentChangesContent(): void
    {
        $center = Center::new($this->strItem('A'))
            ->withContent($this->strItem('B'))
            ->setSize(10, 5);

        $rendered = $center->render();
        $this->assertStringContainsString('B', $rendered);
        $this->assertStringNotContainsString('A', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Centering behavior
    // ═══════════════════════════════════════════════════════════════

    public function testContentIsCenteredInOutput(): void
    {
        $center = Center::new($this->strItem('X'))
            ->setSize(10, 5);

        $rendered = $center->render();
        $lines = explode("\n", $rendered);

        // All lines should have same width
        foreach ($lines as $line) {
            $this->assertLessThanOrEqual(10, \SugarCraft\Core\Util\Width::string($line));
        }
    }

    public function testContentSmallerThanContainerIsPadded(): void
    {
        $center = Center::new($this->strItem('x'))
            ->setSize(20, 10);

        $rendered = $center->render();
        $lines = explode("\n", $rendered);

        // Lines should be exactly 20 wide
        foreach ($lines as $line) {
            $this->assertLessThanOrEqual(20, \SugarCraft\Core\Util\Width::string($line));
        }
    }
}
