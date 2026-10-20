<?php

declare(strict_types=1);

namespace SugarCraft\Animation\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Animation\Frame;
use SugarCraft\Animation\Player;
use SugarCraft\Animation\TickMsg;
use SugarCraft\Core\Msg\QuitMsg;
use SugarCraft\Core\TickRequest;

/**
 * E-02: the loop bound of {@see Player::withLoops()} — playthrough counting
 * and the schedule stop.
 *
 * Every scenario uses four frames of 25 centiseconds (period 0.25 s, cycle
 * 1.0 s) and a fake clock stepped in exact binary fractions, so lap
 * boundaries are reached by arithmetic, not by float luck. The stop law
 * under test: quitting happens on the tick whose schedule position crosses
 * the final lap boundary, while the last shown frame stays displayed —
 * libnsgif's animation_complete-at-the-boundary shape (gif.c:1854), and no
 * wall-clock timer is armed for the bound (the E-09 law inherited by E-02).
 */
final class PlayerLoopStopTest extends TestCase
{
    private float $fakeNow = 0.0;

    /**
     * @return list<Frame>
     */
    private function frames(int $delay, int $count): array
    {
        $frames = [];
        for ($i = 0; $i < $count; $i++) {
            $frames[] = new Frame(cells: ['xx'], delay: $delay);
        }

        return $frames;
    }

    /**
     * @param list<Frame> $frames
     */
    private function player(array $frames): Player
    {
        return new Player($frames, now: fn (): float => $this->fakeNow);
    }

    private function pumpTicks(Player $player, int $count): array
    {
        $steps = [];
        for ($i = 0; $i < $count; $i++) {
            $this->fakeNow += 0.25;
            [$player, $cmd] = $player->update(new TickMsg());
            $steps[] = [$player->index, $cmd];
        }

        return $steps;
    }

    private function isQuit(?\Closure $cmd): bool
    {
        return $cmd !== null && $cmd() instanceof QuitMsg;
    }

    public function testDefaultIsForeverAndMatchesLegacyShape(): void
    {
        $player = $this->player($this->frames(25, 4))->withLoops(null);
        $this->assertNull($player->loops);
        $steps = $this->pumpTicks($player, 10);
        foreach ($steps as [$index, $cmd]) {
            $this->assertFalse($this->isQuit($cmd), 'forever playback never quits (index ' . $index . ')');
            $this->assertInstanceOf(TickRequest::class, $cmd !== null ? $cmd() : null);
        }
        // Four laps plus a bit: position cycled 0,1,2,3,0,1,2,3,0,1.
        $this->assertSame([1, 2, 3, 0, 1, 2, 3, 0, 1, 2], array_map(static fn (array $s): int => $s[0], $steps));
    }

    public function testLoopsOneQuitsOnTheTickThatCompletesTheFirstLap(): void
    {
        $player = $this->player($this->frames(25, 4))->withLoops(1);
        $this->assertSame(1, $player->loops);
        $this->assertNotNull($player->init());

        $steps = $this->pumpTicks($player, 4);
        $this->assertSame([1, 2, 3], array_map(static fn (array $s): int => $s[0], array_slice($steps, 0, 3)));
        $this->assertFalse($this->isQuit($steps[0][1]));
        $this->assertFalse($this->isQuit($steps[1][1]));
        $this->assertFalse($this->isQuit($steps[2][1]));
        // Fourth tick: pos crosses the cycle boundary — the lap is complete.
        $this->assertTrue($this->isQuit($steps[3][1]), 'playOnce stops at the end of lap one');
        $this->assertSame(3, $steps[3][0], 'the last shown frame stays displayed at the stop');
    }

    public function testLoopsTwoPlaysExactlyTwoLaps(): void
    {
        $player = $this->player($this->frames(25, 4))->withLoops(2);
        $steps = $this->pumpTicks($player, 8);
        for ($i = 0; $i < 7; $i++) {
            $this->assertFalse($this->isQuit($steps[$i][1]), 'tick ' . $i . ' of lap two must keep playing');
        }
        $this->assertSame([1, 2, 3, 0, 1, 2, 3], array_map(static fn (array $s): int => $s[0], array_slice($steps, 0, 7)));
        $this->assertTrue($this->isQuit($steps[7][1]), 'the second lap end quits');
        $this->assertSame(3, $steps[7][0]);
    }

