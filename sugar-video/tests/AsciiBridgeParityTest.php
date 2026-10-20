<?php

declare(strict_types=1);

namespace SugarCraft\Video\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Image\ImageSource;
use SugarCraft\Image\Renderer\AsciiColorMode;
use SugarCraft\Image\Renderer\AsciiRenderer as ImageAsciiRenderer;
use SugarCraft\Video\Decode\RgbFrame;
use SugarCraft\Video\Render\AsciiRenderer;
use SugarCraft\Video\Render\LumaRamp;
use SugarCraft\Video\Render\Mode;

/**
 * A-11: the sugar-video AsciiRenderer is a bridge, not a fork.
 *
 * Per the façade law this file does NOT re-run the canonical sugar-image
 * suite. It pins the bridge wiring only, on four axes:
 *
 *  1. byte parity — the video path and a direct canonical reference render
 *     the same bytes across the whole Mode × ramp surface;
 *  2. the A-06 route — the static Mode→AsciiColorMode projection;
 *  3. ramp threading — Reel::withRamp('minimal'|'dense') still changes the
 *     glyphs after the old emitters were deleted;
 *  4. the loud truncation contract — undersized buffers now throw where the
 *     legacy copy painted a silent black column.
 *
 * Canonical behaviour itself (quantizer, pen-delta, scoped reset) is tested
 * in sugar-image/tests/Renderer/; duplicating it here would be the copied
 * suite the façade law forbids.
 *
 * @covers \SugarCraft\Video\Render\AsciiRenderer
 */
final class AsciiBridgeParityTest extends TestCase
{
    // 3×3 synthetic frame: black,red,green / blue,yellow,magenta / cyan,white,grey64.
    private const SYNTHETIC_BYTES =
        "\x00\x00\x00\xff\x00\x00\x00\xff\x00"
        . "\x00\x00\xff\xff\xff\x00\xff\x00\xff"
        . "\x00\xff\xff\xff\xff\xff\x40\x40\x40";

    /**
     * Canonical bytes the bridge must emit for the 3×3 frame, recorded from a
     * direct sugar-image render (A-01 pen-delta, scoped 39m reset, "\n" join,
     * round-to-nearest ramp). The legacy fork emitted different bytes: it is
     * superseded, so the canonical output is the golden from here on.
     */
    private const CANONICAL_MONO_B64 = 'IDtmCiwwMQpDQDs=';

    private const CANONICAL_ANSI256_B64 =
        'IBtbMzg7NTsxOTZtOxtbMzg7NTs0Nm1mG1szOW0KG1szODs1OzIxbSwbWzM4OzU7MjI2bTAbWzM4OzU7MjAxbTEbWzM5bQobWzM4OzU7NTFtQxtbMzg7NTsyMzFtQBtbMzg7NTsyMzhtOxtbMzlt';

    private const CANONICAL_TRUECOLOR_B64 =
        'IBtbMzg7MjsyNTU7MDswbTsbWzM4OzI7MDsyNTU7MG1mG1szOW0KG1szODsyOzA7MDsyNTVtLBtbMzg7MjsyNTU7MjU1OzBtMBtbMzg7MjsyNTU7MDsyNTVtMRtbMzltChtbMzg7MjswOzI1NTsyNTVtQxtbMzg7MjsyNTU7MjU1OzI1NW1AG1szODsyOzY0OzY0OzY0bTsbWzM5bQ==';

    /**
     * @return array<string, array{Mode, string, string, AsciiColorMode}>
     */
    public static function bridgeSurface(): array
    {
        return [
            'ascii/standard' => [Mode::Ascii, 'standard', LumaRamp::CHARS2, AsciiColorMode::Mono],
            'ansi256/standard' => [Mode::Ansi256, 'standard', LumaRamp::CHARS2, AsciiColorMode::Ansi256],
            'truecolor/standard' => [Mode::TrueColor, 'standard', LumaRamp::CHARS2, AsciiColorMode::TrueColor],
            'ascii/minimal' => [Mode::Ascii, 'minimal', LumaRamp::CHARS1, AsciiColorMode::Mono],
            'ansi256/minimal' => [Mode::Ansi256, 'minimal', LumaRamp::CHARS1, AsciiColorMode::Ansi256],
            'truecolor/dense' => [Mode::TrueColor, 'dense', LumaRamp::CHARS3, AsciiColorMode::TrueColor],
            'ansi256/dense' => [Mode::Ansi256, 'dense', LumaRamp::CHARS3, AsciiColorMode::Ansi256],
            'unknown ramp falls back to standard' => [Mode::Ascii, 'no-such-ramp', LumaRamp::CHARS2, AsciiColorMode::Mono],
        ];
    }

