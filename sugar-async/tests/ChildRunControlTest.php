<?php

declare(strict_types=1);

namespace SugarCraft\Async\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use React\EventLoop\LoopInterface;
use React\EventLoop\StreamSelectLoop;
use React\Promise\PromiseInterface;
use SugarCraft\Async\CancellationSource;
use SugarCraft\Async\ChildResult;
use SugarCraft\Async\ChildRun;
use SugarCraft\Async\WindowsPipes;
use SugarCraft\Core\Util\Proc\BoundedShutdown;

/**
 * Cancel, the opt-in idle deadline and the Windows stdin branch, for both the
 * blocking run() and the loop-driven launch(). launch() runs on a private
 * StreamSelectLoop, never the shared Loop::get().
 */
final class ChildRunControlTest extends TestCase
{
    private function php(string $code): array
    {
        return [PHP_BINARY, '-r', $code];
    }

    private function requirePosixPipes(): void
    {
        if (WindowsPipes::runtimeNeedsIt()) {
            $this->markTestSkipped('launch() reads through stream callbacks, which Windows pipes do not offer.');
        }
    }

    /** Run the loop until $promise settles or $budget seconds pass; a rejection is rethrown. */
    private function settle(LoopInterface $loop, PromiseInterface $promise, float $budget = 10.0): ChildResult
    {
        $result = null;
        $error = null;
        $promise->then(
            static function (ChildResult $r) use (&$result, $loop): void {
                $result = $r;
                $loop->stop();
            },
            static function (\Throwable $e) use (&$error, $loop): void {
                $error = $e;
                $loop->stop();
            },
        );
        $guard = $loop->addTimer($budget, static function () use ($loop): void {
            $loop->stop();
        });

        $loop->run();
        $loop->cancelTimer($guard);

        if ($error !== null) {
            throw $error;
        }
        $this->assertInstanceOf(ChildResult::class, $result, 'launch did not settle within the budget');

        return $result;
    }

    // ---------------------------------------------------------------- run()

    public function testRunCancelledFromItsOwnOnChunkKillsTheChildAndReportsCancelled(): void
    {
        $source = CancellationSource::new();
        $started = microtime(true);

        $r = ChildRun::new($this->php('fwrite(STDOUT, "go"); fflush(STDOUT); sleep(30);'))
            ->withTimeout(20.0)
            ->withCancellation($source->token())
            ->withOnChunk(static function () use ($source): void {
                $source->cancel();
            })
            ->run();

        $this->assertSame(ChildResult::CANCELLED, $r->status);
        $this->assertTrue($r->cancelled());
        $this->assertFalse($r->succeeded());
        $this->assertLessThan(5.0, microtime(true) - $started, 'the cancel must not wait out the child');
    }

    public function testRunAlreadyCancelledTokenSpawnsNothing(): void
    {
        $marker = tempnam(sys_get_temp_dir(), 'cr-cancel');
        $this->assertIsString($marker);
        @unlink($marker);
        try {
            $source = CancellationSource::new();
            $source->cancel();

            $r = ChildRun::new($this->php('file_put_contents(' . var_export($marker, true) . ', "ran");'))
                ->withCancellation($source->token())
                ->run();

            $this->assertSame(ChildResult::CANCELLED, $r->status);
            $this->assertNull($r->exitCode, 'nothing was started, so there is no exit status');
            $this->assertFileDoesNotExist($marker, 'the child must not run once the token is cancelled');
        } finally {
            @unlink($marker);
        }
    }

    public function testRunIdleDeadlineKillsASilentChild(): void
    {
        $started = microtime(true);

        $r = ChildRun::new($this->php('fwrite(STDOUT, "hi"); fflush(STDOUT); sleep(30);'))
            ->withTimeout(20.0)
            ->withIdleTimeout(0.4)
            ->run();

        $this->assertSame(ChildResult::TIMED_OUT, $r->status);
        $this->assertSame('hi', $r->stdout, 'output written before the silence is kept');
        $this->assertLessThan(5.0, microtime(true) - $started, 'the idle kill must not wait for the deadline');
    }

