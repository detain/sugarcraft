<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Integration;

use PHPUnit\Framework\TestCase;

/**
 * The ONE launch warning in this project that provably cannot be migrated onto
 * the transcript seam, and — until round 42 (E78) — the one with nothing
 * standing behind it either way.
 *
 * `bin/sugarcrush` opens with an IIFE that hunts for `vendor/autoload.php`
 * across three candidates and, failing all three, writes
 * `sugarcrush: cannot find composer autoload.php` to stderr and exits 2. It is
 * structurally exempt from
 * {@see \SugarCraft\Crush\Cli\Bootstrap::warnPermissionConfigInTranscript()},
 * because the seam is a static method on a class the missing autoloader was
 * supposed to supply and no Chat exists on this path to hold a transcript row.
 *
 * WHAT THIS DOC-BLOCK USED TO SAY: "Every other stderr write in this codebase
 * was re-examined against `Bootstrap::warnPermissionConfigInTranscript()`".
 * WHAT IS TRUE NOW, and what round 42's review measured: that was false. Only
 * `Bootstrap`'s writes and this one had been looked at. The real census of raw
 * `fwrite(STDERR, …)` call sites across `src/` and `bin/` is ELEVEN:
 *
 *  - {@see \SugarCraft\Crush\Cli\NonInteractive}, six —
 *    `run()` twice (a thrown backend error, and an answer that would not encode
 *    as JSON), `failUsage()`, `failUnusableProvider()`,
 *    `noticeOfflineDefault()` and `readStdinIfPiped()`.
 *  - {@see \SugarCraft\Crush\Cli\Subcommands}, two — `sessionDelete()`'s "no
 *    such session" and `mcp()`'s inventory error.
 *  - {@see \SugarCraft\Crush\Cli\Bootstrap}, two —
 *    `warnPermissionConfig()`, which IS the stderr channel the seam delegates
 *    to and so cannot be a migration target, and `reportPrunedSessions()`'s
 *    per-session id rows, which stay raw on purpose.
 *  - `bin/sugarcrush`, one — this branch.
 *
 * WHY THE EXEMPTION CLAIM STILL EARNS ITS PLACE, narrowed to what was checked:
 * the eight in `NonInteractive` and `Subcommands` are all on the ONE-SHOT and
 * SUBCOMMAND paths, and {@see \SugarCraft\Crush\Cli\Bootstrap::launchNotices()}
 * is read by `Bootstrap::chat()` and `Bootstrap::app()` and by nothing else —
 * so on those paths the seam records into a static the process discards. That
 * is a reachability fact, not a judgement that the messages do not qualify, and
 * two of them do qualify under the rule: `noticeOfflineDefault()` (the session
 * cannot reach a real provider) and `readStdinIfPiped()` (10 MB of piped stdin
 * is silently truncated and the model is never told it is answering half a
 * question). Both are recorded in `docs/plans/crush_code_hardening_backlog.md`;
 * neither is in this item's scope, and neither is structurally exempt the way
 * THIS one is. What is unique here is that the seam's class cannot even be
 * loaded.
 *
 * "Structurally exempt" is a claim about behaviour, so it is asserted rather
 * than asserted-in-prose. What makes that cheap is that a checkout with no
 * `vendor/` is one `copy()` away, and {@see guardCandidates()} shows why:
 * the script resolves every candidate against its own `__DIR__`, so a copy
 * placed three directories deep inside a fresh temporary root puts ALL THREE
 * candidates inside that root, which was created empty a moment earlier.
 *
 * THE DEPTH IS THE POINT, and it is round 42's review that forced it. The first
 * version of this file copied the script to `<tmp>/bin/sugarcrush`, where the
 * third candidate (`__DIR__ . '/../../../autoload.php'`) resolves to
 * `/autoload.php` — but ONLY because `sys_get_temp_dir()` is `/tmp` on this
 * box. A host with a deeper TMPDIR (`/var/folders/xy/z`, or a CI runner
 * pointing it inside a project) sent that candidate somewhere unrelated, so the
 * file guarded itself with `markTestSkipped()` on `is_file('/autoload.php')` —
 * an environment-conditional skip that could take the suite's skip count from
 * 1 to 4. Nesting the copy makes the resolution self-contained, the skip is
 * gone, and {@see testTheGuardsThreeCandidatesAllLandInsideTheEmptyFixture()}
 * asserts the containment instead of assuming it.
 *
 * NOT A DUPLICATE OF {@see BinSugarcrushDispatchTest}: every case there runs
 * the real script out of the real checkout, where the autoloader is present by
 * construction and this branch is unreachable.
 */
final class BinSugarcrushAutoloadGuardTest extends TestCase
{
    private string $tmpDir = '';

    /**
     * Three levels below {@see $tmpDir}, so the script's `../../../` candidate
     * cannot escape the fixture. See the class doc-block.
     */
    private string $binDir = '';

    protected function setUp(): void
    {
        parent::setUp();

        $this->tmpDir = sys_get_temp_dir() . '/crush_autoload_guard_' . bin2hex(random_bytes(6));
        $this->binDir = $this->tmpDir . '/nest/deep/bin';
        mkdir($this->binDir, 0o755, true);
    }

    /**
     * Recursive, and unconditional. The first version cleaned the `vendor/`
     * fixture up at the FOOT of the test that created it, below the assertion —
     * so every failing run left a tree behind, and two were observed surviving
     * a mutation run. Cleanup belongs where a failure cannot skip it.
     */
    protected function tearDown(): void
    {
        $this->removeDirectory($this->tmpDir);

        parent::tearDown();
    }

