<?php

declare(strict_types=1);

namespace SugarCraft\Animation\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Animation\Frame;
use SugarCraft\Animation\Player;
use SugarCraft\Animation\TickMsg;

/**
 * E-03: schedule-identity pins for the Player's wrap re-anchor policy (rev
 * fix). The class docblock used to promise the schedule NEVER rescales when
 * a tick overruns; that was false. The real contract, pinned here
 * frame-for-frame: while a tick's elapsed position lands in ANOTHER
 * frame's window the prefix-sum table stays absolute from the kickoff
 * origin (skip, no re-base); when it lands back in the window of the frame
 * already displayed — the wrap/stall case — the tick force-advances exactly
 * one frame and re-bases the epoch to wall-clock now, arming a fresh full
 * period. Every arm is floored at MIN_TICK_CS (1 cs), so a stalled or
 * frozen pump can never drip zero-delay frames nor hang half a cycle with
 * nothing armed. The identity test drives a scripted fake-clock sequence
 * (exact-deadline arrivals, tie arrivals, early pumps, stalls under and
 * over one full cycle) and compares the played index and every re-arm
 * against an independent walk of that same disclosed contract — not of
 * advanceSchedule()'s code. All delay sums use centiseconds that resolve to
 * dyadic fractions (25/50/100/1 cs; prefix sums 0.25/0.75/1.0/2.0/0.01) and
 * arrival instants chosen from the same dyadic lattice, so every arm
 * comparison is exact in binary floating point.
 */
final class PlayerScheduleTest extends TestCase
{
    private float $fakeNow = 0.0;

    /** Arm floor mirroring Player::MIN_TICK_CS, in seconds. */
    private const MIN_ARM = 0.01;

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

    /**
     * Independent walk of the disclosed E-03 contract: per scripted tick,
     * the landing frame from the epoch-relative prefix-sum table; a landing
     * equal to the displayed frame force-advances exactly one frame and
     * re-bases the epoch so the new frame's hold starts at the tick instant;
     * every arm is the distance to the next deadline under the CURRENT
     * epoch, floored at 1 cs.
     *
     * @param list<int>  $delaysCs
     * @param list<float> $tickTimes
     *
     * @return list<array{int,float}>
     */
    private function walkSchedule(array $delaysCs, array $tickTimes): array
    {
        $periods = array_map(static fn (int $d): float => max($d, 1) / 100.0, $delaysCs);
        $n = count($periods);
        $cumulative = [0.0];
        for ($i = 0; $i < $n; $i++) {
            $cumulative[] = $cumulative[$i] + $periods[$i];
        }
        $total = $cumulative[$n];

        $index = 0;
        $epoch = null;
        $walked = [];
        foreach ($tickTimes as $t) {
            $epoch ??= $t - $cumulative[1];
            $pos = max(0.0, $t - $epoch);
            $cycle = (int) floor($pos / $total);
            $within = $pos - $cycle * $total;

            $landing = 0;
            for ($k = $n - 1; $k >= 0; $k--) {
                if ($within >= $cumulative[$k]) {
                    $landing = $k;
                    break;
                }
            }
            if ($landing !== $index) {
                $index = $landing;
            } else {
                // The disclosed wrap/stall case: hold ends now, next frame
                // starts now, the whole table moves with it.
                $index = ($index + 1) % $n;
                $epoch = $t - $cumulative[$index];
                $pos = $cumulative[$index];
                $cycle = 0;
            }
            $walked[] = [$index, max($cycle * $total + $cumulative[$index + 1] - $pos, self::MIN_ARM)];
        }

        return $walked;
    }