    public function testRunIdleDeadlineDoesNotFireWhileOutputKeepsFlowing(): void
    {
        // Twelve chunks, 150 ms apart: 1.8 s in all, well past the 0.6 s idle budget,
        // but never silent for longer than it.
        $code = 'for ($i = 0; $i < 12; $i++) { fwrite(STDOUT, "x"); fflush(STDOUT); usleep(150000); }';

        $r = ChildRun::new($this->php($code))
            ->withTimeout(20.0)
            ->withIdleTimeout(0.6)
            ->run();

        $this->assertSame(ChildResult::EXITED, $r->status);
        $this->assertSame(0, $r->exitCode);
        $this->assertSame(str_repeat('x', 12), $r->stdout);
    }

    public function testRunIdleDeadlineIsOffByDefault(): void
    {
        // Silent for a second, well past any idle budget a caller might set, and
        // within the default deadline: with no idle budget it exits on its own.
        $r = ChildRun::new($this->php('usleep(1000000); exit(0);'))->run();

        $this->assertSame(ChildResult::EXITED, $r->status);
        $this->assertTrue($r->succeeded());
    }

    /** @return iterable<string, array{0: callable(ChildRun): ChildRun}> */
    public static function badControlBuilders(): iterable
    {
        yield 'zero idle' => [static fn (ChildRun $r): ChildRun => $r->withIdleTimeout(0.0)];
        yield 'negative idle' => [static fn (ChildRun $r): ChildRun => $r->withIdleTimeout(-1.0)];
        yield 'infinite idle' => [static fn (ChildRun $r): ChildRun => $r->withIdleTimeout(INF)];
    }

