<?php

declare(strict_types=1);

namespace SugarCraft\Testing;

use React\EventLoop\Loop;
use React\EventLoop\LoopInterface;
use React\EventLoop\StreamSelectLoop;

/**
 * Pins the shared ReactPHP event loop to an implementation whose timer
 * deadlines stay honest inside a test process.
 *
 * ## The trap this exists to close
 *
 * `Loop::get()` autodetects, and where ext-uv is installed it hands back
 * `ExtUvLoop`. libuv does not read the OS clock when you arm a timer: it
 * computes the deadline against the loop's CACHED clock (`loop->time`), which
 * is refreshed once per loop iteration — as soon as the poll syscall returns,
 * before callbacks are dispatched. A timer's error is therefore the wall time
 * between the last refresh and the arm.
 *
 * A PHPUnit process is the worst possible shape for that: seconds of ordinary
 * synchronous test code between short `run()` bursts, with no iteration running
 * to refresh anything. A timer armed for 10s against a clock 10s behind is
 * already overdue and fires on the FIRST tick.
 *
 * Note what the mechanism is NOT. It is not "the loop must stay inside
 * `uv_run()`" — one `$loop->run()` is not one `uv_run()`; `ExtUvLoop::run()` is
 * itself a PHP `while` loop of short `uv_run()` calls. The exposure is idle
 * time between refreshes, wherever it comes from: blocking inside a callback
 * does it just as effectively as idling between bursts.
 *
 * The measurement table lives in ONE place, on
 * {@see \SugarCraft\Core\Program::run()} — arming a 5s timer after N seconds of
 * synchronous work at five different sites, under ext-uv and under
 * `StreamSelectLoop`. It is not restated here on purpose: a copy of it in three
 * files is how a wrong row came to be wrong in three files.
 *
 * The shape of it: every ext-uv figure is linear in N, which is the proof the
 * deadline comes off a stale cached clock rather than the OS clock. Note in
 * particular that arming BEFORE the loop's first `run()` is not the escape
 * hatch it looks like — see the "Exposed, despite appearances" paragraph there.
 * A pre-run arm survives only in the degenerate case where the armed timer is
 * the loop's earliest deadline; any other handle due sooner (and a real
 * `Program` always has one) restores the full `delay - idle` shortening.
 *
 * The casualty is any test that arms a safety timer to bound its wait: the
 * safety net fires instead of the work completing, and the test fails having
 * consumed no wall time. It presents as an intermittent, unattributable flake —
 * in sugar-crush it was a measured ~33% suite failure rate across three tests,
 * reproducing equally on the change under test and on the untouched baseline,
 * and the first diagnosis of it was wrong.
 *
 * `StreamSelectLoop` is the fix, but not for the reason usually given: it caches
 * a clock too (`Timers::$time`, from `hrtime(true) * 1e-9` on PHP 7.3+). What
 * makes it safe is that `Timers::add()` calls `Timers::updateTime()` at ARM
 * time, so a deadline is always computed against a clock read moments earlier.
 * The same probe returns 5.000s at every N and every site.
 *
 * ## Deliberately opt-in
 *
 * This is a plain call a suite makes, not an autoload side effect. A library
 * that silently swapped the caller's event loop the moment it was required
 * would be its own trap, and a worse one — invisible. Suites that want the
 * guarantee ask for it.
 */
final class LoopPin
{
    /**
     * The loop this class installed, so a repeat call is a no-op instead of
     * discarding a loop that already carries the suite's watchers.
     */
    private static ?LoopInterface $pinned = null;

