<?php

declare(strict_types=1);

namespace SugarCraft\Wish\Tests;

use SugarCraft\Wish\Context;
use SugarCraft\Wish\Middleware;
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
        Server::new()->serve($this->fakeSession());
        $this->assertTrue(true);
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
            public function run(Context $ctx, Session $session, array $stack): void
            {
                $this->log[] = 'transport-run';
                $this->ctx = $ctx;
                $this->session = $session;
                $this->stack = $stack;
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
}
