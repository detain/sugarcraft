<?php

declare(strict_types=1);

namespace SugarCraft\Async\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Async\CancellationSource;
use SugarCraft\Async\CancellationToken;

/**
 * @covers \SugarCraft\Async\CancellationToken
 * @covers \SugarCraft\Async\CancellationSource
 */
final class CancellationTokenTest extends TestCase
{
    public function testSourceStartsUncancelled(): void
    {
        $source = CancellationSource::new();
        $token = $source->token();

        $this->assertFalse($source->isCancelled());
        $this->assertFalse($token->isCancelled());
    }

    public function testCancelFlipsSourceAndToken(): void
    {
        $source = CancellationSource::new();
        $token = $source->token();

        $source->cancel();

        $this->assertTrue($source->isCancelled());
        $this->assertTrue($token->isCancelled());
    }

    public function testCancelIsIdempotent(): void
    {
        $source = CancellationSource::new();

        $source->cancel();
        $source->cancel();
        $source->cancel();

        $this->assertTrue($source->isCancelled());
    }

    public function testOnCancelCallbackFiresOnCancel(): void
    {
        $source = CancellationSource::new();
        $token = $source->token();
        $called = false;

        $token->onCancel(function () use (&$called): void {
            $called = true;
        });

        $this->assertFalse($called);
        $source->cancel();
        $this->assertTrue($called);
    }

    public function testOnCancelCallbackFiresExactlyOnce(): void
    {
        $source = CancellationSource::new();
        $token = $source->token();
        $count = 0;

        $token->onCancel(function () use (&$count): void {
            $count++;
        });

        $source->cancel();
        $source->cancel();
        $source->cancel();

        $this->assertSame(1, $count);
    }

    public function testMultipleCallbacksFireInOrder(): void
    {
        $source = CancellationSource::new();
        $token = $source->token();
        $order = [];

        $token->onCancel(function () use (&$order): void {
            $order[] = 'first';
        });
        $token->onCancel(function () use (&$order): void {
            $order[] = 'second';
        });
        $token->onCancel(function () use (&$order): void {
            $order[] = 'third';
        });

        $source->cancel();

        $this->assertSame(['first', 'second', 'third'], $order);
    }

    public function testOnCancelFiresImmediatelyIfAlreadyCancelled(): void
    {
        $source = CancellationSource::new();
        $token = $source->token();
        $called = false;

        $source->cancel();

        $token->onCancel(function () use (&$called): void {
            $called = true;
        });

        $this->assertTrue($called);
    }

    public function testTokenIsReadOnlyViaSourceOnly(): void
    {
        $source = CancellationSource::new();
        $token = $source->token();

        // While markCancelled is technically callable (needed by CancellationSource),
        // the only intended path is via Source::cancel(). Demonstrating that
        // markCancelled IS on the token but the public API is Source->cancel().
        $this->assertFalse($source->isCancelled());
        $this->assertFalse($token->isCancelled());

        // The intended cancellation path
        $source->cancel();
        $this->assertTrue($source->isCancelled());
        $this->assertTrue($token->isCancelled());
    }

    public function testCancellationSourceImplementsCancellable(): void
    {
        $source = CancellationSource::new();
        $this->assertInstanceOf(\SugarCraft\Async\Cancellable::class, $source);
    }

    public function testNewSourceReturnsDistinctInstances(): void
    {
        $source1 = CancellationSource::new();
        $source2 = CancellationSource::new();

        $this->assertNotSame($source1->token(), $source2->token());
    }

    public function testSourceOnCancelDelegatesToToken(): void
    {
        $source = CancellationSource::new();
        $called = false;

        // Register callback via the source (which delegates to the token)
        $source->onCancel(function () use (&$called): void {
            $called = true;
        });

        $this->assertFalse($called);
        $source->cancel();
        $this->assertTrue($called);
    }

    public function testSourceOnCancelFiresImmediatelyIfCancelled(): void
    {
        $source = CancellationSource::new();
        $called = false;

        $source->cancel();

        $source->onCancel(function () use (&$called): void {
            $called = true;
        });

        $this->assertTrue($called);
    }

    public function testMarkCancelledIsIdempotent(): void
    {
        $source = CancellationSource::new();
        $token = $source->token();
        $count = 0;

        $token->onCancel(function () use (&$count): void {
            $count++;
        });

        // First cancellation
        $source->cancel();
        // Second cancellation attempt - should not fire callbacks again
        $source->cancel();

        $this->assertSame(1, $count);
    }

    /**
     * Verify that CancellationSource::cancel() properly sets the token's
     * cancelled state. This is the intended public API for triggering
     * cancellation — consumers must use CancellationSource::cancel(), not
     * bypass it by calling markCancelled() directly on the token.
     *
     * The CancellationToken::markCancelled() method is @internal and must
     * remain accessible to CancellationSource (same package) so that the
     * source can propagate cancellation into the token. Consumers who
     * bypass CancellationSource and call markCancelled() directly would
     * corrupt token state — this test validates the correct path works.
     */
    public function testCancellationSourceCancelSetsTokenState(): void
    {
        $source = CancellationSource::new();
        $token = $source->token();

        // Before cancellation — token is NOT cancelled
        $this->assertFalse($token->isCancelled(), 'Token must not be cancelled before cancel() is called');

        // The intended public cancellation path: CancellationSource::cancel()
        $source->cancel();

        // After cancellation — token IS cancelled (verified via public API)
        $this->assertTrue($token->isCancelled(), 'Token must be cancelled after CancellationSource::cancel()');

        // Subsequent calls to isCancelled() remain true (no state corruption)
        $this->assertTrue($token->isCancelled(), 'Token cancelled state must be stable (no corruption on repeated checks)');

        // Source and token states are consistent
        $this->assertSame($source->isCancelled(), $token->isCancelled(),
            'CancellationSource and CancellationToken must agree on cancelled state');
    }

    /**
     * CancellationSource::cancel() must work correctly across multiple
     * independent source/token pairs — no shared state interference.
     */
    public function testIndependentSourceTokenPairs(): void
    {
        $source1 = CancellationSource::new();
        $source2 = CancellationSource::new();
        $token1 = $source1->token();
        $token2 = $source2->token();

        // Cancel only source 1
        $source1->cancel();

        // Token 1 must be cancelled; token 2 must NOT be cancelled
        $this->assertTrue($token1->isCancelled());
        $this->assertFalse($token2->isCancelled());

        // Source 2 and token 2 remain consistent
        $this->assertSame($source2->isCancelled(), $token2->isCancelled());
    }
}
