<?php

declare(strict_types=1);

namespace SugarCraft\Charts\Tests\Picture;

use SugarCraft\Charts\Picture\Picture;
use SugarCraft\Charts\Picture\Protocol;
use SugarCraft\Charts\Picture\Sixel;
use SugarCraft\Core\Util\Color;
use PHPUnit\Framework\TestCase;

final class SixelTest extends TestCase
{
    public function testEmptyGridPictureShowsPlaceholderNotSilence(): void
    {
        // sugar-charts-102: the encoder's '' must be routed to the visible
        // placeholder by Picture — a Sixel terminal must never get a no-op frame.
        $view = Picture::fromGrid([])->withProtocol(Protocol::Sixel)->view();
        $this->assertNotSame('', $view);
        $this->assertStringNotContainsString("\x1bPq", $view);
        $this->assertStringContainsString('empty', $view);
        $raggedVoid = Picture::fromGrid([[], []])->withProtocol(Protocol::Sixel)->view();
        $this->assertNotSame('', $raggedVoid);
    }

    /**
     * Independent naive oracle for the stripe emission (the pre-CH-24 scan):
     * re-walks every column per color. Byte-equality over a checkerboard and
     * a ragged grid pins the single-pass mask rewrite (sugar-charts-038).
     *
     * @param list<list<Color>> $pixels
     */
    private static function naiveEncode(array $pixels, int $paletteSize): string
    {
        $ref = new \ReflectionMethod(Sixel::class, 'buildPalette');
        $ref->setAccessible(true);
        [$palette, $paletteCount] = $ref->invoke(null, $paletteSize);
        $near = new \ReflectionMethod(Sixel::class, 'nearest');
        $near->setAccessible(true);

        $rows = count($pixels);
        $cols = max(array_map('count', $pixels));
        $quant = [];
        for ($r = 0; $r < $rows; $r++) {
            $rowQ = [];
            for ($c = 0; $c < $cols; $c++) {
                $px = $pixels[$r][$c] ?? null;
                $rowQ[] = $px === null ? 0 : $near->invoke(null, $px, $palette, $paletteCount);
            }
            $quant[] = $rowQ;
        }
        $out = "\x1bPq";
        foreach ($palette as $idx => [$r, $g, $b]) {
            if ($idx >= $paletteCount) {
                break;
            }
            $out .= sprintf(
                '#%d;2;%d;%d;%d',
                $idx,
                (int) round($r * 100 / 255),
                (int) round($g * 100 / 255),
                (int) round($b * 100 / 255),
            );
        }
        for ($s = 0; $s < $rows; $s += 6) {
            $e = min($s + 6, $rows);
            $colors = [];
            for ($r = $s; $r < $e; $r++) {
                foreach ($quant[$r] as $idx) {
                    $colors[$idx] = true;
                }
            }
            $list = array_keys($colors);
            sort($list);
            foreach ($list as $j => $idx) {
                if ($j > 0) {
                    $out .= '$';
                }
                $out .= '#' . $idx;
                for ($c = 0; $c < $cols; $c++) {
                    $bits = 0;
                    for ($k = 0; $k < $e - $s; $k++) {
                        if ($quant[$s + $k][$c] === $idx) {
                            $bits |= 1 << $k;
                        }
                    }
                    $out .= chr(0x3f + $bits);
                }
            }
            $out .= '-'; // the deliberate trailing-LF choice of CH-24 applies to both sides
        }
        return $out . "\x1b\\";
    }

    /**
     * @return list<list<Color>>
     */
    private static function grid(int $rows, int $cols, bool $ragged = false): array
    {
        $g = [];
        for ($r = 0; $r < $rows; $r++) {
            $row = [];
            $limit = $ragged && $r % 3 === 2 ? $cols - 4 : $cols;
            for ($c = 0; $c < $limit; $c++) {
                $row[] = Color::rgb(($r * 37 + $c * 11) % 256, ($c * 53) % 256, ($r * $c * 7) % 256);
            }
            $g[] = $row;
        }
        return $g;
    }

    public function testSinglePassIsByteIdenticalToNaiveWalk(): void
    {
        foreach ([self::grid(24, 64), self::grid(24, 64, true), self::grid(7, 3)] as $i => $grid) {
            foreach ([16, 64] as $size) {
                $this->assertSame(
                    self::naiveEncode($grid, $size),
                    Sixel::encode($grid, $size),
                    "mask rewrite diverged (fixture {$i}, palette {$size})",
                );
            }
        }
    }

    public function testEveryStripeEndsWithNewlineFeeder(): void
    {
        $one = Sixel::encode([[Color::rgb(255, 0, 0)]]);
        $this->assertStringEndsWith("-\x1b\\", $one, 'final stripe must carry the LF (sugar-charts-116)');
        $two = Sixel::encode(self::grid(9, 5));
        $this->assertSame(2, substr_count($two, '-'));
        $this->assertStringEndsWith("-\x1b\\", $two);
    }

    public function testSmallPaletteSpreadsToWhiteNotDarkPrefix(): void
    {
        $bytes = Sixel::encode([[Color::rgb(255, 255, 255)]], 4);
        // Rung 3 of a 4-step spread is bright white (sugar-charts-117);
        // the old prefix slice would have topped out at dark yellow.
        $this->assertStringContainsString('#3;2;100;100;100', $bytes);
        $this->assertStringContainsString('#0;2;0;0;0', $bytes);
    }

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
