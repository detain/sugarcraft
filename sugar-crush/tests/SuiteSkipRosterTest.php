<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Tests\Support\SuiteSkipRoster;

/**
 * {@see SuiteSkipRoster} -- the assertion that this suite skips the tests it is
 * supposed to skip, and no others.
 *
 * ## What this file has to prove, and why the obvious version proves nothing
 *
 * The roster's job is to notice an ABSENCE turning into a presence. A guard of
 * that shape is green in a tree where it is completely dead: delete the
 * subscriber registration, or mutate `report()` to return null unconditionally,
 * and a suite with one legitimate skip stays exactly as green as before. So
 * every test here is built around something whose answer is already known:
 *
 *  - the verdict logic is driven directly with SYNTHETIC recordings in both
 *    polarities -- a clean run, an off-roster skip, a rostered test that stopped
 *    skipping, a duplicate skip event, a filtered run;
 *  - the LIVE roster of the run executing this very file is asked whether it saw
 *    this test prepared. If the bootstrap's `install()` were removed or the
 *    subscriber registration were to start throwing, that is null or false;
 *  - and the whole mechanism -- registration from the real bootstrap, event
 *    accumulation, verdict, shutdown handler, process exit status -- is run END
 *    TO END in a child `phpunit` over a synthetic suite, once with an off-roster
 *    skip in it (must exit non-zero, must name the test) and once without (must
 *    exit zero). One without the other is half a control: "the child failed" is
 *    also what a broken harness says, and "the child passed" is also what a
 *    child that never started says.
 */
#[CoversClass(SuiteSkipRoster::class)]
final class SuiteSkipRosterTest extends TestCase
{
    private const SYNTHETIC_ROSTER = [
        'Acme\\Tests\\ExampleTest::testAlwaysSkips' => 'a placeholder, for the fixture',
    ];

    /**
     * The roster's one real entry names a test that EXISTS and whose skip is
     * unconditional.
     *
     * A roster is a set of names, and a name rots. If the method is renamed the
     * roster silently starts describing nothing: check 3 in `report()` would not
     * fire (the old name is never prepared), check 1 would fire on the new name,
     * and the resolution text would send the reader looking for a method that is
     * not there. Cheaper to red here, with the reason.
     */
    public function testEveryRosterEntryNamesATestThatExists(): void
    {
        self::assertNotSame([], SuiteSkipRoster::EXPECTED, 'the roster is empty');

        foreach (SuiteSkipRoster::EXPECTED as $entry => $why) {
            self::assertNotSame('', trim($why), $entry . ' is on the roster with no reason given');

            [$class, $method] = explode('::', $entry, 2);
            self::assertTrue(
                class_exists($class),
                'the skip roster names class ' . $class . ', which does not exist. A rostered test was '
                . 'renamed or deleted: update ' . SuiteSkipRoster::class . '::EXPECTED.',
            );
            self::assertTrue(
                method_exists($class, $method),
                'the skip roster names ' . $entry . ', which does not exist. A rostered test was renamed '
                . 'or deleted: update ' . SuiteSkipRoster::class . '::EXPECTED.',
            );
        }
    }

    /**
     * The live roster installed by `tests/bootstrap.php` is alive in THIS
     * process and saw THIS test prepared.
     *
     * This is the liveness half. `install()` swallows a registration failure on
     * purpose -- it has to, because several test files load the bootstrap in a
     * plain child PHP process where there is no event facade at all -- so a
     * subscriber that silently stopped registering inside a real run would leave
     * no trace anywhere except here.
     */
    public function testTheLiveRosterIsInstalledAndIsReceivingEvents(): void
    {
        $live = SuiteSkipRoster::live();

        self::assertInstanceOf(
            SuiteSkipRoster::class,
            $live,
            'no roster was installed by tests/bootstrap.php - the skip invariant is unguarded for this '
            . 'whole run. Check the SuiteSkipRoster::install() call in the bootstrap.',
        );
        self::assertGreaterThan(
            0,
            $live->preparedCount(),
            'the roster is installed but has recorded no prepared test, including this one - its '
            . 'PreparedSubscriber is not being notified.',
        );
        self::assertTrue(
            $live->enforces() === (\PHP_OS_FAMILY === 'Linux'),
            'the live roster disagrees with PHP_OS_FAMILY about whether it enforces',
        );
    }

