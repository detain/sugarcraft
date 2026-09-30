<?php

declare(strict_types=1);

namespace SugarCraft\Tetris\Tests;

use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Core\TickRequest;
use SugarCraft\Tetris\Bag;
use SugarCraft\Tetris\Game;
use SugarCraft\Tetris\GravityMsg;
use PHPUnit\Framework\TestCase;

/**
 * CRIT regression (audit finding 1) at the scheduling level.
 *
 * The bug: every hardDrop/hold dispatched a FRESH gravity tick through
 * scheduleGravity() while the previous tick was still queued, and candy-core
 * has no cancel — so each action permanently added +1 concurrent timer. Five
 * rapid hard drops made the game free-fall. The fix is tetris-side: a
 * gravityPending flag makes the chain a singleton; re-arms are skipped while
 * one tick is in flight.
 *
 * These tests run a discrete-event simulation of the loop Program would
 * drive: each Cmd is invoked to a TickRequest, queued at now + seconds, and
 * the produced Msg is fed back through update(). Counting GravityMsg
 * arrivals inside a fixed wall-clock window exposes any timer stacking —
 * no real sleeping involved.
 */
final class GameGravityChainTest extends TestCase
{
    private const WINDOW_SECONDS = 10.0;

    /**
     * @param list<array{0: float, 1: string}> $keys timed inputs to inject
     * @return int number of GravityMsg arrivals within the window
     */
    private function simulate(array $keys = []): int
    {
        $game = Game::start(new Bag(static fn(int $_max): int => 0));
        $now = 0.0;
        /** @var list<array{0: float, 1: \Closure}> $queue absolute fire times */
        $queue = [];
        $arm = static function (?\Closure $cmd) use (&$queue, &$now): void {
            if ($cmd === null) {
                return;
            }
            $request = $cmd();
            if ($request instanceof TickRequest) {
                $queue[] = [$now + $request->seconds, $request->produce];
            }
        };
        $arm($game->init());

        $pendingKeys = array_values(array_filter(
            $keys,
            static fn(array $k): bool => $k[0] <= self::WINDOW_SECONDS,
        ));
        $arrivals = 0;

        while ($queue !== [] || $pendingKeys !== []) {
            $nextEvent = $queue === [] ? INF : min(array_column($queue, 0));
            $nextKey = $pendingKeys === [] ? INF : $pendingKeys[0][0];

            if ($nextKey <= $nextEvent) {
                [$keyTime, $keyName] = array_shift($pendingKeys);
                $now = max($now, $keyTime);
                [$game, $cmd] = $game->update(new KeyMsg(KeyType::Char, $keyName));
                $arm($cmd);
                continue;
            }

            if ($nextEvent > self::WINDOW_SECONDS) {
                break;
            }
            $index = array_search($nextEvent, array_column($queue, 0), true);
            [, $produce] = $queue[$index];
            array_splice($queue, $index, 1);
            $now = $nextEvent;
            $msg = $produce();
            if ($msg === null) {
                continue;
            }
            if ($msg instanceof GravityMsg) {
                $arrivals++;
            }
            [$game, $cmd] = $game->update($msg);
            $arm($cmd);
        }

        return $arrivals;
    }

    public function testLevelZeroGravityFiresTwelveTimesPerTenSeconds(): void
    {
        // 800016 µs per tick at level 0 → floor(10 / 0.800016) = 12.
        $this->assertSame(800016, Game::start()->score->gravityIntervalUs());
        $this->assertSame(12, $this->simulate());
    }

    public function testFiveHardDropsDoNotSpeedUpGravity(): void
    {
        // The bug's fingerprint: one extra concurrent tick per hard drop, so
        // arrivals would swell past the baseline. With the pending-singleton
        // guard the count is bit-identical to the keyless run.
        $withDrops = $this->simulate([
            [1.0, ' '],
            [2.0, ' '],
            [3.0, ' '],
            [4.0, ' '],
            [5.0, ' '],
        ]);
        $this->assertSame(12, $withDrops, 'hard drops may never add gravity timers');
    }

    public function testHoldAndRotateNeverArmExtraGravityEither(): void
    {
        // hold ('c') and rotation re-enter the same guarded arm path; a busy
        // action track must still show exactly twelve gravity arrivals.
        $busy = $this->simulate([
            [1.0, ' '],
            [2.0, 'c'],
            [3.0, 'z'],
            [4.0, 'c'],
            [5.0, ' '],
        ]);
        $this->assertSame(12, $busy);
    }
}
