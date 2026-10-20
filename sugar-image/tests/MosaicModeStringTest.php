<?php

declare(strict_types=1);

namespace SugarCraft\Image\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Image\Mosaic;
use SugarCraft\Image\Renderer\AsciiRenderer;
use SugarCraft\Image\Renderer\ChafaRenderer;
use SugarCraft\Image\Renderer\HalfBlockRenderer;
use SugarCraft\Image\Renderer\Iterm2Renderer;
use SugarCraft\Image\Renderer\KittyRenderer;
use SugarCraft\Image\Renderer\QuarterBlockRenderer;
use SugarCraft\Image\Renderer\SixelRenderer;

/**
 * @covers \SugarCraft\Image\Mosaic::fromModeString
 */
final class MosaicModeStringTest extends TestCase
{
    /** @var array<string,string|false> */
    private array $savedEnv = [];

    protected function setUp(): void
    {
        parent::setUp();
        // 'auto' routes through Mosaic::auto(); clear the terminal knobs so
        // the detection result is deterministic on any machine.
        $keys = [
            'CLICOLOR_FORCE', 'NO_COLOR', 'CLICOLOR', 'TERM', 'COLORTERM',
            'WT_SESSION', 'GOOGLE_CLOUD_SHELL', 'TMUX', 'STY', 'TERM_PROGRAM',
            'KITTY_WINDOW_ID', 'XTERM_VERSION', 'LC_TERMINAL',
        ];
        foreach ($keys as $key) {
            // Detect reads getenv(), not $_ENV — save/restore through the
            // same channel (false = genuinely unset) so the real process env
            // survives even under variables_order settings without 'E'.
            $this->savedEnv[$key] = getenv($key);
            unset($_ENV[$key]);
            putenv($key);
        }
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        foreach ($this->savedEnv as $key => $value) {
            if ($value === false) {
                unset($_ENV[$key]);
                putenv($key);
            } else {
                $_ENV[$key] = $value;
                putenv("{$key}={$value}");
            }
        }
    }

    /**
     * @return array<string, array{0: string, 1: string}> mode input word → renderer FQCN
     *         (row[0] is the word fed to fromModeString(); A-06 left the
     *         'ansi256'/'truecolor' legacy spellings accepted as INPUT, but
     *         they are colour arms of the 'ascii' protocol, not protocols)
     */
    public static function modeProvider(): array
    {
        return [
            'auto'         => ['auto', HalfBlockRenderer::class],
            'sixel'        => ['sixel', SixelRenderer::class],
            'kitty'        => ['kitty', KittyRenderer::class],
            'iterm2'       => ['iterm2', Iterm2Renderer::class],
            'halfblock'    => ['halfblock', HalfBlockRenderer::class],
            'half alias'   => ['half', HalfBlockRenderer::class],
            'ansi alias'   => ['ansi', HalfBlockRenderer::class],
            'quarterblock' => ['quarterblock', QuarterBlockRenderer::class],
            'quarter alias' => ['quarter', QuarterBlockRenderer::class],
            'ascii'        => ['ascii', AsciiRenderer::class],
            'ansi16'       => ['ansi16', AsciiRenderer::class],
            'ansi256'      => ['ansi256', AsciiRenderer::class],
            'truecolor'    => ['truecolor', AsciiRenderer::class],
            'chafa'        => ['chafa', ChafaRenderer::class],
        ];
    }

    /**
     * @dataProvider modeProvider
     */
    public function testEachModeResolvesToItsRenderer(string $mode, string $rendererClass): void
    {
        $mosaic = Mosaic::fromModeString($mode);

        $this->assertInstanceOf(Mosaic::class, $mosaic);
        $this->assertSame($rendererClass, $mosaic->renderer()::class);
    }

    public function testAutoModeProtocolIsHalfBlockOnBareEnvironment(): void
    {
        $this->assertSame('halfblock', Mosaic::fromModeString('auto')?->protocol());
    }

    public function testModeStringsAreCaseInsensitiveAndTrimmed(): void
    {
        $this->assertSame('kitty', Mosaic::fromModeString('  KiTtY ')?->protocol());
        $this->assertSame('iterm2', Mosaic::fromModeString('ITERM2')?->protocol());
        $this->assertSame('halfblock', Mosaic::fromModeString('HALF')?->protocol());
    }

    /**
     * A-06: protocol() reports 'ascii' for all four colour arms — the
     * ramp words 'ansi16'/'ansi256'/'truecolor' are accepted INPUT spellings
     * that reach AsciiRenderer's colour axis, carried by modeDetail()
     * instead of leaking into the protocol slot (B-12 added the ansi16 arm).
     */
    public function testAnsi16Ansi256AndTruecolorModesReachTheAsciiRendererColorMode(): void
    {
        // All four mode words resolve to the same PROTOCOL...
        $this->assertSame('ascii', Mosaic::fromModeString('ascii')?->protocol());
        $this->assertSame('ascii', Mosaic::fromModeString('ansi16')?->protocol());
        $this->assertSame('ascii', Mosaic::fromModeString('ansi256')?->protocol());
        $this->assertSame('ascii', Mosaic::fromModeString('truecolor')?->protocol());

        // ...and the colour arm — the thing that actually differs in the
        // encoded bytes — rides modeDetail(), null for the Mono arm.
        $this->assertNull(Mosaic::fromModeString('ascii')?->modeDetail());
        $this->assertSame('ansi16', Mosaic::fromModeString('ansi16')?->modeDetail());
        $this->assertSame('ansi256', Mosaic::fromModeString('ansi256')?->modeDetail());
        $this->assertSame('truecolor', Mosaic::fromModeString('truecolor')?->modeDetail());
    }

    public function testUnknownModeReturnsNullForCallerPhrasedErrors(): void
    {
        $this->assertNull(Mosaic::fromModeString('webgpu'));
        $this->assertNull(Mosaic::fromModeString(''));
        $this->assertNull(Mosaic::fromModeString('  '));
    }
}
