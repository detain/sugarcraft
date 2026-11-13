<?php

declare(strict_types=1);

namespace SugarCraft\Spring\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Spring\Spring;
use SugarCraft\Spring\SpringChain;

/**
 * Review-1102 M-D: tick() now returns the advanced chain directly
 * (`tick(): self`), not a 3-tuple whose element 0 was computed from the
 * PRE-update stages — the stale tuple let a renderer paint yesterday's
 * frame, and dropping its third element silently looped forever. Freshness
 * is pinned explicitly below: positions read off the RESULT of a tick must
 * have moved within that same call.
 */
final class SpringChainTest extends TestCase
{
    public function testEmptyChainIsComplete(): void
    {
        $chain = SpringChain::new([]);
        $this->assertTrue($chain->isComplete());
        $this->assertEquals([], $chain->currentPositions());
    }

    public function testSingleStageCompletesWhenSettled(): void
    {
        $spring = new Spring(1.0 / 60.0, 6.0, 1.0);
        $chain = SpringChain::new([[$spring, 0.0, 0.0, 100.0]]);

        $this->assertFalse($chain->isComplete());
        $this->assertEquals(0, $chain->activeStage());

        // Advance until settled (immutable - each tick returns the new chain)
        for ($i = 0; $i < 600; $i++) {
            $chain = $chain->tick();
            if ($chain->isComplete()) {
                break;
            }
        }

        $this->assertTrue($chain->isComplete());
        $positions = $chain->currentPositions();
        $this->assertEqualsWithDelta(100.0, $positions[0], 0.01);
    }

    public function testSecondStageStartsAfterFirstSettles(): void
    {
        $spring1 = new Spring(1.0 / 60.0, 6.0, 1.0);
        $spring2 = new Spring(1.0 / 60.0, 6.0, 1.0);

        $chain = SpringChain::new([
            [$spring1, 0.0, 0.0, 100.0],
            [$spring2, 0.0, 0.0, 50.0],
        ]);

        // First stage is active
        $this->assertEquals(0, $chain->activeStage());

        // Advance first stage until settled (immutable - each tick returns the new chain)
        for ($i = 0; $i < 600; $i++) {
            $chain = $chain->tick();
            if ($chain->activeStage() > 0) {
                break;
            }
        }

        // Second stage should now be active
        $this->assertEquals(1, $chain->activeStage());

        // First stage position should be preserved at target
        $positions = $chain->currentPositions();
        $this->assertEqualsWithDelta(100.0, $positions[0], 0.01);
    }

    public function testChainCompletesAllStages(): void
    {
        $spring = new Spring(1.0 / 60.0, 6.0, 1.0);

        $chain = SpringChain::new([
            [$spring, 0.0, 0.0, 100.0],
            [$spring, 0.0, 0.0, 50.0],
            [$spring, 0.0, 0.0, 75.0],
        ]);

        for ($i = 0; $i < 2000 && !$chain->isComplete(); $i++) {
            $chain = $chain->tick();
        }

        $this->assertTrue($chain->isComplete());
        $finalPositions = $chain->currentPositions();
        $this->assertEqualsWithDelta(100.0, $finalPositions[0], 0.01);
        $this->assertEqualsWithDelta(50.0, $finalPositions[1], 0.01);
        $this->assertEqualsWithDelta(75.0, $finalPositions[2], 0.01);
    }

    public function testWithStageAddsNewStage(): void
    {
        $spring = new Spring(1.0 / 60.0, 6.0, 1.0);
        $chain = SpringChain::new([
            [$spring, 0.0, 0.0, 100.0],
        ])->withStage($spring, 0.0, 0.0, 50.0);

        $this->assertCount(2, $chain->currentPositions());
    }

