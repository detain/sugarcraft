<?php

declare(strict_types=1);

namespace SugarCraft\Image\Tests\Renderer;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use SugarCraft\Core\Util\Ansi;
use SugarCraft\Image\Renderer\SixelRenderer;

/**
 * F-09: the band encoder's `!N`-splitting rule has ONE implementation —
 * sugar-core's {@see Ansi::sixelRle()} — and SixelRenderer::emitRle()
 * delegates to it. This pin replays the private emitter across the mask and
 * run-length space and demands byte equality with the shared home, so a
 * re-inlined copy with a different threshold/chunk rule reddens immediately.
 *
 * Byte-frozen stream equivalence is the SixelStreamTest/SixelIntensityTest
 * digest family's job; this file pins the WIRING.
 */
final class SixelRleParityTest extends TestCase
{
    /** Six-bit masks exercised: none, single low bits, a mid mask, all six rows. */
    private const MASKS = [0, 1, 0x15, 0x2A, 0x3F];

    private static function emitter(): \Closure
    {
        $method = new ReflectionMethod(SixelRenderer::class, 'emitRle');
        $renderer = (new ReflectionClass(SixelRenderer::class))->newInstanceWithoutConstructor();

        return static fn (int $bits, int $count): string => $method->invoke($renderer, $bits, $count);
    }

    public function testTheBandEncoderDelegatesToTheSharedEmitter(): void
    {
        $emit = self::emitter();

        foreach (self::MASKS as $bits) {
            $expected = '';
            $actual = '';
            for ($count = 0; $count <= 3000; $count++) {
                $expected .= Ansi::sixelRle($bits, $count) . '|';
                $actual .= $emit($bits, $count) . '|';
            }

            $this->assertSame(
                $expected,
                $actual,
                "emitRle mask {$bits} diverged from Ansi::sixelRle across runs 0..3000",
            );
        }
    }

    public function testEveryRepeatTokenTheBandEncoderEmitsIsLegalSixel(): void
    {
        $emit = self::emitter();

        foreach ([4, 5, 254, 255, 256, 509, 510, 511, 3000] as $count) {
            $encoded = $emit(0x2A, $count);
            $matches = [];
            preg_match_all('/!\d+/', $encoded, $matches);

            foreach ($matches[0] as $repeat) {
                $this->assertMatchesRegularExpression(
                    '/^!([1-9]|[1-9][0-9]|1[0-9]{2}|2[0-4][0-9]|25[0-5])$/',
                    $repeat,
                    "illegal sixel repeat token {$repeat} for run {$count}",
                );
            }

            // Runs of 1..3 are bare literals; longer runs carry a count token.
            if ($count >= 4) {
                $this->assertNotSame([], $matches[0], "run {$count} carried no repeat token");
            }
        }
    }
}