    /**
     * Axis 1: the bridge adds zero deviation of its own — for every live
     * Mode × ramp combination its output is byte-identical to a canonical
     * sugar-image render given the mapped colour mode and character table.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('bridgeSurface')]
    public function testBridgeIsByteIdenticalToCanonicalRender(
        Mode $mode,
        string $rampName,
        string $rampChars,
        AsciiColorMode $expectedColor,
    ): void {
        $bridge = (new AsciiRenderer($rampName))->render(new RgbFrame(self::SYNTHETIC_BYTES, 3, 3), $mode);

        $reference = (new ImageAsciiRenderer($expectedColor, $rampChars))
            ->render(ImageSource::fromRgb(self::SYNTHETIC_BYTES, 3, 3), 3, 3);

        $this->assertSame($reference, $bridge, "bridge output must equal canonical render for {$mode->name}/{$rampName}");
    }

    /**
     * A-11 re-pin: canonical bytes for the 3×3 frame across all three live
     * colour arms. Supersedes the legacy fork bytes (floor glyphs, leading
     * black-cell SGR, ESC[0m row clobbers, "\r\n" joins).
     */
    public function testCanonicalBytesForSyntheticFrame(): void
    {
        $renderer = new AsciiRenderer();

        $this->assertSame(
            base64_decode(self::CANONICAL_MONO_B64, true),
            $renderer->render(new RgbFrame(self::SYNTHETIC_BYTES, 3, 3), Mode::Ascii),
        );
        $this->assertSame(
            base64_decode(self::CANONICAL_ANSI256_B64, true),
            $renderer->render(new RgbFrame(self::SYNTHETIC_BYTES, 3, 3), Mode::Ansi256),
        );
        $this->assertSame(
            base64_decode(self::CANONICAL_TRUECOLOR_B64, true),
            $renderer->render(new RgbFrame(self::SYNTHETIC_BYTES, 3, 3), Mode::TrueColor),
        );
    }

    /**
     * Axis 2: the A-06 accessor route. The Mode enum projects onto exactly the
     * canonical colour depths; graphics modes (never routed here by the
     * factory) keep the legacy default-arm behaviour and land on Mono.
     */
    public function testColorModeProjectionCoversEveryVideoMode(): void
    {
        $this->assertSame(AsciiColorMode::Mono, AsciiRenderer::colorModeFor(Mode::Ascii));
        $this->assertSame(AsciiColorMode::Ansi256, AsciiRenderer::colorModeFor(Mode::Ansi256));
        $this->assertSame(AsciiColorMode::TrueColor, AsciiRenderer::colorModeFor(Mode::TrueColor));
        $this->assertSame(AsciiColorMode::Mono, AsciiRenderer::colorModeFor(Mode::HalfBlock));
        $this->assertSame(AsciiColorMode::Mono, AsciiRenderer::colorModeFor(Mode::QuarterBlock));
        $this->assertSame(AsciiColorMode::Mono, AsciiRenderer::colorModeFor(Mode::Sixel));
        $this->assertSame(AsciiColorMode::Mono, AsciiRenderer::colorModeFor(Mode::Kitty));
        $this->assertSame(AsciiColorMode::Mono, AsciiRenderer::colorModeFor(Mode::Iterm2));
    }

    /**
     * Axis 3: ramp threading. Named ramps must still change glyphs end-to-end
     * (white sits on every table's top; red luma 76 diverges between them) —
     * without this pin, deleting the old fork could silently flatten
     * Reel::withRamp() into one fixed ramp.
     */
    public function testRampNamesStillChangeTheGlyphs(): void
    {
        $white = new RgbFrame("\xff\xff\xff", 1, 1);
        $black = new RgbFrame("\x00\x00\x00", 1, 1);
        $red = new RgbFrame("\xff\x00\x00", 1, 1);

        $this->assertSame('@', (new AsciiRenderer('standard'))->render($white, Mode::Ascii));
        $this->assertSame('@', (new AsciiRenderer('minimal'))->render($white, Mode::Ascii));
        $this->assertSame('$', (new AsciiRenderer('dense'))->render($white, Mode::Ascii));

        $this->assertSame(' ', (new AsciiRenderer('dense'))->render($black, Mode::Ascii));

        $this->assertSame(';', (new AsciiRenderer('standard'))->render($red, Mode::Ascii));
        $this->assertSame('-', (new AsciiRenderer('minimal'))->render($red, Mode::Ascii));
        $this->assertSame('[', (new AsciiRenderer('dense'))->render($red, Mode::Ascii));
    }

    /**
     * Axis 4: truncation is loud now. The legacy copy clamped past-the-end
     * reads to black pixels; the bridge rejects the buffer outright, honouring
     * the documented RgbFrame contract of exactly w*h*3 bytes.
     */
    public function testTruncatedBufferFailsLoudlyInsteadOfPaintingBlack(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        (new AsciiRenderer())->render(new RgbFrame("\x00\x00", 1, 1), Mode::Ansi256);
    }

    /**
     * Re-fork guard (inheriting ColorPackingParityTest's #44 duty after A-11):
     * the bridge must own NO SGR emitter of its own. If someone reintroduces
     * a private pen here instead of delegating, the fork silently respawns and
     * the two implementations drift apart again.
     */
    public function testBridgeDeclaresNoForkEmitters(): void
    {
        $declared = implode('|', array_map(
            static fn (\ReflectionMethod $m): string => $m->getName(),
            (new \ReflectionClass(AsciiRenderer::class))->getMethods(),
        ));

        $this->assertStringNotContainsString(
            'emit',
            $declared,
            'the ascii bridge must delegate pen emission to sugar-image, never re-fork private emitters',
        );

        $this->assertStringContainsString('render', $declared, 'the FrameRenderer contract stays');
    }
}
