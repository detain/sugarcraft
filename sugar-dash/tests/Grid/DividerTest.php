<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Tests\Grid;

use SugarCraft\Core\Util\Color;
use SugarCraft\Dash\Grid\Divider;
use SugarCraft\Dash\Grid\HAlign;
use SugarCraft\Dash\Grid\Item;
use SugarCraft\Dash\Grid\Sizer;
use PHPUnit\Framework\TestCase;

final class DividerTest extends TestCase
{
    // ═══════════════════════════════════════════════════════════════
    // Interface conformance
    // ═══════════════════════════════════════════════════════════════

    public function testDividerImplementsSizer(): void
    {
        $divider = Divider::new();
        $this->assertInstanceOf(Sizer::class, $divider);
    }

    public function testDividerImplementsItem(): void
    {
        $divider = Divider::new();
        $this->assertInstanceOf(Item::class, $divider);
    }

    // ═══════════════════════════════════════════════════════════════
    // Basic rendering
    // ═══════════════════════════════════════════════════════════════

    public function testRenderDefaultDivider(): void
    {
        $divider = Divider::new()->setSize(40, 1);
        $rendered = $divider->render();

        $this->assertSame(40, mb_strlen($rendered));
        $this->assertStringContainsString('─', $rendered);
    }

    public function testRenderEmptyWidth(): void
    {
        $divider = Divider::new()->setSize(0, 1);
        $rendered = $divider->render();

        $this->assertSame('', $rendered);
    }