    /**
     * E-03: the played index and every re-arm under a scripted stall
     * sequence must equal the independent walk of the disclosed contract.
     * The sequence mixes exact-deadline arrivals, a tie arrival on a frame
     * boundary, early pumps at a frozen instant, a sub-centisecond late
     * arrival (the MIN_TICK_CS clamp is load-bearing on it), a stall that
     * crosses two wrap boundaries and lands back on the displayed frame's
     * window, and a stall landing mid-window elsewhere (pure skip, epoch
     * intact).
     */
    public function testThePlayedIndexStreamMatchesANaiveWalkUnderScriptedStalls(): void
    {
        $delays = [25, 50, 25, 100];
        $ticks = [
            0.25,          // frame 1 exactly on deadline (first tick anchors the epoch)
            0.25,          // frozen re-pump tied on frame 1's own window start -> force to 2
            0.99609375,    // deep inside frame 3's window -> skip 2 -> 3
            1.0,           // frame 3's window again -> force to 0, epoch re-bases
            5.25,          // skip into frame 1's window of a later cycle, epoch stays put
            5.25,          // frozen re-pump inside frame 1's window -> force to 2
            6.2,           // frame 3's window -> skip 2 -> 3
            9.0,           // two-cycle stall landing in frame 1's window -> skip 3 -> 1
            9.001953125,   // same window again -> force to 2
            20.5,          // six-cycle stall 1.953 ms before frame 1's start -> skip 2 -> 0, arm clamps to MIN_TICK_CS
            21.0,          // frame 1's window again, 1.953 ms before frame 2's start -> skip 0 -> 1, clamped again
            22.265625,     // frame 0's window -> skip 1 -> 0
            23.99609375,   // frame 3's window -> skip 0 -> 3
        ];

        $player = $this->scheduledPlayer($delays);
        $this->assertSame(0.25, $this->armSeconds($player->init()));

        $playedIndex = [];
        $playedArms = [];
        foreach ($ticks as $t) {
            $this->fakeNow = $t;
            [$player, $cmd] = $player->update(new TickMsg());
            $playedIndex[] = $player->index;
            $playedArms[] = $this->armSeconds($cmd);
        }

        $walked = $this->walkSchedule($delays, $ticks);
        $this->assertSame(
            array_map(static fn (array $step): int => $step[0], $walked),
            $playedIndex,
            'the played index sequence must equal the naive contract walk, tick for tick',
        );
        $this->assertSame(
            array_map(static fn (array $step): float => $step[1], $walked),
            $playedArms,
            'every re-arm must equal the walk (absolute-table distance to the next deadline under the CURRENT epoch, floored at 1 cs)',
        );

        // Hand-checked anchors so a bug in the walk itself cannot co-agree
        // with a bug in the code: the wrap force-boundary (t=1.0), the
        // two-cycle skip (t=9.0), the clamped near-miss (t=20.5) and the
        // final absolute skip (t=23.99609375).
        $this->assertSame([0, 0.25], $walked[3]);
        $this->assertSame([1, 0.25], $walked[7]);
        $this->assertSame([0, 0.01], $walked[9]);
        $this->assertSame([3, 0.255859375], $walked[12]);
        $this->assertSame([1, 2, 3, 0, 1, 2, 3, 1, 2, 0, 1, 0, 3], $playedIndex);
    }

