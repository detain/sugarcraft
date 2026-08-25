<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Support;

use PHPUnit\Event\Code\Test;
use PHPUnit\Event\Code\TestMethod;
use PHPUnit\Event\Facade as EventFacade;
use PHPUnit\Event\Test\Prepared;
use PHPUnit\Event\Test\PreparedSubscriber;
use PHPUnit\Event\Test\Skipped;
use PHPUnit\Event\Test\SkippedSubscriber;

/**
 * The suite's skip roster: WHICH tests are allowed to opt out of running, by
 * name, checked against what actually happened.
 *
 * ## Why this exists at all
 *
 * "The suite skips exactly one test" has been the canary for fifty-four rounds
 * of this plan -- the cheap, one-glance proof that a run was WHOLE and that the
 * assertion total beside it therefore means something. It was checked by eye,
 * every time, and it was asserted NOWHERE. A second skip appearing anywhere in
 * the tree silently re-bases the figure and every comparison made against it,
 * and the only thing standing between that and a plan full of incomparable
 * numbers was a human reading a summary line.
 *
 * ## Why it NAMES the test instead of counting to one
 *
 * A count of 1 is satisfied by ANY one skip. Swap the roster entry below for a
 * brand-new environment gate somewhere else in the tree and a counting guard
 * stays green while the suite is now running one fewer real test AND skipping
 * one it never skipped before. The roster is therefore a SET, compared by
 * identity, and the guard reports the difference in both directions:
 *
 *   1. a skip whose `Class::method` is not on the roster            -> red;
 *   2. more skip EVENTS than roster entries -- which is how a second
 *      data-provider row of an already-rostered method would slip past
 *      check 1, since rows collapse to one method key                -> red;
 *   3. a rostered test that RAN and did NOT skip -- the count moving
 *      DOWN is a re-base too, and it silently retires this guard's
 *      only positive evidence that it can see a skip at all          -> red.
 *
 * Check 3 fires only when the rostered test was actually PREPARED, so a
 * `--filter`ed run that never reaches it is silent rather than red. That is the
 * whole reason preparation is tracked separately from skipping.
 *
 * ## What it does on a non-Linux runner, stated rather than left to be
 * discovered
 *
 * The roster is a LINUX figure. MEASURED on this box, PHP 8.3.6, `PHP_OS_FAMILY`
 * `Linux`: one skip, the entry below. It cannot be a constant across platforms,
 * because a large family of gates in this suite reads procfs -- `commOf()` reads
 * `/proc/<pid>/comm`, `directChildPids()` reads `/proc/self/task/<tid>/children`
 * -- and every one of them fires on a kernel without it. Those gates observe the
 * real process tree; there is no double for "this kernel has no procfs", so they
 * cannot be replaced by one (E438 measured this and it is why E413 was refuted
 * rather than implemented).
 *
 * So: **off Linux this guard REPORTS and does not fail.** It writes the same
 * diagnostic to STDERR, headed by a line saying the roster was measured on Linux
 * and is not being enforced on this platform, and leaves the exit code alone.
 * That is safe rather than lax because the other half of the pair already
 * exists: `BackgroundSupervisorReapTest::testThisSuiteIsNotOptedIntoAnyNonLinuxCiRunner()`
 * reds the day `sugar-crush` is added to `WINDOWS_LIBS` or `MACOS_LIBS` in
 * `scripts/affected-libs.php`, i.e. the day a non-Linux runner could produce a
 * figure anyone quotes. Until then a non-Linux run is a developer's own box, and
 * reddening it would be asserting a number nobody measures.
 *
 * ## How it fails a run, and why not from inside a test method
 *
 * Skips happen throughout the run, in file-discovery order. A test asserting on
 * them can only see the ones that ran BEFORE it, so a plain test placed anywhere
 * in `tests/` is blind to roughly half the tree -- which is a guard that looks
 * like a guard and is not one. The check therefore runs from a shutdown handler
 * armed by {@see install()}, after the last event, and `exit(1)` from a shutdown
 * function does change the process status (MEASURED, PHP 8.3.6: a script whose
 * body is `exit(0)` and whose shutdown handler is `exit(7)` exits 7).
 *
 * Three things keep that handler from firing where it should not:
 *
 *  - it is armed only if the subscribers actually registered, so the several
 *    test files that `require tests/bootstrap.php` in a plain child PHP process
 *    -- no PHPUnit, no event facade -- arm nothing;
 *  - it returns immediately when no test was ever prepared, which is the same
 *    case seen from the other side;
 *  - it returns immediately in a `pcntl_fork()`ed child. This suite forks a
 *    great deal, a child inherits the parent's shutdown handlers, and a child
 *    that exited 1 because of its PARENT's skip bookkeeping would be a fault
 *    injected by the guard itself. The owning pid is captured at arm time.
 *
 * The mechanism end to end -- registration, accumulation, verdict, shutdown,
 * exit status -- is pinned in BOTH polarities by
 * {@see \SugarCraft\Crush\Tests\SuiteSkipRosterTest}, which drives a real child
 * `phpunit` over a synthetic suite: one that skips off-roster (must exit
 * non-zero and name the test) and one that does not (must exit zero). An
 * assertion that a run was clean is worth nothing unless something in the same
 * test proves the scanner can still see a dirty one.
 */