    public function testEarlyFireWrapCountsAsOneLapCompleted(): void
    {
        // Pump faster than the schedule (0.05 s steps) so every advance is a
        // force with an epoch re-base: `cycle` never grows, and the wrap
        // accounting must come from the carried counter instead.
        $player = $this->player($this->frames(25, 4))->playOnce();
        $this->assertNotNull($player->init());
        $stops = [];
        for ($i = 0; $i < 4; $i++) {
            $this->fakeNow += 0.05;
            [$player, $cmd] = $player->update(new TickMsg());
            $stops[] = $this->isQuit($cmd);
        }
        $this->assertSame([false, false, false, true], $stops, 'the stop lands exactly on the forced wrap off the final frame');
        $this->assertSame(3, $player->index, 'the wrap-quit does not display frame 0 of a next lap');
    }

    public function testPlayOnceIsWithLoopsOne(): void
    {
        $player = $this->player($this->frames(25, 4));
        $this->assertSame(1, $player->playOnce()->loops);
        $this->assertSame(1, $player->playOnce()->withLoops(5)->withLoops(1)->loops, 'the seam composes');
    }

    public function testWithLoopsRefusesNonPositiveCounts(): void
    {
        $player = $this->player($this->frames(25, 4));
        $this->assertNull($player->withLoops(null)->loops);
        foreach ([0, -1] as $bad) {
            try {
                $player->withLoops($bad);
                $this->fail('withLoops(' . $bad . ') must refuse a non-positive play count');
            } catch (\InvalidArgumentException $e) {
                $this->assertStringContainsString('withLoops()', $e->getMessage());
            }
        }
    }

    public function testLoopPolicySurvivesPauseResumeStepsAndCadenceSwitches(): void
    {
        $player = $this->player($this->frames(25, 4))->withLoops(3);
        $this->assertSame(3, $player->withLoops(3)->loops);

        [$player, ] = $player->update(new TickMsg()); // first anchor
        $this->assertSame(3, $player->loops);

        [$paused] = $player->update($this->spaceKey());
        [$resumed] = $paused->update($this->spaceKey());
        $this->assertSame(3, $resumed->loops);

        [$stepped] = $resumed->update($this->rightKey());
        $this->assertSame(3, $stepped->loops);

        $this->assertSame(3, $stepped->withFixedFps(10.0)->loops);
        $this->assertSame(3, $stepped->withMaxSeconds(10.0)->loops);
    }

    public function testManualStepsNeverQuitAndTheScheduleStillCompletesTheLapAfterThem(): void
    {
        // Scrub to the final frame by hand: the steps themselves are not a
        // playthrough and raise no stop (E-02 counts automatic crossings
        // only — libnsgif increments loop_count when the frame index goes
        // backwards in playback, gif.c:1905). But the schedule, re-anchored
        // onto the scrubbed frame, must still end the bound when its lap
        // boundary next crosses.
        $player = $this->player($this->frames(25, 4))->withLoops(1);
        for ($i = 0; $i < 3; $i++) {
            [$player, $cmd] = $player->update($this->rightKey());
            $this->assertNull($cmd, 'a manual step arms nothing and stops nothing');
        }
        $this->assertSame(3, $player->index);

        // The step re-anchored so frame 3 is due now; a tick one full period
        // later crosses into the next lap — automatic crossing, bounded stop.
        $this->fakeNow += 0.25;
        [$player, $cmd] = $player->update(new TickMsg());
        $this->assertTrue($this->isQuit($cmd), 'the automatic boundary after scrubbing completes the lap');
        $this->assertSame(3, $player->index);
    }

    private function spaceKey(): \SugarCraft\Core\Msg\KeyMsg
    {
        return new \SugarCraft\Core\Msg\KeyMsg(\SugarCraft\Core\KeyType::Space, ' ');
    }

    private function rightKey(): \SugarCraft\Core\Msg\KeyMsg
    {
        return new \SugarCraft\Core\Msg\KeyMsg(\SugarCraft\Core\KeyType::Right, '');
    }
}
