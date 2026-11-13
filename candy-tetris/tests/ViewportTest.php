<?php

declare(strict_types=1);

namespace SugarCraft\Tetris\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\ColorProfileMsg;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Core\Msg\WindowSizeMsg;
use SugarCraft\Core\Util\ColorProfile;
use SugarCraft\Tetris\Bag;
use SugarCraft\Tetris\Board;
use SugarCraft\Tetris\Game;
use SugarCraft\Tetris\Piece;
use SugarCraft\Tetris\Renderer;
use SugarCraft\Tetris\Tetromino;
use SugarCraft\Tetris\VsGame;
use SugarCraft\Tetris\VsRenderer;

/**
 * C-2 (CRITICAL from the 11-02 review): the terminal is a first-class
 * input. WindowSizeMsg must be consumed, overlays must live ON the frame
 * (never appended below the fold where an 80x24 terminal silently eats
 * them), and below the honest floor the renderer refuses with a notice
 * that itself fits. MA-10 rides along: the game-over banner names WHY the
 * run ended — the fatal piece sits in hidden rows, so the banner is the
 * only on-frame evidence there is.
 */
final class ViewportTest extends TestCase
{
    private static function fresh(): Game
    {
        return Game::start(new Bag(static fn(int $max): int => 0));
    }

    /** @return list<string> */
    private static function frameRows(Game $game): array
    {
        return explode("\n", Renderer::render($game));
    }

    public function testWindowSizeMsgUpdatesTheLiveFrame(): void
    {
        $game = self::fresh();
        [$resized] = $game->update(new WindowSizeMsg(100, 40));
        self::assertSame(100, $resized->frameCols);
        self::assertSame(40, $resized->frameRows);
    }

    public function testAnIdenticalWindowSizeIsInert(): void
    {
        $game = self::fresh();
        [$same] = $game->update(new WindowSizeMsg($game->frameCols, $game->frameRows));
        self::assertSame($game, $same, 'no state churn when the size did not change');
    }

    public function testColorProfileMsgLandsOnTheModel(): void
    {
        $game = self::fresh();
        self::assertNull($game->colorProfile);
        [$seen] = $game->update(new ColorProfileMsg(ColorProfile::Ansi256));
        self::assertSame(ColorProfile::Ansi256, $seen->colorProfile);
    }

    public function testGameOverOverlayIsPaintedIntoTheFixedFrame(): void
    {
        $game = self::fresh();
        $rows = self::frameRows($game);
        $over = $game->mutate(['over' => true]);
        $overRows = self::frameRows($over);

        self::assertCount(count($rows), $overRows, 'an overlay must not change the row count');
        self::assertSame(
            Renderer::NATURAL_ROWS,
            count($overRows),
            'the natural frame is exactly NATURAL_ROWS tall — nothing lives below it',
        );
        $joined = implode("\n", $overRows);
        self::assertStringContainsString('GAME OVER', $joined);
        self::assertStringContainsString('final score', $joined);
        self::assertStringContainsString('press r to restart', $joined);
    }

    public function testPauseOverlayIsPaintedIntoTheFixedFrame(): void
    {
        $game = self::fresh();
        [$pausedGame] = $game->update(new KeyMsg(KeyType::Char, 'p'));
        $rows = self::frameRows($pausedGame);

        self::assertCount(Renderer::NATURAL_ROWS, $rows);
        self::assertStringContainsString('paused', implode("\n", $rows));
    }

    public function testSubFloorTerminalGetsAFittingResizeNotice(): void
    {
        $game = self::fresh()->update(new WindowSizeMsg(20, 10))[0];
        self::assertTrue($game->tooSmall());

        $frame = Renderer::render($game);
        self::assertStringContainsString('terminal', $frame);
        self::assertStringContainsString('20x10', $frame);
        foreach (explode("\n", $frame) as $line) {
            self::assertLessThanOrEqual(20, \SugarCraft\Core\Util\Width::of($line), 'the notice must fit the tiny terminal');
        }
        // The notice screen replaces the frame — no board, no escapes.
        self::assertStringNotContainsString('┌', $frame);
    }

    public function testSubFloorWorldFreezesButQuitAndHeartbeatSurvive(): void
    {
        $small = self::fresh()->update(new WindowSizeMsg(10, 5))[0];

        [$moved] = $small->update(new KeyMsg(KeyType::Left, ''));
        self::assertSame($small, $moved, 'movement is inert below the floor');

        [$pausedAttempt] = $small->update(new KeyMsg(KeyType::Char, 'p'));
        self::assertFalse($pausedAttempt->paused, 'pause cannot be toggled blind below the floor');

        [$afterQuit, $cmd] = $small->update(new KeyMsg(KeyType::Char, 'q'));
        self::assertNotNull($cmd, 'q must still quit from the notice screen');

        [$afterTick, $heartbeat] = $small->update(new \SugarCraft\Tetris\GravityMsg(0));
        self::assertNotNull($heartbeat, 'the gravity chain keeps its heartbeat while frozen');
        self::assertTrue($afterTick->gravityPending, '...and the pending slot stays single');
    }

