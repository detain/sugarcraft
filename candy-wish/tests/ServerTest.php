<?php

declare(strict_types=1);

namespace SugarCraft\Wish\Tests;

use SugarCraft\Wish\Context;
use SugarCraft\Wish\Middleware;
use SugarCraft\Wish\Middleware\Keepalive;
use SugarCraft\Wish\Server;
use SugarCraft\Wish\Session;
use PHPUnit\Framework\TestCase;

final class ServerTest extends TestCase
{
    private function fakeSession(): Session
    {
        return new Session(
            user: 'alice', clientHost: '127.0.0.1', clientPort: 1, serverHost: '127.0.0.1',
            serverPort: 22, term: 'xterm', cols: 80, rows: 24, tty: '/dev/pts/0',
            command: null, lang: 'C.UTF-8',
        );
    }

    public function testStackInvokedInRegistrationOrder(): void
    {
        $log = [];
        $mw1 = new class($log) implements Middleware {
            public function __construct(private array &$log) {}
            public function handle(Context $ctx, Session $s, callable $next): void
            {
                $this->log[] = 'a-pre';
                $next($ctx, $s);
                $this->log[] = 'a-post';
            }
        };
        $mw2 = new class($log) implements Middleware {
            public function __construct(private array &$log) {}
            public function handle(Context $ctx, Session $s, callable $next): void
            {
                $this->log[] = 'b-pre';
                $next($ctx, $s);
                $this->log[] = 'b-post';
            }
        };
        $mw3 = new class($log) implements Middleware {
            public function __construct(private array &$log) {}
            public function handle(Context $ctx, Session $s, callable $next): void
            {
                $this->log[] = 'c';
            }
        };

        Server::new()->use($mw1)->use($mw2)->use($mw3)->serve($this->fakeSession());

        $this->assertSame(['a-pre', 'b-pre', 'c', 'b-post', 'a-post'], $log);
    }

    public function testMiddlewareCanShortCircuit(): void
    {
        $log = [];
        $gate = new class($log) implements Middleware {
            public function __construct(private array &$log) {}
            public function handle(Context $ctx, Session $s, callable $next): void
            {
                $this->log[] = 'gate-blocked';
            }
        };
        $never = new class($log) implements Middleware {
            public function __construct(private array &$log) {}
            public function handle(Context $ctx, Session $s, callable $next): void
            {
                $this->log[] = 'reached';
            }
        };
        Server::new()->use($gate)->use($never)->serve($this->fakeSession());
        $this->assertSame(['gate-blocked'], $log);
    }

    public function testEmptyStackIsNoop(): void
    {
        $this->assertSame(0, Server::new()->serve($this->fakeSession()));
    }

    public function testServeReturnsTheTransportExitStatus(): void
    {
        // MEDIUM-2 seam pin: serve() hands the transport's status to
        // the ForceCommand script, which exits with it so the SSH
        // client sees the child's real failure code.
        $transport = new class implements \SugarCraft\Wish\Transport {
            public function run(Context $ctx, Session $session, array $stack): int
            {
                return 42;
            }
        };

        $server = Server::new()->withTransport($transport);

        $this->assertSame(42, $server->serve($this->fakeSession()));
    }

    public function testDefaultTransportIsInProcess(): void
    {
        $server = Server::new();
        $this->assertInstanceOf(
            \SugarCraft\Wish\Transport\InProcessTransport::class,
            $server->transport(),
        );
    }

    public function testWithTransportOverridesDefault(): void
    {
        $hostSshd = new \SugarCraft\Wish\Transport\HostSshdTransport();
        $server = Server::new()->withTransport($hostSshd);
        $this->assertSame($hostSshd, $server->transport());
    }

    public function testServeDelegatesToActiveTransport(): void
    {
        $log = [];
        $captured = null;
        $captured2 = null;
        $capturedStack = null;
        $transport = new class($log, $captured, $captured2, $capturedStack) implements \SugarCraft\Wish\Transport {
            public function __construct(
                private array &$log,
                private ?Context &$ctx,
                private ?Session &$session,
                private ?array &$stack,
            ) {}
            public function run(Context $ctx, Session $session, array $stack): int
            {
                $this->log[] = 'transport-run';
                $this->ctx = $ctx;
                $this->session = $session;
                $this->stack = $stack;
                return 0;
            }
        };

        $mw = new class($log) implements Middleware {
            public function __construct(private array &$log) {}
            public function handle(Context $ctx, Session $s, callable $next): void
            {
                $this->log[] = 'mw';
            }
        };

        $session = $this->fakeSession();
        Server::new()
            ->withTransport($transport)
            ->use($mw)
            ->serve($session);

        $this->assertSame(['transport-run'], $log, 'transport must run; mw runs only if transport invokes it');
        $this->assertInstanceOf(Context::class, $captured);
        $this->assertSame($session, $captured2);
    }

    public function testWithKeepaliveReturnsANewInstance(): void
    {
        // LOW-12 (audit round): with* builders clone — the receiver
        // keeps its own stack untouched, so a shared base Server can
        // be branched per route.
        $server = Server::new();
        $result = $server->withKeepalive();

        $this->assertNotSame($server, $result);

        $originalStack = (function (): array {
            return $this->stack;
        })->call($server);
        $this->assertCount(0, $originalStack);
    }

    public function testWithTransportReturnsANewInstance(): void
    {
        $server = Server::new();
        $hostSshd = new \SugarCraft\Wish\Transport\HostSshdTransport();
        $result = $server->withTransport($hostSshd);

        $this->assertNotSame($server, $result);
        $this->assertInstanceOf(
            \SugarCraft\Wish\Transport\InProcessTransport::class,
            $server->transport(),
            'the receiver must keep its default transport',
        );
        $this->assertSame($hostSshd, $result->transport());
    }

    public function testServeThreadsTheInjectedContext(): void
    {
        // LOW-12 seam: serve() exposes a Context injection point, so
        // the M1 cancel-propagation machinery is reachable through the
        // public API instead of only via transport->run() directly.
        $captured = null;
        $recorder = new class ($captured) implements \SugarCraft\Wish\Middleware {
            public function __construct(private mixed &$captured)
            {
            }

            public function handle(Context $ctx, Session $session, callable $next)
            {
                $this->captured = $ctx;
                return null;
            }
        };

        $transport = new class implements \SugarCraft\Wish\Transport {
            public function run(Context $ctx, Session $session, array $stack): int
            {
                foreach ($stack as $mw) {
                    $mw->handle($ctx, $session, static fn () => null);
                }
                return 0;
            }
        };

        $ctx = Context::background();
        $server = Server::new()->withTransport($transport)->use($recorder);
        $server->serve($this->fakeSession(), $ctx);

        $this->assertSame($ctx, $captured);
    }

    public function testWithKeepaliveAppendsKeepaliveMiddleware(): void
    {
        $server = Server::new()->withKeepalive();

        $stack = (function (): array {
            return $this->stack;
        })->call($server);

        $this->assertCount(1, $stack);
        $this->assertInstanceOf(Keepalive::class, $stack[0]);
    }

    public function testWithKeepaliveDefaultIntervalIs60(): void
    {
        $server = Server::new()->withKeepalive();

        $stack = (function (): array {
            return $this->stack;
        })->call($server);

        $this->assertSame(60, $stack[0]->interval());
    }

    public function testWithKeepaliveCustomInterval(): void
    {
        $server = Server::new()->withKeepalive(30);

        $stack = (function (): array {
            return $this->stack;
        })->call($server);

        $this->assertSame(30, $stack[0]->interval());
    }
}
