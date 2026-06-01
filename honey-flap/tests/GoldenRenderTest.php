<?php

declare(strict_types=1);

namespace SugarCraft\Flap\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Flap\Game;
use SugarCraft\Testing\Snapshot\GoldenFile;

/**
 * Golden-file snapshot tests for honey-flap game state/output.
 *
 * honey-flap outputs NUMERIC game-state trajectories (JSON), not ANSI.
 * Uses assertGolden (file equality) on the JSON representation.
 *
 * @see Mirrors kbrgl/flapioca game state
 */
final class GoldenRenderTest extends TestCase
{
    private string $fixturesDir;

    protected function setUp(): void
    {
        $this->fixturesDir = __DIR__ . '/fixtures';
    }

    /**
     * Capture game state after 5 deterministic ticks as JSON.
     *
     * Uses a seeded RNG so the pipe layout is deterministic across runs.
     * Snapshot pins the bird position, pipe layout, score, and tick index.
     */
    public function testGameStateAfterFiveTicks(): void
    {
        // Deterministic RNG: fixed seed via closure.
        $rand = static fn(int $max): int => 4; // always returns 4
        $game = Game::start($rand);

        // Advance 5 ticks.
        $g = $game->tickN(5);

        $state = [
            'tickIndex' => $g->tickIndex,
            'score' => $g->score,
            'crashed' => $g->crashed,
            'birdX' => $g->bird->x,
            'birdRow' => $g->bird->row(),
            'pipeCount' => count($g->pipes),
            'pipes' => [],
        ];

        foreach ($g->pipes as $p) {
            $state['pipes'][] = [
                'x' => $p->x,
                'gapY' => $p->gapY,
            ];
        }

        $json = json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        if (getenv('UPDATE_GOLDENS') === '1') {
            GoldenFile::save($this->fixturesDir . '/game-state-5ticks.golden', $json);
            $this->assertTrue(true);
            return;
        }

        $expected = GoldenFile::load($this->fixturesDir . '/game-state-5ticks.golden');
        $this->assertNotNull($expected, 'Golden file not found. Run with UPDATE_GOLDENS=1 to create.');
        $this->assertEquals($expected, $json);
    }

    /**
     * Capture game state at game-over (crash) as JSON.
     *
     * With rand always returning 4 the game will eventually crash.
     */
    public function testGameStateAtCrash(): void
    {
        $rand = static fn(int $max): int => 4;
        $game = Game::start($rand);

        // Advance many ticks until crash.
        $g = $game;
        for ($i = 0; $i < 200; $i++) {
            $g = $g->tickN(1);
            if ($g->crashed) {
                break;
            }
        }

        $this->assertTrue($g->crashed, 'Game should have crashed within 200 ticks with rand=4');

        $state = [
            'tickIndex' => $g->tickIndex,
            'score' => $g->score,
            'crashed' => $g->crashed,
            'birdX' => $g->bird->x,
            'birdRow' => $g->bird->row(),
            'pipeCount' => count($g->pipes),
        ];

        $json = json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        if (getenv('UPDATE_GOLDENS') === '1') {
            GoldenFile::save($this->fixturesDir . '/game-state-crash.golden', $json);
            $this->assertTrue(true);
            return;
        }

        $expected = GoldenFile::load($this->fixturesDir . '/game-state-crash.golden');
        $this->assertNotNull($expected, 'Golden file not found. Run with UPDATE_GOLDENS=1 to create.');
        $this->assertEquals($expected, $json);
    }
}
