<?php

declare(strict_types=1);

namespace SugarCraft\Tetris\Tests;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Tetris\ComputerTickMsg;
use SugarCraft\Tetris\Game;
use SugarCraft\Tetris\GravityMsg;
use SugarCraft\Tetris\LaunchMode;
use SugarCraft\Tetris\VsGame;

/**
 * candy-tetris-018 / candy-tetris-026: the `--seed=N` door.
 *
 * The RNG seam always existed inside Bag; what was missing was the door —
 * a way for the CLI to pin a run. These pins hold the whole thread:
 * argv grammar → LaunchMode → Bag stream → Game factories → the VS
 * garbage-hole stream, plus the replay contract itself (same seed,
 * same sequence) and its negative controls (a different seed must
 * diverge — otherwise every equality pin here is vacuous).
 */
final class SeedReproducibilityTest extends TestCase
{
    // ------------------------------------------------------------------
    // Launch grammar
    // ------------------------------------------------------------------

    public function testSeedRidesEveryModeWithoutChangingIt(): void
    {
        self::assertSame(LaunchMode::SINGLE, LaunchMode::resolve(['candy-tetris', '--seed=42']));
        self::assertSame(42, LaunchMode::resolveSeed(['candy-tetris', '--seed=42']));

        self::assertSame(LaunchMode::VS, LaunchMode::resolve(['candy-tetris', '--vs', '--seed=42']));
        self::assertSame(42, LaunchMode::resolveSeed(['candy-tetris', '--vs', '--seed=42']));

        // Order-free: the seed names no mode, so it may lead.
        self::assertSame(LaunchMode::VS, LaunchMode::resolve(['candy-tetris', '--seed=-7', '--vs']));
        self::assertSame(-7, LaunchMode::resolveSeed(['candy-tetris', '--seed=-7', '--vs']));

        self::assertSame(LaunchMode::HELP, LaunchMode::resolve(['candy-tetris', '--seed=42', '--help']));

        // A plain launch simply has no seed.
        self::assertNull(LaunchMode::resolveSeed(['candy-tetris']));
        self::assertNull(LaunchMode::resolveSeed(['candy-tetris', '-v']));
    }

    public function testMalformedSeedTokensRefuseByName(): void
    {
        // Each refusal NAMES the token exactly as typed (mode.unknown
        // grammar, reused rather than a new key: a token that fails the
        // accepted surface is an unknown flag, honestly stated).
        $refusals = ['--seed', '--seed=x', '--seed=1x', '--seed='];
        foreach ($refusals as $token) {
            try {
                LaunchMode::resolve(['candy-tetris', $token]);
                self::fail("expected a refusal of {$token}");
            } catch (InvalidArgumentException $problem) {
                self::assertStringContainsString($token, $problem->getMessage());
            }
        }
    }

    public function testADuplicateSeedRefusesNamingTheSecondToken(): void
    {
        try {
            LaunchMode::resolve(['candy-tetris', '--seed=1', '--seed=2']);
            self::fail('expected the duplicate to refuse');
        } catch (InvalidArgumentException $problem) {
            self::assertStringContainsString('"--seed=2"', $problem->getMessage());
        }
    }

    // ------------------------------------------------------------------
    // Replay contract — pieces
    // ------------------------------------------------------------------

    public function testSameSeedReplaysTheSamePieceSequenceAndDivergentSeedsDoNot(): void
    {
        $a = self::spawnSequence(Game::start(seed: 42), 12);
        $b = self::spawnSequence(Game::start(seed: 42), 12);
        $c = self::spawnSequence(Game::start(seed: 43), 12);

        self::assertSame($a, $b, 'two runs, same seed, identical piece sequence');
        self::assertNotSame($a, $c, 'negative control: a different seed must diverge within 12 spawns');
    }

    // ------------------------------------------------------------------
    // Replay contract — garbage holes (the -026 thread)
    // ------------------------------------------------------------------

    public function testGarbageHolesRideTheSeedAndAdvanceAcrossArrivals(): void
    {
        $pairs = static function (int $seed): array {
            $g = Game::start(seed: $seed);
            $g = $g->addGarbage(2);
            $after2 = self::holeColumns($g);
            $g = $g->addGarbage(1);
            return [$after2, self::holeColumns($g)];
        };

        self::assertSame($pairs(4242), $pairs(4242), 'same seed → same holes, arrival after arrival');
        self::assertNotSame($pairs(4242), $pairs(9999), 'negative control: different seeds diverge in hole placement');
        // Exact stream identity (measured once, then pinned forever): the
        // hole sequence ADVANCES across arrivals — a mutant that re-derives
        // the stream per addGarbage (dropping the Game::mutate carry) makes
        // arrival 3 punch arrival 1's first hole (3) instead of 5.
        self::assertSame([3, 6], $pairs(4242)[0]);
        self::assertSame([3, 6, 5], $pairs(4242)[1]);
    }

