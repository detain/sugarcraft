<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Support;

use PHPUnit\Framework\TestCase;

/**
 * A TEST THAT REDIRECTS ONE SPELLING OF `HOME` AND NOT THE OTHER IS WRITING
 * INTO THE DEVELOPER'S REAL HOME DIRECTORY, AND NOTHING SAID SO.
 *
 * {@see HomeSandboxTrait}'s doc-block has argued this since it was written --
 * "half a sandbox is not a sandbox", because `$_SERVER['HOME']` does not follow
 * a `putenv()` and `getenv('HOME')` does not follow an assignment to the
 * superglobal. It was an argument with no reader. E482 found a live
 * counter-example one directory away: `Integration/MultiAgentRefactorTest`
 * moved only the superglobal, and every reader it reached goes through
 * {@see \SugarCraft\Crush\Support\HomeDirectory}, which prefers `getenv()`.
 *
 * WHAT THAT COST, measured rather than feared. One run of that file created
 * exactly THREE directories under the real `~/.sugar-crush/teams/` -- one per
 * test that builds a Team. At the moment it was found that directory held
 * 3,133 entries with an mtime minutes old, which is roughly a thousand suite
 * runs of accumulated residue. Fixing the sandbox took the per-run delta from
 * 3 to 0.
 *
 * AND IT IS NOT ONLY LITTER, which is the part that makes this a harness
 * integrity guard rather than a tidiness one. `Agents/TeamTest` asserts in its
 * `tearDown()` that the real `~/.sugar-crush` is unchanged across each of its
 * tests. Three lanes run this suite concurrently against ONE home directory, so
 * a leak in any lane reds a test in another that did nothing wrong, at a moment
 * nobody can reproduce afterwards. That is a cross-lane flake with a mechanism.
 *
 * THE ROSTER BELOW IS A MIGRATION BACKLOG, NOT AN EXEMPTION LIST, and the
 * difference is that it is checked in BOTH directions. A new one-sided file
 * reds. A rostered file that gets fixed ALSO reds, so the row cannot outlive
 * the thing it describes and the list can only shrink. No row carries a reason,
 * deliberately: there is no good reason to sandbox half of `HOME`, and a column
 * for one would invite filling it in.
 */
final class OneSidedHomeSandboxTest extends TestCase
{
    /**
     * Test files that still redirect exactly one spelling of `HOME`.
     *
     * Paths are relative to `tests/`. To retire a row: move BOTH spellings in
     * that file -- or give it {@see HomeSandboxTrait} -- and delete the line.
     *
     * @var list<string>
     */
    private const NOT_YET_MIGRATED = [
        'App/AppBuilderTest.php',
        'Backend/EngineBackendParallelConfigTest.php',
        'Cli/SessionRetentionWiringTest.php',
        'Context/ImportResolverTest.php',
        'Context/InstructionFileLoaderTest.php',
        'Diagnostics/RuntimeNoticeSinkDeliveryTest.php',
        'Integration/MemoryPromptWiringTest.php',
        'Integration/WorkflowExecutionTest.php',
        'SessionTest.php',
    ];

    /**
     * THE SCANNER IS ALIVE, run before anything trusts it.
     *
     * The assertion this file rests on is an ABSENCE -- "no file outside the
     * roster is one-sided" -- and a scanner that matches nothing satisfies it
     * perfectly. So a known positive and a known negative go through the very
     * same {@see classify()} first.
     *
     * THE FIXTURES ARE BUILT BY CONCATENATION rather than spelled out, and
     * that is not decoration: this file is the one that DESCRIBES the pattern,
     * so a fixture written literally would put this guard into the population
     * it measures and the roster would have to carry its own name.
     */
    public function testTheScannerSeesBothPolarities(): void
    {
        $server = '$_SERVER[' . "'HOME'" . '] = $dir;';
        $env = 'putenv(' . "'HOME='" . ' . $dir);';

        $this->assertSame(
            ['server' => true, 'env' => false],
            self::classify('<?php ' . $server),
            'a file that moves only the superglobal was not seen as one-sided - which is '
                . 'exactly the shape that wrote 3 directories per run into a real home',
        );
        $this->assertSame(
            ['server' => false, 'env' => true],
            self::classify('<?php ' . $env),
            'a file that moves only the environment was not seen as one-sided',
        );
        $this->assertSame(
            ['server' => true, 'env' => true],
            self::classify('<?php ' . $server . ' ' . $env),
            'a file that moves BOTH spellings was reported as one-sided, which would put every '
                . 'correctly sandboxed test on the hook and be answered with roster rows',
        );
        $this->assertSame(
            ['server' => false, 'env' => false],
            self::classify('<?php $x = 1;'),
            'a file that does not touch HOME at all was reported as touching it',
        );
        $this->assertSame(
            ['server' => true, 'env' => false],
            self::classify('<?php unset($_SERVER[' . "'HOME'" . ']);'),
            'unsetting the superglobal is a write to it and must count as one',
        );
    }

