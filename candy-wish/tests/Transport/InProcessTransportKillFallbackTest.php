<?php

declare(strict_types=1);

namespace SugarCraft\Wish\Tests\Transport;

use PHPUnit\Framework\TestCase;
use SugarCraft\Pty\Contract\Child;
use SugarCraft\Pty\Contract\MasterPty;
use SugarCraft\Pty\Contract\PtyPair;
use SugarCraft\Pty\Contract\PtySystem;
use SugarCraft\Pty\Contract\SlavePty;
use SugarCraft\Wish\Session;
use SugarCraft\Wish\Transport\InProcessTransport;

/**
 * Pins that InProcessTransport's child termination never depends on
 * ext-posix. candy-pty made ext-posix optional (Libc::kill() falls back
 * to libc kill(2) over FFI); candy-wish used to guard both its teardown
 * and signalChild() with function_exists('posix_kill'), so on a
 * posix-less host a SIGHUP-ignoring child held runChild() in wait() for
 * its whole life and every forwarded SSH signal was silently dropped.
 *
 * The posix-less cases run in a subprocess launched with
 * `-d disable_functions=posix_kill`, which makes function_exists()
 * report false exactly as a host without the extension would.
 */
final class InProcessTransportKillFallbackTest extends TestCase
{
    private const RUNCHILD_FIXTURE = __DIR__ . '/_fixtures/runchild.php';
    private const SIGNALCHILD_FIXTURE = __DIR__ . '/_fixtures/signalchild.php';

    private function requirePtySyscalls(): void
    {
        if (\PHP_OS_FAMILY === 'Windows') {
            $this->markTestSkipped('candy-pty is POSIX-only.');
        }
        if (!\extension_loaded('ffi')) {
            $this->markTestSkipped('ext-ffi is required.');
        }
        if (!\extension_loaded('pcntl')) {
            $this->markTestSkipped('ext-pcntl is required for controllingTerminal:true.');
        }
        if (!\is_readable('/dev/ptmx') || !\is_writable('/dev/ptmx')) {
            $this->markTestSkipped('/dev/ptmx unreadable on this host.');
        }
    }

    private function session(): Session
    {
        return new Session(
            user: 'alice', clientHost: '127.0.0.1', clientPort: 1, serverHost: '127.0.0.1',
            serverPort: 22, term: 'xterm', cols: 80, rows: 24, tty: null,
            command: null, lang: 'C.UTF-8',
        );
    }

    public function testTeardownTerminatesLingeringChildThroughChildKill(): void
    {
        // A child still running after the pump returns must be stopped via
        // the Contract\Child it came from — an injected PtySystem's child
        // included — SIGHUP first, SIGKILL once the grace lapses.
        $child = new class implements Child {
            /** @var list<int> */
            public array $signals = [];
            // A pid no kernel hands out (above every pid_max), so a stray
            // raw kill() aimed at it can only fail with ESRCH.
            public function pid(): int { return 2147483646; }
            public function exited(): bool { return \in_array(9, $this->signals, true); }
            public function wait(): int { return 137; }
            public function exitCode(): ?int { return $this->exited() ? 137 : null; }
            public function kill(int $signal): void { $this->signals[] = $signal; }
        };

        [$masterEnd, $peer] = \stream_socket_pair(\STREAM_PF_UNIX, \STREAM_SOCK_STREAM, \STREAM_IPPROTO_IP);
        $master = new class ($masterEnd) implements MasterPty {
            private bool $closed = false;
            /** @param resource $stream */
            public function __construct(private $stream) {}
            public function fd(): int { return -1; }
            public function read(int $len = 8192, ?float $timeout = null): ?string { $b = @\fread($this->stream, $len); return $b === false ? null : $b; }
            public function write(string $bytes): int { $n = @\fwrite($this->stream, $bytes); return $n === false ? 0 : $n; }
            public function resize(int $cols, int $rows): void {}
            public function size(): array { return ['cols' => 80, 'rows' => 24, 'xpix' => 0, 'ypix' => 0]; }
            public function stream(): mixed { return $this->stream; }
            public function close(): void { $this->closed = true; if (\is_resource($this->stream)) { \fclose($this->stream); } }
            public function isClosed(): bool { return $this->closed; }
        };
        $slave = new class ($child) implements SlavePty {
            public function __construct(private Child $child) {}
            public function path(): string { return '/dev/null'; }
            public function spawn(array $cmd, ?array $env = null, int $cols = 80, int $rows = 24, bool $controllingTerminal = false): Child
            {
                return $this->child;
            }
        };
        $system = new class ($master, $slave) implements PtySystem {
            public function __construct(private MasterPty $master, private SlavePty $slave) {}
            public function open(int $cols = 80, int $rows = 24): PtyPair
            {
                return new class ($this->master, $this->slave) implements PtyPair {
                    public function __construct(private MasterPty $master, private SlavePty $slave) {}
                    public function master(): MasterPty { return $this->master; }
                    public function slave(): SlavePty { return $this->slave; }
                };
            }
            public function capabilities(): array { return ['pty' => false, 'termios' => false, 'signal' => false]; }
        };

        // stdin at EOF from the start: the pump ends after its post-EOF
        // grace while the child still reports running.
        [$stdin, $stdinPeer] = \stream_socket_pair(\STREAM_PF_UNIX, \STREAM_SOCK_STREAM, \STREAM_IPPROTO_IP);
        \fclose($stdinPeer);
        $stdout = \fopen('php://memory', 'w+b');

        try {
            $status = (new InProcessTransport($system))->runChild($this->session(), ['/bin/true'], null, $stdin, $stdout);
        } finally {
            if (\is_resource($peer)) {
                \fclose($peer);
            }
        }

        $hup = \defined('SIGHUP') ? \SIGHUP : 1;
        $this->assertSame([$hup, 9], $child->signals, 'teardown must go through Child::kill(): SIGHUP, then SIGKILL');
        $this->assertTrue($master->isClosed(), 'master must be closed after teardown');
        $this->assertSame(137, $status);
    }

