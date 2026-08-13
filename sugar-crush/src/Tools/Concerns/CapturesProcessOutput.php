<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tools\Concerns;

/**
 * Runs a shell command capturing BOTH stdout and stderr.
 *
 * `exec()` captures only stdout. The child inherits the PHP process's stderr,
 * so anything a command writes there goes straight to the real terminal —
 * underneath a running TUI that means text painted outside the managed frame,
 * at whatever cursor position the terminal happens to be at, surviving until
 * the next full repaint. A `grep … | head` pipeline emitting
 * `grep: write error: Broken pipe` was enough to corrupt the display.
 *
 * It is also a diagnostic loss: stderr is where a failing command explains
 * itself, so `exec()` handed the model an empty result with an error flag and
 * no reason. Capturing it means the agent can actually see why something
 * failed.
 *
 * stdout and stderr are kept SEPARATE rather than merged with `2>&1`, because
 * plenty of well-behaved tools write progress and warnings to stderr on a
 * successful run and folding that into the result would corrupt the output
 * the model reasons about.
 */
trait CapturesProcessOutput
{
    /**
     * $maxBytes bounds what is RETAINED per stream, not what is read: the
     * pipes are still drained to completion so the child never blocks on a
     * full buffer, but bytes past the bound are counted and discarded instead
     * of accumulated. Without it a `cat` of a multi-gigabyte file is an
     * out-of-memory kill of the agent itself, long before {@see
     * TruncatesOutput} would get a chance to clip the finished string. Null
     * keeps the capture unbounded for callers that want the whole thing.
     *
     * The discard counts are reported PER STREAM, not only as a total. Which
     * stream lost bytes is not bookkeeping trivia: {@see
     * mergeCapturedOutput()} routinely drops one stream from the result
     * entirely, and folding that stream's discards into the reported loss
     * labels a complete answer partial — bytes that were never going to
     * appear at any cap counted as if the cap had cost them. `truncatedBytes`
     * remains the sum for callers that genuinely want both.
     *
     * `stdoutMidLine`/`stderrMidLine` say whether the bound landed INSIDE a
     * line. The bound is a byte count, so it lands wherever it lands; the
     * retained text can therefore end on half a path or half a grep hit,
     * which the presentation layer has to repair (it cannot infer this from a
     * discard count alone, since a cut that happened to fall on a newline
     * needs no repair).
     *
     * @return array{
     *     stdout: string,
     *     stderr: string,
     *     exitCode: int,
     *     truncatedBytes: int,
     *     stdoutDropped: int,
     *     stderrDropped: int,
     *     stdoutMidLine: bool,
     *     stderrMidLine: bool,
     * }
     */
    private function runCaptured(string $command, ?string $cwd = null, ?int $maxBytes = null): array
    {
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = @proc_open($command, $descriptors, $pipes, $cwd);
        if (!is_resource($process)) {
            return [
                'stdout' => '',
                'stderr' => 'Failed to start process',
                'exitCode' => 127,
                'truncatedBytes' => 0,
                'stdoutDropped' => 0,
                'stderrDropped' => 0,
                'stdoutMidLine' => false,
                'stderrMidLine' => false,
            ];
        }

        // Close stdin immediately: a command that reads from it (and nothing
        // supplies input) would otherwise block the agent forever.
        fclose($pipes[0]);

        // Non-blocking reads on both pipes. Draining only stdout first can
        // deadlock: a command producing more stderr than the pipe buffer holds
        // blocks writing it while we sit waiting on stdout.
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $stdout = '';
        $stderr = '';
        $stdoutDropped = 0;
        $stderrDropped = 0;
        while (!feof($pipes[1]) || !feof($pipes[2])) {
            $read = array_filter([$pipes[1], $pipes[2]], static fn($p) => !feof($p));
            if ($read === []) {
                break;
            }
            $write = $except = null;
            if (@stream_select($read, $write, $except, 0, 200000) === false) {
                break;
            }
            foreach ($read as $pipe) {
                $chunk = fread($pipe, 8192);
                if ($chunk === false || $chunk === '') {
                    continue;
                }
                if ($pipe === $pipes[1]) {
                    $stdout = self::appendBounded($stdout, $chunk, $maxBytes, $stdoutDropped);
                } else {
                    $stderr = self::appendBounded($stderr, $chunk, $maxBytes, $stderrDropped);
                }
            }
        }

        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);

