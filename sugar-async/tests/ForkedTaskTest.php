<?php

declare(strict_types=1);

namespace SugarCraft\Async\Tests;

use PHPUnit\Framework\TestCase;
use React\EventLoop\StreamSelectLoop;
use SugarCraft\Async\Await;
use SugarCraft\Async\CancellationSource;
use SugarCraft\Async\ForkChannel;
use SugarCraft\Async\ForkedTask;
use SugarCraft\Async\OperationCancelledException;
use SugarCraft\Async\TimeoutException;
use SugarCraft\Core\Util\LockFile;

final class ForkedTaskTest extends TestCase
{
    protected function setUp(): void
    {
        if (!\function_exists('pcntl_fork') || !\function_exists('posix_kill')) {
            self::markTestSkipped('ForkedTask needs ext-pcntl and ext-posix');
        }
    }

    public function testChildFramesReachCollectAndTheExitStatusSettles(): void
    {
        $loop = new StreamSelectLoop();
        $frames = [];
        $status = ForkedTask::run(
            static function (ForkChannel $channel): void {
                $channel->send('one');
                $channel->send('two');
            },
            static function (string $frame) use (&$frames): void {
                $frames[] = $frame;
            },
            $loop,
        );

        self::assertSame(0, Await::until($status, 5.0, $loop));
        self::assertSame(['one', 'two'], $frames);
    }

    public function testAChildExitLeavesALockTheParentHoldsAcrossTheForkHeld(): void
    {
        $dir = sys_get_temp_dir() . '/sugar-async-fork-lock-' . bin2hex(random_bytes(6));
        mkdir($dir, 0700);
        $path = $dir . '/l';
        $guard = LockFile::on($path)->tryAcquire();
        self::assertNotNull($guard);
        try {
            // The child's normal exit runs __destruct() on the parent's guard;
            // the lock must survive it.
            $loop = new StreamSelectLoop();
            $status = ForkedTask::run(
                static function (): void {
                },
                static function (): void {
                },
                $loop,
            );
            self::assertSame(0, Await::until($status, 5.0, $loop));

            $probe = fopen($path, 'c');
            self::assertIsResource($probe);
            self::assertFalse(flock($probe, LOCK_EX | LOCK_NB), 'the child must not unlock the parent lock');
            fclose($probe);
        } finally {
            $guard->release();
            @unlink($path);
            @rmdir($dir);
        }
    }

    public function testAThrowingChildSettlesWithExitStatusOne(): void
    {
        $loop = new StreamSelectLoop();
        $status = ForkedTask::run(
            static function (): void {
                throw new \RuntimeException('child failed');
            },
            static function (): void {
            },
            $loop,
        );

        self::assertSame(1, Await::until($status, 5.0, $loop));
    }

    public function testATokenFiredMidRunKillsTheChildAndRejects(): void
    {
        $loop = new StreamSelectLoop();
        $source = CancellationSource::new();
        $started = microtime(true);
        $promise = ForkedTask::run(
            static function (): void {
                sleep(30);
            },
            static function (): void {
            },
            $loop,
            $source->token(),
        );
        $loop->addTimer(0.05, static fn () => $source->cancel());

        try {
            Await::until($promise, 10.0, $loop);
            self::fail('A cancelled forked task must reject');
        } catch (OperationCancelledException) {
            // expected
        }
        self::assertLessThan(10.0, microtime(true) - $started, 'the child must be killed, not waited out');
    }

    public function testTheParentSideDeadlineKillsTheChildAndRejectsWithATimeout(): void
    {
        $loop = new StreamSelectLoop();
        $promise = ForkedTask::run(
            static function (): void {
                sleep(30);
            },
            static function (): void {
            },
            $loop,
            null,
            0.05,
        );

        try {
            Await::until($promise, 10.0, $loop);
            self::fail('An overrun forked task must time out');
        } catch (TimeoutException $e) {
            self::assertSame(0.05, $e->seconds());
        }
    }

    public function testACollectFailureRejectsAndKillsTheChild(): void
    {
        $loop = new StreamSelectLoop();
        $promise = ForkedTask::run(
            static function (ForkChannel $channel): void {
                $channel->send('boom');
                sleep(30);
            },
            static function (): void {
                throw new \DomainException('collector refused');
            },
            $loop,
        );

        $this->expectException(\DomainException::class);
        Await::until($promise, 10.0, $loop);
    }

    public function testAPreCancelledTokenRejectsWithoutForking(): void
    {
        $source = CancellationSource::new();
        $source->cancel();

        $promise = ForkedTask::run(
            static function (): void {
                throw new \LogicException('must not run');
            },
            static function (): void {
            },
            new StreamSelectLoop(),
            $source->token(),
        );

        $this->expectException(OperationCancelledException::class);
        Await::until($promise, 1.0, new StreamSelectLoop());
    }

