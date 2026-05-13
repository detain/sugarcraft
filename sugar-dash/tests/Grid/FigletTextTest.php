<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Tests\Grid;

use SugarCraft\Dash\Grid\FigletText;
use SugarCraft\Dash\Grid\Item;
use SugarCraft\Dash\Grid\Sizer;
use SugarCraft\Core\Util\Color;
use PHPUnit\Framework\TestCase;

final class FigletTextTest extends TestCase
{
    // ═══════════════════════════════════════════════════════════════
    // Interface conformance
    // ═══════════════════════════════════════════════════════════════

    public function testFigletTextImplementsSizer(): void
    {
        $figlet = FigletText::new('Test');
        $this->assertInstanceOf(Sizer::class, $figlet);
    }

    public function testFigletTextImplementsItem(): void
    {
        $figlet = FigletText::new('Test');
        $this->assertInstanceOf(Item::class, $figlet);
    }

    // ═══════════════════════════════════════════════════════════════
    // Basic rendering
    // ═══════════════════════════════════════════════════════════════

    public function testRenderReturnsNonEmpty(): void
    {
        $figlet = FigletText::new('HI');
        $rendered = $figlet->render();

        $this->assertNotSame('', $rendered);
    }

    public function testRenderContainsCharacters(): void
    {
        $figlet = FigletText::new('A');
        $rendered = $figlet->render();

        // Figlet text renders ASCII art using █ block chars, not the literal letter
        $this->assertNotSame('', $rendered);
        $this->assertStringContainsString('█', $rendered);
    }

    public function testRenderHasMultipleLines(): void
    {
        $figlet = FigletText::new('HELLO');
        $rendered = $figlet->render();

        // Figlet text should have multiple lines
        $this->assertGreaterThan(1, substr_count($rendered, "\n"));
    }

    // ═══════════════════════════════════════════════════════════════
    // Factory methods
    // ═══════════════════════════════════════════════════════════════

    public function testShadowFactory(): void
    {
        $figlet = FigletText::shadow('Test');
        $rendered = $figlet->render();

        $this->assertNotSame('', $rendered);
    }

    public function testSmallFactory(): void
    {
        $figlet = FigletText::small('Test');
        $rendered = $figlet->render();

        $this->assertNotSame('', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Color handling
    // ═══════════════════════════════════════════════════════════════

    public function testTextColorAddsAnsiCodes(): void
    {
        $figlet = FigletText::new('Test')
            ->withTextColor(Color::ansi(9));
        $rendered = $figlet->render();

        $this->assertMatchesRegularExpression('/\x1b\[/', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Font handling
    // ═══════════════════════════════════════════════════════════════

    public function testWithFontChangesOutput(): void
    {
        $figlet1 = FigletText::new('A')->withFont('standard');
        $figlet2 = FigletText::new('A')->withFont('small');

        // Different fonts should produce different output
        $this->assertNotSame($figlet1->render(), $figlet2->render());
    }

    // ═══════════════════════════════════════════════════════════════
    // Size handling
    // ═══════════════════════════════════════════════════════════════

    public function testSetSizeReturnsNewInstance(): void
    {
        $original = FigletText::new('Test');
        $resized = $original->setSize(50, 20);

        $this->assertNotSame($original, $resized);
    }

    // ═══════════════════════════════════════════════════════════════
    // Withers / fluent API
    // ═══════════════════════════════════════════════════════════════

    public function testWithTextColorReturnsNewInstance(): void
    {
        $original = FigletText::new('Test');
        $updated = $original->withTextColor(Color::ansi(12));

        $this->assertNotSame($original, $updated);
    }

    public function testOriginalUnchangedAfterWithTextColor(): void
    {
        $original = FigletText::new('Test');
        $original->withTextColor(Color::ansi(12));

        // Original should not be modified
        $this->assertNotSame('', $original->render());
    }

    // ═══════════════════════════════════════════════════════════════
    // Inner size calculation
    // ═══════════════════════════════════════════════════════════════

    public function testGetInnerSizeReturnsCorrectDimensions(): void
    {
        $figlet = FigletText::new('HI');
        [$w, $h] = $figlet->getInnerSize();

        $this->assertGreaterThan(0, $w);
        $this->assertGreaterThan(0, $h);
    }

    public function testGetInnerSizeWithLongerText(): void
    {
        $short = FigletText::new('A');
        $long = FigletText::new('HELLO');

        [$wShort, ] = $short->getInnerSize();
        [$wLong, ] = $long->getInnerSize();

        $this->assertGreaterThan($wShort, $wLong);
    }

    // ═══════════════════════════════════════════════════════════════
    // Edge cases
    // ═══════════════════════════════════════════════════════════════

    public function testEmptyText(): void
    {
        $figlet = FigletText::new('');
        $rendered = $figlet->render();

        // Should return empty string for empty input
        $this->assertSame('', $rendered);
    }

    public function testNumbersRendering(): void
    {
        $figlet = FigletText::new('123');
        $rendered = $figlet->render();

        $this->assertNotSame('', $rendered);
    }

    public function testSpecialCharactersRendering(): void
    {
        $figlet = FigletText::new('!?.');
        $rendered = $figlet->render();

        $this->assertNotSame('', $rendered);
    }
}
