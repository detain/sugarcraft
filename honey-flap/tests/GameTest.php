<?php

declare(strict_types=1);

namespace SugarCraft\Flap\Tests;

use SugarCraft\Core\BatchMsg;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Flap\Bird;
use SugarCraft\Flap\Game;
use SugarCraft\Flap\Pipe;
use SugarCraft\Flap\TickMsg;
use PHPUnit\Framework\TestCase;

final class GameTest extends TestCase
{
    public function testInitialBirdPositionIsCentered(): void
    {
        $g = Game::start(static fn(int $_max): int => 0);
        $this->assertSame(Game::BIRD_COL, $g->bird->x);
        $this->assertEqualsWithDelta(Game::HEIGHT / 2, $g->bird->body->position->y, 0.01);
        $this->assertSame(0, $g->score);
        $this->assertFalse($g->crashed);
        $this->assertSame([], $g->pipes);
    }

    public function testPipeSpawnsEveryNTicks(): void
    {
        $g = Game::start(static fn(int $_max): int => 0)->tickN(Game::PIPE_EVERY);
        $this->assertCount(1, $g->pipes);
        // The pipe is appended at WIDTH-1 in the same tick that increments
        // the existing pipes, so its first observed x is exactly WIDTH-1.
        $this->assertSame(Game::WIDTH - 1, $g->pipes[0]->x);
    }

    public function testFlapResetsVelocity(): void
    {
        $g = Game::start(static fn(int $_max): int => 0);
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
        $g = Game::start(static fn(int $_max): int => 0);
        [, $cmd] = $g->update(new KeyMsg(KeyType::Char, 'q'));
        $this->assertNotNull($cmd);
    }