        // Decided BEFORE the rtrim: a stream that lost bytes but happened to
        // stop on a newline is intact, and repairing it anyway would throw
        // away a complete line for nothing.
        $stdoutMidLine = $stdoutDropped > 0 && !str_ends_with($stdout, "\n");
        $stderrMidLine = $stderrDropped > 0 && !str_ends_with($stderr, "\n");

        return [
            'stdout' => rtrim($stdout, "\n"),
            'stderr' => rtrim($stderr, "\n"),
            'exitCode' => $exitCode,
            'truncatedBytes' => $stdoutDropped + $stderrDropped,
            'stdoutDropped' => $stdoutDropped,
            'stderrDropped' => $stderrDropped,
            'stdoutMidLine' => $stdoutMidLine,
            'stderrMidLine' => $stderrMidLine,
        ];
    }

    /**
     * Append as much of $chunk as the bound still allows, adding the remainder
     * to $discarded.
     *
     * The partial-append case matters: a bound reached mid-chunk keeps the
     * prefix rather than dropping the whole 8 KiB read, so the retained output
     * lands on the bound instead of somewhere up to a chunk short of it.
     */
    private static function appendBounded(string $buffer, string $chunk, ?int $maxBytes, int &$discarded): string
    {
        if ($maxBytes === null || $maxBytes <= 0) {
            return $buffer . $chunk;
        }

        $room = $maxBytes - strlen($buffer);
        if ($room <= 0) {
            $discarded += strlen($chunk);

            return $buffer;
        }

        if (strlen($chunk) <= $room) {
            return $buffer . $chunk;
        }

        $discarded += strlen($chunk) - $room;

        return $buffer . substr($chunk, 0, $room);
    }

    /**
     * Decide which captured streams belong in the result, and hand the
     * presentation layer everything it needs to bound them honestly.
     *
     * stderr is surfaced when the command FAILED (that is where the reason
     * lives) or when it succeeded silently on stdout but said something on
     * stderr. A successful command with real stdout keeps its output clean.
     *
     * The return is a two-part shape rather than one joined string because
     * the two parts have different claims on a limited budget. `head` is the
     * bulk answer and `tail` is the trailing explanation; concatenating them
     * first and clipping from the front afterwards is precisely how the
     * explanation gets lost, since it is by construction the last thing in
     * the string. {@see TruncatesOutput::truncateMerged()} is what acts on
     * the distinction.
     *
     * `dropped` counts ONLY the streams that made it into this result.
     * Reporting the discards of a stream this merge just threw away tells the
     * model a complete answer is partial and sends it back to re-run a
     * command that already answered the question.
     *
     * @param array{
     *     stdout: string,
     *     stderr: string,
     *     exitCode: int,
     *     stdoutDropped?: int,
     *     stderrDropped?: int,
     *     stdoutMidLine?: bool,
     *     stderrMidLine?: bool,
     * } $run
     *
     * @return array{head: string, tail: string, dropped: int, headMidLine: bool, tailMidLine: bool}
     */
    private function mergeCapturedOutput(array $run): array
    {
        $stdoutDropped = $run['stdoutDropped'] ?? 0;
        $stderrDropped = $run['stderrDropped'] ?? 0;
        $stdoutMidLine = $run['stdoutMidLine'] ?? false;
        $stderrMidLine = $run['stderrMidLine'] ?? false;

        $onlyStdout = [
            'head' => $run['stdout'],
            'tail' => '',
            'dropped' => $stdoutDropped,
            'headMidLine' => $stdoutMidLine,
            'tailMidLine' => false,
        ];
        $onlyStderr = [
            'head' => $run['stderr'],
            'tail' => '',
            'dropped' => $stderrDropped,
            'headMidLine' => $stderrMidLine,
            'tailMidLine' => false,
        ];

        if ($run['stderr'] === '') {
            return $onlyStdout;
        }

        if ($run['stdout'] === '') {
            return $onlyStderr;
        }

        if ($run['exitCode'] !== 0) {
            return [
                'head' => $run['stdout'],
                'tail' => $run['stderr'],
                'dropped' => $stdoutDropped + $stderrDropped,
                'headMidLine' => $stdoutMidLine,
                'tailMidLine' => $stderrMidLine,
            ];
        }

        return $onlyStdout;
    }
}
