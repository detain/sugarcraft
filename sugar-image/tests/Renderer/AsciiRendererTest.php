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
     * A-06: name() is the constant protocol identity 'ascii' for every
     * colour arm — the ramp moved to colorMode() so the PROTOCOL slot stops
     * leaking 'ansi256'/'truecolor' colour-mode names.
     */
    public function testNameIsTheConstantProtocolAndColourModeRidesColorMode(): void
    {
        self::assertSame('ascii', (new AsciiRenderer(AsciiColorMode::Mono))->name());
        self::assertSame('ascii', (new AsciiRenderer(AsciiColorMode::Ansi256))->name());
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
}
