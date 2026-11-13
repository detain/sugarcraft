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
 * A fence that is pure commentary (the commented panic-handler pair)
 * still gets linted, but its execution arm skips LOUDLY instead of
 * counting a guaranteed-green pass as coverage (candy-starter-009).
 *
 * The ceiling is coreutils `timeout -s KILL N` (Linux, where this
 * suite is gated) plus `</dev/null` — `-d max_execution_time` alone is
 * decorative for CLI SAPI blocking work, and an inherited STDIN lets a
 * snippet that reads input stall forever (lib-reviews-1102 int-php M2 /
 * architect M2 / senior M2 / qa M3: the old "ceiling" was empirically
 * vacuous on both counts). Both halves of that ceiling now carry their
 * own DISCRIMINATING pin (candy-starter-010): the timeout wrapper is
 * pinned by a sleeper that must die at the deadline, and the stdin
 * redirect is pinned by a drainer that must reach its exit 0 through
 * the immediate EOF.
 *
 * The three ```bash fences are the literal newcomer on-ramp; they are
 * structure-pinned against a command roster so renames and typos
 * redden without the suite ever needing network (candy-starter-021).
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

    /**
     * The shipped ```bash fences in README order: create-project quickstart,
     * test runner, optional sugar-log install (candy-starter-021). This is
     * a documented-invariant roster — moving or rewording a fence is a
     * deliberate act that must update this list in the same commit.
     */
    private const BASH_FENCE_ROSTER = [
        'composer create-project sugarcraft/candy-starter my-app --stability=dev',
        'vendor/bin/phpunit',
        'composer require sugarcraft/sugar-log',
    ];

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
        // Documented invariant, deliberately a FLOOR not an equality
        // (candy-starter-036): the template ships at least two php fences —
        // the Model anatomy class and the panic-handler pair — and the
        // extractor finding fewer means the README or the fence syntax
        // broke. A docs writer legitimately adding a third runnable fence
        // must not be greeted by four provider errors claiming the
        // extractor died; the guard iterates over however many exist.
        if (count($blocks) < 2) {
            self::fail(sprintf(
                'expected at least two fenced php blocks in README.md, found %d — the extractor broke or a taught example was lost',
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
        // candy-starter-009: the commented panic-handler fence is prose,
        // not a program — executing it can only ever pass, so the run arm
        // would book a guaranteed-green "coverage" row for a doc that
        // nothing runs. Its real signal is the lint arm above; here it
        // skips loudly instead of padding the matrix.
        if (!self::blockIsExecutable($source)) {
            $this->markTestSkipped(
                "the {$kind} snippet is commentary-only (every line is a // comment) — "
                . 'it is pinned by php -l, not by execution; uncomment it into real code and this arm runs',
            );
        }

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
        // Discriminating pin for the `timeout` half of the ceiling (the
        // pre-2026-11 `-d max_execution_time` story was measured inert on
        // this SAPI: `php -d max_execution_time=1 -r 'sleep(3);'` exits 0).
        // The sleeper never touches STDIN, so this test pins ONLY the
        // deadline — the redirect half has its own case below
        // (candy-starter-010). Remove the timeout wrapper and THIS test
        // hangs the suite — the honest shape of a guard whose ceiling
        // regrows fake.
        $script = $this->materialise('sleep(60);');

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

    public function testTheStdinRedirectFeedsTheChildAnImmediateEof(): void
    {
        // Discriminating pin for the `</dev/null` half of the ceiling
        // (candy-starter-010): the old stall script slept unconditionally,
        // so deleting the redirect left the suite green — the half of the
        // documented ceiling the stdin arm exists to police could not
        // fail. This child drains STDIN to EOF and prints how much it
        // ate; only an immediate EOF lets it reach its exit 0 inside the
        // deadline. Delete ` </dev/null` from runPhp and this inherits the
        // runner's blocking handle, the drainer never finishes, and the
        // deadline reddens it 124/137 — exactly the regression it exists
        // to catch. The drained:0 stdout assert additionally pins that the
        // marker the docblock promised was actually measured (the old
        // runPhp discarded stdout and never asserted it).
        $script = $this->materialise('printf("drained:%d\n", strlen((string) stream_get_contents(STDIN)));');

        [$status, $stderr, $stdout] = $this->runPhp([$script], 5);
        $this->assertSame(
            'drained:0',
            trim($stdout),
            'the child must see an immediate empty EOF on stdin (drained:0) and its stdout must be measured, not discarded: '
                . $stderr,
        );
        $this->assertSame(0, $status, 'a stdin-draining snippet must exit 0 through the </dev/null EOF, got ' . $status);
    }

    public function testTheDocumentedBashOnRampsMatchTheirRoster(): void
    {
        // candy-starter-021: three ```bash fences are the literal
        // newcomer on-ramp (create-project quickstart, test runner,
        // sugar-log install) and the php-only extractor pinned none of
        // them. Structural roster check, no network execution in the lib
        // suite — a typo or rename inside any of the commands reddens
        // this pin instead of stranding a copy-pasting newcomer.
        $readme = (string) file_get_contents(dirname(__DIR__) . '/README.md');

        $blocks   = [];
        $block    = [];
        $offset   = 0;
        while (preg_match('/^```bash[ \t]*\n(.*?)\n```/ms', $readme, $block, PREG_OFFSET_CAPTURE, $offset) === 1) {
            $blocks[] = $block[1][0];
            $offset   = $block[0][1] + strlen($block[0][0]);
        }
        if (count($blocks) < count(self::BASH_FENCE_ROSTER)) {
            self::fail(sprintf(
                'expected at least %d fenced bash blocks in README.md, found %d — the extractor broke or a taught command was lost',
                count(self::BASH_FENCE_ROSTER),
                count($blocks),
            ));
        }
        foreach (self::BASH_FENCE_ROSTER as $index => $command) {
            $firstLine = trim(explode("\n", $blocks[$index])[0]);
            $this->assertSame(
                $command,
                $firstLine,
                'bash fence ' . ($index + 1) . ' must carry the rostered command verbatim (update the roster in the same commit as a deliberate command change)',
            );
        }
    }

    /** A fence is executable when at least one non-blank line is not a // comment. */
    private static function blockIsExecutable(string $body): bool
    {
        foreach (explode("\n", $body) as $line) {
            $trimmed = ltrim($line);
            if ($trimmed === '' || str_starts_with($trimmed, '//')) {
                continue;
            }

            return true;
        }

        return false;
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
     * @return array{0:int, 1:string, 2:string} [exit status, captured stderr, captured stdout]
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

        return [$status, (string) file_get_contents($errorFile), implode("\n", $lines)];
    }
}
