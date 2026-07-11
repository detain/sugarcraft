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
 * Proves the transport preamble scrubs the plaintext `SSH_PASSWORD`
 * from a spawned child's environment REGARDLESS of middleware order —
 * i.e. even when a spawner runs before PasswordAuth (which would
 * otherwise never scrub it). Revert the scrub in
 * {@see InProcessTransport::runChild()} and both tests fail because the
 * password leaks to the child.
 */
final class InProcessTransportPasswordScrubTest extends TestCase
{
    /** @var array<string,mixed> */
    private array $serverBackup = [];

    protected function setUp(): void
    {
        $this->serverBackup = $_SERVER;
        unset($_SERVER['SSH_PASSWORD']);
        putenv('SSH_PASSWORD');
    }

    protected function tearDown(): void
    {
        $_SERVER = $this->serverBackup;
        putenv('SSH_PASSWORD');
    }

    private function session(): Session
    {
        return new Session(
            user: 'alice', clientHost: '127.0.0.1', clientPort: 0, serverHost: '127.0.0.1',
            serverPort: 22, term: 'xterm', cols: 80, rows: 24, tty: null,
            command: null, lang: 'C',
        );
    }

    public function testAmbientPasswordScrubbedBeforeSpawn(): void
    {
        // Simulate PasswordAuth NOT having run yet (a spawner precedes it):
        // the plaintext is still live in $_SERVER and the process env.
        $_SERVER['SSH_PASSWORD'] = 'hunter2';
        putenv('SSH_PASSWORD=hunter2');

        $stdin  = fopen('php://memory', 'rb');
        $stdout = fopen('php://memory', 'wb');
        $this->assertIsResource($stdin);
        $this->assertIsResource($stdout);

        // env=null → candy-pty's proc_open would inherit the parent env,
        // leaking SSH_PASSWORD into the child unless the preamble scrubs it.
        (new InProcessTransport(new ScrubRecordingPtySystem()))
            ->runChild($this->session(), ['/bin/true'], null, $stdin, $stdout);

        $this->assertArrayNotHasKey('SSH_PASSWORD', $_SERVER, 'SSH_PASSWORD must be gone from $_SERVER');
        $this->assertFalse(getenv('SSH_PASSWORD'), 'SSH_PASSWORD must be gone from the process env');
    }

    public function testExplicitEnvPasswordStrippedFromChild(): void
    {
        $system = new ScrubRecordingPtySystem();

        $stdin  = fopen('php://memory', 'rb');
        $stdout = fopen('php://memory', 'wb');
        $this->assertIsResource($stdin);
        $this->assertIsResource($stdout);

        (new InProcessTransport($system))->runChild(
            $this->session(),
            ['/bin/true'],
            ['SSH_PASSWORD' => 'hunter2', 'TERM' => 'xterm-256color'],
            $stdin,
            $stdout,
        );

        $slave = $system->lastSlave;
        $this->assertNotNull($slave);
        $this->assertIsArray($slave->capturedEnv);
        $this->assertArrayNotHasKey('SSH_PASSWORD', $slave->capturedEnv, 'child env must not carry SSH_PASSWORD');
        $this->assertSame('xterm-256color', $slave->capturedEnv['TERM'] ?? null, 'other env entries survive');
    }
}

final class ScrubRecordingPtySystem implements PtySystem
{
    public ?ScrubRecordingSlavePty $lastSlave = null;

    public function open(int $cols = 80, int $rows = 24): PtyPair
    {
        $pair = new ScrubRecordingPtyPair();
        $this->lastSlave = $pair->recordingSlave();
        return $pair;
    }

    /** @return array<string, bool> */
    public function capabilities(): array
    {
        return ['pty' => false, 'termios' => false, 'signal' => false];
    }
}

final class ScrubRecordingPtyPair implements PtyPair
{
    private ScrubRecordingMasterPty $master;
    private ScrubRecordingSlavePty $slave;

    public function __construct()
    {
        $this->master = new ScrubRecordingMasterPty();
        $this->slave  = new ScrubRecordingSlavePty();
    }

    public function master(): MasterPty { return $this->master; }
    public function slave(): SlavePty { return $this->slave; }
    public function recordingSlave(): ScrubRecordingSlavePty { return $this->slave; }
}

final class ScrubRecordingMasterPty implements MasterPty
{
    /** @var resource */
    public $stream;
    private bool $closed = false;

    public function __construct()
    {
        $stream = fopen('php://memory', 'r+b');
        if (!is_resource($stream)) {
            throw new \RuntimeException('memory stream open failed');
        }
        $this->stream = $stream;
    }

    public function fd(): int { return -1; }
    public function read(int $len = 8192, ?float $timeout = null): ?string { return ''; }
    public function write(string $bytes): int { return strlen($bytes); }
    public function resize(int $cols, int $rows): void {}
    public function size(): array { return ['cols' => 80, 'rows' => 24, 'xpix' => 0, 'ypix' => 0]; }
    public function stream(): mixed { return $this->stream; }
    public function close(): void { $this->closed = true; if (is_resource($this->stream)) { @fclose($this->stream); } }
    public function isClosed(): bool { return $this->closed; }
}

final class ScrubRecordingSlavePty implements SlavePty
{
    /** @var array<string,string>|null */
    public ?array $capturedEnv = null;
    public bool $spawnCalled = false;

    public function path(): string { return '/dev/null'; }

    public function spawn(
        array $cmd,
        ?array $env = null,
        int $cols = 80,
        int $rows = 24,
        bool $controllingTerminal = false,
    ): Child {
        $this->spawnCalled = true;
        $this->capturedEnv = $env;
        return new ScrubRecordingChild();
    }
}

final class ScrubRecordingChild implements Child
{
    public function pid(): int { return 4243; }
    public function exited(): bool { return true; }
    public function wait(): int { return 0; }
    public function exitCode(): ?int { return 0; }
    public function kill(int $signal): void {}
}
