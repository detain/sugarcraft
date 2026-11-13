<?php

declare(strict_types=1);

namespace SugarCraft\Async\Tests;

use PHPUnit\Framework\TestCase;
use React\EventLoop\LoopInterface;
use React\EventLoop\StreamSelectLoop;
use React\EventLoop\TimerInterface;
use React\Promise\Deferred;
use React\Promise\PromiseInterface;
use SugarCraft\Async\AsyncOps;
use SugarCraft\Async\TimeoutException;

use function React\Promise\reject;
use function React\Promise\resolve;

final class AsyncOpsFanInTest extends TestCase
{
    /** @var list<float> */
    private array $intervals = [];

    /** @var array<int, TimerInterface> */
    private array $live = [];

    public function test_all_resolves_empty_list(): void
    {
        $this->assertSame([], $this->outcome(AsyncOps::all([])));
    }

    public function test_all_resolves_values_in_order(): void
    {
        $result = $this->outcome(AsyncOps::all([resolve(1), resolve(2)]));
        $this->assertSame([1, 2], $result);
    }

    public function test_all_rejects_with_the_first_error(): void
    {
        $this->assertSame(
            'nope',
            $this->rejectionMessage(AsyncOps::all([resolve(1), reject(new \RuntimeException('nope'))])),
        );
    }

    public function test_race_resolves_the_first_settler(): void
    {
        $loop = new StreamSelectLoop();
        $slow = new Deferred();
        $fast = new Deferred();
        $loop->addTimer(0.03, static function () use ($fast): void {
            $fast->resolve('fast');
        });
        $guard = $loop->addTimer(1.0, static function () use ($loop): void {
            $loop->stop();
        });
        $outcome = 'UNSET';
        AsyncOps::race([$slow->promise(), $fast->promise()])->then(
            static function (mixed $value) use (&$outcome, $loop, $guard): void {
                $outcome = $value;
                $loop->cancelTimer($guard);
                $loop->stop();
            },
        );
        $loop->run();
        $this->assertSame('fast', $outcome);
    }

    public function test_race_refuses_an_empty_field(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('race() requires at least one promise');
        AsyncOps::race([]);
    }

    public function test_retry_reports_each_scheduled_backoff_to_the_hook(): void
    {
        $loop = $this->clockwork();
        $seen = [];
        $failures = 0;
        $operation = static function () use (&$failures): PromiseInterface {
            $failures++;
            return $failures < 3
                ? reject(new \RuntimeException('flaky-' . $failures))
                : resolve('ok');
        };
        $promise = AsyncOps::retry(
            $operation,
            attempts: 3,
            baseBackoffSeconds: 0.01,
            loop: $loop,
            onRetry: static function (int $attempt, float $delay) use (&$seen): void {
                $seen[] = [$attempt, $delay];
            },
        );
        $this->fireAll();
        $this->assertSame('ok', $this->outcome($promise));
        $this->assertSame([[1, 0.01], [2, 0.02]], $seen);
        $this->assertSame([0.01, 0.02], $this->intervals);
    }

    public function test_pending_notice_fires_once_and_keeps_waiting(): void
    {
        $loop = $this->clockwork();
        $inner = new Deferred();
        $notices = 0;
        $promise = AsyncOps::pendingNotice($inner->promise(), 0.5, static function () use (&$notices): void {
            $notices++;
        }, $loop);
        $this->fireAll();
        $this->assertSame(1, $notices);
        $inner->resolve('v');
        $this->assertSame('v', $this->outcome($promise));
        $this->assertSame([], $this->live);
    }

    public function test_pending_notice_never_notices_a_quick_settle(): void
    {
        $loop = $this->clockwork();
        $inner = new Deferred();
        $inner->resolve('early');
        $notices = 0;
        $promise = AsyncOps::pendingNotice($inner->promise(), 0.5, static function () use (&$notices): void {
            $notices++;
        }, $loop);
        $this->assertSame([], $this->live);
        $this->assertSame('early', $this->outcome($promise));
        $this->assertSame(0, $notices);
    }

    public function test_bound_wait_abandons_the_wait_by_timeout(): void
    {
        $loop = $this->clockwork();
        $inner = new Deferred();
        $promise = AsyncOps::boundWait($inner->promise(), 0.5, $loop);
        $this->fireAll();
        $error = null;
        $promise->then(null, static function (\Throwable $e) use (&$error): void {
            $error = $e;
        });
        $this->assertInstanceOf(TimeoutException::class, $error);
        $this->assertSame('Operation timed out after 0.5 seconds (still pending)', $error->getMessage());
        $this->assertSame(0.5, $error->seconds());
    }

