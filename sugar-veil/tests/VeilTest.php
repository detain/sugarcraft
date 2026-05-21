<?php

declare(strict_types=1);

namespace SugarCraft\Veil\Tests;

use SugarCraft\Core\{MouseAction, MouseButton, Msg\MouseMsg};
use SugarCraft\Sprinkles\Border;
use SugarCraft\Veil\{Position, Veil};
use SugarCraft\Zone\Manager;
use PHPUnit\Framework\TestCase;

final class VeilTest extends TestCase
{
    private Veil $veil;

    protected function setUp(): void
    {
        $this->veil = Veil::new();
    }

    public function testNew(): void
    {
        $v = Veil::new();
        $this->assertInstanceOf(Veil::class, $v);
    }

    public function testSplitLines(): void
    {
        $lines = $this->veil->splitLines("a\nb\nc");
        $this->assertCount(3, $lines);
        $this->assertSame('a', $lines[0]);
        $this->assertSame('b', $lines[1]);
        $this->assertSame('c', $lines[2]);
    }

    public function testSplitLinesIgnoresTrailingNewline(): void
    {
        $lines = $this->veil->splitLines("a\nb\n");
        $this->assertCount(2, $lines);
    }

    public function testMaxLineWidth(): void
    {
        $lines = ['short', 'medium text', 'tiny'];
        $this->assertSame(11, $this->veil->maxLineWidth($lines));
    }

    public function testMaxLineWidthStripsAnsi(): void
    {
        $lines = ["\x1b[31mred\x1b[0m"];
        $this->assertSame(3, $this->veil->maxLineWidth($lines));
    }

    public function testLineWidth(): void
    {
        $this->assertSame(5, $this->veil->lineWidth('hello'));
    }

    public function testLineWidthWithAnsi(): void
    {
        $this->assertSame(8, $this->veil->lineWidth("\x1b[1m\x1b[38;5;196mbold red\x1b[0m"));
    }

    public function testCompositeCentered(): void
    {
        $bg = "..........\n..........\n..........";
        $fg = "XXX";

        $result = $this->veil->composite($fg, $bg, Position::CENTER, Position::CENTER);

        // FG should appear in the center line
        $this->assertStringContainsString('XXX', $result);
        $this->assertStringContainsString('.', $result); // background preserved
    }

    public function testCompositeTopLeft(): void
    {
        $bg = "..........\n..........";
        $fg = "A";

        $result = $this->veil->composite($fg, $bg, Position::TOP, Position::LEFT);
        $lines = $this->veil->splitLines($result);

        $this->assertStringStartsWith('A', $lines[0]);
    }

    public function testCompositeBottomRight(): void
    {
        $bg = "..........\n..........";
        $fg = "B";

        $result = $this->veil->composite($fg, $bg, Position::BOTTOM, Position::RIGHT);
        $lines = $this->veil->splitLines($result);

        // B should be in last column of last line
        $lastLine = \end($lines);
        $this->assertStringEndsWith('B', \trim($lastLine));
    }

    public function testCompositeWithOffset(): void
    {
        $bg = "..........\n..........";
        $fg = "X";

        $result = $this->veil->composite($fg, $bg, Position::TOP, Position::LEFT, xOffset: 3, yOffset: 1);
        $lines = $this->veil->splitLines($result);

        $this->assertStringStartsWith('...X', $lines[1]);
    }

    public function testCompositeClampStaysInBounds(): void
    {
        // Very large offset should be clamped to visible area
        $bg = "..........\n..........";
        $fg = "T";

        $result = $this->veil->composite($fg, $bg, Position::TOP, Position::LEFT, xOffset: 9999, yOffset: 9999);
        $lines = $this->veil->splitLines($result);

        // Should not crash; should place T somewhere visible
        $this->assertNotEmpty($lines);
    }

    public function testCompositePreservesBackgroundUnaffectedArea(): void
    {
        $bg = "aaaaaaaaaa\naaaaaaaaaa\naaaaaaaaaa";
        $fg = "X";

        $result = $this->veil->composite($fg, $bg, Position::TOP, Position::LEFT);

        // Top-left char replaced
        $this->assertStringStartsWith('X', $result);
        // Rest of background preserved
        $this->assertStringContainsString('a', $result);
    }

    public function testCompositeMultiline(): void
    {
        $bg  = "..........\n..........\n..........\n..........";
        $fg  = "AAA\nBBB";

        $result = $this->veil->composite($fg, $bg, Position::TOP, Position::LEFT);
        $lines  = $this->veil->splitLines($result);

        $this->assertStringStartsWith('AAA', $lines[0]);
        $this->assertStringStartsWith('BBB', $lines[1]);
    }