    public function testResizingBackResumesTheFrozenWorld(): void
    {
        $small = self::fresh()->update(new WindowSizeMsg(10, 5))[0];
        $back = $small->update(new WindowSizeMsg(40, 32))[0];
        self::assertFalse($back->tooSmall());

        $frame = Renderer::render($back);
        self::assertStringContainsString('score', $frame);
        self::assertStringNotContainsString('terminal', $frame);
    }

    public function testSqueezedTerminalDropsTheHelpCardButKeepsScoreNextAndHold(): void
    {
        $squeezed = self::fresh()->update(new WindowSizeMsg(40, 24))[0];
        $frame = Renderer::render($squeezed);

        self::assertStringNotContainsString('p    pause', $frame, 'the help card is the reflow casualty');
        self::assertStringContainsString('score:', $frame);
        self::assertStringContainsString('next:', $frame);
        self::assertStringContainsString('hold:', $frame);
        self::assertLessThanOrEqual(24, count(explode("\n", $frame)), 'reflowed frame fits a MIN_ROWS terminal');
    }

    public function testArmedBadgesStayBudgetedInsideTheNaturalFrame(): void
    {
        // candy-tetris-004: an award moment used to add up to three badge
        // rows to the score card while the sidebar kept its full height —
        // a 35-36 row census inside a 32-row contract, scrolling the
        // bottom border off. The help card is the designated casualty:
        // the frame must stay ≤ NATURAL_ROWS in EVERY state, badges ride.
        $armed = self::fresh()->mutate([
            'lastAward'  => 'TETRIS!',
            'combo'      => 2,
            'backToBack' => true,
        ]);

        $rows = self::frameRows($armed);
        self::assertLessThanOrEqual(
            Renderer::NATURAL_ROWS,
            count($rows),
            'the fully armed sidebar must not exceed the natural frame',
        );
        $frame = implode("\n", $rows);
        self::assertStringContainsString('TETRIS!', $frame, 'an earned badge is never the casualty');
        self::assertStringContainsString('combo x2', $frame);
        self::assertStringContainsString('B2B armed', $frame);
        self::assertStringNotContainsString('p    pause', $frame, 'the help card pays for the expansion');
    }

    public function testSeededAwardStateAtNaturalHeightKeepsEveryBadgeAndTheStamp(): void
    {
        // The --seed stamp on top of all three badges is the worst-case
        // sidebar: 36 rows un-budgeted. At natural height it must still
        // fit the frame AND keep every score-card line (the stamp is only
        // ever sacrificed at the MIN_ROWS floor, see the floor test).
        $armed = self::fresh()->mutate([
            'lastAward'    => 'TETRIS!',
            'combo'        => 2,
            'backToBack'   => true,
            'reproducible' => true,
            'seed'         => 4242,
        ]);

        $rows = self::frameRows($armed);
        self::assertLessThanOrEqual(Renderer::NATURAL_ROWS, count($rows));
        $frame = implode("\n", $rows);
        self::assertStringContainsString('SEED 4242', $frame);
        self::assertStringContainsString('B2B armed', $frame);
    }

    public function testFloorTerminalArmedAndStampedSacrificesTheStampNotABadge(): void
    {
        // 24 rows, every badge armed, --seed stamped: even without the
        // help card the column overflows by one. The seed stamp — the
        // only developer-facing line in the card — is the casualty;
        // gameplay facts and earned moments all stay on frame.
        $armed = self::fresh()->update(new WindowSizeMsg(40, 24))[0]->mutate([
            'lastAward'    => 'TETRIS!',
            'combo'        => 2,
            'backToBack'   => true,
            'reproducible' => true,
            'seed'         => 4242,
        ]);

        $rows = self::frameRows($armed);
        self::assertLessThanOrEqual(24, count($rows), 'the floor terminal keeps its honest contract');
        $frame = implode("\n", $rows);
        self::assertStringContainsString('TETRIS!', $frame);
        self::assertStringContainsString('B2B armed', $frame);
        self::assertStringNotContainsString('SEED', $frame, 'the stamp yields before any badge goes unpainted');
    }

