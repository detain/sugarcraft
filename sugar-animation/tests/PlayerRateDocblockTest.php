<?php

declare(strict_types=1);

namespace SugarCraft\Animation\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Animation\Player;
use SugarCraft\Animation\TickMsg;

/**
 * E-03 flip (W1 review assignment): anti-lie pins for the rate the schedule
 * actually arms. The ctor's `float $interval` was a dead value nothing ever
 * read (removed pre-E-03), and until E-03 every tick re-armed the CURRENT
 * frame's full delay — a schedule that drifted under load. Player now runs
 * an absolute prefix-sum schedule: the rate still VARIES with the frames
 * (a fixed ctor interval could never do that — the original lesson, kept
 * green via `seconds` assertions below), but the arm after an arrival is
 * the NEXT frame's hold, resolved on the fake clock so the arrival
 * instants themselves are pinned, not just the numbers.
 */
final class PlayerRateDocblockTest extends TestCase
{
    private float $clockNow = 0.0;

    /** @param list<int> $delays centiseconds, one synthetic frame per entry */
    private function playerWithDelays(array $delays): Player
    {
        $frames = [];
        foreach ($delays as $delay) {
            $frames[] = new \SugarCraft\Animation\Frame([[0, 0, 0]], $delay);
        }

        return new Player($frames, now: function (): float {
            return $this->clockNow;
        });
    }

    public function testFirstTickCarriesTheFirstFramesDelayNotAnyFixedInterval(): void
    {
        $closure = $this->playerWithDelays([42, 7])->init();
        $this->assertNotNull($closure);
        $rate = $closure()->seconds;
        $this->assertSame(42 / 100.0, $rate);
    }

    public function testTickAfterAdvanceCarriesTheNewCurrentFramesDelay(): void
    {
        // Arrive at frame 1 on its deadline (t = 0.42): the arm is frame 1's
        // own hold — 7 cs — and under the absolute schedule it is the
        // DISTANCE to the 0.49 deadline, exact because the arrival is exact.
        $this->clockNow = 0.42;
        [$player, $closure] = $this->playerWithDelays([42, 7])->update(new TickMsg());
        $this->assertNotNull($closure);
        $rate = $closure()->seconds;
        $this->assertEqualsWithDelta(7 / 100.0, $rate, 1e-9, 'the next tick is armed for the frame being shown, not the one just left');
        $this->assertSame(1, $player->index);
        // The docblock promise: the rate VARIES with the frames, which a fixed
        // ctor interval could never do.
        $this->assertNotSame(42 / 100.0, $rate);
    }

    public function testMidAnimationTickReschedulesWithTheAdvancedFramesDelay(): void
    {
        $player = $this->playerWithDelays([42, 7, 99]);
        $this->clockNow = 0.42;
        [$player, ] = $player->update(new TickMsg());
        $this->clockNow = 0.49;
        [$player, $closure] = $player->update(new TickMsg());
        $this->assertNotNull($closure);
        $rate = $closure()->seconds;
        $this->assertEqualsWithDelta(99 / 100.0, $rate, 1e-9);
        $this->assertSame(2, $player->index);
    }
}