    /** A whole-board garbage request still refuses loudly through the new door. */
    public function testAddGarbageStillRejectsAWholeBoardOfRows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Game::start(seed: 1)->addGarbage(\SugarCraft\Tetris\Board::ROWS);
    }

    // ------------------------------------------------------------------
    // Replay contract — the whole match
    // ------------------------------------------------------------------

    public function testVsMatchReplaysByteIdenticallyFromOneSeed(): void
    {
        $run = static function (int $seed): string {
            $vs = VsGame::start(seed: $seed, reproducible: true);
            for ($i = 0; $i < 240; $i++) {
                // Both clocks advance, exactly as Program would deliver them.
                [$vs, $_] = $vs->update(new GravityMsg($vs->gravityChain));
                [$vs, $_] = $vs->update(new ComputerTickMsg($vs->computerChain));
                if ($vs->over === true) {
                    break;
                }
            }

            return serialize([
                $vs->player->board->rows(),
                $vs->computer->board->rows(),
                $vs->player->piece->kind,
                $vs->computer->piece->kind,
                $vs->over,
                $vs->winner,
            ]);
        };

        self::assertSame($run(606), $run(606));
        // The two boards must not punch matching holes (stream split): a
        // shared stream would make the computer board a mirror of the
        // player board's garbage — compare their bottom rows after 120
        // full rounds below is overkill; the seed-mix equality is pinned:
        $vs = VsGame::start(seed: 606);
        self::assertSame(606, $vs->seed);
        self::assertSame(606 ^ 0x5BD1E995, $vs->computer->seed, 'board 2 rides the mixed stream');
        self::assertNotSame($vs->player->seed, $vs->computer->seed);
    }

    // ------------------------------------------------------------------
    // Seed survives restart (both spellings)
    // ------------------------------------------------------------------

    public function testSoloRestartKeepsTheSeedAndTheStamp(): void
    {
        $game = Game::start(seed: 9, reproducible: true)->mutate(['over' => true]);
        [$fresh, $_] = $game->update(new KeyMsg(KeyType::Char, 'r'));

        self::assertSame(9, $fresh->seed);
        self::assertTrue($fresh->reproducible);
        // The fresh lineage replays the SAME opening as a virgin seeded
        // launch: the seed pins the run, restart included.
        self::assertSame(
            self::spawnSequence(Game::start(seed: 9), 4),
            self::spawnSequence($fresh, 4),
        );
    }

    public function testVsRestartKeepsTheSeedAndTheStamp(): void
    {
        // VsGame::mutate() is private — the public ctor seeds the pair
        // the same way start() threads it.
        $vs = new VsGame(
            Game::start(seed: 77, reproducible: true),
            Game::start(seed: 77 ^ 0x5BD1E995),
            over: true,
            seed: 77,
            reproducible: true,
        );
        [$fresh, $_] = $vs->update(new KeyMsg(KeyType::Char, 'r'));

        self::assertSame(77, $fresh->seed);
        self::assertTrue($fresh->reproducible);
        self::assertSame(77, $fresh->player->seed);
        self::assertSame(77 ^ 0x5BD1E995, $fresh->computer->seed);
    }

    // ------------------------------------------------------------------
    // Banner stamp
    // ------------------------------------------------------------------

    public function testReproducibleRunsStampTheScoreCardAndPlainRunsDoNot(): void
    {
        $stamped = Game::start(seed: 5, reproducible: true)->view();
        self::assertStringContainsString('SEED 5', $stamped);

        // A hidden fresh seed (plain production launch) stamps nothing —
        // the law that keeps every pre-feature frame byte-identical.
        $plain = Game::start(seed: 5)->view();
        self::assertStringNotContainsString('SEED', $plain);
        self::assertStringNotContainsString('SEED', Game::start()->view());
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    /**
     * The kinds of the first $count spawned pieces, gravity-only drive
     * (no human input, no timers — a pure replay of the model).
     *
     * @return list<string>
     */
    private static function spawnSequence(Game $game, int $count): array
    {
        $kinds = [$game->piece->kind->name];
        $last  = $game->piece;

        for ($step = 0; $step < 4000 && count($kinds) < $count; $step++) {
            [$game, $_] = $game->update(new GravityMsg($game->gravityChain));
            if ($game->over === true) {
                break;
            }
            if ($game->piece !== $last) {
                $last  = $game->piece;
                $kinds[] = $game->piece->kind->name;
            }
        }

        return $kinds;
    }

    /**
     * @return list<int> hole column of every bottom-most garbage row present
     */
    private static function holeColumns(Game $game): array
    {
        $holes = [];
        foreach ($game->board->rows() as $row) {
            $hole = null;
            $full = true;
            foreach ($row as $col => $cell) {
                if ($cell === null) {
                    if ($hole !== null) {
                        $full = false; // two holes: not a garbage row
                        break;
                    }
                    $hole = $col;
                }
            }
            if ($full && $hole !== null) {
                $holes[] = $hole;
            }
        }

        return $holes;
    }
}
