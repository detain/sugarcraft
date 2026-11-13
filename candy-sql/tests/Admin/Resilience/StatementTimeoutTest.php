<?php

declare(strict_types=1);

namespace SugarCraft\Sql\Tests\Admin\Resilience;

use PHPUnit\Framework\TestCase;
use SugarCraft\Sql\Admin\Resilience\StatementTimeout;
use SugarCraft\Sql\Admin\Resilience\StatementTimeoutException;

/**
 * Tests for StatementTimeout.
 *
 * SQL-P12 (adminpages-014/-027): the timeout legs used to be hard `usleep`
 * wall-clock shows (~3.1 s of sleeping per file, load-sensitive) and the
 * didTimeout leg asserted its own mock's throw — it passed even with the
 * production alarm wiring broken. The legs now ride the alarm transport
 * seam (constructor $alarm): the REAL SIGALRM handler is armed by the REAL
 * run() bookkeeping and fired by the test double instead of the kernel, so
 * every assertion below can fail against production logic. Exactly one
 * real-signal integration leg remains ({@see
 * testTimeoutExceptionIsThrownOnTimeout}) to prove the pcntl transport the
 * seam stands in for.
 */
final class StatementTimeoutTest extends TestCase
{
    /**
     * Alarm transport double: captures the production handler so the test
     * fires it at the exact moment a real SIGALRM would have, and counts
     * disarms so the arm/disarm bookkeeping is observable.
     *
     * @param callable(int):void|null $capturedHandler
     * @return array{0: callable(int, callable(int):void): callable, 1: \stdClass}
     */
    private function seam(?callable &$capturedHandler = null): array
    {
        $state = new \stdClass();
        $state->armedFor = null;
        $state->disarms = 0;
        $capturedHandler = null;

        $alarm = static function (int $seconds, callable $handler) use (&$capturedHandler, $state): callable {
            $state->armedFor = $seconds;
            $capturedHandler = $handler;

            return static function () use ($state): void {
                $state->disarms++;
            };
        };

        return [$alarm, $state];
    }

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

        [$alarm, $state] = $this->seam($handler);
        $timeout = new StatementTimeout(timeoutSeconds: 30, alarm: $alarm);
        $timeout->execute($mockStmt, []);

        $this->assertFalse($timeout->didTimeout());
        $this->assertSame(30, $state->armedFor, 'the budget reaches the transport as seconds');
        $this->assertSame(1, $state->disarms, 'a settled run disarms exactly once');
        $this->assertNotNull($handler, 'non-vacuity: run() handed the production handler to the transport');
    }

    /**
     * The ONE real-signal integration leg: pcntl_alarm() actually expires
     * mid-work and the kernel-delivered SIGALRM runs the production handler.
     * Everything below fakes only the delivery, never the wiring.
     */
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

    /**
     * adminpages-027 replacement: didTimeout must be produced by the real
     * alarm bookkeeping — production handler throws inside work(), run()
     * catches, stamps, rethrows — not by a mock that threw its own fake.
     */
    public function testDidTimeoutReflectsProductionAlarmFiring(): void
    {
        if (!defined('SIGALRM')) {
            $this->markTestSkipped('pcntl signal constants are not available');
        }

        [$alarm, $state] = $this->seam($handler);
        $timeout = new StatementTimeout(timeoutSeconds: 1, alarm: $alarm);

        try {
            $timeout->run(static function () use (&$handler): bool {
                // Stand-in for the kernel delivering SIGALRM mid-work: the
                // handler here IS the closure StatementTimeout::run built.
                $handler(SIGALRM);

                return true;
            });
            $this->fail('the production handler must cut the work');
        } catch (StatementTimeoutException $e) {
            $this->assertStringContainsString('timed out after 1 s', $e->getMessage(), 'the named error comes from production string-building');
        }

        $this->assertTrue($timeout->didTimeout());
        $this->assertSame(1, $state->disarms, 'the cut run still disarms');
    }

    public function testDidTimeoutFlagResetsPerRun(): void
    {
        if (!defined('SIGALRM')) {
            $this->markTestSkipped('pcntl signal constants are not available');
        }

        [$alarm, ] = $this->seam($handler);
        $timeout = new StatementTimeout(timeoutSeconds: 1, alarm: $alarm);

        try {
            $timeout->run(static function () use (&$handler): void {
                $handler(SIGALRM);
            });
        } catch (StatementTimeoutException) {
            // Expected
        }
        $this->assertTrue($timeout->didTimeout());

        // A subsequent bounded work must not inherit the previous verdict.
        $timeout->run(static fn (): bool => true);
        $this->assertFalse($timeout->didTimeout(), 'didTimeout describes the LAST run');
    }

    public function testSeamDoesNotSwallowForeignErrors(): void
    {
        if (!function_exists('pcntl_alarm')) {
            $this->markTestSkipped('pcntl_alarm is not available');
        }

        [$alarm, $state] = $this->seam($handler);
        $timeout = new StatementTimeout(timeoutSeconds: 1, alarm: $alarm);

        try {
            $timeout->run(static function (): void {
                throw new \RuntimeException('foreign');
            });
            $this->fail('foreign throwables must propagate');
        } catch (\RuntimeException $e) {
            $this->assertSame('foreign', $e->getMessage());
        }

        $this->assertFalse($timeout->didTimeout(), 'a foreign error is not a timeout');
        $this->assertSame(1, $state->disarms);
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
        if (!defined('SIGALRM')) {
            $this->markTestSkipped('pcntl signal constants are not available');
        }

        [$alarm, $state] = $this->seam($handler);
        $timeout = new StatementTimeout(timeoutSeconds: 1, alarm: $alarm);

        try {
            $timeout->run(static function () use (&$handler): bool {
                $handler(SIGALRM); // the overdue digest leg, delivered at zero cost

                return true;
            });
            $this->fail('the wall-clock budget must fire');
        } catch (StatementTimeoutException $e) {
            $this->assertStringContainsString('timed out after 1 s', $e->getMessage());
        }

        $this->assertTrue($timeout->didTimeout());
        $this->assertSame(1, $state->armedFor);
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
