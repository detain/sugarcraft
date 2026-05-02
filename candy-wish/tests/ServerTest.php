<?php

declare(strict_types=1);

namespace CandyCore\Wish\Tests;

use CandyCore\Wish\Middleware;
use CandyCore\Wish\Server;
use CandyCore\Wish\Session;
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
            public function handle(Session $s, callable $next): void
            {
                $this->log[] = 'a-pre';
                $next($s);
                $this->log[] = 'a-post';
            }
        };
        $mw2 = new class($log) implements Middleware {
            public function __construct(private array &$log) {}
            public function handle(Session $s, callable $next): void
            {
                $this->log[] = 'b-pre';
                $next($s);
                $this->log[] = 'b-post';
            }
        };
        $mw3 = new class($log) implements Middleware {
            public function __construct(private array &$log) {}
            public function handle(Session $s, callable $next): void
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
            public function handle(Session $s, callable $next): void
            {
                $this->log[] = 'gate-blocked';
                // never call $next
            }
        };
        $never = new class($log) implements Middleware {
            public function __construct(private array &$log) {}
            public function handle(Session $s, callable $next): void
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
}
