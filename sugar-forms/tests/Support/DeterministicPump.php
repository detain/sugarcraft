<?php

declare(strict_types=1);

namespace SugarCraft\Forms\Tests\Support;

use React\EventLoop\Loop;

/**
 * SF-31 (sugar-forms-037): deterministic pump for the async-suggestion legs.
 *
 * The pre-SF-31 shape waited WALL time (`addTimer(0.25)` stop-pumps sized to
 * beat single-digit-ms debounce windows), so each leg raced CI jitter instead
 * of asserting a contract. React's StreamSelectLoop (the only loop these
 * suites run — the bootstrap pins it and setUp swaps fresh instances) has no
 * virtual clock, so determinism here means ORDER: pump explicit loop
 * iterations until the awaited state transition is OBSERVED, drain a few more
 * iterations to prove nothing else fires, and keep a generous wall-clock
 * BUDGET purely as a fail-loud bound so a genuinely missing event reddens
 * instead of hanging.
 *
 * The budget timer is cancelled on return (an armed leftover would trip the
 * SharedLoopResidue census), and every futureTick chain carries an $active
 * latch: a chain orphaned by an earlier stop() must never stop a later, live
 * run.
 */
trait DeterministicPump
{
    /** Loop iterations drained after an observed event before a negative asserts. */
    private const ADVANCE_TICKS = 5;

    /**
     * Worst-case CI jitter budget, expressed as a multiple of the armed
     * deadline a leg is waiting on. Green legs terminate in microseconds —
     * the multiplied budget exists only so a broken product path fails with
     * a clear assertion rather than a hung suite.
     */
    private const JITTER_FACTOR = 20.0;

    /** A leg's wall budget from the deadline its event was armed against. */
    private static function jitterBudget(float $armedSeconds): float
    {
        return max(0.25, $armedSeconds * self::JITTER_FACTOR);
    }

    /**
     * Run the loop until $condition() reports truth (or one iteration past
     * the budget timer). Returns whether the condition became true — callers
     * assert on it so the failure names the missing event, not the pump.
     */
    private static function pumpUntil(callable $condition, float $budgetSeconds): bool
    {
        if ($condition()) {
            return true;
        }

        $active = true;
        $done   = false;
        $tick   = null;
        $tick   = static function () use (&$tick, &$active, &$done, $condition): void {
            if (!$active) {
                return; // orphaned chain — never stop a live run
            }
            if ($condition()) {
                $done   = true;
                $active = false;
                Loop::stop();

                return;
            }
            Loop::futureTick($tick);
        };
        Loop::futureTick($tick);

        $budget = Loop::addTimer($budgetSeconds, static function () use (&$active): void {
            $active = false;
            Loop::stop();
        });

        Loop::run();
        $active = false;
        Loop::cancelTimer($budget);

        return $done;
    }

    /**
     * Advance the loop by an explicit count of iterations — zero wall time
     * when nothing is due. This is the negative-assertion half of ordering:
     * after the awaited event lands, drain a few more iterations so any
     * spurious follow-up (a superseded fetch sneaking through, a debounce
     * firing twice) would have run before the test asserts it did not.
     */
    private static function advance(int $ticks = self::ADVANCE_TICKS): void
    {
        $active    = true;
        $remaining = $ticks;
        $tick      = null;
        $tick      = static function () use (&$tick, &$active, &$remaining): void {
            if (!$active) {
                return;
            }
            if (--$remaining <= 0) {
                $active = false;
                Loop::stop();

                return;
            }
            Loop::futureTick($tick);
        };
        Loop::futureTick($tick);

        Loop::run();
        $active = false;
    }
}
