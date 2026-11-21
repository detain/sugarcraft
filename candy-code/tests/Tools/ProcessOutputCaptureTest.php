<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Tools;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Tools\BuiltIn\Bash;

/**
 * Regression guard: a tool subprocess must never write to the real terminal.
 *
 * `exec()` captures stdout only — the child inherits the PHP process's stderr,
 * so anything written there lands on the actual terminal. Underneath a running
 * TUI that paints outside the managed frame at whatever cursor position the
 * terminal is at, and survives until the next full repaint. Reported from a
 * live session: `grep: write error: Broken pipe` appeared at the bottom-left,
 * outside the chat area, then vanished when the reply arrived and the screen
 * was rebuilt.
 *
 * The same leak also lost the diagnostic: stderr is where a failing command
 * explains itself, so the model received an empty result flagged as an error
 * with no reason in it.
 */
final class ProcessOutputCaptureTest extends TestCase
{
    /** The actual reported symptom: a broken-pipe warning must not escape. */
    public function testBrokenPipeStderrDoesNotReachTheTerminal(): void
    {
        $tool = new Bash();

        ob_start();
        $result = $tool->execute([
            'command' => 'grep -rn needle /etc/hostname | head -1; echo finished',
            'id' => 'call_1',
        ]);
        $leaked = (string) ob_get_clean();

        $this->assertSame('', $leaked, 'tool stderr leaked to the terminal');
        $this->assertStringContainsString('finished', $result->content());
    }

    /** Nothing a command writes to stderr may escape, broken pipe or not. */
    public function testArbitraryStderrIsCapturedNotPrinted(): void
    {
        $tool = new Bash();

        ob_start();
        $result = $tool->execute([
            'command' => 'echo to-stdout; echo to-stderr 1>&2',
            'id' => 'call_2',
        ]);
        $leaked = (string) ob_get_clean();

        $this->assertSame('', $leaked);
        // Succeeded with real stdout, so the stderr TEXT stays out of the
        // result -- but its existence is announced, because a dropped stream
        // nobody mentions reads to the model as a stream that was never
        // written. Asserted as an exact string so the marker cannot be lost or
        // reworded silently.
        $this->assertSame(
            "to-stdout\n... [stderr suppressed: the command succeeded and also wrote to stderr; "
            . "re-run with 2>&1 to see it]",
            $result->content(),
        );
        $this->assertStringNotContainsString('to-stderr', $result->content(), 'the text itself must not be merged in');
        $this->assertFalse($result->isError());
    }

    /**
     * The diagnostic half: a failing command must hand the model the reason,
     * not an empty string with an error flag.
     */
    public function testFailingCommandSurfacesItsStderrToTheModel(): void
    {
        $tool = new Bash();

        ob_start();
        $result = $tool->execute(['command' => 'ls /definitely-not-here-xyz', 'id' => 'call_3']);
        $leaked = (string) ob_get_clean();

        $this->assertSame('', $leaked);
        $this->assertTrue($result->isError());
        $this->assertNotSame('', $result->content(), 'a failing command must explain itself');
        $this->assertStringContainsString('No such file', $result->content());
    }

    /** A command reading stdin must not hang the agent forever. */
    public function testCommandReadingStdinDoesNotBlock(): void
    {
        $tool = new Bash();

        $started = microtime(true);
        $result = $tool->execute(['command' => 'cat', 'id' => 'call_4']);
        $elapsed = microtime(true) - $started;

        $this->assertLessThan(10.0, $elapsed, 'stdin must be closed so the child cannot block');
        $this->assertSame('', $result->content());
    }

    /**
     * S-T05: the spawn-failure refusal must survive the array (direct-exec
     * argv) form of $command — strlen()/substr() once TypeErrored on it
     * before the promised exit-127 result could be returned.
     */
    public function testSpawnFailureWithArrayArgvYieldsExit127(): void
    {
        $host = new class {
            use \SugarCraft\Code\Tools\Concerns\CapturesProcessOutput;

            /** @param string|list<string> $command @return array<string,mixed> */
            public function capture(string|array $command, string $cwd): array
            {
                return $this->runCaptured($command, $cwd);
            }
        };
        $missing = sys_get_temp_dir() . '/candy-code-no-such-dir-' . bin2hex(random_bytes(6));

        foreach ([['x', str_repeat('y', 200)], 'x ' . str_repeat('y', 200)] as $command) {
            $r = $host->capture($command, $missing);
            $this->assertSame(127, $r['exitCode']);
            $this->assertStringContainsString('Could not start the command x ', $r['stderr']);
            $this->assertStringContainsString('...', $r['stderr']);
        }
    }

