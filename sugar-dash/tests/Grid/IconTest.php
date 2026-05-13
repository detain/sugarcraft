<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Tests\Grid;

use SugarCraft\Dash\Grid\Icon;
use SugarCraft\Dash\Grid\Item;
use SugarCraft\Dash\Grid\Sizer;
use SugarCraft\Core\Util\Color;
use PHPUnit\Framework\TestCase;

final class IconTest extends TestCase
{
    // ═══════════════════════════════════════════════════════════════
    // Interface conformance
    // ═══════════════════════════════════════════════════════════════

    public function testIconImplementsSizer(): void
    {
        $icon = Icon::file();
        $this->assertInstanceOf(Sizer::class, $icon);
    }

    public function testIconImplementsItem(): void
    {
        $icon = Icon::file();
        $this->assertInstanceOf(Item::class, $icon);
    }

    // ═══════════════════════════════════════════════════════════════
    // Factory methods
    // ═══════════════════════════════════════════════════════════════

    public function testFileFactory(): void
    {
        $icon = Icon::file('document.txt');
        $rendered = $icon->render();

        $this->assertStringContainsString('📄', $rendered);
        $this->assertStringContainsString('document.txt', $rendered);
    }

    public function testFolderFactory(): void
    {
        $icon = Icon::folder('myproject');
        $rendered = $icon->render();

        $this->assertStringContainsString('📁', $rendered);
    }

    public function testGearFactory(): void
    {
        $icon = Icon::gear('Settings');
        $rendered = $icon->render();

        $this->assertStringContainsString('⚙', $rendered);
    }

    public function testHeartFactory(): void
    {
        $icon = Icon::heart();
        $rendered = $icon->render();

        $this->assertStringContainsString('♥', $rendered);
    }

    public function testStarFactory(): void
    {
        $icon = Icon::star();
        $rendered = $icon->render();

        $this->assertStringContainsString('★', $rendered);
    }

    public function testCheckFactory(): void
    {
        $icon = Icon::check();
        $rendered = $icon->render();

        $this->assertStringContainsString('✓', $rendered);
    }

    public function testCrossFactory(): void
    {
        $icon = Icon::cross();
        $rendered = $icon->render();

        $this->assertStringContainsString('✗', $rendered);
    }

    public function testInfoFactory(): void
    {
        $icon = Icon::info();
        $rendered = $icon->render();

        $this->assertStringContainsString('ℹ', $rendered);
    }

    public function testWarningFactory(): void
    {
        $icon = Icon::warning();
        $rendered = $icon->render();

        $this->assertStringContainsString('⚠', $rendered);
    }

    public function testErrorFactory(): void
    {
        $icon = Icon::error();
        $rendered = $icon->render();

        $this->assertStringContainsString('⛔', $rendered);
    }

    public function testHomeFactory(): void
    {
        $icon = Icon::home();
        $rendered = $icon->render();

        $this->assertStringContainsString('⌂', $rendered);
    }

    public function testSearchFactory(): void
    {
        $icon = Icon::search();
        $rendered = $icon->render();

        $this->assertStringContainsString('🔍', $rendered);
    }

    public function testMusicFactory(): void
    {
        $icon = Icon::music();
        $rendered = $icon->render();

        $this->assertStringContainsString('♪', $rendered);
    }

    public function testPlayFactory(): void
    {
        $icon = Icon::play();
        $rendered = $icon->render();

        $this->assertStringContainsString('▶', $rendered);
    }

    public function testPauseFactory(): void
    {
        $icon = Icon::pause();
        $rendered = $icon->render();

        $this->assertStringContainsString('⏸', $rendered);
    }

    public function testStopFactory(): void
    {
        $icon = Icon::stop();
        $rendered = $icon->render();

        $this->assertStringContainsString('⏹', $rendered);
    }

    public function testArrowUpFactory(): void
    {
        $icon = Icon::arrowUp();
        $rendered = $icon->render();

        $this->assertStringContainsString('↑', $rendered);
    }

    public function testArrowDownFactory(): void
    {
        $icon = Icon::arrowDown();
        $rendered = $icon->render();

        $this->assertStringContainsString('↓', $rendered);
    }

    public function testArrowLeftFactory(): void
    {
        $icon = Icon::arrowLeft();
        $rendered = $icon->render();

        $this->assertStringContainsString('←', $rendered);
    }

