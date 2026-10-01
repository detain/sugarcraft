<?php

declare(strict_types=1);

namespace SugarCraft\Mines\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\Kind;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Mines\Board;
use SugarCraft\Mines\Cell;
use SugarCraft\Mines\Game;
use SugarCraft\Mines\TickMsg;

/**
 * Audit M4: the status-line timer must advance between inputs, not only in
 * reaction to them — a 1 Hz 'clock' tick is armed while (and only while) the
 * timer is live, and a TickMsg repaints without touching the model.
 *
 * These pins are pure unit dispatch (no loop, no wall-clock waits), so the
 * suite needs no tests/bootstrap.php LoopPin.
 */
final class TickSubscriptionTest extends TestCase
{
    public function testClockIsIdleBeforeTheFirstReveal(): void
    {
        $this->assertNull(Game::start()->subscriptions());
    }

    public function testClockArmsOnceTheTimerRuns(): void
    {
        $g = self::startedGame();
        $subs = $g->subscriptions();

        $this->assertNotNull($subs, 'started game must arm the clock');
        $all = $subs->all();
        $this->assertCount(1, $all);
        $this->assertSame('clock', $all[0]->id);
        $this->assertSame(Kind::Tick, $all[0]->kind);
        $this->assertSame(1.0, $all[0]->params['seconds']);
        $this->assertInstanceOf(TickMsg::class, ($all[0]->produce)());
    }

    public function testClockIsCancelledAfterTheBoardIsOver(): void
    {
        $rows = [];
        $revealed = 0;
        for ($y = 0; $y < 3; $y++) {
            $row = [];
            for ($x = 0; $x < 3; $x++) {
                $isMine = ($x === 0 && $y === 0);
                $row[] = new Cell($isMine, !$isMine, false, 0);
                if (!$isMine) {
                    $revealed++;
                }
            }
            $rows[] = $row;
        }
        // Every safe cell revealed → board won → timer frozen → clock stands down.
        $board = new Board(3, 3, 1, $rows, true, false, $revealed, 0);
        $g = new Game(
            board: $board,
            cursorX: 1,
            cursorY: 1,
            rand: static fn (int $_max): int => 0,
            startedAt: microtime(true),
        );

        $this->assertTrue($g->board->isWon(), 'sanity: fixture board is won');
        $this->assertNull($g->subscriptions());
    }

    public function testTickRepaintsWithoutTouchingTheModel(): void
    {
        $g = self::startedGame();

        [$next, $cmd] = $g->update(new TickMsg());

        $this->assertSame($g, $next, 'tick must not derive a new model');
        $this->assertNull($cmd);
    }

    public function testTickOnAnUnstartedGameIsInert(): void
    {
        $g = Game::start();

        [$next, $cmd] = $g->update(new TickMsg());

        $this->assertSame($g, $next);
        $this->assertNull($cmd);
    }

    public function testStatusLineReadsTheWallClockAtRenderTime(): void
    {
        // Started five seconds ago, still in play: the view shows 0:05 even
        // though no message has been dispatched since — the render-time read
        // the tick exists to surface.
        // Hand-built in-play board: mines already placed, nothing revealed,
        // timer stamped five seconds ago.
        $rows = [];
        for ($y = 0; $y < 9; $y++) {
            $row = [];
            for ($x = 0; $x < 9; $x++) {
                $row[] = new Cell($x === 0 && $y === 0, false, false, 0);
            }
            $rows[] = $row;
        }
        $board = new Board(9, 9, 10, $rows, true, false, 0, 0);
        $g = new Game(
            board: $board,
            cursorX: 4,
            cursorY: 4,
            rand: static fn (int $_max): int => 0,
            startedAt: microtime(true) - 5.0,
        );

        $this->assertStringContainsString('0:05', $g->view());
    }

    private static function startedGame(): Game
    {
        $g = Game::start();
        [$g, ] = $g->update(self::key(KeyType::Space));   // first reveal places mines + starts clock

        return $g;
    }

    private static function key(KeyType $type, string $rune = ''): KeyMsg
    {
        return new KeyMsg($type, $rune);
    }
}
