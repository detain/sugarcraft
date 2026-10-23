<?php

declare(strict_types=1);

namespace SugarCraft\Image\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Image\Detect;
use SugarCraft\Image\Mosaic;
use SugarCraft\Image\Passthrough\ScreenPassthrough;
use SugarCraft\Image\Renderer\SixelRenderer;
use SugarCraft\Image\TermInfo;
use SugarCraft\Image\TmuxPassthroughDecorator;

/**
 * The mux verdict reaches the renderer through the public probe
 * surface — env-detection feeds Capability facts (quirks + passthrough
 * mode, F-02) into Mosaic's backend selection, and the Screen envelope
 * lands around whatever renderer the chain chose.
 *
 * Same env sandbox as MosaicAutoTest: keys scrubbed in setUp/tearDown,
 * only the variables the case names set.
 */
final class MosaicPassthroughWiringTest extends TestCase
{
    /** @var array<string,string|null> */
    private array $savedEnv = [];

    protected function setUp(): void
    {
        parent::setUp();
        $keys = [
            'CLICOLOR_FORCE', 'NO_COLOR', 'CLICOLOR', 'TERM', 'COLORTERM',
            'WT_SESSION', 'GOOGLE_CLOUD_SHELL', 'TMUX', 'STY', 'TERM_PROGRAM',
            'TERM_PROGRAM_VERSION', 'KITTY_WINDOW_ID', 'XTERM_VERSION', 'LC_TERMINAL',
            'MLTERM_VERSION',
        ];
        foreach ($keys as $key) {
            $this->savedEnv[$key] = $_ENV[$key] ?? null;
            unset($_ENV[$key]);
            putenv($key);
        }
    }

    protected function tearDown(): void
    {
        Detect::reset();
        foreach ($this->savedEnv as $key => $value) {
            if ($value === null) {
                unset($_ENV[$key]);
                putenv($key);
            } else {
                $_ENV[$key] = $value;
                putenv("{$key}={$value}");
            }
        }
        parent::tearDown();
    }

    public function testMltermEnvArmsTheQuirksThroughThePublicProbe(): void
    {
        $this->env('TERM', 'mlterm');

        $renderer = Mosaic::probe()->renderer();

        $this->assertInstanceOf(SixelRenderer::class, $renderer);
        // The S4.1 manual-only flag is now table-driven: mlterm answers
        // overshoot + edge-rows from the chain, nobody calls withQuirks.
        $this->assertSame(
            TermInfo::QUIRK_SIXEL_OVERSHOOT | TermInfo::QUIRK_MLTERM_EDGE_ROWS,
            $renderer->quirks(),
        );
    }

    public function testScreenChainWrapsTheSelectedRendererInPacketizedEnvelope(): void
    {
        $this->env('TERM', 'screen-256color');

        $renderer = Mosaic::probe()->renderer();

        $this->assertInstanceOf(ScreenPassthrough::class, $renderer);
        $this->assertStringStartsWith('screen(', $renderer->name());
    }

    public function testTmuxEnvKeepsTheTmuxEnvelopeEvenUnderAStScreenHint(): void
    {
        // TMUX is the innermost pipe: chafa's chain resolves a single mux
        // and tmux wins, so the F-05 allow-passthrough posture still owns
        // the envelope when both hints exist.
        $this->env('TERM', 'screen-256color');
        $this->env('TMUX', '/tmp/sentinel.sock,0,0');

        $renderer = Mosaic::probe()->renderer();

        $this->assertInstanceOf(TmuxPassthroughDecorator::class, $renderer);
        $this->assertStringStartsWith('tmux(', $renderer->name());
    }

    private function env(string $key, string $value): void
    {
        $_ENV[$key] = $value;
        putenv("{$key}={$value}");
    }
}
