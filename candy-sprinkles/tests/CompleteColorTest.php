<?php

declare(strict_types=1);

namespace SugarCraft\Sprinkles\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Sprinkles\CompleteColor;
use SugarCraft\Core\Util\Color;
use SugarCraft\Core\Util\ColorProfile;

final class CompleteColorTest extends TestCase
{
    public function testConstruction(): void
    {
        $tc = Color::hex('#ff0000');
        $a256 = Color::hex('#ff0000');
        $ansi = Color::hex('#f00');
        $cc = new CompleteColor($tc, $a256, $ansi);
        $this->assertInstanceOf(CompleteColor::class, $cc);
        $this->assertSame($tc, $cc->trueColor);
        $this->assertSame($a256, $cc->ansi256);
        $this->assertSame($ansi, $cc->ansi);
    }

    public function testPickTrueColor(): void
    {
        $cc = new CompleteColor(
            Color::hex('#ff0000'),
            Color::hex('#f00000'),
            Color::hex('#aa0000'),
        );
        $result = $cc->pick(ColorProfile::TrueColor);
        $this->assertSame($cc->trueColor, $result);
    }

    public function testPickAnsi256(): void
    {
        $cc = new CompleteColor(
            Color::hex('#ff0000'),
            Color::hex('#f00000'),
            Color::hex('#aa0000'),
        );
        $result = $cc->pick(ColorProfile::Ansi256);
        $this->assertSame($cc->ansi256, $result);
    }

    public function testPickAnsi(): void
    {
        $cc = new CompleteColor(
            Color::hex('#ff0000'),
            Color::hex('#f00000'),
            Color::hex('#aa0000'),
        );
        $result = $cc->pick(ColorProfile::Ansi);
        $this->assertSame($cc->ansi, $result);
    }

    public function testPickAscii(): void
    {
        $cc = new CompleteColor(
            Color::hex('#ff0000'),
            Color::hex('#f00000'),
            Color::hex('#aa0000'),
        );
        $result = $cc->pick(ColorProfile::Ascii);
        $this->assertSame($cc->ansi, $result);
    }

    public function testReadonlyProperties(): void
    {
        $tc = Color::hex('#111111');
        $a256 = Color::hex('#222222');
        $ansi = Color::hex('#333333');
        $cc = new CompleteColor($tc, $a256, $ansi);
        $this->assertSame($tc, $cc->trueColor);
        $this->assertSame($a256, $cc->ansi256);
        $this->assertSame($ansi, $cc->ansi);
    }

    public function testInstancesAreIndependent(): void
    {
        $cc1 = new CompleteColor(Color::hex('#111'), Color::hex('#222'), Color::hex('#333'));
        $cc2 = new CompleteColor(Color::hex('#111'), Color::hex('#222'), Color::hex('#333'));
        $this->assertNotSame($cc1, $cc2);
    }
}