    /**
     * THE ROSTER MATCHES THE TREE EXACTLY, in both directions.
     */
    public function testNoTestFileSandboxesOnlyHalfOfHome(): void
    {
        $oneSided = [];
        foreach (self::everyTestFile() as $relative => $path) {
            $source = (string) file_get_contents($path);
            if ($source === '') {
                $this->fail($relative . ' could not be read, so this census is void');
            }
            if (str_contains($source, 'HomeSandboxTrait')) {
                continue;
            }
            $found = self::classify($source);
            if ($found['server'] !== $found['env']) {
                $oneSided[] = $relative;
            }
        }
        sort($oneSided);

        // Rule 15's positive component. The roster is non-empty today, so a
        // scanner that has stopped matching produces an EMPTY list - which
        // would read as "everything has been migrated" and pass the diff below
        // in one direction while quietly failing to guard anything.
        $this->assertNotSame(
            [],
            $oneSided,
            'the census found no one-sided file anywhere. If every row below really has been '
                . 'migrated that is good news and the roster should be emptied in the same '
                . 'change - but check the scanner first, because a dead scanner reports exactly '
                . 'this',
        );

        $roster = self::NOT_YET_MIGRATED;
        sort($roster);

        $this->assertSame(
            $roster,
            $oneSided,
            'the set of test files redirecting exactly ONE spelling of HOME has changed. '
                . 'A file that appears here writes into the DEVELOPER\'S real home directory '
                . 'for every reader that consults the other spelling: $_SERVER[\'HOME\'] does '
                . 'not follow putenv(), and getenv(\'HOME\') does not follow an assignment to '
                . 'the superglobal. Measured once already - one such file created three '
                . 'directories per run under the real ~/.sugar-crush/teams/, and reds '
                . 'Agents/TeamTest in whichever OTHER lane happens to be mid-test. '
                . 'IF A FILE WAS ADDED: move both spellings, or use HomeSandboxTrait, rather '
                . 'than adding a row. IF A FILE DISAPPEARED: it was fixed - delete its row, '
                . 'because this list is a migration backlog and may only shrink.',
        );
    }

    /**
     * Does $source write to `$_SERVER['HOME']`, and does it call `putenv()`
     * with a HOME argument?
     *
     * @return array{server:bool, env:bool}
     */
    private static function classify(string $source): array
    {
        return [
            'server' => preg_match('/\$_SERVER\[[\'"]HOME[\'"]\]\s*=/', $source) === 1
                || preg_match('/unset\(\s*\$_SERVER\[[\'"]HOME[\'"]\]/', $source) === 1,
            'env' => preg_match('/putenv\(\s*[\'"]HOME/', $source) === 1
                || preg_match('/putenv\([^)]*HOME/', $source) === 1,
        ];
    }

    /**
     * Every `.php` file under `tests/`, keyed by its path relative to `tests/`.
     *
     * @return array<string,string>
     */
    private static function everyTestFile(): array
    {
        $root = \dirname(__DIR__);
        $found = [];

        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root)) as $file) {
            /** @var \SplFileInfo $file */
            if (!$file->isFile() || !str_ends_with($file->getFilename(), '.php')) {
                continue;
            }
            $found[substr($file->getPathname(), \strlen($root) + 1)] = $file->getPathname();
        }
        ksort($found);

        return $found;
    }
}