    /** A run whose only skip is the rostered one has no report. */
    public function testACleanRunReportsNothing(): void
    {
        $roster = $this->syntheticRoster();
        $roster->recordPrepared('Acme\\Tests\\ExampleTest::testAlwaysSkips');
        $roster->recordSkip(
            'Acme\\Tests\\ExampleTest::testAlwaysSkips',
            'Acme\\Tests\\ExampleTest::testAlwaysSkips',
            'placeholder',
        );
        $roster->recordPrepared('Acme\\Tests\\ExampleTest::testRunsNormally');

        self::assertNull($roster->report());
        self::assertSame([], $roster->unexpectedSkips());
        self::assertSame([], $roster->rosterEntriesThatStoppedSkipping());
    }

    /** Check 1: a skip nobody put on the roster. */
    public function testAnOffRosterSkipIsReportedAndNamed(): void
    {
        $roster = $this->syntheticRoster();
        $roster->recordPrepared('Acme\\Tests\\ExampleTest::testAlwaysSkips');
        $roster->recordSkip(
            'Acme\\Tests\\ExampleTest::testAlwaysSkips',
            'Acme\\Tests\\ExampleTest::testAlwaysSkips',
            'placeholder',
        );
        $roster->recordPrepared('Acme\\Tests\\OtherTest::testNeedsProcfs');
        $roster->recordSkip(
            'Acme\\Tests\\OtherTest::testNeedsProcfs',
            'Acme\\Tests\\OtherTest::testNeedsProcfs',
            'no /proc on this kernel',
        );

        self::assertSame(['Acme\\Tests\\OtherTest::testNeedsProcfs'], $roster->unexpectedSkips());

        $report = (string) $roster->report();
        self::assertStringContainsString('SKIPPED BUT NOT ON THE ROSTER', $report);
        self::assertStringContainsString('Acme\\Tests\\OtherTest::testNeedsProcfs', $report);
        self::assertStringContainsString('if the new skip is LEGITIMATE', $report);
    }

    /**
     * Check 3: the count moving DOWN is a re-base too.
     *
     * And it is the more insidious direction, because it removes the roster's
     * only positive evidence that its SkippedSubscriber works at all.
     */
    public function testARosteredTestThatRanWithoutSkippingIsReported(): void
    {
        $roster = $this->syntheticRoster();
        $roster->recordPrepared('Acme\\Tests\\ExampleTest::testAlwaysSkips');

        self::assertSame(
            ['Acme\\Tests\\ExampleTest::testAlwaysSkips'],
            $roster->rosterEntriesThatStoppedSkipping(),
        );
        self::assertStringContainsString(
            'ON THE ROSTER BUT RAN WITHOUT SKIPPING',
            (string) $roster->report(),
        );
    }

    /**
     * Check 2: two skip EVENTS for one rostered method.
     *
     * Data-provider rows collapse to a single `Class::method` key, so check 1
     * cannot see a second row of an already-rostered method skipping. The event
     * count can.
     */
    public function testASecondSkipEventForOneRosteredMethodIsReported(): void
    {
        $roster = $this->syntheticRoster();
        $roster->recordPrepared('Acme\\Tests\\ExampleTest::testAlwaysSkips');
        $roster->recordSkip(
            'Acme\\Tests\\ExampleTest::testAlwaysSkips#0',
            'Acme\\Tests\\ExampleTest::testAlwaysSkips',
            'row 0',
        );
        $roster->recordSkip(
            'Acme\\Tests\\ExampleTest::testAlwaysSkips#1',
            'Acme\\Tests\\ExampleTest::testAlwaysSkips',
            'row 1',
        );

        self::assertSame([], $roster->unexpectedSkips(), 'check 1 is not the one that catches this');
        self::assertSame(2, $roster->skipEventCount());

        $report = (string) $roster->report();
        self::assertStringContainsString('SKIP EVENT COUNT IS 2, ROSTER SIZE IS 1', $report);
        self::assertStringContainsString('row 1', $report);
    }

