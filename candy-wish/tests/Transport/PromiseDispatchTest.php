<?php

declare(strict_types=1);

namespace SugarCraft\Wish\Tests\Transport;

use PHPUnit\Framework\TestCase;
use React\Promise;
use React\Promise\PromiseInterface;
use SugarCraft\Wish\CancellationException;
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

    public function testContextCancelledDuringHandleAbortsTheAwaitAtDispatch(): void
    {
        $log = [];
        $asyncMw = new class($log) extends AsyncMiddleware {
            /** @var array<string> */
            private array $log;
            public function __construct(array &$ref) { $this->log = &$ref; }
            protected function handleAsync(Context $ctx, Session $s, callable $next): PromiseInterface
            {
                // The middleware itself signals cancellation (auth denied the
                // session, client hung up, ...) before handing its result back.
                $ctx->cancel();
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

        $ctx = Context::background()->withCancelable();
        $failure = null;
        try {
            (new HostSshdTransport())->run($ctx, $this->fakeSession(), [$asyncMw, $recording]);
        } catch (\Throwable $e) {
            $failure = $e;
        }

        // E730: the single settle point consults the live context — the await
        // is refused with the context error instead of completing the chain.
        $this->assertInstanceOf(CancellationException::class, $failure);
        $this->assertContains('async-pre', $log);
        $this->assertNotContains('recording', $log);
    }

    public function testTheSourceCarriesExactlyOneSettleCallSite(): void
    {
        // E730 single-settle-point census: PromiseAwait::settle() must be
        // invoked from exactly one place in src/ — the shared stack walk.
        // Before the restructure it was awaited twice per promise (once in
        // AsyncMiddleware::handle, once at dispatch); findings #43/#48.
        $callers = [];
        $srcDir = \dirname(__DIR__, 2) . '/src';
        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($srcDir, \FilesystemIterator::SKIP_DOTS),
        );
        foreach ($files as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            $tokens = array_values(array_filter(
                \PhpToken::tokenize((string) file_get_contents($file->getPathname())),
                static fn (\PhpToken $t): bool => !$t->is([\T_WHITESPACE, \T_COMMENT, \T_DOC_COMMENT]),
            ));
            $count = \count($tokens);
            for ($i = 2; $i < $count; $i++) {
                if (!$tokens[$i]->is(\T_STRING) || $tokens[$i]->text !== 'settle') {
                    continue;
                }
                if (!$tokens[$i - 1]->is(\T_DOUBLE_COLON)) {
                    continue;
                }
                // Owner may be the imported short name or any qualified
                // spelling ending in PromiseAwait — a settle call rewritten
                // as \SugarCraft\Wish\Transport\PromiseAwait::settle() must
                // not sneak past the census.
                $owner = $tokens[$i - 2];
                $namesPromiseAwait = $owner->text === 'PromiseAwait'
                    || ($owner->id === \T_NAME_QUALIFIED || $owner->id === \T_NAME_FULLY_QUALIFIED)
                        && \str_ends_with($owner->text, '\\PromiseAwait');
                if ($namesPromiseAwait) {
                    $callers[] = $file->getFilename();
                }
            }
        }

        $this->assertSame(
            ['DispatchesMiddlewareStack.php'],
            array_values(array_unique($callers)),
            'src/ must contain exactly one PromiseAwait::settle() call site (the transport stack walk)',
        );
    }
}
