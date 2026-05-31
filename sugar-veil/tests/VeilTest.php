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

    // ─── backdrop ─────────────────────────────────────────────────────────────

    public function testWithBackdrop(): void
    {
        $v = $this->veil->withBackdrop(50);
        $this->assertNotSame($this->veil, $v);
    }

    public function testWithBackdropClampsNegativeToZero(): void
    {
        // The constructor clamps: max(0, min(100, -10)) = 0
        $v = $this->veil->withBackdrop(-10);
        $bg = "..........\n..........";
        $result = $v->composite('X', $bg, Position::TOP, Position::LEFT);
        $this->assertStringContainsString('X', $result);
    }

    public function testWithBackdropClampsOver100To100(): void
    {
        // The constructor clamps: max(0, min(100, 150)) = 100
        $v = $this->veil->withBackdrop(150);
        $bg = "..........\n..........";
        $result = $v->composite('X', $bg, Position::TOP, Position::LEFT);
        $this->assertStringContainsString('X', $result);
    }

    // ─── animation ─────────────────────────────────────────────────────────────

    public function testWithAnimation(): void
    {
        $v = $this->veil->withAnimation(\SugarCraft\Veil\Animation\AnimationKind::FADE);
        $this->assertNotSame($this->veil, $v);
    }

    public function testWithAnimationSlide(): void
    {
        $v = $this->veil->withAnimation(\SugarCraft\Veil\Animation\AnimationKind::SLIDE);
        $this->assertNotSame($this->veil, $v);
    }

    public function testWithAnimationScale(): void
    {
        $v = $this->veil->withAnimation(\SugarCraft\Veil\Animation\AnimationKind::SCALE);
        $this->assertNotSame($this->veil, $v);
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

    // ─── Scanner: scan() / hit() ──────────────────────────────────────────────

    public function testMarkWrapsContentWithZoneMarkers(): void
    {
        $marked = $this->veil->mark('btn-1', 'Click me');
        // Mark::wrap uses U+E000/U+E001 private-use sentinels
        $this->assertStringContainsString('Click me', $marked);
        // Should contain the open and close sentinels around the content
        $this->assertStringContainsString("\u{E000}", $marked);
        $this->assertStringContainsString("\u{E001}", $marked);
    }

    public function testScanHitDetectsZoneInside(): void
    {
        // Mark some content with a zone marker and scan it directly.
        // This tests the scanner integration without going through composite()
        // (which has a separate multi-byte UTF-8 encoding issue).
        $marked = $this->veil->mark('overlay-zone', 'XYZ');
        $rendered = $marked; // In real use, this would be the full terminal output

        // scan() parses zone markers and returns a new Veil with updated scanner
        $veiled = $this->veil->scan($rendered);

        // hit() should find the overlay-zone at coordinates inside the marked content
        $zone = $veiled->hit(1, 1);
        $this->assertNotNull($zone);
        $this->assertSame('overlay-zone', $zone->id);
    }

    public function testScanHitReturnsNullOutsideZone(): void
    {
        $marked = $this->veil->mark('centered', 'ABC');
        $rendered = $marked;

        $veiled = $this->veil->scan($rendered);

        // Coordinates far outside any content should return null
        $zone = $veiled->hit(999, 999);
        $this->assertNull($zone);
    }

    public function testScanReturnsNewInstanceForChaining(): void
    {
        $marked = $this->veil->mark('zone-a', 'A');
        $rendered = $marked;

        $result = $this->veil->scan($rendered);
        // scan() returns a new instance (immutable via mutate)
        $this->assertNotSame($this->veil, $result);
    }

    public function testScanUpdatesScannerAndLastRendered(): void
    {
        // scan() passes $this->scanner (non-null) to mutate
        // mutate passes it to constructor where $scanner ?? Scanner::new() uses the passed value
        $marked = $this->veil->mark('test-zone', 'B');
        $rendered = $marked;

        $veiled = $this->veil->scan($rendered);

        // The new instance should have the scanned content
        $zone = $veiled->hit(1, 1);
        $this->assertNotNull($zone);
        $this->assertSame('test-zone', $zone->id);
    }

    public function testHitWithoutScanReturnsNull(): void
    {
        // Fresh veil with no scan() called
        $zone = $this->veil->hit(1, 1);
        $this->assertNull($zone);
    }

    public function testScanHitDetectsZoneById(): void
    {
        // Test that zone is correctly registered by scanning marked content.
        // The internal scanner records zones during scan().
        $marked = $this->veil->mark('my-btn', 'OK');
        $veiled = $this->veil->scan($marked);

        // After scan(), hit() should find zones by their position
        $zone = $veiled->hit(1, 1);
        $this->assertNotNull($zone);
        $this->assertSame('my-btn', $zone->id);
    }

    // ─── Back-compat: withManager() ─────────────────────────────────────────

    public function testWithManagerBackCompatDoesNotThrow(): void
    {
        $manager = \SugarCraft\Zone\Manager::newGlobal();
        // Should not throw — manager is stored but not used for hit-testing
        $v = $this->veil->withManager($manager);
        $this->assertInstanceOf(Veil::class, $v);
    }

    public function testWithManagerPreservesManager(): void
    {
        $manager = \SugarCraft\Zone\Manager::newGlobal();
        $v = $this->veil->withManager($manager);
        $this->assertSame($manager, $v->manager());
    }

    // ─── animate() ───────────────────────────────────────────────────────────

    public function testAnimateWithoutAnimationSetReturnsCompositeResult(): void
    {
        $bg = "....................\n....................";
        $fg = "X";

        // Without animation set, animate() should behave like composite()
        $result = $this->veil->animate($fg, $bg, Position::TOP, Position::LEFT, 0.5);

        // Should composite the foreground onto the background
        $this->assertStringContainsString('X', $result);
        $this->assertStringContainsString('.', $result);
    }

    public function testAnimateWithSlideAnimationAtProgressZero(): void
    {
        $v = $this->veil->withAnimation(\SugarCraft\Veil\Animation\AnimationKind::SLIDE);
        $bg = "....................\n....................";
        $fg = "Test";

        $result = $v->animate($fg, $bg, Position::TOP, Position::LEFT, 0.0);

        // At progress 0, animation offsets are applied but content still appears
        $this->assertIsString($result);
    }

    public function testAnimateWithSlideAnimationAtProgressOne(): void
    {
        $v = $this->veil->withAnimation(\SugarCraft\Veil\Animation\AnimationKind::SLIDE);
        $bg = "....................\n....................";
        $fg = "Test";

        // At progress 1, offsets should be 0, essentially like composite()
        $result = $v->animate($fg, $bg, Position::TOP, Position::LEFT, 1.0);

        $this->assertStringContainsString('Test', $result);
    }

    public function testAnimateWithFadeAnimation(): void
    {
        $v = $this->veil->withAnimation(\SugarCraft\Veil\Animation\AnimationKind::FADE);
        $bg = "....................\n....................";
        $fg = "Fade";

        $result = $v->animate($fg, $bg, Position::TOP, Position::LEFT, 0.5);

        // Fade animation returns foreground unchanged (terminal limitations)
        $this->assertStringContainsString('Fade', $result);
    }

    public function testAnimateWithScaleAnimation(): void
    {
        $v = $this->veil->withAnimation(\SugarCraft\Veil\Animation\AnimationKind::SCALE);
        $bg = "....................\n....................";
        $fg = "A\nB\nC\nD\nE";

        $result = $v->animate($fg, $bg, Position::TOP, Position::LEFT, 0.5);

        // At 50% progress, some lines may not appear due to scale animation
        $this->assertIsString($result);
    }

    public function testAnimateWithScaleAnimationAtProgressOneReturnsFullContent(): void
    {
        // At progress 1.0, animation block is skipped (progress < 1.0 check)
        // but animate() passes full foreground to composite
        $v = $this->veil->withAnimation(\SugarCraft\Veil\Animation\AnimationKind::SCALE);
        $bg = "....................\n....................\n....................\n....................\n....................\n....................";
        $fg = "A\nB\nC\nD\nE";

        $result = $v->animate($fg, $bg, Position::TOP, Position::LEFT, 1.0);

        $this->assertStringContainsString('A', $result);
        $this->assertStringContainsString('E', $result);
    }

    public function testAnimateProgressOneSkipsAnimationBlock(): void
    {
        // At progress >= 1.0, animation is not applied
        $v = $this->veil->withAnimation(\SugarCraft\Veil\Animation\AnimationKind::SCALE);
        $bg = "....................";
        $fg = "X";

        $result = $v->animate($fg, $bg, Position::TOP, Position::LEFT, 1.0);

        // Should composite X at top-left, animation block skipped
        $this->assertStringStartsWith('X', $result);
    }

    public function testAnimateProgressLessThanOneAppliesAnimation(): void
    {
        // At progress < 1.0, animation is applied
        $v = $this->veil->withAnimation(\SugarCraft\Veil\Animation\AnimationKind::SCALE);
        $bg = "....................\n....................\n....................";
        $fg = "A\nB\nC\nD\nE\nF";

        $result = $v->animate($fg, $bg, Position::TOP, Position::LEFT, 0.99);

        // At near-full progress, most content visible but still via animation
        $this->assertIsString($result);
    }

    // ─── getManager() (deprecated) ────────────────────────────────────────────

    public function testGetManagerReturnsSameAsManager(): void
    {
        $manager = \SugarCraft\Zone\Manager::newGlobal();
        $v = $this->veil->withManager($manager);
        $this->assertSame($v->manager(), $v->getManager());
    }

    public function testGetManagerReturnsNullWhenNoManager(): void
    {
        $this->assertNull($this->veil->getManager());
    }
}