    /**
     * A `--filter`ed run that never reaches the rostered test is silent.
     *
     * Without this the guard would red on every partial run a developer makes,
     * which is how a guard gets switched off.
     */
    public function testAFilteredRunThatNeverReachesTheRosteredTestIsSilent(): void
    {
        $roster = $this->syntheticRoster();
        $roster->recordPrepared('Acme\\Tests\\SomethingElseTest::testOne');

        self::assertSame([], $roster->rosterEntriesThatStoppedSkipping());
        self::assertNull($roster->report(), 'a filtered run was reported as a roster violation');
    }

    /** Nothing prepared at all is "nothing to judge", not "clean". */
    public function testARunWithNoPreparedTestIsNotJudged(): void
    {
        $roster = $this->syntheticRoster();

        self::assertSame(0, $roster->preparedCount());
        self::assertNull($roster->report());
    }

    /**
     * The non-Linux arm, reachable on this Linux box only because the OS family
     * is a constructor parameter.
     *
     * Both halves are asserted: the diagnostic is still produced, and it is
     * marked as not enforced and says which platform it is on.
     */
    public function testOffLinuxTheSameViolationIsReportedButNotEnforced(): void
    {
        foreach (['Darwin', 'Windows', 'BSD'] as $family) {
            $roster = new SuiteSkipRoster(self::SYNTHETIC_ROSTER, $family);
            $roster->recordPrepared('Acme\\Tests\\ExampleTest::testAlwaysSkips');
            $roster->recordSkip(
                'Acme\\Tests\\ExampleTest::testAlwaysSkips',
                'Acme\\Tests\\ExampleTest::testAlwaysSkips',
                'placeholder',
            );
            $roster->recordPrepared('Acme\\Tests\\OtherTest::testNeedsProcfs');
            $roster->recordSkip(
                'Acme\\Tests\\OtherTest::testNeedsProcfs',
                'Acme\\Tests\\OtherTest::testNeedsProcfs',
                'no /proc',
            );

            self::assertFalse($roster->enforces(), $family . ' was treated as an enforcing platform');

            $report = (string) $roster->report();
            self::assertStringContainsString('NOT enforced on ' . $family, $report);
            self::assertStringContainsString('Acme\\Tests\\OtherTest::testNeedsProcfs', $report);
        }

        $linux = new SuiteSkipRoster(self::SYNTHETIC_ROSTER, 'Linux');
        self::assertTrue($linux->enforces(), 'Linux was treated as a non-enforcing platform');
    }

    /**
     * END TO END, in a real child `phpunit`: an off-roster skip fails the run,
     * and an otherwise identical run without one does not.
     *
     * This is the only test here that exercises the parts no synthetic recording
     * can reach -- that `tests/bootstrap.php` really registers the subscribers
     * against a live facade, that PHPUnit really delivers the events, that the
     * shutdown handler really runs, and that `exit(1)` from it really reaches
     * the shell. Both polarities in one test on purpose: the negative control is
     * what separates "the guard fired" from "the harness cannot start a child".
     *
     * The child suite does not contain the rostered test, so check 3 and the
     * event-count check are both silent by construction (a filtered run) and the
     * only thing under test is check 1.
     */
    public function testAChildRunSkippingOffRosterExitsNonZeroAndACleanOneDoesNot(): void
    {
        $root = \dirname(__DIR__);

        $dirty = $this->runChildSuite(
            $root,
            <<<'PHP'
                public function testSomethingWeSuddenlyStoppedRunning(): void
                {
                    $this->markTestSkipped('a brand-new environment gate nobody put on the roster');
                }
                PHP,
        );
        $clean = $this->runChildSuite(
            $root,
            <<<'PHP'
                public function testSomethingWeSuddenlyStoppedRunning(): void
                {
                    $this->assertTrue(true);
                }
                PHP,
        );

        self::assertSame(
            0,
            $clean['rc'],
            "the NEGATIVE control failed, so the positive one below proves nothing about the roster.\n"
            . $clean['output'],
        );
        self::assertStringNotContainsString('SUITE SKIP ROSTER', $clean['output']);

        self::assertNotSame(
            0,
            $dirty['rc'],
            "an off-roster skip did not fail a real child run. The roster is installed but is not "
            . "reaching the exit code - suspect the shutdown handler or install()'s registration.\n"
            . $dirty['output'],
        );
        self::assertStringContainsString('SUITE SKIP ROSTER VIOLATION', $dirty['output']);
        self::assertStringContainsString(
            'SkipRosterProbeTest::testSomethingWeSuddenlyStoppedRunning',
            $dirty['output'],
        );
    }

