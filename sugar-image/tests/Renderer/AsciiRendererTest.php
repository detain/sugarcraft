<?php

declare(strict_types=1);

namespace SugarCraft\Image\Tests\Renderer;

use PHPUnit\Framework\TestCase;
use SugarCraft\Image\ImageSource;
use SugarCraft\Image\Renderer\AsciiColorMode;
use SugarCraft\Image\Renderer\AsciiRenderer;

final class AsciiRendererTest extends TestCase
{
    private function fixture(): ImageSource
    {
        return ImageSource::fromFile(__DIR__ . '/../fixtures/4x2.png');
    }

    public function testProducesOneLinePerPixelRowWithNoCarriageReturn(): void
    {
        $out = (new AsciiRenderer())->render($this->fixture(), 4, 2);

        // 2 rows → exactly one newline, no stray CR (CRLF would shred a rail).
        self::assertSame(2, substr_count($out, "\n") + 1);
        self::assertStringNotContainsString("\r", $out);
    }

    public function testMonoEmitsNoSgrEscapes(): void
    {
        $out = (new AsciiRenderer(AsciiColorMode::Mono))->render($this->fixture(), 4, 2);

        self::assertStringNotContainsString("\x1b[", $out, 'mono ASCII is plain text');
    }

    public function testTrueColorEmitsTruecolorForeground(): void
    {
        $out = (new AsciiRenderer(AsciiColorMode::TrueColor))->render($this->fixture(), 4, 2);

        self::assertStringContainsString("\x1b[38;2;", $out);
        self::assertStringContainsString("\x1b[39m", $out, 'a coloured row restores the default foreground at its end (A-13: scoped, never ESC[0m)');
        self::assertStringNotContainsString("\x1b[0m", $out, 'the reset-scope guard: a clobber reset must never reappear');
    }

    public function testAnsi256EmitsIndexedForeground(): void
    {
        $out = (new AsciiRenderer(AsciiColorMode::Ansi256))->render($this->fixture(), 4, 2);

        self::assertStringContainsString("\x1b[38;5;", $out);
    }

    /**
     * B-12: the ansi16 arm rides Color::ansi16Sgr()'s base-16 foreground rules
     * (30-37 normal, 90-97 bright) — never the 38; extended spelling — and the
     * A-13 row-end scope stays intact (39m, never a clobber).
     */
    public function testAnsi16EmitsBaseSixteenForeground(): void
    {
        $out = (new AsciiRenderer(AsciiColorMode::Ansi16))->render($this->fixture(), 4, 2);

        self::assertMatchesRegularExpression('/\x1b\[(?:3[0-7]|9[0-7])m/', $out, 'a coloured pixel must arm a base-16 foreground');
        self::assertStringNotContainsString("\x1b[38;", $out, 'ansi16 must not fall through to an extended sequence');
        self::assertStringContainsString("\x1b[39m", $out);
        self::assertStringNotContainsString("\x1b[0m", $out);
    }

    /**
     * A-06: name() is the constant protocol identity 'ascii' for every
     * colour arm — the ramp moved to colorMode() so the PROTOCOL slot stops
     * leaking 'ansi256'/'truecolor' colour-mode names.
     */
    public function testNameIsTheConstantProtocolAndColourModeRidesColorMode(): void
    {
        self::assertSame('ascii', (new AsciiRenderer(AsciiColorMode::Mono))->name());
        self::assertSame('ascii', (new AsciiRenderer(AsciiColorMode::Ansi256))->name());
        self::assertSame('ascii', (new AsciiRenderer(AsciiColorMode::Ansi16))->name());
        self::assertSame('ascii', (new AsciiRenderer(AsciiColorMode::TrueColor))->name());

        self::assertSame(AsciiColorMode::Mono, (new AsciiRenderer(AsciiColorMode::Mono))->colorMode());
        self::assertSame(AsciiColorMode::Ansi256, (new AsciiRenderer(AsciiColorMode::Ansi256))->colorMode());
        self::assertSame(AsciiColorMode::TrueColor, (new AsciiRenderer(AsciiColorMode::TrueColor))->colorMode());
    }

    public function testWidthOnlyDerivesHeightFromAspectRatio(): void
    {
        $renderer = new AsciiRenderer();

        // 4x2 fixture → aspect 2.0, so width=4 derives height=2.
        self::assertSame(
            $renderer->render($this->fixture(), 4, 2),
            $renderer->render($this->fixture(), 4),
        );
    }

    public function testInvalidDimensionsThrow(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new AsciiRenderer())->render($this->fixture(), -1, 2);
    }

    /**
     * A-11: the ramp is an injectable knob so sugar-video's bridge can thread
     * its named tables through this canonical quantizer instead of forking it.
     * Glyphs index the GIVEN table; the default stays the frozen 15-step ramp.
     */
    public function testCustomRampSelectsGlyphsFromTheGivenTable(): void
    {
        $white = ImageSource::fromRgb("\xff\xff\xff", 1, 1);
        $black = ImageSource::fromRgb("\x00\x00\x00", 1, 1);

        // luma 255 → last char of whatever table is supplied.
        self::assertSame('b', (new AsciiRenderer(AsciiColorMode::Mono, 'ab'))->render($white, 1, 1));
        self::assertSame('z', (new AsciiRenderer(AsciiColorMode::Mono, 'xyz'))->render($white, 1, 1));
        // luma 0 → first char.
        self::assertSame('x', (new AsciiRenderer(AsciiColorMode::Mono, 'xyz'))->render($black, 1, 1));
        // default unchanged: the 15-step ramp's top and bottom.
        self::assertSame('@', (new AsciiRenderer(AsciiColorMode::Mono))->render($white, 1, 1));
        self::assertSame(' ', (new AsciiRenderer(AsciiColorMode::Mono))->render($black, 1, 1));
    }

    /**
     * A-11: an empty table cannot index a glyph — refuse at construction,
     * not mid-render on the first pixel.
     */
    public function testEmptyRampIsRefusedAtConstruction(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new AsciiRenderer(AsciiColorMode::Mono, '');
    }
}
