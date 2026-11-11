<?php

declare(strict_types=1);

namespace App\Tests;

use PHPUnit\Framework\TestCase;

/**
 * README-compiles guard (docs-fix-1023 spring B1/B2 genre, minted here
 * for the template every downstream app copies).
 *
 * Every fenced ```php block in README.md is linted AND executed in a
 * child `php` process under a real wall-clock ceiling, asserting exit
 * code 0 and empty stderr. A template that documents a fatal (or a
 * hang) teaches the fatal; a doc that runs is a doc that stays honest.
 *
 * The ceiling is coreutils `timeout -s KILL N` (Linux, where this
 * suite is gated) plus `</dev/null` — `-d max_execution_time` alone is
 * decorative for CLI SAPI blocking work, and an inherited STDIN lets a
 * snippet that reads input stall forever (lib-reviews-1102 int-php M2 /
 * architect M2 / senior M2 / qa M3: the old "ceiling" was empirically
 * vacuous on both counts). The ceiling's own pin is
 * testTheWallClockCeilingActuallyKillsAStalledSnippet: neuter the
 * wrapper and the suite hangs on that test instead of passing silently.
 *
 * README blocks must be self-contained: each runs against the package
 * autoloader plus `declare(strict_types=1)` prepended, so
 * snippet-local imports are part of what this test pins.
 */
final class ReadmeSnippetsTest extends TestCase
{
    private const AUTOLOAD = __DIR__ . '/../vendor/autoload.php';

    /** Wall-clock ceiling per snippet, seconds (SIGKILL at the deadline). */
    private const RUN_TIMEOUT_SECONDS = 15;

    private string $scratchDir;

    protected function setUp(): void
    {
        $this->scratchDir = sys_get_temp_dir() . '/starter-readme-' . getmypid() . '-' . spl_object_id($this);
        if (!is_dir($this->scratchDir) && !mkdir($this->scratchDir, 0700) && !is_dir($this->scratchDir)) {
            $this->fail('could not create scratch dir ' . $this->scratchDir);
        }
    }

    protected function tearDown(): void
    {
        foreach (glob($this->scratchDir . '/' . chr(42)) ?: [] as $leftover) {
            unlink($leftover);
        }
        @rmdir($this->scratchDir);
    }

    /**
     * @return iterable<string, array{0:string, 1:string}> [label, php block body]
     */
    public static function scriptProvider(): iterable
    {
        $readme = (string) file_get_contents(dirname(__DIR__) . '/README.md');

        $blocks = [];
        $offset = 0;
        while (preg_match('/^```php[ \t]*\n(.*?)\n```/ms', $readme, $block, PREG_OFFSET_CAPTURE, $offset) === 1) {
            $blocks[] = $block[1][0];
            $offset   = $block[0][1] + strlen($block[0][0]);
        }
        // The template README carries exactly two php fences today (the
        // Model anatomy class and the commented panic-handler pair).
        // Fewer means the extractor or the README broke; both blocks
        // must stay runnable for the guard to mean anything.
        if (count($blocks) !== 2) {
            self::fail(sprintf(
                'expected exactly two fenced php blocks in README.md, found %d — the extractor broke',
                count($blocks),
            ));
        }
        foreach ($blocks as $index => $body) {
            yield 'README block ' . ($index + 1) => ['snippet', $body];
        }
    }

    /**
     * @dataProvider scriptProvider
     */
    public function testEveryDocumentedSnippetCompiles(string $kind, string $source): void
    {
        $script = $this->materialise($source);

        [$status, $stderr] = $this->runPhp(['-l', $script]);
        $this->assertSame(0, $status, "php -l failed for {$kind} snippet:\n" . $stderr);
    }

    /**
     * @dataProvider scriptProvider
     */
    public function testEveryDocumentedSnippetRunsToCompletion(string $kind, string $source): void
    {
        $script = $this->materialise($source);

        [$status, $stderr] = $this->runPhp([
            '-d', 'max_execution_time=' . self::RUN_TIMEOUT_SECONDS,
            '-d', 'display_errors=stderr',
            '-d', 'error_reporting=E_ALL',
            $script,
        ]);
        $this->assertSame(0, $status, "running the {$kind} snippet exited {$status}:\n" . $stderr);
        $this->assertSame('', trim($stderr), "the {$kind} snippet wrote to stderr:\n" . $stderr);
    }

    public function testTheWallClockCeilingActuallyKillsAStalledSnippet(): void
    {
        // Discriminating pin for the ceiling itself (the pre-2026-11
        // `-d max_execution_time` story was measured inert on this SAPI:
        // `php -d max_execution_time=1 -r 'sleep(3);'` exits 0). Remove
        // the timeout wrapper and THIS test hangs the suite — the honest
        // shape of a guard whose ceiling regrows fake.
        $script = $this->materialise('if (stream_get_contents(STDIN, 1) !== false) { echo "ate stdin\n"; } sleep(60);');

        [$status, $stderr] = $this->runPhp([$script], 2);
        // GNU timeout has two documented shapes for a ceiling that fired:
        // 137 when the wrapper propagates the SIGKILL death (measured on
        // coreutils 9.4, this dev box) and 124 when it reports the timeout
        // itself (measured 2026-11-10 on the ubuntu-26.04 CI runners, job
        // 130183809879). Both mean the stalled child died AT the deadline,
        // which is the behavior under test. Everything else is precisely
        // the drift this pin exists to catch: 0 = fake ceiling (the 60s
        // sleep ran out), 255 = startup fatal (never reached the stall),
        // 127 = the wrapper vanished from PATH. Dated disclosure of the
        // widened expectation: LANE-CW3, 2026-11-11.
        $this->assertContains(
            $status,
            [124, 137],
            'a stalled snippet must die at the SIGKILL deadline (124/137), not run to completion: '
                . $status . ' ' . $stderr,
        );
    }

    /** README bodies become child-process scripts. */
    private function materialise(string $source): string
    {
        $header = "<?php\ndeclare(strict_types=1);\nrequire " . var_export(realpath(self::AUTOLOAD), true) . ";\n";
        $path   = $this->scratchDir . '/snippet-' . substr(md5($source), 0, 12) . '.php';
        file_put_contents($path, $header . $source . "\n");

        return $path;
    }

    /**
     * @param list<string> $args
     * @return array{0:int, 1:string} [exit status, captured stderr]
     */
    private function runPhp(array $args, int $timeoutSeconds = self::RUN_TIMEOUT_SECONDS): array
    {
        $errorFile = $this->scratchDir . '/stderr-' . substr(md5(implode('|', $args)), 0, 12);
        // The ceiling is coreutils timeout itself: when it is missing the
        // shell reports 127 and every assertion reddens loudly — never a
        // silently unbounded run.
        $command = escapeshellarg('timeout') . ' -s KILL ' . $timeoutSeconds . ' ' . escapeshellarg(PHP_BINARY);
        foreach ($args as $arg) {
            $command .= ' ' . escapeshellarg($arg);
        }
        $command .= ' 2>' . escapeshellarg($errorFile) . ' </dev/null';

        $lines = [];
        exec($command, $lines, $status);

        return [$status, (string) file_get_contents($errorFile)];
    }
}
