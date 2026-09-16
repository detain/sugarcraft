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

    /**
     * @testdox pack() matches the pre-extraction inline formula on every corner and channel edge
     *
     * Finding #44 collapsed duplicated packing expressions into one helper. The oracle here is
     * the verbatim old inline expression, sampled at the lattice where a shift/mask bug can hide:
     * 0 and 255 (both extremes), 1 and 254 (off-by-one), 127/128 (the sign bit of a signed byte),
     * 63/64 and 191/192 (the bit-boundary neighbours). 9 values cubed = 729 triples.
     */
    public function testPackMatchesLegacyFormulaAcrossCornerAndEdgeLattice(): void
    {
        $lattice = [0, 1, 63, 64, 127, 128, 191, 254, 255];
        $legacy = static fn (int $r, int $g, int $b): int => (($r & 0xFF) << 16) | (($g & 0xFF) << 8) | ($b & 0xFF);

        foreach ($lattice as $r) {
            foreach ($lattice as $g) {
                foreach ($lattice as $b) {
                    $this->assertSame(
                        $legacy($r, $g, $b),
                        Color::pack($r, $g, $b),
                        sprintf('pack(%d,%d,%d) must equal the legacy shift expression', $r, $g, $b),
                    );
                }
            }
        }
    }

    /**
     * @testdox pack() spans the whole 0x000000-0xFFFFFF range without collision
     */
    public function testPackCoversFullRangeWithoutCollision(): void
    {
        // Every 7919th value (7919 is prime, so the walk cycles all three channels
        // out of phase instead of re-visiting diagonals) plus both endpoints.
        $legacy = static fn (int $v): int => ((($v >> 16) & 0xFF) << 16) | ((($v >> 8) & 0xFF) << 8) | ($v & 0xFF);
        $seen = [];

        for ($value = 0; $value <= 0xFFFFFF; $value += 7919) {
            $packed = Color::pack($value >> 16, ($value >> 8) & 0xFF, $value & 0xFF);
            $this->assertSame($legacy($value), $packed, sprintf('round-trip of 0x%06X', $value));
            $this->assertArrayNotHasKey($packed, $seen, sprintf('0x%06X collided with an earlier packing', $value));
            $seen[$packed] = true;
        }

        $this->assertSame(0xFFFFFF, Color::pack(255, 255, 255));
        $this->assertGreaterThan(2000, count($seen), 'the stride walk must sample the whole range');
    }
}
