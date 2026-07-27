<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Tests\Foundation;

use PHPUnit\Framework\TestCase;
use SugarCraft\Dash\Foundation\Style;
use SugarCraft\Core\Util\Color;
use SugarCraft\Core\Util\ColorProfile;

final class StyleTest extends TestCase
{
    public function testDefaultConstruction(): void
    {
        $style = new Style();

        $this->assertNull($style->foreground);
        $this->assertNull($style->background);
        $this->assertFalse($style->bold);
        $this->assertFalse($style->dim);
        $this->assertFalse($style->italic);
        $this->assertFalse($style->underline);
        $this->assertFalse($style->reverse);
        $this->assertFalse($style->strike);
    }

    public function testConstructionWithColors(): void
    {
        $fg = Color::hex('#FF0000');
        $bg = Color::hex('#0000FF');

        $style = new Style(
            foreground: $fg,
            background: $bg,
            bold: true,
        );

        $this->assertSame($fg, $style->foreground);
        $this->assertSame($bg, $style->background);
        $this->assertTrue($style->bold);
        $this->assertFalse($style->dim);
    }

    public function testToAnsiEmptyStyle(): void
    {
        $style = new Style();
        $ansi = $style->toAnsi();

        $this->assertSame('', $ansi);
    }

    public function testToAnsiWithForeground(): void
    {
        $fg = Color::hex('#FF0000');
        $style = new Style(foreground: $fg);
        $ansi = $style->toAnsi(ColorProfile::TrueColor);

        $this->assertStringContainsString("\x1b[38;2", $ansi);
    }

    public function testToAnsiWithBackground(): void
    {
        $bg = Color::hex('#0000FF');
        $style = new Style(background: $bg);
        $ansi = $style->toAnsi(ColorProfile::TrueColor);

        $this->assertStringContainsString("\x1b[48;2", $ansi);
    }

    public function testToAnsiWithBold(): void
    {
        $style = new Style(bold: true);
        $ansi = $style->toAnsi();

        $this->assertStringContainsString("\x1b[1m", $ansi);
    }

    public function testToAnsiWithDim(): void
    {
        $style = new Style(dim: true);
        $ansi = $style->toAnsi();

        $this->assertStringContainsString("\x1b[2m", $ansi);
    }

    public function testToAnsiWithItalic(): void
    {
        $style = new Style(italic: true);
        $ansi = $style->toAnsi();

        $this->assertStringContainsString("\x1b[3m", $ansi);
    }

    public function testToAnsiWithUnderline(): void
    {
        $style = new Style(underline: true);
        $ansi = $style->toAnsi();

        $this->assertStringContainsString("\x1b[4m", $ansi);
    }

    public function testToAnsiWithReverse(): void
    {
        $style = new Style(reverse: true);
        $ansi = $style->toAnsi();

        $this->assertStringContainsString("\x1b[7m", $ansi);
    }

    public function testToAnsiWithStrike(): void
    {
        $style = new Style(strike: true);
        $ansi = $style->toAnsi();

        $this->assertStringContainsString("\x1b[9m", $ansi);
    }

    public function testToAnsiMultipleAttributes(): void
    {
        $fg = Color::hex('#FF0000');
        $style = new Style(foreground: $fg, bold: true, italic: true);
        $ansi = $style->toAnsi();

        $this->assertStringContainsString("\x1b[38;2", $ansi);
        $this->assertStringContainsString("\x1b[1m", $ansi);
        $this->assertStringContainsString("\x1b[3m", $ansi);
    }

    public function testWithForegroundReturnsNewInstance(): void
    {
        $style = new Style();
        $newStyle = $style->withForeground(Color::hex('#FF0000'));

        $this->assertNotSame($style, $newStyle);
        $this->assertEquals(Color::hex('#FF0000'), $newStyle->foreground);
        $this->assertNull($style->foreground);
    }

    public function testWithBackgroundReturnsNewInstance(): void
    {
        $style = new Style();
        $newStyle = $style->withBackground(Color::hex('#00FF00'));

        $this->assertNotSame($style, $newStyle);
        $this->assertEquals(Color::hex('#00FF00'), $newStyle->background);
        $this->assertNull($style->background);
    }

    public function testWithBoldReturnsNewInstance(): void
    {
        $style = new Style();
        $newStyle = $style->withBold(true);

        $this->assertNotSame($style, $newStyle);
        $this->assertTrue($newStyle->bold);
        $this->assertFalse($style->bold);
    }

    public function testWithForegroundPreservesOtherAttributes(): void
    {
        $style = new Style(bold: true, italic: true);
        $newStyle = $style->withForeground(Color::hex('#FF0000'));

        $this->assertTrue($newStyle->bold);
        $this->assertTrue($newStyle->italic);
        $this->assertEquals(Color::hex('#FF0000'), $newStyle->foreground);
    }

    public function testImmutabilityWithMultipleWithers(): void
    {
        $original = new Style();

        $s1 = $original->withForeground(Color::hex('#FF0000'));
        $s2 = $s1->withBackground(Color::hex('#00FF00'));
        $s3 = $s2->withBold(true);

        $this->assertNull($original->foreground);
        $this->assertNull($s1->background);
        $this->assertFalse($s1->bold);
        $this->assertEquals(Color::hex('#FF0000'), $s3->foreground);
        $this->assertEquals(Color::hex('#00FF00'), $s3->background);
        $this->assertTrue($s3->bold);
    }
}
