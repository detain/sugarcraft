<?php

declare(strict_types=1);

namespace SugarCraft\Async\Tests;

use PHPUnit\Framework\TestCase;
use React\EventLoop\Loop;
use React\Promise\Deferred;
use React\Promise\PromiseInterface;
use SugarCraft\Async\AsyncOps;
use SugarCraft\Async\TimeoutException;

use function React\Promise\resolve;

/**
 * B8 (M10): withTimeout()/withDeadline() share the library-wide loop
 * convention — pass null for the loop and the helpers bind the shared
 * Loop::get(), exactly like retry()/debounce()/throttle()/onPending().
 */
final class AsyncOpsLoopDefaultTest extends TestCase
{
    public function testWithTimeoutAcceptsNullForTheSharedLoop(): void
    {
        $outcome = 'UNSET';
        AsyncOps::withTimeout(promise: resolve('v'), seconds: 30.0)
            ->then(static function (mixed $value) use (&$outcome): void {
                $outcome = $value;
            });
        self::assertSame('v', $outcome, 'an already-settled inner answers through the null-loop wrapper');
        self::assertSame([], self::scheduledTimers(), 'the settle handler released the shared loop');
    }

    public function testWithDeadlineAcceptsNullForTheSharedLoop(): void
    {
        $outcome = 'UNSET';
        AsyncOps::withDeadline(promise: resolve('w'), seconds: 30.0)
            ->then(static function (mixed $value) use (&$outcome): void {
                $outcome = $value;
            });
        self::assertSame('w', $outcome);
        self::assertSame([], self::scheduledTimers(), 'no timer is left holding the shared loop');
    }

    public function testNullLoopStillTimesOutOnTheSharedLoop(): void
    {
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

    /** @return list<mixed> */
    private static function scheduledTimers(): array
    {
        $loop = (new \ReflectionProperty(Loop::class, 'instance'))->getValue();
        if ($loop === null) {
            return [];
        }
        try {
            $property = new \ReflectionProperty($loop, 'timers');
        } catch (\ReflectionException) {
            return [];
        }
        $timers = $property->getValue($loop);
        if (is_object($timers) && method_exists($timers, 'isEmpty')) {
            return $timers->isEmpty() ? [] : ['nonempty'];
        }
        return is_array($timers) && $timers === [] ? [] : ['nonempty'];
    }
}