    public function testCompositeReplacesOnlyForegroundCells(): void
    {
        $bg = "abcdefghij";
        $fg = "X";

        $result = $this->veil->composite($fg, $bg, Position::TOP, Position::LEFT);

        // X replaces 'a', rest preserved
        $lines = $this->veil->splitLines($result);
        $this->assertSame('Xbcdefghij', $lines[0]);
    }

    public function testEmptyBackground(): void
    {
        $result = $this->veil->composite('X', '', Position::CENTER, Position::CENTER);
        $this->assertSame('', $result);
    }

    public function testEmptyForeground(): void
    {
        $bg = "..........";
        $result = $this->veil->composite('', $bg, Position::TOP, Position::LEFT);
        $this->assertSame($bg, $result);
    }

    public function testPositionYOffset(): void
    {
        $this->assertSame(0,                           Position::TOP->yOffset(5, 20));
        $this->assertSame(15,                          Position::BOTTOM->yOffset(5, 20));
        $this->assertSame(7,                           Position::CENTER->yOffset(6, 20));
    }

    public function testPositionXOffset(): void
    {
        $this->assertSame(0,       Position::LEFT->xOffset(10, 40));
        $this->assertSame(30,      Position::RIGHT->xOffset(10, 40));
        $this->assertSame(15,      Position::CENTER->xOffset(10, 40));
    }

    // ─── z-index ─────────────────────────────────────────────────────────────

    public function testZIndexDefaultsToZero(): void
    {
        $this->assertSame(0, $this->veil->zIndex());
    }

    public function testWithZIndex(): void
    {
        $v = $this->veil->withZIndex(42);
        $this->assertSame(42, $v->zIndex());
    }

    public function testZIndexIsImmutable(): void
    {
        $v1 = $this->veil->withZIndex(1);
        $v2 = $v1->withZIndex(2);
        $this->assertSame(1, $v1->zIndex());
        $this->assertSame(2, $v2->zIndex());
    }

    public function testZIndexNegative(): void
    {
        $v = $this->veil->withZIndex(-10);
        $this->assertSame(-10, $v->zIndex());
    }

    // ─── click-outside-dismiss ─────────────────────────────────────────────────

    public function testClickOutsideDismissDefaultsToFalse(): void
    {
        $this->assertFalse($this->veil->clickOutsideDismiss());
    }

    public function testWithClickOutsideDismiss(): void
    {
        $v = $this->veil->withClickOutsideDismiss(true);
        $this->assertTrue($v->clickOutsideDismiss());
    }

    public function testClickOutsideDismissImmutable(): void
    {
        $v1 = $this->veil->withClickOutsideDismiss(true);
        $v2 = $v1->withClickOutsideDismiss(false);
        $this->assertTrue($v1->clickOutsideDismiss());
        $this->assertFalse($v2->clickOutsideDismiss());
    }

    public function testIsClickOutsideReturnsFalseWhenDismissDisabled(): void
    {
        $mouse = new MouseMsg(999, 999, MouseButton::Left, MouseAction::Press);
        $this->assertFalse($this->veil->isClickOutside($mouse));
    }

    public function testIsClickOutsideReturnsFalseWhenManagerNotSet(): void
    {
        $v = $this->veil->withClickOutsideDismiss(true);
        $mouse = new MouseMsg(999, 999, MouseButton::Left, MouseAction::Press);
        $this->assertFalse($v->isClickOutside($mouse));
    }

    // ─── auto-size ──────────────────────────────────────────────────────────

    public function testAutoSizeDefaultsToFalse(): void
    {
        $this->assertFalse($this->veil->autoSize());
    }

    public function testWithAutoSize(): void
    {
        $v = $this->veil->withAutoSize(true);
        $this->assertTrue($v->autoSize());
    }

    public function testAutoSizeImmutable(): void
    {
        $v1 = $this->veil->withAutoSize(true);
        $v2 = $v1->withAutoSize(false);
        $this->assertTrue($v1->autoSize());
        $this->assertFalse($v2->autoSize());
    }

    // ─── border chrome ──────────────────────────────────────────────────────

    public function testBorderDefaultsToNull(): void
    {
        $this->assertNull($this->veil->border());
    }

    public function testWithBorder(): void
    {
        $border = Border::normal();
        $v = $this->veil->withBorder($border);
        $this->assertNotNull($v->border());
        $this->assertSame($border, $v->border());
    }

    public function testBorderImmutable(): void
    {
        $border1 = Border::normal();
        $border2 = Border::thick();
        $v1 = $this->veil->withBorder($border1);
        $v2 = $v1->withBorder($border2);
        $this->assertSame($border1, $v1->border());
        $this->assertSame($border2, $v2->border());
    }

