<?php

declare(strict_types=1);

namespace SugarCraft\Async\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SugarCraft\Async\CancellationSource;
use SugarCraft\Async\ChildPump;
use SugarCraft\Async\ChildResult;
use SugarCraft\Async\ChildRun;
use SugarCraft\Async\WindowsPipes;

final class ChildRunTest extends TestCase
{
    private function php(string $code): array
    {
        return [PHP_BINARY, '-r', $code];
    }

    private function requirePosixPipes(): void
    {
        if (WindowsPipes::runtimeNeedsIt()) {
            $this->markTestSkipped('ChildRun stdin and the select arm need non-blocking pipes.');
        }
    }

    private function now(): float
    {
        return microtime(true);
    }

    public function testRunCollectsStdoutStderrAndTheExitStatus(): void
    {
        $r = ChildRun::new($this->php('fwrite(STDOUT, "out"); fwrite(STDERR, "err"); exit(3);'))->run();

        $this->assertSame('out', $r->stdout);
        $this->assertSame('err', $r->stderr);
        $this->assertSame(3, $r->exitCode);
        $this->assertSame(ChildResult::EXITED, $r->status);
        $this->assertFalse($r->timedOut());
        $this->assertFalse($r->succeeded(), 'a nonzero exit is not success');
    }

    public function testSucceededOnlyWhenTheChildExitsZeroOnItsOwn(): void
    {
        $r = ChildRun::new($this->php('fwrite(STDOUT, "fine");'))->run();

        $this->assertTrue($r->succeeded());
        $this->assertSame(0, $r->exitCode);
        $this->assertFalse($r->truncated());
    }

    /**
     * A hook backgrounds a child that inherits the output pipes and outlives the
     * process that started it. The run ends one exit grace after the parent exits
     * and reports its status; it must not wait out the deadline and report a timeout.
     */
    public function testABackgroundedChildHoldingThePipesDoesNotStretchTheRunToItsDeadline(): void
    {
        $this->requirePosixPipes();
        $started = $this->now();
        $r = ChildRun::new(['/bin/sh', '-c', 'sleep 6 & exit 0'])->withTimeout(4.0)->run();
        $elapsed = $this->now() - $started;

        $this->assertSame(ChildResult::EXITED, $r->status);
        $this->assertFalse($r->timedOut());
        $this->assertTrue($r->succeeded());
        $this->assertTrue($r->abandonedPipes(), 'the held pipe is abandoned after the silence budget');
        $this->assertGreaterThanOrEqual(0.9, $elapsed, 'the pipe is read on for the silence budget before it is cut');
        $this->assertLessThan(3.0, $elapsed, 'the run must end after the exit grace, not at the 4s deadline');
    }

    /**
     * The grace counts silence, not time from the exit: a grandchild that writes
     * every half second for two seconds after the parent exits keeps the pipes
     * read, so every line is kept. A fixed one-second window from the exit would
     * cut the output after the third line. The pipe reaches EOF when the writer
     * ends, so nothing is abandoned.
     */
    public function testExitGraceCountsSilenceNotTimeFromTheExit(): void
    {
        $this->requirePosixPipes();
        $started = $this->now();
        $r = ChildRun::new(['/bin/sh', '-c', '(for i in 1 2 3 4 5; do echo $i; sleep 0.5; done) & exit 0'])
            ->withTimeout(10.0)
            ->run();
        $elapsed = $this->now() - $started;

        $this->assertSame(ChildResult::EXITED, $r->status);
        $this->assertSame("1\n2\n3\n4\n5\n", $r->stdout, 'output that keeps coming inside the silence budget is kept');
        $this->assertFalse($r->abandonedPipes(), 'a pipe read to EOF is not abandoned');
        $this->assertLessThan(6.0, $elapsed, 'the walk must end at EOF, not at the deadline');
    }

