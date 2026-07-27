<?php

declare(strict_types=1);

namespace SugarCraft\Query\Tests\Db;

use PHPUnit\Framework\TestCase;
use SugarCraft\Query\Db\ReconnectException;

/**
 * Tests for ReconnectException.
 */
final class ReconnectExceptionTest extends TestCase
{
    public function testExceptionStoresOriginalPDOException(): void
    {
        $original = new \PDOException('Connection lost');
        $exception = new ReconnectException('Reconnect failed', $original);

        $this->assertSame($original, $exception->getOriginal());
    }

    public function testExceptionMessageIsPreserved(): void
    {
        $original = new \PDOException('Original error');
        $exception = new ReconnectException('Reconnect attempt failed', $original);

        $this->assertSame('Reconnect attempt failed', $exception->getMessage());
    }

    public function testExceptionCodeDefaultsToZero(): void
    {
        $original = new \PDOException('Error');
        $exception = new ReconnectException('Failed', $original);

        $this->assertSame(0, $exception->getCode());
    }

    public function testExceptionCodeCanBeSet(): void
    {
        $original = new \PDOException('Error');
        $exception = new ReconnectException('Failed', $original, 42);

        $this->assertSame(42, $exception->getCode());
    }

    public function testPreviousThrowableIsSet(): void
    {
        $previous = new \RuntimeException('Previous error');
        $original = new \PDOException('PDO error', 0, $previous);
        $exception = new ReconnectException('Failed', $original, 0, $previous);

        $this->assertSame($previous, $exception->getPrevious());
    }

    public function testExceptionIsRuntimeException(): void
    {
        $exception = new ReconnectException('Test', new \PDOException('Error'));

        $this->assertInstanceOf(\RuntimeException::class, $exception);
    }

    public function testCanBeThrownAndCaught(): void
    {
        $this->expectException(ReconnectException::class);

        throw new ReconnectException('Connection lost', new \PDOException('Original'));
    }
}
