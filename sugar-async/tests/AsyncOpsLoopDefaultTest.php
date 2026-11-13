<?php

declare(strict_types=1);

namespace SugarCraft\Async\Tests;

use PHPUnit\Framework\TestCase;
use React\EventLoop\Loop;
use React\Promise\Deferred;
use React\Promise\PromiseInterface;
use SugarCraft\Async\AsyncOps;
use SugarCraft\Async\Tests\Support\SharedLoopClean;
use SugarCraft\Async\TimeoutException;

use function React\Promise\resolve;

/**
 * B8 (M10): withTimeout()/withDeadline() share the library-wide loop
 * convention — pass null for the loop and the helpers bind the shared
 * Loop::get(), exactly like retry()/debounce()/throttle()/pendingNotice()/boundWait().
 */
final class AsyncOpsLoopDefaultTest extends TestCase
{
    use SharedLoopClean;

    protected function tearDown(): void
    {
        $this->assertSharedLoopClean();
        parent::tearDown();
    }

    public function testWithTimeoutAcceptsNullForTheSharedLoop(): void
    {
        // Delta pin, not global-emptiness pin: compare the shared loop's
        // armed-timer identity set BEFORE and AFTER the call. A stale timer
        // from another file (file order is not ours to control) can neither
        // mask a real leak here nor fail this pin on its own.
        $before = self::scheduledTimerIds();

        $outcome = 'UNSET';
        AsyncOps::withTimeout(promise: resolve('v'), seconds: 30.0)
            ->then(static function (mixed $value) use (&$outcome): void {
                $outcome = $value;
            });

        self::assertSame('v', $outcome, 'an already-settled inner answers through the null-loop wrapper');
        self::assertSame($before, self::scheduledTimerIds(), 'the settle handler released every timer the null-loop wrapper armed');
    }

    public function testWithDeadlineAcceptsNullForTheSharedLoop(): void
    {
        $before = self::scheduledTimerIds();

        $outcome = 'UNSET';
        AsyncOps::withDeadline(promise: resolve('w'), seconds: 30.0)
            ->then(static function (mixed $value) use (&$outcome): void {
                $outcome = $value;
            });

        self::assertSame('w', $outcome);
        self::assertSame($before, self::scheduledTimerIds(), 'no timer the wrapper armed is left holding the shared loop');
    }

    public function testRetryAcceptsNullForTheSharedLoop(): void
    {
        // The combinator coverage the shared-loop residue sweep must NOT
        // lose: retry() called without a loop argument really binds
        // Loop::get() — the first attempt settles synchronously, so the
        // cycle neither arms a backoff nor leaves anything behind.
        $before = self::scheduledTimerIds();

        $attempts = 0;
        $outcome = 'UNSET';
        AsyncOps::retry(
            function () use (&$attempts): PromiseInterface {
                $attempts++;
                return resolve('r');
            },
            attempts: 3,
            baseBackoffSeconds: 0.01,
        )->then(static function (mixed $value) use (&$outcome): void {
            $outcome = $value;
        });

        self::assertSame('r', $outcome);
        self::assertSame(1, $attempts);
        self::assertSame($before, self::scheduledTimerIds(), 'a settled first-attempt retry arms nothing on the shared loop');
    }

    public function testNullLoopStillTimesOutOnTheSharedLoop(): void    {
        $loop = Loop::get();
        $error = null;
        $outcome = AsyncOps::withTimeout(promise: (new Deferred())->promise(), seconds: 0.01)
            ->then(
                static fn (mixed $value): mixed => $value,
                static function (\Throwable $e) use (&$error, $loop): void {
                    $error = $e;
                    $loop->stop();
                },
            );
        self::assertNotNull($outcome);
        $guard = $loop->addTimer(1.0, static function () use ($loop): void {
            $loop->stop();
        });
        $loop->run();
        $loop->cancelTimer($guard);
        self::assertInstanceOf(TimeoutException::class, $error);
        self::assertSame('Operation timed out after 0.01 seconds', $error->getMessage());
    }

    /**
     * Identity set of every timer armed on the shared loop right now.
     *
     * @return list<int>
     */
    private static function scheduledTimerIds(): array
    {
        $loop = (new \ReflectionProperty(Loop::class, 'instance'))->getValue();
        if ($loop === null) {
            return [];
        }

        $timersStore = (new \ReflectionProperty($loop, 'timers'))->getValue($loop);
        if (!is_object($timersStore)) {
            self::fail('the shared loop timer store is a ' . get_debug_type($timersStore) . ' — the delta pin must go red on what it cannot read');
        }

        $timers = (new \ReflectionProperty($timersStore, 'timers'))->getValue($timersStore);
        if (!is_array($timers)) {
            self::fail('the shared loop timer store holds a ' . get_debug_type($timers) . ' — the delta pin must go red on what it cannot read');
        }
        if (!is_array($timers)) {
            self::fail('the shared loop timer store holds a ' . get_debug_type($timers) . ' — the delta pin must go red on what it cannot read');
        }

        return array_values(array_map(spl_object_id(...), $timers));
    }
}
