<?php

declare(strict_types=1);

namespace SugarCraft\Flap\Tests;

use SugarCraft\Flap\Game;
use SugarCraft\Flap\Renderer;
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
            highScores: [],
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

    public function testRenderShowsNewHighScoreBanner(): void
    {
        // Build a crashed Game where the score IS a strict new record.
        // withHighScore(99) on an empty list creates newRecord=true.
        $base = new Game(
            bird:  $this->game()->bird,
            pipes: [],
            score: 0,
            crashed: false,
            highScores: [],
        );
        $crashed = $base->tickN(80);  // crash into floor
        $withScore = new Game(
            bird: $crashed->bird,
            pipes: $crashed->pipes,
            score: 99,
            crashed: true,
            tickIndex: $crashed->tickIndex,
            highScores: [99],
            newRecord: true,
        );
        $out = Renderer::render($withScore);
        $this->assertStringContainsString('NEW HIGH SCORE', $out);
        $this->assertStringContainsString('🏆', $out);
    }

    public function testRenderShowsBestLineWhenNotARecord(): void
    {
        // Crashed with score below existing best — shows "best:" not "NEW HIGH SCORE".
        $g = new Game(
            bird:  $this->game()->bird,
            pipes: [],
            score: 3,
            crashed: true,
            highScores: [10, 20],  // best is 20
            newRecord: false,
        );
        $out = Renderer::render($g);
        $this->assertStringContainsString('best:', $out);
        $this->assertStringNotContainsString('NEW HIGH SCORE', $out);
    }

    public function testRenderTieDoesNotShowNewRecord(): void
    {
        // Tie (score equals best but doesn't beat it) — newRecord must be false.
        // The score=10, highScores=[10] means newRecord=false (not strictly greater).
        $g = new Game(
            bird:  $this->game()->bird,
            pipes: [],
            score: 10,
            crashed: true,
            highScores: [10],  // score equals best, not greater
            newRecord: false,
        );
        $out = Renderer::render($g);
        $this->assertStringNotContainsString('NEW HIGH SCORE', $out);
        $this->assertStringContainsString('best:', $out);
    }
}