final class SuiteSkipRoster
{
    /**
     * The tests this suite is allowed to skip, `Class::method` => why.
     *
     * There is exactly one, and it is UNCONDITIONAL -- no environment gate, no
     * platform check, no `#[Requires…]` attribute. It is a placeholder for a
     * test that was never written, which is why it skips identically on every
     * runner and why the Linux figure and the "would be nice to delete this one
     * day" figure are the same number.
     */
    public const EXPECTED = [
        'SugarCraft\Crush\Tests\MCP\McpClientTest::testLoadConfigReturnsEmptyArrayWhenFileGetContentsFails'
            => 'unconditional placeholder: the failure arm of McpClient::loadConfig() needs a '
                . 'read that fails after file_exists() passes, and the test was left unwritten '
                . 'rather than faking a built-in',
    ];

    private static ?self $live = null;

    /** @var array<string,string> every skip EVENT, full test id => message */
    private array $skipEvents = [];

    /** @var array<string,true> roster keys (Class::method) that skipped */
    private array $skippedKeys = [];

    /** @var array<string,true> roster keys that reached preparation */
    private array $preparedKeys = [];

    /**
     * @param array<string,string> $expected roster, `Class::method` => why
     * @param string               $osFamily the value of `PHP_OS_FAMILY` to
     *                                       judge by; a parameter so the
     *                                       non-Linux arm is reachable from a
     *                                       test on this Linux box
     */
    public function __construct(
        private readonly array $expected = self::EXPECTED,
        private readonly string $osFamily = \PHP_OS_FAMILY,
    ) {
    }

    /**
     * Install the roster on the live PHPUnit run.
     *
     * Called from `tests/bootstrap.php`, which PHPUnit loads BEFORE it seals the
     * event facade (`Application::run()` loads the bootstrap script and seals
     * later in the same method), so subscriber registration from there is legal.
     * A sealed facade, a missing facade, or anything else that throws means this
     * is not a PHPUnit run at all and there is nothing to observe: the failure is
     * swallowed and nothing is armed.
     */
    public static function install(): void
    {
        if (self::$live !== null) {
            return;
        }

        $roster = new self();

        try {
            EventFacade::instance()->registerSubscribers(
                new class($roster) implements PreparedSubscriber {
                    public function __construct(private readonly SuiteSkipRoster $roster)
                    {
                    }

                    public function notify(Prepared $event): void
                    {
                        $this->roster->recordPrepared(SuiteSkipRoster::keyOf($event->test()));
                    }
                },
                new class($roster) implements SkippedSubscriber {
                    public function __construct(private readonly SuiteSkipRoster $roster)
                    {
                    }

                    public function notify(Skipped $event): void
                    {
                        $this->roster->recordSkip(
                            $event->test()->id(),
                            SuiteSkipRoster::keyOf($event->test()),
                            $event->message(),
                        );
                    }
                },
            );
        } catch (\Throwable) {
            return;
        }

        self::$live = $roster;
        $ownerPid   = getmypid();

        register_shutdown_function(static function () use ($roster, $ownerPid): void {
            // A pcntl_fork()ed child inherits this handler. Its exit status
            // belongs to whatever the fork was for, never to the parent's
            // bookkeeping.
            if (getmypid() !== $ownerPid) {
                return;
            }

            $report = $roster->report();
            if ($report === null) {
                return;
            }

            fwrite(\STDERR, \PHP_EOL . $report . \PHP_EOL);

            if ($roster->enforces()) {
                exit(1);
            }
        });
    }

    /** The live roster of this run, or null when nothing was installed. */
    public static function live(): ?self
    {
        return self::$live;
    }

    /**
     * The roster key for a test: `Class::method` for a test method, and the
     * event's own id for anything else PHPUnit can skip.
     *
     * Data-provider rows share one key by design -- a provider's rows are one
     * test as far as "which test is allowed to skip" is concerned. What that
     * loses is caught by the event-count check in {@see report()} instead.
     */
    public static function keyOf(Test $test): string
    {
        if ($test instanceof TestMethod) {
            return $test->className() . '::' . $test->methodName();
        }

        return $test->id();
    }

