<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Tests\Components\Modal;

use SugarCraft\Dash\Components\Modal\Popover;
use SugarCraft\Dash\Foundation\Item;
use SugarCraft\Dash\Foundation\Sizer;
use SugarCraft\Core\Util\Color;
use SugarCraft\Core\Util\Ansi;
use PHPUnit\Framework\TestCase;

final class PopoverTest extends TestCase
{
    // ═══════════════════════════════════════════════════════════════
    // Interface conformance
    // ═══════════════════════════════════════════════════════════════

    public function testPopoverImplementsSizer(): void
    {
        $popover = Popover::new('Title', 'Content');
        $this->assertInstanceOf(Sizer::class, $popover);
    }

    public function testPopoverImplementsItem(): void
    {
        $popover = Popover::new('Title', 'Content');
        $this->assertInstanceOf(Item::class, $popover);
    }

    // ═══════════════════════════════════════════════════════════════
    // Basic rendering
    // ═══════════════════════════════════════════════════════════════

    public function testRenderReturnsNonEmpty(): void
    {
        $popover = Popover::new('Title', 'Content');
        $rendered = $popover->render();

        $this->assertNotSame('', $rendered);
    }

    public function testRenderContainsTitle(): void
    {
        $popover = Popover::new('My Title', 'Some content');
        $rendered = $popover->render();

        $this->assertStringContainsString('My Title', $rendered);
    }

