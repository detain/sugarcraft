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
 * Pins the P4.2 DI seam: InProcessTransport accepts an injected
 * {@see PtySystem} and routes its master/slave allocation through it
 * instead of the default {@see \SugarCraft\Pty\PtySystemFactory::default()}
 * lookup. Lets test code stub out the libc surface entirely.
 */
final class InProcessTransportInjectedSystemTest extends TestCase
{
    public function testInjectedPtySystemIsCalledWithSessionDimensions(): void
    {
        $system = new RecordingPtySystem();

        $session = new Session(
            user: 'a', clientHost: '127.0.0.1', clientPort: 0, serverHost: '127.0.0.1',
            serverPort: 22, term: 'xterm', cols: 132, rows: 43, tty: null,
            command: null, lang: 'C',
        );

        $stdin = \fopen('php://memory', 'rb');
        $stdout = \fopen('php://memory', 'wb');
        $this->assertIsResource($stdin);
        $this->assertIsResource($stdout);

        $exitCode = (new InProcessTransport($system))->runChild(
            $session,
            ['/bin/echo', 'stub'],
            null,
            $stdin,
            $stdout,
        );

        $this->assertSame(1, $system->openCalls, 'system.open() must be called exactly once per runChild');
        $this->assertSame([132, 43], $system->lastOpenArgs, 'open() must receive the Session cols/rows');
        $this->assertSame(0, $exitCode, 'stub child reports exit 0');
    }

    public function testZeroDimensionsFallBackTo80x24(): void
    {
        $system = new RecordingPtySystem();

        $session = new Session(
            user: 'a', clientHost: '127.0.0.1', clientPort: 0, serverHost: '127.0.0.1',
            serverPort: 22, term: 'xterm', cols: 0, rows: 0, tty: null,
            command: null, lang: 'C',
        );

        $stdin = \fopen('php://memory', 'rb');
        $stdout = \fopen('php://memory', 'wb');

        (new InProcessTransport($system))->runChild($session, ['/bin/true'], null, $stdin, $stdout);

        $this->assertSame([80, 24], $system->lastOpenArgs, 'Session(0,0) must fall back to 80x24');
    }

    public function testDefaultConstructorResolvesViaFactory(): void
    {
        // No-arg construction must not throw — PtySystemFactory::default()
        // returns PosixPtySystem on every POSIX host (Linux/Darwin/BSD/Solaris).
        if (\PHP_OS_FAMILY === 'Windows') {
            $this->markTestSkipped('Default resolution throws on Windows; covered separately in candy-pty.');
        }
        $transport = new InProcessTransport();
        $this->assertInstanceOf(InProcessTransport::class, $transport);
    }
}

/**
 * Stub system that records open() calls and hands out memory-only
 * master/slave stubs. No libc, no /dev/ptmx — runs anywhere.
 */
final class RecordingPtySystem implements PtySystem
{
    public int $openCalls = 0;
    /** @var array{0:int,1:int} */
    public array $lastOpenArgs = [0, 0];

    public function open(int $cols = 80, int $rows = 24): PtyPair
    {
        $this->openCalls++;
        $this->lastOpenArgs = [$cols, $rows];
        return new StubPtyPair();
    }

    /** @return array<string, bool> */
    public function capabilities(): array
    {
        return ['pty' => false, 'termios' => false, 'signal' => false];
    }
}

final class StubPtyPair implements PtyPair
{
    private StubMasterPty $master;
    private StubSlavePty $slave;

    public function __construct()
    {
        $this->master = new StubMasterPty();
        $this->slave = new StubSlavePty();
    }

    public function master(): MasterPty { return $this->master; }
    public function slave(): SlavePty { return $this->slave; }
}

final class StubMasterPty implements MasterPty
{
    /** @var resource */
    public $stream;
    private bool $closed = false;
    public int $resizeCalls = 0;

    public function __construct()
    {
        $stream = \fopen('php://memory', 'r+b');
        if (!\is_resource($stream)) {
            throw new \RuntimeException('memory stream open failed');
        }
        $this->stream = $stream;
    }

    public function fd(): int { return -1; }
    public function read(int $len = 8192, ?float $timeout = null): ?string { return ''; }
    public function write(string $bytes): int { return \strlen($bytes); }
    public function resize(int $cols, int $rows): void { $this->resizeCalls++; }
    public function size(): array { return ['cols' => 80, 'rows' => 24, 'xpix' => 0, 'ypix' => 0]; }
    public function stream(): mixed { return $this->stream; }
    public function close(): void { $this->closed = true; if (\is_resource($this->stream)) { @\fclose($this->stream); } }
    public function isClosed(): bool { return $this->closed; }
}

final class StubSlavePty implements SlavePty
{
    public function path(): string { return '/dev/null'; }

    public function spawn(
        array $cmd,
        ?array $env = null,
        int $cols = 80,
        int $rows = 24,
        bool $controllingTerminal = false,
    ): Child {
        return new StubChild();
    }
}

final class StubChild implements Child
{
    public function pid(): int { return 4242; }
    public function exited(): bool { return true; }
    public function wait(): int { return 0; }
    public function exitCode(): ?int { return 0; }
    public function kill(int $signal): void {}
}