    public function testTeardownKillsSighupIgnoringChildWithoutExtPosix(): void
    {
        $this->requirePtySyscalls();
        if (!\is_executable('/bin/sh') || !\is_executable('/bin/sleep')) {
            $this->markTestSkipped('/bin/sh and /bin/sleep required.');
        }

        // The child ignores SIGHUP (the disposition survives exec), so only
        // the SIGKILL leg of the teardown can end it before its 10s sleep.
        $argv = [
            \PHP_BINARY, '-d', 'disable_functions=posix_kill', self::RUNCHILD_FIXTURE, '80', '24',
            '/bin/sh', '-c', 'trap "" HUP; exec /bin/sleep 10',
        ];
        $proc = \proc_open($argv, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $this->assertIsResource($proc);
        [$stdin, $stdout, $stderr] = $pipes;
        \stream_set_blocking($stdout, false);
        \stream_set_blocking($stderr, false);

        $start = \microtime(true);
        \fclose($stdin); // client disconnect

        $errBytes = '';
        $status = \proc_get_status($proc);
        $deadline = $start + 5.0;
        while (\microtime(true) < $deadline) {
            $status = \proc_get_status($proc);
            if ($status['running'] === false) {
                break;
            }
            @\fread($stdout, 4096);
            $errBytes .= (string) @\fread($stderr, 4096);
            \usleep(50_000);
        }
        $elapsed = \microtime(true) - $start;
        if ($status['running']) {
            \proc_terminate($proc, 9);
        }
        $errBytes .= (string) @\stream_get_contents($stderr);
        \fclose($stdout);
        \fclose($stderr);
        \proc_close($proc);

        $this->assertFalse($status['running'], "supervisor must not wait out a SIGHUP-ignoring child; stderr={$errBytes}");
        $this->assertLessThan(5.0, $elapsed, "teardown must SIGKILL the child without ext-posix; took {$elapsed}s");
        $this->assertSame('', $errBytes, 'fixture must not error');
    }

    public function testSignalChildDeliversWithoutExtPosix(): void
    {
        $this->requirePtySyscalls();
        if (!\is_executable('/bin/sleep')) {
            $this->markTestSkipped('/bin/sleep required.');
        }

        $cmd = \implode(' ', \array_map('escapeshellarg', [
            \PHP_BINARY, '-d', 'disable_functions=posix_kill', self::SIGNALCHILD_FIXTURE,
        ]));
        $out = [];
        \exec($cmd . ' 2>&1', $out, $rc);

        $this->assertSame(0, $rc, \implode("\n", $out));
        $this->assertSame(['terminated'], $out, 'signalChild() must deliver the signal when ext-posix is absent');
    }
}
