<?php

declare(strict_types=1);

namespace SugarCraft\Vcr\Tests\Raster;

use PHPUnit\Framework\TestCase;
use SugarCraft\Vcr\Raster\ImagickRasterizer;
use SugarCraft\Vt\Theme;

/**
 * Regression: ImagickRasterizer::indexToHex returns the proper grayscale
 * RGB for xterm-256 grayscale palette indices (232..255).
 *
 * Bug fixed in d070e742: the old `str_repeat(dechex(8), 6)` produced
 * `#888888` for index 232 (an "8" repeated six times, which reads as
 * RGB 136,136,136). The fix routes through `Theme::color()` so the
 * gray value comes from the standard xterm `(index-232) * 10 + 8`
 * formula — index 232 → 8,8,8 → `#080808`.
 *
 * Calls indexToHex via reflection since the method is private.
 */
final class ImagickRasterizerGrayscaleTest extends TestCase
{
    public function testIndex232ReturnsSevenCharHexWithGray8(): void
    {
        if (!extension_loaded('imagick')) {
            $this->markTestSkipped('imagick extension not available');
        }

        $rasterizer = new ImagickRasterizer(14, 'JetBrainsMono', Theme::tokyoNight());
        $hex = $this->invokeIndexToHex($rasterizer, 232);

        $this->assertMatchesRegularExpression('/^#[0-9a-f]{6}$/', $hex, "indexToHex(232) must be 7-char hex; got '{$hex}'");
        $this->assertSame('#080808', $hex, 'index 232 must map to grayscale rgb(8,8,8) per xterm-256 spec');
    }

    public function testIndex250ReturnsSevenCharHexWithGray188(): void
    {
        if (!extension_loaded('imagick')) {
            $this->markTestSkipped('imagick extension not available');
        }

        $rasterizer = new ImagickRasterizer(14, 'JetBrainsMono', Theme::tokyoNight());
        $hex = $this->invokeIndexToHex($rasterizer, 250);

        $this->assertMatchesRegularExpression('/^#[0-9a-f]{6}$/', $hex);
        // 250: (250-232)*10 + 8 = 188 → 0xbc.
        $this->assertSame('#bcbcbc', $hex, 'index 250 must map to grayscale rgb(188,188,188)');
    }

    private function invokeIndexToHex(ImagickRasterizer $rasterizer, int $index): string
    {
        $m = new \ReflectionMethod($rasterizer, 'indexToHex');
        $m->setAccessible(true);
        return (string) $m->invoke($rasterizer, $index);
    }
}