    /** Abandonment is an exit-time cut: a deadline kill of a held pipe is a timeout, not an abandonment. */
    public function testADeadlineKillOfAHeldPipeIsNotReportedAsAbandoned(): void
    {
        $this->requirePosixPipes();
        $r = ChildRun::new(['/bin/sh', '-c', 'sleep 6 & sleep 6'])->withTimeout(1.0)->run();

        $this->assertSame(ChildResult::TIMED_OUT, $r->status);
        $this->assertFalse($r->abandonedPipes(), 'the parent never exited, so no pipe was abandoned after an exit');
    }

    /**
     * The run() counterpart of the launch case: an idle budget shorter than the exit
     * grace does not cut a child that has already exited. The grace governs its held
     * pipe, so the run is EXITED with the pipe reported abandoned, not TIMED_OUT.
     */
    public function testRunExitGraceGovernsAHeldPipeWhenAnIdleBudgetIsSet(): void
    {
        $this->requirePosixPipes();
        $started = $this->now();

        $r = ChildRun::new(['/bin/sh', '-c', 'sleep 6 & exit 0'])
            ->withTimeout(4.0)
            ->withIdleTimeout(0.5)
            ->run();
        $elapsed = $this->now() - $started;

        $this->assertSame(ChildResult::EXITED, $r->status, 'an exited child is not idle-killed');
        $this->assertSame(0, $r->exitCode);
        $this->assertTrue($r->abandonedPipes(), 'the held pipe is abandoned after the exit grace');
        $this->assertLessThan(3.0, $elapsed, 'run settles after the exit grace, not at the idle budget or deadline');
    }

    /** A pipe that is read to EOF with its child is not abandoned, and neither is a child that never held one. */
    public function testPipesClosedByTheChildAreNotAbandoned(): void
    {
        $r = ChildRun::new($this->php('fwrite(STDOUT, "done");'))->run();

        $this->assertSame('done', $r->stdout);
        $this->assertFalse($r->abandonedPipes());
    }

    /**
     * With the grace removed, a backgrounded child that holds the pipes holds the
     * run to its deadline: the run reads until EOF or the deadline, the group is
     * killed there, and the result is TIMED_OUT. This is the shape a caller that
     * bounds a whole command by its budget needs.
     */
    public function testWithoutAnExitGraceAHeldPipeHoldsTheRunToItsDeadline(): void
    {
        $this->requirePosixPipes();
        $started = $this->now();
        $r = ChildRun::new(['/bin/sh', '-c', 'sleep 6 & exit 0'])
            ->withTimeout(2.0)
            ->withExitGrace(null)
            ->run();
        $elapsed = $this->now() - $started;

        $this->assertTrue($r->timedOut(), 'the held pipe must not end the run before the deadline');
        $this->assertGreaterThanOrEqual(1.5, $elapsed, 'the run waits for the deadline while the pipe is held');
        $this->assertLessThan(5.0, $elapsed, 'the deadline and the kill ladder bound the run');
    }

    /**
     * Pins the exit grace's cut-off: a line a backgrounded grandchild writes inside
     * the grace is kept, and one written after the pipes have been silent for the
     * whole grace is not. The 'early' line restarts the silence count, so the cut
     * lands one grace after it, well before 'late' is due. The grace length is a
     * policy call, so this test fixes the shape, not the number.
     */
    public function testOutputWrittenAfterTheExitGraceIsCutOff(): void
    {
        $this->requirePosixPipes();
        $started = $this->now();
        $r = ChildRun::new(['/bin/sh', '-c', '(sleep 0.3; echo early; sleep 3; echo late) & exit 0'])
            ->withTimeout(10.0)
            ->run();
        $elapsed = $this->now() - $started;

        $this->assertSame(ChildResult::EXITED, $r->status);
        $this->assertSame("early\n", $r->stdout);
        $this->assertLessThan(3.0, $elapsed, 'the run must end at the grace cut-off, before the late line is due');
    }

    /** The exit grace keeps the status the probe reads: a nonzero exit is still reported as itself. */
    public function testABackgroundedChildDoesNotCostTheParentItsExitCode(): void
    {
        $this->requirePosixPipes();
        $r = ChildRun::new(['/bin/sh', '-c', 'sleep 6 & exit 3'])->withTimeout(4.0)->run();

        $this->assertSame(ChildResult::EXITED, $r->status);
        $this->assertSame(3, $r->exitCode);
        $this->assertFalse($r->succeeded());
    }