    /**
     * SPR-15 (finding 059, shipped ruling: DOCUMENT + PIN, not change):
     * withStage() is building-time sugar — its return carries no
     * activeIndex, so appending to an ALREADY-TICKED chain deliberately
     * rewinds the run to stage 0. The docblock has always said so; this
     * pins the words against the behaviour so a silent "preserve the
     * index" drift (or any refactor of the rebuild) is a disclosed
     * contract change. The rewound chain must still COMPLETE — settled
     * stages hand off again on their own tick, so the restart re-runs
     * cheaply rather than hanging — and live joins/retargets mid-flight
     * remain SpringCollection's job.
     */
    public function testWithStageOnATickedChainDeliberatelyRewindsToStageZero(): void
    {
        $spring = new Spring(1.0 / 60.0, 6.0, 1.0);
        // A chain handally parked at stage 1 with stage 0 already settled —
        // the exact "mid-run" state an append is being tested against.
        $running = new SpringChain(
            [
                [$spring, 100.0, 0.0, 100.0], // settled stage
                [$spring, 0.0, 0.0, 50.0],    // active stage
            ],
            1,
        );

        $extended = $running->withStage($spring, 0.0, 0.0, 25.0);

        $this->assertCount(3, $extended->currentPositions(), 'the append must add the stage');
        $this->assertSame(0, $extended->activeStage(), 'appending to a ticked chain rewinds to stage 0 (documented building-time-only contract)');
        $this->assertSame(1, $running->activeStage(), 'the original chain is untouched (copy-on-write)');

        $ticks = 0;
        while (!$extended->isComplete() && $ticks < 2000) {
            $extended = $extended->tick();
            $ticks++;
        }
        $this->assertTrue($extended->isComplete(), 'a rewound chain still completes — settled stages re-hand-off, not re-animate');
        $this->assertLessThan(400, $ticks, 'the settled stage re-hand-offs on its own tick, it does NOT re-animate (a measured fresh three-stage run of these same springs costs 487 ticks)');
    }

    /**
     * SPR-15 (finding 060): SpringChain::SETTLING_THRESHOLD used to be a
     * consultable constant with NO observable consequence anywhere in the
     * suite. This drives a real three-stage chain to completion and checks
     * the contract the README quotes: every stage lands within the 5e-4
     * hand-off tolerance of its target — "visibly done", not exact. Tighten
     * the constant without telling anyone and this test reddens on a real
     * frame, not on an assertSame of a number to itself.
     */
    public function testThreeStageDriveLandsEveryStageWithinHandoffTolerance(): void
    {
        $spring = new Spring(1.0 / 60.0, 6.0, 1.0);
        $targets = [100.0, 50.0, 25.0];
        $chain = SpringChain::new([
            [$spring, 0.0, 0.0, $targets[0]],
            [$spring, 0.0, 0.0, $targets[1]],
            [$spring, 0.0, 0.0, $targets[2]],
        ]);

        // Observable consequence of WHICH band is in force (finding 060,
        // second leg): the hand-off boundaries of the candidate thresholds
        // are measured — the chain's 5e-4 band hands stage 0 off at tick
        // 170, a looser 1e-3 would already be at stage 1 by tick 162, and a
        // stale lone-spring 1e-4 would wait until 187. Two boundary frames
        // pin the constant to its observable behaviour: at tick 165 the run
        // must still be in stage 0 (looser bands are red), and at tick 178
        // it must be in stage 1 with stage 1 still far from any settle band
        // (tighter bands are red).
        $probe = $chain;
        for ($i = 0; $i < 165; $i++) {
            $probe = $probe->tick();
        }
        $this->assertSame(0, $probe->activeStage(), 'tick 165: the 5e-4 hand-off has not fired yet (a 1e-3 band would have fired at 162)');

        for ($i = 165; $i < 178; $i++) {
            $probe = $probe->tick();
        }
        $this->assertSame(1, $probe->activeStage(), 'tick 178: the 5e-4 hand-off has fired (a stale 1e-4 band would not fire until 187)');
        $this->assertGreaterThan(
            1.0,
            abs($probe->currentPositions()[1] - $targets[1]),
            'stage 1 is only just starting — nowhere near any settle band',
        );

        $ticks = 0;
        while (!$chain->isComplete() && $ticks < 6000) {
            $chain = $chain->tick();
            $ticks++;
        }

        $this->assertTrue($chain->isComplete(), 'a plain three-stage chain must complete within the tick bound');
        $positions = $chain->currentPositions();
        $this->assertCount(3, $positions);
        foreach ($targets as $index => $target) {
            $this->assertLessThan(
                SpringChain::SETTLING_THRESHOLD,
                abs($positions[$index] - $target),
                "stage {$index} must land inside the documented 5e-4 hand-off band (got {$positions[$index]} vs {$target})",
            );
        }
    }

