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
 * Pins that InProcessTransport::signalChild() is a no-op once runChild()
 * has torn its child down, as {@see \SugarCraft\Wish\Transport\ChildSpawner}
 * promises. The tracked pid used to outlive the reap; the kernel recycles
 * pids, so a late SSH "signal" request was delivered to whatever unrelated
 * process inherited the number.
 *
 * The recycled pid is modelled directly: the injected child reports the
 * pid of a live, unrelated `/bin/sleep`, and has already exited by the
 * time the pump returns.
 */
final class InProcessTransportStalePidTest extends TestCase
{
    public function testSignalChildAfterRunChildDoesNotHitRecycledPid(): void
    {
        if (\PHP_OS_FAMILY === 'Windows' || !\is_executable('/bin/sleep')) {
            $this->markTestSkipped('POSIX /bin/sleep required.');
        }

        $bystander = \proc_open(
            ['/bin/sleep', '30'],
            [['file', '/dev/null', 'r'], ['file', '/dev/null', 'w'], ['file', '/dev/null', 'w']],
            $pipes,
        );
        $this->assertIsResource($bystander);
        $bystanderPid = \proc_get_status($bystander)['pid'];

        try {
            $transport = new InProcessTransport($this->systemWithExitedChild($bystanderPid));

            [$stdin, $stdinPeer] = \stream_socket_pair(\STREAM_PF_UNIX, \STREAM_SOCK_STREAM, \STREAM_IPPROTO_IP);
            \fclose($stdinPeer);
            $stdout = \fopen('php://memory', 'w+b');
            $status = $transport->runChild($this->session(), ['/bin/true'], null, $stdin, $stdout);
            $this->assertSame(0, $status);

            $transport->signalChild(\defined('SIGTERM') ? \SIGTERM : 15);

            $running = true;
            $deadline = \microtime(true) + 0.5;
            while (\microtime(true) < $deadline) {
                $running = \proc_get_status($bystander)['running'];
                if (!$running) {
                    break;
                }
                \usleep(20_000);
            }
            $this->assertTrue($running, 'signalChild() after teardown must not signal the stale (recycled) pid');
        } finally {
            if (\proc_get_status($bystander)['running']) {
                \proc_terminate($bystander, 9);
            }
            \proc_close($bystander);
        }
    }

    private function systemWithExitedChild(int $pid): PtySystem
    {
        $child = new class ($pid) implements Child {
            public function __construct(private int $pid) {}
            public function pid(): int { return $this->pid; }
            public function exited(): bool { return true; }
            public function wait(): int { return 0; }
            public function exitCode(): ?int { return 0; }
            public function kill(int $signal): void { throw new \LogicException('an exited child must not be killed'); }
        };
        [$masterEnd, $peer] = \stream_socket_pair(\STREAM_PF_UNIX, \STREAM_SOCK_STREAM, \STREAM_IPPROTO_IP);
        // Peer closed: the master reads EOF, so the pump settles at once.
        \fclose($peer);
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

        return new class ($master, $slave) implements PtySystem {
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
    }

    private function session(): Session
    {
        return new Session(
            user: 'alice', clientHost: '127.0.0.1', clientPort: 1, serverHost: '127.0.0.1',
            serverPort: 22, term: 'xterm', cols: 80, rows: 24, tty: null,
            command: null, lang: 'C.UTF-8',
        );
    }
}