    public function testStdinPayloadIsDeliveredToTheChild(): void
    {
        $this->requirePosixPipes();
        $payload = str_repeat('stdin-line' . "\n", 20000); // ~200 KiB, past one pipe buffer
        $r = ChildRun::new($this->php('fwrite(STDOUT, strtoupper(stream_get_contents(STDIN)));'))
            ->withStdin($payload)
            ->run();

        $this->assertSame(strtoupper($payload), $r->stdout);
        $this->assertTrue($r->succeeded());
    }

    public function testWithoutStdinTheChildSeesAnImmediateEof(): void
    {
        $this->requirePosixPipes();
        $r = ChildRun::new($this->php('fwrite(STDOUT, "[" . stream_get_contents(STDIN) . "]");'))->run();

        $this->assertSame('[]', $r->stdout);
        $this->assertSame(ChildResult::EXITED, $r->status);
    }

    public function testAChildPastTheDeadlineIsKilledAndReportedAsTimedOut(): void
    {
        $this->requirePosixPipes();
        $t0 = $this->now();
        $r = ChildRun::new($this->php('sleep(30);'))->withTimeout(0.3)->run();

        $this->assertSame(ChildResult::TIMED_OUT, $r->status);
        $this->assertTrue($r->timedOut());
        $this->assertFalse($r->succeeded());
        $this->assertLessThan(5.0, $this->now() - $t0, 'the kill ladder must bound the run');
    }

    public function testAFloodingChildPastTheDeadlineIsReportedAsTimedOutInBoundedTime(): void
    {
        $this->requirePosixPipes();
        // Regression: the expiry sweep walked each pipe to EOF, so a child that
        // kept writing (a large git log -p or diff) never returned control.
        $t0 = $this->now();
        $r = ChildRun::new($this->php('while (true) { fwrite(STDOUT, str_repeat("y", 65536)); }'))
            ->withTimeout(0.5)
            ->withMaxBytes(4096)
            ->run();

        $this->assertSame(ChildResult::TIMED_OUT, $r->status);
        $this->assertTrue($r->timedOut());
        $this->assertLessThan(5.0, $this->now() - $t0, 'the sweep must not run on past the budget');
    }

    public function testAChildThatClosesItsOutputButLingersIsKilledAsTimedOut(): void
    {
        $this->requirePosixPipes();
        // Regression: a child that closes its pipes and keeps running used to be
        // reaped as if it had exited, letting a wedged process outlive its budget.
        $t0 = $this->now();
        $r = ChildRun::new($this->php('fclose(STDOUT); fclose(STDERR); sleep(30);'))->withTimeout(0.5)->run();

        $this->assertSame(ChildResult::TIMED_OUT, $r->status);
        $this->assertLessThan(5.0, $this->now() - $t0);
    }

    public function testTheCapKeepsTheHeadAndCountsTheRestAcrossBothStreams(): void
    {
        $this->requirePosixPipes();
        $r = ChildRun::new($this->php('fwrite(STDOUT, str_repeat("o", 1000)); fwrite(STDERR, str_repeat("e", 50));'))
            ->withMaxBytes(100)
            ->run();

        $this->assertSame(100, strlen($r->stdout));
        $this->assertSame(50, strlen($r->stderr));
        $this->assertSame(900, $r->droppedBytes);
        $this->assertTrue($r->truncated());
        $this->assertSame(ChildResult::EXITED, $r->status, 'truncation does not kill the child');
    }

    public function testADefaultCapBoundsAChildThatHasNoCapOfItsOwn(): void
    {
        $this->requirePosixPipes();
        // Regression: the copies that carried no cap (candy-code CommandBackend,
        // CapturesProcessOutput) let a runaway child grow the buffer without bound.
        $r = ChildRun::new($this->php('fwrite(STDOUT, str_repeat("a", ' . (ChildRun::DEFAULT_MAX_BYTES + 1000) . '));'))
            ->withTimeout(30.0)
            ->run();

        $this->assertSame(ChildRun::DEFAULT_MAX_BYTES, strlen($r->stdout));
        $this->assertSame(1000, $r->droppedBytes);
    }