    #[DataProvider('badControlBuilders')]
    public function testRejectsBadIdleTimeouts(callable $apply): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $apply(ChildRun::new(['true']));
    }

    public function testTheControlBuildersAreImmutableAndNullClearsThem(): void
    {
        $base = ChildRun::new(['true']);
        $source = CancellationSource::new();
        $tuned = $base->withIdleTimeout(2.0)->withCancellation($source->token());

        $this->assertNotSame($base, $tuned);
        $this->assertNull((new \ReflectionProperty(ChildRun::class, 'idleTimeout'))->getValue($base));
        $this->assertNull((new \ReflectionProperty(ChildRun::class, 'cancellation'))->getValue($base));

        $cleared = $tuned->withIdleTimeout(null)->withCancellation(null);
        $this->assertNull((new \ReflectionProperty(ChildRun::class, 'idleTimeout'))->getValue($cleared));
        $this->assertNull((new \ReflectionProperty(ChildRun::class, 'cancellation'))->getValue($cleared));
    }

    public function testStdinIsStagedToAFileOnWindowsAndFedToTheChild(): void
    {
        if (!WindowsPipes::runtimeNeedsIt()) {
            $this->markTestSkipped(
                'The Windows stdin branch stages the payload to a file, and only the Windows runtime takes it; this host is not Windows.',
            );
        }
        $payload = str_repeat('staged-line' . "\n", 2000); // past one 4 KiB pipe buffer

        $r = ChildRun::new($this->php('fwrite(STDOUT, stream_get_contents(STDIN));'))
            ->withStdin($payload)
            ->withTimeout(20.0)
            ->run();

        $this->assertSame(ChildResult::EXITED, $r->status);
        $this->assertSame($payload, $r->stdout, 'the staged file must reach the child as its whole stdin');
    }

    // -------------------------------------------------------------- launch()

    public function testLaunchCancelKillsTheChildAndReportsCancelled(): void
    {
        $this->requirePosixPipes();
        $loop = new StreamSelectLoop();
        $source = CancellationSource::new();
        $pid = null;
        $started = microtime(true);

        $promise = ChildRun::new($this->php('sleep(30);'))
            ->withTimeout(20.0)
            ->withCancellation($source->token())
            ->withOnSpawn(static function (int $spawned) use (&$pid): void {
                $pid = $spawned;
            })
            ->launch($loop);
        $loop->addTimer(0.2, static function () use ($source): void {
            $source->cancel();
        });

        $r = $this->settle($loop, $promise);

        $this->assertSame(ChildResult::CANCELLED, $r->status);
        $this->assertTrue($r->cancelled());
        $this->assertLessThan(5.0, microtime(true) - $started, 'the cancel must not wait out the child');
        $this->assertIsInt($pid);
        $this->assertTrue(
            BoundedShutdown::waitBounded(static fn (): bool => BoundedShutdown::pidExited($pid), 3.0),
            'the cancelled child must be killed and reaped',
        );
    }

    public function testLaunchAlreadyCancelledTokenResolvesWithoutSpawning(): void
    {
        $this->requirePosixPipes();
        $loop = new StreamSelectLoop();
        $source = CancellationSource::new();
        $source->cancel();
        $spawned = false;

        $r = $this->settle($loop, ChildRun::new($this->php('sleep(30);'))
            ->withCancellation($source->token())
            ->withOnSpawn(static function () use (&$spawned): void {
                $spawned = true;
            })
            ->launch($loop));

        $this->assertSame(ChildResult::CANCELLED, $r->status);
        $this->assertFalse($spawned, 'a token cancelled before launch must not start the child');
    }

    public function testLaunchCancelAfterTheChildFinishedChangesNothing(): void
    {
        $this->requirePosixPipes();
        $loop = new StreamSelectLoop();
        $source = CancellationSource::new();

        $r = $this->settle($loop, ChildRun::new($this->php('fwrite(STDOUT, "done");'))
            ->withCancellation($source->token())
            ->launch($loop));
        $source->cancel();

        $this->assertSame(ChildResult::EXITED, $r->status);
        $this->assertSame('done', $r->stdout);
        $this->assertTrue($r->succeeded());
    }

    public function testLaunchIdleDeadlineKillsASilentChild(): void
    {
        $this->requirePosixPipes();
        $loop = new StreamSelectLoop();
        $started = microtime(true);

        $r = $this->settle($loop, ChildRun::new($this->php('fwrite(STDOUT, "hi"); fflush(STDOUT); sleep(30);'))
            ->withTimeout(20.0)
            ->withIdleTimeout(0.4)
            ->launch($loop));

        $this->assertSame(ChildResult::TIMED_OUT, $r->status);
        $this->assertSame('hi', $r->stdout);
        $this->assertLessThan(5.0, microtime(true) - $started, 'the idle kill must not wait for the deadline');
    }

    public function testLaunchIdleDeadlineDoesNotFireWhileOutputKeepsFlowing(): void
    {
        $this->requirePosixPipes();
        $loop = new StreamSelectLoop();
        $code = 'for ($i = 0; $i < 12; $i++) { fwrite(STDOUT, "x"); fflush(STDOUT); usleep(150000); }';

        $r = $this->settle($loop, ChildRun::new($this->php($code))
            ->withTimeout(20.0)
            ->withIdleTimeout(0.6)
            ->launch($loop));

        $this->assertSame(ChildResult::EXITED, $r->status);
        $this->assertSame(0, $r->exitCode);
        $this->assertSame(str_repeat('x', 12), $r->stdout);
    }

    public function testLaunchIdleDeadlineIsOffByDefault(): void
    {
        $this->requirePosixPipes();
        $loop = new StreamSelectLoop();

        $r = $this->settle($loop, ChildRun::new($this->php('usleep(1000000); exit(0);'))->launch($loop));

        $this->assertSame(ChildResult::EXITED, $r->status);
        $this->assertTrue($r->succeeded());
    }

    public function testLaunchStagedWindowsStdinReachesTheChild(): void
    {
        if (!WindowsPipes::runtimeNeedsIt()) {
            $this->markTestSkipped(
                'The Windows stdin branch stages the payload to a file, and only the Windows runtime takes it; this host is not Windows.',
            );
        }
        $loop = new StreamSelectLoop();
        $payload = str_repeat('staged-line' . "\n", 2000);

        $r = $this->settle($loop, ChildRun::new($this->php('fwrite(STDOUT, stream_get_contents(STDIN));'))
            ->withStdin($payload)
            ->withTimeout(20.0)
            ->launch($loop));

        $this->assertSame(ChildResult::EXITED, $r->status);
        $this->assertSame($payload, $r->stdout);
    }
}
