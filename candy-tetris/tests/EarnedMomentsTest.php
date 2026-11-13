<?php

declare(strict_types=1);

namespace SugarCraft\Tetris\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Tetris\Bag;
use SugarCraft\Tetris\Board;
use SugarCraft\Tetris\Game;
use SugarCraft\Tetris\Piece;
use SugarCraft\Tetris\Renderer;
use SugarCraft\Tetris\Tetromino;
use SugarCraft\Tetris\VsGame;
use SugarCraft\Tetris\VsRenderer;

/**
 * MA-13 — the earned moments must be SEEN, not just paid.
 *
 * Combo chains, back-to-back arming, T-Spins, Tetris and perfect clears
 * were computed at every lock and rendered nowhere; a 4-line drop and a
 * quiet 1-row nudge looked identical on frame. These pins name each badge
 * in the sidebar (solo) and the match strip (VS), and pin that the score
 * card grows ONLY while something is armed — the natural-height frame of
 * an ordinary game (goldens, ViewportTest) must never shift.
 */
final class EarnedMomentsTest extends TestCase
{
    private function fresh(): Game
    {
        return Game::start(new Bag(static fn (int $max): int => 0));
    }

    /** Four full bottom rows + a vertical I down the gap: a real Tetris. */
    private function tetrisReady(): Game
    {
        $g = $this->fresh();

        $rows = $g->board->rows();
        for ($row = Board::ROWS - 4; $row < Board::ROWS; $row++) {
            for ($col = 0; $col < Board::COLS; $col++) {
                $rows[$row][$col] = Tetromino::I;
            }
        }

        return $g->mutate([
            'board' => new Board($rows),
            'piece' => new Piece(Tetromino::I, 0, 0, 0),
        ]);
    }

    public function testATetrisIsNamedOnTheFrame(): void
    {
        [$after] = $this->tetrisReady()->update(new KeyMsg(KeyType::Char, ' '));

        self::assertSame(4, $after->score->lines, 'setup sanity: four lines cleared');
        self::assertSame('TETRIS', $after->lastAward, 'the lock names what it earned');
        self::assertStringContainsString('TETRIS', $after->view(), 'and the frame shows the name');
    }

    public function testAnOrdinaryLockClearsTheBadge(): void
    {
        [$tetris] = $this->tetrisReady()->update(new KeyMsg(KeyType::Char, ' '));
        self::assertSame('TETRIS', $tetris->lastAward);

        // The next piece drops onto an empty board: no lines, no spin —
        // the badge must not linger as a permanent title.
        [$quiet] = $tetris->update(new KeyMsg(KeyType::Char, ' '));

        self::assertNull($quiet->lastAward, 'a quiet lock reclaims the badge slot');
        self::assertStringNotContainsString('TETRIS', $quiet->view());
    }

    public function testComboAndBackToBackBadgeTheScoreCard(): void
    {
        $armed = $this->fresh()->mutate([
            'lastAward'  => 'T-SPIN',
            'combo'      => 3,
            'backToBack' => true,
            'frameRows'  => 24,
        ]);

        $frame = $armed->view();
        self::assertStringContainsString('T-SPIN', $frame);
        self::assertStringContainsString('combo x3', $frame);
        self::assertStringContainsString('B2B armed', $frame);
        // candy-tetris-004: measured in a posture both games share (below
        // natural height, where the help card is already budgeted out), so
        // the difference is exactly the badge rows the score card grew by —
        // at full height the armed frame instead SHRINKS, the help card
        // paying for the expansion (ViewportTest pins that law).
        $quiet = $this->fresh()->mutate(['frameRows' => 24]);
        self::assertGreaterThan(
            count(explode("\n", $quiet->view())),
            count(explode("\n", $armed->view())),
            'badges add rows only while armed',
        );
    }

    public function testAFirstComboHitStaysQuiet(): void
    {
        // combo === 1 is the plain chain starter, not a spectacle — only
        // chains of 2+ earn a line (the >=2 threshold is the pinned door).
        $game = $this->fresh()->mutate(['combo' => 1]);

        self::assertStringNotContainsString('combo x1', $game->view());
    }

    public function testFreshStateCarriesNoBadgesAndNoExtraRows(): void
    {
        $frame = $this->fresh()->view();

        self::assertStringNotContainsString('combo', $frame);
        self::assertStringNotContainsString('B2B', $frame);
        self::assertCount(
            Renderer::NATURAL_ROWS,
            explode("\n", rtrim($frame, "\n")),
            'the unbadged frame keeps the natural height the goldens pin',
        );
    }

    public function testTheVsStripCarriesThePlayersAward(): void
    {
        $vs = VsGame::start();
        $badge = $vs->player->mutate(['lastAward' => 'TETRIS']);
        $mutate = new \ReflectionMethod(VsGame::class, 'mutate');
        /** @var VsGame $withAward */
        $withAward = $mutate->invoke($vs, ['player' => $badge]);

        $frame = VsRenderer::render($withAward);
        self::assertStringContainsString('lvl 0  TETRIS', $frame, 'player row carries the moment whole');
        self::assertStringContainsString(
            'computer  score: 0       lines: 0   level: 0',
            $frame,
            'the computer row does not inherit it',
        );
    }
}