    public function testRestartKeepsTheLiveTerminalSize(): void
    {
        $over = self::fresh()->update(new WindowSizeMsg(40, 28))[0]->mutate(['over' => true]);
        [$fresh] = $over->update(new KeyMsg(KeyType::Char, 'r'));
        self::assertSame(40, $fresh->frameCols);
        self::assertSame(28, $fresh->frameRows, 'a natural-size seed would re-clip the squeezed one');
    }

    public function testNoTtyProfileRendersAPlainRefusal(): void
    {
        $game = self::fresh()->update(new ColorProfileMsg(ColorProfile::NoTty))[0];
        $frame = Renderer::render($game);
        self::assertStringNotContainsString("\x1b[", $frame, 'a dumb terminal must receive zero escape bytes');
        self::assertStringContainsString('interactive terminal (a TTY)', $frame);
        self::assertStringNotContainsString('colour-capable', $frame, 'the refusal must not demand colour the app does not need (candy-tetris-041)');
    }

    public function testSpawnBlockedDeathNamesItsReason(): void
    {
        $game = self::fresh();
        $guard = 0;
        while ($game->over === false && $guard++ < 40) {
            $game = $game->update(new KeyMsg(KeyType::Char, ' '))[0];
        }
        self::assertTrue($game->over, 'a hard-drop column must eventually top the well');
        self::assertSame(Game::OVER_SPAWN_BLOCKED, $game->overReason);

        $frame = Renderer::render($game);
        self::assertStringContainsString('reason: no room for next piece', $frame);
    }

    public function testGarbageCeilingOverflowNamesTopOut(): void
    {
        $rows = [];
        for ($y = 0; $y < Board::ROWS; $y++) {
            $rows[$y] = array_fill(0, Board::COLS, null);
        }
        $rows[0][5] = Tetromino::I; // locked content in the row the lift would push out

        $game = self::fresh()->mutate(['board' => new Board($rows)]);
        $dead = $game->addGarbageRows(1);
        self::assertTrue($dead->over);
        self::assertSame(Game::OVER_TOP_OUT, $dead->overReason);
        self::assertStringContainsString('reason: stack reached the ceiling', Renderer::render($dead));
    }

    public function testGarbageCrushNamesTheCrush(): void
    {
        $rows = [];
        for ($y = 0; $y < Board::ROWS; $y++) {
            $rows[$y] = array_fill(0, Board::COLS, null);
        }
        foreach ([3, 4, 5] as $y) {
            $rows[$y] = array_fill(0, Board::COLS, Tetromino::I);
        }
        // Active O straddling rows 4-5: the lift buries it under old row 5,
        // and staying put buries it under old rows 4→3. Neither fits → crush.
        $game = self::fresh()->mutate([
            'board' => new Board($rows),
            'piece' => new Piece(Tetromino::O, 0, 0, 4),
        ]);
        $dead = $game->addGarbageRows(1);
        self::assertTrue($dead->over);
        self::assertSame(Game::OVER_CRUSHED, $dead->overReason);
        self::assertStringContainsString('reason: crushed by garbage', Renderer::render($dead));
    }

    public function testVersusOverlaysStayOnFrameAndSayPausedLoudly(): void
    {
        // MA-8: the VS pair had a p binding, a pause gate, and ZERO on-frame
        // evidence of either — a paused match looked exactly like a hang.
        $vs = VsGame::start();
        [$paused] = $vs->update(new KeyMsg(KeyType::Char, 'p'));
        self::assertTrue($paused->player->paused);
        $rows = explode("\n", VsRenderer::render($paused));
        self::assertCount(VsRenderer::NATURAL_ROWS, $rows);
        self::assertStringContainsString('paused', implode("\n", $rows));

        $mutate = new \ReflectionMethod(VsGame::class, 'mutate');
        $mutate->setAccessible(true);
        $over = $mutate->invoke($vs, ['over' => true, 'winner' => 'PLAYER']);
        $overFrame = VsRenderer::render($over);
        self::assertCount(
            VsRenderer::NATURAL_ROWS,
            explode("\n", $overFrame),
            'the VS banner must not extend the frame either (C-2)',
        );
        self::assertStringContainsString('GAME OVER', $overFrame);
        self::assertStringContainsString('YOU WIN!', $overFrame);
    }

    public function testVersusSubFloorFreezesAndRefuses(): void
    {
        $vs = VsGame::start();
        [$small] = $vs->update(new WindowSizeMsg(30, 20));
        self::assertTrue($small->tooSmall());
        $frame = VsRenderer::render($small);
        self::assertStringContainsString('terminal', $frame);
        self::assertStringNotContainsString('PLAYER', $frame, 'the split screen is replaced, not clipped');

        [$afterQuit, $cmd] = $small->update(new KeyMsg(KeyType::Char, 'q'));
        self::assertNotNull($cmd);
    }
}