    /**
     * E-03: exact-tie boundary semantics. A tick landing precisely on
     * C[k] resolves to frame k (inclusive lower edge), advancing on the
     * absolute table without re-basing; a FROZEN re-pump at that same
     * instant now lands on the current frame's own window start, which is
     * the force-advance case — forward exactly one frame with the epoch
     * re-based, never a backward step (strict-greater landing would walk the
     * index backwards here).
     */
    public function testATickLandingExactlyOnAFrameBoundaryIsInclusiveAndItsForcedRepeatReBases(): void
    {
        $player = $this->scheduledPlayer([25, 25, 25, 25]);
        $this->armSeconds($player->init());

        // t=0.25: first tick anchors the epoch at 0, shows frame 1.
        $this->fakeNow = 0.25;
        [$player, $cmd] = $player->update(new TickMsg());
        $this->assertSame(1, $player->index);
        $this->assertSame(0.25, $this->armSeconds($cmd));

        // Tie arrival exactly on C[2]: inclusive landing takes frame 2 on
        // the absolute table; the kickoff epoch survives (playedSeconds is
        // measured from the ORIGIN, so 0.5 proves no re-base happened).
        $this->fakeNow = 0.5;
        [$player, $cmd] = $player->update(new TickMsg());
        $this->assertSame(2, $player->index);
        $this->assertSame(0.25, $this->armSeconds($cmd));
        $this->assertSame(0.5, $player->playedSeconds(), 'a tie on another frame\'s boundary must not re-base the epoch');

        // Frozen re-pump at the same instant: the elapsed position now sits
        // exactly on the displayed frame's OWN window start -> landing ==
        // index -> force forward to 3 with the epoch re-based (playedSeconds
        // collapses to 0.0). A strict-greater landing would instead resolve
        // frame 1 here and walk the index BACKWARDS from 2.
        [$player, $cmd] = $player->update(new TickMsg());
        $this->assertSame(3, $player->index, 'the forced repeat must advance exactly one frame forward');
        $this->assertSame(0.25, $this->armSeconds($cmd));
        $this->assertSame(0.75, $player->playedSeconds(), 'the force-advance re-bases the epoch so the schedule position sits exactly at the forced frame 3\'s own window start C[3]=0.75 (a held epoch would still read 0.5 here)');
    }

    /**
     * E-03: multi-wrap continuity. Two full clean laps run on the absolute
     * table; a stall then crosses wrap #1 and lands back inside the window
     * of the displayed last frame — the tick must advance EXACTLY one frame
     * (one frame per boundary crossing), re-base the epoch, and every hold
     * sequence after the re-anchor must be identical to the pre-stall lap:
     * cadence preserved, epoch moved.
     */
    public function testCadenceIsIdenticalAcrossLapsAfterAWrapCrossingReAnchor(): void
    {
        $player = $this->scheduledPlayer([25, 50, 25, 100]);
        $lapHolds = [0.25, 0.5, 0.25, 1.0];
        $this->assertSame(0.25, $this->armSeconds($player->init()));

        $indices = [];
        $arms = [];
        foreach ([0.25, 0.75, 1.0, 2.0, 2.25, 2.75, 3.0] as $t) {
            $this->fakeNow = $t;
            [$player, $cmd] = $player->update(new TickMsg());
            $indices[] = $player->index;
            $arms[] = $this->armSeconds($cmd);
        }
        // Clean lap 1 plus the on-time wrap crossing at t=2.0 (frame 3 -> 0
        // exactly one advance) and the start of lap 2.
        $this->assertSame([1, 2, 3, 0, 1, 2, 3], $indices);
        $this->assertSame([0.5, 0.25, 1.0, 0.25, 0.5, 0.25, 1.0], $arms);
        // The kickoff epoch survives two full laps: at t=3.0 the schedule
        // position is still measured from anchor 0 (a re-base at the on-time
        // wrap would have moved it below 2.0).
        $this->assertSame(3.0, $player->playedSeconds(), 'the on-time wrap keeps the kickoff epoch');

        // Stall across wrap #1 (deadline was t=4.0): lands at 5.25 back
        // inside frame 3's window -> force to 0, ONE frame advanced, epoch
        // re-based (playedSeconds collapses to 0.0).
        $this->fakeNow = 5.25;
        [$player, $cmd] = $player->update(new TickMsg());
        $this->assertSame(0, $player->index, 'a wrap-crossing force may advance exactly one frame');
        $this->assertSame(0.25, $this->armSeconds($cmd));
        $this->assertSame(0.0, $player->playedSeconds(), 'the wrap re-anchor re-bases the epoch to wall-clock now');

        // Every subsequent hold repeats the lap sequence verbatim, and each
        // T-boundary crossing still advances exactly one frame per tick.
        $postArms = [0.25];
        $postIndices = [];
        $before = 0;
        foreach ([5.5, 6.0, 6.25, 7.25] as $t) {
            $this->fakeNow = $t;
            [$player, $cmd] = $player->update(new TickMsg());
            $postArms[] = $this->armSeconds($cmd);
            $this->assertSame(($before + 1) % 4, $player->index, 'exactly one frame advanced per tick');
            $postIndices[] = $player->index;
            $before = $player->index;
        }
        $this->assertSame($lapHolds, array_slice($postArms, 0, 4), 'post-re-anchor cadence must equal the pre-stall lap holds');
        $this->assertSame([1, 2, 3, 0], $postIndices);
    }

