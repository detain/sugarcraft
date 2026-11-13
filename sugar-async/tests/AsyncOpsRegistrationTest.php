<?php

declare(strict_types=1);

namespace SugarCraft\Async\Tests;

use PHPUnit\Framework\TestCase;
use React\EventLoop\LoopInterface;
use React\EventLoop\TimerInterface;
use React\Promise\Deferred;
use React\Promise\PromiseInterface;
use ReflectionObject;
use SugarCraft\Async\AsyncOps;
use SugarCraft\Async\CancellationSource;
use SugarCraft\Async\CancellationToken;
use SugarCraft\Async\MessageFormat;

/**
 * Registration hygiene for the cancellation surface (M7/M18 completion):
 * wrappers drop their hold on a shared token the moment the operation
 * settles, none() is the process-wide never-cancelled default, and the
 * consolidated duration formatter keeps sub-microsecond values visible
 * instead of collapsing them to zero.
 */
final class AsyncOpsRegistrationTest extends TestCase
{
    /** @var list<float> */
    private array $intervals = [];

    public function testNoneIsMemoisedAndNeverCancelled(): void
    {
        self::assertSame(CancellationToken::none(), CancellationToken::none());
        self::assertFalse(CancellationToken::none()->isCancelled());
    }

    public function testNoneNeverRetainsNorFiresRegistrations(): void
    {
        $fired = 0;
        $handle = CancellationToken::none()->onCancel(static function () use (&$fired): void {
            $fired++;
        });

        self::assertSame(0, $fired);
        self::assertFalse($handle->isActive(), 'Handles from none() are born spent.');
    }

    public function testNoneRefusesCancellationThroughBothInternalDoors(): void
    {
        $token = CancellationToken::none();

        $this->expectException(\LogicException::class);
        $token->notifyCancellation();
    }

    public function testNoneRefusesDirectFire(): void
    {
        $token = CancellationToken::none();

        try {
            $token->fireCallbacks();
            self::fail('fireCallbacks() on none() must refuse.');
        } catch (\LogicException) {
            // Expected.
        }

        self::assertFalse($token->isCancelled(), 'Refusal must not mutate the sentinel.');
    }

    public function testRetryDropsItsTokenMirrorOnceTheCycleSettles(): void
    {
        $loop = $this->immediateLoop();
        $source = CancellationSource::new();

        $failures = 1;
        $operation = static function () use (&$failures): PromiseInterface {
            $deferred = new Deferred();
            if ($failures-- > 0) {
                $deferred->reject(new \RuntimeException('transient'));
            } else {
                $deferred->resolve('ok');
            }

            return $deferred->promise();
        };

        $promise = AsyncOps::retry($operation, attempts: 2, baseBackoffSeconds: 0.01, loop: $loop, token: $source->token());
        $settled = null;
        $promise->then(static function (mixed $value) use (&$settled): void {
            $settled = $value;
        });

        self::assertSame('ok', $settled);
        self::assertSame([], $this->callbacksOf($source->token()), 'Settled retries must leave no registration on a shared token.');
    }

    public function testCancellableDropsItsRegistrationAndNeverTouchesSettledInner(): void
    {
        $innerCancelled = 0;
        $innerDeferred = new Deferred(static function () use (&$innerCancelled): void {
            $innerCancelled++;
        });
        $source = CancellationSource::new();

        $wrapper = AsyncOps::cancellable($innerDeferred->promise(), $source->token());

        $innerDeferred->resolve('done');

        self::assertSame([], $this->callbacksOf($source->token()));
        self::assertSame(0, $innerCancelled, 'A settled inner promise must not be cancelled by wrapper cleanup.');

        $source->cancel();
        self::assertSame(0, $innerCancelled);
    }

    public function testSubMicrosecondDurationsStayVisible(): void
    {
        $loop = $this->immediateLoop();
        $deferred = new Deferred();

        $error = null;
        AsyncOps::withTimeout(promise: $deferred->promise(), seconds: 1e-9, loop: $loop)
            ->then(null, static function (\Throwable $e) use (&$error): void {
                $error = $e;
            });

        self::assertInstanceOf(\SugarCraft\Async\TimeoutException::class, $error);
        self::assertSame('Operation timed out after 0.000001 seconds', $error->getMessage());
        self::assertSame('0.000001', MessageFormat::seconds(1e-9));
    }

    /**
     * @return array<int, callable>
     */
    private function callbacksOf(CancellationToken $token): array
    {
        $property = (new ReflectionObject($token))->getProperty('callbacks');
        $property->setAccessible(true);

        /** @var array<int, callable> $callbacks */
        $callbacks = $property->getValue($token);

        return $callbacks;
    }

    private function immediateLoop(): LoopInterface
    {
        $loop = $this->createMock(LoopInterface::class);
        $this->intervals = [];
        $loop->method('addTimer')->willReturnCallback(
            function (float $interval, callable $callback): TimerInterface {
                $this->intervals[] = $interval;
                $callback();

                return $this->createStub(TimerInterface::class);
            },
        );
        $loop->method('cancelTimer')->willReturn(null);

        return $loop;
    }
}
