<?php

declare(strict_types=1);

namespace SugarCraft\Flappy\Tests;

use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Flappy\Bird;
use SugarCraft\Flappy\Game;
use SugarCraft\Flappy\Pipe;
use SugarCraft\Flappy\TickMsg;
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
        $tmp = sys_get_temp_dir() . '/candy-flappy-test-' . uniqid();
        mkdir($tmp . '/.candy-flappy', 0755, true);
        file_put_contents($tmp . '/.candy-flappy/scores.json', json_encode([3, 7, 5]));
        $g = Game::start(static fn(int $_max): int => 0, $tmp);
        $this->assertSame(7, $g->highScore());
        $this->assertSame([3, 5, 7], $g->highScores());
        // Clean up.
        unlink($tmp . '/.candy-flappy/scores.json');
        @rmdir($tmp . '/.candy-flappy');
        @rmdir($tmp);
    }

    public function testReadScoresQuarantinesNonArrayTopLevelInsteadOfThrowing(): void
    {
        // M3 in-step flip: the old pin asserted launch THREW on a non-array
        // save (the brick). Launch now fails soft with a quarantine artifact.
        $tmp = sys_get_temp_dir() . '/candy-flappy-test-' . uniqid();
        mkdir($tmp . '/.candy-flappy', 0755, true);
        file_put_contents($tmp . '/.candy-flappy/scores.json', '42');
        $g = Game::start(static fn(int $_max): int => 0, $tmp);
        $this->assertSame([], $g->highScores());
        $this->assertFileDoesNotExist($tmp . '/.candy-flappy/scores.json');
        array_map('unlink', glob($tmp . '/.candy-flappy/scores.json.corrupt-*') ?: []);
        @rmdir($tmp . '/.candy-flappy');
        @rmdir($tmp);
    }

    public function testCorruptSaveIsQuarantinedAndLaunchContinues(): void
    {
        // M3 (lib-reviews-1102): a corrupt scores.json used to BRICK launch
        // (RuntimeException propagating out of start() -> bin exit 255).
        // Launch now fails soft: the bad file is quarantined aside, play
        // starts with an empty board, and the next record republishes cleanly.
        foreach (['"corrupt"', '{not valid json', '[]oops'] as $i => $junk) {
            $tmp = sys_get_temp_dir() . '/candy-flappy-quarantine-' . getmypid() . '-' . $i;
            mkdir($tmp . '/.candy-flappy', 0755, true);
            $target = $tmp . '/.candy-flappy/scores.json';
            file_put_contents($target, $junk);
            $g = Game::start(static fn(int $_max): int => 0, $tmp);
            $this->assertSame([], $g->highScores(), "junk shape {$i} must seed an empty board");
            $this->assertFileDoesNotExist($target, 'quarantine must move the bad file aside');
            $quarantined = array_values(array_filter(
                scandir($tmp . '/.candy-flappy'),
                static fn(string $f): bool => str_starts_with($f, 'scores.json.corrupt-'),
            ));
            $this->assertCount(1, $quarantined, 'exactly one quarantine artifact per corrupt file');
            array_map('unlink', array_map(
                static fn(string $f): string => $tmp . '/.candy-flappy/' . $f,
                $quarantined,
            ));
            @rmdir($tmp . '/.candy-flappy');
            @rmdir($tmp);
        }
    }

    public function testForgedAndOversizedScoresAreClampedOnRead(): void
    {
        // m7/m8: read is the legality gate, not just is_int. Negatives,
        // zero, absurd forgeries, non-ints, and past-cap entries never
        // reach the board; a save file larger than the read cap is treated
        // as corrupt (quarantined) rather than parsed.
        $tmp = sys_get_temp_dir() . '/candy-flappy-clamp-' . getmypid();
        mkdir($tmp . '/.candy-flappy', 0755, true);
        $forged = [
            -5, 0, 3, '7', 4.0, 9, 11, 12, 13, 14, 15, 16, 17,
            18, 19, 20, 999999999999999999999, 100000,
        ];
        file_put_contents(
            $tmp . '/.candy-flappy/scores.json',
            json_encode($forged, JSON_THROW_ON_ERROR),
        );
        $g = Game::start(static fn(int $_max): int => 0, $tmp);
        $scores = $g->highScores();
        // Twelve legal entries survive the clamp; the ascending top-10 cap
        // then keeps 11..20 and sheds 3 and 9 — a cap, not a rejection.
        $this->assertSame(range(11, 20), $scores);
        $this->assertNotContains(-5, $scores);
        $this->assertNotContains(0, $scores);
        $this->assertNotContains(100000, $scores);
        $this->assertLessThanOrEqual(Game::MAX_HIGH_SCORES, count($scores));
        foreach ($scores as $v) {
            $this->assertIsInt($v);
            $this->assertGreaterThan(0, $v);
            $this->assertLessThan(Game::SCORE_CEILING + 1, $v);
        }
        @unlink($tmp . '/.candy-flappy/scores.json');
        @rmdir($tmp . '/.candy-flappy');
        @rmdir($tmp);
    }

    public function testOversizedSaveFileIsQuarantinedWithoutBeingParsed(): void
    {
        $tmp = sys_get_temp_dir() . '/candy-flappy-bigsave-' . getmypid();
        mkdir($tmp . '/.candy-flappy', 0755, true);
        $target = $tmp . '/.candy-flappy/scores.json';
        file_put_contents($target, str_repeat('[1,2,3]', Game::MAX_SCORE_FILE_BYTES));
        $g = Game::start(static fn(int $_max): int => 0, $tmp);
        $this->assertSame([], $g->highScores());
        $this->assertFileDoesNotExist($target);
        array_map('unlink', glob($tmp . '/.candy-flappy/scores.json.corrupt-*') ?: []);
        @rmdir($tmp . '/.candy-flappy');
        @rmdir($tmp);
    }

    public function testPersistPublishesPrivatelyAndAtomically(): void
    {
        // M4: the write goes through sugar-core AtomicJsonFile with an
        // explicit 0600 publish mode (the old bare file_put_contents landed
        // 0664/0644 under common umasks and could tear on ENOSPC).
        $tmp = sys_get_temp_dir() . '/candy-flappy-atomic-' . getmypid();
        $bird = Bird::spawn(Game::BIRD_COL, (float) Game::HEIGHT);
        for ($i = 0; $i < 5; $i++) {
            $bird = $bird->tick();
        }
        $g = new Game(
            bird: $bird,
            pipes: [],
            score: 5,
            crashed: false,
            highScores: [],
            configDir: $tmp,
        );
        // Drive the real production path: the crash tick hands back the
        // persist Cmd; executing it is what writes the file.
        [$updated, $cmd] = $g->update(new TickMsg());
        $this->assertTrue($updated->newRecord);
        $this->assertNotNull($cmd);
        $cmd();
        $target = $tmp . '/.candy-flappy/scores.json';
        $this->assertFileExists($target);
        $mode = fileperms($target) & 0777;
        $this->assertSame(0600, $mode, 'leaderboard must publish 0600');
        $this->assertSame([5], json_decode((string) file_get_contents($target), true, 512, JSON_THROW_ON_ERROR));
        // No temp residue: only the target + its stable lock sidecar exist.
        $leftovers = array_values(array_filter(
            scandir($tmp . '/.candy-flappy'),
            static fn(string $f): bool => !in_array($f, ['.', '..', '.scores.json.lock', 'scores.json'], true),
        ));
        $this->assertSame([], $leftovers);
        @chmod($tmp . '/.candy-flappy', 0755);
        @unlink($target);
        @unlink($tmp . '/.candy-flappy/.scores.json.lock');
        @rmdir($tmp . '/.candy-flappy');
        @rmdir($tmp);
    }

    public function testReadScoresMissingFileReturnsEmptyList(): void
    {
        // New game with no save file: the missing-file fallback must survive
        // the SSOT migration and still seed an empty high-score list.
        $tmp = sys_get_temp_dir() . '/candy-flappy-test-' . uniqid();
        mkdir($tmp, 0755, true);
        $g = Game::start(static fn(int $_max): int => 0, $tmp);
        $this->assertSame([], $g->highScores());
        $this->assertSame(0, $g->highScore());
        @rmdir($tmp);
    }

    public function testReadScoresFiltersNonIntEntries(): void
    {
        $tmp = sys_get_temp_dir() . '/candy-flappy-test-' . uniqid();
        mkdir($tmp . '/.candy-flappy', 0755, true);
        file_put_contents($tmp . '/.candy-flappy/scores.json', json_encode([1, 'x', 2, null, 3]));
        $g = Game::start(static fn(int $_max): int => 0, $tmp);
        $this->assertSame([1, 2, 3], $g->highScores());
        // Clean up.
        unlink($tmp . '/.candy-flappy/scores.json');
        @rmdir($tmp . '/.candy-flappy');
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
        $tmp = sys_get_temp_dir() . '/candy-flappy-persist-' . uniqid();
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

            // C1: the crash Cmd is the persist alone (no batched tick re-arm).
            $this->assertNull($cmd());

            // The leaderboard was written to the configured dir …
            $this->assertFileExists($tmp . '/.candy-flappy/scores.json');
            // … and a fresh game reads it back.
            $reloaded = Game::start(static fn(int $_max): int => 0, $tmp);
            $this->assertSame([7], $reloaded->highScores());
            $this->assertSame(7, $reloaded->highScore());
        } finally {
            @unlink($tmp . '/.candy-flappy/scores.json');
            @rmdir($tmp . '/.candy-flappy');
            @rmdir($tmp);
        }
    }

    public function testUpdateWithUnwritableDirDoesNotThrow(): void
    {
        // Create an unwritable config dir.
        $tmp = sys_get_temp_dir() . '/candy-flappy-test-' . uniqid();
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

    public function testInitArmsNoTimerBecauseTickingIsDeclaredInSubscriptions(): void
    {
        // C1 (lib-reviews-1102): an init()-armed one-shot re-armed per update
        // multiplies tick chains on every mid-flight restart. The single
        // source of the cadence is now subscriptions().
        $g = Game::start(static fn(int $_max): int => 0);
        $this->assertNull($g->init());
    }

    public function testAliveGameSubscribesToOneStableTickChain(): void
    {
        $g = Game::start(static fn(int $_max): int => 0);
        $subs = $g->subscriptions();
        $this->assertNotNull($subs);
        $all = $subs->all();
        $this->assertCount(1, $all);
        $this->assertSame('game-tick', $all[0]->id);
        $this->assertSame(\SugarCraft\Core\Kind::Tick, $all[0]->kind);
        $this->assertSame(Game::TICK_SECONDS, $all[0]->params['seconds']);
    }

    public function testCrashedGameWantsNoTickSubscription(): void
    {
        // m31: a crashed board must not keep a 33 ms chain spinning forever.
        $g = Game::start(static fn(int $_max): int => 0)->tickN(80);
        $this->assertTrue($g->crashed);
        $subs = $g->subscriptions();
        $this->assertTrue($subs === null || $subs->all() === []);
    }

    public function testTickUpdateNeverReArmsATimer(): void
    {
        $g = Game::start(static fn(int $_max): int => 0);
        [, $cmd] = $g->update(new TickMsg());
        $this->assertNull($cmd);
    }

    public function testRestartWhileAliveEmitsNoFreshTimerChain(): void
    {
        $g = Game::start(static fn(int $_max): int => 0);
        [$next, $cmd] = $g->update(new KeyMsg(KeyType::Char, 'r'));
        $this->assertFalse($next->crashed);
        // A restart must emit no timer at all: the Program keeps the single
        // already-running 'game-tick' subscription because the id is stable.
        $this->assertNull($cmd);
        $subs = $next->subscriptions();
        $this->assertNotNull($subs);
        $this->assertSame(['game-tick'], array_map(static fn($s): string => $s->id, $subs->all()));
    }

    public function testUpdateWithUnhandledMsgTypeReturnsSelf(): void
    {
        // An unrecognized Msg type should fall through to return [$this, null]
        $g = Game::start(static fn(int $_max): int => 0);
        $otherMsg = new class implements \SugarCraft\Core\Msg {};
        [$next, $cmd] = $g->update($otherMsg);
        $this->assertSame($g, $next);
        $this->assertNull($cmd);
    }

    public function testViewReturnsString(): void
    {
        $g = Game::start(static fn(int $_max): int => 0);
        $view = $g->view();
        $this->assertIsString($view);
        $this->assertNotEmpty($view);
    }

    public function testTickNAdvancesGameState(): void
    {
        $g = Game::start(static fn(int $_max): int => 0);
        $this->assertSame(0, $g->tickIndex);
        $g2 = $g->tickN(10);
        $this->assertSame(10, $g2->tickIndex);
        // Bird should have moved (fallen) due to gravity
        $this->assertNotEquals($g->bird->row(), $g2->bird->row());
    }

    public function testAdvanceDropsPipesOffScreen(): void
    {
        // A pipe at x=0 is off-screen immediately and should be dropped
        $bird = Bird::spawn(Game::BIRD_COL, 9.0);
        $pipe = new Pipe(0, gapY: 9, gapHeight: 6);
        $g = new Game(bird: $bird, pipes: [$pipe], highScores: []);
        $next = $g->tickN(1);
        $this->assertCount(0, $next->pipes);
    }

    public function testAdvanceSlidesPipesLeft(): void
    {
        $bird = Bird::spawn(Game::BIRD_COL, 9.0);
        $pipe = new Pipe(Game::WIDTH - 1, gapY: 9, gapHeight: 6);
        $g = new Game(bird: $bird, pipes: [$pipe], highScores: []);
        $next = $g->tickN(1);
        $this->assertCount(1, $next->pipes);
        $this->assertSame(Game::WIDTH - 2, $next->pipes[0]->x);
    }

    public function testHighScoreWithPositiveScoreEqualToCurrentHighDoesNotSetNewRecord(): void
    {
        $g = new Game(
            bird: Game::start(static fn(int $_max): int => 0)->bird,
            pipes: [],
            highScores: [10],
        );
        // Score equal to current high should not set newRecord
        $g2 = $g->withHighScore(10);
        $this->assertSame($g, $g2);
        $this->assertFalse($g2->newRecord);
    }

    public function testPersistHighScoresGracefullyHandlesWriteFailure(): void
    {
        // Create a pre-existing scores file that we make read-only.
        // When persistHighScores() tries to write and fails, the exception
        // is caught by the try/catch in update() and swallowed, so the
        // game loop continues without crashing.
        $tmp = sys_get_temp_dir() . '/candy-flappy-test-' . uniqid();
        mkdir($tmp . '/.candy-flappy', 0755, true);
        $path = $tmp . '/.candy-flappy/scores.json';
        file_put_contents($path, json_encode([1, 2, 3]));
        chmod($path, 0444); // read-only (may not work as root)

        // Create a bird below the floor so it crashes on advance()
        $bird = Bird::spawn(Game::BIRD_COL, (float) Game::HEIGHT);
        for ($i = 0; $i < 5; $i++) {
            $bird = $bird->tick();
        }
        $g = new Game(
            bird: $bird,
            pipes: [],
            score: 99,
            highScores: [],
            configDir: $tmp,
        );

        // update() should not throw even if persist fails - error is swallowed
        try {
            [$updated, $cmd] = $g->update(new TickMsg());
            $this->assertTrue($updated->crashed);
            $this->assertTrue($updated->newRecord);
            // The command should be a batch containing the persist cmd
            $this->assertNotNull($cmd);
        } finally {
            @chmod($path, 0644);
            @unlink($path);
            @rmdir($tmp . '/.candy-flappy');
            @rmdir($tmp);
        }
    }
}
