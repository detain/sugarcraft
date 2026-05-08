<?php

declare(strict_types=1);

namespace SugarCraft\Vt\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Vt\Color\Color;
use SugarCraft\Vt\Sgr\Sgr;

final class SgrTest extends TestCase
{
    public function testEmpty(): void
    {
        $s = Sgr::empty();
        $this->assertFalse($s->bold);
        $this->assertFalse($s->italic);
        $this->assertFalse($s->underline);
        $this->assertFalse($s->strikethrough);
        $this->assertFalse($s->blink);
        $this->assertFalse($s->reverse);
        $this->assertFalse($s->dim);
        $this->assertFalse($s->hidden);
        $this->assertFalse($s->invisible);
        $this->assertNull($s->foreground);
        $this->assertNull($s->background);
    }

    public function testWithBold(): void
    {
        $s = Sgr::empty()->withBold(true);
        $this->assertTrue($s->bold);
        $other = $s->withBold(false);
        $this->assertFalse($other->bold);
    }

    public function testWithItalic(): void
    {
        $s = Sgr::empty()->withItalic(true);
        $this->assertTrue($s->italic);
    }

    public function testWithUnderline(): void
    {
        $s = Sgr::empty()->withUnderline(true);
        $this->assertTrue($s->underline);
    }

    public function testWithStrikethrough(): void
    {
        $s = Sgr::empty()->withStrikethrough(true);
        $this->assertTrue($s->strikethrough);
    }

    public function testWithBlink(): void
    {
        $s = Sgr::empty()->withBlink(true);
        $this->assertTrue($s->blink);
    }

    public function testWithReverse(): void
    {
        $s = Sgr::empty()->withReverse(true);
        $this->assertTrue($s->reverse);
    }

    public function testWithDim(): void
    {
        $s = Sgr::empty()->withDim(true);
        $this->assertTrue($s->dim);
    }

    public function testWithHidden(): void
    {
        $s = Sgr::empty()->withHidden(true);
        $this->assertTrue($s->hidden);
    }

    public function testWithForeground(): void
    {
        $fg = Color::indexed16(1);
        $s = Sgr::empty()->withForeground($fg);
        $this->assertNotNull($s->foreground);
        $this->assertTrue($s->foreground->equals($fg));
    }

    public function testWithBackground(): void
    {
        $bg = Color::truecolor(0, 0, 0);
        $s = Sgr::empty()->withBackground($bg);
        $this->assertNotNull($s->background);
        $this->assertTrue($s->background->equals($bg));
    }

    public function testEquals(): void
    {
        $a = Sgr::empty()->withBold(true)->withForeground(Color::indexed16(1));
        $b = Sgr::empty()->withBold(true)->withForeground(Color::indexed16(1));
        $c = Sgr::empty()->withBold(false)->withForeground(Color::indexed16(1));
        $this->assertTrue($a->equals($b));
        $this->assertFalse($a->equals($c));
    }
}