    public function recordPrepared(string $key): void
    {
        $this->preparedKeys[$key] = true;
    }

    public function recordSkip(string $id, string $key, string $message): void
    {
        $this->skipEvents[$id] = $message;
        $this->skippedKeys[$key] = true;
    }

    /** True when a verdict of this roster is allowed to fail the run. */
    public function enforces(): bool
    {
        return $this->osFamily === 'Linux';
    }

    /** @return list<string> skips whose test is not on the roster */
    public function unexpectedSkips(): array
    {
        $out = [];
        foreach (array_keys($this->skippedKeys) as $key) {
            if (!\array_key_exists($key, $this->expected)) {
                $out[] = $key;
            }
        }

        return $out;
    }

    /** @return list<string> rostered tests that ran and did not skip */
    public function rosterEntriesThatStoppedSkipping(): array
    {
        $out = [];
        foreach (array_keys($this->expected) as $key) {
            if (isset($this->preparedKeys[$key]) && !isset($this->skippedKeys[$key])) {
                $out[] = $key;
            }
        }

        return $out;
    }

    public function skipEventCount(): int
    {
        return \count($this->skipEvents);
    }

    public function preparedCount(): int
    {
        return \count($this->preparedKeys);
    }

    /**
     * The diagnostic for this run, or null when the observed skips match the
     * roster exactly.
     *
     * Answers null when NO test was ever prepared. That is not a green verdict,
     * it is "there is nothing to judge": a plain PHP process that included the
     * bootstrap, or a run that died before the first test.
     */
    public function report(): ?string
    {
        if ($this->preparedKeys === []) {
            return null;
        }

        $unexpected = $this->unexpectedSkips();
        $stopped    = $this->rosterEntriesThatStoppedSkipping();
        $countsOff  = $this->skipEventCount() !== \count($this->expected)
            && $this->rosterWasFullyReached();

        if ($unexpected === [] && $stopped === [] && !$countsOff) {
            return null;
        }

        $lines = [];
        $lines[] = '=================================================================';
        $lines[] = $this->enforces()
            ? 'SUITE SKIP ROSTER VIOLATION -- this run is NOT comparable to the plan floor.'
            : 'SUITE SKIP ROSTER DIFFERENCE -- reported only, NOT enforced on '
                . $this->osFamily . '. The roster is a Linux figure (see '
                . self::class . "'s doc-block); CI runs this suite on ubuntu only.";
        $lines[] = '=================================================================';

        foreach ($unexpected as $key) {
            $lines[] = '  SKIPPED BUT NOT ON THE ROSTER: ' . $key;
        }

        foreach ($stopped as $key) {
            $lines[] = '  ON THE ROSTER BUT RAN WITHOUT SKIPPING: ' . $key;
        }

        if ($countsOff) {
            $lines[] = sprintf(
                '  SKIP EVENT COUNT IS %d, ROSTER SIZE IS %d '
                . '(a rostered method skipping in more than one data-provider row lands here)',
                $this->skipEventCount(),
                \count($this->expected),
            );
            foreach ($this->skipEvents as $id => $message) {
                $lines[] = '    - ' . $id . ': ' . $message;
            }
        }

        $lines[] = '';
        $lines[] = 'HOW TO RESOLVE THIS, and it is a decision rather than a formality:';
        $lines[] = '  * if the new skip is LEGITIMATE, add its Class::method to '
            . self::class . '::EXPECTED with the reason, AND say so wherever the';
        $lines[] = '    suite floor is quoted -- the "tests / assertions / skipped" triple in the';
        $lines[] = '    round brief moves with it, and every figure compared against the old one';
        $lines[] = '    is now comparing across a different suite.';
        $lines[] = '  * if it is NOT legitimate, the test stopped running and the fix is in the';
        $lines[] = '    test, not here. Do not add a row to silence it.';

        return implode(\PHP_EOL, $lines);
    }

    /**
     * True when every rostered test was reached by this run.
     *
     * The event-count check compares against the whole roster, so it is only
     * meaningful once the whole roster has had its chance to fire; under
     * `--filter` it would otherwise report "1 event expected, 0 seen" on every
     * partial run.
     */
    private function rosterWasFullyReached(): bool
    {
        foreach (array_keys($this->expected) as $key) {
            if (!isset($this->preparedKeys[$key])) {
                return false;
            }
        }

        return true;
    }
}