    /**
     * A pipe failure (ChildRun's PIPE_FAILED) ends the read early and kills the
     * child, and the exit code that comes back is the kill's. The result must say
     * so on stderr, the tail every cap keeps, so the code is not read as the
     * command's own answer. ChildRun cannot be made to fail its pipes on demand,
     * so the mapping is driven with the result such a run returns.
     */
    public function testAPipeFailureIsNamedOnStderrAndNotPassedOffAsAnExit(): void
    {
        $map = new \ReflectionMethod(self::captureHost(), 'capturedResult');
        $map->setAccessible(true);

        $r = $map->invoke(null, new \SugarCraft\Async\ChildResult('partial out', "half a li", -1, \SugarCraft\Async\ChildResult::PIPE_FAILED, 5, 5), false);

        $this->assertSame(-1, $r['exitCode'], 'the exit code stays the real outcome');
        $this->assertFalse($r['timedOut']);
        $this->assertSame('partial out', $r['stdout']);
        $this->assertStringStartsWith("half a li\n[output capture failed:", $r['stderr']);
        $this->assertStringContainsString('may be incomplete', $r['stderr']);
        $this->assertFalse($r['stderrMidLine'], 'the note ends stderr on a whole line, so no repair may drop it');

        $clean = $map->invoke(null, new \SugarCraft\Async\ChildResult('out', '', 0, \SugarCraft\Async\ChildResult::EXITED, 0), false);
        $this->assertSame('', $clean['stderr'], 'an ordinary exit carries no note');
    }

    /**
     * The empty-command refusal says what is true of it: the command was empty.
     * The process-limit hint belongs to a spawn the system refused, and here it
     * would point at the wrong cause. With no detach wrapper an empty string
     * reaches ChildRun as-is and is refused before any spawn.
     */
    public function testAnEmptyCommandIsRefusedWithoutTheProcessLimitHint(): void
    {
        \SugarCraft\Code\Support\ProcessContainment::setDetachedSpawnCandidatesForTesting([]);
        try {
            $r = self::captureHost()->capture('', null);
        } finally {
            \SugarCraft\Code\Support\ProcessContainment::setDetachedSpawnCandidatesForTesting(null);
        }

        $this->assertSame(127, $r['exitCode']);
        $this->assertStringContainsString('the command is empty', $r['stderr']);
        $this->assertStringNotContainsString('ulimit', $r['stderr']);
        $this->assertStringNotContainsString('not a problem with the command text', $r['stderr']);
    }

    /** An unreadable stdin file is the request's problem, not the system's: no process-limit hint. */
    public function testAnUnreadableStdinFileIsRefusedWithoutTheProcessLimitHint(): void
    {
        $missing = sys_get_temp_dir() . '/candy-code-no-such-stdin-' . bin2hex(random_bytes(6));
        $r = self::captureHost()->capture('cat', null, $missing);

        $this->assertSame(127, $r['exitCode']);
        $this->assertStringContainsString('could not be read', $r['stderr']);
        $this->assertStringNotContainsString('ulimit', $r['stderr']);
    }

    /** A spawn the system refused keeps the process-limit hint. */
    public function testASystemSpawnRefusalKeepsTheProcessLimitHint(): void
    {
        $refuse = new \ReflectionMethod(self::captureHost(), 'spawnRefused');
        $refuse->setAccessible(true);

        $r = $refuse->invoke(null, 'true', 'proc_open() failed', 'fork: Resource temporarily unavailable', true);

        $this->assertSame(127, $r['exitCode']);
        $this->assertStringContainsString('proc_open() failed (fork: Resource temporarily unavailable)', $r['stderr']);
        $this->assertStringContainsString('ulimit -u', $r['stderr']);
    }

    /**
     * A stdin payload the Windows runtime cannot stage is a temp-directory problem:
     * it is named as one, with tempnam()'s warning as the cause, and never carries
     * the process-limit hint. The staging path runs only on the Windows runtime and
     * there is no seam to force it on another host, so the refusal mapping is driven
     * directly with the exception ChildRun raises there.
     */
    public function testAStdinStagingFailureIsReportedAsATempFileProblemNotAProcessLimit(): void
    {
        $map = new \ReflectionMethod(self::captureHost(), 'startFailed');
        $map->setAccessible(true);
        $failure = new \SugarCraft\Async\StdinStagingException('ChildRun: could not stage stdin for cmd', tempDir: 'C:\\Temp');

        $r = $map->invoke(null, 'cat', $failure, 'tempnam(): file created in the system\'s temporary directory');

        $this->assertSame(127, $r['exitCode']);
        $this->assertStringContainsString('its stdin could not be staged: no temp file could be created and written in C:\\Temp', $r['stderr']);
        $this->assertStringContainsString('(tempnam(): file created', $r['stderr']);
        $this->assertStringNotContainsString('ulimit', $r['stderr']);
        $this->assertStringNotContainsString('proc_open() failed', $r['stderr']);
        $this->assertStringNotContainsString('system-level spawn failure', $r['stderr']);
    }

    /** Any other refused start is still proc_open() refusing the spawn, hint included. */
    public function testAnyOtherRefusedStartKeepsTheProcessLimitHint(): void
    {
        $map = new \ReflectionMethod(self::captureHost(), 'startFailed');
        $map->setAccessible(true);

        $r = $map->invoke(null, 'true', new \RuntimeException('ChildRun: could not start true'), 'fork: Resource temporarily unavailable');

        $this->assertSame(127, $r['exitCode']);
        $this->assertStringContainsString('proc_open() failed (fork: Resource temporarily unavailable)', $r['stderr']);
        $this->assertStringContainsString('ulimit -u', $r['stderr']);
    }

    private static function captureHost(): object
    {
        return new class {
            use \SugarCraft\Code\Tools\Concerns\CapturesProcessOutput;

            /** @param string|list<string> $command @return array<string,mixed> */
            public function capture(string|array $command, ?string $cwd, ?string $stdinFile = null): array
            {
                return $this->runCaptured($command, $cwd, stdinFile: $stdinFile);
            }
        };
    }
}
