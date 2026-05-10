<?php

declare(strict_types=1);

namespace SugarCraft\Flap\Tests;

use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Flap\Game;
use SugarCraft\Flap\TickMsg;
use PHPUnit\Framework\TestCase;

final class GameTest extends TestCase
{
    public function testInitialBirdPositionIsCentered(): void
    {
        $g = Game::start(static fn(int $max): int => 0);
        $this->assertSame(Game::BIRD_COL, $g->bird->x);
        $this->assertEqualsWithDelta(Game::HEIGHT / 2, $g->bird->body->position->y, 0.01);
        $this->assertSame(0, $g->score);
        $this->assertFalse($g->crashed);
        $this->assertSame([], $g->pipes);
    }

    public function testPipeSpawnsEveryNTicks(): void
    {
        $g = Game::start(static fn(int $max): int => 0)->tickN(Game::PIPE_EVERY);
        $this->assertCount(1, $g->pipes);
        // The pipe is appended at WIDTH-1 in the same tick that increments
        // the existing pipes, so its first observed x is exactly WIDTH-1.
        $this->assertSame(Game::WIDTH - 1, $g->pipes[0]->x);
    }

    public function testFlapResetsVelocity(): void
    {
        $g = Game::start(static fn(int $max): int => 0);
        $beforeY = $g->bird->row();
        // Without flap, bird drops several rows over 6 ticks.
        $dropped = $g->tickN(6);
        $this->assertGreaterThan($beforeY, $dropped->bird->row());
        // With flap right before those ticks, bird is higher.
        $msg = new KeyMsg(KeyType::Space, '');
        [$g, ] = $g->update($msg);
        $afterFlap = $g->tickN(6);
        $this->assertLessThan($dropped->bird->row(), $afterFlap->bird->row());
    }

    public function testQuitOnQuit(): void
    {
        $g = Game::start(static fn(int $max): int => 0);
        [, $cmd] = $g->update(new KeyMsg(KeyType::Char, 'q'));
        $this->assertNotNull($cmd);
    }

    public function testRestartFromCrashedState(): void
    {
        $g = Game::start(static fn(int $max): int => 0);
        // Drop into the floor by ticking many frames.
        $g = $g->tickN(80);
        $this->assertTrue($g->crashed);
        [$g, ] = $g->update(new KeyMsg(KeyType::Char, 'r'));
        $this->assertFalse($g->crashed);
        $this->assertSame(0, $g->score);
        $this->assertSame([], $g->pipes);
    }

    public function testCrashStopsBirdFromMovingAfterFurtherTicks(): void
    {
        $g = Game::start(static fn(int $max): int => 0)->tickN(80);
        $this->assertTrue($g->crashed);
        $rowAtCrash = $g->bird->row();
        $g2 = $g->tickN(10);
        // tickN bypasses the crashed gate (it's a test helper that drives
        // advance() directly), so the bird keeps falling past the floor —
        // but the runtime gate in update(TickMsg) will not advance the model.
        // Verify that update() returns the same model with no further work.
        [$next, $cmd] = $g->update(new TickMsg());
        $this->assertSame($g, $next);
        $this->assertNull($cmd);
    }

    public function testDeterministicWithSeededRand(): void
    {
        // Both runs use the same closure, so pipe layouts should match.
        $rand = static fn(int $max): int => intdiv($max, 2);
        $a = Game::start($rand)->tickN(60);
        $b = Game::start($rand)->tickN(60);
        $this->assertSame(count($a->pipes), count($b->pipes));
        foreach ($a->pipes as $i => $pipe) {
            $this->assertSame($pipe->gapY, $b->pipes[$i]->gapY);
        }
    }

    public function testHighScoreReturnsZeroWhenNoScores(): void
    {
        $tmp = sys_get_temp_dir() . '/honey-flap-test-' . uniqid();
        $g = new Game(
            bird: Game::start(static fn(int $max): int => 0)->bird,
            pipes: [],
            configDir: $tmp,
        );
        $this->assertSame(0, $g->highScore());
        // Clean up.
        @rmdir($tmp . '/.honey-flap');
        @rmdir($tmp);
    }

    public function testSaveHighScoreOnlySavesWhenHigher(): void
    {
        $tmp = sys_get_temp_dir() . '/honey-flap-test-' . uniqid();
        mkdir($tmp . '/.honey-flap', 0755, true);
        $g = new Game(
            bird: Game::start(static fn(int $max): int => 0)->bird,
            pipes: [],
            configDir: $tmp,
        );
        // Score of 5 should be saved.
        $this->assertTrue($g->saveHighScore(5));
        $this->assertSame(5, $g->highScore());
        // Score of 3 should NOT be saved (lower than current high).
        $this->assertFalse($g->saveHighScore(3));
        $this->assertSame(5, $g->highScore());
        // Score of 10 should be saved (new high).
        $this->assertTrue($g->saveHighScore(10));
        $this->assertSame(10, $g->highScore());
        // Clean up.
        unlink($tmp . '/.honey-flap/scores.json');
        @rmdir($tmp . '/.honey-flap');
        @rmdir($tmp);
    }

    public function testHighScoresReturnsSortedList(): void
    {
        $tmp = sys_get_temp_dir() . '/honey-flap-test-' . uniqid();
        mkdir($tmp . '/.honey-flap', 0755, true);
        $g = new Game(
            bird: Game::start(static fn(int $max): int => 0)->bird,
            pipes: [],
            configDir: $tmp,
        );
        $g->saveHighScore(5);
        $g->saveHighScore(15);
        $g->saveHighScore(10);  // 10 is NOT a new high (15 > 10), so not saved
        $scores = $g->highScores();
        $this->assertSame([5, 15], $scores);
        // Clean up.
        unlink($tmp . '/.honey-flap/scores.json');
        @rmdir($tmp . '/.honey-flap');
        @rmdir($tmp);
    }

    public function testLoadHighScoresOnConstruction(): void
    {
        $tmp = sys_get_temp_dir() . '/honey-flap-test-' . uniqid();
        mkdir($tmp . '/.honey-flap', 0755, true);
        file_put_contents($tmp . '/.honey-flap/scores.json', json_encode([3, 7, 5]));
        $g = new Game(
            bird: Game::start(static fn(int $max): int => 0)->bird,
            pipes: [],
            configDir: $tmp,
        );
        $this->assertSame(7, $g->highScore());
        $this->assertSame([3, 5, 7], $g->highScores());
        // Clean up.
        unlink($tmp . '/.honey-flap/scores.json');
        @rmdir($tmp . '/.honey-flap');
        @rmdir($tmp);
    }
}
