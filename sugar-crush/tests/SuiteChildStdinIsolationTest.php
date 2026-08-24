<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests;

use PHPUnit\Framework\TestCase;

/**
 * A child this suite spawns must not block on the RUNNER's descriptor 0.
 *
 * E212 closed this for the runner itself, with
 * {@see \SugarCraft\Crush\Cli\NonInteractive::pinStdinDefault()} in
 * `tests/bootstrap.php`, and that pin is deliberately in-process only: `src/`
 * and `bin/` read the real `\STDIN`, which is the production contract. But
 * `exec()` and `proc_open()` hand a child THIS process's descriptor 0, and
 * eighteen test files spawn a real `bin/sugarcrush`. So the same hazard was
 * still live one process down, and it is the WORSE version: the runner's
 * `-p` tests are in-process and pinned, while a spawned `-p` child reaches
 * `readStdinIfPiped()` with no pin at all and `stream_get_contents()` on an
 * open, never-written pipe returns when the writer closes and not before.
 *
 * WHAT IT LOOKS LIKE WHEN IT HAPPENS, which is why it went unattributed for a
 * round: `phpunit.xml` sets `defaultTimeLimit="60"`, so the test is reported
 * RISKY — "aborted after 60 seconds", "did not perform any assertions" — with
 * no mention of stdin, and the run merely gets slower. E242 recorded exactly
 * that shape ("crawling", "not CPU-bound", "wall-clock waits") and attributed
 * it to two suites sharing a temp sandbox. It reproduces with one process on
 * an idle box; what varies is the fd 0 the runner was started with, which is a
 * property of the harness rather than of the tree.
 *
 * The fix is in `tests/bootstrap.php`, which carries the measurements.
 */
final class SuiteChildStdinIsolationTest extends TestCase
{
    /** Seconds the CONTROL is allowed to hang before it is killed. */
    private const CONTROL_BOUND = 6;

    /** Seconds the bootstrapped run gets. Generous: it should take ~0.1s. */
    private const TREATMENT_BOUND = 30;

    /** @var list<string> */
    private array $paths = [];

    private string $home = '';

    protected function setUp(): void
    {
        parent::setUp();

        $this->home = sys_get_temp_dir() . '/sc_stdin_home_' . getmypid() . '_' . uniqid((string) getmypid(), true);
        mkdir($this->home, 0700, true);
    }

    protected function tearDown(): void
    {
        foreach ($this->paths as $path) {
            @unlink($path);
        }
        $this->paths = [];
        @rmdir($this->home);

        parent::tearDown();
    }

    /**
     * THE CONTROL FIRST, and it is not optional here (rule 15/E228). "The
     * child finished quickly" is also what a harness reports when it never
     * started the child, mis-spelled the binary, or handed it a stdin that was
     * already at EOF. So the same harness runs the same spawn from a script
     * that has NOT loaded `tests/bootstrap.php`, and that one must hang.
     *
     * Then the treatment: the identical script with the bootstrap required
     * first. Same runner stdin — an open pipe this process holds the write end
     * of and never writes to — and the spawned binary must answer anyway.
     */
    public function testTheBootstrapKeepsASpawnedBinaryOffTheRunnersHeldOpenStdin(): void
    {
        $control = $this->spawnWithHeldOpenStdin($this->script(false, self::CONTROL_BOUND));
        $this->assertSame(
            137,
            $control['rc'],
            "the control did not hang, so this harness cannot detect one.\nchild said: " . $control['raw'],
        );
        $this->assertGreaterThan(self::CONTROL_BOUND - 1.0, $control['elapsed']);

        $treatment = $this->spawnWithHeldOpenStdin($this->script(true, self::TREATMENT_BOUND));
        $this->assertSame(
            0,
            $treatment['rc'],
            "the spawned binary blocked on the runner's stdin.\nchild said: " . $treatment['raw'],
        );
        $this->assertLessThan(
            self::CONTROL_BOUND,
            $treatment['elapsed'],
            'the spawned binary answered, but only after a wait the control shows is the stdin block',
        );
    }

    /**
     * A script that spawns `bin/sugarcrush -p` and reports how that went.
     *
     * `$withBootstrap` is the only difference between control and treatment.
     * TMPDIR is passed explicitly on BOTH so the control does not sweep the
     * machine's real temp directory — the bootstrap is what normally sets it,
     * and the control is the run that does not have one.
     */
    private function script(bool $withBootstrap, int $bound): string
    {
        $root = \dirname(__DIR__);
        $require = $withBootstrap
            ? 'require ' . var_export($root . '/tests/bootstrap.php', true) . ';'
            : 'require ' . var_export($root . '/vendor/autoload.php', true) . ';';

        $command = sprintf(
            'cd %s && TMPDIR=%s HOME=%s timeout -s KILL %d %s %s -p %s >/dev/null 2>&1',
            escapeshellarg($this->home),
            escapeshellarg((string) (getenv('TMPDIR') ?: sys_get_temp_dir())),
            escapeshellarg($this->home),
            $bound,
            escapeshellarg(PHP_BINARY),
            escapeshellarg($root . '/bin/sugarcrush'),
            escapeshellarg('hi'),
        );

        return "<?php\ndeclare(strict_types=1);\n"
            . $require . "\n"
            . '$start = microtime(true);' . "\n"
            . '$out = [];' . "\n"
            . '$rc = 0;' . "\n"
            . 'exec(' . var_export($command, true) . ', $out, $rc);' . "\n"
            . 'printf("ELAPSED=%.3f RC=%d\n", microtime(true) - $start, $rc);' . "\n";
    }

    /**
     * Run one script with descriptor 0 wired to a pipe THIS process holds open
     * and never writes to — the shape a supervising harness produces.
     *
     * @return array{elapsed: float, rc: int, raw: string}
     */
    private function spawnWithHeldOpenStdin(string $script): array
    {
        $file = tempnam(sys_get_temp_dir(), 'sc_stdin_probe_' . getmypid() . '_');
        self::assertIsString($file);
        $this->paths[] = $file;
        file_put_contents($file, $script);

        $process = proc_open(
            [PHP_BINARY, $file],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        self::assertIsResource($process);

        // $pipes[0] is deliberately left open and unwritten for the whole run.
        $out = (string) stream_get_contents($pipes[1]);
        $err = (string) stream_get_contents($pipes[2]);

        fclose($pipes[0]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);

        self::assertSame(1, preg_match('/^ELAPSED=([\d.]+) RC=(-?\d+)$/m', $out, $m), "no report from the probe:\n" . $out . $err);

        return ['elapsed' => (float) $m[1], 'rc' => (int) $m[2], 'raw' => trim($out)];
    }
}
