<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Providers;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Providers\ClaudeCodeInvocation;
use SugarCraft\Crush\Providers\ClaudeCodeProvider;
use SugarCraft\Crush\Providers\CompleteRequest;
use SugarCraft\Crush\Messages\UserMessage;

/**
 * {@see ClaudeCodeProvider::completeStream()} spawns a child, and E366 asked
 * what happens to it. Three separate defects, each MEASURED on this host
 * (PHP 8.3.6, Linux 6.8) rather than reasoned about.
 *
 * 1. THE DIAGNOSTIC WAS A TYPE ERROR, not a blank string. The method
 *    `fclose($pipes[2])`d and then, a few lines later,
 *    `stream_get_contents($pipes[2])`. The brief for this work described the
 *    result as the exception message reading `Claude Code exited with code N:`
 *    with the reason blank. It does not: `stream_get_contents()` on a closed
 *    resource raises `TypeError: stream_get_contents(): supplied resource is
 *    not a valid stream resource`, and `@` does not suppress a TypeError. The
 *    `RuntimeException` was therefore never CONSTRUCTED on any non-zero exit,
 *    so every caller catching `\RuntimeException` around this generator caught
 *    nothing at all. That is worse than a blank reason and it is a different
 *    bug — see
 *    {@see testANonZeroExitThrowsARuntimeExceptionCarryingTheChildsStderr()}.
 *
 * 2. IT DEADLOCKED ON A NOISY CHILD. Only stdout was read inside the loop, and
 *    stderr was read once, after `proc_close()`. A child that writes more than
 *    one pipe buffer to stderr blocks in its own `write()`, never closes
 *    stdout, and the loop never reaches EOF. MEASURED with a child writing N
 *    bytes to stderr then a line to stdout: N = 1000 and N = 60000 drain in
 *    0.04s, N = 100000 never completes — the pipe buffer here is 64 KiB. A
 *    failing `claude` run is exactly what produces six figures of stderr, so
 *    the hang was on the failure path only.
 *
 * 3. AN ABANDONED STREAM ORPHANED THE CHILD. A consumer that `break`s out of
 *    the `foreach` destroys the generator, and the `proc_open()` handle was
 *    simply dropped. MEASURED: dropping a handle whose child is still RUNNING
 *    takes 0.000s and leaves it in state `S` — the resource destructor reaps an
 *    already-exited child but never waits for a live one. The `claude` process
 *    kept running under pid 1 holding every inherited descriptor above 2, which
 *    is E366's shape and E365's consequence.
 *
 * WHY EVERY FIXTURE ARMS `pcntl_alarm()`. Defect 2 is a HANG, and a test for a
 * hang must not be able to hang the suite. Each stub sets an alarm with SIGALRM
 * at its default disposition, so a regression kills the child at the alarm, the
 * pipes close, the loop ends, and this file FAILS on its duration assertion
 * instead of wedging a five-lane run.
 */
final class ClaudeCodeProviderStreamReapTest extends TestCase
{
    /** Generous against the fixtures' millisecond work, tight against a 15s alarm. */
    private const BOUND_SECONDS = 10.0;

    private string $tempDir = '';

