<?php

declare(strict_types=1);

namespace SugarCraft\Query\Tests\Admin;

use PHPUnit\Framework\TestCase;
use React\EventLoop\Loop;
use React\Promise\Deferred;
use React\Promise\Promise;
use SugarCraft\Query\Admin\QueryTimeout;

use function React\Promise\resolve;

/**
 * Guards the async-query deadline semantics: a query promise that never
 * settles (network partition — react/mysql and PgAsync only reject on an
 * observed socket error) must reject with a clear timeout message instead of
 * hanging the admin tick's single-in-flight gate forever; a disabled timeout
 * must not interpose at all; and a promise that settles in time must pass its
 * value through untouched.
 */
final class QueryTimeoutTest extends TestCase
{
    public function testPendingPromiseRejectsWithAClearErrorOnceTheDeadlinePasses(): void
    {
        // Never settles and cancels to a no-op — models a dropped route.
        $never = new Promise(static function (): void {}, static function (): void {});

        $error = null;
        QueryTimeout::wrap($never, 0.05, Loop::get())->then(
            null,
            static function (\Throwable $e) use (&$error): void {
                $error = $e;
            },
        );

        // Runs until no timers remain: the 0.05s deadline fires and rejects.
        Loop::run();

        $this->assertInstanceOf(\RuntimeException::class, $error);
        $this->assertStringContainsString('Async query timed out after', $error->getMessage());
    }

    public function testZeroTimeoutDisablesTheDeadlineEntirely(): void
    {
        $deferred = new Deferred();

        $this->assertSame(
            $deferred->promise(),
            QueryTimeout::wrap($deferred->promise(), 0.0),
            'a disabled timeout must return the promise unwrapped — no timer, no rejection path',
        );

        $deferred->resolve([]);
    }

    /**
     * Timeout → cancellation wiring (plan 5.1): when the deadline fires, the
     * onTimeout hook must run so the connection can KILL the query server-side
     * — a timed-out query must not keep burning the server. The timeout
     * rejection itself must still reach the caller even if the hook throws.
     */
    public function testDeadlineFiresTheOnTimeoutHookAndStillRejects(): void
    {
        $never = new Promise(static function (): void {}, static function (): void {});

        $kills = 0;
        $error = null;
        QueryTimeout::wrap($never, 0.05, Loop::get(), static function () use (&$kills): void {
            $kills++;
            throw new \LogicException('kill failed — must not mask the timeout');
        })->then(
            null,
            static function (\Throwable $e) use (&$error): void {
                $error = $e;
            },
        );

        Loop::run();

        $this->assertSame(1, $kills, 'onTimeout hook fires exactly once on deadline');
        $this->assertInstanceOf(\RuntimeException::class, $error);
        $this->assertStringContainsString('Async query timed out after', $error->getMessage());
    }

    public function testOnTimeoutHookDoesNotFireWhenThePromiseSettlesInTime(): void
    {
        $kills = 0;
        $rows = null;
        QueryTimeout::wrap(resolve([['ok' => 1]]), 30.0, Loop::get(), static function () use (&$kills): void {
            $kills++;
        })->then(static function (array $value) use (&$rows): void {
            $rows = $value;
        });

        Loop::run();

        $this->assertSame([['ok' => 1]], $rows);
        $this->assertSame(0, $kills, 'a query that finished in time must never be killed');
    }

    public function testPromiseSettlingBeforeTheDeadlinePassesItsValueThrough(): void
    {
        $rows = null;
        QueryTimeout::wrap(resolve([['Variable_name' => 'Uptime', 'Value' => '42']]), 30.0, Loop::get())
            ->then(static function (array $value) use (&$rows): void {
                $rows = $value;
            });

        // Drain anything promise-timer scheduled; the value must already be set
        // (resolve() settles synchronously) and the deadline timer cancelled.
        Loop::run();

        $this->assertSame([['Variable_name' => 'Uptime', 'Value' => '42']], $rows);
    }
}
