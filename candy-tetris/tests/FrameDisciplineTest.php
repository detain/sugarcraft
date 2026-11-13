<?php

/**
 * candy-tetris/tests/FrameDisciplineTest.php
 *
 * candy-tetris-025/-092: when the loop owns the terminal, the FRAME is
 * the only honest channel. The score-save failures used to fwrite(STDERR)
 * from inside update() — raw lines across the live alt-screen, corrupting
 * the very frame the next paint recovered from — and line clears,
 * level-ups and incoming garbage were simply silent (GC-006: the banner
 * machinery is 2 lines from delivering it). Both roads now end in the
 * pending notice slot, painted through Support\Paint's band machinery.
 *
 * Lifetime law pinned here: the slot survives ticks (a death-screen save
 * refusal must still be readable), and the player's own next press
 * retires it — attention moved, the notice may go.
 */

declare(strict_types=1);

namespace SugarCraft\Tetris\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Tetris\Bag;
use SugarCraft\Tetris\Board;
use SugarCraft\Tetris\Game;
use SugarCraft\Tetris\GravityMsg;
use SugarCraft\Tetris\Lang;
use SugarCraft\Tetris\Piece;
use SugarCraft\Tetris\Score;
use SugarCraft\Tetris\Tetromino;
use SugarCraft\Tetris\VsGame;