    private function syntheticRoster(): SuiteSkipRoster
    {
        return new SuiteSkipRoster(self::SYNTHETIC_ROSTER, 'Linux');
    }

    /**
     * Run one synthetic test class through a real child `phpunit` that boots
     * from THIS suite's `tests/bootstrap.php`.
     *
     * The sandbox is named with `tempnam()` so it cannot collide with a sibling
     * checkout running its own suite on this shared box, and it is removed by
     * exact path - never by glob.
     *
     * @return array{rc:int, output:string}
     */
    private function runChildSuite(string $root, string $body): array
    {
        $stem = tempnam(sys_get_temp_dir(), 'sc_skiproster_');
        self::assertIsString($stem);
        unlink($stem);
        $dir = $stem . '.d';
        self::assertTrue(mkdir($dir, 0o700), 'could not create the child suite sandbox');

        $created = [];

        try {
            $testFile = $dir . '/SkipRosterProbeTest.php';
            file_put_contents(
                $testFile,
                "<?php\n\ndeclare(strict_types=1);\n\n"
                . "namespace SugarCraft\\Crush\\Tests\\SkipRosterProbe;\n\n"
                . "use PHPUnit\\Framework\\TestCase;\n\n"
                . "final class SkipRosterProbeTest extends TestCase\n{\n"
                . $body . "\n}\n",
            );
            $created[] = $testFile;

            $configFile = $dir . '/phpunit.xml';
            file_put_contents(
                $configFile,
                '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
                . '<phpunit bootstrap="' . htmlspecialchars($root . '/tests/bootstrap.php', \ENT_XML1)
                . '" colors="false" cacheDirectory="' . htmlspecialchars($dir . '/.cache', \ENT_XML1) . '">'
                . "\n  <testsuites><testsuite name=\"probe\"><directory>"
                . htmlspecialchars($dir, \ENT_XML1)
                . "</directory></testsuite></testsuites>\n</phpunit>\n",
            );
            $created[] = $configFile;

            // The probe class lives outside any PSR-4 root, so it is required
            // explicitly rather than autoloaded.
            $descriptors = [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
            $process = proc_open(
                [
                    \PHP_BINARY,
                    '-d',
                    'auto_prepend_file=',
                    $root . '/vendor/phpunit/phpunit/phpunit',
                    '--configuration',
                    $configFile,
                    '--no-progress',
                ],
                $descriptors,
                $pipes,
                $root,
            );
            self::assertIsResource($process, 'could not start the child phpunit');

            $output = (string) stream_get_contents($pipes[1]) . (string) stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $rc = proc_close($process);

            return ['rc' => $rc, 'output' => $output];
        } finally {
            foreach ($created as $path) {
                @unlink($path);
            }
            // The cache directory PHPUnit made for the child, removed by exact
            // path and one level deep - this sandbox holds nothing else.
            $cache = $dir . '/.cache';
            if (is_dir($cache)) {
                foreach ((array) scandir($cache) as $entry) {
                    if ($entry !== '.' && $entry !== '..') {
                        @unlink($cache . '/' . $entry);
                    }
                }
                @rmdir($cache);
            }
            @rmdir($dir);
        }
    }
}
