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
        // Without flap, the bird drops over ~0.5s (15 ticks). Gravity is
        // gentle enough now that a handful of ticks barely shifts the
        // rounded row, so tick far enough for the fall to register.
        $dropped = $g->tickN(15);
        $this->assertGreaterThan($beforeY, $dropped->bird->row());
        // With flap right before those ticks, bird is higher.
        $msg = new KeyMsg(KeyType::Space, '');
        [$g, ] = $g->update($msg);
        $afterFlap = $g->tickN(15);
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
        $g = new Game(
            bird: Game::start(static fn(int $max): int => 0)->bird,
            pipes: [],
            highScores: [],
        );
        $this->assertSame(0, $g->highScore());
    }

    public function testWithHighScoreIsImmutable(): void
    {
        $g = new Game(
            bird: Game::start(static fn(int $max): int => 0)->bird,
            pipes: [],
            highScores: [5, 10],
        );
        $g2 = $g->withHighScore(99);
        // Returns a NEW instance.
        $this->assertNotSame($g, $g2);
        // Original is unchanged.
        $this->assertSame([5, 10], $g->highScores());
        $this->assertFalse($g->newRecord);
        // New instance has the merged list.
        $this->assertSame([5, 10, 99], $g2->highScores());
        $this->assertTrue($g2->newRecord);
    }

    public function testWithHighScoreOnlyMergesWhenHigher(): void
    {
        $g = new Game(
            bird: Game::start(static fn(int $max): int => 0)->bird,
            pipes: [],
            highScores: [5, 10],
        );
        // Score of 3 is NOT higher than current best (10) — returns same instance.
        $g2 = $g->withHighScore(3);
        $this->assertSame($g, $g2);
        $this->assertSame([5, 10], $g->highScores());
        $this->assertFalse($g->newRecord);

        // Score of 15 IS a new record — returns new instance.
        $g3 = $g->withHighScore(15);
        $this->assertNotSame($g, $g3);
        $this->assertSame([5, 10, 15], $g3->highScores());
        $this->assertTrue($g3->newRecord);

        // Score of 0 or negative — no change.
        $g4 = $g->withHighScore(0);
        $this->assertSame($g, $g4);
        $g5 = $g->withHighScore(-5);
        $this->assertSame($g, $g5);
    }

    public function testWithHighScoreKeepsSortedOrder(): void
    {
        $g = new Game(
            bird: Game::start(static fn(int $max): int => 0)->bird,
            pipes: [],
            highScores: [5, 15],
        );
        $g2 = $g->withHighScore(10);  // 10 is NOT a new high (15 > 10)
        $this->assertSame($g, $g2);   // no change

        $g3 = $g->withHighScore(20);  // 20 IS a new high
        $this->assertNotSame($g, $g3);
        $this->assertSame([5, 15, 20], $g3->highScores());
        $this->assertTrue($g3->newRecord);
    }

    public function testRandAccessorReturnsInjectedClosure(): void
    {
        $sentinel = static fn(int $max): int => 42;
        $g = Game::start($sentinel);
        $this->assertSame($sentinel, $g->rand());
    }

    public function testScoresAreSeededViaStart(): void
    {
        $tmp = sys_get_temp_dir() . '/honey-flap-test-' . uniqid();
        mkdir($tmp . '/.honey-flap', 0755, true);
        file_put_contents($tmp . '/.honey-flap/scores.json', json_encode([3, 7, 5]));
        $g = Game::start(static fn(int $max): int => 0, $tmp);
        $this->assertSame(7, $g->highScore());
        $this->assertSame([3, 5, 7], $g->highScores());
        // Clean up.
        unlink($tmp . '/.honey-flap/scores.json');
        @rmdir($tmp . '/.honey-flap');
        @rmdir($tmp);
    }

    public function testReadScoresThrowsOnNonArrayJson(): void
    {
        $tmp = sys_get_temp_dir() . '/honey-flap-test-' . uniqid();
        mkdir($tmp . '/.honey-flap', 0755, true);
        file_put_contents($tmp . '/.honey-flap/scores.json', '42');
        $this->expectException(\RuntimeException::class);
        Game::start(static fn(int $max): int => 0, $tmp);
    }

    public function testReadScoresFiltersNonIntEntries(): void
    {
        $tmp = sys_get_temp_dir() . '/honey-flap-test-' . uniqid();
        mkdir($tmp . '/.honey-flap', 0755, true);
        file_put_contents($tmp . '/.honey-flap/scores.json', json_encode([1, 'x', 2, null, 3]));
        $g = Game::start(static fn(int $max): int => 0, $tmp);
        $this->assertSame([1, 2, 3], $g->highScores());
        // Clean up.
        unlink($tmp . '/.honey-flap/scores.json');
        @rmdir($tmp . '/.honey-flap');
        @rmdir($tmp);
    }

    public function testUpdateWithUnwritableDirDoesNotThrow(): void
    {
        // Create an unwritable config dir.
        $tmp = sys_get_temp_dir() . '/honey-flap-test-' . uniqid();
        mkdir($tmp, 0000, true);
        $g = Game::start(static fn(int $max): int => 0, $tmp)->tickN(80);
        $this->assertTrue($g->crashed);
        // update() should not throw even though the dir is unwritable.
        // The persist runs via Cmd which swallows exceptions.
        [$next, $cmd] = $g->update(new TickMsg());
        $this->assertSame($g, $next);
        // Clean up (use ignore for root permission issues).
        @chmod($tmp, 0755);
        @rmdir($tmp);
    }
}
