<?php

declare(strict_types=1);

namespace SugarCraft\Query\Tests\Admin\Resilience;

use PHPUnit\Framework\TestCase;
use SugarCraft\Query\Admin\Resilience\StatementTimeout;
use SugarCraft\Query\Admin\Resilience\StatementTimeoutException;

/**
 * Tests for StatementTimeout.
 */
final class StatementTimeoutTest extends TestCase
{
    public function testExecuteWithoutPcntlDegradesGracefully(): void
    {
        // If pcntl is not available, execute should just run normally
        if (function_exists('pcntl_alarm')) {
            $this->markTestSkipped('pcntl_alarm is available, cannot test graceful degradation');
        }

        $mockStmt = $this->createMock(\PDOStatement::class);
        $mockStmt->method('execute')->willReturn(true);

        $timeout = new StatementTimeout(timeoutSeconds: 1);
        $result = $timeout->execute($mockStmt, []);

        $this->assertTrue($result);
        $this->assertFalse($timeout->didTimeout());
    }

    public function testDidTimeoutReturnsFalseAfterSuccessfulExecution(): void
    {
        if (!function_exists('pcntl_alarm')) {
            $this->markTestSkipped('pcntl_alarm is not available');
        }

        $mockStmt = $this->createMock(\PDOStatement::class);
        $mockStmt->method('execute')->willReturn(true);

        $timeout = new StatementTimeout(timeoutSeconds: 30);
        $timeout->execute($mockStmt, []);

        $this->assertFalse($timeout->didTimeout());
    }

    public function testTimeoutExceptionIsThrownOnTimeout(): void
    {
        if (!function_exists('pcntl_alarm')) {
            $this->markTestSkipped('pcntl_alarm is not available');
        }

        $mockStmt = $this->createMock(\PDOStatement::class);
        // Long-running query that exceeds our 1 second timeout
        $mockStmt->method('execute')->willReturnCallback(function (): bool {
            usleep(1500000); // 1.5 seconds - exceeds 1 second timeout
            return true;
        });

        $timeout = new StatementTimeout(timeoutSeconds: 1);

        $this->expectException(StatementTimeoutException::class);
        $this->expectExceptionMessageMatches('/timed out/i');

        $timeout->execute($mockStmt, []);
    }

    public function testCustomTimeoutSeconds(): void
    {
        if (!function_exists('pcntl_alarm')) {
            $this->markTestSkipped('pcntl_alarm is not available');
        }

        $mockStmt = $this->createMock(\PDOStatement::class);
        // This won't timeout because we're using a very long timeout
        $mockStmt->method('execute')->willReturn(true);

        $timeout = new StatementTimeout(timeoutSeconds: 300);
        $result = $timeout->execute($mockStmt, []);

        $this->assertTrue($result);
    }

    public function testDidTimeoutReturnsTrueAfterTimeout(): void
    {
        if (!function_exists('pcntl_alarm')) {
            $this->markTestSkipped('pcntl_alarm is not available');
        }

        $mockStmt = $this->createMock(\PDOStatement::class);
        $mockStmt->method('execute')->willReturnCallback(function (): void {
            usleep(100000); // 100ms - this won't exceed our short timeout
            throw new StatementTimeoutException('Statement execution timed out');
        });

        $timeout = new StatementTimeout(timeoutSeconds: 1);

        try {
            $timeout->execute($mockStmt, []);
        } catch (StatementTimeoutException) {
            // Expected
        }

        $this->assertTrue($timeout->didTimeout());
    }
}
