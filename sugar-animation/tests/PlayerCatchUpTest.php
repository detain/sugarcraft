<?php

declare(strict_types=1);

namespace SugarCraft\Animation\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SugarCraft\Animation\Frame;
use SugarCraft\Animation\Player;
use SugarCraft\Animation\TickMsg;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Core\Msg\QuitMsg;

/**
 * E-03: the Player runs on an absolute prefix-sum schedule. Every tick's
 * index and re-arm are resolved against a fake monotonic clock, so the
 * exact numbers the schedule must produce are pinned — including the
 * catch-up skips a fresh-delay re-arm could never produce (pre-fix, each
 * tick re-armed the current frame's FULL delay, so a late tick pushed every
 * later frame one stall further behind: the arms below are DISTANCES TO
 * DEADLINES (0.125/0.0625/…), values the fresh-delay shape cannot emit —
 * it answers every tick with a full 0.25 period regardless of the overrun).
 *
 * E-09: withFixedFps()/withMaxSeconds() ride the same schedule; the
 * duration bound is a model deadline checked per tick (no wall-clock
 * timers, no kill — E646-clean by construction).
 *
 * All periods are powers of two (25 cs = 0.25 s) so every prefix sum and
 * difference in this file is exact in binary floating point.
 */
final class PlayerCatchUpTest extends TestCase
{
    private float $fakeNow = 0.0;

    /** @param list<int> $delaysCs centiseconds, one synthetic frame per entry */
    private function scheduledPlayer(array $delaysCs): Player
    {
        $frames = [];
        foreach ($delaysCs as $delay) {
            $frames[] = new Frame([[0, 0, 0]], $delay);
        }

        return new Player($frames, now: function (): float {
            return $this->fakeNow;
        });
    }

    /** Seconds carried by a scheduled command (tick arms only). */
    private function armSeconds(?\Closure $cmd): float
    {
        $this->assertNotNull($cmd, 'playing player must arm the next tick');
        $request = $cmd();
        $this->assertInstanceOf(\SugarCraft\Core\TickRequest::class, $request);

        return $request->seconds;
    }

    public function testSlowTicksFollowTheAbsoluteSchedule(): void
    {
        // Four frames of 25 cs each: cycle T = 1.0 s, boundaries 0.25/0.5/0.75/1.0.
        $player = $this->scheduledPlayer([25, 25, 25, 25]);

        // init arms frame 0's full period at t = 0.
        $this->assertSame(0.25, $this->armSeconds($player->init()));

        // Tick 1 arrives exactly on its deadline: advance to frame 1, arm
        // the DISTANCE to the next deadline, not a fresh copy of a delay
        // the loop has not spent yet.
        $this->fakeNow = 0.25;
        [$player, $cmd] = $player->update(new TickMsg());
        $this->assertSame(1, $player->index);
        $this->assertSame(0.25, $this->armSeconds($cmd));

        // Tick 2 arrives LATE: frame 1's work ran until 0.875, i.e. 0.375 s
        // past the 0.5 deadline. The schedule lands on frame 3 (its window
        // [0.75, 1.0) contains now) — frame 2 is skipped — and the arm is
        // the 0.125 s REMAINING to the deadline at 1.0. The old fresh-delay
        // re-arm would sit on index 2 with a full 0.25 arm and stretch the
        // cycle from 1.0 s to 1.375 s.
        $this->fakeNow = 0.875;
        [$player, $cmd] = $player->update(new TickMsg());
        $this->assertSame(3, $player->index, 'a late tick skips ahead on the schedule instead of drifting');
        $this->assertSame(0.125, $this->armSeconds($cmd));

        // Tick 3 lands back on the grid at 1.0: wraps to frame 0 and arms
        // the full 0.25 s — the cycle total is UNCHANGED by the stall
        // (drift-before 0.375 s kept accruing, drift-after 0.0 s absorbed).
        $this->fakeNow = 1.0;
        [$player, $cmd] = $player->update(new TickMsg());
        $this->assertSame(0, $player->index, 'crossing the cycle boundary wraps through the schedule');
        $this->assertSame(0.25, $this->armSeconds($cmd));
    }

    public function testCostThreePeriodsSkipsTwoFramesAndDeadlinesStayMonotonic(): void
    {
        // Survey prescription: "cost 3x skips 2 frames". Uniform 25 cs; a
        // tick arriving three periods after the last arrival lands three
        // windows forward, and the due times behind the arms never go back.
        $player = $this->scheduledPlayer([25, 25, 25, 25]);
        $this->fakeNow = 0.25;
        [$player, $cmd] = $player->update(new TickMsg());
        $this->assertSame(1, $player->index);
        $dues = [$this->fakeNow + $this->armSeconds($cmd)];

        $this->fakeNow = 1.0; // 0.75 elapsed since arrival — three periods
        [$player, $cmd] = $player->update(new TickMsg());
        // pos = 1.0 = the cycle boundary: within 0 lands on frame 0; from
        // index 1 that is a forward arrival skipping frames 2 and 3.
        $this->assertSame(0, $player->index, 'a 3-period stall skips two frames to the current window');
        $dues[] = $this->fakeNow + $this->armSeconds($cmd);

        for ($i = 1; $i < count($dues); $i++) {
            $this->assertGreaterThan($dues[$i - 1], $dues[$i], 'scheduled deadlines must be strictly monotonic');
        }
    }

