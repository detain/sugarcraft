<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Diagnostics;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Diagnostics\RuntimeNoticeSink;

/**
 * Audit C2a's second guard: while the sink is armed WITH the cross-fork
 * transport — the interactive launch's shape — and `error_log` still resolves
 * to stderr, {@see RuntimeNoticeSink::warn()} must put nothing on fd 2, which
 * in that process is the tty the renderer owns.
 *
 * IN A CHILD PROCESS, because the claim is about the process's REAL fd 2 and
 * PHPUnit's own stderr is not something an in-process test can read. Each
 * child arms the sink the way it is told, calls warn() once, and prints what
 * the transcript drain returned on stdout — so a silent stderr cannot be
 * mistaken for a warn() that never ran.
 */
final class RuntimeNoticeSinkStderrTest extends TestCase
{
    private const PROBE = 'C2A-SINK-STDERR-PROBE';

    private const STDERR_PROBE_DEADLINE_SECONDS = 20;

    private string $scratch;

    protected function setUp(): void
    {
        RuntimeNoticeSink::reset();
        $this->scratch = sys_get_temp_dir() . '/sc_sink_stderr_' . getmypid() . '_' . bin2hex(random_bytes(6));
        mkdir($this->scratch, 0o700, true);
    }

    protected function tearDown(): void
    {
        RuntimeNoticeSink::reset();
        foreach (glob($this->scratch . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->scratch);
    }

    #[DataProvider('stderrDestinations')]
    public function testAnArmedTransportSinkWritesNothingToStderr(string $destination): void
    {
        $run = $this->runChild('transport', $destination);

        self::assertSame(0, $run['status'], 'child failed: ' . $run['stderr'] . $run['stdout']);
        self::assertSame('', $run['stderr'], 'warn() painted its stderr copy over the TUI frame');
        self::assertSame([self::PROBE], json_decode($run['stdout'], true), 'the transcript copy was lost too');
    }

    /** @return array<string, array{string}> */
    public static function stderrDestinations(): array
    {
        return [
            'unset' => [''],
            '/dev/stderr' => ['/dev/stderr'],
        ];
    }

    /** The `-p` / subcommand shape: no reader, so stderr is the only record. */
    public function testAnUnarmedSinkStillWritesItsStderrCopy(): void
    {
        $run = $this->runChild('unarmed', '');

        self::assertSame(0, $run['status'], 'child failed: ' . $run['stderr'] . $run['stdout']);
        self::assertStringContainsString(self::PROBE, $run['stderr']);
        self::assertSame([], json_decode($run['stdout'], true));
    }

    /** The in-process backend has no fork and is not the TUI's shape. */
    public function testTheInProcessBackendStillWritesItsStderrCopy(): void
    {
        $run = $this->runChild('in-process', '');

        self::assertSame(0, $run['status'], 'child failed: ' . $run['stderr'] . $run['stdout']);
        self::assertStringContainsString(self::PROBE, $run['stderr']);
        self::assertSame([self::PROBE], json_decode($run['stdout'], true));
    }

    /**
     * A non-stderr destination — the TUI's own log file once
     * {@see \SugarCraft\Crush\Diagnostics\TuiErrorLog} is installed — keeps the
     * complete record even with the transport armed. In-process: no fd 2 is
     * involved.
     */
    public function testAnArmedTransportSinkStillWritesToAFileDestination(): void
    {
        self::assertTrue(RuntimeNoticeSink::arm(), 'this host could not create the transport');

        $log = $this->scratch . '/error.log';
        $previous = ini_set('error_log', $log);

        try {
            RuntimeNoticeSink::warn(self::PROBE);
        } finally {
            ini_set('error_log', $previous === false ? '' : $previous);
        }

        self::assertStringContainsString(self::PROBE, (string) file_get_contents($log));
        self::assertSame([self::PROBE], RuntimeNoticeSink::drain());
    }

    /**
     * @return array{status: int, stdout: string, stderr: string}
     */
    private function runChild(string $mode, string $destination): array
    {
        $script = $this->scratch . '/child.php';
        file_put_contents($script, <<<'PHP'
            <?php
            declare(strict_types=1);
            require $argv[1];
            use SugarCraft\Crush\Diagnostics\RuntimeNoticeSink;
            if ($argv[2] === 'transport' && !RuntimeNoticeSink::arm()) {
                fwrite(STDOUT, 'no transport on this host');
                exit(3);
            }
            if ($argv[2] === 'in-process') {
                RuntimeNoticeSink::arm(false);
            }
            RuntimeNoticeSink::warn($argv[3]);
            fwrite(STDOUT, json_encode(RuntimeNoticeSink::drain()));
            PHP);

        $command = [
            \PHP_BINARY,
            '-d', 'error_log=' . $destination,
            '-d', 'log_errors=1',
            '-d', 'display_errors=0',
            $script,
            \dirname(__DIR__, 2) . '/vendor/autoload.php',
            $mode,
            self::PROBE,
        ];

        $process = proc_open($command, [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        self::assertIsResource($process, 'failed to spawn the child');

        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $stdout = '';
        $stderr = '';
        $exitCode = null;
        $deadline = microtime(true) + self::STDERR_PROBE_DEADLINE_SECONDS;

        while (true) {
            $stdout .= (string) stream_get_contents($pipes[1]);
            $stderr .= (string) stream_get_contents($pipes[2]);

            $status = proc_get_status($process);
            if ($status['running'] === false) {
                $exitCode = (int) $status['exitcode'];
                break;
            }

            if (microtime(true) > $deadline) {
                proc_terminate($process, 9);
                break;
            }

            usleep(10000);
        }

        $stdout .= (string) stream_get_contents($pipes[1]);
        $stderr .= (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);

        if ($exitCode === null) {
            self::fail('the child did not exit within ' . self::STDERR_PROBE_DEADLINE_SECONDS . 's');
        }

        return ['status' => $exitCode, 'stdout' => $stdout, 'stderr' => $stderr];
    }
}
