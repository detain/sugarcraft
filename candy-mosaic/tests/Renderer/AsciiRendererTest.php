<?php

declare(strict_types=1);

namespace SugarCraft\Mosaic\Tests\Renderer;

use PHPUnit\Framework\TestCase;
use SugarCraft\Mosaic\ImageSource;
use SugarCraft\Mosaic\Renderer\AsciiColorMode;
use SugarCraft\Mosaic\Renderer\AsciiRenderer;

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
        self::assertStringContainsString("\x1b[0m", $out, 'a coloured row resets SGR at its end');
    }

    public function testAnsi256EmitsIndexedForeground(): void
    {
        $out = (new AsciiRenderer(AsciiColorMode::Ansi256))->render($this->fixture(), 4, 2);

        self::assertStringContainsString("\x1b[38;5;", $out);
    }

    public function testNameMatchesColourModeForCacheKeying(): void
    {
        self::assertSame('ascii', (new AsciiRenderer(AsciiColorMode::Mono))->name());
        self::assertSame('ansi256', (new AsciiRenderer(AsciiColorMode::Ansi256))->name());
        self::assertSame('truecolor', (new AsciiRenderer(AsciiColorMode::TrueColor))->name());
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