    public function testRenderContainsContent(): void
    {
        $popover = Popover::new('Title', 'My Content');
        $rendered = $popover->render();

        $this->assertStringContainsString('My Content', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Box-drawing characters
    // ═══════════════════════════════════════════════════════════════

    public function testRenderContainsBoxCharacters(): void
    {
        $popover = Popover::new('Test', 'Content');
        $rendered = $popover->render();

        $this->assertStringContainsString('┌', $rendered);
        $this->assertStringContainsString('┐', $rendered);
        $this->assertStringContainsString('│', $rendered);
        $this->assertStringContainsString('└', $rendered);
        $this->assertStringContainsString('─', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Arrow
    // ═══════════════════════════════════════════════════════════════

    public function testArrowVisibleByDefault(): void
    {
        $popover = Popover::new('Title', 'Content');
        $rendered = $popover->render();

        $this->assertStringContainsString('▼', $rendered);
    }

    public function testArrowCanBeHidden(): void
    {
        $popover = Popover::new('Title', 'Content')->withShowArrow(false);
        $rendered = $popover->render();

        $this->assertStringNotContainsString('▼', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Preset styles
    // ═══════════════════════════════════════════════════════════════

    public function testInfoFactory(): void
    {
        $popover = Popover::info('Info Title', 'Info content');
        $rendered = $popover->render();

        $this->assertStringContainsString('Info Title', $rendered);
        $this->assertStringContainsString('Info content', $rendered);
    }

    public function testWarningFactory(): void
    {
        $popover = Popover::warning('Warning Title', 'Warning content');
        $rendered = $popover->render();

        $this->assertStringContainsString('Warning Title', $rendered);
        $this->assertMatchesRegularExpression('/\x1b\[/', $rendered);
    }

    public function testDangerFactory(): void
    {
        $popover = Popover::danger('Error Title', 'Error content');
        $rendered = $popover->render();

        $this->assertStringContainsString('Error Title', $rendered);
        $this->assertMatchesRegularExpression('/\x1b\[/', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Color handling
    // ═══════════════════════════════════════════════════════════════

    public function testColorAddsAnsiCodes(): void
    {
        $popover = Popover::new('Title', 'Content')
            ->withBorderColor(Color::ansi(9));
        $rendered = $popover->render();

        $this->assertMatchesRegularExpression('/\x1b\[/', $rendered);
    }

    public function testBackgroundColorAddsAnsiCodes(): void
    {
        $popover = Popover::new('Title', 'Content')
            ->withBackgroundColor(Color::ansi(8));
        $rendered = $popover->render();

        $this->assertMatchesRegularExpression('/\x1b\[/', $rendered);
    }

    public function testColorResetAtEnd(): void
    {
        $popover = Popover::new('Title', 'Content')
            ->withBorderColor(Color::ansi(9))
            ->withBackgroundColor(Color::ansi(8));
        $rendered = $popover->render();

        $this->assertStringEndsWith("\x1b[0m", $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Size handling
    // ═══════════════════════════════════════════════════════════════

    public function testSetSizeReturnsNewInstance(): void
    {
        $original = Popover::new('Title', 'Content');
        $resized = $original->setSize(40, 10);

        $this->assertNotSame($original, $resized);
    }

    public function testWidthAllocation(): void
    {
        $popover = Popover::new('Title', 'Content')->setSize(50, 10);
        [$w, ] = $popover->getInnerSize();

        $this->assertGreaterThanOrEqual(50, $w);
    }

    // ═══════════════════════════════════════════════════════════════
    // Withers / fluent API
    // ═══════════════════════════════════════════════════════════════

    public function testWithTitleReturnsNewInstance(): void
    {
        $original = Popover::new('Original', 'Content');
        $updated = $original->withTitle('Updated');

        $this->assertNotSame($original, $updated);
        $this->assertStringContainsString('Updated', $updated->render());
    }

    public function testWithContentReturnsNewInstance(): void
    {
        $original = Popover::new('Title', 'Original');
        $updated = $original->withContent('Updated');

        $this->assertNotSame($original, $updated);
        $this->assertStringContainsString('Updated', $updated->render());
    }

    public function testWithBorderColorReturnsNewInstance(): void
    {
        $original = Popover::new('Title', 'Content');
        $updated = $original->withBorderColor(Color::ansi(9));

        $this->assertNotSame($original, $updated);
    }

    public function testWithBackgroundColorReturnsNewInstance(): void
    {
        $original = Popover::new('Title', 'Content');
        $updated = $original->withBackgroundColor(Color::ansi(8));

        $this->assertNotSame($original, $updated);
    }

    public function testWithTitleColorReturnsNewInstance(): void
    {
        $original = Popover::new('Title', 'Content');
        $updated = $original->withTitleColor(Color::ansi(12));

        $this->assertNotSame($original, $updated);
    }

    public function testWithShowArrowReturnsNewInstance(): void
    {
        $original = Popover::new('Title', 'Content');
        $updated = $original->withShowArrow(false);

        $this->assertNotSame($original, $updated);
    }

    public function testOriginalUnchangedAfterWithTitle(): void
    {
        $original = Popover::new('Original', 'Content');
        $original->withTitle('Changed');
        $rendered = $original->render();

        $this->assertStringContainsString('Original', $rendered);
        $this->assertStringNotContainsString('Changed', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Inner size calculation
    // ═══════════════════════════════════════════════════════════════

    public function testGetInnerSizeReturnsCorrectDimensions(): void
    {
        $popover = Popover::new('Title', 'Content');
        [$w, $h] = $popover->getInnerSize();

        $this->assertGreaterThan(0, $w);
        $this->assertGreaterThan(0, $h);
    }

    public function testGetInnerSizeWithTitleHasExtraLine(): void
    {
        $popoverNoTitle = Popover::new('', 'Content');
        $popoverWithTitle = Popover::new('Title', 'Content');

        [, $hNoTitle] = $popoverNoTitle->getInnerSize();
        [, $hWithTitle] = $popoverWithTitle->getInnerSize();

        $this->assertGreaterThan($hNoTitle, $hWithTitle);
    }

    public function testGetInnerSizeWithArrowHasExtraLine(): void
    {
        $popoverNoArrow = Popover::new('Title', 'Content')->withShowArrow(false);
        $popoverWithArrow = Popover::new('Title', 'Content')->withShowArrow(true);

        [, $hNoArrow] = $popoverNoArrow->getInnerSize();
        [, $hWithArrow] = $popoverWithArrow->getInnerSize();

        $this->assertGreaterThan($hNoArrow, $hWithArrow);
    }

    // ═══════════════════════════════════════════════════════════════
    // Word wrapping
    // ═══════════════════════════════════════════════════════════════

    public function testLongContentGetsWrapped(): void
    {
        $popover = Popover::new('Title', str_repeat('word ', 50));
        $rendered = $popover->render();

        // Should wrap to multiple lines
        $lines = explode("\n", $rendered);
        $this->assertGreaterThan(3, count($lines));
    }

    public function testEmptyTitleRendersWithoutTitleLine(): void
    {
        $popover = Popover::new('', 'Content');
        $rendered = $popover->render();

        // Should only have top, content, and bottom borders
        $this->assertStringNotContainsString('│ │', $rendered);
    }

    public function testEmptyContentRenders(): void
    {
        $popover = Popover::new('Title', '');
        $rendered = $popover->render();

        $this->assertNotSame('', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Edge cases
    // ═══════════════════════════════════════════════════════════════

    public function testUnicodeTitle(): void
    {
        $popover = Popover::new('タイトル', 'Content');
        $rendered = $popover->render();

        $this->assertStringContainsString('タイトル', $rendered);
    }

    public function testUnicodeContent(): void
    {
        $popover = Popover::new('Title', 'こんにちは');
        $rendered = $popover->render();

        $this->assertStringContainsString('こんにちは', $rendered);
    }

    public function testSpecialCharsInContent(): void
    {
        $popover = Popover::new('Title', 'Test & <Tag> "Quote"');
        $rendered = $popover->render();

        $this->assertStringContainsString('Test & <Tag> "Quote"', $rendered);
    }
}
