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
 * rather than dressed up.
 *
 * WHERE THE LEAK IS ACTUALLY PINNED, AND THIS PARAGRAPH NAMED THE WRONG PLACE.
 * WHAT IT SAID: "the leak it was written for is pinned directly, in
 * `testThePoolSuiteLeavesNothingArmedOnTheSharedLoop()`, by driving that
 * class's own loop work here." WHAT IS TRUE NOW: no method of that name has
 * ever existed in this repo -- a bare citation naming nothing, which is how a
 * rename leaves a mechanism claim standing with nothing under it. The nearest
 * method, {@see testTheSharedLoopIsCleanByTheTimeThisFileRuns()}, is not what
 * the sentence described either: its own doc-block records that restoring the
 * leak in `PtyPoolReactLoopTest` left it GREEN, because that file's third test
 * waits out the orphan cap and a later observer always finds a clean loop. So
 * the sentence asserted a mechanism that the method it pointed at explicitly
 * refutes. VERIFIED HERE rather than inferred: `PtyPoolReactLoopTest` declares
 * a `tearDown()` that runs `SharedLoopResidue::census()` and asserts all five
 * axes are zero after EVERY test in that class -- which is the only window the
 * leak is visible from, and where the regression proper lives.
 *
 * WHY THE PARAGRAPH STILL EARNS ITS PLACE: the ordering caveat is the true and
 * load-bearing half. A reader who takes this file for a suite-wide guarantee
 * will read its empty verdict as covering leaks it cannot see; what it covers
 * is whatever ran before it, and the per-class `tearDown()` is what covers the
 * class the leak came from.
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
     * THE LOOP IS CLEAN BY THE TIME THIS FILE RUNS — a weaker claim than the
     * one this test was first written to make, and it is stated weakly on
     * purpose.
     *
     * WHAT THIS SAID: "AND THE REAL CLASS LEAVES NOTHING BEHIND. This is the
     * regression proper." WHAT IS TRUE NOW: it was not the regression and it
     * could not have been. Restoring the leak in `PtyPoolReactLoopTest` left
     * this test GREEN, because that file's own third test consumes the orphan
     * cap — it waits out the remaining 4.8 seconds, the cap fires, calls
     * `Loop::stop()`, and is gone — so a later observer always finds a clean
     * loop. The assertion was looking in the right direction at the wrong
     * moment. The regression proper now lives in that class's own
     * `tearDown()`, which is the only window the leak is visible from.
     * WHY THIS STILL EARNS ITS PLACE: it is the arm that would notice a leak
     * from somewhere OTHER than that class — a future test that starts driving
     * the shared facade and never gets a `tearDown()` of its own — and it
     * costs nothing. It is an ordering-dependent statement about whatever ran
     * before it, which is why it is not the guard anything rests on.
     */
    public function testTheSharedLoopIsCleanByTheTimeThisFileRuns(): void
    {
        $this->assertSame(
            ['timers' => 0, 'readStreams' => 0, 'writeStreams' => 0, 'signals' => 0, 'futureTicks' => 0],
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

    /**
     * AN ARMED SIGNAL IS RESIDUE, and it is the residue with the worst
     * consequence.
     *
     * Read `StreamSelectLoop::run()`: of the conditions that keep it running,
     * `readStreams || writeStreams || !signals->isEmpty()` is the one that sets
     * `$timeout = null`, i.e. `stream_select()` with no timeout at all. That is
     * the `wchan: do_select` state the one observed E490 hang was sitting in,
     * and a leaked signal handler reaches it with no stream involved.
     *
     * Measured before this axis existed: with a signal added, the census
     * answered `{timers:0, readStreams:0, writeStreams:0}` -- a clean bill of
     * health for a loop that could no longer return.
     */
    public function testAnArmedSignalIsCountedAsResidue(): void
    {
        if (!\defined('SIGUSR1')) {
            self::markTestSkipped('this platform has no SIGUSR1 to arm');
        }

        $this->assertSame(0, SharedLoopResidue::census()['signals'], 'the loop was not clean');

        $listener = static function (): void {};
        Loop::get()->addSignal(\SIGUSR1, $listener);

        try {
            $this->assertSame(
                1,
                SharedLoopResidue::census()['signals'],
                'an armed signal handler was invisible to the residue census. That is the '
                    . 'branch of StreamSelectLoop::run() which waits with NO timeout, so a '
                    . 'census that cannot see it reports a loop that can never return as clean',
            );
        } finally {
            Loop::get()->removeSignal(\SIGUSR1, $listener);
        }

        // The negative half: removing it must return the count to zero, or the
        // axis is stuck at one and says nothing.
        $this->assertSame(
            0,
            SharedLoopResidue::census()['signals'],
            'removing the signal handler did not clear the count, so this axis cannot tell a '
                . 'cleaned loop from a leaking one',
        );
    }

    /**
     * A QUEUED FUTURE TICK IS RESIDUE TOO, on the `$timeout = 0` branch -- it
     * spins rather than blocking, but it still keeps `run()` from returning.
     *
     * THE DRAIN IS THE QUEUE'S OWN `tick()`, NOT `Loop::run()`, and that
     * distinction cost a hang to learn. Draining via `Loop::run()` passes in
     * isolation and WEDGES inside the full suite: `run()` returns only when
     * nothing is left, so a read stream armed by any earlier test leaves it
     * blocking in `stream_select()` for ever. That is the `wchan: do_select`
     * state this very class documents as the E490 candidate -- reached, in the
     * first draft of this test, by the test that measures it. The out-of-process
     * watchdog caught it and named it, which is what it is for.
     */
    public function testAQueuedFutureTickIsCountedAsResidue(): void
    {
        $this->assertSame(0, SharedLoopResidue::census()['futureTicks'], 'the loop was not clean');

        $ran = false;
        Loop::get()->futureTick(static function () use (&$ran): void {
            $ran = true;
        });

        $this->assertSame(
            1,
            SharedLoopResidue::census()['futureTicks'],
            'a queued future tick was invisible to the residue census',
        );

        // Drain ONLY the future-tick queue. Loop::run() would also wait on
        // every stream and timer the rest of the suite has armed.
        $queue = new \ReflectionProperty(Loop::get(), 'futureTickQueue');
        $queue->setAccessible(true);
        $queue->getValue(Loop::get())->tick();

        $this->assertTrue($ran, 'the fixture tick never ran, so the drain below proves nothing');
        $this->assertSame(
            0,
            SharedLoopResidue::census()['futureTicks'],
            'the future-tick queue did not drain, so this axis cannot tell a cleaned loop from '
                . 'a leaking one',
        );
    }

    /**
     * THE REFUSAL IS DORMANT, AND DORMANT IS NOT THE SAME AS DEAD.
     *
     * `census()` throws for a loop class it cannot inspect. That branch cannot
     * be reached in this suite as it stands -- `LoopPin::pinStableClock()`
     * installs a `StreamSelectLoop` and nothing replaces it -- so it is a seam
     * kept for the day someone changes the loop, not a live path. Rule 6 asks
     * for such a seam to be PINNED rather than argued about, because an
     * untested branch is what the next reader deletes as unreachable.
     *
     * The swap is restored in `finally`. Leaving another loop installed on the
     * shared facade is precisely the class of damage this whole file exists to
     * detect, and doing it here while looking for it would be its own joke.
     */
    public function testTheCensusRefusesALoopItCannotInspect(): void
    {
        $original = Loop::get();

        $foreign = new class () implements \React\EventLoop\LoopInterface {
            public function addReadStream($stream, $listener): void {}

            public function addWriteStream($stream, $listener): void {}

            public function removeReadStream($stream): void {}

            public function removeWriteStream($stream): void {}

            public function addTimer($interval, $callback): \React\EventLoop\TimerInterface
            {
                throw new \LogicException('fixture');
            }

            public function addPeriodicTimer($interval, $callback): \React\EventLoop\TimerInterface
            {
                throw new \LogicException('fixture');
            }

            public function cancelTimer(\React\EventLoop\TimerInterface $timer): void {}

            public function futureTick($listener): void {}

            public function addSignal($signal, $listener): void {}

            public function removeSignal($signal, $listener): void {}

            public function run(): void {}

            public function stop(): void {}
        };

        try {
            Loop::set($foreign);

            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('which this census cannot inspect');

            SharedLoopResidue::census();
        } finally {
            Loop::set($original);
        }
    }
}
