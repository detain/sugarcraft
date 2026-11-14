<?php

declare(strict_types=1);

namespace SugarCraft\Vcr\Tests\Tape;

use PHPUnit\Framework\TestCase;
use SugarCraft\Vcr\Raster\GdRasterizer;
use SugarCraft\Vcr\Encode\PhpGifEncoder;
use SugarCraft\Vcr\Encode\TapeToGif;
use SugarCraft\Vcr\Player;
use SugarCraft\Vcr\Render\FrameStream;
use SugarCraft\Vcr\Render\Renderer;
use SugarCraft\Vcr\Tape\Compiler;
use SugarCraft\Vcr\Tape\Lexer;
use SugarCraft\Vcr\Tape\Parser;
use SugarCraft\Vterm\Terminal;
use SugarCraft\Vterm\Theme;

/**
 * VCR-S68 (finding VCR-COPY-05): fallback notices used to bypass the CLI's
 * warning channel via raw error_log() — unstyled, order-shifted, invisible
 * to memory-stream callers. Every notice now shares the collected
 * `warnings()` voice.
 */
final class RenderWarningChannelTest extends TestCase
{
    public function testUnknownThemeFallbackRidesTheCollectorNotErrorLog(): void
    {
        $pipeline = $this->pipeline();
        $resolve = new \ReflectionMethod(TapeToGif::class, 'resolveTheme');

        $log = tempnam(sys_get_temp_dir(), 'vcr-warn-log');
        $this->assertIsString($log);
        $previous = ini_set('error_log', $log);
        try {
            $theme = $resolve->invoke($pipeline, 'NoSuchTheme', false);
        } finally {
            is_string($previous) ? ini_set('error_log', $previous) : ini_set('error_log', '');
        }

        $this->assertSame(Theme::tokyoNight()->color(0), $theme->color(0));
        $this->assertSame(
            ["unknown theme 'NoSuchTheme', falling back to TokyoNight"],
            $pipeline->warnings(),
        );
        $this->assertSame('', (string) file_get_contents($log), 'no raw error_log bypass');
        @unlink($log);
    }

    public function testStrictUnknownThemeThrowSharesTheLowercaseDialect(): void
    {
        $pipeline = $this->pipeline();
        $resolve = new \ReflectionMethod(TapeToGif::class, 'resolveTheme');

        try {
            $resolve->invoke($pipeline, 'NoSuchTheme', true);
            $this->fail('strict mode must refuse an unknown theme');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringStartsWith('unknown theme', $e->getMessage());
        }
    }

    public function testExecFallbackCollectsOnTheFrameStream(): void
    {
        $stream = $this->frameStream();
        $warn = new \ReflectionMethod(FrameStream::class, 'warnExecFallback');

        $this->assertSame([], $stream->warnings());
        $warn->invoke($stream, 'no shell available');
        $warn->invoke($stream, 'fork refused');

        $this->assertSame([
            'Set Shell requested but exec mode unavailable (no shell available); rendering keystrokes only',
            'Set Shell requested but exec mode unavailable (fork refused); rendering keystrokes only',
        ], $stream->warnings());
    }

    public function testTapeToGifMergesTheFrameStreamWarnings(): void
    {
        $pipeline = $this->pipeline();
        $stream = $this->frameStream();
        (new \ReflectionMethod(FrameStream::class, 'warnExecFallback'))
            ->invoke($stream, 'exec mode unavailable under test');

        $property = new \ReflectionProperty(TapeToGif::class, 'lastFrameStream');
        $property->setValue($pipeline, $stream);

        $this->assertSame(
            ['Set Shell requested but exec mode unavailable (exec mode unavailable under test); rendering keystrokes only'],
            $pipeline->warnings(),
        );
    }

    private function pipeline(): TapeToGif
    {
        return new TapeToGif(new Lexer(), new Parser(), new Compiler(), new Renderer(), new GdRasterizer(), new PhpGifEncoder());
    }

    private function frameStream(): FrameStream
    {
        $cassette = new \SugarCraft\Vcr\Cassette(
            new \SugarCraft\Vcr\CassetteHeader(
                version: 1,
                createdAt: '2026-05-22T00:00:00Z',
                cols: 80,
                rows: 24,
                runtime: 'test',
            ),
            [],
        );
        $player = new Player($cassette);
        $terminal = Terminal::new(80, 24, Theme::tokyoNight());

        return new FrameStream($player, $terminal, 10.0);
    }
}
