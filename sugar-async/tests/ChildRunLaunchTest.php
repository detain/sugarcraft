<?php

declare(strict_types=1);

namespace SugarCraft\Async\Tests;

use PHPUnit\Framework\TestCase;
use React\EventLoop\LoopInterface;
use React\EventLoop\StreamSelectLoop;
use React\Promise\PromiseInterface;
use SugarCraft\Async\ChildResult;
use SugarCraft\Async\ChildRun;
use SugarCraft\Async\ChildRunLaunch;
use SugarCraft\Async\WindowsPipes;
use SugarCraft\Core\Util\Proc\BoundedShutdown;

/**
 * launch() on a private StreamSelectLoop, never the shared Loop::get(), so no
 * timer from these tests can leak into a suite that shares the loop.
 */
final class ChildRunLaunchTest extends TestCase
{
    private function php(string $code): array
    {
        return [PHP_BINARY, '-r', $code];
    }

    private function reflect(string $class, string $name): \ReflectionProperty|\ReflectionMethod
    {
        $ref = method_exists($class, $name)
            ? new \ReflectionMethod($class, $name)
            : new \ReflectionProperty($class, $name);
        $ref->setAccessible(true);

        return $ref;
    }

    private function requirePosixPipes(): void
    {
        if (WindowsPipes::runtimeNeedsIt()) {
            $this->markTestSkipped('launch() reads through stream callbacks, which Windows pipes do not offer.');
        }
    }

    /**
     * Run the loop until $promise settles or $budget seconds pass, then return
     * the result. A rejection is rethrown; a budget overrun fails the test.
     */
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

    public function testLaunchResolvesWithTheOutputAndExitStatus(): void
    {
        $this->requirePosixPipes();
        $loop = new StreamSelectLoop();
        $r = $this->settle($loop, ChildRun::new($this->php('fwrite(STDOUT, "out"); fwrite(STDERR, "err"); exit(4);'))->launch($loop));

        $this->assertSame('out', $r->stdout);
        $this->assertSame('err', $r->stderr);
        $this->assertSame(4, $r->exitCode);
        $this->assertSame(ChildResult::EXITED, $r->status);
    }

    /**
     * Regression: launch() reported a signal-killed child's exit as null while
     * run() reported -1, so the dashboard's async path showed no failure for a
     * crashed command. The two paths must agree.
     */
    public function testLaunchReportsASignalKilledChildAsRunDoesNotNull(): void
    {
        $this->requirePosixPipes();
        $sync = ChildRun::new(['sh', '-c', 'kill -9 $$'])->run();

        $loop = new StreamSelectLoop();
        $async = $this->settle($loop, ChildRun::new(['sh', '-c', 'kill -9 $$'])->launch($loop));

        $this->assertSame(-1, $sync->exitCode);
        $this->assertSame(-1, $async->exitCode, 'a signal death must not read as "could not be reaped"');
        $this->assertSame($sync->status, $async->status);
    }

    public function testLaunchDoesNotBlockTheLoop(): void
    {
        $this->requirePosixPipes();
        $loop = new StreamSelectLoop();
        $ticks = 0;
        $ticker = $loop->addPeriodicTimer(0.02, static function () use (&$ticks): void {
            $ticks++;
        });

        // The child is silent for 0.4 s; the loop must keep ticking through that wait.
        $r = $this->settle($loop, ChildRun::new($this->php('usleep(400000); fwrite(STDOUT, "late");'))->launch($loop));
        $loop->cancelTimer($ticker);

        $this->assertSame('late', $r->stdout);
        $this->assertGreaterThanOrEqual(5, $ticks, 'the loop ran while the child was silent');
    }

    public function testLaunchKillsAChildPastItsDeadline(): void
    {
        $this->requirePosixPipes();
        $loop = new StreamSelectLoop();
        $t0 = microtime(true);
        $r = $this->settle($loop, ChildRun::new($this->php('sleep(30);'))->withTimeout(0.3)->launch($loop));

        $this->assertSame(ChildResult::TIMED_OUT, $r->status);
        $this->assertLessThan(5.0, microtime(true) - $t0, 'the kill ladder runs on the loop and is bounded');
    }

