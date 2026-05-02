<?php

declare(strict_types=1);

namespace CandyCore\Charts\Tests\Picture;

use CandyCore\Charts\Picture\Sixel;
use CandyCore\Core\Util\Color;
use PHPUnit\Framework\TestCase;

final class SixelTest extends TestCase
{
    public function testEmptyGridReturnsEmpty(): void
    {
        $this->assertSame('', Sixel::encode([]));
    }

    public function testEmitsDcsHeaderAndTerminator(): void
    {
        $pixels = [[Color::rgb(255, 0, 0)]];
        $bytes = Sixel::encode($pixels);
        $this->assertStringStartsWith("\x1bPq", $bytes);
        $this->assertStringEndsWith("\x1b\\", $bytes);
    }

    public function testEmitsPaletteEntries(): void
    {
        $pixels = [[Color::rgb(255, 0, 0)]];
        $bytes = Sixel::encode($pixels);
        // First palette entry is ANSI black: #0;2;0;0;0
        $this->assertStringContainsString('#0;2;0;0;0', $bytes);
        // ANSI red: 205,0,0 → 80,0,0 in 0-100 scale.
        $this->assertStringContainsString('#1;2;80;0;0', $bytes);
    }

    public function testSixelDataBytesAreInValidRange(): void
    {
        $pixels = [];
        for ($r = 0; $r < 6; $r++) {
            $row = [];
            for ($c = 0; $c < 4; $c++) {
                $row[] = Color::rgb(255, 255, 255);
            }
            $pixels[] = $row;
        }
        $bytes = Sixel::encode($pixels);
        // Strip the DCS envelope and palette prelude.
        $body = substr($bytes, 3, -2);
        // Every sixel data byte must be in 0x3f-0x7e (or '#' / '$' / '-').
        $len = strlen($body);
        for ($i = 0; $i < $len; $i++) {
            $b = ord($body[$i]);
            $valid = $b === 0x23   /* # */
                  || $b === 0x24   /* $ */
                  || $b === 0x2d   /* - */
                  || ($b >= 0x30 && $b <= 0x39)   /* 0-9 */
                  || $b === 0x3b   /* ; */
                  || ($b >= 0x3f && $b <= 0x7e); /* sixel data */
            $this->assertTrue($valid, "byte at $i (0x" . dechex($b) . ") is not a valid sixel byte");
        }
    }

    public function testMultipleStripesEmitSeparator(): void
    {
        // 7 rows = 2 stripes (6 + 1).
        $pixels = [];
        for ($r = 0; $r < 7; $r++) {
            $pixels[] = [Color::rgb(0, 0, 0)];
        }
        $bytes = Sixel::encode($pixels);
        $this->assertStringContainsString('-', $bytes);
    }

    public function testRespectsPaletteSize(): void
    {
        $pixels = [];
        for ($r = 0; $r < 3; $r++) {
            $row = [];
            for ($c = 0; $c < 8; $c++) {
                $row[] = Color::rgb(min(255, 32 * $c), min(255, 64 * $r), 128);
            }
            $pixels[] = $row;
        }
        $smallPalette = Sixel::encode($pixels, 4);
        $largePalette = Sixel::encode($pixels, 64);
        // Larger palette → more `#idx;...` entries → longer output.
        $this->assertGreaterThan(strlen($smallPalette), strlen($largePalette));
    }
}
