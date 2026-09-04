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
use PHPUnit\Event\TestSuite\Skipped as TestSuiteSkipped;
use PHPUnit\Event\TestSuite\SkippedSubscriber as TestSuiteSkippedSubscriber;

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
 *      only positive evidence that it can see a skip at all          -> red;
 *   4. an entire test CLASS skipping                                 -> red.
 *
 * Check 3 fires only when the rostered test was actually PREPARED, so a
 * `--filter`ed run that never reaches it is silent rather than red. That is the
 * whole reason preparation is tracked separately from skipping.
 *
 * ## Why check 4 is a separate event family, and why it is UNCONDITIONALLY red
 *
 * The `Skipped: N` line PHPUnit prints is a SUM of two unrelated event families,
 * and reading it as one number is what this guard was built to stop. MEASURED in
 * `vendor/phpunit/phpunit/src/TextUI/Output/SummaryPrinter.php`: the figure is
 * `numberOfTestSuiteSkippedEvents() + numberOfTestSkippedEvents()`.
 *
 *  - `Test\Skipped` is one test opting out from its own body or from a
 *    requirement attribute. It has a `Class::method`, so it can be rostered.
 *  - `TestSuite\Skipped` is an entire CLASS opting out -- a `setUpBeforeClass()`
 *    that calls `markTestSkipped()`, or class-level `#[Requires…]` metadata.
 *    MEASURED in `Framework/TestSuite.php::invokeMethodsBeforeFirstTest()`: it
 *    emits `testSuiteSkipped()` and then `return false`, so the class's test
 *    methods never run and never emit `Test\Prepared` or `Test\Skipped` at all.
 *
 * That second family is the LARGER silent re-base of the two, and the roster was
 * blind to it for its whole first day. MEASURED on this box, PHP 8.3.6, PHPUnit
 * 10.5.64: a child suite of one two-method class skipping from
 * `setUpBeforeClass()` plus one ordinary passing test printed
 * `OK, but some tests were skipped! / Tests: 1, Assertions: 1, Skipped: 1` and
 * exited 0 -- the skipped class's TWO tests gone from the `Tests:` total, and
 * not one byte of roster output. The same harness, same session, with a
 * body-level skip and with an attribute skip, exited 1 and named both.
 *
 * It is unconditionally red rather than roster-able because there is no key to
 * roster it BY: `TestSuite\Skipped` carries a
 * {@see \PHPUnit\Event\TestSuite\TestSuite} value object, not a
 * {@see \PHPUnit\Event\Code\Test}, so {@see keyOf()} cannot accept it and the
 * `Class::method` roster has nothing to compare against. This suite has no
 * class-level skip today (checked) and the day it acquires one is a decision
 * about the suite floor that should be made deliberately, not absorbed.
 *
 * ## What it does on a non-Linux runner, stated rather than left to be
 * discovered
 *
 * The roster is a LINUX figure. MEASURED on this box, PHP 8.3.6, `PHP_OS_FAMILY`
 * `Linux`: two skips, the entries below. It cannot be a constant across platforms,
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
 * Three things keep that handler from firing where it should not, and it is
 * worth being exact about which of them is load-bearing, because the obvious
 * answer is wrong.
 *
 *  - WHAT THIS USED TO SAY: that the handler "is armed only if the subscribers
 *    actually registered, so the several test files that
 *    `require tests/bootstrap.php` in a plain child PHP process -- no PHPUnit,
 *    no event facade -- arm nothing". WHAT IS TRUE NOW: registration SUCCEEDS in
 *    a plain child. MEASURED on this box, PHP 8.3.6 -- a `php` script whose only
 *    statement is `require tests/bootstrap.php` reports `live()` non-null and
 *    exits 0. `PHPUnit\Event\Facade` is autoloadable straight out of `vendor/`
 *    and an unsealed facade accepts subscribers from anyone. So the `try`/`catch`
 *    around registration STILL EARNS ITS PLACE -- it is what makes a SEALED
 *    facade, or a PHPUnit that moved the class, a no-op instead of a fatal in
 *    every one of those children -- but it is not what keeps them quiet.
 *  - What actually keeps them quiet is the NEXT one: {@see report()} returns
 *    null when no test was ever prepared. In a plain child nothing is prepared,
 *    nothing is skipped, and there is nothing to judge. This is the load-bearing
 *    half, and it is pinned by
 *    {@see \SugarCraft\Crush\Tests\SuiteSkipRosterTest::testAPlainChildProcessThatRequiresTheBootstrapExitsZero()}.
 *  - it returns immediately in a `pcntl_fork()`ed child. This suite forks a
 *    great deal, a child inherits the parent's shutdown handlers, and a child
 *    that exited 1 because of its PARENT's skip bookkeeping would be a fault
 *    injected by the guard itself. The owning pid is captured at arm time.
 *
 * The cost of enforcing from a shutdown handler, stated because it is not
 * obvious: `exit(1)` from one ABORTS every shutdown function registered after
 * it. MEASURED, PHP 8.3.6: of two handlers, the first calling `exit(3)`, the
 * second never runs and the process exits 3. This handler is armed from the
 * first statement of `tests/bootstrap.php`, so it precedes the
 * `register_shutdown_function` in `src/Cli/Bootstrap.php` that stops MCP
 * servers -- on a VIOLATING run those children are left unstopped. That is
 * accepted rather than fixed: the run is already red and already
 * non-comparable, and moving the verdict earlier would make it blind to the
 * back half of the tree, which is the whole reason it lives here.
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
     * The first is UNCONDITIONAL -- no environment gate, no platform check, no
     * `#[Requires…]` attribute. It is a placeholder for a test that was never
     * written, which is why it skips identically on every runner and why the
     * Linux figure and the "would be nice to delete this one day" figure are
     * the same number.
     *
     * The second is CONDITIONAL on the vendor layout: it skips itself only when
     * `vendor/sugarcraft` holds no path-repo symlinks -- the case on any
     * checkout whose siblings resolved from packagist -- and asserts wherever
     * CI's path-repo injection puts the symlink farm back.
     */
    public const EXPECTED = [
        'SugarCraft\Crush\Tests\MCP\McpClientTest::testLoadConfigReturnsEmptyArrayWhenFileGetContentsFails'
            => 'unconditional placeholder: the failure arm of McpClient::loadConfig() needs a '
                . 'read that fails after file_exists() passes, and the test was left unwritten '
                . 'rather than faking a built-in',
        'SugarCraft\Crush\Tests\Tools\BuiltIn\GitignoreAwarenessTest::testTheMonorepoPathRepoSymlinksAreNotFollowed'
            => 'conditional layout gate: since the 2026-09-04 upstream sync, packagist-resolved '
                . 'siblings install as real directories under vendor/sugarcraft, so this checkout '
                . 'has no path-repo symlinks for the walk to guard and the test skips itself; the '
                . 'symlinks return whenever CI injects the path repositories, and the test runs '
                . 'and asserts again there',
    ];

    private static ?self $live = null;

    /** @var array<string,string> every skip EVENT, full test id => message */
    private array $skipEvents = [];

    /** @var array<string,true> roster keys (Class::method) that skipped */
    private array $skippedKeys = [];

    /** @var array<string,true> roster keys that reached preparation */
    private array $preparedKeys = [];

    /**
     * Every `TestSuite\Skipped` event, suite name => message.
     *
     * A separate bucket from {@see $skipEvents} because it is a separate PHPUnit
     * event family with no `Class::method` to roster by; see check 4 in the
     * class doc-block.
     *
     * @var array<string,string>
     */
    private array $suiteSkips = [];

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
                new class($roster) implements TestSuiteSkippedSubscriber {
                    public function __construct(private readonly SuiteSkipRoster $roster)
                    {
                    }

                    public function notify(TestSuiteSkipped $event): void
                    {
                        $this->roster->recordSuiteSkip(
                            $event->testSuite()->name(),
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

    /** Record an entire test class opting out. See check 4. */
    public function recordSuiteSkip(string $suiteName, string $message): void
    {
        $this->suiteSkips[$suiteName] = $message;
    }

    /** @return array<string,string> suite name => why, for every skipped CLASS */
    public function suiteSkips(): array
    {
        return $this->suiteSkips;
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
     * Answers null when nothing was prepared AND nothing was skipped in either
     * event family. That is not a green verdict, it is "there is nothing to
     * judge": a plain PHP process that required the bootstrap, or a run that
     * died before the first test.
     *
     * A skip WITHOUT a preparation is deliberately NOT in that quiet case, and
     * the distinction is not hypothetical in either family. A `#[Requires…]`
     * skip is emitted from `TestCase::runBare()`'s `checkRequirements()`, which
     * runs ABOVE `$emitter->testPrepared()` (MEASURED in
     * `vendor/phpunit/phpunit/src/Framework/TestCase.php`), so a `--filter` that
     * selects only such a test leaves `preparedKeys` empty while a skip really
     * did happen; a `TestSuite\Skipped` class never prepares anything at all.
     * Returning null there would report a skipped run as unjudged.
     */
    public function report(): ?string
    {
        if ($this->preparedKeys === [] && $this->skipEvents === [] && $this->suiteSkips === []) {
            return null;
        }

        $unexpected = $this->unexpectedSkips();
        $stopped    = $this->rosterEntriesThatStoppedSkipping();
        $countsOff  = $this->skipEventCount() !== \count($this->expected)
            && $this->rosterWasFullyReached();

        if ($unexpected === [] && $stopped === [] && !$countsOff && $this->suiteSkips === []) {
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

        foreach ($this->suiteSkips as $suiteName => $message) {
            $lines[] = '  AN ENTIRE TEST CLASS SKIPPED: ' . $suiteName . ': ' . $message;
            $lines[] = '    Its test methods never ran and are MISSING FROM THE "Tests:" TOTAL, '
                . 'not counted as skips.';
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

        if ($this->suiteSkips !== []) {
            $lines[] = '  * a skipped CLASS cannot be put on the roster and there is no row to add:';
            $lines[] = '    the roster is keyed Class::method and PHPUnit\'s TestSuite\\Skipped event';
            $lines[] = '    carries a TestSuite, not a Test. Either make the class-level gate a';
            $lines[] = '    per-method one (a body markTestSkipped, or a method #[Requires...]';
            $lines[] = '    attribute) so it becomes rosterable, or remove the gate. Note that the';
            $lines[] = '    "Skipped: N" summary line ADDS suite skips to test skips, so N did not';
            $lines[] = '    move by the number of tests you just lost.';
        }

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