    /**
     * Regression for the loop path of the run() case: closing both pipes is not
     * an exit. A child that closes its output and keeps running must still be
     * ended at the deadline, not settled as soon as the pipes reach EOF.
     */
    public function testLaunchKillsAChildThatClosesItsOutputButLingersPastItsDeadline(): void
    {
        $this->requirePosixPipes();
        $loop = new StreamSelectLoop();
        $t0 = microtime(true);
        $r = $this->settle($loop, ChildRun::new($this->php('fclose(STDOUT); fclose(STDERR); sleep(30);'))->withTimeout(0.5)->launch($loop));

        $this->assertSame(ChildResult::TIMED_OUT, $r->status);
        $this->assertLessThan(5.0, microtime(true) - $t0, 'the lingering child is killed at its deadline, not reaped at EOF');
    }

    /**
     * The kill budget is spent while the child is still running (a kernel wait
     * after SIGKILL, simulated here by a child that is simply still alive). The
     * result must settle without proc_close() blocking the loop, and the handle
     * must be reaped by the loop's poll once the child is gone.
     */
    public function testAChildStillRunningWhenTheKillBudgetIsSpentIsReapedOnTheLoop(): void
    {
        $this->requirePosixPipes();
        $loop = new StreamSelectLoop();
        $proc = proc_open($this->php('sleep(30);'), [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes);
        $this->assertIsResource($proc);

        $deferred = new \React\Promise\Deferred();
        $launch = new \SugarCraft\Async\ChildRunLaunch(
            $loop,
            $proc,
            $pipes,
            '',
            30.0,
            ChildRun::DEFAULT_MAX_BYTES,
            $deferred,
        );
        $result = null;
        $deferred->promise()->then(static function (ChildResult $r) use (&$result): void {
            $result = $r;
        });

        // Settling with the child still alive is the kill-budget-spent path: the
        // result must not wait for the child, and the handle is left to the loop.
        $t0 = microtime(true);
        $this->reflect(ChildRunLaunch::class, 'finish')->invoke($launch, ChildResult::TIMED_OUT);
        $this->assertLessThan(1.0, microtime(true) - $t0, 'settling a live child must not wait for it');
        $this->assertInstanceOf(ChildResult::class, $result);
        $this->assertSame(ChildResult::TIMED_OUT, $result->status);
        $this->assertNull($result->exitCode);
        $this->assertTrue(is_resource($proc), 'a live child is not proc_closed on the settle path');

        proc_terminate($proc, 9);
        $guard = $loop->addTimer(5.0, static function () use ($loop): void {
            $loop->stop();
        });
        $loop->addPeriodicTimer(0.01, static function () use ($loop, $proc): void {
            if (!is_resource($proc)) {
                $loop->stop();
            }
        });
        $loop->run();
        $loop->cancelTimer($guard);

        $this->assertFalse(is_resource($proc), 'the loop reaps the child once the kernel releases it');
    }

    public function testAChildThatIgnoresSigtermIsKilledByTheLadderAndSettlesTimedOut(): void
    {
        $this->requirePosixPipes();
        // The TERM rung is ignored (inherited across exec), so only the KILL rung
        // can end the child: the run must take at least the TERM grace to settle.
        $loop = new StreamSelectLoop();
        $proc = proc_open(['sh', '-c', 'trap "" TERM; exec sleep 30'], [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes);
        $this->assertIsResource($proc);

        $deferred = new \React\Promise\Deferred();
        $launch = new \SugarCraft\Async\ChildRunLaunch(
            $loop,
            $proc,
            $pipes,
            '',
            0.2,
            ChildRun::DEFAULT_MAX_BYTES,
            $deferred,
        );
        $result = null;
        $deferred->promise()->then(static function (ChildResult $r) use (&$result, $loop): void {
            $result = $r;
            $loop->stop();
        });
        $guard = $loop->addTimer(10.0, static function () use ($loop): void {
            $loop->stop();
        });
        $t0 = microtime(true);
        $launch->start();
        $loop->run();
        $loop->cancelTimer($guard);
        $elapsed = microtime(true) - $t0;

        $this->assertInstanceOf(ChildResult::class, $result, 'the ladder must settle the run');
        $this->assertSame(ChildResult::TIMED_OUT, $result->status);
        $this->assertGreaterThanOrEqual(BoundedShutdown::TERMINATE_GRACE_SECONDS, $elapsed, 'the TERM rung must be given its grace before KILL');
        $this->assertLessThan(5.0, $elapsed, 'the kill budget bounds the run');
    }

    public function testLaunchFeedsStdinAndReadsItBackLineByLine(): void
    {
        $this->requirePosixPipes();
        $loop = new StreamSelectLoop();
        $lines = '';
        for ($i = 0; $i < 200; $i++) {
            $lines .= "line $i\n";
        }
        $r = $this->settle(
            $loop,
            ChildRun::new($this->php('while (($l = fgets(STDIN)) !== false) { fwrite(STDOUT, $l); }'))
                ->withStdin($lines)
                ->launch($loop),
        );

        $this->assertSame($lines, $r->stdout);
        $this->assertTrue($r->succeeded());
    }

    /**
     * A child that exits without reading a payload past one pipe buffer breaks
     * the stdin pipe. The run must still settle as EXITED with the child's code.
     */
    public function testLaunchSettlesWhenTheChildExitsBeforeReadingItsStdin(): void
    {
        $this->requirePosixPipes();
        $loop = new StreamSelectLoop();
        $r = $this->settle(
            $loop,
            ChildRun::new($this->php('exit(0);'))->withStdin(str_repeat('x', 200_000))->launch($loop),
        );

        $this->assertSame(ChildResult::EXITED, $r->status);
        $this->assertSame(0, $r->exitCode);
    }

    /**
     * Regression: once the child has closed its stdin, the next payload write
     * fails with a broken pipe, and onWritable() must close the stdin stream
     * then. The child lingers after closing its pipes, so the stream is still
     * open when the check runs if that branch is broken.
     */
    public function testBrokenStdinPipeIsClosedWhileTheChildLingers(): void
    {
        $this->requirePosixPipes();
        $loop = new StreamSelectLoop();
        $proc = proc_open(
            $this->php('fclose(STDIN); fclose(STDOUT); fclose(STDERR); usleep(600000);'),
            [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']],
            $pipes,
        );
        $this->assertIsResource($proc);

        $deferred = new \React\Promise\Deferred();
        $launch = new ChildRunLaunch(
            $loop,
            $proc,
            $pipes,
            str_repeat('x', 200_000),
            10.0,
            ChildRun::DEFAULT_MAX_BYTES,
            $deferred,
        );
        $stdinPipe = $this->reflect(ChildRunLaunch::class, 'stdinPipe');
        $stdinOpenAtCheck = null;
        $result = null;
        $deferred->promise()->then(static function (ChildResult $r) use (&$result, $loop): void {
            $result = $r;
            $loop->stop();
        });
        $loop->addTimer(0.3, static function () use ($loop, $launch, $stdinPipe, &$stdinOpenAtCheck): void {
            $stdinOpenAtCheck = $stdinPipe->getValue($launch) !== null;
        });
        $guard = $loop->addTimer(10.0, static function () use ($loop): void {
            $loop->stop();
        });

        $launch->start();
        $loop->run();
        $loop->cancelTimer($guard);

        $this->assertFalse($stdinOpenAtCheck, 'the broken stdin pipe must be closed while the child still lingers');
        $this->assertInstanceOf(ChildResult::class, $result, 'the run must settle');
        $this->assertSame(ChildResult::EXITED, $result->status);
    }

    public function testLaunchAppliesTheCapAndCountsTheRest(): void
    {
        $this->requirePosixPipes();
        $loop = new StreamSelectLoop();
        $r = $this->settle(
            $loop,
            ChildRun::new($this->php('fwrite(STDOUT, str_repeat("z", 500));'))->withMaxBytes(50)->launch($loop),
        );

        $this->assertSame(50, strlen($r->stdout));
        $this->assertSame(450, $r->droppedBytes);
        $this->assertTrue($r->truncated());
    }

    public function testLaunchRejectsWhenTheProgramCannotStart(): void
    {
        $loop = new StreamSelectLoop();
        $rejected = null;
        ChildRun::new(['/nonexistent/sugar-async-launch-' . getmypid()])
            ->launch($loop)
            ->then(null, static function (\Throwable $e) use (&$rejected, $loop): void {
                $rejected = $e;
                $loop->stop();
            });
        $loop->addTimer(5.0, static function () use ($loop): void {
            $loop->stop();
        });
        $loop->run();

        $this->assertInstanceOf(\RuntimeException::class, $rejected);
    }
}
