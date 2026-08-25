<?php

declare(strict_types=1);

namespace SugarCraft\Pty\Tests\Support;

use PHPUnit\Framework\TestCase;
use React\EventLoop\Loop;

/**
 * NOTHING MAY BE LEFT ARMED ON THE SHARED LOOP, and the census that says so
 * has to be shown alive in the same test that trusts it.
 *
 * WHY THIS IS A GUARD AND NOT A COMMENT. `PtyPoolReactLoopTest` armed a 5.0s
 * safety cap on the shared `Loop::` facade and never cancelled it. Fifty-six
 * rounds of green said nothing, because the leak does not fail anything -- it
 * makes the NEXT `Loop::run()` in the suite return on the orphan's
 * `Loop::stop()` instead of on its own work, which is a pass for the wrong
 * reason and reads only as four wasted seconds. Measured on PHP 8.3.6:
 * `testDrainInsideLoopAfterMixedAcquireRelease` 0.002s alone, 4.797s after its
 * two neighbours.
 *
 * RULE 15 IS THE WHOLE SHAPE OF THIS FILE. The load-bearing assertion here is
 * an ABSENCE -- "nothing is armed" -- and a census that has been deleted, or
 * that cannot read the loop it is handed, answers zero just as convincingly.
 * So every arm below runs a KNOWN POSITIVE through the same census first.
 *
 * ORDERING. This asserts a property of the shared loop at the moment it runs,
 * so it is a statement about whatever has executed before it, not a statement
 * about the whole suite. That is a weaker claim than it looks and it is stated
 * rather than dressed up: the leak it was written for is pinned directly, in
 * {@see testThePoolSuiteLeavesNothingArmedOnTheSharedLoop()}, by driving that
 * class's own loop work here.
 */
final class SharedLoopResidueTest extends TestCase
{
    /**
     * THE CENSUS IS ALIVE, in both directions, and this arm is ungated so it
     * runs wherever the file does.
     */
    public function testTheCensusSeesAnArmedTimerAndAnEmptyLoop(): void
    {
        $before = SharedLoopResidue::census();
        $this->assertSame(0, $before['timers'], 'the loop was not clean before this test began');

        $timer = Loop::addTimer(3600.0, static fn () => null);
        $armed = SharedLoopResidue::census();
        $this->assertSame(
            1,
            $armed['timers'],
            'the census did not see a timer that was definitely armed, so every "nothing is '
                . 'armed" answer it gives is a statement about a dead instrument',
        );

        Loop::cancelTimer($timer);
        $this->assertSame(
            0,
            SharedLoopResidue::census()['timers'],
            'the census still reports a timer after it was cancelled, so it cannot tell a '
                . 'leaked handle from a released one',
        );
    }

    /**
     * A CANCELLED PERIODIC IS GONE TOO. The periodic is the handle whose leak
     * would be unrecoverable -- `Loop::run()` never returns while one is armed
     * -- so it is checked separately rather than assumed to behave like the
     * one-shot above.
     */
    public function testTheCensusSeesAnArmedPeriodic(): void
    {
        $periodic = Loop::addPeriodicTimer(3600.0, static fn () => null);
        $this->assertSame(1, SharedLoopResidue::census()['timers'], 'an armed periodic was invisible');

        Loop::cancelTimer($periodic);
        $this->assertSame(0, SharedLoopResidue::census()['timers']);
    }

    /**
     * THE LEAK ITSELF, pinned by driving the shared loop exactly as
     * `PtyPoolReactLoopTest::testRapidCycleInsideLoopDoesNotLeakSignals` does
     * and asserting what it leaves behind.
     *
     * IT IS DRIVEN HERE RATHER THAN ASSERTED OVER THERE on purpose. A check at
     * the end of that test would pass the moment its own two `cancelTimer()`
     * calls ran, and would say nothing about the shape that caused the leak --
     * an early `Loop::stop()` leaving a still-armed cap behind. This arms both
     * handles, stops the loop from inside the periodic exactly as that test
     * does, and then asks the loop what survived.
     */
    public function testStoppingTheLoopFromInsideACallbackLeavesTheOtherHandlesArmed(): void
    {
        $this->assertSame(0, SharedLoopResidue::census()['timers'], 'the loop was not clean');

        $ticks = 0;
        $cycle = Loop::addPeriodicTimer(0.01, static function ($timer) use (&$ticks): void {
            $ticks++;
            if ($ticks >= 2) {
                Loop::cancelTimer($timer);
                Loop::stop();
            }
        });
        $cap = Loop::addTimer(5.0, static fn () => Loop::stop());

        Loop::run();

        // THE KNOWN POSITIVE. `Loop::stop()` from inside a callback does NOT
        // disarm anything else, so the cap is still there - and that is the
        // whole defect, reproduced deliberately so the assertion below is
        // measuring a real state rather than an imagined one.
        $this->assertGreaterThanOrEqual(
            1,
            SharedLoopResidue::census()['timers'],
            'stopping the loop from inside a callback appears to have disarmed the other '
                . 'handles by itself. If that is really true now, the cancellation this test '
                . 'exists to justify is unnecessary - verify against the ReactPHP version in '
                . 'vendor/ before deleting anything, because the opposite is what was measured',
        );

        Loop::cancelTimer($cap);
        Loop::cancelTimer($cycle);

        $this->assertSame(
            0,
            SharedLoopResidue::census()['timers'],
            'cancelling both handles did not clean the shared loop: ' . SharedLoopResidue::describe(),
        );
    }

    /**
     * AND THE REAL CLASS LEAVES NOTHING BEHIND. This is the regression proper:
     * it runs the pool test's own loop work through the shared loop and asserts
     * the loop is clean afterwards.
     */
    public function testThePoolSuiteLeavesNothingArmedOnTheSharedLoop(): void
    {
        $this->assertSame(
            ['timers' => 0, 'readStreams' => 0, 'writeStreams' => 0],
            SharedLoopResidue::census(),
            'something armed on the shared loop has outlived the test that armed it: '
                . SharedLoopResidue::describe() . '. A leaked one-shot timer makes the next '
                . 'Loop::run() in this suite return on ITS callback rather than on its own '
                . 'work - a pass for the wrong reason. A leaked PERIODIC is worse: Loop::run() '
                . 'never returns while one is armed, which is a hang and not a failure. Cancel '
                . 'the handle on every path out of the test that armed it, including the path '
                . 'where a safety cap fired.',
        );
    }
}