    public function testPauseResumeAnchorsFreshPeriodAndNeverAccruesPausedTime(): void
    {
        $player = $this->scheduledPlayer([25, 25, 25]);
        $this->fakeNow = 0.25;
        [$player, ] = $player->update(new TickMsg());
        $this->assertSame(1, $player->index);

        [$player, $cmd] = $player->update(new KeyMsg(KeyType::Space, ''));
        $this->assertTrue($player->paused);
        $this->assertNull($cmd, 'pausing disarms the schedule');

        // Half a minute of wall time passes while paused.
        $this->fakeNow += 30.0;
        [$player, $cmd] = $player->update(new KeyMsg(KeyType::Space, ''));
        $this->assertFalse($player->paused);
        $this->assertSame(0.25, $this->armSeconds($cmd), 'resume gives the shown frame a fresh full period');
        $this->assertSame(1, $player->index, 'resume keeps the paused-on frame');

        // Schedule restarted from the resume instant: the first tick after
        // resume lands on frame 2, not deep in catch-up.
        $this->fakeNow += 0.25;
        [$player, $cmd] = $player->update(new TickMsg());
        $this->assertSame(2, $player->index);
        $this->assertSame(0.25, $this->armSeconds($cmd));
    }

    public function testFixedFpsReplacesDelaysOnTheSameSchedule(): void
    {
        // 8 fps = 0.125 s per frame regardless of the 25 cs GIF delays.
        $player = $this->scheduledPlayer([25, 25, 25])->withFixedFps(8.0);
        $this->assertSame(8.0, $player->fixedFps);
        $this->assertSame(0.125, $this->armSeconds($player->init()));

        $this->fakeNow = 0.125;
        [$player, $cmd] = $player->update(new TickMsg());
        $this->assertSame(1, $player->index);
        $this->assertSame(0.125, $this->armSeconds($cmd));

        // Late past the cycle wrap (grid 0.125/0.25/0.375, T = 0.375): pos
        // 0.4375 is one cycle plus 0.0625 → frame 0's window again, armed
        // for 0.0625 to the next boundary at 0.5. Catch-up reads the
        // uniform grid, never a fresh full period and never the GIF delays.
        $this->fakeNow = 0.4375;
        [$player, $cmd] = $player->update(new TickMsg());
        $this->assertSame(0, $player->index);
        $this->assertSame(0.0625, $this->armSeconds($cmd));
    }

    public function testMaxSecondsQuitsAtTheFinalScheduleDeadlineWithoutAnyTimer(): void
    {
        $player = $this->scheduledPlayer([25, 25, 25, 25])->withMaxSeconds(0.6);
        $this->assertSame(0.6, $player->maxSeconds);

        $this->fakeNow = 0.25;
        [$player, $cmd] = $player->update(new TickMsg());
        $this->assertSame(1, $player->index);
        $this->armSeconds($cmd);

        $this->fakeNow = 0.5;
        [$player, $cmd] = $player->update(new TickMsg());
        $this->assertSame(2, $player->index);
        $this->armSeconds($cmd);

        // 0.75 s of schedule position >= the 0.6 s bound: quit,
        // deterministically, from the model — no wall-clock timer was ever
        // armed (E646-clean).
        $this->fakeNow = 0.75;
        [$player, $cmd] = $player->update(new TickMsg());
        $this->assertNotNull($cmd);
        $this->assertInstanceOf(QuitMsg::class, $cmd());
        $this->assertSame(2, $player->index, 'the bound quits without painting a late frame');
    }

    /** @return array<string, array{float}> */
    public static function unacceptableRates(): array
    {
        return [
            'zero'     => [0.0],
            'negative' => [-1.0],
            'infinite' => [\INF],
            'nan'      => [\NAN],
        ];
    }

    #[DataProvider('unacceptableRates')]
    public function testFixedFpsRefusesNonPositiveAndInfiniteValues(float $bad): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->scheduledPlayer([10])->withFixedFps($bad);
    }

    #[DataProvider('unacceptableRates')]
    public function testMaxSecondsRefusesNonPositiveAndInfiniteValues(float $bad): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->scheduledPlayer([10])->withMaxSeconds($bad);
    }

    public function testMutateCarriesClockAndRateFields(): void
    {
        // Seam sanity: mutate() must carry the clock, fps and duration
        // fields through index changes, so the schedule cannot silently
        // lose them (a dropped $now would flip the test clock back to
        // hrtime and every exact assertion in this file would drift).
        $player = $this->scheduledPlayer([25, 50])->withFixedFps(8.0)->withMaxSeconds(1.0);
        $this->fakeNow = 0.125;
        [$next, $cmd] = $player->update(new TickMsg());
        $this->assertSame(0.125, $next->playedSeconds(), 'clock survived the tick');
        $this->assertSame(8.0, $next->fixedFps);
        $this->assertSame(1.0, $next->maxSeconds);
        $this->assertSame(0.125, $this->armSeconds($cmd), 'uniform 1/8 s cadence after one frame');
    }
}
