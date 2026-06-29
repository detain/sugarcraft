<?php

declare(strict_types=1);

namespace SugarCraft\Wish\Tests\Middleware;

use PHPUnit\Framework\TestCase;
use SugarCraft\Wish\Context;
use SugarCraft\Wish\Middleware\Spawn;
use SugarCraft\Wish\Server;
use SugarCraft\Wish\Session;
use SugarCraft\Wish\Transport\ChildSpawner;
use SugarCraft\Wish\Transport\InProcessTransport;
use SugarCraft\Wish\Transport\HostSshdTransport;

final class SpawnTest extends TestCase
{
    private function fakeSession(): Session
    {
        return new Session(
            user: 'alice', clientHost: '127.0.0.1', clientPort: 1, serverHost: '127.0.0.1',
            serverPort: 22, term: 'xterm', cols: 80, rows: 24, tty: '/dev/pts/0',
            command: null, lang: 'C.UTF-8',
        );
    }

    public function testFactoryReceivesSessionAndReturnedCmdReachesSpawner(): void
    {
        $observedSession = null;
        $observedCmd = null;
        $observedEnv = null;

        $spawner = new class($observedCmd, $observedEnv) implements ChildSpawner {
            public function __construct(
                private ?array &$cmd,
                private ?array &$env,
            ) {}
            public function runChild(Session $session, array $cmd, ?array $env = null): int
            {
                $this->cmd = $cmd;
                $this->env = $env;
                return 0;
            }

            public function signalChild(int $signal): void {}
        };

        $spawn = new Spawn(function (Session $s) use (&$observedSession): array {
            $observedSession = $s;
            return [
                'cmd' => ['/bin/bash', '-l'],
                'env' => ['TERM' => $s->term, 'USER' => $s->user],
            ];
        });
        $spawn->setTransport($spawner);

        $session = $this->fakeSession();
        $spawn->handle(Context::background(), $session, fn() => null);

        $this->assertSame($session, $observedSession, 'factory must receive the active Session');
        $this->assertSame(['/bin/bash', '-l'], $observedCmd);
        $this->assertSame(['TERM' => 'xterm', 'USER' => 'alice'], $observedEnv);
    }

    public function testFactoryWithNoEnvDefaultsToNull(): void
    {
        $observedEnv = 'unset';

        $spawner = new class($observedEnv) implements ChildSpawner {
            public function __construct(private mixed &$env) {}
            public function runChild(Session $s, array $cmd, ?array $env = null): int
            {
                $this->env = $env;
                return 0;
            }

            public function signalChild(int $signal): void {}
        };

        $spawn = new Spawn(fn () => ['cmd' => ['/bin/true']]);
        $spawn->setTransport($spawner);
        $spawn->handle(Context::background(), $this->fakeSession(), fn() => null);

        $this->assertNull($observedEnv);
    }

    public function testThrowsWhenNoTransportInjected(): void
    {
        $spawn = new Spawn(fn () => ['cmd' => ['/bin/true']]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/InProcessTransport/');
        $spawn->handle(Context::background(), $this->fakeSession(), fn() => null);
    }

    public function testThrowsOnNonArrayFactoryReturn(): void
    {
        $spawn = new Spawn(fn () => 'not-an-array');
        $spawn->setTransport($this->nullSpawner());

        $this->expectException(\InvalidArgumentException::class);
        $spawn->handle(Context::background(), $this->fakeSession(), fn() => null);
    }

    public function testThrowsOnMissingCmdKey(): void
    {
        $spawn = new Spawn(fn () => ['env' => []]);
        $spawn->setTransport($this->nullSpawner());

        $this->expectException(\InvalidArgumentException::class);
        $spawn->handle(Context::background(), $this->fakeSession(), fn() => null);
    }

    public function testThrowsOnEmptyCmd(): void
    {
        $spawn = new Spawn(fn () => ['cmd' => []]);
        $spawn->setTransport($this->nullSpawner());

        $this->expectException(\InvalidArgumentException::class);
        $spawn->handle(Context::background(), $this->fakeSession(), fn() => null);
    }

    public function testDoesNotInvokeNext(): void
    {
        $nextCalled = false;

        $spawn = new Spawn(fn () => ['cmd' => ['/bin/true']]);
        $spawn->setTransport($this->nullSpawner());
        $spawn->handle(
            Context::background(),
            $this->fakeSession(),
            function () use (&$nextCalled): void {
                $nextCalled = true;
            },
        );

        $this->assertFalse($nextCalled, 'Spawn is terminal — must NOT call $next');
    }

    public function testInProcessTransportInjectsItselfAtStackWalkTime(): void
    {
        // Verify injection by observing that Spawn becomes able to
        // call handle() without throwing a "no transport" error AFTER
        // the transport's run() walks the stack. The duck-typed
        // setTransport hook is what InProcessTransport invokes.
        //
        // We use a setTransport-aware probe middleware (NOT extending
        // the final Spawn class) to capture the injected instance.
        $observed = null;
        $probe = new class($observed) implements \SugarCraft\Wish\Middleware {
            public function __construct(private mixed &$captured) {}
            public function setTransport(ChildSpawner $t): void
            {
                $this->captured = $t;
            }
            public function handle(Context $ctx, Session $s, callable $next): void
            {
                // Don't invoke $next — keep the stack from walking past
                // the injection check.
            }
        };

        $transport = new InProcessTransport();
        $transport->run(Context::background(), $this->fakeSession(), [$probe]);

        $this->assertSame($transport, $observed, 'InProcessTransport must inject itself into setTransport-aware middleware');
    }

    public function testHostSshdTransportDoesNotInjectSpawnFailsAtHandle(): void
    {
        $session = $this->fakeSession();
        $spawn = new Spawn(fn () => ['cmd' => ['/bin/true']]);

        $this->expectException(\RuntimeException::class);
        (new HostSshdTransport())->run(Context::background(), $session, [$spawn]);
    }

    private function nullSpawner(): ChildSpawner
    {
        return new class implements ChildSpawner {
            public function runChild(Session $s, array $cmd, ?array $env = null): int
            {
                return 0;
            }

            public function signalChild(int $signal): void {}
        };
    }
}
