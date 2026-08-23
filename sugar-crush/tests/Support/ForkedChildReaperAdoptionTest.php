<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Support;

use PHPUnit\Framework\TestCase;

/**
 * A test that forks IN-PROCESS must also reap, and the two facts must not be
 * able to drift apart.
 *
 * {@see ReapsForkedChildrenTrait} explains the hole: `phpunit.xml`'s
 * `defaultTimeLimit` is `pcntl_alarm()`, which is not inherited across
 * `pcntl_fork()`, so aborting a forking test aborts exactly one of the
 * processes it put on the machine. Adopting the trait closes it - and nothing
 * about adopting it is self-enforcing. Delete the `use` line, or the
 * `tearDown()` call, and every test still passes; the net is simply gone.
 * So the adoption is derived from the forks rather than listed: any file the
 * fork scanner finds a site in has to carry both halves.
 *
 * SCOPE, and it is deliberate rather than accidental. WHAT IT SAID:
 * "`tests/Integration/` only... widening {@see SCOPE} to `''` derives the
 * list instead. That is the whole of the work when somebody owns those
 * files." WHAT IS TRUE NOW: round 47's lane b owned `tests/Agents/`,
 * `tests/Integration/` and `tests/Support/`, and added exactly those three
 * to {@see SCOPE} - which is what turned four raw, unreaped forks in
 * `Agents/AgentWorkerPoolTest.php` (two of whose children `sleep(120)`,
 * i.e. twice `defaultTimeLimit`) and two in `Support/ForkedChildTest.php`
 * into adoptions. `Diagnostics/` is a FOURTH, and it is not lane b's: round
 * 47's lane a added it in the same round, which is why counting the prefixes
 * from this paragraph alone gives the wrong answer. Round 48 owned
 * `tests/Backend/` and adopted the four raw forks in
 * `Backend/EngineBackendReapTest.php`, so {@see SCOPE} gained a FIFTH prefix
 * and {@see OUT_OF_SCOPE} emptied. WHY THIS STILL EARNS ITS
 * PLACE: `''` STILL is not reachable from one lane - the remaining
 * directories hold no unreaped fork today, but adding them is an obligation
 * on every fork a sibling later writes there, which reds at merge in a lane
 * that never saw this file. So {@see OUT_OF_SCOPE} stays as the mechanism
 * even while it is empty: it is derived, self-deleting, and NOT prose,
 * because {@see testEveryOutOfScopeDirectoryStillHasAnUnreapedFork()} fails
 * the moment a listed directory becomes clean - which is exactly how
 * `Backend/` came off it, and which is the failure mode the prose list this
 * replaced actually had (it was written from a census taken with a scanner
 * that could not see `\pcntl_fork()`, and so omitted
 * `tests/Agents/TaskListTest.php` entirely).
 */
final class ForkedChildReaperAdoptionTest extends TestCase
{
    /**
     * Path prefixes, relative to `tests/`, the adoption is required under.
     *
     * @var list<string>
     */
    private const SCOPE = ['Agents/', 'Backend/', 'Diagnostics/', 'Integration/', 'Support/'];

    /**
     * Prefixes with in-process forks that are NOT yet under {@see SCOPE},
     * with the reason each is still out.
     *
     * Not an exemption and not a to-do list in prose: every entry is checked
     * against the tree by {@see testEveryOutOfScopeDirectoryStillHasAnUnreapedFork()},
     * which fails both when a listed prefix has become clean (delete the row
     * and widen SCOPE) and when a listed prefix has crept into SCOPE (the two
     * lists would then disagree about the same directory).
     *
     * EMPTY, AND KEPT. WHAT IT SAID: one row for `Backend/`, whose
     * `EngineBackendReapTest.php` forked four times with a raw
     * `pcntl_fork()` and declared no `tearDown()`, deferred because round
     * 47's lane split gave that directory to nobody. WHAT IS TRUE NOW: round
     * 48 owned it, adopted the trait, routed all four through
     * `$this->forkTracked()` and added a reaping `tearDown()`, so the row
     * went and `Backend/` joined {@see SCOPE} - which is the self-deleting
     * behaviour working, not an incident. WHY AN EMPTY MAP STILL EARNS ITS
     * PLACE: it is the only place a future deferral can be recorded as
     * something checked against the tree rather than as a comment, and
     * {@see testNoDirectoryWithUnreapedForksIsUnaccountedFor()} refuses the
     * alternative - a fork in a directory named by neither map.
     *
     * WHAT EMPTYING IT COST, and why the test below changed in the same
     * commit: this map was the reaper predicate's ONLY known-positive
     * against real files on disk. Every other assertion in this file is an
     * ABSENCE, and an absence is not evidence unless something in the same
     * test proves the instrument can still produce a positive. So the walk
     * those absences use now runs over a SYNTHETIC fixture tree first
     * ({@see assertTheOffenderWalkIsAlive()}), which does not empty when the
     * tree gets healthier.
     *
     * @var array<string,string>
     */
    private const OUT_OF_SCOPE = [];