    public function testChattyStderrDoesNotWedgeStdout(): void
    {
        $this->requirePosixPipes();
        $r = ChildRun::new($this->php('fwrite(STDERR, str_repeat("e", 300000)); fwrite(STDOUT, "done");'))->run();

        $this->assertSame('done', $r->stdout);
        $this->assertSame(300000, strlen($r->stderr));
        $this->assertTrue($r->succeeded());
    }

    public function testEnvAndCwdReachTheChild(): void
    {
        $this->requirePosixPipes();
        $dir = realpath(sys_get_temp_dir());
        $this->assertIsString($dir);
        $r = ChildRun::new($this->php('echo getenv("SC_ASYNC_PROBE"), "|", getcwd();'))
            ->withEnv(['SC_ASYNC_PROBE' => 'set'])
            ->withCwd($dir)
            ->run();

        $this->assertSame('set|' . $dir, $r->stdout);
    }

    public function testAProgramThatCannotStartThrows(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('could not start');
        ChildRun::new(['/nonexistent/sugar-async-probe-' . getmypid()])->run();
    }

    public function testTheDefaultsArePinnedToThePumpDefaults(): void
    {
        $this->assertSame(ChildPump::DEFAULT_TIMEOUT_SECONDS, ChildRun::DEFAULT_TIMEOUT_SECONDS);
        $this->assertSame(ChildPump::DEFAULT_MAX_BYTES, ChildRun::DEFAULT_MAX_BYTES);
        $this->assertSame(8_388_608, ChildRun::DEFAULT_MAX_BYTES);
    }

    /** @return iterable<string, array{0: callable(ChildRun): ChildRun}> */
    public static function badBuilders(): iterable
    {
        yield 'zero timeout' => [static fn (ChildRun $r): ChildRun => $r->withTimeout(0.0)];
        yield 'infinite timeout' => [static fn (ChildRun $r): ChildRun => $r->withTimeout(INF)];
        yield 'negative cap' => [static fn (ChildRun $r): ChildRun => $r->withMaxBytes(-1)];
        yield 'zero group pid' => [static fn (ChildRun $r): ChildRun => $r->withGroupPid(0)];
        yield 'negative group pid' => [static fn (ChildRun $r): ChildRun => $r->withGroupPid(-4)];
        yield 'negative exit grace' => [static fn (ChildRun $r): ChildRun => $r->withExitGrace(-0.5)];
        yield 'infinite exit grace' => [static fn (ChildRun $r): ChildRun => $r->withExitGrace(INF)];
        yield 'zero exit grace' => [static fn (ChildRun $r): ChildRun => $r->withExitGrace(0.0)];
    }

