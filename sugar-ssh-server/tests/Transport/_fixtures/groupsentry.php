<?php

declare(strict_types=1);

/**
 * Test fixture — SSHS-16 self-preservation sentry.
 *
 * The ladder's group sweep carries one absolute rule: never signal the
 * process group this process LEADS. That rule is unprovable inside a
 * normal PHPUnit process — the runner is not its own group leader, so
 * a missing guard merely ESRCHes and the consuming test still sees the
 * fallback arm. Here the process makes itself a session AND group
 * leader (posix_setsid), hands the transport a fake child reporting
 * its own pid (the corrupt/fake shape the guard exists for), and runs
 * the ladder to completion.
 *
 * Guard present: the sweep declines, the direct Child::kill() arm
 * fires, exit 0. Guard removed: kill(-getmypid()) lands on this
 * process's own group — SIGKILL, death by signal, no exit code — and
 * the consuming test's exit-0 assertion reddens. Either way the real
 * test runner stays safe: everything dangerous happens inside this
 * separate process.
 *
 * Exit contract: 0 survived-and-fell-back · 2 wrong signal record ·
 * 3 ext-posix unavailable (skip) · 4 pump did not settle the ladder.
 */

require __DIR__ . '/../../../vendor/autoload.php';

use SugarCraft\Pty\Contract\Child;
use SugarCraft\Pty\Contract\MasterPty;
use SugarCraft\Pty\Contract\PtyPair;
use SugarCraft\Pty\Contract\PtySystem;
use SugarCraft\Pty\Contract\SlavePty;
use SugarCraft\SshServer\Session;
use SugarCraft\SshServer\Transport\InProcessTransport;

if (!\function_exists('posix_setsid')) {
    exit(3);
}
if (\posix_setsid() === false) {
    exit(3);
}

$kills = new \stdClass();
$kills->signals = [];

$child = new class ($kills) implements Child {
    public function __construct(private \stdClass $kills) {}
    public function pid(): int
    {
        return \getmypid();
    }
    public function exited(): bool
    {
        return false;
    }
    public function wait(): int
    {
        return 9;
    }
    public function exitCode(): ?int
    {
        return 9;
    }
    public function kill(int $signal): void
    {
        $this->kills->signals[] = $signal;
    }
};

/**
 * Master with a held-open peer so reads idle on the pump's select tick
 * rather than EOF; a closed stdin peer settles the run through the
 * stdin-EOF grace, which is exactly the path into the ladder.
 */
$master = new class implements MasterPty {
    /** @var resource */
    public $stream;
    /** @var resource */
    public $peer;
    private bool $closed = false;

    public function __construct()
    {
        [$mine, $peer] = \stream_socket_pair(\STREAM_PF_UNIX, \STREAM_SOCK_STREAM, \STREAM_IPPROTO_IP);
        $this->stream = $mine;
        $this->peer = $peer;
    }
    public function fd(): int
    {
        return -1;
    }
    public function read(int $len = 8192, ?float $timeout = null): ?string
    {
        $bytes = @\fread($this->stream, $len);
        return $bytes === false ? null : $bytes;
    }
    public function write(string $bytes): int
    {
        return \strlen($bytes);
    }
    public function resize(int $cols, int $rows): void {}
    public function size(): array
    {
        return ['cols' => 80, 'rows' => 24, 'xpix' => 0, 'ypix' => 0];
    }
    public function stream(): mixed
    {
        return $this->stream;
    }
    public function close(): void
    {
        $this->closed = true;
        if (\is_resource($this->stream)) {
            \fclose($this->stream);
        }
        if (\is_resource($this->peer)) {
            \fclose($this->peer);
        }
    }
    public function isClosed(): bool
    {
        return $this->closed;
    }
};

$slave = new class ($child) implements SlavePty {
    public function __construct(private Child $child) {}
    public function path(): string
    {
        return '/dev/null';
    }
    public function spawn(array $cmd, ?array $env = null, ?int $cols = null, ?int $rows = null, bool $controllingTerminal = false): Child
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
            public function master(): MasterPty
            {
                return $this->master;
            }
            public function slave(): SlavePty
            {
                return $this->slave;
            }
        };
    }
    public function capabilities(): array
    {
        return ['pty' => false, 'termios' => false, 'signal' => false];
    }
};

$session = new Session(
    user: 'sentry', clientHost: '127.0.0.1', clientPort: 1, serverHost: '127.0.0.1',
    serverPort: 22, term: 'xterm', cols: 80, rows: 24, tty: null,
    command: null, lang: 'C.UTF-8',
);

// stdin with its peer already closed: the pump sees EOF at entry,
// burns its grace, and hands control to the teardown ladder.
[$stdin, $stdinPeer] = \stream_socket_pair(\STREAM_PF_UNIX, \STREAM_SOCK_STREAM, \STREAM_IPPROTO_IP);
\fclose($stdinPeer);
$stdout = \fopen('php://memory', 'w+b');

$status = (new InProcessTransport($system))->runChild($session, ['/bin/true'], null, $stdin, $stdout);
\fclose($stdin);
\fclose($stdout);

$expected = [\defined('SIGHUP') ? \SIGHUP : 1, \defined('SIGKILL') ? \SIGKILL : 9];
if ($status !== 9) {
    \fwrite(\STDERR, "status={$status}\n");
    exit(4);
}
if ($kills->signals !== $expected) {
    \fwrite(\STDERR, 'kills=' . \json_encode($kills->signals) . "\n");
    exit(2);
}
exit(0);