    public function test_pending_notice_refuses_unbounded_notice_windows(): void
    {
        foreach ([0.0, -1.0, \NAN, \INF] as $seconds) {
            $this->expectException(\InvalidArgumentException::class);
            $this->expectExceptionMessage('Pending notice seconds must be a positive finite number');
            AsyncOps::pendingNotice(resolve(1), $seconds, static function (): void {}, $this->clockwork());
        }
    }

    public function test_bound_wait_refuses_unbounded_wait_windows(): void
    {
        foreach ([0.0, -1.0, \NAN, \INF] as $seconds) {
            $this->expectException(\InvalidArgumentException::class);
            $this->expectExceptionMessage('Bounded wait seconds must be a positive finite number');
            AsyncOps::boundWait(resolve(1), $seconds, $this->clockwork());
        }
    }

    /**
     * SA-06 BC contract: the deprecated onPending() shim must delegate
     * one-to-one — a hook lands on the pendingNotice() (advisory) arm, a
     * null hook on the boundWait() (rejection) arm — and each call raises
     * exactly one E_USER_DEPRECATED naming both settled helpers.
     */
    public function test_on_pending_shim_delegates_one_to_one_to_the_split_names(): void
    {
        $notices = [];
        \set_error_handler(static function (int $severity, string $message) use (&$notices): bool {
            $notices[] = [$severity, $message];

            return true;
        });
        try {
            $loop = $this->clockwork();
            $inner = new Deferred();
            $fired = 0;
            $advisory = AsyncOps::onPending($inner->promise(), 0.5, static function () use (&$fired): void {
                $fired++;
            }, $loop);
            $this->fireAll();
            $this->assertSame(1, $fired, 'hook arm behaves as pendingNotice()');
            $inner->resolve('v');
            $this->assertSame('v', $this->outcome($advisory), 'advisory arm never rejects on expiry');

            $loop2 = $this->clockwork();
            $hung = new Deferred();
            $bounded = AsyncOps::onPending($hung->promise(), 0.5, null, $loop2);
            $this->fireAll();
            $error = null;
            $bounded->then(null, static function (\Throwable $e) use (&$error): void {
                $error = $e;
            });
            $this->assertInstanceOf(TimeoutException::class, $error, 'null hook arm behaves as boundWait()');
            $this->assertStringContainsString('(still pending)', $error->getMessage());
        } finally {
            \restore_error_handler();
        }

        $this->assertCount(2, $notices, 'one deprecation per shim call');
        foreach ($notices as [$severity, $message]) {
            $this->assertSame(\E_USER_DEPRECATED, $severity);
            $this->assertStringContainsString('pendingNotice()', $message);
            $this->assertStringContainsString('boundWait()', $message);
            $this->assertStringContainsString('2.0.0', $message);
        }
    }

    private function clockwork(): LoopInterface
    {
        $this->intervals = [];
        $this->live = [];
        $loop = $this->createMock(LoopInterface::class);
        $loop->method('addTimer')->willReturnCallback(
            function (float $interval, callable $callback) {
                $this->intervals[] = $interval;
                $timer = new class ($interval, $callback) implements TimerInterface {
                    public function __construct(private readonly float $interval, private $callback)
                    {
                    }

                    public function getInterval(): float
                    {
                        return $this->interval;
                    }

                    public function getCallback(): callable
                    {
                        return $this->callback;
                    }

                    public function isPeriodic(): bool
                    {
                        return false;
                    }
                };
                $this->live[spl_object_id($timer)] = $timer;
                return $timer;
            },
        );
        $loop->method('cancelTimer')->willReturnCallback(
            function (TimerInterface $timer): void {
                unset($this->live[spl_object_id($timer)]);
            },
        );
        return $loop;
    }

    private function fireAll(): void
    {
        while ($this->live !== []) {
            $id = array_key_first($this->live);
            $timer = $this->live[$id];
            unset($this->live[$id]);
            ($timer->getCallback())($timer);
        }
    }

    private function outcome(PromiseInterface $promise): mixed
    {
        $outcome = 'UNSET';
        $promise->then(static function (mixed $value) use (&$outcome): void {
            $outcome = $value;
        });
        $this->assertNotSame('UNSET', $outcome);
        return $outcome;
    }

    private function rejectionMessage(PromiseInterface $promise): string
    {
        $message = 'UNSET';
        $promise->then(null, static function (\Throwable $e) use (&$message): void {
            $message = $e->getMessage();
        });
        $this->assertNotSame('UNSET', $message);
        return $message;
    }
}
