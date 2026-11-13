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
