<?php

declare(strict_types=1);

namespace SugarCraft\Flappy\Tests;

use SugarCraft\Core\BatchMsg;
use SugarCraft\Core\InputReader;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\ColorProfileMsg;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Core\Msg\WindowSizeMsg;
use SugarCraft\Core\Util\ColorProfile;
use SugarCraft\Flappy\Bird;
use SugarCraft\Flappy\Game;
use SugarCraft\Flappy\Pipe;
use SugarCraft\Flappy\TickMsg;
use PHPUnit\Framework\TestCase;

use SugarCraft\Flappy\Tests\Support\UsesHermeticConfig;

final class GameTest extends TestCase
{
    use UsesHermeticConfig;

    public function testInitialBirdPositionIsCentered(): void
    {
        $g = Game::start(static fn(int $_max): int => 0, $this->hermeticConfigDir());
        $this->assertSame(Game::BIRD_COL, $g->bird->x);
        $this->assertEqualsWithDelta(Game::HEIGHT / 2, $g->bird->body->position->y, 0.01);
        $this->assertSame(0, $g->score);
        $this->assertFalse($g->crashed);
        $this->assertSame([], $g->pipes);
    }

    public function testPipeSpawnsEveryNTicks(): void
    {
        $g = Game::start(static fn(int $_max): int => 0, $this->hermeticConfigDir())->tickN(Game::PIPE_EVERY);
        $this->assertCount(1, $g->pipes);
        // The pipe is appended at WIDTH-1 in the same tick that increments
        // the existing pipes, so its first observed x is exactly WIDTH-1.
        $this->assertSame(Game::WIDTH - 1, $g->pipes[0]->x);
    }

    public function testFlapResetsVelocity(): void
    {
        $g = Game::start(static fn(int $_max): int => 0, $this->hermeticConfigDir());
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
        $g = Game::start(static fn(int $_max): int => 0, $this->hermeticConfigDir());
        [, $cmd] = $g->update(new KeyMsg(KeyType::Char, 'q'));
        $this->assertNotNull($cmd);
    }

    public function testRestartFromCrashedState(): void
    {
        $g = Game::start(static fn(int $_max): int => 0, $this->hermeticConfigDir());
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
        $g = Game::start(static fn(int $_max): int => 0, $this->hermeticConfigDir())->tickN(80);
        $this->assertTrue($g->crashed);
        // tickN bypasses the crashed gate (it's a test helper that drives
        // advance() directly), so the bird keeps falling past the floor.
        // The old code COMPUTED that fall and pinned nothing (flappy-49):
        // assert the claim now — ten further ticks of gravity move the row.
        $rowAtCrash = $g->bird->row();
        $fallen = $g->tickN(10);
        $this->assertGreaterThan($rowAtCrash, $fallen->bird->row(), 'tickN keeps integrating past the floor by design');
        // Verify that update() returns the same model with no further work —
        // the runtime gate in update(TickMsg) will not advance the model.
        [$next, $cmd] = $g->update(new TickMsg());
        $this->assertSame($g, $next);
        $this->assertNull($cmd);
    }

    public function testDeterministicWithSeededRand(): void
    {
        // Both runs use the same closure, so pipe layouts should match.
        $rand = static fn(int $max): int => intdiv($max, 2);
        $a = Game::start($rand, $this->hermeticConfigDir())->tickN(60);
        $b = Game::start($rand, $this->hermeticConfigDir())->tickN(60);
        $this->assertSame(count($a->pipes), count($b->pipes));
        foreach ($a->pipes as $i => $pipe) {
            $this->assertSame($pipe->gapY, $b->pipes[$i]->gapY);
        }
    }

    public function testHighScoreReturnsZeroWhenNoScores(): void
    {
        $g = new Game(
            bird: Game::start(static fn(int $_max): int => 0, $this->hermeticConfigDir())->bird,
            pipes: [],
            highScores: [],
        );
        $this->assertSame(0, $g->highScore());
    }

