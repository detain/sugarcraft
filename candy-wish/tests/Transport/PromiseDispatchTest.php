<?php

declare(strict_types=1);

namespace SugarCraft\Wish\Tests\Transport;

use PHPUnit\Framework\TestCase;
use React\Promise;
use React\Promise\PromiseInterface;
use SugarCraft\Wish\Context;
use SugarCraft\Wish\Middleware;
use SugarCraft\Wish\Middleware\AsyncMiddleware;
use SugarCraft\Wish\Session;
use SugarCraft\Wish\Transport\HostSshdTransport;

/**
 * Tests for promise-aware dispatch through the transport dispatcher.
 *
 * Verifies that:
 * - a middleware whose handle() returns a promise that calls $next
 *   (AsyncMiddleware style) proceeds through the chain
 * - a middleware returning reject() short-circuits the chain
 *
 * The key invariant: the transport's PromiseAwait::settle() must
 * synchronously drive the promise to settlement; if it throws,
 * the chain short-circuits.
 */
final class PromiseDispatchTest extends TestCase
{
    private function fakeSession(): Session
    {
        return new Session(
            user: 'alice', clientHost: '127.0.0.1', clientPort: 1, serverHost: '127.0.0.1',
            serverPort: 22, term: 'xterm', cols: 80, rows: 24, tty: '/dev/pts/0',
            command: null, lang: 'C.UTF-8',
        );
    }

    public function testAsyncMiddlewareResolveProceedsToNextMiddleware(): void
    {
        $log = [];
        $asyncMw = new class($log) extends AsyncMiddleware {
            /** @var array<string> */
            private array $log;
            public function __construct(array &$ref) { $this->log = &$ref; }
            protected function handleAsync(Context $ctx, Session $s, callable $next): PromiseInterface
            {
                $this->log[] = 'async-pre';
                return Promise\resolve(null)->then(fn () => $next($ctx, $s));
            }
        };
        $recording = new class($log) implements Middleware {
            /** @var array<string> */
            private array $log;
            public function __construct(array &$ref) { $this->log = &$ref; }
            public function handle(Context $ctx, Session $s, callable $next): void
            {
                $this->log[] = 'recording';
            }
        };

        (new HostSshdTransport())->run(
            Context::background(),
            $this->fakeSession(),
            [$asyncMw, $recording],
        );

        $this->assertContains('async-pre', $log);
        $this->assertContains('recording', $log);
    }

    public function testAsyncMiddlewareRejectShortCircuitsChain(): void
    {
        $log = [];
        $asyncMw = new class($log) extends AsyncMiddleware {
            /** @var array<string> */
            private array $log;
            public function __construct(array &$ref) { $this->log = &$ref; }
            protected function handleAsync(Context $ctx, Session $s, callable $next): PromiseInterface
            {
                $this->log[] = 'async-pre';
                return Promise\reject(new \RuntimeException('boom'));
            }
        };
        $recording = new class($log) implements Middleware {
            /** @var array<string> */
            private array $log;
            public function __construct(array &$ref) { $this->log = &$ref; }
            public function handle(Context $ctx, Session $s, callable $next): void
            {
                $this->log[] = 'recording';
            }
        };

        try {
            (new HostSshdTransport())->run(
                Context::background(),
                $this->fakeSession(),
                [$asyncMw, $recording],
            );
            $this->fail('Expected RuntimeException was not thrown');
        } catch (\RuntimeException $e) {
            $this->assertSame('boom', $e->getMessage());
        }

        $this->assertContains('async-pre', $log);
        $this->assertNotContains('recording', $log);
    }

    public function testRejectViaExpectException(): void
    {
        $asyncMw = new class extends AsyncMiddleware {
            protected function handleAsync(Context $ctx, Session $s, callable $next): PromiseInterface
            {
                return Promise\reject(new \RuntimeException('boom'));
            }
        };

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('boom');

        (new HostSshdTransport())->run(
            Context::background(),
            $this->fakeSession(),
            [$asyncMw],
        );
    }
}