    /**
     * M-D discriminator: a single tick must move the ACTIVE stage's position
     * within the same call — a renderer reading currentPositions() off the
     * returned chain sees this frame, not yesterday's. (The old tuple's
     * element 0 was computed before the update ran.)
     */
    public function testTickPositionsAreFreshNotOneFrameStale(): void
    {
        $spring = new Spring(1.0 / 60.0, 6.0, 1.0);
        $chain = SpringChain::new([[$spring, 0.0, 0.0, 100.0]]);

        $this->assertSame([0.0], $chain->currentPositions());

        $advanced = $chain->tick();

        $this->assertNotSame($chain, $advanced, 'tick must be copy-on-write');
        $positions = $advanced->currentPositions();
        $this->assertGreaterThan(
            0.0,
            $positions[0],
            'the frame a tick returns must already contain the advanced position (no one-frame staleness)',
        );
        // And the pre-tick chain is untouched (immutability).
        $this->assertSame([0.0], $chain->currentPositions());
    }

    public function testTickIsCopyOnWriteAndReturnsChain(): void
    {
        $spring = new Spring(1.0 / 60.0, 6.0, 1.0);
        $chain = SpringChain::new([[$spring, 0.0, 0.0, 100.0]]);

        $newChain = $chain->tick();

        $this->assertInstanceOf(SpringChain::class, $newChain);
        $this->assertFalse($newChain->isComplete());
    }

    /**
     * SPR-14 (finding 010): tick()'s rebuild must ride the private parsed
     * door — the constructor's arity/instanceof/coercion sweep belongs at
     * the boundary, not once per stage per frame. Source-level pin: a
     * future "simplify" back to `new self(...)` inside tick() reddens here
     * even though every behavioural test stays green.
     */
    public function testTickRebuildsThroughTheParsedDoorNotTheConstructorSweep(): void
    {
        $tick = new \ReflectionMethod(SpringChain::class, 'tick');
        $lines = array_slice(
            file($tick->getFileName()),
            $tick->getStartLine() - 1,
            $tick->getEndLine() - $tick->getStartLine() + 1,
        );
        $body = implode('', $lines);

        $this->assertStringNotContainsString('new self(', $body);
        $this->assertStringNotContainsString('new SpringChain(', $body);
        $this->assertStringContainsString('self::fromParsed(', $body);
    }

    /**
     * Copy-on-write parity: a single tick must advance ONLY the active stage
     * and leave every later stage at its initial position. This is what the
     * clone-once refactor (replace one index instead of rebuilding the array)
     * must preserve.
     */
    public function testTickAdvancesOnlyActiveStage(): void
    {
        $spring = new Spring(1.0 / 60.0, 6.0, 1.0);
        $chain = SpringChain::new([
            [$spring, 0.0, 0.0, 100.0],
            [$spring, 0.0, 0.0, 50.0],
        ]);

        $advanced = $chain->tick();
        $positions = $advanced->currentPositions();

        $this->assertGreaterThan(0.0, $positions[0], 'active stage must advance');
        $this->assertSame(0.0, $positions[1], 'inactive stage must stay at its initial position');
    }

    /**
     * Parity: identical tick sequence yields identical positions run to run
     * (guards the clone-once refactor against silent behavioural drift).
     */
    public function testTickIsDeterministic(): void
    {
        $build = static fn(): SpringChain => SpringChain::new([
            [new Spring(1.0 / 60.0, 6.0, 1.0), 0.0, 0.0, 100.0],
            [new Spring(1.0 / 60.0, 6.0, 1.0), 0.0, 0.0, 50.0],
        ]);

        $runOne = $build();
        $runTwo = $build();
        for ($i = 0; $i < 200; $i++) {
            $runOne = $runOne->tick();
            $runTwo = $runTwo->tick();
        }

        $this->assertSame($runOne->currentPositions(), $runTwo->currentPositions());
        $this->assertSame($runOne->activeStage(), $runTwo->activeStage());
    }

