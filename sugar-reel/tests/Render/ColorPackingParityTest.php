<?php

declare(strict_types=1);

namespace SugarCraft\Reel\Tests\Render;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use SugarCraft\Reel\Decode\RgbFrame;
use SugarCraft\Reel\Player;
use SugarCraft\Reel\Render\AsciiRenderer;
use SugarCraft\Reel\Render\Color;
use SugarCraft\Reel\Render\Mode;

/**
 * Parity guard for finding #44: the terminal colour path and the GD export path
 * must be the SAME packing implementation, not two copies that drift.
 *
 * Both call sites are pinned against `Color::pack()` AND against the verbatim
 * legacy inline expression that used to be duplicated in each of them, so this
 * test would fail if either site re-inlined a subtly different formula.
 *
 * @covers \SugarCraft\Reel\Render\Color
 */
final class ColorPackingParityTest extends TestCase
{
    /**
     * The expression as it stood in `Player::rgbToStyleColor()` before extraction.
     */
    private static function legacyPack(int $r, int $g, int $b): int
    {
        return (($r & 0xFF) << 16) | (($g & 0xFF) << 8) | ($b & 0xFF);
    }

    /**
     * @return array<string, array{int, int, int}>
     */
    public static function cornerAndEdgeColors(): array
    {
        $cases = [
            'black' => [0, 0, 0],
            'white' => [255, 255, 255],
            'red' => [255, 0, 0],
            'green' => [0, 255, 0],
            'blue' => [0, 0, 255],
            'cyan' => [0, 255, 255],
            'magenta' => [255, 0, 255],
            'yellow' => [255, 255, 0],
            'near-black' => [1, 1, 1],
            'near-white' => [254, 254, 254],
            'mid-grey' => [127, 127, 127],
            'sign-bit-grey' => [128, 128, 128],
            'r-edge-only' => [255, 128, 1],
            'g-edge-only' => [1, 255, 128],
            'b-edge-only' => [128, 1, 255],
        ];

        // Diagonal ramp: every 17th value, so 16 grey steps land exactly on 0 and 255.
        for ($v = 0; $v <= 255; $v += 17) {
            $cases['grey-' . $v] = [$v, $v, $v];
        }

        return $cases;
    }

    #[DataProvider('cornerAndEdgeColors')]
    public function testPlayerStyleColorMatchesSharedHelper(int $r, int $g, int $b): void
    {
        $method = new ReflectionMethod(Player::class, 'rgbToStyleColor');
        $method->setAccessible(true);

        foreach ([Mode::TrueColor, Mode::HalfBlock, Mode::Ansi256] as $mode) {
            self::assertSame(
                Color::pack($r, $g, $b),
                $method->invoke(null, $r, $g, $b, $mode),
                sprintf('Player::rgbToStyleColor(%d,%d,%d, %s)', $r, $g, $b, $mode->name),
            );
            // And the shared value must be the legacy value, not merely shared: this
            // is the half that fails if `pack()` itself drifts (see the toGd test below).
            self::assertSame(
                self::legacyPack($r, $g, $b),
                $method->invoke(null, $r, $g, $b, $mode),
                sprintf('rgbToStyleColor(%d,%d,%d, %s) must equal the pre-extraction expression', $r, $g, $b, $mode->name),
            );
        }

        self::assertSame(self::legacyPack($r, $g, $b), Color::pack($r, $g, $b));
    }

    #[DataProvider('cornerAndEdgeColors')]
    public function testRgbFrameToGdMatchesSharedHelper(int $r, int $g, int $b): void
    {
        if (!extension_loaded('gd')) {
            self::markTestSkipped('ext-gd required for the GD packing path');
        }

        $frame = new RgbFrame(chr($r) . chr($g) . chr($b), 1, 1);
        $img = $frame->toGd();

        // imagecolorat() on a truecolor image yields 0x00RRGGBB — the same layout
        // the legacy expression produces, so the comparison is direct.
        //
        // NOTE the deliberate choice of RIGHT-hand side: `toGd()` now paints through
        // `Color::pack()` itself, so asserting `Color::pack(...) === imagecolorat(...)`
        // would compare a value with a copy of itself and pass no matter how pack() is
        // written (verified: corrupting pack()'s green shift keeps it green). The
        // assertion that actually pins #44 is the one against the *legacy* formula —
        // the pre-extraction output — because nothing in the new code path derives from it.
        self::assertSame(
            self::legacyPack($r, $g, $b),
            imagecolorat($img, 0, 0) & 0xFFFFFF,
            sprintf('RgbFrame::toGd() pixel (0,0) for RGB(%d,%d,%d)', $r, $g, $b),
        );

        imagedestroy($img);
    }

    public function testAsciiModeContributesNoColor(): void
    {
        $method = new ReflectionMethod(Player::class, 'rgbToStyleColor');
        $method->setAccessible(true);

        self::assertNull($method->invoke(null, 255, 128, 0, Mode::Ascii));
    }

    /**
     * Round-1 review M5: `AsciiRenderer` was the last site still re-inlining the
     * packing expression (its TrueColor dedup sentinel `$fg`/`$lastFg`). The inputs
     * come from `ord()` today, so masked and unmasked agree — but that is precisely
     * the silent drift #44 exists to kill. This leg pins the sentinel against
     * `Color::pack()` with an OUT-OF-BYTE CHANNEL: only the masked formula makes
     * `emitColorCode()` recognise its own previous colour, so reverting the site to
     * `($r << 16) | ($g << 8) | $b` turns this RED.
     */
    public function testAsciiRendererSentinelUsesSharedPack(): void
    {
        $emit = new ReflectionMethod(AsciiRenderer::class, 'emitColorCode');
        $emit->setAccessible(true);
        $renderer = new AsciiRenderer();

        // In-range: dedup works under any formula — this is the contract, not the discriminator.
        self::assertSame(
            '',
            $emit->invoke($renderer, 255, 128, 1, Color::pack(255, 128, 1)),
            'the SGR must be suppressed when lastFg equals the packed colour',
        );

        // Out-of-byte channel: pack() masks (300 → 0x2C0000); an unmasked re-inline
        // would compute 0x12C0000, miss the equality, and emit a redundant SGR.
        self::assertSame(
            Color::pack(300, 0, 0),
            0x2C0000,
            'the shared helper masks each channel to one byte',
        );
        self::assertSame(
            '',
            $emit->invoke($renderer, 300, 0, 0, Color::pack(300, 0, 0)),
            'emitColorCode() must dedup on Color::pack() exactly, not on a private re-derivation',
        );

        // And a genuinely new colour still emits — the dedup is not a blanket ''.
        self::assertSame(
            "\x1b[38;2;10;20;30m",
            $emit->invoke($renderer, 10, 20, 30, Color::pack(30, 20, 10)),
        );
    }
}