    /**
     * Raw `pcntl_fork()` sites in an adopting file that are deliberately NOT
     * tracked, with the count and the reason.
     *
     * The count is part of the exemption on purpose. A bare file-keyed
     * exemption is a blank cheque - it would license every future fork added
     * to the file as well as the one that was argued for.
     *
     * @var array<string,array{count:int,reason:string}>
     */
    private const UNTRACKED_FORKS_ALLOWED = [
        'Integration/ParallelToolCallsTest.php' => [
            'count' => 1,
            'reason' =>
                'testAGroupWhoseForksAllFail...\'s NPROC probe. It runs INSIDE a child that '
                . 'forkTracked() already emptied the ledger in, and that child leaves through '
                . 'ForkedChild::exitNow() without ever running tearDown() - so there is no reaper '
                . 'on that side for a ledger entry to reach. Tracking it would record a pid that '
                . 'nothing can ever reap, which is a lie about what the ledger is for.',
        ],

        'Support/ReapsForkedChildrenTraitTest.php' => [
            'count' => 2,
            'reason' =>
                'BOTH raw forks are the point of the test they sit in, and routing either through '
                . 'forkTracked() would delete what it measures. forkSleeper()\'s child is handed '
                . 'to trackForkedChild() BY HAND, which is the other entry point to the ledger '
                . 'and has to be exercised by something. '
                . 'testAChildForkedOutsideTheTraitCannotReapTheLedgerItInherited()\'s child must '
                . 'inherit a POPULATED ledger to reach the owner check at all - forkTracked() '
                . 'empties the child\'s copy, so routing it there would exercise the FIRST line '
                . 'of defence for a second time and leave the second untested, which is the state '
                . 'that test was written to end.',
        ],
    ];

    /**
     * The three halves an adopting file must carry, matched as CODE.
     *
     * THE THIRD HALF IS THE ONE THAT CARRIES THE PIDS, and it was missing.
     * The first version checked for a `use` line and a `reapTrackedForkedChildren()`
     * line, neither of which implies a single pid ever enters the ledger:
     * reverting the two `$this->forkTracked()` calls in MultiAgentRefactorTest
     * to plain `pcntl_fork()` left the reaper reaping nothing, on the abort
     * path and every other path, and both guards stayed green (measured:
     * mutation R1 SURVIVED). Declaring the net is not hanging it.
     *
     * The other two halves are structural now rather than line-anchored, for
     * reasons {@see ReaperAdoptionScanner} records: a namespace import
     * satisfied the first, and a call in a method nothing calls satisfied the
     * second.
     *
     * @param list<array{line:int,spelling:string,shape:string}> $sites
     * @return list<string> the halves a source is missing, empty when whole
     */
    private static function missingHalves(string $source, array $sites, int $untrackedAllowed = 0): array
    {
        $missing = [];

        // A FILE WHOSE EVERY SITE IS A FORK WRAPPER HAS PUT NO CHILD ON THE
        // MACHINE OF ITS OWN, and owes nothing here. The wrapper's CALLER
        // forked; the ledger and the reaper belong in the caller's file, which
        // is where the other two halves are already required.
        //
        // This is not a courtesy: it is what stops the guard reddening
        // {@see ReapsForkedChildrenTrait} itself the moment SCOPE includes
        // `Support/`. The trait DEFINES the reaper and cannot adopt itself,
        // and it declares no tearDown() because it is not a test - so under
        // the unconditional check it reported both halves missing, which is
        // the polarity that gets a guard exempted rather than fixed.
        //
        // Deliberately derived rather than file-keyed. A file earns the skip
        // by having no fork of its own and loses it again the instant it
        // calls one, because a `forkTracked()`/`pcntl_fork()` CALL is a site
        // of a shape other than {@see ForkedChildExitScanner::SHAPE_FORK_WRAPPER}.
        if ($sites !== [] && self::everySiteIsAForkWrapper($sites)) {
            return [];
        }

        if (!ReaperAdoptionScanner::adoptsTrait($source, 'ReapsForkedChildrenTrait')) {
            $missing[] = 'use ReapsForkedChildrenTrait in the class body';
        }

        $position = ReaperAdoptionScanner::reapPositionInTearDown($source, 'reapTrackedForkedChildren');
        if ($position !== ReaperAdoptionScanner::REAP_FIRST) {
            $missing[] = 'reapTrackedForkedChildren() first in tearDown() (' . $position . ')';
        }

        $untracked = 0;
        foreach ($sites as $site) {
            if ($site['spelling'] !== 'forkTracked'
                && $site['shape'] !== ForkedChildExitScanner::SHAPE_FORK_WRAPPER) {
                $untracked++;
            }
        }
        if ($untracked > $untrackedAllowed) {
            $missing[] = $untracked . ' fork(s) not routed through $this->forkTracked()'
                . ($untrackedAllowed > 0 ? ' (' . $untrackedAllowed . ' allowed)' : '');
        }

        return $missing;
    }