    public function testTickOnCompletedChainIsIdempotent(): void
    {
        $spring = new Spring(1.0 / 60.0, 6.0, 1.0);
        $chain = SpringChain::new([[$spring, 0.0, 0.0, 100.0]]);

        // Advance until complete
        for ($i = 0; $i < 600 && !$chain->isComplete(); $i++) {
            $chain = $chain->tick();
        }
        $this->assertTrue($chain->isComplete());

        // Ticking an already-complete chain is a no-op: same state, positions
        // and stage unchanged (the loop-terminates predicate stays stable).
        $after = $chain->tick();
        $this->assertTrue($after->isComplete());
        $this->assertSame($chain->currentPositions(), $after->currentPositions());
        $this->assertSame($chain->activeStage(), $after->activeStage());
    }

    // ---- stage parsing guards (M-A/parse-don't-validate family) ----

    public function testStageWithTooFewElementsIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('SpringChain stage 0 must be [Spring, float, float, float]');

        SpringChain::new([[new Spring(1.0 / 60.0, 6.0, 1.0), 0.0, 0.0]]);
    }

    public function testStageWithNonSpringFirstElementIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('SpringChain stage 1 must be [Spring, float, float, float]');

        SpringChain::new([
            [new Spring(1.0 / 60.0, 6.0, 1.0), 0.0, 0.0, 100.0],
            ['not-a-spring', 0.0, 0.0, 50.0],
        ]);
    }

    public function testStageWithNonNumericPositionIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('SpringChain stage 0 must be [Spring, float, float, float]');

        SpringChain::new([[new Spring(1.0 / 60.0, 6.0, 1.0), 'ocean', 0.0, 100.0]]);
    }

    public function testActiveIndexBeyondStageCountIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('SpringChain activeIndex must be between 0 and the stage count; got 5 with 1 stages');

        new SpringChain(
            [[new Spring(1.0 / 60.0, 6.0, 1.0), 0.0, 0.0, 100.0]],
            5,
        );
    }

    public function testNegativeActiveIndexIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('SpringChain activeIndex must be between 0 and the stage count; got -1 with 1 stages');

        new SpringChain(
            [[new Spring(1.0 / 60.0, 6.0, 1.0), 0.0, 0.0, 100.0]],
            -1,
        );
    }

    public function testSettlingThresholdIsPubliclyConsultable(): void
    {
        // The README quotes this constant as the hand-off tolerance; it used
        // to be private, so the quote was unbackable.
        $this->assertSame(0.0005, SpringChain::SETTLING_THRESHOLD);
    }

    // ── Step 32 (docs-fix-1104): reduced-motion tick contract ───────────────
    //
    // Under REDUCE_MOTION every stage snaps in one tick, but the hand-off
    // still costs its own tick: final positions after 2n-1 ticks,
    // isComplete() on tick 2n. Shape coverage for 1 and 3 stages alongside
    // the 2-stage case ReducedMotionTest tightens. putenv is restored in the
    // same method — this file has no env setUp/tearDown of its own.

    /**
     * @group parallel-unsafe
     *
     * Mutates the process environment (REDUCE_MOTION) and the probe memo —
     * cannot run in parallel; same rationale as ReducedMotionTest (SPR-16).
     */
    public function testReducedMotionChainCompletesOnTickTwoNWithPositionsAtTwoNMinusOne(): void
    {
        putenv('REDUCE_MOTION=1');
        Spring::resetReducedMotionProbe(); // SPR-09: the verdict is memoized, the seam re-reads

        try {
            foreach ([[100.0], [100.0, 50.0, 75.0]] as $targets) {
                $chain = SpringChain::new(array_map(
                    static fn (float $target): array => [new Spring(1.0 / 60.0, 6.0, 1.0), 0.0, 0.0, $target],
                    $targets,
                ));

                $ticks   = 0;
                $halfway = null;
                while (!$chain->isComplete() && $ticks < 20) {
                    $chain = $chain->tick();
                    $ticks++;
                    if ($ticks === 2 * count($targets) - 1) {
                        $halfway = $chain->currentPositions();
                    }
                }

                $this->assertSame(
                    2 * count($targets),
                    $ticks,
                    count($targets) . ' reduced-motion stages complete on tick 2n',
                );
                $this->assertSame($targets, $halfway, 'final positions land on tick 2n-1');
            }
        } finally {
            putenv('REDUCE_MOTION');
            Spring::resetReducedMotionProbe(); // do not leak the snap verdict into sibling tests
        }
    }
}
