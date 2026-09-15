<?php

declare(strict_types=1);

namespace SugarCraft\Wish\Tests\Middleware;

use React\EventLoop\Loop;
use React\Promise\Promise;
use React\Promise\PromiseInterface;
use SugarCraft\Wish\Context;
use SugarCraft\Wish\Middleware\AsyncMiddleware;
use SugarCraft\Wish\Session;
use SugarCraft\Wish\Transport\PromiseAwait;
use PHPUnit\Framework\TestCase;

final class AsyncMiddlewareTest extends TestCase
{
    private function session(): Session
    {
        return new Session(
            user: 'alice', clientHost: '203.0.113.7', clientPort: 5555, serverHost: '198.51.100.1',
            serverPort: 22, term: 'xterm', cols: 80, rows: 24, tty: '/dev/pts/3',
            command: null, lang: 'C.UTF-8',
        );
    }

    public function testResolvedPromiseAllowsChainToContinue(): void
    {
        $reachedNext = false;
        $middleware = new class extends AsyncMiddleware {
            protected function handleAsync(Context $ctx, Session $session, callable $next): PromiseInterface
            {
                return \React\Promise\resolve(null)->then(fn () => $next($ctx, $session));
            }
        };
        $middleware->handle(Context::background(), $this->session(), function (Context $c, Session $s) use (&$reachedNext): void {
            $reachedNext = true;
        });
        $this->assertTrue($reachedNext);
    }

    public function testAsyncWorkCompletesBeforeNextIsCalled(): void
    {
        $order = [];
        $middleware = new class($order) extends AsyncMiddleware {
            /** @var array<string, int> */
            private array $order;
            /** @param array<string, int> $order */
            public function __construct(array &$order) { $this->order = &$order; }
            protected function handleAsync(Context $ctx, Session $session, callable $next): PromiseInterface
            {
                $this->order['mw-start'] = 1;
                return \React\Promise\resolve(null)
                    ->then(function () {
                        $this->order['async-done'] = 2;
                    })
                    ->then(fn () => $next($ctx, $session))
                    ->then(function () {
                        $this->order['mw-end'] = 4;
                    });
            }
        };

        $middleware->handle(Context::background(), $this->session(), function (Context $c, Session $s) use (&$order): void {
            $order['next-called'] = 3;
        });

        $this->assertSame(1, $order['mw-start']);
        $this->assertSame(2, $order['async-done']);
        $this->assertSame(3, $order['next-called']);
        $this->assertSame(4, $order['mw-end']);
    }

    public function testRejectedPromiseIsHandedBackForTheAwaitingLayerToRaise(): void
    {
        $reachedNext = false;
        $middleware = new class extends AsyncMiddleware {
            protected function handleAsync(Context $ctx, Session $session, callable $next): PromiseInterface
            {
                return \React\Promise\reject(new \RuntimeException('async auth failed'));
            }
        };
        // E730: handle() is a pure async adapter — it must NOT throw and must
        // NOT await; the rejection surfaces where the single settle point
        // drives the promise (transport dispatch, or an explicit settle).
        $result = $middleware->handle(Context::background(), $this->session(), function (Context $c, Session $s) use (&$reachedNext): void {
            $reachedNext = true;
        });
        $this->assertInstanceOf(PromiseInterface::class, $result);
        $failure = null;
        try {
            PromiseAwait::settle($result);
        } catch (\RuntimeException $e) {
            $failure = $e;
        }
        $this->assertNotNull($failure, 'the awaiting layer must raise the rejection');
        $this->assertSame('async auth failed', $failure->getMessage());
        $this->assertFalse($reachedNext);
    }

    public function testMiddlewareReceivesContextWithValueFromResolvedPromise(): void
    {
        $capturedSession = null;
        $middleware = new class extends AsyncMiddleware {
            protected function handleAsync(Context $ctx, Session $session, callable $next): PromiseInterface
            {
                return \React\Promise\resolve($session->user)
                    ->then(fn (string $user) => $next(
                        $ctx->withValue('authenticated_user', $user),
                        $session,
                    ));
            }
        };
        $middleware->handle(Context::background(), $this->session(), function (Context $c, Session $s) use (&$capturedSession): void {
            $capturedSession = $c->value('authenticated_user');
        });
        $this->assertSame('alice', $capturedSession);
    }

    public function testHandleReturnsPromiseThatResolvesAfterChainCompletes(): void
    {
        $order = [];
        $mw1 = new class($order) extends AsyncMiddleware {
            /** @var array<string, int> */
            private array $order;
            /** @param array<string, int> $order */
            public function __construct(array &$order) { $this->order = &$order; }
            protected function handleAsync(Context $ctx, Session $session, callable $next): PromiseInterface
            {
                $this->order['mw1-start'] = 1;
                return \React\Promise\resolve(null)
                    ->then(fn () => $next($ctx, $session))
                    ->then(function () { $this->order['mw1-end'] = 4; });
            }
        };
        $mw2 = new class($order) extends AsyncMiddleware {
            /** @var array<string, int> */
            private array $order;
            /** @param array<string, int> $order */
            public function __construct(array &$order) { $this->order = &$order; }
            protected function handleAsync(Context $ctx, Session $session, callable $next): PromiseInterface
            {
                $this->order['mw2-start'] = 2;
                return \React\Promise\resolve(null)
                    ->then(fn () => $next($ctx, $session))
                    ->then(function () { $this->order['mw2-end'] = 3; });
            }
        };

        $finalCalled = false;
        $final = function (Context $c, Session $s) use (&$finalCalled, &$order): void {
            $order['final'] = 5;
            $finalCalled = true;
        };

        $ctx = Context::background();
        $session = $this->session();

        $result = $mw1->handle($ctx, $session, function (Context $c, Session $s) use ($mw2, $final) {
            return $mw2->handle($c, $s, $final);
        });

        $this->assertTrue($finalCalled);
        $this->assertSame(1, $order['mw1-start']);
        $this->assertSame(2, $order['mw2-start']);
        $this->assertSame(5, $order['final']);
        $this->assertSame(3, $order['mw2-end']);
        $this->assertSame(4, $order['mw1-end']);
    }

    public function testHandleDoesNotDriveTheLoopForAPendingPromise(): void
    {
        $chainDone = false;
        $middleware = new class extends AsyncMiddleware {
            protected function handleAsync(Context $ctx, Session $session, callable $next): PromiseInterface
            {
                return (new Promise(static function (callable $resolve): void {
                    Loop::addTimer(0.001, static fn () => $resolve(null));
                }))->then(fn () => $next($ctx, $session));
            }
        };
        $result = $middleware->handle(Context::background(), $this->session(), function (Context $c, Session $s) use (&$chainDone): void {
            $chainDone = true;
        });
        // E730: a genuinely pending promise comes back untouched — handle()
        // no longer runs the event loop on the caller's behalf.
        $this->assertFalse($chainDone);
        // The awaiting layer (the transport's single settle point in real
        // use, invoked directly here) completes the chain.
        PromiseAwait::settle($result);
        $this->assertTrue($chainDone);
    }
}
