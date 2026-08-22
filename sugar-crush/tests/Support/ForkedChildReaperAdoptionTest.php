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
 * SCOPE, and it is deliberate rather than accidental. `tests/Integration/`
 * only. Round 46's lane split gave this lane `tests/Integration/` and
 * `tests/Support/`, and a guard cannot require an adoption in a directory the
 * change is not allowed to edit. The in-process fork sites OUTSIDE that scope
 * are real and unreaped, and rather than enumerate them here - a list in
 * prose rots, and this one already did: it was written from a census taken
 * with a scanner that could not see `\pcntl_fork()`, and so omitted
 * `tests/Agents/TaskListTest.php` entirely - widening {@see SCOPE} to `''`
 * derives the list instead. That is the whole of the work when somebody owns
 * those files; the guard will fail loudly and name every one of them.
 */
final class ForkedChildReaperAdoptionTest extends TestCase
{
    /** Path prefix, relative to `tests/`, the adoption is required under. */
    private const SCOPE = 'Integration/';

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
    }

    public function testEveryInProcessForkInScopeIsCoveredByTheReaper(): void
    {
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
            if (!str_starts_with($relative, self::SCOPE)) {
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
            'the scanner found no covered in-process forks under ' . self::SCOPE
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
