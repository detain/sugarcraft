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

        $t0 = microtime(true);
        $this->reflect(ChildRunLaunch::class, 'forceFinish')->invoke($launch);
        $this->assertLessThan(1.0, microtime(true) - $t0, 'settling a live child must not wait for it');
        $this->assertInstanceOf(ChildResult::class, $result);
        $this->assertSame(ChildResult::TIMED_OUT, $result->status);
        $this->assertNull($result->exitCode);
        $this->assertNotNull($this->reflect(ChildRunLaunch::class, 'reaper')->getValue($launch), 'a live child keeps a reap poll armed');

        proc_terminate($proc, 9);
        $reaper = $this->reflect(ChildRunLaunch::class, 'reaper');
        $loop->addPeriodicTimer(0.01, static function () use ($loop, $launch, $reaper): void {
            if ($reaper->getValue($launch) === null) {
                $loop->stop();
            }
        });
        $guard = $loop->addTimer(5.0, static function () use ($loop): void {
            $loop->stop();
        });
        $loop->run();
        $loop->cancelTimer($guard);

        $this->assertNull($reaper->getValue($launch), 'the poll reaps the child once it is gone');
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
