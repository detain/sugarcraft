<?php

declare(strict_types=1);

namespace SugarCraft\Flappy\Tests;

use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Core\KeyType;
use SugarCraft\Flappy\Game;
use SugarCraft\Flappy\Renderer;
use SugarCraft\Testing\Snapshot\Assertions;
use PHPUnit\Framework\TestCase;

use SugarCraft\Flappy\Tests\Support\UsesHermeticConfig;

/**
 * Byte-exact ANSI render snapshots to guard against accidental styling changes.
 *
 * Re-baselining is create-only (mirrors sugar-testing): delete the stale
 * fixture, then run once with UPDATE_GOLDENS=1 — an existing golden that
 * differs is never rewritten, so CI cannot silently "pass" by regenerating.
 */
final class GoldenRenderTest extends TestCase
{
    use UsesHermeticConfig;

    private const FIXTURE_DIR = __DIR__ . '/fixtures';

    public function testAliveFrameIsByteExact(): void
    {
        // Deterministic seed + tick count: pipe spawned, bird off spawn row.
        // M10: flap first — a ready board never scrolls, so the live golden
        // must show a RUN, exactly like a player sees it.
        $rand = static fn(int $max): int => 3;
        [$g, ] = Game::start($rand, $this->hermeticConfigDir())
            ->update(new KeyMsg(KeyType::Space));
        $g = $g->tickN(Game::PIPE_EVERY + 5);

        $output = Renderer::render($g);

        // M5: assertGoldenAnsi owns both modes — compare when the golden
        // exists, and (with UPDATE_GOLDENS=1) create it when missing. The
        // old pre-flight assertNotNull blocked re-baselining entirely.
        Assertions::assertGoldenAnsi(self::FIXTURE_DIR . '/render-alive.golden', $output);
        // Counted assertion for PHPUnit's risky detector (sugar-testing's
        // standalone Assert:: calls do not register on the TestCase).
        $this->assertSame($output, file_get_contents(self::FIXTURE_DIR . '/render-alive.golden'));
    }

    public function testClimbingFrameIsByteExact(): void
    {
        // flappy-32 (finding -035): the '^' bucket earns its own golden —
        // alive pins the falling 'v', ready pins the level '>'. One tick
        // off the flap: velocity -10 + 0.6, still climbing.
        $rand = static fn(int $max): int => 3;
        [$g, ] = Game::start($rand, $this->hermeticConfigDir())
            ->update(new KeyMsg(KeyType::Space));
        $g = $g->tickN(1);

        $output = Renderer::render($g);

        Assertions::assertGoldenAnsi(self::FIXTURE_DIR . '/render-climbing.golden', $output);
        $this->assertSame($output, file_get_contents(self::FIXTURE_DIR . '/render-climbing.golden'));
    }

    public function testCrashedFrameIsByteExact(): void
    {
        // Deterministic seed, start, tick until crash.
        $rand = static fn(int $max): int => 0;
        [$g, ] = Game::start($rand, $this->hermeticConfigDir())
            ->update(new KeyMsg(KeyType::Space));
        $g = $g->tickN(80);

        $output = Renderer::render($g);

        Assertions::assertGoldenAnsi(self::FIXTURE_DIR . '/render-crashed.golden', $output);
        $this->assertSame($output, file_get_contents(self::FIXTURE_DIR . '/render-crashed.golden'));
    }

    public function testReadyFrameIsByteExact(): void
    {
        // M10: the READY card — bird hovering, frozen world, start hint.
        $g = Game::start(static fn(int $max): int => 0, $this->hermeticConfigDir());

        $output = Renderer::render($g);

        Assertions::assertGoldenAnsi(self::FIXTURE_DIR . '/render-ready.golden', $output);
        $this->assertSame($output, file_get_contents(self::FIXTURE_DIR . '/render-ready.golden'));
    }
}
