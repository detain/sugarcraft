<?php

declare(strict_types=1);

namespace SugarCraft\Stash;

/**
 * Runs an external command with a wall-clock timeout.
 *
 * The synchronous {@see Git} driver shells out on the UI thread, so a git
 * invocation that wedges — a credential or pager prompt, an index lock held by
 * another process, an unreachable remote — would otherwise freeze the whole TUI
 * indefinitely. Draining stdout/stderr through `stream_select` with a deadline
 * lets us `proc_terminate` the child once the budget is spent instead of
 * blocking on `stream_get_contents` forever.
 */
final class Process
{
    /**
     * @param list<string> $argv    Command + args passed straight to proc_open (no shell).
     * @param string|null  $stdin   Written to the child's stdin, which is then closed. Null opens no stdin pipe.
     * @param float        $timeout Seconds before the child is killed; <= 0 disables the timeout.
     *
     * @return array{stdout: string, stderr: string, exit: int, timedOut: bool}
     */
    public static function run(array $argv, ?string $stdin = null, float $timeout = 30.0): array
    {
        $spec = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        if ($stdin !== null) {
            $spec[0] = ['pipe', 'r'];
        }
        $proc = proc_open($argv, $spec, $pipes);
        if (!is_resource($proc)) {
            throw new \RuntimeException(Lang::t('git.spawn_failed'));
        }

        if ($stdin !== null) {
            fwrite($pipes[0], $stdin);
            fclose($pipes[0]);
        }

        $open = [];
        foreach ([1, 2] as $i) {
            stream_set_blocking($pipes[$i], false);
            $open[$i] = $pipes[$i];
        }

        $stdout   = '';
        $stderr   = '';
        $timedOut = false;
        $deadline = $timeout > 0 ? microtime(true) + $timeout : null;

        while ($open !== []) {
            $wait = null;
            if ($deadline !== null) {
                $wait = $deadline - microtime(true);
                if ($wait <= 0) {
                    $timedOut = true;
                    break;
                }
            }

            $read   = array_values($open);
            $write  = null;
            $except = null;
            if ($wait === null) {
                $ready = @stream_select($read, $write, $except, null);
            } else {
                $sec   = (int) $wait;
                $usec  = (int) (($wait - $sec) * 1_000_000);
                $ready = @stream_select($read, $write, $except, $sec, $usec);
            }

            // false = interrupted by a signal; stop draining and reap the child.
            if ($ready === false) {
                break;
            }
            if ($ready === 0) {
                $timedOut = true;
                break;
            }

            foreach ($read as $stream) {
                $key   = array_search($stream, $open, true);
                $chunk = fread($stream, 8192);
                if ($chunk === '' || $chunk === false) {
                    if ($key !== false) {
                        fclose($open[$key]);
                        unset($open[$key]);
                    }
                    continue;
                }
                if ($key === 1) {
                    $stdout .= $chunk;
                } else {
                    $stderr .= $chunk;
                }
            }
        }

        if ($timedOut) {
            proc_terminate($proc, 9);
        }

        foreach ($open as $stream) {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }

        $exit = proc_close($proc);

        return [
            'stdout'   => $stdout,
            'stderr'   => $stderr,
            'exit'     => $exit,
            'timedOut' => $timedOut,
        ];
    }
}