final class FrameDisciplineTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/candy-tetris-discipline-' . uniqid('', true);
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
                @chmod($full, 0700);
                @rmdir($full);
                continue;
            }
            @unlink($full);
        }
        @rmdir($this->dir);
    }

    private function bag(): Bag
    {
        return new Bag(static fn(int $_max): int => 0);
    }

    /**
     * A board whose bottom rows are complete except columns 1-2, so the
     * O piece at box (0,22) clears exactly $height rows from a grounded
     * lock (the well fixture from the scoring pins).
     */
    private function almostFullBottom(int $height): Board
    {
        $rows = array_map(
            static fn(): array => array_fill(0, Board::COLS, null),
            range(1, Board::ROWS),
        );
        for ($r = Board::ROWS - $height; $r < Board::ROWS; $r++) {
            for ($col = 0; $col < Board::COLS; $col++) {
                if ($col !== 1 && $col !== 2) {
                    $rows[$r][$col] = Tetromino::I;
                }
            }
        }

        return new Board($rows);
    }

    /** Drive one gravity tick on the live chain (grounded piece → lock). */
    private function tick(Game $game): Game
    {
        [$next] = $game->update(new GravityMsg($game->gravityChain));

        return $next;
    }

    // ------------------------------------------------------------------
    // -025: the save refusal is a frame line, never a raw write.
    // ------------------------------------------------------------------

    public function testASaveRefusalRidesTheFrameInsteadOfStderr(): void
    {
        $path = $this->dir . '/scores.json';
        mkdir($path); // a directory can never be published over

        $game = Game::start($this->bag(), scoresPath: $path);
        $rows = $game->board->rows();
        // candy-tetris-093: the spawn zone is now the visible top — rows
        // HIDDEN_ROWS..+1 are what a replacement I collides with.
        for ($r = Board::HIDDEN_ROWS; $r < Board::HIDDEN_ROWS + 2; $r++) {
            for ($col = 1; $col < Board::COLS; $col++) {
                $rows[$r][$col] = Tetromino::I;
            }
        }
        $doomed = $game->mutate([
            'board' => new Board($rows),
            'piece' => new Piece(Tetromino::O, 0, 0, 22),
        ]);

        [$after] = $doomed->update(new KeyMsg(KeyType::Char, ' '));

        self::assertTrue($after->over);
        self::assertNotSame([], $after->notices, 'the refusal must surface somewhere');
        self::assertStringContainsString(
            'could not save scores',
            $after->notices[0] ?? '',
            'the same honest copy — now a frame line, not stderr',
        );
        $frame = $after->view();
        self::assertStringContainsString(
            'could not save scores',
            $frame,
            'the death screen and the refusal paint in the same band',
        );
        self::assertSame(
            ['.', '..'],
            array_values(scandir($path) ?: []),
            'the refused write leaves no residue inside the path',
        );
    }

    public function testTheSlotSurvivesTicksAndRetiresOnTheNextPress(): void
    {
        $path = $this->dir . '/scores.json';
        mkdir($path);
        $game = Game::start($this->bag(), scoresPath: $path);
        $rows = $game->board->rows();
        // candy-tetris-093: the spawn zone is now the visible top — rows
        // HIDDEN_ROWS..+1 are what a replacement I collides with.
        for ($r = Board::HIDDEN_ROWS; $r < Board::HIDDEN_ROWS + 2; $r++) {
            for ($col = 1; $col < Board::COLS; $col++) {
                $rows[$r][$col] = Tetromino::I;
            }
        }
        $doomed = $game->mutate([
            'board' => new Board($rows),
            'piece' => new Piece(Tetromino::O, 0, 0, 22),
        ]);
        [$dead] = $doomed->update(new KeyMsg(KeyType::Char, ' '));
        self::assertNotSame([], $dead->notices);

        // Time passing is not attention: ticks never retire the notice.
        [$later] = $dead->update(new GravityMsg($dead->gravityChain));
        self::assertSame($dead->notices, $later->notices);

        // And the law is uniform — on the death screen too, the player's
        // own next press retires what the last frame showed.
        [$retired] = $later->update(new KeyMsg(KeyType::Insert, ''));
        self::assertSame([], $retired->notices, 'a press retires the slot on any posture');

        // A LIVE press retires it. Below the over screen only quit/restart
        // answer, so test the law on the live board with a pushed moment.
        $live = $this->tick($this->playGame(1));
        self::assertNotSame([], $live->notices);
        [$idle] = $live->update(new GravityMsg($live->gravityChain));
        self::assertSame($live->notices, $idle->notices, 'the moment survives the fall');
        [$pressed] = $idle->update(new KeyMsg(KeyType::Insert, ''));
        self::assertSame([], $pressed->notices, 'the next press retires it');
    }

    // ------------------------------------------------------------------
    // -092: the game-feel moments announce themselves on-frame.
    // ------------------------------------------------------------------

    private function playGame(int $clearRows): Game
    {
        return Game::start($this->bag())->mutate([
            'board' => $this->almostFullBottom($clearRows),
            'piece' => new Piece(Tetromino::O, 0, 0, 22),
            'gravityPending' => true,
        ]);
    }

    public function testAClearedLineAnnouncesItself(): void
    {
        $locked = $this->tick($this->playGame(1));

        self::assertSame(1, $locked->score->lines);
        self::assertSame([Lang::t('moment.lines.one')], $locked->notices);
        self::assertStringContainsString(Lang::t('moment.lines.one'), $locked->view());
    }

    public function testAMultiLineClearCarriesItsCount(): void
    {
        $locked = $this->tick($this->playGame(2));

        self::assertSame(2, $locked->score->lines);
        self::assertSame(
            [Lang::t('moment.lines.many', ['count' => 2])],
            $locked->notices,
        );
    }

    public function testALevelUpEarnsItsOwnLineBesideTheClear(): void
    {
        $game = $this->playGame(1)->mutate(['score' => new Score(0, 9, 0)]);
        $locked = $this->tick($game);

        self::assertSame(1, $locked->score->level, 'ten lines is the level-up');
        self::assertSame([
            Lang::t('moment.lines.one'),
            Lang::t('moment.level', ['level' => 1]),
        ], $locked->notices, 'the clear and the climb both speak');
    }

    public function testIncomingGarbageAnnouncesItselfAndRepaintsTheFrame(): void
    {
        $game = Game::start($this->bag());
        $hit = $game->addGarbage(2);

        self::assertSame([Lang::t('moment.garbage')], $hit->notices);
        self::assertStringContainsString(Lang::t('moment.garbage'), $hit->view());

        // A lethal delivery still gets its word (the frame band carries
        // the alert the moment it arrives — clipped to the band width is
        // Paint's contract, proven by the merge under the death banner).
        $rows = $game->board->rows();
        for ($r = 0; $r < 2; $r++) {
            for ($col = 1; $col < Board::COLS; $col++) {
                $rows[$r][$col] = Tetromino::I;
            }
        }
        $lethal = $game
            ->mutate(['board' => new Board($rows), 'piece' => new Piece(Tetromino::O, 0, 0, 2)])
            ->addGarbage(2);
        self::assertTrue($lethal->over);
        self::assertSame([Lang::t('moment.garbage')], $lethal->notices);
    }

    public function testTheVersusViewMergesBothSlotsUnderItsBanners(): void
    {
        $player = Game::start($this->bag())->addGarbage(1);
        $computer = Game::start($this->bag());
        $vs = new VsGame($player, $computer);

        self::assertStringContainsString(Lang::t('moment.garbage'), $vs->view());

        // The match's own slot paints too — and the computer's receipts
        // are NOT the human's news: only player and match slots merge.
        $quiet = new VsGame(Game::start($this->bag()), $computer->addGarbage(1));
        self::assertStringNotContainsString(Lang::t('moment.garbage'), $quiet->view());
    }

    public function testTheHelpOverlayIsSoloOnlyAndNeverFreezesAVersusBoard(): void
    {
        // candy-tetris-006 law correction (this row): 'h' in VS must not
        // reach the player Game's overlay toggle — invisible there, it
        // would freeze the human board behind a card that never paints.
        $vs = VsGame::start();
        [$after] = $vs->update(new KeyMsg(KeyType::Char, 'h'));

        self::assertFalse($after->player->helpOpen, 'the VS seat swallows the solo overlay key');
        [$moved] = $after->update(new KeyMsg(KeyType::Char, 'h'));
        self::assertSame($after->player->piece->x, $moved->player->piece->x);
    }

    // ------------------------------------------------------------------
    // The census: no code path inside the loop writes raw bytes.
    // ------------------------------------------------------------------

    public function testNeitherLoopModelEverWritesStderrItself(): void
    {
        foreach (['src/Game.php', 'src/VsGame.php'] as $relative) {
            $lines = explode("\n", (string) file_get_contents(__DIR__ . '/../' . $relative));
            $offenders = [];
            foreach ($lines as $number => $line) {
                if (str_contains(trim($line), 'fwrite(STDERR') && !str_starts_with(trim($line), '*') && !str_starts_with(trim($line), '//')) {
                    $offenders[] = $relative . ':' . ($number + 1);
                }
            }
            self::assertSame([], $offenders, "candy-tetris-025: $relative must speak through the frame slot, never raw stderr");
        }
    }
}
