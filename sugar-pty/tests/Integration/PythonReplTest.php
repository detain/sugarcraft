<?php

declare(strict_types=1);

namespace SugarCraft\Pty\Tests\Integration;

/**
 * End-to-end smoke test for `python3 -i` under a real PTY.
 *
 * Mirrors creack/pty integration tests that drive Python's REPL — we
 * cover both a single-statement echo and the multi-line `for`-loop
 * continuation (spread across lines, terminated by a blank line) —
 * the shape that exercises the REPL's `...` prompt, which only behaves
 * correctly when stdin is a real tty.

 */
final class PythonReplTest extends InteractiveShellTestCase
{
    private const PYTHON3_PATH = '/usr/bin/python3';
    private const PYTHON_PATH = '/usr/bin/python';

    public function testPythonReplSingleLineEcho(): void
    {
        $this->requirePtySyscalls();
        $binary = $this->resolvePythonBinary();

        // -i forces an interactive prompt even though stdin is fed.
        // -q suppresses the version/copyright banner so the prompt
        //    arrives first; `print("hi")` emits the marker; `exit()`
        //    drops us cleanly out of the REPL.
        $output = $this->runShellRoundTrip(
            [$binary, '-i', '-q'],
            "print(\"hi\")\nexit()\n",
        );

        $this->assertStringContainsString(
            'hi',
            $output,
            'python3 -i must echo "hi" within the 5s wallclock budget',
        );
    }

    public function testPythonReplMultiLineContinuation(): void
    {
        $this->requirePtySyscalls();
        $binary = $this->resolvePythonBinary();

        // Heredoc-style multi-line: open a `for` loop, send the
        // indented body on continuation lines, then a blank line to
        // terminate the suite, then exit(). Each line ends with \n so
        // the REPL advances from `>>>` to `...` and back.
        $script = "for n in range(3):\n"
            . "    print(\"line\" + str(n))\n"
            . "\n"
            . "print(\"hi\")\n"
            . "exit()\n";

        $output = $this->runShellRoundTrip(
            [$binary, '-i', '-q'],
            $script,
            budget: 7.0,
        );

        $this->assertStringContainsString('line0', $output, 'loop iter 0 must print');
        $this->assertStringContainsString('line1', $output, 'loop iter 1 must print');
        $this->assertStringContainsString('line2', $output, 'loop iter 2 must print');
        $this->assertStringContainsString('hi', $output, 'post-loop marker must print');
    }

    private function resolvePythonBinary(): string
    {
        if (\is_executable(self::PYTHON3_PATH)) {
            return self::PYTHON3_PATH;
        }
        if (\is_executable(self::PYTHON_PATH)) {
            return self::PYTHON_PATH;
        }
        $this->markTestSkipped('python3/python not installed');
    }
}
