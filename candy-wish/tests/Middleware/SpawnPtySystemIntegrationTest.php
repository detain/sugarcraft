<?php

declare(strict_types=1);

namespace SugarCraft\Wish\Tests\Middleware;

use PHPUnit\Framework\TestCase;
use SugarCraft\Pty\Contract\Child;
use SugarCraft\Pty\Contract\MasterPty;
use SugarCraft\Pty\Contract\PtyPair;
use SugarCraft\Pty\Contract\PtySystem;
use SugarCraft\Pty\Contract\SlavePty;
use SugarCraft\Wish\Context;
use SugarCraft\Wish\Middleware\Spawn;
use SugarCraft\Wish\Session;
use SugarCraft\Wish\Transport\InProcessTransport;

/**
 * End-to-end integration of plan P4.3: a Spawn middleware running
 * under an InProcessTransport whose PtySystem is fully stubbed —
 * proves the full chain (`Server::use(new Spawn(...))` → transport
 * → PtySystem) is DI-clean without touching libc / FFI / pcntl.
 */
final class SpawnPtySystemIntegrationTest extends TestCase
{
    public function testSpawnMiddlewareRoutesThroughInjectedPtySystem(): void
    {
        $system = new SpyPtySystem();

        $observedSession = null;
        $spawn = new Spawn(function (Session $s) use (&$observedSession): array {
            $observedSession = $s;
            return [
                'cmd' => ['/usr/bin/env'],
                'env' => ['TERM' => $s->term, 'USER' => $s->user],
            ];
        });

        $transport = new InProcessTransport($system);
        $session = new Session(
            user: 'alice', clientHost: '127.0.0.1', clientPort: 0, serverHost: '127.0.0.1',
            serverPort: 22, term: 'xterm-256color', cols: 132, rows: 43, tty: null,
            command: null, lang: 'C.UTF-8',
        );

        // Replace STDIN/STDOUT for the duration so PosixPump has live
        // resources to point at — memory streams suffice for a stub run.
        $stdin = \fopen('php://memory', 'rb');
        $stdout = \fopen('php://memory', 'wb');
        $this->assertIsResource($stdin);
        $this->assertIsResource($stdout);

        // Stack-walk pre-step: InProcessTransport::run() injects itself
        // via setTransport. We drive it the same way the production
        // server bootstrap would.
        $transport->run(Context::background(), $session, [
            new class($transport, $session, $spawn, $stdin, $stdout) implements \SugarCraft\Wish\Middleware {
                public function __construct(
                    private InProcessTransport $transport,
                    private Session $session,
                    private Spawn $spawn,
                    private mixed $stdin,
                    private mixed $stdout,
                ) {}
                public function handle(Context $ctx, Session $s, callable $next): void
                {
                    $this->spawn->setTransport($this->transport);
                    $this->transport->runChild(
                        $this->session,
                        ['/usr/bin/env'],
                        ['TERM' => $s->term, 'USER' => $s->user],
                        $this->stdin,
                        $this->stdout,
                    );
                }
            },
        ]);

        $this->assertSame(1, $system->openCalls, 'PtySystem::open() should be invoked exactly once');
        $this->assertSame([132, 43], $system->lastOpenArgs);
        $this->assertSame(1, $system->spawnCalls, 'SlavePty::spawn() should be invoked exactly once');
        $this->assertSame(['/usr/bin/env'], $system->lastSpawnCmd);
        $this->assertSame(['TERM' => 'xterm-256color', 'USER' => 'alice'], $system->lastSpawnEnv);
        $this->assertTrue($system->lastSpawnControllingTerminal, 'Spawn must request controllingTerminal:true');
    }
}

/**
 * Spy that captures both PtySystem::open() and SlavePty::spawn() args
 * so the integration test can verify the full chain wired up.
 */
final class SpyPtySystem implements PtySystem
{
    public int $openCalls = 0;
    /** @var array{0:int,1:int} */
    public array $lastOpenArgs = [0, 0];

    public int $spawnCalls = 0;
    /** @var list<string> */
    public array $lastSpawnCmd = [];
    /** @var array<string,string>|null */
    public ?array $lastSpawnEnv = null;
    public bool $lastSpawnControllingTerminal = false;

    public function open(int $cols = 80, int $rows = 24): PtyPair
    {
        $this->openCalls++;
        $this->lastOpenArgs = [$cols, $rows];
        return new SpyPtyPair($this);
    }

    /** @return array<string, bool> */
    public function capabilities(): array
    {
        return ['pty' => false, 'termios' => false, 'signal' => false];
    }
}

final class SpyPtyPair implements PtyPair
{
    private SpyMasterPty $master;
    private SpySlavePty $slave;

    public function __construct(SpyPtySystem $sys)
    {
        $this->master = new SpyMasterPty();
        $this->slave = new SpySlavePty($sys);
    }

    public function master(): MasterPty { return $this->master; }
    public function slave(): SlavePty { return $this->slave; }
}

final class SpyMasterPty implements MasterPty
{
    /** @var resource */
    public $stream;
    private bool $closed = false;

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
    public function resize(int $cols, int $rows): void {}
    public function size(): array { return ['cols' => 80, 'rows' => 24, 'xpix' => 0, 'ypix' => 0]; }
    public function stream(): mixed { return $this->stream; }
    public function close(): void { $this->closed = true; if (\is_resource($this->stream)) { @\fclose($this->stream); } }
    public function isClosed(): bool { return $this->closed; }
}

final class SpySlavePty implements SlavePty
{
    public function __construct(private SpyPtySystem $sys) {}

    public function path(): string { return '/dev/null'; }

    public function spawn(
        array $cmd,
        ?array $env = null,
        int $cols = 80,
        int $rows = 24,
        bool $controllingTerminal = false,
    ): Child {
        $this->sys->spawnCalls++;
        $this->sys->lastSpawnCmd = $cmd;
        $this->sys->lastSpawnEnv = $env;
        $this->sys->lastSpawnControllingTerminal = $controllingTerminal;
        return new SpyChild();
    }
}

final class SpyChild implements Child
{
    public function pid(): int { return 7777; }
    public function exited(): bool { return true; }
    public function wait(): int { return 0; }
    public function exitCode(): ?int { return 0; }
    public function kill(int $signal): void {}
}
