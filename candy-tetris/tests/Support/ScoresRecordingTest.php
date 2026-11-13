<?php

/**
 * candy-tetris/tests/Support/ScoresRecordingTest.php
 *
 * MA-23 end-to-end pins: a real lock-and-spawn top-out through the public
 * update() surface must settle the record exactly once, the banner must
 * answer against it, versus deaths must tally W/L without ever touching
 * the solo best, and a refused write must stay inside the model.
 */

declare(strict_types=1);

namespace SugarCraft\Tetris\Tests\Support;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Tetris\Bag;
use SugarCraft\Tetris\Board;
use SugarCraft\Tetris\Game;
use SugarCraft\Tetris\Lang;
use SugarCraft\Tetris\Piece;
use SugarCraft\Tetris\Score;
use SugarCraft\Tetris\Support\Scores;
use SugarCraft\Tetris\Tetromino;
use SugarCraft\Tetris\VsGame;

final class ScoresRecordingTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/candy-tetris-record-' . uniqid('', true);
        mkdir($this->dir, 0700);
    }

    protected function tearDown(): void
    {
        foreach (scandir($this->dir) ?: [] as $leftover) {
            if ($leftover === '.' || $leftover === '..') {
                continue;
            }
            $full = $this->dir . '/' . $leftover;
            if (is_dir($full)) {
                @rmdir($full);
                continue;
            }
            @unlink($full);
        }
        @rmdir($this->dir);
    }

    public function testTheFirstSoloDeathWritesTheRecordAndFlagsANewBest(): void
    {
        $path = $this->dir . '/scores.json';
        [$after] = $this->topOut(Game::start($this->bag(), scoresPath: $path), 500);

        self::assertTrue($after->over);
        self::assertTrue($after->newBest, 'an empty record is always beatable');

        $read = Scores::load($path);
        self::assertInstanceOf(Scores::class, $read);
        self::assertSame(500, $read->soloBest);
        self::assertSame(1, $read->soloGames, 'one transition, one settlement');
        self::assertSame(0, $read->versusWins);

        $frame = $after->view();
        self::assertStringContainsString(Lang::t('over.best', ['points' => 500]), $frame);
        self::assertStringContainsString(Lang::t('over.new_best'), $frame);
        // candy-tetris-096: the finished-games denominator rode the JSON
        // unseen — the banner now reads the score against it.
        self::assertStringContainsString(Lang::t('over.games', ['games' => 1]), $frame);
    }

    public function testAQuietRestartCarriesTheRecordAndTheHigherBestWins(): void
    {
        $path = $this->dir . '/scores.json';
        [$first] = $this->topOut(Game::start($this->bag(), scoresPath: $path), 500);

        [$second] = $first->update(new KeyMsg(KeyType::Char, 'r'));
        self::assertFalse($second->over, 'r restarts with the record in hand');
        self::assertNotNull($second->scores);
        self::assertSame(500, $second->scores->soloBest, 'the restart must not clobber the loaded record');

        [$lower] = $this->topOut($second, 300);
        self::assertTrue($lower->over);
        self::assertFalse($lower->newBest, '300 is not a new best against 500');

        $read = Scores::load($path);
        self::assertInstanceOf(Scores::class, $read);
        self::assertSame(500, $read->soloBest, 'the best never moves backwards across sessions of one run');
        self::assertSame(2, $read->soloGames);

        $frame = $lower->view();
        self::assertStringContainsString(Lang::t('over.best', ['points' => 500]), $frame);
        self::assertStringNotContainsString(Lang::t('over.new_best'), $frame);
    }

    public function testASaveRefusalStaysInsideTheModel(): void
    {
        // A path that is a directory can never be published over: fopen on
        // the temp target inside AtomicJsonFile throws, and settled() must
        // announce-and-continue rather than escalate out of update().
        $path = $this->dir . '/scores.json';
        mkdir($path);

        [$after] = $this->topOut(Game::start($this->bag(), scoresPath: $path), 500);

        // The write threw inside settled() and was announced, not escalated:
        // the game itself is still properly over and still knows its best.
        self::assertTrue($after->over);
        self::assertNotNull($after->scores);
        self::assertSame(500, $after->scores->soloBest);
        self::assertDirectoryExists($path);
        self::assertSame(
            ['.', '..'],
            array_values(scandir($path) ?: []),
            'the refused write must leave no residue inside the path',
        );
    }

    public function testVersusDeathTalliesALossAndNeverPollutesTheSoloBest(): void
    {
        $path = $this->dir . '/scores.json';
        $rows = array_map(
            static fn(): array => array_fill(0, Board::COLS, null),
            range(1, Board::ROWS),
        );
        for ($r = 0; $r < 2; $r++) {
            for ($col = 1; $col < Board::COLS; $col++) {
                $rows[$r][$col] = Tetromino::I;
            }
        }
        $blocked = new Board($rows);
        $blockedPlayer = Game::start($this->bag())->mutate([
            'board' => $blocked,
            'piece' => new Piece(Tetromino::O, 0, 0, 22),
        ]);

        // Built through the public constructor so the two seats are known:
        // only the MATCH carries the path — the inner games answer with
        // null paths by design and must never settle a solo record.
        $match = new VsGame(
            $blockedPlayer,
            Game::start($this->bag()),
            scoresPath: $path,
        );

        // A versus match dispatches every non-chord press through to the
        // player board, so the same hard-drop that tops out solo ends the
        // match here — through withComputerWinner, the single tally choke.
        $guard = 0;
        while ($match->over === false && $guard++ < 20) {
            [$match] = $match->update(new KeyMsg(KeyType::Char, ' '));
        }

        self::assertTrue($match->over, 'the blocked player board must end the match');
        self::assertSame('COMPUTER', $match->winner);

        $read = Scores::load($path);
        self::assertInstanceOf(Scores::class, $read);
        self::assertSame(1, $read->versusLosses);
        self::assertSame(0, $read->versusWins);
        self::assertNull($read->soloBest, 'the inner boards answer with null paths by design');
        self::assertSame(0, $read->soloGames);

        $frame = $match->view();
        self::assertStringContainsString(Lang::t('vs.record', ['wins' => 0, 'losses' => 1]), $frame);
    }

    public function testAnUnwiredGameWritesNothingAtAll(): void
    {
        // The pre-MA-23 contract survives: every existing caller that never
        // names a path keeps the old write-free behavior byte-for-byte.
        $path = $this->dir . '/scores.json';
        [$after] = $this->topOut(Game::start($this->bag()), 500);

        self::assertTrue($after->over);
        self::assertNull($after->scores, 'no path means no record, not an empty one');
        self::assertFalse($after->newBest);
        self::assertFileDoesNotExist($path);
        self::assertStringNotContainsString(Lang::t('over.new_best'), $after->view());
    }

    public function testTheLauncherWiresThePathAndAnnouncesCorruption(): void
    {
        $bin = (string) file_get_contents(__DIR__ . '/../../bin/candy-tetris');
        self::assertStringContainsString('Paths::scores()', $bin);
        self::assertStringContainsString('Scores::load($scoresPath)', $bin);
        // The announcement is catalogue-owned since candy-tetris-028: the
        // launcher wires the key, the phrase lives in the source of truth.
        self::assertStringContainsString("Lang::t('scores.ignored'", $bin);
        $en = (string) file_get_contents(__DIR__ . '/../../lang/en.php');
        self::assertStringContainsString('ignoring unreadable scores file', $en);
    }

    /**
     * Deterministic solo top-out (GameTest's shape): cols 1-9 of the
     * VISIBLE-TOP spawn rows (candy-tetris-093) blocked, the live O parked
     * on the floor, one hard drop to lock and spawn into the wall.
     *
     * @return array{0:Game,1:?\Closure}
     */
    private function topOut(Game $game, int $points): array
    {
        $rows = $game->board->rows();
        for ($r = Board::HIDDEN_ROWS; $r < Board::HIDDEN_ROWS + 2; $r++) {
            for ($col = 1; $col < Board::COLS; $col++) {
                $rows[$r][$col] = Tetromino::I;
            }
        }

        return $game
            ->mutate([
                'board' => new Board($rows),
                'piece' => new Piece(Tetromino::O, 0, 0, 22),
                'score' => new Score(points: $points),
            ])
            ->update(new KeyMsg(KeyType::Char, ' '));
    }

    private function bag(): Bag
    {
        return new Bag(static fn(int $_max): int => 0);
    }
}