    public function testArrowRightFactory(): void
    {
        $icon = Icon::arrowRight();
        $rendered = $icon->render();

        $this->assertStringContainsString('→', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Basic rendering
    // ═══════════════════════════════════════════════════════════════

    public function testRenderReturnsNonEmpty(): void
    {
        $icon = Icon::file();
        $rendered = $icon->render();

        $this->assertNotSame('', $rendered);
    }

    public function testRenderContainsGlyph(): void
    {
        $icon = new Icon('★');
        $rendered = $icon->render();

        $this->assertStringContainsString('★', $rendered);
    }

    public function testRenderContainsLabel(): void
    {
        $icon = new Icon('★', 'Gold star');
        $rendered = $icon->render();

        $this->assertStringContainsString('Gold star', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Size scaling
    // ═══════════════════════════════════════════════════════════════

    public function testSizeOneRendersSingleGlyph(): void
    {
        $icon = new Icon('★', null, 1);
        $rendered = $icon->render();

        // Should contain glyph exactly once
        $this->assertSame(1, substr_count($rendered, '★'));
    }

    public function testSizeTwoRendersDoubledGlyph(): void
    {
        $icon = new Icon('★', null, 2);
        $rendered = $icon->render();

        // Should contain glyph twice
        $this->assertSame(2, substr_count($rendered, '★'));
    }

    public function testSizeThreeRendersTripledGlyph(): void
    {
        $icon = new Icon('★', null, 3);
        $rendered = $icon->render();

        // Should contain glyph three times
        $this->assertSame(3, substr_count($rendered, '★'));
    }

    public function testSizeZeroDefaultsToOne(): void
    {
        $icon = new Icon('★', null, 0);
        $rendered = $icon->render();

        $this->assertStringContainsString('★', $rendered);
    }

    public function testSizeNegativeDefaultsToOne(): void
    {
        $icon = new Icon('★', null, -5);
        $rendered = $icon->render();

        $this->assertStringContainsString('★', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Color handling
    // ═══════════════════════════════════════════════════════════════

    public function testColorAddsAnsiCodes(): void
    {
        $icon = new Icon('★', null, 1, Color::ansi(11));
        $rendered = $icon->render();

        $this->assertMatchesRegularExpression('/\x1b\[/', $rendered);
    }

    public function testLabelColorAddsAnsiCodes(): void
    {
        $icon = new Icon('★', 'label', 1, null, Color::ansi(12));
        $rendered = $icon->render();

        $this->assertMatchesRegularExpression('/\x1b\[/', $rendered);
    }

    public function testColorResetAtEnd(): void
    {
        $icon = new Icon('★', 'test', 1, Color::ansi(11));
        $rendered = $icon->render();

        $this->assertStringEndsWith("\x1b[0m", $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Size handling
    // ═══════════════════════════════════════════════════════════════

    public function testSetSizeReturnsNewInstance(): void
    {
        $original = Icon::file();
        $resized = $original->setSize(20, 5);

        $this->assertNotSame($original, $resized);
    }

    public function testGetInnerSizeReturnsCorrectDimensions(): void
    {
        $icon = new Icon('★', 'Label', 1);
        [$w, $h] = $icon->getInnerSize();

        $this->assertGreaterThan(0, $w);
        $this->assertSame(1, $h);
    }

    public function testGetInnerSizeWithLabel(): void
    {
        $icon = new Icon('★', 'Label', 1);
        [$w, ] = $icon->getInnerSize();

        // Width should include glyph + space + label
        $this->assertGreaterThan(1, $w);
    }

    // ═══════════════════════════════════════════════════════════════
    // Withers / fluent API
    // ═══════════════════════════════════════════════════════════════

    public function testWithGlyphReturnsNewInstance(): void
    {
        $original = Icon::file();
        $updated = $original->withGlyph('★');

        $this->assertNotSame($original, $updated);
        $this->assertStringContainsString('★', $updated->render());
    }

    public function testWithLabelReturnsNewInstance(): void
    {
        $original = Icon::file();
        $updated = $original->withLabel('New Label');

        $this->assertNotSame($original, $updated);
        $this->assertStringContainsString('New Label', $updated->render());
    }

    public function testWithSizeReturnsNewInstance(): void
    {
        $original = Icon::file();
        $updated = $original->withSize(2);

        $this->assertNotSame($original, $updated);
    }

    public function testWithColorReturnsNewInstance(): void
    {
        $original = Icon::file();
        $updated = $original->withColor(Color::ansi(9));

        $this->assertNotSame($original, $updated);
    }

    public function testWithLabelColorReturnsNewInstance(): void
    {
        $original = Icon::file();
        $updated = $original->withLabelColor(Color::ansi(12));

        $this->assertNotSame($original, $updated);
    }

    public function testOriginalUnchangedAfterWithers(): void
    {
        $original = Icon::file('Original');
        $original->withGlyph('★');
        $original->withLabel('Changed');
        $original->withSize(3);

        $rendered = $original->render();
        $this->assertStringContainsString('Original', $rendered);
        $this->assertStringContainsString('📄', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Edge cases
    // ═══════════════════════════════════════════════════════════════

    public function testEmptyLabelStillRenders(): void
    {
        $icon = new Icon('★', '', 1);
        $rendered = $icon->render();

        $this->assertStringContainsString('★', $rendered);
    }

    public function testVeryLongLabelRenders(): void
    {
        $icon = new Icon('★', str_repeat('x', 100), 1);
        $rendered = $icon->render();

        $this->assertNotSame('', $rendered);
    }

    public function testUnicodeGlyph(): void
    {
        $icon = new Icon('🎉', 'Party', 1);
        $rendered = $icon->render();

        $this->assertStringContainsString('🎉', $rendered);
        $this->assertStringContainsString('Party', $rendered);
    }

    public function testFactoryWithNullLabelRendersOnlyGlyph(): void
    {
        $icon = Icon::file(null);
        $rendered = $icon->render();

        $this->assertStringContainsString('📄', $rendered);
        // No additional text after the glyph
        $this->assertStringNotContainsString(' ', $rendered);
    }
}
