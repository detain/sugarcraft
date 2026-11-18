<?php

declare(strict_types=1);

namespace SugarCraft\Async\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
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
    }

    #[DataProvider('badBuilders')]
    public function testRejectsBadBuilderValues(callable $apply): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $apply(ChildRun::new(['true']));
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
}
