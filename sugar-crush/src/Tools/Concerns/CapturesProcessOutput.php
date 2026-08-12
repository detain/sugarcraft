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
     * @return array{stdout: string, stderr: string, exitCode: int}
     */
    private function runCaptured(string $command, ?string $cwd = null): array
    {
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = @proc_open($command, $descriptors, $pipes, $cwd);
        if (!is_resource($process)) {
            return ['stdout' => '', 'stderr' => 'Failed to start process', 'exitCode' => 127];
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
                    $stdout .= $chunk;
                } else {
                    $stderr .= $chunk;
                }
            }
        }

        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);

        return [
            'stdout' => rtrim($stdout, "\n"),
            'stderr' => rtrim($stderr, "\n"),
            'exitCode' => $exitCode,
        ];
    }

    /**
     * Fold a captured run into the single string a {@see
     * \SugarCraft\Crush\Tools\ToolResult} carries.
     *
     * stderr is surfaced when the command FAILED (that is where the reason
     * lives) or when it succeeded silently on stdout but said something on
     * stderr. A successful command with real stdout keeps its output clean.
     *
     * @param array{stdout: string, stderr: string, exitCode: int} $run
     */
    private function mergeCapturedOutput(array $run): string
    {
        $failed = $run['exitCode'] !== 0;

        if ($run['stderr'] === '') {
            return $run['stdout'];
        }

        if ($failed) {
            return $run['stdout'] === ''
                ? $run['stderr']
                : $run['stdout'] . "\n" . $run['stderr'];
        }

        return $run['stdout'] === '' ? $run['stderr'] : $run['stdout'];
    }
}
