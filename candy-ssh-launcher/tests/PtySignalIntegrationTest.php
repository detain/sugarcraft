<?php

declare(strict_types=1);

namespace SugarCraft\SshLauncher\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Real-PTY signal integration (sshl-40, finding -098): the legs the pipe
 * harness can never run. Ctrl-C is delivered THROUGH the pty line
 * discipline — the picker's raw-mode recipe deliberately keeps ISIG on, so
 * the typed 0x03 byte becomes SIGINT for the foreground process — and the
 * assertion is the full contract: exit 130, the cancel line on the tty, and
 * `stty -a` afterwards (run in the same pty, after the app died) showing
 * canonical mode and echo back. That third clause is the -001 tripwire: a
 * picker that dies mid-raw leaves the terminal the shell keeps using turned
 * off, which no pipe-driven leg can ever observe.
 *
 * The SIGTERM arm proves the restore-before-death contract of armCancelSignal:
 * the harness finds the picker's pid through /proc and kills it for real;
 * the run must surface status 143 (re-raised with SIG_DFL, so the
 * shell-visible status is 129+signo) with the terminal ALSO restored.
 *
 * Harness: util-linux `script -q` (dependency-free — sugar-pty is not in this
 * lib's require and stays out). Same spawn shape as BinCliTest::runUnderPty:
 * whitelisted env, non-blocking pipes, first-frame gate before keys, 20 s
 * deadline with terminate-and-fail — a wedged pty reddens the leg, it does
 * not park the shard.
 */
final class PtySignalIntegrationTest extends TestCase
{
    private const BIN = __DIR__ . '/../bin/candy-ssh-launcher';

    /** @var list<string> */
    private array $tempFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $file) {
            @unlink($file);
        }
        $this->tempFiles = [];
    }

    private function scratchConfig(string $contents): string
    {
        $path = tempnam(sys_get_temp_dir(), 'sshlaunch-pty-') . '.yml';
        $this->tempFiles[] = $path;
        file_put_contents($path, $contents);
        // Ambient umask is 0002 here — 0664 would face the trust gate.
        chmod($path, 0o644);

        return $path;
    }

    /**
     * Spawn the picker under script(1) with an inner shell chain that keeps
     * running AFTER the app exits: `$?` is captured, `stty -a` answers on the
     * same pty (the terminal-state witness), and the chain exits with the
     * app's code so the harness sees the picker's own status.
     *
     * @param callable(string,resource):void $drive called each poll tick with
     *                                             the accumulated output and
     *                                             the child's stdin pipe
     *
     * @return array{int,string} [exit code, merged pty output]
     */
    private function runUnderPty(callable $drive): array
    {
        $binary = realpath(self::BIN);
        $this->assertNotFalse($binary);
        // `trap '' INT TERM` shields the witness shell: while php is the
        // foreground child, the ^C group-signal would otherwise make the
        // non-interactive shell terminate itself with the deferred status
        // (130/143 — the rc alone LOOKED right) and the stty listing would
        // never run. Ignored dispositions are reset by php's own
        // pcntl_signal() handler arming, so the picker still sees both
        // signals for real. The sleep keeps script(1)'s copy loop alive
        // across the witness listing.
        $inner = 'stty rows 24 cols 80 2>/dev/null; trap \'\' INT TERM; '
            . $this->innerCommand($binary) . '; '
            . 'rc=$?; sleep 0.2; stty -a; exit $rc';
        $process = proc_open(
            'exec script -qec ' . escapeshellarg($inner) . ' /dev/null',
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            \dirname(__DIR__),
            [
                'PATH' => '/usr/bin:/bin',
                'HOME' => \sys_get_temp_dir(),
                'TERM' => 'xterm-256color',
                'LC_ALL' => 'C',
                'LANG' => 'C',
            ],
        );
        $this->assertIsResource($process, 'failed to spawn script(1) — is util-linux installed?');
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $deadline = microtime(true) + 20.0;
        $output = '';
        while (true) {
            $output .= (string) stream_get_contents($pipes[1]) . (string) stream_get_contents($pipes[2]);
            $status = proc_get_status($process);
            if (!$status['running']) {
                $exitCode = $status['exitcode'];
                break;
            }
            $drive($output, $pipes[0], $process);
            if (microtime(true) >= $deadline) {
                proc_terminate($process, 9);
                fclose($pipes[0]);
                fclose($pipes[1]);
                fclose($pipes[2]);
                proc_close($process);
                $this->fail('pty-driven picker run did not exit within the deadline: ' . $this->printable($output));
            }
            usleep(20_000);
        }
        $output .= (string) stream_get_contents($pipes[1]) . (string) stream_get_contents($pipes[2]);
        fclose($pipes[0]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);

        return [$exitCode, $output];
    }

    /** The app invocation inside the pty chain (config + harmless ssh stub). */
    private function innerCommand(string $binary): string
    {
        $config = $this->scratchConfig(implode("\n", [
            '- name: sigtest',
            '  host: s.test',
            '  port: 2222',
            '  user: ops',
        ]) . "\n");

        return implode(' ', array_map(
            'escapeshellarg',
            [PHP_BINARY, $binary, '--config', $config, '--ssh', '/bin/echo'],
        ));
    }

    private function printable(string $bytes): string
    {
        return addcslashes(substr($bytes, -600), "\0..\37\177");
    }

    /** stty -a prints an option on as `icanon` and off as `-icanon`. */
    private static function optionIsOn(string $output, string $option): bool
    {
        return preg_match('/(?<![-\w])' . preg_quote($option, '/') . '(?![\w-])/', $output) === 1;
    }

    public function testCtrlCThroughThePtyExits130AndHandsTheTerminalBack(): void
    {
        [$code, $output] = $this->runUnderPty(
            static function (string $seen, $stdin): void {
                static $sent = false;
                if (!$sent && str_contains($seen, "\x1b[1;1H")) {
                    // The picker has painted: it is in raw mode WITH ISIG, so
                    // this byte is the line discipline's SIGINT, not a key.
                    fwrite($stdin, "\x03");
                    $sent = true;
                }
            },
        );

        self::assertSame(130, $code, 'pty output: ' . $this->printable($output));
        self::assertStringContainsString('cancelled', $output, 'the cli.cancelled line rides the tty');
        self::assertTrue(
            self::optionIsOn($output, 'icanon'),
            'canonical mode was NOT restored — the -001 class: ' . $this->printable($output),
        );
        self::assertTrue(self::optionIsOn($output, 'echo'), 'echo was NOT restored');
    }

    public function testSigTermThroughProcRestoresTheTerminalAndDiesAt143(): void
    {
        $childrenFile = '';
        $drive = static function (string $seen, $stdin, $process) use (&$childrenFile): void {
            static $killed = false;
            if ($killed || !str_contains($seen, "\x1b[1;1H")) {
                return;
            }
            // Find the picker's pid two generations under script: the shell
            // that carries the chain is script's child, php is the shell's.
            $scriptPid = self::scriptPid($process);
            if ($scriptPid === 0) {
                return;
            }
            $phpPid = self::phpChildOf($scriptPid);
            if ($phpPid === 0) {
                return;
            }
            $killed = true;
            posix_kill($phpPid, SIGTERM);
        };

        if (!function_exists('posix_kill') || !is_readable('/proc/self/task/' . getmypid() . '/children')) {
            self::markTestSkipped('no /proc children visibility or ext-posix on this host (2026-11-13) — pty signal leg needs both');
        }

        [$code, $output] = $this->runUnderPty($drive);

        self::assertSame(143, $code, 'pty output: ' . $this->printable($output));
        self::assertTrue(
            self::optionIsOn($output, 'icanon'),
            'SIGTERM died without restoring the terminal: ' . $this->printable($output),
        );
        self::assertTrue(self::optionIsOn($output, 'echo'), 'echo was NOT restored after SIGTERM');
    }

    /** The OS pid of the script(1) process spawned by proc_open. */
    private static function scriptPid($process): int
    {
        $status = proc_get_status($process);

        return (int) $status['pid'];
    }

    /**
     * Walk /proc child lists from script down to the php process actually
     * holding the terminal. Returns 0 while nothing matches (still starting).
     */
    private static function phpChildOf(int $rootPid): int
    {
        $queue = [$rootPid];
        $seen = 0;
        while ($queue !== [] && $seen++ < 32) {
            $pid = (int) array_shift($queue);
            $children = @file_get_contents('/proc/' . $pid . '/task/' . $pid . '/children');
            if (!is_string($children)) {
                continue;
            }
            foreach (preg_split('/\s+/', trim($children)) ?: [] as $child) {
                if ($child === '') {
                    continue;
                }
                $cmdline = @file_get_contents('/proc/' . $child . '/cmdline');
                // argv[0] must BE the php binary: the chain's own
                // `sh -c '... php ...'` cmdline contains PHP_BINARY as text
                // inside the script string — matching it would SIGTERM the
                // (trap-shielded) shell and the picker would run forever.
                if (is_string($cmdline) && explode("\0", $cmdline)[0] === PHP_BINARY) {
                    return (int) $child;
                }
                $queue[] = (int) $child;
            }
        }

        return 0;
    }
}