    /** @dataProvider invalidDeadlineProvider */
    public function testRejectsANonPositiveOrNonFiniteDeadline(float $deadline): void
    {
        $this->expectException(\InvalidArgumentException::class);
        ForkedTask::run(static function (): void {
        }, static function (): void {
        }, new StreamSelectLoop(), null, $deadline);
    }

    /** @return iterable<string, array{float}> */
    public static function invalidDeadlineProvider(): iterable
    {
        yield 'zero' => [0.0];
        yield 'negative' => [-1.0];
        yield 'infinite' => [INF];
    }

    public function testTheParentLoopKeepsTickingWhileAChildThatClosedItsChannelStillRuns(): void
    {
        // A child that closes its channel early and keeps running used to park
        // the loop in a blocking waitpid() from the onClose path: the ticks
        // below would not fire until the child was gone.
        $loop = new StreamSelectLoop();
        $ticks = 0;
        $status = null;
        $ticker = $loop->addPeriodicTimer(0.02, static function () use (&$ticks): void {
            $ticks++;
        });
        $failsafe = $loop->addTimer(5.0, static function () use ($loop): void {
            $loop->stop();
        });

        $promise = ForkedTask::run(
            static function (ForkChannel $channel): void {
                $channel->close();
                \usleep(600_000);
            },
            static function (string $frame): void {
            },
            $loop,
        );
        $promise->then(static function (int $code) use (&$status, $loop): void {
            $status = $code;
            $loop->stop();
        });
        $loop->run();
        $loop->cancelTimer($ticker);
        $loop->cancelTimer($failsafe);

        self::assertSame(0, $status, 'the closed child was not reaped with its exit status');
        self::assertGreaterThanOrEqual(5, $ticks, 'the loop was parked while the closed child ran');
    }

    public function testTheDeadlineStillKillsAChildThatClosedItsChannelAndKeepsRunning(): void
    {
        // Closing the channel used to disarm the deadline, so a child that
        // closed early and then blocked was never killed and the promise
        // stayed pending. The deadline must still bound the reap.
        $loop = new StreamSelectLoop();
        $promise = ForkedTask::run(
            static function (ForkChannel $channel): void {
                $channel->close();
                \usleep(4_000_000);
            },
            static function (): void {
            },
            $loop,
            null,
            0.5,
        );

        $start = \microtime(true);
        try {
            Await::until($promise, 5.0, $loop);
            self::fail('A channel-closed child past its deadline must be killed and rejected');
        } catch (TimeoutException $e) {
            self::assertSame(0.5, $e->seconds());
            self::assertLessThan(3.0, \microtime(true) - $start, 'the kill must land at the deadline, not when the child exits');
        }
    }

    public function testTheChildDoesNotReEmitTheParentsBufferedOutput(): void
    {
        // The child inherits the parent's ob_* stack. A normal exit() flushes
        // it onto the shared stdout, so the parent's pending bytes used to
        // appear twice. The probe runs in a real subprocess because only a
        // separate stdout shows the duplicate.
        $probe = \tempnam(\sys_get_temp_dir(), 'forked-ob-probe-');
        \file_put_contents($probe, <<<'PHP'
<?php

declare(strict_types=1);

require $argv[1];

use React\EventLoop\StreamSelectLoop;
use SugarCraft\Async\ForkChannel;
use SugarCraft\Async\ForkedTask;

$loop = new StreamSelectLoop();
ob_start();
echo 'STALE-FRAME';
$done = ForkedTask::run(
    static function (ForkChannel $channel): void {
        $channel->send('frame');
    },
    static function (string $frame): void {
    },
    $loop,
);
$done->then(static function () use ($loop): void {
    $loop->stop();
});
$loop->run();

PHP);
        try {
            $process = \proc_open(
                [\PHP_BINARY, $probe, \dirname(__DIR__) . '/vendor/autoload.php'],
                [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                $pipes,
            );
            self::assertIsResource($process);
            \fclose($pipes[0]);
            $stdout = (string) \stream_get_contents($pipes[1]);
            $stderr = (string) \stream_get_contents($pipes[2]);
            \fclose($pipes[1]);
            \fclose($pipes[2]);
            $exit = \proc_close($process);
        } finally {
            @\unlink($probe);
        }

        self::assertSame(0, $exit, 'the probe failed: ' . $stderr);
        self::assertSame(1, \substr_count($stdout, 'STALE-FRAME'), 'the parent buffer was emitted twice');
    }
}