    #[DataProvider('badBuilders')]
    public function testRejectsBadBuilderValues(callable $apply): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $apply(ChildRun::new(['true']));
    }

    public function testACommandLineIsHandedToTheShellAsOneString(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            $this->markTestSkipped('The POSIX shell line is pinned here; the Windows cmd.exe line runs on a Windows runtime.');
        }

        $r = ChildRun::commandLine('printf "%s" "a b"; exit 4')->run();

        $this->assertSame('a b', $r->stdout);
        $this->assertSame(4, $r->exitCode);
    }

    public function testACommandLineKeepsItsFormThroughTheBuilder(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            $this->markTestSkipped('The POSIX shell line is pinned here; the Windows cmd.exe line runs on a Windows runtime.');
        }

        $r = ChildRun::commandLine('printf hi')->withTimeout(5.0)->run();

        $this->assertSame('hi', $r->stdout);
        $this->assertTrue($r->succeeded());
    }

    public function testAnEmptyCommandLineIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        ChildRun::commandLine('');
    }

    public function testRejectsAnEmptyOrNonStringArgv(): void
    {
        try {
            ChildRun::new([]);
            $this->fail('an empty argv must be refused');
        } catch (\InvalidArgumentException) {
            $this->addToAssertionCount(1);
        }

        $this->expectException(\InvalidArgumentException::class);
        ChildRun::new([PHP_BINARY, 42]);
    }

    public function testStdinIsFedInFullEvenAfterTheOutputPipesClose(): void
    {
        $this->requirePosixPipes();
        $payload = str_repeat('x', 200_000); // past one pipe buffer
        $marker = tempnam(sys_get_temp_dir(), 'cr-stdin');
        try {
            // The child closes its output pipes, then reads everything it is fed.
            $code = 'fclose(STDOUT); fclose(STDERR); file_put_contents('
                . var_export((string) $marker, true)
                . ', strlen(stream_get_contents(STDIN)));';
            ChildRun::new($this->php($code))->withStdin($payload)->withTimeout(10.0)->run();

            $this->assertSame(strlen($payload), (int) file_get_contents((string) $marker));
        } finally {
            @unlink((string) $marker);
        }
    }

    public function testTheBuilderIsImmutable(): void
    {
        $base = ChildRun::new($this->php('exit(0);'));
        $tuned = $base->withTimeout(2.0)->withMaxBytes(10)->withStdin('x');

        $this->assertNotSame($base, $tuned);
        $this->assertSame(ChildRun::DEFAULT_MAX_BYTES, (new \ReflectionProperty($base, 'maxBytes'))->getValue($base));
        $this->assertSame(10, (new \ReflectionProperty($tuned, 'maxBytes'))->getValue($tuned));
    }

    public function testOnChunkSeesEveryChunkOfBothStreamsAsItArrives(): void
    {
        $seen = ['1' => '', '2' => ''];
        $r = ChildRun::new($this->php('fwrite(STDOUT, "out-a"); fwrite(STDERR, "err-b"); fwrite(STDOUT, "out-c");'))
            ->withOnChunk(static function (int|string $slot, string $chunk) use (&$seen): void {
                $seen[(string) $slot] .= $chunk;
            })
            ->run();

        $this->assertSame('out-aout-c', $r->stdout);
        $this->assertSame('err-b', $r->stderr);
        $this->assertSame('out-aout-c', $seen['1']);
        $this->assertSame('err-b', $seen['2']);
    }

    public function testWithOnChunkNullRemovesTheFeed(): void
    {
        $calls = 0;
        $r = ChildRun::new($this->php('fwrite(STDOUT, "quiet");'))
            ->withOnChunk(static function () use (&$calls): void {
                $calls++;
            })
            ->withOnChunk(null)
            ->run();

        $this->assertSame('quiet', $r->stdout);
        $this->assertSame(0, $calls);
    }

    public function testTheGroupPidAndOnChunkCarryThroughTheBuilder(): void
    {
        $base = ChildRun::new(['true']);
        $tuned = $base->withGroupPid(4242)->withOnChunk(static fn () => null);

        $this->assertNotSame($base, $tuned);
        $this->assertNull((new \ReflectionProperty($base, 'groupPid'))->getValue($base));
        $this->assertSame(4242, (new \ReflectionProperty($tuned, 'groupPid'))->getValue($tuned));
        $this->assertNotNull((new \ReflectionProperty($tuned, 'onChunk'))->getValue($tuned));
        $this->assertSame(4242, (new \ReflectionProperty($tuned->withTimeout(2.0), 'groupPid'))->getValue($tuned->withTimeout(2.0)));
    }

    public function testOnSpawnReportsTheChildPidBeforeTheResultIsAssembled(): void
    {
        $this->requirePosixPipes();
        $reported = [];
        $r = ChildRun::new($this->php('fwrite(STDOUT, (string) getmypid());'))
            ->withOnSpawn(static function (int $pid) use (&$reported): void {
                $reported[] = $pid;
            })
            ->run();

        $this->assertCount(1, $reported);
        $this->assertGreaterThan(0, $reported[0]);
        $this->assertSame((string) $reported[0], $r->stdout);
    }

    public function testAnOnSpawnThrowKillsTheStartedChildAndRethrows(): void
    {
        $this->requirePosixPipes();
        if (!\function_exists('posix_kill')) {
            $this->markTestSkipped('The posix extension is needed to probe the child after the throw.');
        }
        $pid = 0;

        try {
            ChildRun::new($this->php('sleep(30);'))
                ->withOnSpawn(static function (int $spawned) use (&$pid): void {
                    $pid = $spawned;

                    throw new \RuntimeException('onSpawn refused');
                })
                ->run();
            self::fail('an onSpawn throw must escape run()');
        } catch (\RuntimeException $e) {
            $this->assertSame('onSpawn refused', $e->getMessage());
        }

        $this->assertGreaterThan(0, $pid);
        $this->assertFalse(\posix_kill($pid, 0), 'the child must be killed and reaped, not left running');
    }

    /**
     * A throwing onSpawn on a wrapped child must take the wrapper's process group
     * down, not only the wrapper pid: the descendant it started is still alive when
     * the throw happens, and the abandon path has to reach it.
     */
    public function testAnOnSpawnThrowKillsTheWrappedChildsGroupDescendants(): void
    {
        $this->requirePosixPipes();
        if (!\function_exists('posix_kill') || PHP_OS_FAMILY === 'Windows' || trim((string) shell_exec('command -v setsid')) === '') {
            $this->markTestSkipped('Needs setsid and ext-posix to lead a process group.');
        }
        $marker = \tempnam(\sys_get_temp_dir(), 'cr-abandon-grandchild-');
        self::assertIsString($marker);
        $code = '$p = proc_open(["sleep", "30"], [], $pipes); $s = proc_get_status($p); '
            . 'file_put_contents(' . \var_export($marker, true) . ', (string) $s["pid"]); sleep(30);';
        $grandchild = 0;
        $wrapper = 0;
        try {
            ChildRun::new(['setsid', '-w', '--', PHP_BINARY, '-r', $code])
                ->withOnSpawn(static function (int $spawned) use (&$grandchild, &$wrapper, $marker): void {
                    $wrapper = $spawned;
                    // Hold the throw until the descendant exists, so the group has a live member.
                    $until = \microtime(true) + 5.0;
                    while (\microtime(true) < $until) {
                        $written = (string) @\file_get_contents($marker);
                        if (\ctype_digit($written)) {
                            $grandchild = (int) $written;

                            break;
                        }
                        \usleep(20_000);
                    }

                    throw new \RuntimeException('onSpawn refused');
                })
                ->run();
            self::fail('an onSpawn throw must escape run()');
        } catch (\RuntimeException $e) {
            $this->assertSame('onSpawn refused', $e->getMessage());
        } finally {
            @\unlink($marker);
        }

        $this->assertGreaterThan(0, $grandchild, 'the grandchild must have started before the throw');
        $this->assertFalse(\posix_kill($wrapper, 0), 'the wrapper must be killed and reaped');
        $until = \microtime(true) + 2.0;
        while (\posix_kill($grandchild, 0) && \microtime(true) < $until) {
            \usleep(20_000);
        }
        $this->assertFalse(\posix_kill($grandchild, 0), 'the group kill must reach the grandchild');
    }

    public function testAnAbandonedSpawnRemovesTheStagedPayloadAndReapsTheChild(): void
    {
        // Runs on every platform: the staged file is a plain temp file here, the
        // child is a plain proc_open, so the cleanup is exercised without Windows.
        $staged = \tempnam(\sys_get_temp_dir(), 'staged-abandon-');
        self::assertIsString($staged);
        \file_put_contents($staged, 'payload');

        $pipes = [];
        $proc = \proc_open($this->php('sleep(30);'), [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        self::assertIsResource($proc);

        try {
            $abandon = new \ReflectionMethod(ChildRun::class, 'abandonSpawn');
            $abandon->setAccessible(true);
            $abandon->invoke(null, $proc, $pipes, $staged);

            self::assertFileDoesNotExist($staged);
            self::assertFalse(\is_resource($proc), 'proc_close must have reaped the child');
        } finally {
            @\unlink($staged);
            if (\is_resource($proc)) {
                \proc_terminate($proc, 9);
                \proc_close($proc);
            }
        }
    }

    public function testTheSpawnCallbackCarriesThroughTheBuilderAndNullRemovesIt(): void
    {
        $base = ChildRun::new(['true']);
        $tuned = $base->withOnSpawn(static fn (int $pid) => null);

        $this->assertNotSame($base, $tuned);
        $this->assertNull((new \ReflectionProperty($base, 'onSpawn'))->getValue($base));
        $this->assertNotNull((new \ReflectionProperty($tuned, 'onSpawn'))->getValue($tuned));
        $this->assertNull((new \ReflectionProperty($tuned->withOnSpawn(null), 'onSpawn'))->getValue($tuned->withOnSpawn(null)));
    }

    /**
     * An unnamed group is measured while the child lives: a wrapped child that
     * leads its own group takes its descendants down with it on timeout, the
     * same as a caller that passed the group id explicitly.
     */
    public function testATimedOutWrappedChildTakesItsDescendantsDown(): void
    {
        $this->requirePosixPipes();
        if (!function_exists('posix_kill') || PHP_OS_FAMILY === 'Windows' || trim((string) shell_exec('command -v setsid')) === '') {
            $this->markTestSkipped('Needs setsid and ext-posix to lead a process group.');
        }
        $marker = tempnam(sys_get_temp_dir(), 'cr-grandchild-');
        $code = '$p = proc_open(["sleep", "30"], [], $pipes); $s = proc_get_status($p); '
            . 'file_put_contents(' . var_export($marker, true) . ', (string) $s["pid"]); sleep(30);';
        try {
            $r = ChildRun::new(['setsid', '-w', '--', PHP_BINARY, '-r', $code])
                ->withTimeout(1.5)
                ->run();
            $this->assertSame(ChildResult::TIMED_OUT, $r->status);

            $grandchild = (int) @file_get_contents($marker);
            $this->assertGreaterThan(0, $grandchild, 'the grandchild must have started');
            $until = microtime(true) + 2.0;
            while (posix_kill($grandchild, 0) && microtime(true) < $until) {
                usleep(20_000);
            }
            $this->assertFalse(posix_kill($grandchild, 0), 'the group kill must reach the grandchild');
        } finally {
            @unlink($marker);
        }
    }

    /**
     * withHeartbeat() beats while a silent child runs, so a watchdog keyed on the beat
     * is not starved by a child that writes nothing for its whole run.
     */
    public function testRunBeatsWhileASilentChildRuns(): void
    {
        $this->requirePosixPipes();
        $beats = 0;
        $r = ChildRun::new($this->php('usleep(400000); fwrite(STDOUT, "done");'))
            ->withHeartbeat(0.05, static function () use (&$beats): void {
                $beats++;
            })
            ->run();

        $this->assertSame('done', $r->stdout);
        $this->assertTrue($r->succeeded());
        $this->assertGreaterThanOrEqual(2, $beats, 'a 0.4 s silent run at a 0.05 s heartbeat must beat several times');
    }

    /**
     * A child that closes both pipes and then lingers still needs its beats: the
     * post-close wait for the exit is where it spends its time.
     */
    public function testRunBeatsWhileAChildThatClosedItsPipesStillRuns(): void
    {
        $this->requirePosixPipes();
        $beats = 0;
        $r = ChildRun::new($this->php('fclose(STDOUT); fclose(STDERR); usleep(400000);'))
            ->withHeartbeat(0.05, static function () use (&$beats): void {
                $beats++;
            })
            ->run();

        $this->assertTrue($r->succeeded());
        $this->assertGreaterThanOrEqual(2, $beats, 'the post-close exit wait must beat too');
    }

    /**
     * The heartbeat is a liveness signal and never a verdict: beating does not end a run.
     */
    public function testAHeartbeatDoesNotEndTheRun(): void
    {
        $this->requirePosixPipes();
        $r = ChildRun::new($this->php('usleep(200000); fwrite(STDOUT, "still here");'))
            ->withHeartbeat(0.02, static fn () => null)
            ->run();

        $this->assertSame('still here', $r->stdout);
        $this->assertSame(ChildResult::EXITED, $r->status);
    }

    public function testWithHeartbeatRejectsANonPositiveInterval(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        ChildRun::new(['true'])->withHeartbeat(0.0, static fn () => null);
    }

    /**
     * A wrapped child whose leader exits at once while a backgrounded grandchild
     * holds the pipes: the group must be taken from the pid at spawn, so the
     * deadline kill still reaches the grandchild after the leader is gone.
     */
    public function testWithGroupLeaderKillsTheGroupAfterTheLeaderExited(): void
    {
        $this->requirePosixPipes();
        if (!\function_exists('posix_kill') || !is_executable('/usr/bin/setsid')) {
            $this->markTestSkipped('needs posix_kill and /usr/bin/setsid');
        }
        $pidFile = tempnam(sys_get_temp_dir(), 'childrun-leader-');
        try {
            $r = ChildRun::new(['/usr/bin/setsid', '-w', '/bin/sh', '-c', 'sleep 30 & echo $! > ' . escapeshellarg((string) $pidFile) . '; exit 0'])
                ->withGroupLeader(true)
                ->withExitGrace(null)
                ->withTimeout(0.5)
                ->run();

            $this->assertSame(ChildResult::TIMED_OUT, $r->status);
            $grandchild = (int) trim((string) file_get_contents((string) $pidFile));
            $this->assertGreaterThan(0, $grandchild, 'the fixture never started its backgrounded part');
            $deadline = microtime(true) + 2.0;
            while (\posix_kill($grandchild, 0) && microtime(true) < $deadline) {
                usleep(20000);
            }
            $this->assertFalse(\posix_kill($grandchild, 0), 'the group kill must reach the grandchild');
        } finally {
            @unlink((string) $pidFile);
        }
    }

    /**
     * Regression: a cancel that lands after the probe has reaped a setsid-wrapped shell.
     * The shell exits at once and leaves a subshell and a sleep holding stdout, both in
     * its group. The subshell's line arrives a second later and cancels the run, so the
     * running probe has already reaped the shell and the live group read can no longer
     * name it. The kill must still reach the background job through the wrapper's pid.
     */
    public function testACancelAfterTheWrappedShellWasReapedStillKillsItsJob(): void
    {
        $this->requirePosixPipes();
        if (!\function_exists('posix_kill') || PHP_OS_FAMILY === 'Windows' || !\is_executable('/usr/bin/setsid')) {
            $this->markTestSkipped('needs posix_kill, /usr/bin/setsid and a POSIX host');
        }

        $source = CancellationSource::new();
        $grandchild = null;
        $run = ChildRun::new(['/usr/bin/setsid', 'sh', '-c', 'sleep 31 & echo $!; (sleep 1; echo tick) & exit 0'])
            ->withTimeout(10.0)
            ->withExitGrace(3.0)
            ->withSetsidWrapped(true)
            ->withCancellation($source->token())
            ->withOnChunk(static function (int|string $slot, string $chunk) use (&$grandchild, $source): void {
                if ((string) $slot !== '1') {
                    return;
                }
                if ($grandchild === null) {
                    $grandchild = (int) trim($chunk);
                    return;
                }
                if (trim($chunk) === 'tick') {
                    $source->cancel();
                }
            });

        $result = $run->run();

        $this->assertSame(ChildResult::CANCELLED, $result->status);
        $this->assertIsInt($grandchild, 'the grandchild pid must be read from stdout');
        $until = microtime(true) + 3.0;
        while (!self::grandchildGone($grandchild) && microtime(true) < $until) {
            usleep(20_000);
        }
        $this->assertTrue(self::grandchildGone($grandchild), 'the cancel must reach the job of the reaped shell');
    }

    /**
     * Gone means signal-refused or a zombie: a PID 1 that does not reap leaves a killed
     * grandchild a zombie, which a bare posix_kill($pid, 0) would count as alive.
     */
    private static function grandchildGone(int $pid): bool
    {
        if (!@posix_kill($pid, 0)) {
            return true;
        }

        $stat = @file_get_contents('/proc/' . $pid . '/stat');
        if ($stat === false) {
            return false;
        }
        $after = strrpos($stat, ')');
        $state = $after === false ? '' : substr(trim(substr($stat, $after + 1)), 0, 1);

        return $state === 'Z' || $state === 'X';
    }
}
