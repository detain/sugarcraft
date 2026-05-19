<?php

declare(strict_types=1);

namespace SugarCraft\Wish\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Wish\Context;
use SugarCraft\Wish\Middleware;
use SugarCraft\Wish\Server;
use SugarCraft\Wish\Session;

final class MiddlewareContextTest extends TestCase
{
    private function fakeSession(): Session
    {
        return new Session(
            user: 'alice', clientHost: '127.0.0.1', clientPort: 1, serverHost: '127.0.0.1',
            serverPort: 22, term: 'xterm', cols: 80, rows: 24, tty: '/dev/pts/0',
            command: null, lang: 'C.UTF-8',
        );
    }

    public function testMiddlewareChainReceivesContext(): void
    {
        $capturedCtx = null;
        $capturedSess = null;

        $mw = new class($capturedCtx, $capturedSess) implements Middleware {
            public function __construct(private mixed &$ctx, private mixed &$sess) {}
            public function handle(Context $ctx, Session $session, callable $next): void
            {
                $this->ctx = $ctx;
                $this->sess = $session;
                $next($ctx, $session);
            }
        };

        Server::new()->use($mw)->serve($this->fakeSession());

        $this->assertInstanceOf(Context::class, $capturedCtx);
        $this->assertInstanceOf(Session::class, $capturedSess);
    }

    public function testContextIsPropagatedDownTheChain(): void
    {
        $receivedBySecond = null;
        $receivedByThird = null;

        $mw1 = new class implements Middleware {
            public function handle(Context $ctx, Session $session, callable $next): void
            {
                $next($ctx, $session);
            }
        };

        $mw2 = new class($receivedBySecond) implements Middleware {
            public function __construct(private mixed &$received) {}
            public function handle(Context $ctx, Session $session, callable $next): void
            {
                $this->received = $ctx;
                $next($ctx, $session);
            }
        };

        $mw3 = new class($receivedByThird) implements Middleware {
            public function __construct(private mixed &$received) {}
            public function handle(Context $ctx, Session $session, callable $next): void
            {
                $this->received = $ctx;
            }
        };

        Server::new()->use($mw1)->use($mw2)->use($mw3)->serve($this->fakeSession());

        $this->assertNotNull($receivedBySecond);
        $this->assertNotNull($receivedByThird);
        $this->assertSame($receivedBySecond, $receivedByThird);
    }

    public function testMiddlewareThatCancelsContextAbortsDownstream(): void
    {
        $log = [];

        $mw1 = new class($log) implements Middleware {
            public function __construct(private array &$log) {}
            public function handle(Context $ctx, Session $session, callable $next): void
            {
                $this->log[] = 'mw1-before-next';
                $cancellable = $ctx->withCancelable();
                $cancellable->cancel();
                $next($cancellable, $session);
                $this->log[] = 'mw1-after-next';
            }
        };

        $mw2 = new class($log) implements Middleware {
            public function __construct(private array &$log) {}
            public function handle(Context $ctx, Session $session, callable $next): void
            {
                $this->log[] = 'mw2-reached';
                $next($ctx, $session);
            }
        };

        Server::new()->use($mw1)->use($mw2)->serve($this->fakeSession());

        $this->assertSame(['mw1-before-next', 'mw1-after-next'], $log);
        $this->assertNotContains('mw2-reached', $log);
    }

    public function testMiddlewareCanCheckContextDoneBeforeNext(): void
    {
        $log = [];

        $mw1 = new class($log) implements Middleware {
            public function __construct(private array &$log) {}
            public function handle(Context $ctx, Session $session, callable $next): void
            {
                $this->log[] = 'mw1-before-cancel';
                $cancellable = $ctx->withCancelable();
                $cancellable->cancel();
                $this->log[] = 'mw1-after-cancel';
                if ($cancellable->done()) {
                    $this->log[] = 'mw1-short-circuit';
                    return;
                }
                $next($cancellable, $session);
                $this->log[] = 'mw1-after-next';
            }
        };

        $mw2 = new class($log) implements Middleware {
            public function __construct(private array &$log) {}
            public function handle(Context $ctx, Session $session, callable $next): void
            {
                $this->log[] = 'mw2-reached';
                $next($ctx, $session);
            }
        };

        Server::new()->use($mw1)->use($mw2)->serve($this->fakeSession());

        $this->assertSame(['mw1-before-cancel', 'mw1-after-cancel', 'mw1-short-circuit'], $log);
        $this->assertNotContains('mw2-reached', $log);
    }

    public function testContextValuesFlowToDownstreamMiddleware(): void
    {
        $receivedValue = null;

        $mw1 = new class implements Middleware {
            public function handle(Context $ctx, Session $session, callable $next): void
            {
                $next($ctx->withValue('request-id', 'req-123'), $session);
            }
        };

        $mw2 = new class($receivedValue) implements Middleware {
            public function __construct(private mixed &$received) {}
            public function handle(Context $ctx, Session $session, callable $next): void
            {
                $this->received = $ctx->value('request-id');
                $next($ctx, $session);
            }
        };

        Server::new()->use($mw1)->use($mw2)->serve($this->fakeSession());

        $this->assertSame('req-123', $receivedValue);
    }

    public function testShortCircuitedMiddlewareDoesNotAffectSiblingsAfter(): void
    {
        $log = [];

        $gate = new class($log) implements Middleware {
            public function __construct(private array &$log) {}
            public function handle(Context $ctx, Session $session, callable $next): void
            {
                $this->log[] = 'gate';
            }
        };

        $mw = new class($log) implements Middleware {
            public function __construct(private array &$log) {}
            public function handle(Context $ctx, Session $session, callable $next): void
            {
                $this->log[] = 'mw';
                $next($ctx, $session);
            }
        };

        Server::new()->use($gate)->use($mw)->serve($this->fakeSession());

        $this->assertSame(['gate'], $log);
    }
}
