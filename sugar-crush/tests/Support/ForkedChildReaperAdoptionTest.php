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
     * The two halves an adopting file must carry, matched as CODE rather than
     * as text.
     *
     * MATCHED AT THE START OF A LINE, and that is the whole of what makes this
     * work. The first version looked for the bare string
     * `ReapsForkedChildrenTrait` anywhere in the source - and every adopting
     * file carries a doc-block above its `use` line saying
     * `{@see ReapsForkedChildrenTrait}`. Deleting the `use` line left the
     * mention behind and this guard stayed green over a file with no reaper at
     * all (measured: mutation M7 SURVIVED). A doc-block wraps at 80 columns
     * with ` * ` on every line, so anchoring on `^[ \t]*use` / `^[ \t]*$this->`
     * excludes prose by construction - a `*` is neither.
     *
     * @return list<string> the halves a source is missing, empty when whole
     */
    private static function missingHalves(string $source): array
    {
        $missing = [];

        if (\preg_match('/^[ \t]*use[ \t]+\\\\?(?:[A-Za-z_]\w*\\\\)*ReapsForkedChildrenTrait[ \t]*;/m', $source) !== 1) {
            $missing[] = 'use ReapsForkedChildrenTrait';
        }
        if (\preg_match('/^[ \t]*\$this->reapTrackedForkedChildren[ \t]*\(/m', $source) !== 1) {
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
        $both = ['use ReapsForkedChildrenTrait', 'a reapTrackedForkedChildren() call in tearDown()'];

        $this->assertSame($both, self::missingHalves("<?php\nclass F {}\n"));

        $this->assertSame(
            ['a reapTrackedForkedChildren() call in tearDown()'],
            self::missingHalves("<?php\nclass F {\n    use ReapsForkedChildrenTrait;\n}\n"),
        );

        $this->assertSame(
            ['use ReapsForkedChildrenTrait'],
            self::missingHalves("<?php\nclass F {\n    function t() {\n        \$this->reapTrackedForkedChildren();\n    }\n}\n"),
        );

        $this->assertSame(
            [],
            self::missingHalves(
                "<?php\nclass F {\n    use \\A\\B\\ReapsForkedChildrenTrait;\n"
                . "    function t() {\n        \$this->reapTrackedForkedChildren();\n    }\n}\n",
            ),
        );

        // THE MUTATION THAT GOT THROUGH, kept as a fixture. Both halves named
        // in PROSE and neither present as code: this is exactly the file that
        // results from deleting a `use` line while leaving the doc-block that
        // introduced it, and the loose version of this predicate called it
        // whole.
        $this->assertSame(
            $both,
            self::missingHalves(
                "<?php\n/**\n * Adopts {@see ReapsForkedChildrenTrait}: call\n"
                . " * \$this->reapTrackedForkedChildren() from tearDown().\n */\nclass F {}\n",
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
