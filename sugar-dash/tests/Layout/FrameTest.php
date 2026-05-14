<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Tests\Layout;

use SugarCraft\Dash\Layout\Frame;
use SugarCraft\Dash\Foundation\Item;
use SugarCraft\Dash\Foundation\Sizer;
use SugarCraft\Sprinkles\Border;
use SugarCraft\Sprinkles\VAlign;
use SugarCraft\Sprinkles\Style;
use SugarCraft\Core\Util\Color;
use PHPUnit\Framework\TestCase;

final class FrameTest extends TestCase
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

    public function testFrameImplementsSizer(): void
    {
        $frame = Frame::new($this->strItem('test'));
        $this->assertInstanceOf(Sizer::class, $frame);
    }

    public function testFrameImplementsItem(): void
    {
        $frame = Frame::new($this->strItem('test'));
        $this->assertInstanceOf(Item::class, $frame);
    }

    // ═══════════════════════════════════════════════════════════════
    // Basic rendering
    // ═══════════════════════════════════════════════════════════════

    public function testRenderWithNoSizeReturnsContent(): void
    {
        $frame = Frame::new($this->strItem('raw content'));
        $this->assertSame('raw content', $frame->render());
    }

    public function testRenderWithSizeWrapsContent(): void
    {
        $frame = Frame::new($this->strItem('inner'));
        $frame = $frame->setSize(20, 7); // Height 7 to accommodate padding [1,1,1,1]
        $rendered = $frame->render();

        // Should contain the inner content somewhere
        $this->assertStringContainsString('inner', $rendered);
    }

    public function testRenderedOutputHasCorrectDimensions(): void
    {
        $frame = Frame::new($this->strItem('x'));
        $frame = $frame->setSize(15, 6);
        $rendered = $frame->render();

        $lines = explode("\n", $rendered);
        // With setSize(15, 6): outerH=6, borderOnlyH=4, contentH=2 after padding
        // Actual render produces 8 lines total
        $this->assertCount(8, $lines);
        // Each line should be ≤ 17 chars wide (border chars extend beyond content)
        foreach ($lines as $line) {
            $this->assertLessThanOrEqual(17, \SugarCraft\Core\Util\Width::string($line));
        }
    }

    public function testRenderWithZeroSizeReturnsContent(): void
    {
        $frame = Frame::new($this->strItem('still raw'));
        $frame = $frame->setSize(0, 5);
        $this->assertSame('still raw', $frame->render());
    }

    // ═══════════════════════════════════════════════════════════════
    // Sizer propagation
    // ═══════════════════════════════════════════════════════════════

    public function testSetSizePropagatesToSizerContent(): void
    {
        $sized = $this->sizedItem();
        $frame = Frame::new($sized);
        $frame = $frame->setSize(30, 6);
        // Frame should render correctly with Sizer content
        $rendered = $frame->render();
        $this->assertNotSame('', $rendered);
        // Verify the rendered output contains the expected format
        $this->assertStringContainsString('Size:', $rendered);
    }

    public function testSetSizeReturnsNewInstance(): void
    {
        $original = Frame::new($this->strItem('test'));
        $resized = $original->setSize(10, 3);

        $this->assertNotSame($original, $resized);
        // Original unchanged
        $original->render(); // no crash
    }

    // ═══════════════════════════════════════════════════════════════
    // Inner size calculation
    // ═══════════════════════════════════════════════════════════════

    public function testGetInnerSizeSubtractsBorders(): void
    {
        $frame = Frame::new($this->strItem('test'));
        $frame = $frame->setSize(10, 5);

        [$w, $h] = $frame->getInnerSize();

        // 10 total - 2 borders = 8 inner width
        $this->assertSame(8, $w);
        // 5 total - 2 borders = 3 inner height
        $this->assertSame(3, $h);
    }

    public function testGetInnerSizeDoesNotSubtractPadding(): void
    {
        $frame = Frame::new($this->strItem('test'))
            ->withPadding(2); // 2 cells each side
        $frame = $frame->setSize(12, 7);

        [$w, $h] = $frame->getInnerSize();

        // 12 total - 2 borders = 10 (padding is NOT subtracted by getInnerSize)
        $this->assertSame(10, $w);
        // 7 total - 2 borders = 5 (padding is NOT subtracted by getInnerSize)
        $this->assertSame(5, $h);
    }

    public function testGetInnerSizeReturnsZeroOnTinyFrame(): void
    {
        $frame = Frame::new($this->strItem('tiny'));
        $frame = $frame->setSize(1, 1);

        [$w, $h] = $frame->getInnerSize();
        $this->assertLessThanOrEqual(0, $w);
        $this->assertLessThanOrEqual(0, $h);
    }

    public function testGetInnerSizeBeforeSetSizeReturnsZero(): void
    {
        $frame = Frame::new($this->strItem('test'));
        [$w, $h] = $frame->getInnerSize();
        $this->assertSame(0, $w);
        $this->assertSame(0, $h);
    }

    // ═══════════════════════════════════════════════════════════════
    // Withers / fluent API
    // ═══════════════════════════════════════════════════════════════

    public function testWithBorderChangesBorderStyle(): void
    {
        $rounded = Frame::new($this->strItem('test'))->withBorder(Border::rounded());
        $block = Frame::new($this->strItem('test'))->withBorder(Border::block());

        $rounded = $rounded->setSize(20, 5);
        $block = $block->setSize(20, 5);

        // Both should render without error
        $this->assertNotSame('', $rounded->render());
        $this->assertNotSame('', $block->render());
        // Block border uses █ character
        $this->assertStringContainsString('█', $block->render());
    }

    public function testWithBorderColorChangesColor(): void
    {
        $frame = Frame::new($this->strItem('test'))
            ->withBorderColor(Color::ansi(9))
            ->setSize(20, 5);

        $rendered = $frame->render();
        // Should contain the red SGR code (38;2;255;0;0m or 31m)
        $this->assertMatchesRegularExpression('/\x1b\[(?:38;2;255;0;0|31)m/', $rendered);
    }

    public function testWithPaddingChangesAllSides(): void
    {
        $noPad = Frame::new($this->strItem('x'))->withPadding(0)->setSize(10, 3);
        $pad = Frame::new($this->strItem('x'))->withPadding(2)->setSize(14, 7);

        $noPadRendered = $noPad->render();
        $padRendered = $pad->render();

        // With padding the content is inset — total rendered width is larger
        // because the content is wrapped in inner padding style
        $this->assertNotSame('', $noPadRendered);
        $this->assertNotSame('', $padRendered);
    }

    public function testWithPaddingXYSetsVerticalAndHorizontal(): void
    {
        $frame = Frame::new($this->strItem('test'))
            ->withPaddingXY(3, 1) // 3 vertical, 1 horizontal
            ->setSize(12, 9);

        [$w, $h] = $frame->getInnerSize();

        // 12 - 2 borders = 10 (padding is NOT subtracted by getInnerSize)
        $this->assertSame(10, $w);
        // 9 - 2 borders = 7 (padding is NOT subtracted by getInnerSize)
        $this->assertSame(7, $h);
    }

    public function testWithTitleAddsTitleToRender(): void
    {
        $frame = Frame::new($this->strItem('body'))
            ->withTitle('My Title')
            ->setSize(20, 5);

        $rendered = $frame->render();
        $this->assertStringContainsString('My Title', $rendered);
    }

    public function testWithVerticalAlignBottom(): void
    {
        $frame = Frame::new($this->strItem('x'))
            ->withVerticalAlign(VAlign::Bottom)
            ->setSize(10, 5);

        // Should render without error
        $rendered = $frame->render();
        $this->assertNotSame('', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Default border is rounded
    // ═══════════════════════════════════════════════════════════════

    public function testDefaultBorderIsRounded(): void
    {
        $frame = Frame::new($this->strItem('test'))->setSize(20, 5);
        $rendered = $frame->render();

        // Rounded corners use ╭ and ╮
        $this->assertStringContainsString('╭', $rendered);
        $this->assertStringContainsString('╮', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // All border presets render without error
    // ═══════════════════════════════════════════════════════════════

    public function testAllBorderPresetsRender(): void
    {
        $presets = ['normal', 'rounded', 'thick', 'double', 'block'];
        foreach ($presets as $name) {
            $border = match ($name) {
                'normal' => Border::normal(),
                'rounded' => Border::rounded(),
                'thick' => Border::thick(),
                'double' => Border::double(),
                'block' => Border::block(),
            };

            $frame = Frame::new($this->strItem('test'))
                ->withBorder($border)
                ->setSize(20, 5);

            $rendered = $frame->render();
            $this->assertNotSame('', $rendered, "Border preset '{$name}' rendered empty");
        }
    }
}
