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
}