    public function testRenderNegativeWidth(): void
    {
        $divider = Divider::new()->setSize(-10, 1);
        $rendered = $divider->render();

        $this->assertSame('', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Character variants
    // ═══════════════════════════════════════════════════════════════

    public function testBoxChar(): void
    {
        $divider = Divider::new()->withChar(Divider::CHAR_BOX)->setSize(20, 1);
        $rendered = $divider->render();

        $this->assertSame(20, mb_strlen($rendered));
        $this->assertStringContainsString('─', $rendered);
    }

    public function testHeavyChar(): void
    {
        $divider = Divider::new()->withChar(Divider::CHAR_HEAVY)->setSize(20, 1);
        $rendered = $divider->render();

        $this->assertSame(20, mb_strlen($rendered));
        $this->assertStringContainsString('━', $rendered);
    }

    public function testDashedChar(): void
    {
        $divider = Divider::new()->withChar(Divider::CHAR_DASHED)->setSize(20, 1);
        $rendered = $divider->render();

        $this->assertSame(20, mb_strlen($rendered));
        $this->assertStringContainsString('-', $rendered);
    }

    public function testDottedChar(): void
    {
        $divider = Divider::new()->withChar(Divider::CHAR_DOTTED)->setSize(20, 1);
        $rendered = $divider->render();

        $this->assertSame(20, mb_strlen($rendered));
        $this->assertStringContainsString('·', $rendered);
    }

    public function testDoubleChar(): void
    {
        $divider = Divider::new()->withChar(Divider::CHAR_DOUBLE)->setSize(20, 1);
        $rendered = $divider->render();

        $this->assertSame(20, mb_strlen($rendered));
        $this->assertStringContainsString('═', $rendered);
    }

    public function testCustomChar(): void
    {
        $divider = Divider::new()->withChar('=')->setSize(20, 1);
        $rendered = $divider->render();

        $this->assertSame(20, mb_strlen($rendered));
        $this->assertStringNotContainsString('─', $rendered);
        $this->assertStringContainsString('=', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Labels
    // ═══════════════════════════════════════════════════════════════

    public function testRenderWithLabelCentered(): void
    {
        $divider = Divider::new('Title')->setSize(40, 1);
        $rendered = $divider->render();

        // Should contain the label
        $this->assertStringContainsString('Title', $rendered);
        // Should have dashes on both sides
        $this->assertStringContainsString('─', $rendered);
    }

    public function testRenderWithLabelLeft(): void
    {
        $divider = Divider::new('Left')->withLabelAlign(HAlign::Left)->setSize(40, 1);
        $rendered = $divider->render();

        // Label should be at the start
        $this->assertStringStartsWith('Left', $rendered);
    }

    public function testRenderWithLabelRight(): void
    {
        $divider = Divider::new('Right')->withLabelAlign(HAlign::Right)->setSize(40, 1);
        $rendered = $divider->render();

        // Label should be at the end
        $this->assertStringEndsWith('Right', $rendered);
    }

    public function testRenderWithEmptyLabel(): void
    {
        $divider = Divider::new('')->setSize(40, 1);
        $rendered = $divider->render();

        // Should render just the line characters
        $this->assertSame(40, mb_strlen($rendered));
        $this->assertStringContainsString('─', $rendered);
    }

    public function testRenderWithNullLabel(): void
    {
        $divider = Divider::new(null)->setSize(40, 1);
        $rendered = $divider->render();

        // Should render just the line characters
        $this->assertSame(40, mb_strlen($rendered));
        $this->assertStringContainsString('─', $rendered);
    }

    public function testLabelWiderThanWidth(): void
    {
        $divider = Divider::new('This is a very long label')->setSize(10, 1);
        $rendered = $divider->render();

        // Should render as plain line when label is wider
        $this->assertSame(10, mb_strlen($rendered));
    }

    // ═══════════════════════════════════════════════════════════════
    // Size handling
    // ═══════════════════════════════════════════════════════════════

    public function testSetSizeReturnsNewInstance(): void
    {
        $original = Divider::new();
        $resized = $original->setSize(40, 1);

        $this->assertNotSame($original, $resized);
    }

    public function testDefaultWidth(): void
    {
        $divider = Divider::new();
        [$w, $h] = $divider->getInnerSize();

        $this->assertSame(80, $w);
        $this->assertSame(1, $h);
    }

    public function testGetInnerSizeWithSetSize(): void
    {
        $divider = Divider::new()->setSize(50, 1);
        [$w, $h] = $divider->getInnerSize();

        $this->assertSame(50, $w);
        $this->assertSame(1, $h);
    }

    // ═══════════════════════════════════════════════════════════════
    // Wither chaining
    // ═══════════════════════════════════════════════════════════════

    public function testChainedWithers(): void
    {
        $divider = Divider::new()
            ->withChar('=')
            ->withLabel('Section')
            ->withLabelAlign(HAlign::Left)
            ->setSize(50, 1);

        $rendered = $divider->render();

        $this->assertStringStartsWith('Section', $rendered);
        $this->assertStringContainsString('=', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Style constants
    // ═══════════════════════════════════════════════════════════════

    public function testStyleConstants(): void
    {
        $this->assertSame('─', Divider::CHAR_BOX);
        $this->assertSame('━', Divider::CHAR_HEAVY);
        $this->assertSame('-', Divider::CHAR_DASHED);
        $this->assertSame('·', Divider::CHAR_DOTTED);
        $this->assertSame('═', Divider::CHAR_DOUBLE);
    }

    // ═══════════════════════════════════════════════════════════════
    // Edge cases
    // ═══════════════════════════════════════════════════════════════

    public function testVerySmallWidth(): void
    {
        $divider = Divider::new()->setSize(1, 1);
        $rendered = $divider->render();

        $this->assertSame(1, mb_strlen($rendered));
    }

    public function testUnicodeLabel(): void
    {
        $divider = Divider::new('標題')->setSize(40, 1);
        $rendered = $divider->render();

        $this->assertStringContainsString('標題', $rendered);
    }

    public function testLabelWithSpecialChars(): void
    {
        $divider = Divider::new('Section 1: $pecial')->setSize(50, 1);
        $rendered = $divider->render();

        $this->assertStringContainsString('Section 1: $pecial', $rendered);
    }

    public function testWithColor(): void
    {
        $divider = Divider::new()->withColor(Color::hex('#FF0000'));
        $this->assertInstanceOf(Divider::class, $divider);
    }
}