    /** @var list<int> pids a fixture reported for itself, killed on the way out */
    private array $reportedPids = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->tempDir = sys_get_temp_dir() . '/sc_cc_stream_' . getmypid() . '_' . bin2hex(random_bytes(6));
        mkdir($this->tempDir, 0o755, true);
    }

    protected function tearDown(): void
    {
        // ONLY pids this test's own fixtures wrote down — never a pattern sweep.
        foreach ($this->reportedPids as $pid) {
            if (function_exists('posix_kill')) {
                @posix_kill($pid, 9);
            }
        }
        $this->reportedPids = [];

        foreach (glob($this->tempDir . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->tempDir);

        parent::tearDown();
    }

    /**
     * A `RuntimeException`, actually thrown, actually carrying the reason. Under
     * the pre-fix ordering this is a `TypeError` about a stream resource, so
     * `expectException(\RuntimeException::class)` is the assertion that catches
     * defect 1 — and the message assertion is what stops a fix that reorders the
     * calls but reads a pipe nobody filled.
     */
    public function testANonZeroExitThrowsARuntimeExceptionCarryingTheChildsStderr(): void
    {
        $provider = $this->providerOver(<<<'PHP'
            <?php
            pcntl_alarm(15);
            fwrite(STDERR, 'ENOENT: model unavailable');
            exit(3);
            PHP);

        try {
            iterator_to_array($provider->completeStream($this->request()));
            $this->fail('a non-zero exit must throw');
        } catch (\TypeError $e) {
            $this->fail(
                'completeStream() threw a TypeError rather than a RuntimeException: ' . $e->getMessage()
                . ' — stderr is being read from a pipe that was already fclose()d'
            );
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('exited with code 3', $e->getMessage());
            $this->assertStringContainsString(
                'ENOENT: model unavailable',
                $e->getMessage(),
                "the child's stderr is the only diagnostic on this path and must reach the message",
            );
        }
    }

    /**
     * THE KNOWN-POSITIVE CONTROL for the assertion above. An empty-reason
     * message would satisfy `assertStringContainsString('exited with code 3')`
     * on its own, so this proves the stderr channel is genuinely being read
     * rather than the test passing on the half that never broke: a child that
     * says NOTHING on stderr must produce a message with nothing after the
     * colon, from the same code path that produced the text above.
     */
    public function testAChildThatSaysNothingOnStderrProducesAnEmptyReason(): void
    {
        $provider = $this->providerOver(<<<'PHP'
            <?php
            pcntl_alarm(15);
            exit(4);
            PHP);

        try {
            iterator_to_array($provider->completeStream($this->request()));
            $this->fail('a non-zero exit must throw');
        } catch (\RuntimeException $e) {
            $this->assertSame('Claude Code exited with code 4: ', $e->getMessage());
        }
    }

    /**
     * THE TRUNCATION KEEPS THE TAIL, and this is the only thing that says so.
     *
     * `clipStderr()`'s doc-block argues the tail because "the reason a process
     * gives is the last thing it says", and a head-keeping truncation would
     * reliably preserve a startup banner and drop the actual error. MEASURED:
     * with no test here, reversing `substr($errors, -N)` to
     * `substr($errors, 0, N)` SURVIVED the whole file. The fixture writes a
     * recognisable banner, then a megabyte of filler, then the real reason last
     * — so the two directions are distinguishable rather than merely both large.
     */
    public function testStderrTruncationKeepsTheTailWhereTheReasonIs(): void
    {
        $provider = $this->providerOver(<<<'PHP'
            <?php
            pcntl_alarm(15);
            fwrite(STDERR, 'BANNER-node-v22-experimental-warning');
            fwrite(STDERR, str_repeat('.', 1000000));
            fwrite(STDERR, 'REASON-model-quota-exhausted');
            exit(7);
            PHP);

        try {
            iterator_to_array($provider->completeStream($this->request()));
            $this->fail('a non-zero exit must throw');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString(
                'REASON-model-quota-exhausted',
                $e->getMessage(),
                'the LAST thing the child said is the reason it exited and must survive truncation',
            );
            $this->assertStringNotContainsString(
                'BANNER-node-v22-experimental-warning',
                $e->getMessage(),
                'a head-keeping truncation preserves the banner and drops the error — the wrong half',
            );
            $this->assertLessThan(
                200000,
                strlen($e->getMessage()),
                'a megabyte of stderr must not be carried whole into an exception message',
            );
        }
    }

    /**
     * DEFECT 2. 200000 bytes is comfortably past the 64 KiB pipe buffer measured
     * on this host, and past the 100000 that was already observed never to
     * complete. Pre-fix this does not fail slowly — it does not finish.
     */
    public function testAChildWritingFarMoreThanOnePipeBufferToStderrDoesNotDeadlock(): void
    {
        $provider = $this->providerOver(<<<'PHP'
            <?php
            pcntl_alarm(15);
            fwrite(STDERR, str_repeat('E', 200000));
            fwrite(STDOUT, "data: " . json_encode(['event' => ['delta' => ['type' => 'text_delta', 'text' => 'hi']]]) . "\n");
            exit(0);
            PHP);

        $start = microtime(true);
        $chunks = iterator_to_array($provider->completeStream($this->request()));
        $elapsed = microtime(true) - $start;

        $this->assertLessThan(
            self::BOUND_SECONDS,
            $elapsed,
            sprintf(
                'completeStream() took %.2fs; stderr must be drained in the same loop as stdout, or a '
                . 'child that fills the stderr pipe blocks in write() and stdout never reaches EOF',
                $elapsed,
            ),
        );
        $this->assertSame('hi', $chunks[0]->content, 'the stdout stream must still be parsed');
    }

    /**
     * DEFECT 3. The consumer takes one chunk and walks away; the child must be
     * dead by the time the generator is destroyed. Without the `finally` the
     * handle is dropped and the child keeps running — measured, 0.000s, state
     * `S`.
     */
    public function testAbandoningTheStreamKillsTheChild(): void
    {
        $provider = $this->providerOver(<<<'PHP'
            <?php
            pcntl_alarm(30);
            file_put_contents('%PID_FILE%', (string) getmypid());
            fwrite(STDOUT, "data: " . json_encode(['event' => ['delta' => ['type' => 'text_delta', 'text' => 'first']]]) . "\n");
            $deadline = microtime(true) + 25.0;
            while (microtime(true) < $deadline) {
                usleep(20000);
            }
            PHP);

        $stream = $provider->completeStream($this->request());
        foreach ($stream as $chunk) {
            $this->assertSame('first', $chunk->content);
            break;
        }

        $pid = $this->selfReportedPid();
        unset($stream);
        gc_collect_cycles();

        $this->assertFalse(
            $this->isAlive($pid),
            "pid {$pid} survived the abandoned stream; the generator's finally block must reap the child, "
            . 'or a consumer that breaks out of the foreach leaves a claude process under pid 1',
        );
    }

    /**
     * THE CLEAN-PATH CONTROL. Everything above is about failure, and a teardown
     * that killed the child too eagerly — before its output was read, or before
     * it had exited on its own — would satisfy all of it while breaking the only
     * case that actually happens. A zero exit must yield its chunks and throw
     * nothing.
     */
    public function testACleanRunYieldsEveryChunkAndDoesNotThrow(): void
    {
        $provider = $this->providerOver(<<<'PHP'
            <?php
            pcntl_alarm(15);
            foreach (['alpha', 'beta', 'gamma'] as $word) {
                fwrite(STDOUT, "data: " . json_encode(['event' => ['delta' => ['type' => 'text_delta', 'text' => $word]]]) . "\n");
            }
            exit(0);
            PHP);

        $chunks = iterator_to_array($provider->completeStream($this->request()));

        $this->assertSame(
            ['alpha', 'beta', 'gamma'],
            array_map(static fn ($c): string => $c->content, $chunks),
        );
    }

    /**
     * A provider whose `claude` binary is $source.
     *
     * `claudePath` is used as argv[0] of an ARGV-form `proc_open()`, so a
     * `#!`-headed script at a real path is all a stub needs to be — no shell,
     * and therefore no dash wrapper standing between the signal and the child.
     */
    private function providerOver(string $source): ClaudeCodeProvider
    {
        // The pid file path is SUBSTITUTED INTO the stub rather than passed as an
        // argument. `ClaudeCodeInvocation` decides this child's whole argv —
        // `baseArgs()` then `printModeArgs()` — and a test that smuggled a path
        // through one of those fields would be asserting on argv ordering it
        // does not own, and would break the moment either method grew a flag.
        $source = str_replace('%PID_FILE%', $this->pidFile(), $source);

        $script = $this->tempDir . '/claude';
        // A shebang, because `claudePath` becomes argv[0] of an ARGV-form
        // `proc_open()` — there is no shell to interpret the file, so the kernel
        // has to know what to run it with.
        $withShebang = str_replace('<?php', '#!' . PHP_BINARY . "\n<?php", $source, $count);
        $this->assertSame(1, $count, 'the stub source must contain exactly one PHP opener to prefix');
        file_put_contents($script, $withShebang);
        chmod($script, 0o755);

        @unlink($this->pidFile());

        return new ClaudeCodeProvider(new ClaudeCodeInvocation(
            claudePath: $script,
            configDir: $this->tempDir,
        ));
    }

    private function request(): CompleteRequest
    {
        return new CompleteRequest(model: 'claude-sonnet-4-6', messages: [new UserMessage('hi')]);
    }

    private function pidFile(): string
    {
        return $this->tempDir . '/child.pid';
    }

    private function selfReportedPid(): int
    {
        $deadline = microtime(true) + 5.0;
        while (microtime(true) < $deadline) {
            $raw = @file_get_contents($this->pidFile());
            if (is_string($raw) && ctype_digit(trim($raw))) {
                $pid = (int) trim($raw);
                if (!in_array($pid, $this->reportedPids, true)) {
                    $this->reportedPids[] = $pid;
                }

                return $pid;
            }
            usleep(20000);
        }

        $this->fail('the fixture child never reported its pid');
    }

    private function isAlive(int $pid): bool
    {
        if (!function_exists('posix_kill')) {
            $this->markTestSkipped('ext-posix is required to observe process liveness');
        }

        // A pid that exited but was not waited for is a ZOMBIE, and
        // `posix_kill($pid, 0)` answers true for one. The state field is what
        // separates "still running" from "dead and already reaped".
        $stat = @file_get_contents("/proc/{$pid}/stat");
        if ($stat === false) {
            return false;
        }

        return (explode(' ', $stat)[2] ?? 'Z') !== 'Z';
    }
}