    /**
     * The reachability argument, asserted rather than assumed.
     *
     * If a fourth candidate is ever added to the script, or an existing one is
     * respelled so it resolves outside the fixture, this reds here with the
     * path — instead of the two cases below quietly starting to test a launch
     * that found a real autoloader. Restating the list is safe in the direction
     * that matters: a candidate this method does not know about, which found
     * something, would also red `testACheckoutWithNoAutoloaderFailsOnStderr…`,
     * because the guard would not fire at all.
     */
    public function testTheGuardsThreeCandidatesAllLandInsideTheEmptyFixture(): void
    {
        $candidates = $this->guardCandidates();

        self::assertCount(3, $candidates);

        foreach ($candidates as $candidate) {
            self::assertStringStartsWith(
                $this->tmpDir . '/',
                $candidate,
                'a candidate resolving outside the fixture makes this file depend on what the host '
                . 'happens to have at that path — which is exactly how the first version depended on '
                . 'sys_get_temp_dir() being one directory below /',
            );
            self::assertFileDoesNotExist($candidate);
        }
    }

    public function testACheckoutWithNoAutoloaderFailsOnStderrWithExitTwo(): void
    {
        [$status, $stdout, $stderr] = $this->runCopiedBinary();

        self::assertSame(2, $status, "expected exit 2; stderr was:\n" . $stderr);
        self::assertSame("sugarcrush: cannot find composer autoload.php\n", $stderr);
        self::assertSame('', $stdout);
    }

    /**
     * STDOUT STAYS EMPTY, and that is the documented exception rather than an
     * oversight: this is the one exit that cannot honour the
     * `--output-format json` contract, because the class owning that document
     * shape ({@see \SugarCraft\Crush\Cli\NonInteractive}) is behind the
     * autoloader that is missing. Pinned so the exception stays a decision.
     */
    public function testTheGuardEmitsNothingOnStdoutEvenUnderOutputFormatJson(): void
    {
        [$status, $stdout] = $this->runCopiedBinary('--output-format json -p hi');

        self::assertSame(2, $status);
        self::assertSame('', $stdout);
    }

    /**
     * The guard must not be reachable from a checkout that DOES have a
     * `vendor/autoload.php` — otherwise the two tests above would pass against
     * a script whose candidate list had silently stopped working, and every
     * real install would print this line.
     */
    public function testTheGuardIsNotReachedWhenAnAutoloaderIsInPlace(): void
    {
        $vendor = $this->tmpDir . '/nest/deep/vendor';
        mkdir($vendor, 0o755, true);
        // Enough of an autoloader to satisfy `is_file()` and `require`; the
        // script's next statement is `use`, then ArgvParser, so it will fail
        // afterwards — the assertion is only that it failed SOMEWHERE ELSE.
        file_put_contents($vendor . '/autoload.php', "<?php\n");

        [, , $stderr] = $this->runCopiedBinary();

        // tearDown() removes the tree whether or not this holds.
        self::assertStringNotContainsString('cannot find composer autoload.php', $stderr);
    }

    /**
     * The three paths `bin/sugarcrush`'s IIFE will test, resolved the way the
     * script resolves them: relative spellings against `__DIR__ . '/../'`,
     * absolute ones as they stand.
     *
     * @return list<string>
     */
    private function guardCandidates(): array
    {
        $resolved = [];

        foreach ([
            'vendor/autoload.php',
            $this->binDir . '/../vendor/autoload.php',
            $this->binDir . '/../../../autoload.php',
        ] as $candidate) {
            $path = str_starts_with($candidate, '/') ? $candidate : $this->binDir . '/../' . $candidate;
            $resolved[] = self::collapse($path);
        }

        return $resolved;
    }

    /**
     * Textual `..` collapse — `realpath()` is no use here, because the whole
     * question is about paths that do not exist.
     */
    private static function collapse(string $path): string
    {
        $out = [];

        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..') {
                array_pop($out);
                continue;
            }
            $out[] = $segment;
        }

        return '/' . implode('/', $out);
    }

    /**
     * `--version` BY DEFAULT, and the default is load-bearing rather than
     * cosmetic. Every case here expects the IIFE to exit before argv is ever
     * parsed, so the argument is irrelevant when the test passes — but under a
     * mutation that makes the guard find an autoloader, an empty argv sends the
     * copied script into `Program::run()` and the alt screen, where it sits
     * until `timeout -s KILL 60` reaps it. Measured while pinning this file:
     * that turned one mutation run into a >2-minute hang. `--version` makes the
     * same mutation fail in milliseconds with the wrong exit code, which is the
     * answer the assertion wanted anyway.
     *
     * @return array{0: int, 1: string, 2: string} exit status, stdout, stderr
     */
    private function runCopiedBinary(string $arguments = '--version'): array
    {
        $binary = $this->binDir . '/sugarcrush';
        copy(dirname(__DIR__, 2) . '/bin/sugarcrush', $binary);

        $outFile = $this->tmpDir . '/out.txt';
        $errFile = $this->tmpDir . '/err.txt';

        exec(sprintf(
            'timeout -s KILL 60 %s %s %s >%s 2>%s',
            escapeshellarg(PHP_BINARY),
            escapeshellarg($binary),
            $arguments,
            escapeshellarg($outFile),
            escapeshellarg($errFile),
        ), $ignored, $status);

        $stdout = is_file($outFile) ? (string) file_get_contents($outFile) : '';
        $stderr = is_file($errFile) ? (string) file_get_contents($errFile) : '';

        return [$status, $stdout, $stderr];
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            is_dir($path) ? $this->removeDirectory($path) : unlink($path);
        }

        rmdir($dir);
    }
}
