<?php

/**
 * candy-tetris/tests/IntroGateTest.php
 *
 * MA-24 pins for the first-run on-ramp: the card shows exactly when the
 * record has never seen it, the world parks behind it the way pause
 * parks it, 'begin' dismisses and marks (loudly refusing when the write
 * refuses), 'quit' leaves UNMARKED, versus is intro-free, and the card
 * speaks the LIVE begin tokens (m27).
 */

declare(strict_types=1);

namespace SugarCraft\Tetris\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Tetris\Bag;
use SugarCraft\Tetris\Game;
use SugarCraft\Tetris\GravityMsg;
use SugarCraft\Tetris\Support\Scores;
use SugarCraft\Tetris\VsGame;

final class IntroGateTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/candy-tetris-intro-' . uniqid('', true);
        mkdir($this->dir, 0700);
    }

    protected function tearDown(): void
    {
        foreach (scandir($this->dir) ?: [] as $leftover) {
            if ($leftover !== '.' && $leftover !== '..') {
                @unlink($this->dir . '/' . $leftover);
            }
        }
        @rmdir($this->dir);
    }

    public function testTheCardRidesTheFreshFrameWithTheLiveBeginTokens(): void
    {
        $frame = $this->intro(true)->view();
        self::assertStringContainsString('welcome to candy-tetris', $frame);
        self::assertStringContainsString('clear lines — do not reach the top', $frame);
        self::assertStringContainsString('press Space or Enter to play', $frame, 'begin defaults to Space/Enter spelled like the keycaps (candy-tetris-053)');
        self::assertStringContainsString('versus mode: candy-tetris -v', $frame);
    }

    public function testAGrantedOnboardedRecordNeverOpensTheCard(): void
    {
        // The launcher only seeds introPending on an un-onboarded record;
        // a seeded-false game must render the plain board byte-honestly.
        $game = $this->intro(false);
        self::assertStringNotContainsString('welcome to candy-tetris', $game->view());
    }

    public function testVersusBoardsNeverCarryTheCard(): void
    {
        self::assertStringNotContainsString('welcome to candy-tetris', VsGame::start()->view());
    }

    public function testGravityKeepsItsHeartbeatButNothingSteps(): void
    {
        $game = $this->intro(true);
        $before = $game->piece;

        [$next, $cmd] = $game->update(new GravityMsg($game->gravityChain));
        self::assertNotNull($cmd, 'the parked chain re-arms instead of dying');
        self::assertSame($before->kind, $next->piece->kind);
        self::assertSame($before->x, $next->piece->x);
        self::assertSame($before->y, $next->piece->y, 'the piece must not fall behind the card');
        self::assertTrue($next->gravityPending, 'exactly one tick stays in flight');
        self::assertTrue($next->introPending, 'the card STAYS up across ticks — gravity parks, it never dismisses');
    }

    public function testOrdinaryPlayKeysAreSwallowedBehindTheCard(): void
    {
        $game = $this->intro(true);
        [$held, $cmd] = $game->update(new KeyMsg(KeyType::Char, 'c'));
        self::assertNull($cmd);
        self::assertNull($held->hold, 'hold must not fire behind the card');
        self::assertSame($game->piece->y, $held->piece->y);

        [$moved] = $game->update(new KeyMsg(KeyType::Left, ''));
        self::assertSame($game->piece->x, $moved->piece->x, 'movement must not fire either');
    }

    public function testBeginDismissesAndMarksTheRecord(): void
    {
        $path = $this->dir . '/scores.json';
        [$next, $cmd] = $this->intro(true, $path)->update(new KeyMsg(KeyType::Space, ' '));

        self::assertNull($cmd, 'begin starts play; it is not an exit');
        self::assertFalse($next->introPending);
        self::assertFileExists($path);
        $read = Scores::load($path);
        self::assertInstanceOf(Scores::class, $read);
        self::assertTrue($read->onboarded);
        self::assertNull($read->soloBest, 'begin marks the visit, it does not settle a game');
        self::assertSame(0, $read->soloGames);
    }

    public function testBeginWithoutAPathStillDismissesAndWritesNothing(): void
    {
        [$next, $cmd] = $this->intro(true)->update(new KeyMsg(KeyType::Space, ' '));
        self::assertNull($cmd);
        self::assertFalse($next->introPending);
        self::assertNull($next->scores, 'no path means no mark object, not an empty one');
    }

    public function testQuitLeavesTheCardUnmarked(): void
    {
        $path = $this->dir . '/scores.json';
        [$same, $cmd] = $this->intro(true, $path)->update(new KeyMsg(KeyType::Char, 'q'));

        self::assertNotNull($cmd, 'q still quits from behind the card');
        self::assertTrue($same->introPending, 'the departing game was never shown play');
        self::assertFileDoesNotExist($path, 'a quit must not mark onboarded nor mint a file');
    }

    public function testAMarkedVisitSurvivesAWriteRefusalInTheMemoryState(): void
    {
        // The path names an EXISTING DIRECTORY: the atomic publish fails at
        // rename, announced loudly on stderr by the model itself.
        [$next] = $this->intro(true, $this->dir)->update(new KeyMsg(KeyType::Space, ' '));
        self::assertFalse($next->introPending, 'a refused mark must not strand the player on the card');
        self::assertNotNull($next->scores);
        self::assertTrue($next->scores->onboarded, 'the session-level dismissal still lands in memory');
    }

    public function testTheLauncherSeedsTheCardFromTheRecord(): void
    {
        $bin = (string) file_get_contents(__DIR__ . '/../bin/candy-tetris');
        self::assertStringContainsString('introPending: $scores === null || $scores->onboarded === false', $bin);
        self::assertStringContainsString('LaunchMode::VS => VsGame::start($bindings, $scores, $scoresPath)', $bin);
    }

    private function intro(bool $pending, ?string $scoresPath = null): Game
    {
        return Game::start(
            new Bag(static fn(int $_max): int => 0),
            scoresPath: $scoresPath,
            introPending: $pending,
        );
    }
}