    public function testApplyBorderChromeReturnsContentUnchangedWhenNoBorder(): void
    {
        $content = "Hello\nWorld";
        $result = $this->veil->applyBorderChrome($content);
        $this->assertSame($content, $result);
    }

    public function testApplyBorderChromeWrapsContentWithBorder(): void
    {
        $v = $this->veil->withBorder(Border::normal());
        $content = "Hi";
        $result = $v->applyBorderChrome($content);
        // Normal border adds box-drawing characters around content
        $this->assertStringContainsString('┌', $result);
        $this->assertStringContainsString('─', $result);
        $this->assertStringContainsString('┐', $result);
    }

    public function testApplyBorderChromeWithThickBorder(): void
    {
        $v = $this->veil->withBorder(Border::thick());
        $result = $v->applyBorderChrome("X");
        $this->assertStringContainsString('━', $result);
    }

    // ─── manager ────────────────────────────────────────────────────────────

    public function testManagerDefaultsToNull(): void
    {
        $this->assertNull($this->veil->manager());
        $this->assertNull($this->veil->getManager());
    }

    public function testWithManager(): void
    {
        $manager = Manager::newGlobal();
        $v = $this->veil->withManager($manager);
        $this->assertSame($manager, $v->manager());
    }

    public function testManagerIsImmutable(): void
    {
        $manager1 = Manager::newGlobal();
        $manager2 = Manager::newGlobal();
        $v1 = $this->veil->withManager($manager1);
        $v2 = $v1->withManager($manager2);
        $this->assertSame($manager1, $v1->manager());
        $this->assertSame($manager2, $v2->manager());
    }

    public function testWithManagerReturnsNewInstance(): void
    {
        $original = $this->veil;
        $manager = Manager::newGlobal();
        $modified = $this->veil->withManager($manager);
        $this->assertNotSame($original, $modified);
        $this->assertNull($original->manager());
        $this->assertSame($manager, $modified->manager());
    }

    // ─── autoSize behavior in composite ─────────────────────────────────────

    public function testCompositeAutoSizeComputesDimensionsFromBorderedContent(): void
    {
        // When autoSize is true and border is set, dimensions should be computed
        // from the border-chromed content, not the raw content.
        $v = Veil::new()
            ->withAutoSize(true)
            ->withBorder(Border::normal());

        $bg = "....................\n....................\n....................";
        $fg = "Hi";

        // With normal border around "Hi", the bordered content is ~5 chars wide
        // (┌──┐ + Hi + ──┐ = more than just "Hi")
        $result = $v->composite($fg, $bg, Position::TOP, Position::LEFT);
        $lines = $v->splitLines($result);

        // The bordered content starts with box-drawing chars, not raw content
        $this->assertStringStartsWith('┌', $lines[0]);
        $this->assertStringContainsString('Hi', $result);
    }

    public function testCompositeAutoSizeWithoutBorderBehavesNormally(): void
    {
        // When autoSize is true but no border is set, dimensions come from raw content
        $v = Veil::new()->withAutoSize(true);

        $bg = "....................\n....................";
        $fg = "Hi";

        $result = $v->composite($fg, $bg, Position::TOP, Position::LEFT);
        $lines = $v->splitLines($result);

        // Should have "Hi" at the start, no border chars
        $this->assertStringStartsWith('Hi', $lines[0]);
    }

    public function testCompositeAutoSizeFalseUsesRawContentDimensions(): void
    {
        // When autoSize is false, raw content dimensions are used regardless of border
        $v = Veil::new()
            ->withAutoSize(false)
            ->withBorder(Border::normal());

        $bg = "....................\n....................\n....................";
        $fg = "Hi";

        $result = $v->composite($fg, $bg, Position::TOP, Position::LEFT);
        $lines = $v->splitLines($result);

        // Without autoSize, border chrome is NOT applied during composite
        // So raw "Hi" is placed, not the bordered version
        $this->assertStringStartsWith('Hi', $lines[0]);
        $this->assertStringNotContainsString('┌', $result);
    }

    // ─── combinations ─────────────────────────────────────────────────────────

    public function testChainingAllNewMethods(): void
    {
        $border = Border::rounded();
        $v = $this->veil
            ->withZIndex(5)
            ->withClickOutsideDismiss(true)
            ->withAutoSize(true)
            ->withBorder($border);

        $this->assertSame(5, $v->zIndex());
        $this->assertTrue($v->clickOutsideDismiss());
        $this->assertTrue($v->autoSize());
        $this->assertSame($border, $v->border());
    }

    public function testWithZIndexReturnsNewInstance(): void
    {
        $original = $this->veil;
        $modified = $this->veil->withZIndex(10);
        $this->assertNotSame($original, $modified);
        $this->assertSame(0, $original->zIndex());
        $this->assertSame(10, $modified->zIndex());
    }
}
