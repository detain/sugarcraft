<?php

declare(strict_types=1);

namespace SugarCraft\Flap\Tests;

use SugarCraft\Flap\Game;
use SugarCraft\Flap\Renderer;
use SugarCraft\Testing\Snapshot\Assertions;
use PHPUnit\Framework\TestCase;

/**
 * Byte-exact ANSI render snapshots to guard against accidental styling changes.
 *
 * Run with UPDATE_GOLDENS=1 environment variable to regenerate fixtures.
 */
final class GoldenRenderTest extends TestCase
{
    private const FIXTURE_DIR = __DIR__ . '/fixtures';

    public function testAliveFrameIsByteExact(): void
    {
        // Deterministic seed + tick count: pipe spawned, bird off spawn row.
        $rand = static fn(int $max): int => 3;
        $g = Game::start($rand)->tickN(Game::PIPE_EVERY + 5);

        $output = Renderer::render($g);

        $existing = \SugarCraft\Testing\Snapshot\GoldenFile::load(
            self::FIXTURE_DIR . '/render-alive.golden',
        );
        // assertGoldenAnsi already compares; we add an explicit assert to silence risky.
        $this->assertNotNull($existing);
        Assertions::assertGoldenAnsi(self::FIXTURE_DIR . '/render-alive.golden', $output);
    }

    public function testCrashedFrameIsByteExact(): void
    {
        // Deterministic seed, tick until crash.
        $rand = static fn(int $max): int => 0;
        $g = Game::start($rand)->tickN(80);

        $output = Renderer::render($g);

        $existing = \SugarCraft\Testing\Snapshot\GoldenFile::load(
            self::FIXTURE_DIR . '/render-crashed.golden',
        );
        $this->assertNotNull($existing);
        Assertions::assertGoldenAnsi(self::FIXTURE_DIR . '/render-crashed.golden', $output);
    }
}
