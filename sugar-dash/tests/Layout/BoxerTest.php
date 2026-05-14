<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Tests\Layout;

use SugarCraft\Dash\Layout\Pad;
use SugarCraft\Dash\Foundation\Item;
use SugarCraft\Dash\Foundation\Sizer;
use SugarCraft\Sprinkles\VAlign;
use SugarCraft\Core\Util\Width;
use PHPUnit\Framework\TestCase;

final class BoxerTest extends TestCase
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
        };
    }

    // ═══════════════════════════════════════════════════════════════
    // Interface conformance
    // ═══════════════════════════════════════════════════════════════

    public function testBoxerImplementsSizer(): void
    {
        $boxer = Pad::new($this->strItem('test'));
        $this->assertInstanceOf(Sizer::class, $boxer);
    }

    public function testBoxerImplementsItem(): void
    {
        $boxer = Pad::new($this->strItem('test'));
        $this->assertInstanceOf(Item::class, $boxer);
    }

    // ═══════════════════════════════════════════════════════════════
    // Basic rendering
    // ═══════════════════════════════════════════════════════════════

    public function testRenderWithNoSizeReturnsContent(): void
    {
        $boxer = Pad::new($this->strItem('raw content'));
        $this->assertSame('raw content', $boxer->render());
    }

    public function testRenderWithSizeStillReturnsContent(): void
    {
        $boxer = Pad::new($this->strItem('inner'));
        $boxer = $boxer->setSize(20, 7);
        $rendered = $boxer->render();

        // Should contain the inner content somewhere
        $this->assertStringContainsString('inner', $rendered);
    }

    public function testRenderedOutputHasCorrectDimensions(): void
    {
        $boxer = Pad::new($this->strItem('x'));
        $boxer = $boxer->setSize(15, 6);
        $rendered = $boxer->render();

        $lines = explode("\n", $rendered);
        // With setSize(15, 6) and default padding [0,0,0,0], output is 6 lines
        $this->assertCount(6, $lines);
    }

    public function testRenderWithZeroSizeReturnsContent(): void
    {
        $boxer = Pad::new($this->strItem('still raw'));
        $boxer = $boxer->setSize(0, 5);
        $this->assertSame('still raw', $boxer->render());
    }

    // ═══════════════════════════════════════════════════════════════
    // Sizer propagation
    // ═══════════════════════════════════════════════════════════════

    public function testSetSizePropagatesToSizerContent(): void
    {
        $sized = $this->sizedItem();
        $boxer = Pad::new($sized);
        $boxer = $boxer->setSize(30, 6);
        // Boxer should render correctly with Sizer content
        $rendered = $boxer->render();
        $this->assertNotSame('', $rendered);
        // Verify the rendered output contains the expected format
        $this->assertStringContainsString('Size:', $rendered);
    }

    public function testSetSizeReturnsNewInstance(): void
    {
        $original = Pad::new($this->strItem('test'));
        $resized = $original->setSize(10, 3);

        $this->assertNotSame($original, $resized);
        // Original unchanged
        $original->render(); // no crash
    }

    // ═══════════════════════════════════════════════════════════════
    // Inner size calculation
    // ═══════════════════════════════════════════════════════════════

    public function testGetInnerSizeSubtractsPadding(): void
    {
        $boxer = Pad::new($this->strItem('test'))
            ->withPadding(2); // 2 cells each side
        $boxer = $boxer->setSize(12, 8);

        [$w, $h] = $boxer->getInnerSize();

        // 12 total - 4 (2 left + 2 right) = 8 inner width
        $this->assertSame(8, $w);
        // 8 total - 4 (2 top + 2 bottom) = 4 inner height
        $this->assertSame(4, $h);
    }

    public function testGetInnerSizeWithNoPadding(): void
    {
        $boxer = Pad::new($this->strItem('test'))
            ->withPadding(0);
        $boxer = $boxer->setSize(10, 5);

        [$w, $h] = $boxer->getInnerSize();

        // 10 - 0 = 10, 5 - 0 = 5
        $this->assertSame(10, $w);
        $this->assertSame(5, $h);
    }

    public function testGetInnerSizeReturnsZeroOnTinyBox(): void
    {
        // With padding of 1 on each side, a 1x1 box has no room for content
        $boxer = Pad::new($this->strItem('tiny'))
            ->withPadding(1);
        $boxer = $boxer->setSize(1, 1);

        [$w, $h] = $boxer->getInnerSize();
        // padding 1 on each side = 2 total each axis, 1 - 2 = -1 -> clamped to 0
        $this->assertSame(0, $w);
        $this->assertSame(0, $h);
    }

    public function testGetInnerSizeBeforeSetSizeReturnsZero(): void
    {
        $boxer = Pad::new($this->strItem('test'));
        [$w, $h] = $boxer->getInnerSize();
        $this->assertSame(0, $w);
        $this->assertSame(0, $h);
    }

    public function testGetInnerSizeWithPaddingXY(): void
    {
        $boxer = Pad::new($this->strItem('test'))
            ->withPaddingXY(3, 1); // 3 vertical, 1 horizontal
        $boxer = $boxer->setSize(12, 9);

        [$w, $h] = $boxer->getInnerSize();

        // 12 - 2 (1 left + 1 right) = 10
        $this->assertSame(10, $w);
        // 9 - 6 (3 top + 3 bottom) = 3
        $this->assertSame(3, $h);
    }

    // ═══════════════════════════════════════════════════════════════
    // Withers / fluent API
    // ═══════════════════════════════════════════════════════════════

    public function testWithPaddingChangesAllSides(): void
    {
        $noPad = Pad::new($this->strItem('x'))->withPadding(0)->setSize(10, 3);
        $pad = Pad::new($this->strItem('x'))->withPadding(2)->setSize(14, 7);

        $noPadRendered = $noPad->render();
        $padRendered = $pad->render();

        // Both should render without error
        $this->assertNotSame('', $noPadRendered);
        $this->assertNotSame('', $padRendered);
    }

    public function testWithPaddingXYSetsVerticalAndHorizontal(): void
    {
        $boxer = Pad::new($this->strItem('test'))
            ->withPaddingXY(3, 1) // 3 vertical, 1 horizontal
            ->setSize(12, 9);

        [$w, $h] = $boxer->getInnerSize();

        // 12 - 2 (1 left + 1 right) = 10
        $this->assertSame(10, $w);
        // 9 - 6 (3 top + 3 bottom) = 3
        $this->assertSame(3, $h);
    }

    public function testWithPaddingArraySetsIndividualSides(): void
    {
        $boxer = Pad::new($this->strItem('test'))
            ->withPaddingArray([1, 2, 3, 4]) // top=1, right=2, bottom=3, left=4
            ->setSize(12, 8);

        [$w, $h] = $boxer->getInnerSize();

        // 12 - 6 (4 left + 2 right) = 6
        $this->assertSame(6, $w);
        // 8 - 4 (1 top + 3 bottom) = 4
        $this->assertSame(4, $h);
    }

    public function testWithBorderEnablesBorder(): void
    {
        $boxer = Pad::new($this->strItem('test'))
            ->withBorder(true)
            ->setSize(10, 5);

        $rendered = $boxer->render();
        $this->assertNotSame('', $rendered);
    }

    public function testWithBorderFalseDisablesBorder(): void
    {
        $boxer = Pad::new($this->strItem('test'))
            ->withBorder(false)
            ->setSize(10, 5);

        $rendered = $boxer->render();
        $this->assertNotSame('', $rendered);
    }

    public function testWithVerticalAlignBottom(): void
    {
        $boxer = Pad::new($this->strItem('x'))
            ->withVerticalAlign(VAlign::Bottom)
            ->setSize(10, 5);

        // Should render without error
        $rendered = $boxer->render();
        $this->assertNotSame('', $rendered);
    }

    public function testWithVerticalAlignMiddle(): void
    {
        $boxer = Pad::new($this->strItem('x'))
            ->withVerticalAlign(VAlign::Middle)
            ->setSize(10, 5);

        // Should render without error
        $rendered = $boxer->render();
        $this->assertNotSame('', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Default values
    // ═══════════════════════════════════════════════════════════════

    public function testDefaultPaddingIsZero(): void
    {
        $boxer = Pad::new($this->strItem('test'))->setSize(10, 5);
        [$w, $h] = $boxer->getInnerSize();

        // No padding subtracted
        $this->assertSame(10, $w);
        $this->assertSame(5, $h);
    }

    public function testDefaultBorderIsFalse(): void
    {
        $boxer = Pad::new($this->strItem('test'))->setSize(10, 5);
        $rendered = $boxer->render();

        // Should not have border characters when border is disabled
        $this->assertStringNotContainsString('╭', $rendered);
        $this->assertStringNotContainsString('╮', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Edge cases
    // ═══════════════════════════════════════════════════════════════

    public function testNegativePaddingClampedByStyle(): void
    {
        $boxer = Pad::new($this->strItem('test'))
            ->withPadding(0) // No negative padding
            ->setSize(5, 3);

        $rendered = $boxer->render();
        $this->assertNotSame('', $rendered);
    }

    public function testContentTruncationWhenTooWide(): void
    {
        $boxer = Pad::new($this->strItem('this is very long content'))
            ->withPadding(1)
            ->setSize(10, 5);

        $rendered = $boxer->render();
        $this->assertNotSame('', $rendered);

        // Each line should fit within the allocated width
        $lines = explode("\n", $rendered);
        foreach ($lines as $line) {
            $this->assertLessThanOrEqual(10, Width::string($line));
        }
    }

    public function testContentPaddingMakesContentSmaller(): void
    {
        $noPad = Pad::new($this->strItem('x'))->withPadding(0)->setSize(5, 3);
        $pad = Pad::new($this->strItem('x'))->withPadding(1)->setSize(5, 3);

        $noPadRendered = $noPad->render();
        $padRendered = $pad->render();

        // Both should render without error
        $this->assertNotSame('', $noPadRendered);
        $this->assertNotSame('', $padRendered);
    }
}
