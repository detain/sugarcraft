<?php

declare(strict_types=1);

namespace SugarCraft\Wish\Tests\Middleware;

use PHPUnit\Framework\TestCase;
use SugarCraft\Wish\Middleware\Keepalive;
use SugarCraft\Wish\Server;
use SugarCraft\Wish\Session;
use SugarCraft\Wish\Transport\ChildSpawner;
use SugarCraft\Wish\Transport\InProcessTransport;

final class KeepaliveTest extends TestCase
{
    private function session(): Session
    {
        return new Session(
            user: 'alice', clientHost: '127.0.0.1', clientPort: 1, serverHost: '127.0.0.1',
            serverPort: 22, term: 'xterm', cols: 80, rows: 24, tty: null,
            command: null, lang: 'C.UTF-8',
        );
    }

    public function testConstructedWithDefaultInterval(): void
    {
        $mw = new Keepalive();
        $this->assertSame(60, $mw->interval());
    }

    public function testConstructedWithCustomInterval(): void
    {
        $mw = new Keepalive(30);
        $this->assertSame(30, $mw->interval());
    }

    public function testInvalidIntervalThrowsException(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Keepalive(0);
    }

    public function testNegativeIntervalThrowsException(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Keepalive(-1);
    }

    public function testCanBeAddedToServer(): void
    {
        $mw = new Keepalive(30);
        $server = Server::new()->use($mw);
        $this->assertNotNull($server);
    }

    public function testSetTransportWithInProcessTransportRegistersCallback(): void
    {
        $mw = new Keepalive(1);
        $transport = new InProcessTransport();

        // Capture the registered callback.
        $registeredCallback = null;
        $transportReflection = new \ReflectionClass($transport);
        $prop = $transportReflection->getProperty('keepaliveCallback');
        $prop->setAccessible(true);

        $mw->setTransport($transport);

        // After setTransport, a callback should be registered.
        $this->assertNotNull($prop->getValue($transport));
    }

    public function testSetTransportWithNonInProcessTransportDoesNotThrow(): void
    {
        $mw = new Keepalive(30);
        $fakeTransport = new class implements ChildSpawner {
            public function runChild(Session $s, array $cmd, ?array $env = null): int
            {
                return 0;
            }
        };

        // Should not throw — HostSshdTransport or fake transports
        // are gracefully ignored.
        $mw->setTransport($fakeTransport);
        $this->assertSame(30, $mw->interval());
    }

    public function testMiddlewarePassesNextThrough(): void
    {
        $nextCalled = false;
        $mw = new Keepalive(60);
        $mw->handle($this->session(), function () use (&$nextCalled): void {
            $nextCalled = true;
        });
        $this->assertTrue($nextCalled, 'Keepalive must call $next');
    }

    public function testGetPtyThrowsOutsidePumpLoop(): void
    {
        $transport = new InProcessTransport();
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('getPty() called outside of active pump loop');
        $transport->getPty();
    }

    public function testInProcessTransportInjectsItselfIntoSetTransportAwareMiddleware(): void
    {
        // Verify that InProcessTransport's run() walks the stack and
        // calls setTransport on middleware that implement the hook.
        $observed = null;
        $probe = new class($observed) implements \SugarCraft\Wish\Middleware {
            public function __construct(private mixed &$captured) {}
            public function setTransport(ChildSpawner $t): void
            {
                $this->captured = $t;
            }
            public function handle(Session $s, callable $next): void
            {
                // Don't invoke $next.
            }
        };

        $transport = new InProcessTransport();
        $transport->run($this->session(), [$probe]);

        $this->assertSame($transport, $observed, 'InProcessTransport must inject itself via setTransport');
    }
}
