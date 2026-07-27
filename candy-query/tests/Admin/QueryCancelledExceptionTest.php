<?php

declare(strict_types=1);

namespace SugarCraft\Query\Tests\Admin;

use PHPUnit\Framework\TestCase;
use SugarCraft\Query\Admin\QueryCancelledException;

/**
 * Tests for QueryCancelledException.
 */
final class QueryCancelledExceptionTest extends TestCase
{
    public function testExceptionIsRuntimeException(): void
    {
        $exception = new QueryCancelledException();

        $this->assertInstanceOf(\RuntimeException::class, $exception);
    }

    public function testDefaultMessageIsEmpty(): void
    {
        $exception = new QueryCancelledException();

        $this->assertSame('', $exception->getMessage());
    }

    public function testDefaultCodeIsZero(): void
    {
        $exception = new QueryCancelledException();

        $this->assertSame(0, $exception->getCode());
    }

    public function testCustomMessage(): void
    {
        $exception = new QueryCancelledException('Query was cancelled by user');

        $this->assertSame('Query was cancelled by user', $exception->getMessage());
    }

    public function testCustomCode(): void
    {
        $exception = new QueryCancelledException('', 42);

        $this->assertSame(42, $exception->getCode());
    }

    public function testPreviousThrowable(): void
    {
        $previous = new \Exception('original');
        $exception = new QueryCancelledException('', 0, $previous);

        $this->assertSame($previous, $exception->getPrevious());
    }

    public function testCanBeThrownAndCaught(): void
    {
        $this->expectException(QueryCancelledException::class);

        throw new QueryCancelledException('User cancelled');
    }
}
