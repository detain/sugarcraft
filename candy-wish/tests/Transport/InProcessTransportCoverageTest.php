<?php

declare(strict_types=1);

namespace SugarCraft\Wish\Tests\Transport;

use PHPUnit\Framework\TestCase;
use SugarCraft\Wish\Session;
use SugarCraft\Wish\Transport\InProcessTransport;

/**
 * Tests for InProcessTransport methods with low or no coverage:
 * - setKeepaliveCallback
 * - getPty (RuntimeException path)
 * - run transport injection into middleware via setTransport
 * - runChild invalid argument validation
 */
final class InProcessTransportCoverageTest extends TestCase
{
    private function fakeSession(): Session
    {
        return new Session(
            user: 'alice', clientHost: '127.0.0.1', clientPort: 1, serverHost: '127.0.0.1',
            serverPort: 22, term: 'xterm', cols: 80, rows: 24, tty: '/dev/pts/0',
            command: null, lang: 'C.UTF-8',
        );
    }

    public function testGetPtyThrowsRuntimeExceptionWhenNotInPumpLoop(): void
    {
        $transport = new InProcessTransport();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('getPty() called outside of active pump loop');
        $transport->getPty();
    }

    public function testSetKeepaliveCallbackStoresCallback(): void
    {
        $transport = new InProcessTransport();
        $called = false;
        $transport->setKeepaliveCallback(function () use (&$called): void {
            $called = true;
        });

        // Use reflection to verify the callback was stored
        $reflection = new \ReflectionClass($transport);
        $prop = $reflection->getProperty('keepaliveCallback');
        $prop->setAccessible(true);
        $stored = $prop->getValue($transport);

        $this->assertNotNull($stored);
        $this->assertIsCallable($stored);

        // Verify it can be invoked
        $stored();
        $this->assertTrue($called);
    }

    public function testWithSizeProviderReturnsClonedTransport(): void
    {
        $original = new InProcessTransport();
        $original->setKeepaliveCallback(function (): void {});

        $clone = $original->withSizeProvider(fn (): array => ['cols' => 200, 'rows' => 60]);

        $this->assertNotSame($original, $clone, 'withSizeProvider must clone, not mutate');
        $this->assertInstanceOf(InProcessTransport::class, $clone);

        // Original should still have its callback
        $origReflection = new \ReflectionClass($original);
        $origProp = $origReflection->getProperty('keepaliveCallback');
        $origProp->setAccessible(true);
        $this->assertNotNull($origProp->getValue($original));

        // Clone should have the new size provider
        $cloneReflection = new \ReflectionClass($clone);
        $cloneSizeProp = $cloneReflection->getProperty('sizeProvider');
        $cloneSizeProp->setAccessible(true);
        $this->assertNotNull($cloneSizeProp->getValue($clone));
    }

    public function testRunInjectsTransportIntoMiddlewareWithSetTransport(): void
    {
        $transport = new InProcessTransport();
        $capturedTransport = null;

        // Use reflection to capture what gets passed to setTransport
        $middlewareWithCapture = new class($capturedTransport) implements \SugarCraft\Wish\Middleware {
            private ?\SugarCraft\Wish\Transport\ChildSpawner $receivedTransport = null;

            public function __construct(private mixed &$captured) {}

            public function handle(\SugarCraft\Wish\Session $s, callable $next): void
            {
                $next($s);
            }

            public function setTransport(\SugarCraft\Wish\Transport\ChildSpawner $t): void
            {
                $this->receivedTransport = $t;
                $this->captured = $t;
            }

            public function getReceivedTransport(): ?\SugarCraft\Wish\Transport\ChildSpawner
            {
                return $this->receivedTransport;
            }
        };

        $transport->run($this->fakeSession(), [$middlewareWithCapture]);

        $this->assertNotNull($capturedTransport, 'Middleware setTransport should have been called');
        $this->assertSame($transport, $capturedTransport, 'Should receive the InProcessTransport instance');
    }

    public function testRunDoesNotInjectTransportIntoMiddlewareWithoutSetTransport(): void
    {
        $transport = new InProcessTransport();
        $nextCalled = false;

        $middlewareWithoutSetTransport = new class($nextCalled) implements \SugarCraft\Wish\Middleware {
            public function __construct(private mixed &$nextCalled) {}
            public function handle(\SugarCraft\Wish\Session $s, callable $next): void
            {
                // This middleware does NOT have setTransport
                $this->nextCalled = true;
                $next($s);
            }
        };

        $transport->run($this->fakeSession(), [$middlewareWithoutSetTransport]);

        $this->assertTrue($nextCalled, 'Middleware without setTransport should still work');
    }

    /**
     * @requires extension pcntl
     */
    public function testRunChildRejectsNonResourceStdout(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            $this->markTestSkipped('candy-pty is POSIX-only.');
        }
        if (!\extension_loaded('ffi')) {
            $this->markTestSkipped('ext-ffi is required.');
        }
        if (!\extension_loaded('pcntl')) {
            $this->markTestSkipped('ext-pcntl is required.');
        }
        if (!\is_readable('/dev/ptmx') || !\is_writable('/dev/ptmx')) {
            $this->markTestSkipped('/dev/ptmx unreadable on this host.');
        }

        $session = new Session(
            user: 't', clientHost: '127.0.0.1', clientPort: 0, serverHost: '127.0.0.1',
            serverPort: 22, term: 'xterm', cols: 80, rows: 24, tty: null,
            command: null, lang: 'C',
        );

        $this->expectException(\InvalidArgumentException::class);
        (new InProcessTransport())->runChild(
            $session,
            ['/bin/true'],
            null,
            \STDIN,
            'not-a-resource',
        );
    }

    public function testDispatchShortCircuitsWhenIndexExceedsStack(): void
    {
        $transport = new InProcessTransport();

        // Empty stack should be handled by dispatch returning early
        // This is tested via run() with empty stack in InProcessTransportTest
        // But let's verify dispatch behavior directly via run()
        $called = false;
        $middleware = new class($called) implements \SugarCraft\Wish\Middleware {
            public function __construct(private mixed &$called) {}
            public function handle(\SugarCraft\Wish\Session $s, callable $next): void
            {
                $this->called = true;
                $next($s);
            }
        };

        $transport->run($this->fakeSession(), [$middleware]);
        $this->assertTrue($called);
    }
}