    /** @param list<array{line:int,spelling:string,shape:string}> $sites */
    private static function everySiteIsAForkWrapper(array $sites): bool
    {
        foreach ($sites as $site) {
            if ($site['shape'] !== ForkedChildExitScanner::SHAPE_FORK_WRAPPER) {
                return false;
            }
        }

        return true;
    }

    /**
     * Every file under $directory the reaper predicate finds work in, as
     * paths relative to $root.
     *
     * THE ONE WALK. It was inline in
     * {@see testEveryOutOfScopeDirectoryStillHasAnUnreapedFork()} and is
     * extracted so the synthetic known-positive below runs through THE SAME
     * code the real-tree assertions do. A fixture that exercises a
     * re-implementation of the walk proves the fixture works.
     *
     * {@see UNTRACKED_FORKS_ALLOWED} IS CONSULTED HERE, and it was not in the
     * inline version - that copy called {@see missingHalves()} with the
     * default allowance of 0 while the other two guards passed the file's
     * real allowance, so two guards disagreed about the same predicate. No
     * live effect either before or after (no exempted file has ever sat under
     * a prefix this walk is pointed at), which is exactly why it would have
     * gone on disagreeing until the day it mattered.
     *
     * @return list<string>
     */
    private static function unreapedForkOffendersUnder(string $directory, string $root): array
    {
        $offenders = [];

        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory));
        foreach ($files as $file) {
            /** @var \SplFileInfo $file */
            if (!$file->isFile() || !str_ends_with($file->getFilename(), '.php')) {
                continue;
            }

            $source = (string) file_get_contents($file->getPathname());
            $sites = ForkedChildExitScanner::scan($source);
            if ($sites === []) {
                continue;
            }

            $relative = substr($file->getPathname(), \strlen($root) + 1);
            if (self::missingHalves($source, $sites, self::UNTRACKED_FORKS_ALLOWED[$relative]['count'] ?? 0) !== []) {
                $offenders[] = $relative;
            }
        }

        sort($offenders);

        return $offenders;
    }

    /**
     * A KNOWN-POSITIVE THROUGH THE SAME PREDICATE, on files whose answer is
     * known, in the same test that uses it to assert an absence.
     *
     * PREDICATE, NOT WALK, and the distinction is measured rather than
     * pedantic. {@see unreapedForkOffendersUnder()} - the directory walk this
     * fixture drives - is called by exactly ONE of the three tests that
     * assert an absence, {@see testEveryOutOfScopeDirectoryStillHasAnUnreapedFork()};
     * the other two iterate `tests/` inline. What all three share, and what
     * this fixture therefore proves is alive, is the pair that DECIDES:
     * {@see ForkedChildExitScanner::scan()} finding the sites and
     * {@see missingHalves()} judging them. Measured, and stated precisely
     * because the first draft of this paragraph rounded it up: stubbing
     * `scan()` to `[]` reds all four real-tree tests here, and stubbing
     * `missingHalves()` to `[]` reds three of them plus
     * {@see testThePredicateReportsEachHalfItLooksFor()} - the fourth,
     * {@see testEveryUntrackedForkExemptionStillDescribesRealSites()},
     * counts sites and never consults the judgement. So the claim this
     * method supports is "the predicate is alive", which is what the absences
     * depend on - not "this exact walk is alive", which would be true of one
     * caller in three.
     *
     * WHY THIS EXISTS AT ALL, and it is the whole reason it was written in
     * the commit that emptied {@see OUT_OF_SCOPE} rather than in a later one.
     * That map used to be this file's only real-tree known-positive: with a
     * row for `Backend/`, {@see testEveryOutOfScopeDirectoryStillHasAnUnreapedFork()}
     * asserted a PRESENCE against files on disk, so a scanner that stopped
     * matching failed loudly there. Fixing `Backend/` deleted the row, and
     * every remaining assertion in this file became an absence - "nothing is
     * missing a reaper" - which a dead instrument satisfies perfectly. Doing
     * the fix and the replacement in separate commits would leave a window
     * where the guard was green and blind, and that window is the whole
     * hazard.
     *
     * A SYNTHETIC TREE RATHER THAN A REAL FILE, because a real one is exactly
     * what stopped working: a known-positive that lives in `tests/` is a
     * known-positive somebody is eventually asked to fix. This pair cannot be
     * fixed away - it is written on the fly, outside `tests/`, and removed
     * again.
     *
     * BOTH POLARITIES, in one assertion. A scanner stuck at "everything is an
     * offender" is not alive either; it is a guard that reds correct code,
     * which is answered with an exemption, which is where the next real
     * offender hides. So the adopting fixture beside the offending one has to
     * come back clean.
     */
    private function assertTheOffenderWalkIsAlive(): void
    {
        $root = sys_get_temp_dir() . '/sc_reaper_walk_fixture_' . bin2hex(random_bytes(6));
        $directory = $root . '/Fixture';
        if (!mkdir($directory, 0o700, true) && !is_dir($directory)) {
            self::fail('could not create the known-positive fixture tree at ' . $directory);
        }

        // An in-process fork with a child branch the scanner understands, in
        // a class that declares neither half of the adoption.
        file_put_contents($directory . '/Unreaped.php', <<<'PHP'
            <?php
            class UnreapedFixture
            {
                public function testForks(): void
                {
                    $pid = pcntl_fork();
                    if ($pid === 0) {
                        \SugarCraft\Crush\Support\ForkedChild::exitNow(0);
                    }
                    pcntl_waitpid($pid, $status);
                }
            }
            PHP);

        // The same fork, carrying all three halves.
        file_put_contents($directory . '/Adopting.php', <<<'PHP'
            <?php
            class AdoptingFixture
            {
                use ReapsForkedChildrenTrait;

                protected function tearDown(): void
                {
                    $this->reapTrackedForkedChildren();
                    $this->removeTree();
                }

                public function testForks(): void
                {
                    $pid = $this->forkTracked();
                    if ($pid === 0) {
                        \SugarCraft\Crush\Support\ForkedChild::exitNow(0);
                    }
                    pcntl_waitpid($pid, $status);
                }
            }
            PHP);

        try {
            $offenders = self::unreapedForkOffendersUnder($directory, $root);
        } finally {
            @unlink($directory . '/Unreaped.php');
            @unlink($directory . '/Adopting.php');
            @rmdir($directory);
            @rmdir($root);
        }

        $this->assertSame(
            ['Fixture/Unreaped.php'],
            $offenders,
            'the walk every absence assertion in this file depends on is not reporting a file '
                . 'that plainly forks in-process with no reaper, or is reporting one that plainly '
                . 'does have one. Until this passes, "no directory has an unreaped fork" is a '
                . 'statement about a dead instrument rather than about the tree.',
        );
    }

    /** Whether a `tests/`-relative path falls under any {@see SCOPE} prefix. */
    private static function inScope(string $relative): bool
    {
        foreach (self::SCOPE as $prefix) {
            if (str_starts_with($relative, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * THE PREDICATE IS ALIVE, proved on inputs whose answer is known, in the
     * same test that uses it to assert an absence. A scanner that stopped
     * matching would report "nothing is missing" just as cheerfully.
     */
    public function testThePredicateReportsEachHalfItLooksFor(): void
    {
        $tracked = [['line' => 1, 'spelling' => 'forkTracked', 'shape' => 'exitNow']];
        $raw = [['line' => 1, 'spelling' => 'pcntl_fork', 'shape' => 'exitNow']];

        $whole = "<?php\nclass F extends TestCase {\n    use ReapsForkedChildrenTrait;\n"
            . "    protected function tearDown(): void {\n"
            . "        \$this->reapTrackedForkedChildren();\n        \$this->wipe();\n    }\n}\n";

        $this->assertSame([], self::missingHalves($whole, $tracked));

        $this->assertSame(
            [
                'use ReapsForkedChildrenTrait in the class body',
                'reapTrackedForkedChildren() first in tearDown() ('
                    . ReaperAdoptionScanner::TEARDOWN_MISSING . ')',
                '1 fork(s) not routed through $this->forkTracked()',
            ],
            self::missingHalves("<?php\nclass F {}\n", $raw),
            'all three halves absent',
        );

        // HALF THREE, and the mutation that got through. Every declaration
        // present, the forks going nowhere near the ledger.
        $this->assertSame(
            ['1 fork(s) not routed through $this->forkTracked()'],
            self::missingHalves($whole, $raw),
        );

        // ...and the exemption, which is a count and not a blank cheque.
        $this->assertSame([], self::missingHalves($whole, $raw, 1));
        $this->assertSame(
            ['2 fork(s) not routed through $this->forkTracked() (1 allowed)'],
            self::missingHalves($whole, [...$raw, ...$raw], 1),
        );

        // A fork WRAPPER is the trait's own `pcntl_fork()`; it is not an
        // untracked call site.
        $this->assertSame(
            [],
            self::missingHalves(
                $whole,
                [['line' => 1, 'spelling' => 'pcntl_fork', 'shape' => ForkedChildExitScanner::SHAPE_FORK_WRAPPER]],
            ),
        );

        // A NAMESPACE IMPORT is not an adoption. This is the file that
        // results from deleting the `use` inside the class while leaving the
        // import that made the short name resolve.
        $this->assertContains(
            'use ReapsForkedChildrenTrait in the class body',
            self::missingHalves(
                "<?php\nuse SugarCraft\\Crush\\Tests\\Support\\ReapsForkedChildrenTrait;\n"
                . "class F {\n    protected function tearDown(): void {\n"
                . "        \$this->reapTrackedForkedChildren();\n    }\n}\n",
                $tracked,
            ),
        );

        // THE MUTATION THAT GOT THROUGH, kept as a fixture. The call moved
        // out of tearDown() into a private method nothing calls: present in
        // the file, on no execution path at all.
        $this->assertContains(
            'reapTrackedForkedChildren() first in tearDown() (' . ReaperAdoptionScanner::REAP_ABSENT . ')',
            self::missingHalves(
                "<?php\nclass F {\n    use ReapsForkedChildrenTrait;\n"
                . "    protected function tearDown(): void { \$this->wipe(); }\n"
                . "    private function unused(): void { \$this->reapTrackedForkedChildren(); }\n}\n",
                $tracked,
            ),
        );

        // Present in tearDown() but AFTER the temp tree goes - the ordering
        // the whole mechanism turns on.
        $this->assertContains(
            'reapTrackedForkedChildren() first in tearDown() (' . ReaperAdoptionScanner::REAP_NOT_FIRST . ')',
            self::missingHalves(
                "<?php\nclass F {\n    use ReapsForkedChildrenTrait;\n"
                . "    protected function tearDown(): void {\n        \$this->removeTree();\n"
                . "        \$this->reapTrackedForkedChildren();\n    }\n}\n",
                $tracked,
            ),
        );

        // Both halves named in PROSE and neither present as code.
        $this->assertSame(
            [
                'use ReapsForkedChildrenTrait in the class body',
                'reapTrackedForkedChildren() first in tearDown() ('
                    . ReaperAdoptionScanner::TEARDOWN_MISSING . ')',
            ],
            self::missingHalves(
                "<?php\n/**\n * Adopts {@see ReapsForkedChildrenTrait}: call\n"
                . " * \$this->reapTrackedForkedChildren() from tearDown().\n */\nclass F {}\n",
                $tracked,
            ),
        );

        // THE OTHER POLARITY, which is the one that bites an instrument: a
        // file that IS adopting, read as though it were not. An interpolated
        // string opens its brace with an ARRAY token and closes it with a
        // plain '}', so a walk that counts only the closer loses a level and
        // every later class-body `use` stops looking like one. Measured
        // before the fix: this exact source reported the trait half missing,
        // i.e. the guard reddened correct code - and a guard that reds
        // correct code is answered with an exemption, which is where the
        // next real offender hides.
        $this->assertSame(
            [],
            self::missingHalves(
                "<?php\nclass F {\n"
                . "    protected function name(): string { return \"run={\$this->id}\"; }\n"
                . "    use ReapsForkedChildrenTrait;\n"
                . "    protected function tearDown(): void {\n"
                . "        \$this->reapTrackedForkedChildren();\n    }\n}\n",
                $tracked,
            ),
            'a trait use after an interpolated string is still a trait use',
        );

        // A FILE WHOSE EVERY SITE IS A FORK WRAPPER owes nothing, even with
        // both declarations absent - it forked nobody, its caller did. This is
        // ReapsForkedChildrenTrait's own shape, and without it widening SCOPE
        // to include `Support/` reddened the trait that defines the reaper for
        // failing to adopt itself.
        $this->assertSame(
            [],
            self::missingHalves(
                "<?php\ntrait T { protected function forkTracked(): int { return 0; } }\n",
                [['line' => 1, 'spelling' => 'pcntl_fork', 'shape' => ForkedChildExitScanner::SHAPE_FORK_WRAPPER]],
            ),
            'a file that only DEFINES a fork wrapper has put no child on the machine',
        );

        // ...and it loses the skip the instant it also CALLS one. The skip is
        // derived from the sites, not granted to the file, so a wrapper cannot
        // be used to buy an exemption for a real fork beside it.
        $this->assertNotSame(
            [],
            self::missingHalves(
                "<?php\ntrait T { protected function forkTracked(): int { return 0; } }\n",
                [
                    ['line' => 1, 'spelling' => 'pcntl_fork', 'shape' => ForkedChildExitScanner::SHAPE_FORK_WRAPPER],
                    ['line' => 9, 'spelling' => 'pcntl_fork', 'shape' => ForkedChildExitScanner::SHAPE_BARE_EXIT],
                ],
            ),
            'a wrapper standing beside a real fork must not exempt the file',
        );
    }

    public function testEveryInProcessForkInScopeIsCoveredByTheReaper(): void
    {
        $this->assertTheOffenderWalkIsAlive();

        $root = \dirname(__DIR__);
        $offenders = [];
        $covered = 0;

        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root));
        foreach ($files as $file) {
            /** @var \SplFileInfo $file */
            if (!$file->isFile() || !str_ends_with($file->getFilename(), '.php')) {
                continue;
            }

            $relative = substr($file->getPathname(), \strlen($root) + 1);
            if (!self::inScope($relative)) {
                continue;
            }

            $source = (string) file_get_contents($file->getPathname());
            $sites = ForkedChildExitScanner::scan($source);
            if ($sites === []) {
                continue;
            }

            $missing = self::missingHalves(
                $source,
                $sites,
                self::UNTRACKED_FORKS_ALLOWED[$relative]['count'] ?? 0,
            );
            if ($missing === []) {
                $covered++;

                continue;
            }

            $offenders[] = $relative . ' is missing: ' . implode(' and ', $missing);
        }

        // A SCOPE THAT MATCHES NOTHING WOULD PASS THIS SILENTLY. It is not a
        // headcount - the number is free to move as files are added - only a
        // statement that the loop above found work to do at all.
        $this->assertGreaterThan(
            0,
            $covered,
            'the scanner found no covered in-process forks under ' . implode(', ', self::SCOPE)
                . ' - either the scope is wrong or the scanner is dead',
        );

        $this->assertSame(
            [],
            $offenders,
            'a test here forks inside the PHPUnit process but has no reaper, so an abort at the '
                . 'per-test time limit leaves its children running with no clock (the alarm fires '
                . 'in the parent only). Add `use ReapsForkedChildrenTrait;`, fork through '
                . '`$this->forkTracked()`, and call `$this->reapTrackedForkedChildren()` as the '
                . 'FIRST thing in tearDown() - before anything that removes a temp tree.',
        );
    }

    /**
     * A DEFERRAL IS A CLAIM ABOUT THE TREE, so it is checked against the tree.
     *
     * {@see OUT_OF_SCOPE} says "this directory still has unreaped in-process
     * forks and this lane could not edit it". Both halves are verified: the
     * prefix must be genuinely outside {@see SCOPE} (otherwise the two
     * constants disagree about the same files), and it must still contain a
     * file the reaper predicate finds work in (otherwise the row is a note
     * about something already done, and the next reader widens SCOPE by
     * deleting a row rather than by measuring).
     *
     * WHAT THIS SAID: "the scanner used here is the SAME one the in-scope
     * assertion uses, and this test is the known-positive fixture for it: it
     * asserts a PRESENCE. A scanner that stopped matching would fail here
     * loudly instead of turning the absence assertion above silently green."
     *
     * WHAT IS TRUE NOW: {@see OUT_OF_SCOPE} is empty, so the loop below runs
     * zero times and this test asserts no presence against the tree at all.
     * The sentence described the file as it stood while `Backend/` still had
     * a row; the commit that adopted `Backend/` deleted the row and, with it,
     * the only real-tree positive this predicate had. Leaving the claim
     * standing tells the next reader that the absence assertions elsewhere in
     * this file are backed by a positive here, and they are not.
     *
     * WHY THE REASONING STILL EARNS ITS PLACE: it was right about the hazard
     * and wrong only about which mechanism now covers it. An absence asserted
     * by a dead scanner is a green run about nothing, and the answer is still
     * a positive through the same predicate - it just has to be one the tree
     * cannot get healthier than, which a synthetic fixture is and a row in a
     * legitimately-empty map is not. That is
     * {@see assertTheOffenderWalkIsAlive()}, called on the first line below.
     * Delete this paragraph and the next reader restores a known-positive
     * duty to a map that is allowed to be empty.
     */
    public function testEveryOutOfScopeDirectoryStillHasAnUnreapedFork(): void
    {
        // AND THE MAP IS ALLOWED TO BE EMPTY, so this is what keeps the test
        // from being a vacuous pass - an iteration over nothing performs no
        // assertions at all, which under this suite's `failOnRisky` reds the
        // run for the wrong reason and under any other config passes silently.
        $this->assertTheOffenderWalkIsAlive();

        $root = \dirname(__DIR__);

        foreach (self::OUT_OF_SCOPE as $prefix => $reason) {
            $this->assertNotSame('', trim($reason), "{$prefix} is deferred without a reason");
            $this->assertFalse(
                self::inScope($prefix),
                "{$prefix} is listed as out of scope but SCOPE covers it - the two constants "
                    . 'disagree about the same directory.',
            );

            // Asserted rather than left to the iterator: a prefix naming a
            // directory that no longer exists is a rotted row, and a guard
            // that ERRORS on it (UnexpectedValueException out of
            // RecursiveDirectoryIterator) reports a broken test rather than a
            // stale claim, which is the wrong message to leave for the reader.
            $directory = $root . '/' . rtrim($prefix, '/');
            $this->assertDirectoryExists(
                $directory,
                "{$prefix} is recorded in OUT_OF_SCOPE but no such directory exists under tests/.",
            );

            $this->assertNotSame(
                [],
                self::unreapedForkOffendersUnder($directory, $root),
                "{$prefix} is recorded in OUT_OF_SCOPE as still holding unreaped in-process forks, "
                    . 'and it no longer does. Delete its row and add the prefix to SCOPE - a '
                    . 'deferral that has been overtaken is how a directory silently stops being '
                    . 'guarded.',
            );
        }
    }

    /**
     * {@see SCOPE} AND {@see OUT_OF_SCOPE} MUST BE JOINTLY TOTAL over the
     * offenders, or the deferral is a hole rather than a record.
     *
     * Without this, deleting a row from OUT_OF_SCOPE is a silent way to stop
     * guarding a directory: {@see testEveryOutOfScopeDirectoryStillHasAnUnreapedFork()}
     * iterates that map, so an EMPTY map passes it vacuously, and
     * {@see testEveryInProcessForkInScopeIsCoveredByTheReaper()} never looks
     * outside SCOPE. Between them the two tests would agree that a directory
     * nobody covers is fine.
     *
     * So this one starts from the FORKS rather than from either list: every
     * file anywhere under `tests/` that the predicate finds work in has to be
     * accounted for by one list or the other. Adding a directory to
     * OUT_OF_SCOPE is then a visible act with a reason attached, and deleting
     * the row without widening SCOPE fails here.
     *
     * NOTE THE REACH, because it is wider than {@see SCOPE} and it is easy to
     * describe this guard as though it were not. The walk is over ALL of
     * `tests/`, not the directories {@see SCOPE} names: an in-process fork
     * added ANYWHERE - including a directory no lane has ever listed - reds
     * this test until its directory is put in one of the two maps. Verified
     * by injecting an unreaped fork into a file under `tests/Renderer/`,
     * which is in neither list; this failed, naming that file. That is the
     * intended behaviour and it is also a standing obligation on every other
     * lane, so it is written down here rather than left to be rediscovered
     * from a red merge.
     *
     * A TEST AT THE ROOT OF `tests/` has no directory to add. The two maps
     * are matched with `str_starts_with()`, so the entry for such a file is
     * its own filename - which works, but reads oddly enough that the failure
     * message below says so explicitly rather than sending the reader looking
     * for a directory that does not exist.
     */
    public function testNoDirectoryWithUnreapedForksIsUnaccountedFor(): void
    {
        $this->assertTheOffenderWalkIsAlive();

        $root = \dirname(__DIR__);
        $unaccounted = [];
        $checked = 0;

        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root));
        foreach ($files as $file) {
            /** @var \SplFileInfo $file */
            if (!$file->isFile() || !str_ends_with($file->getFilename(), '.php')) {
                continue;
            }

            $relative = substr($file->getPathname(), \strlen($root) + 1);
            $source = (string) file_get_contents($file->getPathname());
            $sites = ForkedChildExitScanner::scan($source);
            if ($sites === []) {
                continue;
            }
            $checked++;

            $allowed = self::UNTRACKED_FORKS_ALLOWED[$relative]['count'] ?? 0;
            if (self::missingHalves($source, $sites, $allowed) === []) {
                continue;
            }

            $accounted = self::inScope($relative);
            foreach (array_keys(self::OUT_OF_SCOPE) as $prefix) {
                $accounted = $accounted || str_starts_with($relative, $prefix);
            }
            if (!$accounted) {
                $unaccounted[] = $relative;
            }
        }

        // The scanner has to have found something to reason about, or "nothing
        // is unaccounted for" is a statement about a dead instrument.
        $this->assertGreaterThan(0, $checked, 'the fork scanner found no files at all - it is dead');

        $this->assertSame(
            [],
            $unaccounted,
            'this file forks inside the PHPUnit process with no reaper, and is matched by no '
                . 'prefix in either SCOPE or OUT_OF_SCOPE. Either adopt the reaper and add its '
                . 'directory to SCOPE, or add that directory to OUT_OF_SCOPE with the reason it '
                . 'cannot be adopted yet. Both maps are matched with str_starts_with(), so for a '
                . 'test at the ROOT of tests/ - which has no directory - the entry is the '
                . 'filename itself. Leaving it in neither is the only outcome this guard refuses.',
        );
    }

    /**
     * An untracked-fork exemption cannot outlive the site it was written for.
     */
    public function testEveryUntrackedForkExemptionStillDescribesRealSites(): void
    {
        $root = \dirname(__DIR__);

        foreach (self::UNTRACKED_FORKS_ALLOWED as $file => $exemption) {
            $path = $root . '/' . $file;
            $this->assertFileExists($path, "{$file} is exempted but no longer exists");

            $untracked = 0;
            foreach (ForkedChildExitScanner::scan((string) file_get_contents($path)) as $site) {
                if ($site['spelling'] !== 'forkTracked'
                    && $site['shape'] !== ForkedChildExitScanner::SHAPE_FORK_WRAPPER) {
                    $untracked++;
                }
            }

            $this->assertSame(
                $exemption['count'],
                $untracked,
                "{$file} is exempted for {$exemption['count']} untracked fork(s) but has {$untracked}. "
                    . 'Re-argue the exemption or delete it - a count that no longer matches is a '
                    . 'licence nobody checked.',
            );
            $this->assertNotSame('', trim($exemption['reason']), "{$file} is exempted without a reason");
        }
    }
}
