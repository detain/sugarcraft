<?php

declare(strict_types=1);

namespace SugarCraft\Async\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Async\CancellationSource;
use SugarCraft\Async\Cancellable;

/**
 * @covers \SugarCraft\Async\CancellationSource
 */
final class CancellationSourceTest extends TestCase
{
    public function testNewReturnsCancellationSourceInstance(): void
    {
        $source = CancellationSource::new();
        $this->assertInstanceOf(CancellationSource::class, $source);
    }

    public function testCancellationSourceImplementsCancellable(): void
    {
        $source = CancellationSource::new();
        $this->assertInstanceOf(Cancellable::class, $source);
    }



    public function testTokenReturnsReadOnlyCancellationToken(): void
    {
        $source = CancellationSource::new();
        $token = $source->token();

        $this->assertNotSame($source, $token);
        $this->assertFalse($token->isCancelled());
    }

    public function testSourceStartAsNotCancelled(): void
    {
        $source = CancellationSource::new();
        $this->assertFalse($source->isCancelled());
    }

    public function testCancelSetsCancelledStateToTrue(): void
    {
        $source = CancellationSource::new();
        $this->assertFalse($source->isCancelled());

        $source->cancel();

        $this->assertTrue($source->isCancelled());
    }

    public function testCancelIsIdempotentOnSource(): void
    {
        $source = CancellationSource::new();

        $source->cancel();
        $source->cancel();
        $source->cancel();

        $this->assertTrue($source->isCancelled());
    }

    public function testCancelPropagatesToToken(): void
    {
        $source = CancellationSource::new();
        $token = $source->token();

        $source->cancel();

        $this->assertTrue($source->isCancelled());
        $this->assertTrue($token->isCancelled());
    }

    public function testOnCancelDelegatesToToken(): void
    {
        $source = CancellationSource::new();
        $called = false;

        $source->onCancel(function () use (&$called): void {
            $called = true;
        });

        $this->assertFalse($called);
        $source->cancel();
        $this->assertTrue($called);
    }

    public function testOnCancelFiresImmediatelyWhenAlreadyCancelled(): void
    {
        $source = CancellationSource::new();
        $called = false;

        $source->cancel();

        $source->onCancel(function () use (&$called): void {
            $called = true;
        });

        $this->assertTrue($called);
    }

    public function testTokenRemainsValidAfterMultipleCancels(): void
    {
        $source = CancellationSource::new();
        $token = $source->token();

        $source->cancel();
        $source->cancel();

        $this->assertSame($source->isCancelled(), $token->isCancelled());
    }
}