    /**
     * E-03: wrap-drip. A tick crosses the wrap point and the loop goes
     * idle (frozen clock). The wrap re-anchor must arm at exactly the
     * 1 cs floor, never null and never below it, and every subsequent pump
     * must move the schedule forward by AT MOST one frame — no silent
     * half-cycle hang and no drip of zero-delay frames.
     */
    public function testAFrozenPostWrapPumpArmsTheOneCsFloorAndAdvancesAtMostOneFrame(): void
    {
        $player = $this->scheduledPlayer([1, 50]);
        $this->assertSame(0.01, $this->armSeconds($player->init()));

        // Anchor at 0, land on frame 1 (its deadline is t=0.51), then stall
        // past TWO wrap boundaries into frame 1's own window -> force to 0.
        $this->fakeNow = 0.01;
        [$player, $cmd] = $player->update(new TickMsg());
        $this->assertSame(1, $player->index);
        $this->assertSame(0.5, $this->armSeconds($cmd));

        $this->fakeNow = 1.51;
        [$player, $cmd] = $player->update(new TickMsg());
        $this->assertSame(0, $player->index, 'the wrap-crossing force advances exactly one frame');
        $this->assertSame(0.01, $this->armSeconds($cmd), 'the re-anchor onto the 1 cs frame arms at the floor and fires on the next pump');
        $this->assertSame(0.0, $player->playedSeconds(), 'the post-wrap re-anchor re-bases the epoch');

        // Clock frozen: idle pumps. Each advances exactly one frame (never
        // more — the bounded catch-up), each arm is at or above the floor
        // (a zero arm would be the drip), and a tick is always armed while
        // playing (a null arm would be the silent half-cycle hang).
        $seen = [];
        for ($pump = 0; $pump < 3; $pump++) {
            $before = $player->index;
            [$player, $cmd] = $player->update(new TickMsg());
            $this->assertSame(($before + 1) % 2, $player->index, 'at most one catch-up advance per pump');
            $this->assertGreaterThanOrEqual(0.01, $this->armSeconds($cmd), 'no arm may drip below the MIN_TICK_CS floor');
            $seen[] = $player->index;
        }
        $this->assertSame([1, 0, 1], $seen);
    }

    /**
     * E-03: anti-lie arm for the class docblock. The refuted blanket claim
     * must never return, and the disclosure of the wrap re-anchor (the
     * shipped, bounded choice) must stay named.
     */
    public function testTheClassDocblockDisclosesTheWrapReAnchorAndDeniesTheBlanketClaim(): void
    {
        $docblock = (new \ReflectionClass(Player::class))->getDocComment();
        $this->assertIsString($docblock);

        $this->assertStringNotContainsString(
            'never rescales',
            $docblock,
            'the schedule rescales at the wrap boundary; the blanket non-rescale claim was false and must not return',
        );
        $this->assertStringContainsString('DOES', $docblock);
        $this->assertStringContainsString('rescale at the wrap boundary', $docblock);
        $this->assertStringContainsString('re-anchor', $docblock);
        $this->assertStringContainsString('one advance per', $docblock);
        $this->assertStringContainsString('re-anchor is the shipped', $docblock);
    }
}
