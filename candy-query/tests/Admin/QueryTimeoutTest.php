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
