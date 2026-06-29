<?php

declare(strict_types=1);

namespace SugarCraft\Wish\Tests\Middleware;

use SugarCraft\Wish\Context;
use SugarCraft\Wish\Middleware\BubbleTea;
use SugarCraft\Wish\Session;
use PHPUnit\Framework\TestCase;

final class BubbleTeaTest extends TestCase
{
    private function session(): Session
    {
        return new Session(
            user: 'alice', clientHost: '127.0.0.1', clientPort: 1, serverHost: '127.0.0.1',
            serverPort: 22, term: 'xterm', cols: 80, rows: 24, tty: null,
            command: null, lang: 'C.UTF-8',
        );
    }

    public function testFactoryReceivesSessionAndProgramRunInvoked(): void
    {
        $observed = null;
        $ran      = false;
        $factory = function (Session $s) use (&$observed, &$ran) {
            $observed = $s;
            return new class($ran) {
                public function __construct(private bool &$ran) {}
                public function run(): void { $this->ran = true; }
            };
        };
        $ctx = Context::background();
        $mw = new BubbleTea($factory);
        $mw->handle($ctx, $this->session(), fn() => null);
        $this->assertNotNull($observed);
        $this->assertSame('alice', $observed->user);
        $this->assertTrue($ran);
    }

    public function testRejectsFactoryReturningNonRunnable(): void
    {
        $mw = new BubbleTea(fn() => new \stdClass());
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('run()');
        $mw->handle(Context::background(), $this->session(), fn() => null);
    }

    public function testThrowsWhenTransportInjectsItself(): void
    {
        // PR5: BubbleTea exposes a duck-typed setTransport(ChildSpawner)
        // hook — when InProcessTransport injects itself at stack-walk
        // time, BubbleTea flips into in-process-mode and refuses to
        // run inline (Program would collide with the bytes pump).
        $mw = new BubbleTea(function () {
            return new class {
                public function run(): void { /* should never run */ }
            };
        });

        $spawner = new class implements \SugarCraft\Wish\Transport\ChildSpawner {
            public function runChild(Session $s, array $cmd, ?array $env = null): int
            {
                return 0;
            }

            public function signalChild(int $signal): void {}
        };
        $mw->setTransport($spawner);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/HostSshd|InProcess/');
        $mw->handle(Context::background(), $this->session(), fn() => null);
    }

    public function testWorksWhenNoTransportInjectedHostSshdMode(): void
    {
        // Without setTransport injection (HostSshdTransport doesn't
        // scan for it), BubbleTea runs the Program inline as before.
        // This is the legacy code path that pre-PTY-upgrade
        // deployments still rely on.
        $ran = false;
        $factory = function (Session $s) use (&$ran) {
            return new class($ran) {
                public function __construct(private bool &$ran) {}
                public function run(): void { $this->ran = true; }
            };
        };
        $mw = new BubbleTea($factory);
        $mw->handle(Context::background(), $this->session(), fn() => null);
        $this->assertTrue($ran, 'BubbleTea must run inline when no transport injected');
    }

    public function testInProcessTransportInjectsItselfAndBubbleTeaRefuses(): void
    {
        // End-to-end: full Server stack, InProcessTransport (default),
        // BubbleTea as terminal middleware. The transport's run()
        // walks the stack, injects itself into BubbleTea via
        // setTransport, then dispatch reaches handle() which throws.
        $mw = new BubbleTea(fn () => new class {
            public function run(): void { /* should never run */ }
        });

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/HostSshd|InProcess/');
        \SugarCraft\Wish\Server::new()
            ->use($mw)
            ->serve($this->session());
    }

    public function testHostSshdTransportPathStillRunsBubbleTea(): void
    {
        $ran = false;
        $mw = new BubbleTea(function () use (&$ran) {
            return new class($ran) {
                public function __construct(private bool &$ran) {}
                public function run(): void { $this->ran = true; }
            };
        });

        \SugarCraft\Wish\Server::new()
            ->withTransport(new \SugarCraft\Wish\Transport\HostSshdTransport())
            ->use($mw)
            ->serve($this->session());

        $this->assertTrue($ran, 'BubbleTea must run under HostSshdTransport');
    }
}