    public function testRestartFromCrashedState(): void
    {
        $g = Game::start(static fn(int $_max): int => 0);
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
        $g = Game::start(static fn(int $_max): int => 0)->tickN(80);
        $this->assertTrue($g->crashed);
        $_rowAtCrash = $g->bird->row();
        $_g2 = $g->tickN(10);
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
            bird: Game::start(static fn(int $_max): int => 0)->bird,
            pipes: [],
            highScores: [],
        );
        $this->assertSame(0, $g->highScore());
    }

    public function testWithHighScoreIsImmutable(): void
    {
        $g = new Game(
            bird: Game::start(static fn(int $_max): int => 0)->bird,
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
            bird: Game::start(static fn(int $_max): int => 0)->bird,
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
            bird: Game::start(static fn(int $_max): int => 0)->bird,
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
        $g = Game::start(static fn(int $_max): int => 0, $tmp);
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
        Game::start(static fn(int $_max): int => 0, $tmp);
    }

    public function testReadScoresRejectsNonArrayScalarViaSharedGuard(): void
    {
        // A present-but-corrupt saved-state file whose top level is a JSON
        // scalar (not an array) must be rejected with a clean RuntimeException
        // via the candy-core Json::decodeArray SSOT — never a raw TypeError
        // from array_filter() on a non-array. The pre-SSOT loader ALREADY
        // rejected a non-array via a local is_array() check, so a bare
        // expectException(RuntimeException) pins nothing the migration added.
        // Assert the chained cause instead: decodeArray's own \RuntimeException
        // (top level not an array) is wrapped with the localized file-format
        // message, whereas the old is_array() path threw with no cause.
        $tmp = sys_get_temp_dir() . '/honey-flap-test-' . uniqid();
        mkdir($tmp . '/.honey-flap', 0755, true);
        file_put_contents($tmp . '/.honey-flap/scores.json', '"corrupt"');
        try {
            Game::start(static fn(int $_max): int => 0, $tmp);
            $this->fail('Expected a non-array saved-state top level to be rejected');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Invalid high score file format', $e->getMessage());
            $this->assertInstanceOf(\RuntimeException::class, $e->getPrevious());
            $this->assertStringContainsString(
                'Expected JSON top level to be an array',
                (string) $e->getPrevious()?->getMessage(),
            );
        } finally {
            @unlink($tmp . '/.honey-flap/scores.json');
            @rmdir($tmp . '/.honey-flap');
            @rmdir($tmp);
        }
    }

    public function testReadScoresRejectsMalformedJsonViaSharedGuard(): void
    {
        // Malformed (unparseable) JSON is likewise rejected with a
        // RuntimeException carrying the file path, preserving the pre-SSOT
        // contract. The migration's added JSON_THROW_ON_ERROR is what makes
        // decodeArray surface a \JsonException here; the pre-SSOT json_decode()
        // returned null (no throw) and the is_array() check threw with NO
        // cause. Asserting the chained \JsonException therefore pins the
        // guard rather than the pre-existing is_array() rejection.
        $tmp = sys_get_temp_dir() . '/honey-flap-test-' . uniqid();
        mkdir($tmp . '/.honey-flap', 0755, true);
        file_put_contents($tmp . '/.honey-flap/scores.json', '{not valid json');
        try {
            Game::start(static fn(int $_max): int => 0, $tmp);
            $this->fail('Expected malformed saved-state JSON to be rejected');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Invalid high score file format', $e->getMessage());
            $this->assertInstanceOf(\JsonException::class, $e->getPrevious());
        } finally {
            @unlink($tmp . '/.honey-flap/scores.json');
            @rmdir($tmp . '/.honey-flap');
            @rmdir($tmp);
        }
    }

    public function testReadScoresMissingFileReturnsEmptyList(): void
    {
        // New game with no save file: the missing-file fallback must survive
        // the SSOT migration and still seed an empty high-score list.
        $tmp = sys_get_temp_dir() . '/honey-flap-test-' . uniqid();
        mkdir($tmp, 0755, true);
        $g = Game::start(static fn(int $_max): int => 0, $tmp);
        $this->assertSame([], $g->highScores());
        $this->assertSame(0, $g->highScore());
        @rmdir($tmp);
    }

    public function testReadScoresFiltersNonIntEntries(): void
    {
        $tmp = sys_get_temp_dir() . '/honey-flap-test-' . uniqid();
        mkdir($tmp . '/.honey-flap', 0755, true);
        file_put_contents($tmp . '/.honey-flap/scores.json', json_encode([1, 'x', 2, null, 3]));
        $g = Game::start(static fn(int $_max): int => 0, $tmp);
        $this->assertSame([1, 2, 3], $g->highScores());
        // Clean up.
        unlink($tmp . '/.honey-flap/scores.json');
        @rmdir($tmp . '/.honey-flap');
        @rmdir($tmp);
    }

    public function testPipeCollisionCrashesBird(): void
    {
        // Pipe one column right of the bird, gap far from the bird's row.
        // After one tick it slides onto the bird's column and the bird —
        // still mid-field, clear of both walls — collides with the wall.
        $bird = Bird::spawn(Game::BIRD_COL, 9.0);
        $pipe = new Pipe(Game::BIRD_COL + 1, gapY: 3, gapHeight: 3); // gap rows 2..4
        $g = new Game(bird: $bird, pipes: [$pipe], highScores: []);

        $next = $g->tickN(1);

        $this->assertTrue($next->crashed);
        // The crash was the pipe, not a wall: the bird row is in-bounds.
        $this->assertGreaterThanOrEqual(0, $next->bird->row());
        $this->assertLessThan(Game::HEIGHT, $next->bird->row());
        // The colliding pipe now sits on the bird's column.
        $this->assertSame(Game::BIRD_COL, $next->pipes[0]->x);
    }

    public function testTopWallCrashesBird(): void
    {
        // A flap from the ceiling carries the bird's rounded row above the top
        // wall (row < 0), which crashes exactly like hitting the floor.
        $bird = Bird::spawn(Game::BIRD_COL, 0.0)->flap();
        $g = new Game(bird: $bird, pipes: [], highScores: []);

        $next = $g->tickN(3);

        $this->assertTrue($next->crashed);
        $this->assertLessThan(0, $next->bird->row());
    }

    public function testScoreIncrementsWhenPipePassesBird(): void
    {
        // Pipe on the bird's column with a gap covering the bird's row, so the
        // bird survives; the next tick slides it to BIRD_COL-1 (just past the
        // bird), which scores exactly one point.
        $bird = Bird::spawn(Game::BIRD_COL, 9.0);
        $pipe = new Pipe(Game::BIRD_COL, gapY: 9, gapHeight: 6); // gap rows 6..12
        $g = new Game(bird: $bird, pipes: [$pipe], score: 0, highScores: []);

        $next = $g->tickN(1);

        $this->assertFalse($next->crashed, 'bird is inside the gap and must survive');
        $this->assertSame(1, $next->score);
        $this->assertSame(Game::BIRD_COL - 1, $next->pipes[0]->x);
    }

    public function testWithHighScoreCapsLeaderboardAtMax(): void
    {
        // Ten existing scores; a new record must drop the lowest so the
        // retained list never exceeds MAX_HIGH_SCORES.
        $g = new Game(
            bird: Game::start(static fn(int $_max): int => 0)->bird,
            pipes: [],
            highScores: range(1, Game::MAX_HIGH_SCORES), // [1..10]
        );

        $g2 = $g->withHighScore(Game::MAX_HIGH_SCORES + 1); // 11 — a new record

        $this->assertCount(Game::MAX_HIGH_SCORES, $g2->highScores());
        $this->assertSame(range(2, Game::MAX_HIGH_SCORES + 1), $g2->highScores()); // 1 dropped
        $this->assertTrue($g2->newRecord);
    }

    public function testGameOverWithNewHighScorePersistsToDisk(): void
    {
        // Drive update(TickMsg) to a game-over that sets a NEW high score and
        // assert the persist Cmd wrote the leaderboard to the configured dir —
        // then a fresh Game::start() reads it back. This is the load-bearing
        // guard on update() calling persistHighScores(): revert that call and
        // the file is never written, so this test fails.
        $tmp = sys_get_temp_dir() . '/honey-flap-persist-' . uniqid();
        mkdir($tmp, 0755, true);

        try {
            // Bird pre-ticked below the floor with downward momentum, so the
            // very next advance() inside update() crashes the game.
            $bird = Bird::spawn(Game::BIRD_COL, (float) Game::HEIGHT);
            for ($i = 0; $i < 5; $i++) {
                $bird = $bird->tick();
            }
            $g = new Game(
                bird: $bird,
                pipes: [],
                score: 7,
                crashed: false,
                highScores: [],
                configDir: $tmp,
            );

            [$updated, $cmd] = $g->update(new TickMsg());

            $this->assertTrue($updated->crashed);
            $this->assertSame(7, $updated->score);
            $this->assertTrue($updated->newRecord);
            $this->assertSame([7], $updated->highScores());
            $this->assertNotNull($cmd);

            // Execute the batched Cmd: it fans out into the tick + persist cmds.
            $batch = $cmd();
            $this->assertInstanceOf(BatchMsg::class, $batch);
            foreach ($batch->cmds as $c) {
                $c();
            }

            // The leaderboard was written to the configured dir …
            $this->assertFileExists($tmp . '/.honey-flap/scores.json');
            // … and a fresh game reads it back.
            $reloaded = Game::start(static fn(int $_max): int => 0, $tmp);
            $this->assertSame([7], $reloaded->highScores());
            $this->assertSame(7, $reloaded->highScore());
        } finally {
            @unlink($tmp . '/.honey-flap/scores.json');
            @rmdir($tmp . '/.honey-flap');
            @rmdir($tmp);
        }
    }

    public function testUpdateWithUnwritableDirDoesNotThrow(): void
    {
        // Create an unwritable config dir.
        $tmp = sys_get_temp_dir() . '/honey-flap-test-' . uniqid();
        mkdir($tmp, 0000, true);
        $g = Game::start(static fn(int $_max): int => 0, $tmp)->tickN(80);
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
