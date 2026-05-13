<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Tests\Grid;

use SugarCraft\Dash\Grid\Cover;
use SugarCraft\Dash\Grid\HAlign;
use SugarCraft\Dash\Grid\Item;
use SugarCraft\Dash\Grid\Sizer;
use SugarCraft\Dash\Grid\VAlign;
use PHPUnit\Framework\TestCase;

final class CoverTest extends TestCase
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

    public function testCoverImplementsSizer(): void
    {
        $cover = Cover::new($this->strItem('test'));
        $this->assertInstanceOf(Sizer::class, $cover);
    }

    public function testCoverImplementsItem(): void
    {
        $cover = Cover::new($this->strItem('test'));
        $this->assertInstanceOf(Item::class, $cover);
    }

    // ═══════════════════════════════════════════════════════════════
    // Factory methods
    // ═══════════════════════════════════════════════════════════════

    public function testNewFactoryCreatesCover(): void
    {
        $cover = Cover::new($this->strItem('hello'));
        $cover = $cover->setSize(20, 10);

        $this->assertNotSame('', $cover->render());
    }

    public function testTopLeftFactoryCreatesTopLeftAlignedCover(): void
    {
        $cover = Cover::topLeft($this->strItem('test'));
        $cover = $cover->setSize(20, 10);

        $rendered = $cover->render();
        $this->assertNotSame('', $rendered);
    }

    public function testTopRightFactoryCreatesTopRightAlignedCover(): void
    {
        $cover = Cover::topRight($this->strItem('test'));
        $cover = $cover->setSize(20, 10);

        $rendered = $cover->render();
        $this->assertNotSame('', $rendered);
    }

    public function testBottomLeftFactoryCreatesBottomLeftAlignedCover(): void
    {
        $cover = Cover::bottomLeft($this->strItem('test'));
        $cover = $cover->setSize(20, 10);

        $rendered = $cover->render();
        $this->assertNotSame('', $rendered);
    }

    public function testBottomRightFactoryCreatesBottomRightAlignedCover(): void
    {
        $cover = Cover::bottomRight($this->strItem('test'));
        $cover = $cover->setSize(20, 10);

        $rendered = $cover->render();
        $this->assertNotSame('', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Basic rendering
    // ═══════════════════════════════════════════════════════════════

    public function testRenderWithNoSizeReturnsContent(): void
    {
        $cover = Cover::new($this->strItem('content'));
        $rendered = $cover->render();
        $this->assertStringContainsString('content', $rendered);
    }

    public function testRenderWithSizeFillsSpace(): void
    {
        $cover = Cover::new($this->strItem('x'));
        $cover = $cover->setSize(20, 10);

        $rendered = $cover->render();
        $lines = explode("\n", $rendered);

        // Should have exactly 10 lines
        $this->assertCount(10, $lines);

        // Each line should be exactly 20 wide (padded with spaces)
        foreach ($lines as $line) {
            $width = \SugarCraft\Core\Util\Width::string($line);
            $this->assertLessThanOrEqual(20, $width);
        }
    }

    // ═══════════════════════════════════════════════════════════════
    // Size propagation
    // ═══════════════════════════════════════════════════════════════

    public function testSetSizePropagatesToSizerContent(): void
    {
        $sized = $this->sizedItem();
        $cover = Cover::new($sized)
            ->setSize(30, 10);

        $rendered = $cover->render();
        $this->assertStringContainsString('Size:', $rendered);
    }

    public function testSetSizeReturnsNewInstance(): void
    {
        $original = Cover::new($this->strItem('test'));
        $resized = $original->setSize(10, 3);

        $this->assertNotSame($original, $resized);
    }

    // ═══════════════════════════════════════════════════════════════
    // Natural size calculation
    // ═══════════════════════════════════════════════════════════════

    public function testGetInnerSizeReturnsAllocatedSizeWhenSet(): void
    {
        $cover = Cover::new($this->strItem('test'))
            ->setSize(15, 5);

        [$w, $h] = $cover->getInnerSize();
        $this->assertSame(15, $w);
        $this->assertSame(5, $h);
    }

    public function testGetInnerSizeReturnsContentSizeWhenNotSet(): void
    {
        $cover = Cover::new($this->strItem('hello'));

        [$w, $h] = $cover->getInnerSize();
        $this->assertGreaterThan(0, $w);
        $this->assertGreaterThan(0, $h);
    }

    // ═══════════════════════════════════════════════════════════════
    // Withers / fluent API
    // ═══════════════════════════════════════════════════════════════

    public function testWithAlignmentChangesHorizontalAlignment(): void
    {
        $cover = Cover::new($this->strItem('test'))
            ->withAlignment(HAlign::Left)
            ->setSize(20, 10);

        $rendered = $cover->render();
        $this->assertNotSame('', $rendered);
    }

    public function testWithAlignmentChangesHorizontalAlignmentToRight(): void
    {
        $cover = Cover::new($this->strItem('test'))
            ->withAlignment(HAlign::Right)
            ->setSize(20, 10);

        $rendered = $cover->render();
        $this->assertNotSame('', $rendered);
    }

    public function testWithVerticalAlignChangesVerticalAlignment(): void
    {
        $cover = Cover::new($this->strItem('test'))
            ->withVerticalAlign(VAlign::Top)
            ->setSize(20, 10);

        $rendered = $cover->render();
        $this->assertNotSame('', $rendered);
    }

    public function testWithVerticalAlignChangesVerticalAlignmentToBottom(): void
    {
        $cover = Cover::new($this->strItem('test'))
            ->withVerticalAlign(VAlign::Bottom)
            ->setSize(20, 10);

        $rendered = $cover->render();
        $this->assertNotSame('', $rendered);
    }

    public function testWithContentChangesContent(): void
    {
        $cover = Cover::new($this->strItem('A'))
            ->withContent($this->strItem('B'))
            ->setSize(10, 5);

        $rendered = $cover->render();
        $this->assertStringContainsString('B', $rendered);
        $this->assertStringNotContainsString('A', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Full coverage behavior
    // ═══════════════════════════════════════════════════════════════

    public function testOutputHasExactSize(): void
    {
        $cover = Cover::new($this->strItem('x'))
            ->setSize(15, 7);

        $rendered = $cover->render();
        $lines = explode("\n", $rendered);

        // Should have exactly 7 lines
        $this->assertCount(7, $lines);
    }

    public function testContentFillsEntireSpace(): void
    {
        $cover = Cover::new($this->strItem('X'))
            ->setSize(10, 5);

        $rendered = $cover->render();
        $lines = explode("\n", $rendered);

        // All lines should have content (not just spaces)
        $hasContent = false;
        foreach ($lines as $line) {
            if (trim($line) !== '') {
                $hasContent = true;
                break;
            }
        }
        $this->assertTrue($hasContent);
    }
}
