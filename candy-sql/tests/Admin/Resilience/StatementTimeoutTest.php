<?php

declare(strict_types=1);

namespace SugarCraft\Sql\Tests\Admin\Resilience;

use PHPUnit\Framework\TestCase;
use SugarCraft\Sql\Admin\Resilience\StatementTimeout;
use SugarCraft\Sql\Admin\Resilience\StatementTimeoutException;

/**
 * Tests for StatementTimeout.
 */
final class StatementTimeoutTest extends TestCase
{
    public function testExecuteWithoutPcntlDegradesGracefully(): void
    {
        // Without pcntl, execute should just run normally (no alarm, no
        // timeout). Forced via the constructor override so this path is
        // exercised even on hosts where pcntl is loaded.
        $mockStmt = $this->createMock(\PDOStatement::class);
        $mockStmt->method('execute')->willReturn(true);

        $timeout = new StatementTimeout(timeoutSeconds: 1, pcntlAvailable: false);
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

    public function testRunReturnsTheWorkValueUnderBudget(): void
    {
        $timeout = new StatementTimeout(timeoutSeconds: 1, pcntlAvailable: false);

        $this->assertSame(
            [['id' => 7]],
            $timeout->run(static fn (): array => [['id' => 7]]),
            'the callable seam must hand the work result straight back',
        );
        $this->assertFalse($timeout->didTimeout());
    }

    public function testRunCutsWorkPastTheBudgetWithANamedError(): void
    {
        if (!function_exists('pcntl_alarm')) {
            $this->markTestSkipped('pcntl_alarm is not available');
        }

        $timeout = new StatementTimeout(timeoutSeconds: 1);

        $start = microtime(true);
        try {
            $timeout->run(static function (): bool {
                usleep(1_500_000);
                return true;
            });
            $this->fail('the wall-clock budget must fire inside ~1 s');
        } catch (StatementTimeoutException $e) {
            $this->assertStringContainsString('timed out after 1 s', $e->getMessage());
        }
        $this->assertLessThan(3.0, microtime(true) - $start);
        $this->assertTrue($timeout->didTimeout());
    }

    public function testRunDegradesTransparentlyWithoutPcntl(): void
    {
        // Windows rider: no alarm layer, no exception — the work simply runs
        // and its value arrives. The transport-level network timeouts are the
        // remaining bound in this mode (documented in the class docblock).
        $timeout = new StatementTimeout(timeoutSeconds: 1, pcntlAvailable: false);

        $result = $timeout->run(static function (): string {
            usleep(50_000);
            return 'done';
        });

        $this->assertSame('done', $result);
        $this->assertFalse($timeout->didTimeout());
    }

    public function testDefaultBudgetIsTheSharedThirtySecondSeam(): void
    {
        $this->assertSame(30, StatementTimeout::DEFAULT_SECONDS);

        $ref = new \ReflectionMethod(StatementTimeout::class, '__construct');
        $param = $ref->getParameters()[0];
        $this->assertSame(StatementTimeout::DEFAULT_SECONDS, $param->getDefaultValue());
    }
}
