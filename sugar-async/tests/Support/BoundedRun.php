<?php

declare(strict_types=1);

namespace SugarCraft\Async\Tests\Support;

/**
 * Bounded child process runner shared by the test guards.
 *
 * The README fence guard used to shell out to GNU `timeout`, which does not
 * exist on macOS or Windows — the suite hard-failed 127 off-Linux, and the
 * skip-installed alternative would silently lose the fence check on the
 * very platforms that never had GNU coreutils. This is the same bound
 * everywhere: spawn with pipes, poll until the deadline, SIGKILL on
 * overrun, then drain the pipes dry and reap on EVERY path — no child
 * outlives the call, no descriptor dangles.
 *
 * The repo-wide child-lifetime census (tools/check-child-lifetimes.php)
 * walks library autoload roots only, and tests are autoload-dev — outside
 * its scope by design. The discipline that census enforces still applies
 * here in spirit: every spawn here is synchronous, bounded, and reaped
 * before returning.
 */
final class BoundedRun
{
    /**
     * @param list<string> $argv             Full argv; $argv[0] is the executable
     * @param float $deadlineSeconds         Wall-clock bound; overrun SIGKILLs the child
     * @param string|null $cwd               Working directory for the child (null: inherit)
     * @return array{code: int, output: string, killed: bool} Merged stdout+stderr
     */
    public static function run(array $argv, float $deadlineSeconds, ?string $cwd = null): array
    {
        $devNull = \str_starts_with(\PHP_OS_FAMILY, 'WIN') ? 'NUL' : '/dev/null';
        $pipes = [];

        $process = \proc_open($argv, [
            0 => ['file', $devNull, 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ], $pipes, $cwd);

        if (!\is_resource($process)) {
            throw new \RuntimeException('BoundedRun: proc_open failed for ' . ($argv[0] ?? '?'));
        }

        \stream_set_blocking($pipes[1], false);
        \stream_set_blocking($pipes[2], false);

        $deadline = \microtime(true) + $deadlineSeconds;
        $killed = false;
        $output = '';

        while (true) {
            $status = \proc_get_status($process);
            if ($status['running'] !== true) {
                break;
            }
            $remaining = $deadline - \microtime(true);
            if ($remaining <= 0.0) {
                \proc_terminate($process, 9);
                $killed = true;
                break;
            }
            $read = [$pipes[1], $pipes[2]];
            $write = $except = null;
            @\stream_select($read, $write, $except, 0, (int) (\min(0.05, $remaining) * 1_000_000));
            foreach ($read as $stream) {
                $chunk = \fread($stream, 8192);
                if ($chunk !== false && $chunk !== '') {
                    $output .= $chunk;
                }
            }
        }

        // Post-exit drain: whatever the child buffered is still in flight.
        // Go blocking and read both pipes dry before releasing them, so no
        // path — clean exit, overrun kill, or crash — leaves unread bytes or
        // an open descriptor behind.
        $drainDeadline = \microtime(true) + 2.0;
        foreach ([$pipes[1], $pipes[2]] as $stream) {
            \stream_set_blocking($stream, true);
            while (\microtime(true) < $drainDeadline) {
                $chunk = \fread($stream, 8192);
                if ($chunk === false || $chunk === '') {
                    break;
                }
                $output .= $chunk;
            }
        }

        \fclose($pipes[1]);
        \fclose($pipes[2]);
        $code = \proc_close($process);

        return [
            'code' => $killed ? -9 : $code,
            'output' => $output,
            'killed' => $killed,
        ];
    }
}
