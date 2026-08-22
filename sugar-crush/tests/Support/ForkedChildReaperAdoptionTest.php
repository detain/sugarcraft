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
 * are real and unreaped - `tests/Agents/AgentWorkerPoolTest.php`,
 * `tests/Agents/MailboxTest.php`, `tests/Backend/EngineBackendReapTest.php`,
 * `tests/Support/ForkedChildTest.php` - and each is recorded as such rather
 * than quietly left out. Widening {@see SCOPE} to `''` is the whole of the
 * work when somebody owns those files; it will fail loudly and name them.
 */
final class ForkedChildReaperAdoptionTest extends TestCase
{
    /** Path prefix, relative to `tests/`, the adoption is required under. */
    private const SCOPE = 'Integration/';

    /**
     * @return list<string> the halves a source is missing, empty when whole
     */
    private static function missingHalves(string $source): array
    {
        $missing = [];

        if (!str_contains($source, 'ReapsForkedChildrenTrait')) {
            $missing[] = 'use ReapsForkedChildrenTrait';
        }
        if (!str_contains($source, 'reapTrackedForkedChildren(')) {
            $missing[] = 'a reapTrackedForkedChildren() call in tearDown()';
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
        $this->assertSame(
            ['use ReapsForkedChildrenTrait', 'a reapTrackedForkedChildren() call in tearDown()'],
            self::missingHalves('<?php class F {}'),
        );

        $this->assertSame(
            ['a reapTrackedForkedChildren() call in tearDown()'],
            self::missingHalves('<?php class F { use ReapsForkedChildrenTrait; }'),
        );

        $this->assertSame(
            ['use ReapsForkedChildrenTrait'],
            self::missingHalves('<?php class F { function t() { $this->reapTrackedForkedChildren(); } }'),
        );

        $this->assertSame(
            [],
            self::missingHalves(
                '<?php class F { use ReapsForkedChildrenTrait; function t() { $this->reapTrackedForkedChildren(); } }',
            ),
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
            if (ForkedChildExitScanner::scan($source) === []) {
                continue;
            }

            $missing = self::missingHalves($source);
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
}
