<?php

declare(strict_types=1);

namespace SugarCraft\SshServer\Tests\Middleware;

use PHPUnit\Framework\TestCase;
use SugarCraft\SshServer\Context;
use SugarCraft\SshServer\Middleware;
use SugarCraft\SshServer\Middleware\Keepalive;
use SugarCraft\SshServer\Session;
use SugarCraft\SshServer\Transport\KeepaliveArming;

/**
 * ssrv-11 (audit 079) rewrote Keepalive from a middleware with a
 * transport side effect into a pure value holder armed through
 * `Server::withKeepalive()`. The request-path tests retired with the
 * shape; the registration story now lives in ServerTest (arm census
 * through a recording transport) and in the transport-level arms below.
 */
final class KeepaliveTest extends TestCase
{
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

    /**
     * Structural pin of the shape change: a value holder, not a
     * middleware. `->use(new Keepalive(30))` fails loudly (TypeError at
     * the parameter type) instead of silently registering nothing.
     */
    public function testKeepaliveIsAPureValueHolderNotMiddleware(): void
    {
        $this->assertFalse(
            (new Keepalive(30)) instanceof Middleware,
            'keepalive behaviour never rode the request path; it must not dress as middleware',
        );
        $methods = \array_map(
            static fn (\ReflectionMethod $m): string => $m->getName(),
            (new \ReflectionClass(Keepalive::class))->getMethods(\ReflectionMethod::IS_PUBLIC),
        );
        $this->assertSame(['__construct', 'interval'], $methods, 'no handle()/setTransport() smuggle may return');
    }

    public function testHostSshdTransportDoesNotImplementTheArmingSeam(): void
    {
        // HostSshdTransport has no pump idle path; the Server door
        // degrades to its documented silent no-op, which the seam
        // census here pins from the other side.
        $this->assertFalse(
            (new \SugarCraft\SshServer\Transport\HostSshdTransport()) instanceof KeepaliveArming,
        );
    }

    public function testArmKeepaliveRegistersThePumpCallback(): void
    {
        $transport = new \SugarCraft\SshServer\Transport\InProcessTransport();
        $this->assertTrue($transport instanceof KeepaliveArming);

        $transport->armKeepalive(1);

        $prop = (new \ReflectionClass($transport))->getProperty('keepaliveCallback');
        $this->assertNotNull($prop->getValue($transport), 'arming must replace the idle callback');
    }

    public function testArmedCallbackWritesThroughThePtyWhenFired(): void
    {
        // The beat writes through pty(); outside a live pump loop that
        // accessor must throw (its own guard), which is what invoking
        // the captured callback here proves — the closure really points
        // at the master write, not at a no-op.
        $transport = new \SugarCraft\SshServer\Transport\InProcessTransport();
        $transport->armKeepalive(1);

        $prop = (new \ReflectionClass($transport))->getProperty('keepaliveCallback');
        $callback = $prop->getValue($transport);
        $this->assertIsCallable($callback);

        // Fired before the interval has elapsed the guard holds: no
        // write, so pty() is never touched and nothing throws.
        $callback();

        // Past the interval the write path runs — and outside a live
        // pump loop pty() refuses, which is the proof the closure
        // points at the master.
        \usleep(1_050_000);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('pty() called outside');
        $callback();
    }

    public function testArmKeepaliveRejectsSubSecondIntervals(): void
    {
        $transport = new \SugarCraft\SshServer\Transport\InProcessTransport();

        $this->expectException(\InvalidArgumentException::class);
        $transport->armKeepalive(0);
    }

    public function testInProcessTransportInjectsItselfIntoSetTransportAwareMiddleware(): void
    {
        // The TransportAware seam stays for the middlewares whose
        // behaviour really is request-path (Spawn, BubbleTea): this
        // pins the REAL transport's run() injection walk, now that
        // Keepalive was the odd one out and left.
        $observed = null;
        $probe = new class ($observed) implements Middleware, \SugarCraft\SshServer\TransportAware {
            public function __construct(private mixed &$captured) {}

            public function setTransport(\SugarCraft\SshServer\Transport\ChildSpawner $t): void
            {
                $this->captured = $t;
            }

            public function handle(Context $ctx, Session $s, callable $next): void
            {
                // Don't invoke $next.
            }
        };

        $transport = new \SugarCraft\SshServer\Transport\InProcessTransport();
        $transport->run(Context::background(), new Session(
            user: 'alice', clientHost: '127.0.0.1', clientPort: 1, serverHost: '127.0.0.1',
            serverPort: 22, term: 'xterm', cols: 80, rows: 24, tty: null,
            command: null, lang: 'C.UTF-8',
        ), [$probe]);

        $this->assertSame($transport, $observed, 'the stack-walk seam must keep injecting the transport');
    }
}