    /**
     * Pin the shared loop to a clock-fresh implementation and return it.
     *
     * One line from a lib's `tests/bootstrap.php`:
     *
     *     \SugarCraft\Testing\LoopPin::pinStableClock();
     *
     * **Prefer calling it before anything else touches the loop**, for two
     * reasons. First, an already-created loop may already own stream watchers
     * and timers, and those do not transfer to the pin. Second, `Loop::get()`
     * registers a shutdown function that runs whatever loop IT created; pinning
     * afterwards would otherwise leave that hook holding the discarded
     * autodetected loop, which then runs at the end of the process — and if it
     * owns a periodic timer, runs forever. That second failure mode is a
     * process hang, so it is not left to prose: see the `Loop::stop()` call
     * below, which disarms the hook whatever the call order was.
     *
     * Idempotent: calling it again while our pin is still the active loop
     * returns that same loop untouched. If something else has since swapped the
     * shared loop out (a nested harness, another bootstrap), the re-check
     * notices and re-pins, so the returned loop is always the one `Loop::get()`
     * hands out — never an orphan nobody else uses.
     *
     * One global side effect to know about: `StreamSelectLoop`'s constructor
     * calls `pcntl_async_signals(true)` process-wide, at bootstrap time. A
     * Program does the same in {@see \SugarCraft\Core\Program::run()}, so for
     * most suites this is the production setting arriving earlier rather than a
     * divergence — but it is not unconditional parity:
     * `Program::installSignalHandlers()` returns early under
     * `withoutSignalHandler` or `catchInterrupts: false`, and such a Program
     * never enables async signals at all. A suite built around one of those
     * does diverge. Recorded rather than worked around — sugar-crush A/B'd it
     * over 30 runs with and 30 without and the results were identical. Re-check
     * it for a suite that leans on `Loop::addSignal()` or does its own
     * `pcntl_signal()` bookkeeping.
     *
     * @return LoopInterface The pinned loop, for a caller that wants to hold it
     */
    public static function pinStableClock(): LoopInterface
    {
        // Safe to consult Loop::get() only once our own pin proves an instance
        // already exists — otherwise this diagnostic would itself create the
        // autodetected loop (and its shutdown hook) that we are here to avoid.
        // The second half is the whole safety property: if anything swapped the
        // shared loop after we pinned, we must re-pin rather than hand back a
        // loop that is no longer the one Loop::get() serves.
        if (self::$pinned !== null && Loop::get() === self::$pinned) {
            return self::$pinned;
        }

        $loop = new StreamSelectLoop();

        // Loop::set() is marked @internal by react/event-loop. It is the only
        // supported way to do this, so the call is confined to this one place:
        // if upstream ever changes it, there is a single site to fix rather
        // than one per suite bootstrap.
        Loop::set($loop);

        // Neuter any shutdown hook Loop::get() already registered. The hook
        // closes over `Loop::$stopped` BY REFERENCE and skips its `$loop->run()`
        // when that flag is set, so this one call defuses it no matter what the
        // call order was — without it, a bootstrap that pinned after something
        // had already created the loop AND armed a periodic timer on it never
        // exits the process.
        //
        // The trade-off, since it is permanent for the process and not just for
        // the discarded loop: it also switches off React's "you never have to
        // call run() yourself" convenience, where the shutdown hook runs the
        // shared loop for you. A suite that schedules work on Loop::get() and
        // relies on that hook to execute it would silently get nothing. For a
        // test process that is the right side of the trade — no surprise loop
        // run after the last test — but it is a behaviour change, which is part
        // of why pinning is opt-in rather than an autoload side effect.
        Loop::stop();

        return self::$pinned = $loop;
    }

    /**
     * Whether a loop computes timer deadlines against a clock that can go stale
     * between the moment it was last refreshed and the moment a timer is armed.
     *
     * Deliberately an allowlist of implementations known to refresh the clock
     * when a timer goes in, so an unrecognised loop reads as risky rather than
     * silently safe. `StreamSelectLoop` qualifies because `Timers::add()` calls
     * `Timers::updateTime()` at arm time. Every ext-backed loop is reported as
     * at risk: ext-uv is confirmed and measured (see the class docblock), and
     * the libev/libevent loops share the same refresh-per-iteration design,
     * though they were not measured here.
     *
     * Takes the loop explicitly rather than defaulting to `Loop::get()` so that
     * asking the question can never create a loop as a side effect.
     */
    public static function hasStaleClockRisk(LoopInterface $loop): bool
    {
        return !$loop instanceof StreamSelectLoop;
    }
}
