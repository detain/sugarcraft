<?php

declare(strict_types=1);

namespace SugarCraft\Flip\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Flip\Player;
use SugarCraft\Flip\TickMsg;

/**
 * Anti-lie pin: the Player docblock used to claim ticks are scheduled via
 * `Cmd::tick($interval, …)`. They are not — the real rate is the current
 * frame's GIF delay in centiseconds (delay / 100.0 seconds); the ctor's
 * `float $interval` was a dead value nothing ever read. This test pins the
 * actual schedule so the docblock above cannot silently rot back, and dies
 * the moment a real $interval consumer is (legitimately) introduced.
 */
final class PlayerRateDocblockTest extends TestCase
{
    /** @param list<int> $delays centiseconds, one synthetic frame per entry */
    private function playerWithDelays(array $delays): Player
    {
        $frames = [];
        foreach ($delays as $delay) {
            $frames[] = new \SugarCraft\Flip\Frame([[0, 0, 0]], $delay);
        }
        return new Player($frames);
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
        [$player] = $this->playerWithDelays([42, 7])->update(new TickMsg());
        $closure = $player->init();
        $this->assertNotNull($closure);
        $rate = $closure()->seconds;
        $this->assertSame(7 / 100.0, $rate);
        // The docblock promise: the rate VARIES with the frames, which a fixed
        // ctor interval could never do.
        $this->assertNotSame(42 / 100.0, $rate);
    }

    public function testMidAnimationTickReschedulesWithTheAdvancedFramesDelay(): void
    {
        [$player] = $this->playerWithDelays([42, 7, 99])->update(new TickMsg());
        [$player] = $player->update(new TickMsg());
        $closure = $player->init();
        $this->assertNotNull($closure);
        $rate = $closure()->seconds;
        $this->assertSame(99 / 100.0, $rate);
    }
}
