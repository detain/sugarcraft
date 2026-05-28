<?php

declare(strict_types=1);

namespace SugarCraft\Buffer\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Buffer\Style;

/**
 * @covers \SugarCraft\Buffer\Style
 */
final class StyleTest extends TestCase
{
    public function testNewDefault(): void
    {
        $style = Style::new();

        $this->assertNull($style->fg());
        $this->assertNull($style->bg());
        $this->assertSame(0, $style->attrs());
    }

    public function testNewWithColors(): void
    {
        $style = Style::new(0xff0000, 0x0000ff);

        $this->assertSame(0xff0000, $style->fg());
        $this->assertSame(0x0000ff, $style->bg());
    }

    public function testBold(): void
    {
        $style = Style::bold();

        $this->assertTrue($style->hasBold());
        $this->assertFalse($style->hasItalic());
        $this->assertFalse($style->hasUnderline());
        $this->assertFalse($style->hasStrike());
        $this->assertFalse($style->hasFaint());
        $this->assertFalse($style->hasBlink());
        $this->assertFalse($style->hasReverse());
        $this->assertFalse($style->hasOverline());
        $this->assertFalse($style->hasInvisible());
    }

    public function testReverse(): void
    {
        $style = Style::reverse();

        $this->assertFalse($style->hasBold());
        $this->assertTrue($style->hasReverse());
    }

    public function testHasBold(): void
    {
        $style = Style::new(null, null, Style::ATTR_BOLD);
        $this->assertTrue($style->hasBold());

        $style = Style::new();
        $this->assertFalse($style->hasBold());
    }

    public function testHasItalic(): void
    {
        $style = Style::new(null, null, Style::ATTR_ITALIC);
        $this->assertTrue($style->hasItalic());

        $style = Style::new();
        $this->assertFalse($style->hasItalic());
    }

    public function testHasUnderline(): void
    {
        $style = Style::new(null, null, Style::ATTR_UNDERLINE);
        $this->assertTrue($style->hasUnderline());

        $style = Style::new();
        $this->assertFalse($style->hasUnderline());
    }

    public function testHasStrike(): void
    {
        $style = Style::new(null, null, Style::ATTR_STRIKE);
        $this->assertTrue($style->hasStrike());

        $style = Style::new();
        $this->assertFalse($style->hasStrike());
    }

    public function testHasFaint(): void
    {
        $style = Style::new(null, null, Style::ATTR_FAINT);
        $this->assertTrue($style->hasFaint());

        $style = Style::new();
        $this->assertFalse($style->hasFaint());
    }

    public function testHasBlink(): void
    {
        $style = Style::new(null, null, Style::ATTR_BLINK);
        $this->assertTrue($style->hasBlink());

        $style = Style::new();
        $this->assertFalse($style->hasBlink());
    }

    public function testHasReverse(): void
    {
        $style = Style::new(null, null, Style::ATTR_REVERSE);
        $this->assertTrue($style->hasReverse());

        $style = Style::new();
        $this->assertFalse($style->hasReverse());
    }

    public function testHasOverline(): void
    {
        $style = Style::new(null, null, Style::ATTR_OVERLINE);
        $this->assertTrue($style->hasOverline());

        $style = Style::new();
        $this->assertFalse($style->hasOverline());
    }

    public function testHasInvisible(): void
    {
        $style = Style::new(null, null, Style::ATTR_INVISIBLE);
        $this->assertTrue($style->hasInvisible());

        $style = Style::new();
        $this->assertFalse($style->hasInvisible());
    }

    public function testMultipleAttributes(): void
    {
        $style = Style::new(null, null, Style::ATTR_BOLD | Style::ATTR_ITALIC | Style::ATTR_UNDERLINE);

        $this->assertTrue($style->hasBold());
        $this->assertTrue($style->hasItalic());
        $this->assertTrue($style->hasUnderline());
        $this->assertFalse($style->hasStrike());
    }
}
