<?php

declare(strict_types=1);

namespace CandyCore\Flap\Tests;

use CandyCore\Flap\Game;
use CandyCore\Flap\Renderer;
use PHPUnit\Framework\TestCase;

final class RendererTest extends TestCase
{
    private function game(): Game
    {
        // Deterministic PRNG: always return 0 → pipes pin to top.
        return Game::start(static fn(int $max): int => 0);
    }

    public function testRenderHasBirdGlyph(): void
    {
        $out = Renderer::render($this->game());
        $this->assertStringContainsString('>', $out);
    }

    public function testRenderShowsScore(): void
    {
        $out = Renderer::render($this->game());
        $this->assertStringContainsString('score:', $out);
    }

    public function testRenderShowsHelpHintWhenAlive(): void
    {
        $out = Renderer::render($this->game());
        $this->assertStringContainsString('flap', $out);
        $this->assertStringContainsString('quit', $out);
        $this->assertStringNotContainsString('splat', $out);
    }

    public function testRenderShowsCrashHint(): void
    {
        $g = new Game(
            bird:  $this->game()->bird,
            pipes: [],
            score: 0,
            crashed: true,
        );
        $out = Renderer::render($g);
        $this->assertStringContainsString('splat', $out);
        $this->assertStringContainsString('press r', $out);
    }

    public function testRenderHasNonZeroDimensions(): void
    {
        $out = Renderer::render($this->game());
        $lines = explode("\n", $out);
        $this->assertGreaterThan(Game::HEIGHT, count($lines));
    }
}
