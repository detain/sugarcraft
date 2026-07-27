<?php

declare(strict_types=1);

namespace SugarCraft\Reel\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Reel\Render\Color;

/**
 * Unit tests for Color RGB packing utility.
 *
 * @covers \SugarCraft\Reel\Render\Color
 */
final class ColorTest extends TestCase
{
    /**
     * @testdox pack() returns a 24-bit integer in 0xRRGGBB format
     */
    public function testPackReturnsInteger(): void
    {
        $packed = Color::pack(0, 0, 0);
        $this->assertIsInt($packed);
    }

    /**
     * @testdox pack(0,0,0) returns 0x000000 (black)
     */
    public function testPackBlackIsZero(): void
    {
        $this->assertSame(0x000000, Color::pack(0, 0, 0));
    }

    /**
     * @testdox pack(255,255,255) returns 0xFFFFFF (white)
     */
    public function testPackWhiteIsFFFFFF(): void
    {
        $this->assertSame(0xFFFFFF, Color::pack(255, 255, 255));
    }

    /**
     * @testdox pack() correctly positions R in bits 16-23, G in 8-15, B in 0-7
     */
    public function testPackPositionsRgbCorrectly(): void
    {
        // Pure red: R=0xAB, G=0, B=0 → 0xAB0000
        $this->assertSame(0xAB0000, Color::pack(0xAB, 0, 0));

        // Pure green: R=0, G=0xCD, B=0 → 0x00CD00
        $this->assertSame(0x00CD00, Color::pack(0, 0xCD, 0));

        // Pure blue: R=0, G=0, B=0xEF → 0x0000EF
        $this->assertSame(0x0000EF, Color::pack(0, 0, 0xEF));
    }

    /**
     * @testdox pack(255,0,0) returns 0xFF0000 (red)
     */
    public function testPackPureRed(): void
    {
        $this->assertSame(0xFF0000, Color::pack(255, 0, 0));
    }

    /**
     * @testdox pack(0,255,0) returns 0x00FF00 (green)
     */
    public function testPackPureGreen(): void
    {
        $this->assertSame(0x00FF00, Color::pack(0, 255, 0));
    }

    /**
     * @testdox pack(0,0,255) returns 0x0000FF (blue)
     */
    public function testPackPureBlue(): void
    {
        $this->assertSame(0x0000FF, Color::pack(0, 0, 255));
    }

    /**
     * @testdox pack() masks each component to 0xFF before shifting
     */
    public function testPackMasksComponents(): void
    {
        // Values above 255 are masked to low 8 bits
        // 0x1FF = 511, masked to 0xFF = 255, shifted left 16 bits for R = 0xFF0000
        $this->assertSame(0xFF0000, Color::pack(0x1FF, 0, 0));
        $this->assertSame(0xFF0000, Color::pack(0x100 + 0xFF, 0, 0));
        $this->assertSame(0x00FF00, Color::pack(0, 0x1FF, 0));
        $this->assertSame(0x0000FF, Color::pack(0, 0, 0x1FF));

        // Negative values are bitwise-masked: -256 & 0xFF = 0
        $this->assertSame(0x000000, Color::pack(-256, 0, 0));
        // -1 in two's complement is all bits set: -1 & 0xFF = 255
        $this->assertSame(0xFF0000, Color::pack(-1, 0, 0));
    }

    /**
     * @testdox pack() produces the same result as manual bit shifting
     */
    public function testPackMatchesManualShift(): void
    {
        for ($r = 0; $r <= 255; $r += 51) {
            for ($g = 0; $g <= 255; $g += 51) {
                for ($b = 0; $b <= 255; $b += 51) {
                    $expected = (($r & 0xFF) << 16) | (($g & 0xFF) << 8) | ($b & 0xFF);
                    $this->assertSame($expected, Color::pack($r, $g, $b));
                }
            }
        }
    }
}
