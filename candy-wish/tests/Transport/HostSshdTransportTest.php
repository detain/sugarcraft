<?php

declare(strict_types=1);

namespace SugarCraft\Wish\Tests\Transport;

use PHPUnit\Framework\TestCase;
use SugarCraft\Wish\Context;
use SugarCraft\Wish\Middleware;
use SugarCraft\Wish\Session;
use SugarCraft\Wish\Transport\HostSshdTransport;

final class HostSshdTransportTest extends TestCase
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
        $a = new class($log) implements Middleware {
            public function __construct(private array &$log) {}
            public function handle(Context $ctx, Session $s, callable $next): void
            {
                $this->log[] = 'a-pre';
                $next($ctx, $s);
                $this->log[] = 'a-post';
            }
        };
        $b = new class($log) implements Middleware {
            public function __construct(private array &$log) {}
            public function handle(Context $ctx, Session $s, callable $next): void
            {
                $this->log[] = 'b-pre';
                $next($ctx, $s);
                $this->log[] = 'b-post';
            }
        };
        $terminal = new class($log) implements Middleware {
            public function __construct(private array &$log) {}
            public function handle(Context $ctx, Session $s, callable $next): void
            {
                $this->log[] = 'terminal';
            }
        };

        (new HostSshdTransport())->run(Context::background(), $this->fakeSession(), [$a, $b, $terminal]);

        $this->assertSame(['a-pre', 'b-pre', 'terminal', 'b-post', 'a-post'], $log);
    }

    public function testMiddlewareCanShortCircuit(): void
    {
        $log = [];
        $gate = new class($log) implements Middleware {
            public function __construct(private array &$log) {}
            public function handle(Context $ctx, Session $s, callable $next): void
            {
                $this->log[] = 'gate-block';
            }
        };
        $never = new class($log) implements Middleware {
            public function __construct(private array &$log) {}
            public function handle(Context $ctx, Session $s, callable $next): void
            {
                $this->log[] = 'never';
            }
        };

        (new HostSshdTransport())->run(Context::background(), $this->fakeSession(), [$gate, $never]);

        $this->assertSame(['gate-block'], $log);
    }

    public function testEmptyStackIsHarmless(): void
    {
        (new HostSshdTransport())->run(Context::background(), $this->fakeSession(), []);
        $this->assertTrue(true);
    }

    public function testSessionPropagatesUnchangedToTerminalMiddleware(): void
    {
        $captured = null;
        $session = $this->fakeSession();
        $terminal = new class($captured) implements Middleware {
            public function __construct(private ?Session &$captured) {}
            public function handle(Context $ctx, Session $s, callable $next): void
            {
                $this->captured = $s;
            }
        };

        (new HostSshdTransport())->run(Context::background(), $session, [$terminal]);

        $this->assertSame($session, $captured);
    }
}
