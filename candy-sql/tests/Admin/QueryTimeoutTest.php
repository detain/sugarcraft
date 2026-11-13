<?php

declare(strict_types=1);

namespace SugarCraft\Sql\Tests\Admin;

use PHPUnit\Framework\TestCase;
use React\EventLoop\Loop;
use React\EventLoop\StreamSelectLoop;
use React\Promise\Deferred;
use React\Promise\Promise;
use SugarCraft\Sql\Admin\QueryTimeout;
use SugarCraft\Sql\Tests\Support\FakeTickLoop;

use function React\Promise\resolve;

/**
 * Guards the async-query deadline semantics: a query promise that never
 * settles (network partition — react/mysql and PgAsync only reject on an
 * observed socket error) must reject with a clear timeout message instead of
 * hanging the admin tick's single-in-flight gate forever; a disabled timeout
 * must not interpose at all; and a promise that settles in time must pass its
 * value through untouched.
 *
 * SQL-P12 (adminpages-014): these legs used to arm deadlines on the SHARED
 * Loop::get() with no clock pin — the exact ext-uv clock-staleness flake
 * class AGENTS.md names — and drain it with a blocking Loop::run(). Now the
 * suite pins the stable clock from tests/bootstrap.php, the behavioral legs
 * drive a FakeTickLoop (deterministic delivery, no sleeping, and cancel-on-
 * settle is OBSERVABLE as zero live timers), and the single real-signal
 * integration leg runs on its own fresh StreamSelectLoop instead of the
 * shared one.
 */
final class QueryTimeoutTest extends TestCase
{
    private static ?int $sharedTimersAtStart = null;

    public static function setUpBeforeClass(): void
    {
        self::$sharedTimersAtStart = self::sharedTimerCount();
    }

    private static function sharedTimerCount(): ?int
    {
        $loop = Loop::get();
        $ref = new \ReflectionObject($loop);
        if (!$ref->hasProperty('timers')) {
            return null;
        }
        $timers = $ref->getProperty('timers')->getValue($loop);

        return count((new \ReflectionObject($timers))->getProperty('timers')->getValue($timers));
    }

    /**
     * The one real-signal integration leg: a kernel timer actually expires.
     * A file-scoped fresh loop keeps it off the shared Loop::get() — nothing
     * else's deadlines (or a stale uv clock) can perturb it, and it leaves no
     * residue on the shared loop by construction.
     */
    public function testPendingPromiseRejectsWithAClearErrorOnceTheDeadlinePasses(): void
    {
        // Never settles and cancels to a no-op — models a dropped route.
        $never = new Promise(static function (): void {}, static function (): void {});

        $loop = new StreamSelectLoop();
        $error = null;
        QueryTimeout::wrap($never, 0.05, $loop)->then(
            null,
            static function (\Throwable $e) use (&$error): void {
                $error = $e;
            },
        );

        // Runs until no timers remain: the 0.05s deadline fires and rejects.
        $loop->run();

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

        $loop = new FakeTickLoop();
        $kills = 0;
        $error = null;
        QueryTimeout::wrap($never, 5.0, $loop, static function () use (&$kills): void {
            $kills++;
            throw new \LogicException('kill failed — must not mask the timeout');
        })->then(
            null,
            static function (\Throwable $e) use (&$error): void {
                $error = $e;
            },
        );

        $this->assertSame(1, $loop->tickDue(), 'the wrapped deadline armed exactly one timer');

        $this->assertSame(1, $kills, 'onTimeout hook fires exactly once on deadline');
        $this->assertInstanceOf(\RuntimeException::class, $error);
        $this->assertStringContainsString('Async query timed out after', $error->getMessage());
    }

    public function testOnTimeoutHookDoesNotFireWhenThePromiseSettlesInTime(): void
    {
        $loop = new FakeTickLoop();
        $kills = 0;
        $rows = null;
        QueryTimeout::wrap(resolve([['ok' => 1]]), 5.0, $loop, static function () use (&$kills): void {
            $kills++;
        })->then(static function (array $value) use (&$rows): void {
            $rows = $value;
        });

        // A settled query must have CANCELLED its deadline — nothing due.
        $this->assertSame(0, $loop->tickDue(), 'a settled leg leaves no armed deadline');
        $this->assertSame(0, $loop->liveTimerCount(), 'the loop carries no timer residue');

        $this->assertSame([['ok' => 1]], $rows);
        $this->assertSame(0, $kills, 'a query that finished in time must never be killed');
    }

    public function testPromiseSettlingBeforeTheDeadlinePassesItsValueThrough(): void
    {
        $loop = new FakeTickLoop();
        $rows = null;
        QueryTimeout::wrap(resolve([['Variable_name' => 'Uptime', 'Value' => '42']]), 5.0, $loop)
            ->then(static function (array $value) use (&$rows): void {
                $rows = $value;
            });

        // resolve() settles synchronously; the deadline timer is cancelled at
        // arm time and the value already stands.
        $this->assertSame(0, $loop->tickDue());
        $this->assertSame([['Variable_name' => 'Uptime', 'Value' => '42']], $rows);
    }

    /**
     * The shared loop the rest of the process runs on must be exactly as
     * this file found it — the residue that used to bleed 0.05 s/30 s deadline
     * timers onto Loop::get() for whichever test ran next. Snapshot at class
     * start keeps the pin file-scoped (alphabetically earlier files' own
     * loops are not this file's business).
     */
    public function testSuiteLeavesNoTimersOnTheSharedLoop(): void
    {
        $now = self::sharedTimerCount();
        if ($now === null) {
            $this->markTestSkipped('loop implementation exposes no timer inventory');
        }

        $this->assertSame(self::$sharedTimersAtStart, $now, 'deadline legs must never arm the shared loop');
    }
}
