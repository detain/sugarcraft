<?php

declare(strict_types=1);

namespace SugarCraft\Async\Tests;

use PHPUnit\Framework\TestCase;
use React\Promise\Deferred;
use React\Promise\PromiseInterface;
use SugarCraft\Async\AsyncOps;

use function React\Promise\reject;
use function React\Promise\resolve;

/**
 * Guards the request-coalescing semantics ported from phlix's
 * ApiClient::refreshInFlight (port P6): concurrent calls sharing a key receive
 * ONE worker invocation and the same promise; the slot is freed exactly on
 * settle — success, failure, or synchronous throw — so the next wave re-runs
 * the worker (this is coalescing, not caching). No timers anywhere: the whole
 * contract is observable in one synchronous pass.
 *
 * @covers \SugarCraft\Async\AsyncOps::singleFlight
 */
final class AsyncOpsSingleFlightTest extends TestCase
{
    public function testConcurrentCallersShareOneWorkerInvocation(): void
    {
        $inFlight = new Deferred();
        $calls = 0;
        $run = AsyncOps::singleFlight(
            static fn (string $user): string => "refresh:$user",
            static function () use (&$calls, $inFlight): PromiseInterface {
                $calls++;
                return $inFlight->promise();
            },
        );

        $first = $run('alice');
        $second = $run('alice');

        $this->assertSame($first, $second, 'joiners receive the identical in-flight promise');
        $this->assertSame(1, $calls);

        $values = [];
        $first->then(static function ($v) use (&$values): void {
            $values[] = $v;
        });
        $second->then(static function ($v) use (&$values): void {
            $values[] = $v;
        });
        $inFlight->resolve('token');

        $this->assertSame(['token', 'token'], $values, 'one worker result feeds every joined caller');
    }

    public function testSlotFreesOnSettleAndTheNextWaveRerunsTheWorker(): void
    {
        $calls = 0;
        $run = AsyncOps::singleFlight(
            static fn (): string => 'k',
            static function () use (&$calls): PromiseInterface {
                $calls++;
                return resolve("wave $calls");
            },
        );

        $first = null;
        $run()->then(static function ($v) use (&$first): void {
            $first = $v;
        });
        $second = null;
        $run()->then(static function ($v) use (&$second): void {
            $second = $v;
        });

        $this->assertSame(2, $calls, 'a settled flight must not be reused — coalescing, not caching');
        $this->assertSame('wave 1', $first);
        $this->assertSame('wave 2', $second);
    }

    public function testFailureFreesTheSlot(): void
    {
        $calls = 0;
        $run = AsyncOps::singleFlight(
            static fn (): string => 'k',
            static function () use (&$calls): PromiseInterface {
                $calls++;
                return $calls === 1
                    ? reject(new \RuntimeException('boom'))
                    : resolve('recovered');
            },
        );

        $error = null;
        $run()->then(null, static function (\Throwable $e) use (&$error): void {
            $error = $e;
        });
        $this->assertInstanceOf(\RuntimeException::class, $error);

        $value = null;
        $run()->then(static function ($v) use (&$value): void {
            $value = $v;
        });
        $this->assertSame('recovered', $value, 'a failed flight must leave no stuck slot behind');
        $this->assertSame(2, $calls);
    }

    public function testSynchronousWorkerThrowRejectsAndFreesSlot(): void
    {
        $calls = 0;
        $run = AsyncOps::singleFlight(
            static fn (): string => 'k',
            static function () use (&$calls): PromiseInterface {
                $calls++;
                if ($calls === 1) {
                    throw new \LogicException('worker exploded before returning');
                }
                return resolve('ok');
            },
        );

        $error = null;
        $run()->then(null, static function (\Throwable $e) use (&$error): void {
            $error = $e;
        });

        $this->assertInstanceOf(\LogicException::class, $error);

        $value = null;
        $run()->then(static function ($v) use (&$value): void {
            $value = $v;
        });
        $this->assertSame('ok', $value, 'slot was freed by the synchronous throw');
    }

    public function testKeysIsolateFlights(): void
    {
        $a = new Deferred();
        $b = new Deferred();
        $calls = [];
        $run = AsyncOps::singleFlight(
            static fn (string $user): string => $user,
            static function (string $user) use (&$calls, $a, $b): PromiseInterface {
                $calls[] = $user;
                return $user === 'a' ? $a->promise() : $b->promise();
            },
        );

        $fromA = $run('a');
        $fromB = $run('b');
        $againA = $run('a');

        $this->assertSame(['a', 'b'], $calls, 'distinct keys run distinct workers; a repeat key joins the flight');
        $this->assertSame($fromA, $againA);
        $this->assertNotSame($fromA, $fromB);
    }

    public function testEmptyKeyThrows(): void
    {
        $run = AsyncOps::singleFlight(
            static fn (): string => '',
            static fn (): PromiseInterface => resolve('x'),
        );

        $this->expectException(\InvalidArgumentException::class);
        $run();
    }

    public function testWorkerReturningNonPromiseFailsLoudlyAndFreesSlot(): void
    {
        $calls = 0;
        $run = AsyncOps::singleFlight(
            static fn (): string => 'k',
            /** @phpstan-ignore-next-line intentional contract violation fixture */
            static function () use (&$calls) {
                $calls++;
                if ($calls === 1) {
                    return 'not a promise';
                }
                return resolve('fine');
            },
        );

        $error = null;
        $run()->then(null, static function (\Throwable $e) use (&$error): void {
            $error = $e;
        });

        $this->assertInstanceOf(\Throwable::class, $error, 'a non-promise return must reject, never strand the caller');

        $value = null;
        $run()->then(static function ($v) use (&$value): void {
            $value = $v;
        });
        $this->assertSame('fine', $value, 'the bad flight must not occupy the slot');
    }
}