    public function testWithHighScoreIsImmutable(): void
    {
        $g = new Game(
            bird: Game::start(static fn(int $_max): int => 0, $this->hermeticConfigDir())->bird,
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
            bird: Game::start(static fn(int $_max): int => 0, $this->hermeticConfigDir())->bird,
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
            bird: Game::start(static fn(int $_max): int => 0, $this->hermeticConfigDir())->bird,
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

    public function testConfigDirWithTrailingSlashMintsNoDoubleSlashSegments(): void
    {
        $method = new \ReflectionMethod(Game::class, 'scoreFilePath');
        $method->setAccessible(true);

        $this->assertSame(
            '/tmp/hermetic-flappy-slash/.candy-flappy/scores.json',
            $method->invoke(null, '/tmp/hermetic-flappy-slash/'),
        );
    }

    public function testAnUnresolvableConfigDirectoryFailsLoud(): void
    {
        $snapHome = getenv('HOME');
        $snapXdg = getenv('XDG_CONFIG_HOME');
        $serverHome = $_SERVER['HOME'] ?? null;
        $method = new \ReflectionMethod(Game::class, 'getDefaultConfigDir');
        $method->setAccessible(true);

        try {
            putenv('XDG_CONFIG_HOME');
            putenv('HOME');
            unset($_SERVER['HOME']);

            $this->expectException(\RuntimeException::class);
            $method->invoke(null);
        } finally {
            if ($snapXdg !== false) {
                putenv('XDG_CONFIG_HOME=' . $snapXdg);
            }
            if ($snapHome !== false) {
                putenv('HOME=' . $snapHome);
            }
            if ($serverHome !== null) {
                $_SERVER['HOME'] = $serverHome;
            }
        }
    }

    public function testRandAccessorReturnsInjectedClosure(): void
    {
        $sentinel = static fn(int $max): int => 42;
        $g = Game::start($sentinel, $this->hermeticConfigDir());
        $this->assertSame($sentinel, $g->rand());
    }

    /**
     * flappy-06 (finding -006): every transition builder must thread the
     * WHOLE field list through mutate(). This is the structural law that
     * kills the m30 class of bug — a hand-copied `new self(...)` silently
     * forgetting one field. The probe board below carries a distinct value
     * in EVERY slot; each transition asserts the slots it must not touch,
     * newRecord included (the pre-06 advance/withBird/withStarted omitted
     * it and were only incidentally green because no reachable live board
     * ever arrives there with a record flagged).
     */
    public function testMutateRoundTripPreservesEveryFieldThroughEachTransition(): void
    {
        $rand = static fn(int $max): int => $max;
        $configDir = new \ReflectionProperty(Game::class, 'configDir');
        $board = new Game(
            bird: Bird::spawn(Game::BIRD_COL, 5.0),
            pipes: [new Pipe(20, 9, 5)],
            score: 5,
            crashed: false,
            tickIndex: 7,
            highScores: [3, 9],
            newRecord: true,
            started: true,
            rand: $rand,
            configDir: '/tmp/flappy-mutate-probe',
            frameCols: 100,
            frameRows: 40,
            colorProfile: ColorProfile::Ansi256,
        );

        // Slots no transition below may move: keyed by property name.
        // $skip names the slots THAT transition is allowed to change.
        $inherited = static function (Game $next, array $skip) use ($board, $configDir): void {
            foreach ([
                'score' => $board->score,
                'crashed' => $board->crashed,
                'tickIndex' => $board->tickIndex,
                'highScores' => $board->highScores,
                'newRecord' => $board->newRecord,
                'started' => $board->started,
                'frameCols' => $board->frameCols,
                'frameRows' => $board->frameRows,
                'colorProfile' => $board->colorProfile,
            ] as $field => $want) {
                if (in_array($field, $skip, true)) {
                    continue;
                }
                self::assertSame($want, $next->$field, "mutate() dropped {$field}");
            }
            if (!in_array('pipes', $skip, true)) {
                self::assertSame($board->pipes, $next->pipes, 'mutate() re-rolled the pipes');
            }
            self::assertSame($board->rand(), $next->rand(), 'mutate() re-rolled the PRNG');
            self::assertSame($configDir->getValue($board), $configDir->getValue($next), 'mutate() dropped configDir');
        };

        [$windowed, ] = $board->update(new WindowSizeMsg(120, 50));
        $this->assertSame([120, 50], [$windowed->frameCols, $windowed->frameRows]);
        $this->assertSame($board->bird, $windowed->bird);
        $inherited($windowed, ['frameCols', 'frameRows']);

        [$profiled, ] = $board->update(new ColorProfileMsg(ColorProfile::TrueColor));
        $this->assertSame(ColorProfile::TrueColor, $profiled->colorProfile);
        $inherited($profiled, ['colorProfile']);

        // A tick legitimately evolves bird/pipes/score/tickIndex — the
        // assertion that matters here is the CARRIAGE of everything else,
        // newRecord first among them (the thread 06 makes structural).
        [$ticked, ] = $board->update(new TickMsg());
        $this->assertNotSame($board->bird, $ticked->bird);
        $this->assertSame(8, $ticked->tickIndex);
        $this->assertTrue($ticked->newRecord);
        $inherited($ticked, ['score', 'crashed', 'tickIndex', 'pipes']);

        [$flapped, ] = $board->update(new KeyMsg(KeyType::Space, ''));
        $this->assertNotSame($board->bird, $flapped->bird);
        $inherited($flapped, []);

        $merged = $board->withHighScore(12);
        $this->assertSame([3, 9, 12], $merged->highScores);
        $this->assertTrue($merged->newRecord);
        $inherited($merged, ['highScores']);
    }

    /**
     * flappy-47 (finding -047): a resize mid-flight must carry the LIVE
     * board, not re-seed it. The -06 round-trip pins hand-built boards;
     * this one exercises the real path — flap, several dozen ticks (pipes
     * on the board, score moving), then WindowSizeMsg — and asserts every
     * other readonly field rides through, PRNG identity included. A
     * withFrameSize() that dropped pipes or re-ran start() mid-flight
     * reddens here (previously nothing pinned it: resizes were exercised
     * from fresh boards only).
     */
    public function testResizeMidFlightPreservesTheLiveBoardState(): void
    {
        $rand = static fn(int $max): int => intdiv($max, 2);
        $g = Game::start($rand, $this->hermeticConfigDir());
        [$g, ] = $g->update(new KeyMsg(KeyType::Space, ''));
        $g = $g->tickN(2 * Game::PIPE_EVERY + 2);
        $this->assertNotEmpty($g->pipes, 'probe board should carry pipes by now');
        $this->assertTrue($g->started);

        [$resized, $cmd] = $g->update(new WindowSizeMsg(100, 40));
        $this->assertNull($cmd);
        $this->assertSame([100, 40], [$resized->frameCols, $resized->frameRows]);
        $this->assertSame($g->bird, $resized->bird, 'resize moved the bird');
        $this->assertSame($g->pipes, $resized->pipes, 'resize dropped the pipes');
        $this->assertSame($g->score, $resized->score, 'resize reset the score');
        $this->assertSame($g->crashed, $resized->crashed);
        $this->assertSame($g->tickIndex, $resized->tickIndex, 'resize rewound the clock');
        $this->assertSame($g->highScores, $resized->highScores);
        $this->assertTrue($resized->started);
        $this->assertSame($g->rand(), $resized->rand(), 'resize re-rolled the PRNG');
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

    public function testQuarantineKeepsEveryGenerationOfARePoisonedSave(): void
    {
        // n6 (step 14): the old bare rename() silently REPLACED a same-second
        // `.corrupt-<ts>` artifact, so the forensic copies the readScores
        // docblock promises were destroyed by each fresh poisoning within the
        // same second. The link()-based quarantine refuses to overwrite: five
        // same-second poisonings must keep five distinct artifacts, each with
        // its own generation's bytes, and the original path must be gone.
        $tmp = sys_get_temp_dir() . '/candy-flappy-quarantine-repeat-' . getmypid();
        mkdir($tmp . '/.candy-flappy', 0755, true);
        $target = $tmp . '/.candy-flappy/scores.json';
        $quarantine = new \ReflectionMethod(Game::class, 'quarantine');
        for ($generation = 1; $generation <= 5; $generation++) {
            file_put_contents($target, "poison-generation-{$generation}");
            $quarantine->invoke(null, $target);
            $this->assertFileDoesNotExist($target, "generation {$generation} must be moved aside");
        }
        $artifacts = array_values(array_filter(
            scandir($tmp . '/.candy-flappy'),
            static fn(string $f): bool => str_starts_with($f, 'scores.json.corrupt-'),
        ));
        $this->assertCount(5, $artifacts, 'no quarantine generation may replace an earlier same-second one');
        $this->assertCount(5, array_unique($artifacts), 'artifact names must be distinct');
        $bodies = array_map(
            static fn(string $f): string => (string) file_get_contents($tmp . '/.candy-flappy/' . $f),
            $artifacts,
        );
        sort($bodies);
        $this->assertSame(
            ['poison-generation-1', 'poison-generation-2', 'poison-generation-3',
             'poison-generation-4', 'poison-generation-5'],
            $bodies,
            'every generation keeps its own bytes',
        );
        array_map('unlink', array_map(
            static fn(string $f): string => $tmp . '/.candy-flappy/' . $f,
            $artifacts,
        ));
        @rmdir($tmp . '/.candy-flappy');
        @rmdir($tmp);
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
            started: true,
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
            bird: Game::start(static fn(int $_max): int => 0, $this->hermeticConfigDir())->bird,
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
                started: true,
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
        $g = Game::start(static fn(int $_max): int => 0, $this->hermeticConfigDir());
        $this->assertNull($g->init());
    }

    public function testAliveGameSubscribesToOneStableTickChain(): void
    {
        $g = Game::start(static fn(int $_max): int => 0, $this->hermeticConfigDir());
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
        $g = Game::start(static fn(int $_max): int => 0, $this->hermeticConfigDir())->tickN(80);
        $this->assertTrue($g->crashed);
        $subs = $g->subscriptions();
        $this->assertTrue($subs === null || $subs->all() === []);
    }

    public function testTickUpdateNeverReArmsATimer(): void
    {
        $g = Game::start(static fn(int $_max): int => 0, $this->hermeticConfigDir());
        [, $cmd] = $g->update(new TickMsg());
        $this->assertNull($cmd);
    }

    public function testRestartWhileAliveEmitsNoFreshTimerChain(): void
    {
        $g = Game::start(static fn(int $_max): int => 0, $this->hermeticConfigDir());
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
        $g = Game::start(static fn(int $_max): int => 0, $this->hermeticConfigDir());
        $otherMsg = new class implements \SugarCraft\Core\Msg {};
        [$next, $cmd] = $g->update($otherMsg);
        $this->assertSame($g, $next);
        $this->assertNull($cmd);
    }

    public function testViewReturnsString(): void
    {
        $g = Game::start(static fn(int $_max): int => 0, $this->hermeticConfigDir());
        $view = $g->view();
        $this->assertIsString($view);
        $this->assertNotEmpty($view);
    }

    public function testTickNAdvancesGameState(): void
    {
        $g = Game::start(static fn(int $_max): int => 0, $this->hermeticConfigDir());
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
            bird: Game::start(static fn(int $_max): int => 0, $this->hermeticConfigDir())->bird,
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
            started: true,
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

    // ------------------------------------------------------------------
    // Playability block (M10/M18/M19/M20/M24/m28/m29, lib-reviews-1102)
    // ------------------------------------------------------------------

    public function testReadyBoardFreezesUnderTicks(): void
    {
        // M10: an unstarted board ignores TickMsg entirely — no gravity,
        // no scroll, no pipes. The old launch dropped the bird straight
        // toward the floor before the player could react.
        $g = Game::start(static fn(int $_max): int => 0, $this->hermeticConfigDir());
        $before = $g->update(new TickMsg())[0];
        $after = $before->update(new TickMsg())[0];
        $this->assertSame($g->bird->row(), $after->bird->row());
        $this->assertSame(0, $after->tickIndex);
        $this->assertSame([], $after->pipes);
    }

    public function testFirstFlapStartsTheRun(): void
    {
        // M10: the flap both kicks the bird and leaves the ready phase;
        // ticks after it move the world.
        $g = Game::start(static fn(int $_max): int => 0, $this->hermeticConfigDir());
        [$g, ] = $g->update(new KeyMsg(KeyType::Space));
        $g = $g->update(new TickMsg())[0];
        $this->assertSame(1, $g->tickIndex);
    }

    public function testEnterFlapsLikeSpace(): void
    {
        // M20: Enter is a flap/start key (README promises a one-key start).
        $g = Game::start(static fn(int $_max): int => 0, $this->hermeticConfigDir());
        $row = $g->bird->row();
        [$g, ] = $g->update(new KeyMsg(KeyType::Enter));
        $g = $g->update(new TickMsg())[0];
        $this->assertSame(1, $g->tickIndex);
        // The Enter board actually kicked the bird upward (one tick is
        // sub-cell; four ticks of lift clear a whole row).
        $this->assertNotSame($row, $g->tickN(4)->bird->row());
    }

    public function testMidFlightRestartReturnsAReadyBoard(): void
    {
        // M19: 'r' works any time; with M10 it lands on the READY card,
        // not a falling board.
        $g = Game::start(static fn(int $_max): int => 0, $this->hermeticConfigDir());
        [$g, ] = $g->update(new KeyMsg(KeyType::Space));
        $g = $g->update(new TickMsg())[0];
        [$g, ] = $g->update(new KeyMsg(KeyType::Char, 'r'));
        $this->assertSame(0, $g->score);
        $this->assertSame([], $g->pipes);
        $this->assertFalse($g->crashed);
        // Ready: a tick does nothing until the next flap.
        $frozen = $g->update(new TickMsg())[0];
        $this->assertSame(0, $frozen->tickIndex);
    }

    public function testQuitSettlesAQualifyingMidFlightScore(): void
    {
        // M18: q with a live record writes the leaderboard before quitting.
        $tmp = sys_get_temp_dir() . '/candy-flappy-settle-' . getmypid() . '-' . spl_object_id($this);
        $bird = Bird::spawn(Game::BIRD_COL, Game::HEIGHT / 2.0);
        $g = new Game(
            bird: $bird,
            pipes: [],
            score: 12,
            started: true,
            highScores: [],
            configDir: $tmp,
        );
        [, $cmd] = $g->update(new KeyMsg(KeyType::Char, 'q'));
        $this->assertNotNull($cmd);
        // Cmd::batch is a data sentinel: the Program explodes it. Drive the
        // children exactly as runCmd does.
        $batch = $cmd();
        $this->assertInstanceOf(BatchMsg::class, $batch);
        foreach ($batch->cmds as $child) {
            $child();
        }
        $target = $tmp . '/.candy-flappy/scores.json';
        $this->assertFileExists($target);
        $this->assertSame([12], json_decode((string) file_get_contents($target), true, 512, JSON_THROW_ON_ERROR));
        @unlink($target);
        @rmdir($tmp . '/.candy-flappy');
        @rmdir($tmp);
    }

    public function testQuitDoesNotDoublePersistAfterACrash(): void
    {
        // The crash already persisted; q must be a bare quit, no second write.
        $tmp = sys_get_temp_dir() . '/candy-flappy-nodbl-' . getmypid() . '-' . spl_object_id($this);
        // Live board with a score, dropped onto the floor through the
        // production tick path so the crash persist really runs first.
        $g = new Game(
            bird: Bird::spawn(Game::BIRD_COL, Game::HEIGHT / 2.0),
            pipes: [],
            score: 7,
            started: true,
            highScores: [],
            configDir: $tmp,
        );
        for ($i = 0; $i < 400 && $g->crashed === false; $i++) {
            [$g, $cmd] = $g->update(new TickMsg());
            if ($cmd !== null) {
                $cmd();
            }
        }
        $this->assertTrue($g->crashed);
        $this->assertSame([7], json_decode((string) file_get_contents($tmp . '/.candy-flappy/scores.json'), true));
        $target = $tmp . '/.candy-flappy/scores.json';
        $this->assertFileExists($target);
        $mtime = (int) filemtime($target);
        [$next, $cmd] = $g->update(new KeyMsg(KeyType::Char, 'q'));
        $this->assertNotNull($cmd);
        $this->assertSame($g, $next, 'a crashed board quits as-is, no re-merge');
        $cmd();
        clearstatcache();
        $this->assertFileExists($target);
        $this->assertSame($mtime, (int) filemtime($target), 'quit after crash must not rewrite the save');
        @unlink($target);
        @rmdir($tmp . '/.candy-flappy');
        @rmdir($tmp);
    }

    public function testReadyViewAdvertisesTheStartKey(): void
    {
        $g = Game::start(static fn(int $_max): int => 0, $this->hermeticConfigDir());
        $this->assertStringContainsString('to start', $g->view());
        $this->assertStringNotContainsString('space/⏎/↑/w flap', $g->view());
    }

    public function testLiveStatusCarriesTheStandingBest(): void
    {
        // m28: 'best:' is no longer death-screen-only.
        $g = new Game(
            bird: Bird::spawn(Game::BIRD_COL, Game::HEIGHT / 2.0),
            pipes: [],
            score: 3,
            started: true,
            highScores: [42],
            configDir: $this->hermeticConfigDir(),
        );
        $this->assertStringContainsString('best: 42', $g->view());
    }

    public function testCrashCopySaysTryAgainNotFlapAgain(): void
    {
        // M24: 'r' restarts, it does not flap — the copy must match the key.
        $g = Game::start(static fn(int $_max): int => 0, $this->hermeticConfigDir())->tickN(80);
        $this->assertTrue($g->crashed);
        $view = $g->view();
        $this->assertStringContainsString('press r to try again', $view);
        $this->assertStringNotContainsString('flap again', $view);
    }

    public function testCrashedBoardDrawsTheSplatGlyph(): void
    {
        // m29: a burst glyph is actually on the board. n9: the board carries
        // the half-width ✕ (the hint's full-width 💥 would overflow the
        // 62-column floor row by one cell and clip the border); the 💥 lives
        // on in the crash hint.
        // A floor crash leaves the frame (the bird is below it), so pin the
        // glyph with an in-field crash board instead.
        $g = new Game(
            bird: Bird::spawn(Game::BIRD_COL, Game::HEIGHT / 2.0),
            pipes: [],
            score: 3,
            crashed: true,
            highScores: [42],
            configDir: $this->hermeticConfigDir(),
        );
        // One ✕ on the board (m29/n9) and one 💥 in the crash hint (M24 copy).
        $this->assertSame(1, substr_count($g->view(), '✕'));
        $this->assertSame(1, substr_count($g->view(), '💥'));
    }

    public function testShiftedLetterKeysActLikeTheirLowerCase(): void
    {
        // m4: terminals deliver Shift+w as 'W'; the game used to ignore it.
        $g = Game::start(static fn(int $_m): int => 0, $this->hermeticConfigDir());
        $g = $g->update(new KeyMsg(KeyType::Char, rune: 'W'))[0];
        self::assertTrue($g->started);

        $flipped = $g->update(new KeyMsg(KeyType::Space))[0];
        $g = $flipped->update(new KeyMsg(KeyType::Char, rune: 'R'));
        self::assertTrue($g[0]->pipes === [] && $g[0]->started === false,
            'uppercase R restarts to the ready card like r');

        $quit = $g[0]->update(new KeyMsg(KeyType::Char, rune: 'Q'))[1];
        self::assertNotNull($quit);
    }

    public function testCtrlChordsAreInertExceptCtrlC(): void
    {
        // m1 (review round 2): Ctrl+C quit was documented and gated, but the
        // ledger overclaimed full chord gating - Ctrl+Q quit and Ctrl+W
        // flapped until this pin. The rule now: Ctrl+C is the ONLY chord the
        // game reads; every other ctrl-combination does nothing.
        $g = Game::start(static fn(int $_m): int => 0, $this->hermeticConfigDir());
        [$afterW, $cmdW] = $g->update(new KeyMsg(KeyType::Char, 'w', ctrl: true));
        self::assertFalse($afterW->started, 'Ctrl+W must not flap or start');
        self::assertNull($cmdW);

        [$afterQ, $cmdQ] = $g->update(new KeyMsg(KeyType::Char, 'q', ctrl: true));
        self::assertNull($cmdQ, 'Ctrl+Q must not quit');
        self::assertSame($g, $afterQ);

        [$afterE, $cmdE] = $g->update(new KeyMsg(KeyType::Space, ctrl: true));
        self::assertNull($cmdE, 'Ctrl+Space must not flap');

        // The one chord that IS documented stays alive.
        [, $cmdC] = $g->update(new KeyMsg(KeyType::Char, 'c', ctrl: true));
        self::assertNotNull($cmdC, 'Ctrl+C still quits');

        // n4 (step 13): Escape carries the same ctrl gate its q/r siblings
        // do — under kitty extended reporting Ctrl+[ arrives as
        // Escape+ctrl=true (InputReader maps code 27 with the modifier
        // bits) and must be inert, while a plain Esc still quits. Legacy
        // Ctrl+[ is the bare 0x1B byte and decodes with ctrl=false, so it
        // is hardware-identical to Esc and quits by byte identity.
        [$afterCe, $cmdCe] = $g->update(new KeyMsg(KeyType::Escape, ctrl: true));
        self::assertNull($cmdCe, 'Escape with ctrl set must not quit');
        self::assertSame($g, $afterCe);

        [, $cmdEsc] = $g->update(new KeyMsg(KeyType::Escape));
        self::assertNotNull($cmdEsc, 'plain Esc still quits');
    }

    public function testCrashedBoardIgnoresCtrlEscapeStillQuitsOnPlainEsc(): void
    {
        // n4 (step 13): the quit arm is shared by the live and the crashed
        // board — the original finding sat on the crash branch, so pin the
        // posture there explicitly: Ctrl+Esc inert, plain Esc quits.
        $g = new Game(
            bird: Bird::spawn(Game::BIRD_COL, Game::HEIGHT / 2.0),
            pipes: [],
            score: 3,
            crashed: true,
            configDir: $this->hermeticConfigDir(),
        );
        [$after, $cmd] = $g->update(new KeyMsg(KeyType::Escape, ctrl: true));
        self::assertNull($cmd, 'Escape with ctrl set must not quit a crashed board');
        self::assertSame($g, $after);

        [, $cmdEsc] = $g->update(new KeyMsg(KeyType::Escape));
        self::assertNotNull($cmdEsc, 'plain Esc still quits a crashed board');
    }

    public function testLegacyCtrlBracketIsByteIdenticalToEscape(): void
    {
        // n4 (step 13) probe record. Ctrl+[ physically sends the single
        // 0x1B byte — identical to what the Esc key sends; no consumer can
        // tell them apart at byte level. sugar-core's InputReader buffers a
        // lone 0x1B awaiting disambiguation (Program polls
        // hasPendingEscape() and promotes after a short timeout via
        // flushPending()), and the promotion is a plain KeyType::Escape with
        // the ctrl flag never set. That is why the README and the Game
        // docblock state legacy Ctrl+[ quits by hardware identity while the
        // model-level gate binds only the protocol-flagged chord (kitty
        // 27;5u) — pinning the decoder shape so a future change to it must
        // revisit that claim.
        $reader = new InputReader();
        self::assertSame([], $reader->parse("\x1b"), 'a lone ESC byte buffers, awaiting disambiguation');
        self::assertTrue($reader->hasPendingEscape());
        $msg = $reader->flushPending();
        self::assertInstanceOf(KeyMsg::class, $msg);
        self::assertSame(KeyType::Escape, $msg->type);
        self::assertFalse($msg->ctrl, 'the promoted Escape never carries ctrl — legacy Ctrl+[ is indistinguishable from Esc');
    }

    public function testSettleOnExitSavesALiveQualifyingScore(): void
    {
        // The bin-level safety net for signal death (M18): a started,
        // un-crashed board with a qualifying score must land on disk even
        // though no quit KeyMsg ever ran.
        $dir = $this->hermeticConfigDir();
        $g = new Game(
            bird: Bird::spawn(0, 9.0),
            pipes: [],
            score: 12,
            started: true,
            configDir: $dir,
        );
        $g->settleOnExit();
        $file = $dir . '/.candy-flappy/scores.json';
        self::assertFileExists($file);
        self::assertSame([12], json_decode((string) file_get_contents($file), true));

        // Idempotent: settling again writes the same bytes, no duplicate.
        $g->settleOnExit();
        self::assertSame([12], json_decode((string) file_get_contents($file), true));
    }

    public function testSettleOnExitIgnoresBoardsWithNothingToSettle(): void
    {
        $dir = $this->hermeticConfigDir();
        $file = $dir . '/.candy-flappy/scores.json';

        // Ready board (never started): nothing earned yet.
        (new Game(bird: Bird::spawn(0, 9.0), pipes: [], score: 0, configDir: $dir))->settleOnExit();
        // Zero score started board: nothing qualifies.
        (new Game(bird: Bird::spawn(0, 9.0), pipes: [], score: 0, started: true, configDir: $dir))->settleOnExit();
        self::assertFileDoesNotExist($file, 'non-qualifying boards must not create the save');

        // A crashed board already persisted through the crash Cmd; the
        // safety net must NOT double-dip (guard: crashed short-circuits).
        $crashed = new Game(
            bird: Bird::spawn(0, 9.0),
            pipes: [],
            score: 9,
            crashed: true,
            started: true,
            configDir: $dir,
        );
        $crashed->settleOnExit();
        self::assertFileDoesNotExist($file, 'crash-path persistence belongs to update(), not settleOnExit()');
    }
}
