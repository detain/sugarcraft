<?php

declare(strict_types=1);

namespace SugarCraft\Sprinkles\Tests;

use SugarCraft\Core\Util\Color;
use PHPUnit\Framework\TestCase;

final class ColorParseTest extends TestCase
{
    public function testParseCssColorCyan(): void
    {
        $color = Color::parse('cyan');
        $this->assertSame(0, $color->r);
        $this->assertSame(205, $color->g);
        $this->assertSame(205, $color->b);
    }

    public function testParseCssColorRed(): void
    {
        $color = Color::parse('red');
        $this->assertSame(205, $color->r);
        $this->assertSame(0, $color->g);
        $this->assertSame(0, $color->b);
    }

    public function testParseCssColorBlue(): void
    {
        $color = Color::parse('blue');
        $this->assertSame(0, $color->r);
        $this->assertSame(0, $color->g);
        $this->assertSame(238, $color->b);
    }

    public function testParseCssColorWhite(): void
    {
        $color = Color::parse('white');
        $this->assertSame(229, $color->r);
        $this->assertSame(229, $color->g);
        $this->assertSame(229, $color->b);
    }

    public function testParseColorCaseInsensitive(): void
    {
        $color = Color::parse('CYAN');
        $this->assertSame(0, $color->r);
        $this->assertSame(205, $color->g);
        $this->assertSame(205, $color->b);
    }

    public function testParseColorWithWhitespace(): void
    {
        $color = Color::parse('  cyan  ');
        $this->assertSame(0, $color->r);
        $this->assertSame(205, $color->g);
        $this->assertSame(205, $color->b);
    }

    public function testParseColorPurple(): void
    {
        $color = Color::parse('purple');
        // purple maps to magenta
        $this->assertSame(205, $color->r);
        $this->assertSame(0, $color->g);
        $this->assertSame(205, $color->b);
    }

    public function testParseColorOrange(): void
    {
        $color = Color::parse('orange');
        $this->assertSame(255, $color->r);
        $this->assertSame(165, $color->g);
        $this->assertSame(0, $color->b);
    }

    public function testParseColorNavy(): void
    {
        $color = Color::parse('navy');
        $this->assertSame(0, $color->r);
        $this->assertSame(0, $color->g);
        $this->assertSame(128, $color->b);
    }

    public function testParseColorGray(): void
    {
        $color = Color::parse('gray');
        $this->assertSame(128, $color->r);
        $this->assertSame(128, $color->g);
        $this->assertSame(128, $color->b);
    }

    public function testParseColorGrey(): void
    {
        $color = Color::parse('grey');
        $this->assertSame(128, $color->r);
        $this->assertSame(128, $color->g);
        $this->assertSame(128, $color->b);
    }

    public function testParseEmptyThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Color::parse('');
    }

    public function testParseWhitespaceOnlyThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Color::parse('   ');
    }

    public function testParseUnknownThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Color::parse('notacolor');
    }

    public function testParseBlack(): void
    {
        $color = Color::parse('black');
        $this->assertSame(0, $color->r);
        $this->assertSame(0, $color->g);
        $this->assertSame(0, $color->b);
    }

    public function testParseGreen(): void
    {
        $color = Color::parse('green');
        $this->assertSame(0, $color->r);
        $this->assertSame(205, $color->g);
        $this->assertSame(0, $color->b);
    }

    public function testParseYellow(): void
    {
        $color = Color::parse('yellow');
        $this->assertSame(205, $color->r);
        $this->assertSame(205, $color->g);
        $this->assertSame(0, $color->b);
    }

    public function testParseMagenta(): void
    {
        $color = Color::parse('magenta');
        $this->assertSame(205, $color->r);
        $this->assertSame(0, $color->g);
        $this->assertSame(205, $color->b);
    }

    public function testParseAqua(): void
    {
        $color = Color::parse('aqua');
        $this->assertSame(0, $color->r);
        $this->assertSame(205, $color->g);
        $this->assertSame(205, $color->b);
    }

    public function testParseLime(): void
    {
        $color = Color::parse('lime');
        $this->assertSame(0, $color->r);
        $this->assertSame(205, $color->g);
        $this->assertSame(0, $color->b);
    }

    public function testParseBrightRed(): void
    {
        $color = Color::parse('bright-red');
        $this->assertSame(255, $color->r);
        $this->assertSame(0, $color->g);
        $this->assertSame(0, $color->b);
    }

    public function testParseBrightGreen(): void
    {
        $color = Color::parse('bright-green');
        $this->assertSame(0, $color->r);
        $this->assertSame(255, $color->g);
        $this->assertSame(0, $color->b);
    }

    public function testParseBrightBlue(): void
    {
        $color = Color::parse('bright-blue');
        $this->assertSame(92, $color->r);
        $this->assertSame(92, $color->g);
        $this->assertSame(255, $color->b);
    }

    public function testParseBrightWhite(): void
    {
        $color = Color::parse('bright-white');
        $this->assertSame(255, $color->r);
        $this->assertSame(255, $color->g);
        $this->assertSame(255, $color->b);
    }
}
