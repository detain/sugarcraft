<?php

declare(strict_types=1);

namespace SugarCraft\Wish\Tests\Transport;

use PHPUnit\Framework\TestCase;
use SugarCraft\Wish\Middleware;
use SugarCraft\Wish\Session;
use SugarCraft\Wish\Transport\InProcessTransport;

/**
 * PR1 stub-coverage: until PR2 lands the PTY allocation + bytes
 * pump, the InProcess transport walks the middleware stack inline
 * exactly like HostSshd does. These tests pin that PR1 contract so
 * downstream callers can rely on Logger / RateLimit / Auth running
 * the same way under either transport.
 */
final class InProcessTransportTest extends TestCase
{
    private function fakeSession(): Session
    {
        return new Session(
            user: 'alice', clientHost: '127.0.0.1', clientPort: 1, serverHost: '127.0.0.1',
            serverPort: 22, term: 'xterm', cols: 80, rows: 24, tty: '/dev/pts/0',
            command: null, lang: 'C.UTF-8',
        );
    }

    public function testNonSpawningMiddlewareWalkInRegistrationOrder(): void
    {
        $log = [];
        $a = new class($log) implements Middleware {
            public function __construct(private array &$log) {}
            public function handle(Session $s, callable $next): void
            {
                $this->log[] = 'a-pre';
                $next($s);
                $this->log[] = 'a-post';
            }
        };
        $b = new class($log) implements Middleware {
            public function __construct(private array &$log) {}
            public function handle(Session $s, callable $next): void
            {
                $this->log[] = 'b-pre';
                $next($s);
                $this->log[] = 'b-post';
            }
        };

        (new InProcessTransport())->run($this->fakeSession(), [$a, $b]);

        $this->assertSame(['a-pre', 'b-pre', 'b-post', 'a-post'], $log);
    }

    public function testMiddlewareShortCircuitWorks(): void
    {
        $log = [];
        $gate = new class($log) implements Middleware {
            public function __construct(private array &$log) {}
            public function handle(Session $s, callable $next): void
            {
                $this->log[] = 'gate-block';
            }
        };
        $never = new class($log) implements Middleware {
            public function __construct(private array &$log) {}
            public function handle(Session $s, callable $next): void
            {
                $this->log[] = 'never';
            }
        };

        (new InProcessTransport())->run($this->fakeSession(), [$gate, $never]);

        $this->assertSame(['gate-block'], $log);
    }

    public function testEmptyStackIsHarmless(): void
    {
        (new InProcessTransport())->run($this->fakeSession(), []);
        $this->assertTrue(true);
    }
}
