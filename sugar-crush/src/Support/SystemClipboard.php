<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Support;

/**
 * Hand copied text to the host's clipboard tool, alongside the OSC 52 write.
 *
 * OSC 52 alone is not enough on the setups a terminal agent actually runs in.
 * tmux's default `set-clipboard external` IGNORES an application's OSC 52 (it
 * only lets tmux itself set the outer terminal's clipboard), so a copy made
 * inside tmux went nowhere; many terminals also ship with OSC 52 writes off.
 * `tmux load-buffer -w -` is the documented way in: it fills a tmux paste
 * buffer AND forwards the text to the outer terminal through tmux's own
 * OSC 52. Outside tmux the platform tool is tried — the same order Neovim's
 * clipboard provider probes (pbcopy, wl-copy, xclip, xsel).
 *
 * ONE TOOL, FIRST MATCH, BEST EFFORT. A copy is a convenience riding on a
 * mouse release: nothing here throws, a missing tool or a failed exit is
 * simply "not copied by this route" (the OSC 52 write went out regardless),
 * and the whole spawn is held to {@see TIMEOUT_SECONDS} with a TERM→KILL
 * ladder so a wedged X forward cannot freeze the TUI.
 *
 * STDOUT/STDERR GO TO /dev/null, NOT PIPES. `xclip` and `wl-copy` fork a
 * daemon that keeps serving the selection after the command returns; a
 * daemon holding our pipe's write end would make every drain wait out the
 * deadline. Only stdin is a pipe, and it is closed as soon as the text is in.
 */
final class SystemClipboard
{
    /** Wall-clock budget for one copy, spawn to reap. */
    public const TIMEOUT_SECONDS = 1.0;

    private const TERMINATE_GRACE_SECONDS = 0.25;

    private const KILL_GRACE_SECONDS = 0.25;

    private const EXIT_POLL_MICROSECONDS = 5_000;

    /**
     * Test seam: replaces the spawn. Receives the argv and the text, returns
     * whether the copy succeeded. Null runs the real tool.
     *
     * @var (\Closure(list<string>, string): bool)|null
     */
    private static ?\Closure $runner = null;

    /**
     * Copy $text with the first clipboard tool this host offers.
     *
     * @return bool true when a tool accepted the text
     */
    public static function copy(string $text): bool
    {
        if ($text === '') {
            return false;
        }

        foreach (self::candidates() as $argv) {
            if (self::run($argv, $text)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The argv list to try, in order, for this environment. Each entry's
     * binary has already been located on PATH, so a spawn never targets a
     * missing program (PHP warns on that, and the suite fails on warnings).
     *
     * @return list<list<string>>
     */
    public static function candidates(): array
    {
        $found = [];
        $add = static function (string $binary, string ...$args) use (&$found): void {
            $path = ProcessContainment::locateOnPath($binary);
            if ($path !== '') {
                $found[] = [$path, ...$args];
            }
        };

        if ((string) getenv('TMUX') !== '') {
            // `-w` (tmux >= 3.2) is the half that reaches the OUTER
            // terminal; an older tmux rejects it, and the plain form still
            // leaves the text in a paste buffer (`prefix ]`).
            $add('tmux', 'load-buffer', '-w', '-');
            $add('tmux', 'load-buffer', '-');

            return $found;
        }

        if (PHP_OS_FAMILY === 'Darwin') {
            $add('pbcopy');
        }
        if ((string) getenv('WAYLAND_DISPLAY') !== '') {
            $add('wl-copy');
        }
        if ((string) getenv('DISPLAY') !== '') {
            $add('xclip', '-selection', 'clipboard');
            $add('xsel', '--clipboard', '--input');
        }

        return $found;
    }

    /**
     * @param (\Closure(list<string>, string): bool)|null $runner
     */
    public static function useRunnerForTesting(?\Closure $runner): void
    {
        self::$runner = $runner;
    }

    /**
     * @param list<string> $argv
     */
    private static function run(array $argv, string $text): bool
    {
        if (self::$runner !== null) {
            return (self::$runner)($argv, $text);
        }

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['file', '/dev/null', 'w'],
            2 => ['file', '/dev/null', 'w'],
        ];

        // Direct argv, never a shell string: the text goes in on stdin and no
        // byte of it is ever parsed as a command.
        $process = @proc_open($argv, $descriptors, $pipes, null, ProcessContainment::env());
        if (!\is_resource($process)) {
            return false;
        }

        $deadline = microtime(true) + self::TIMEOUT_SECONDS;
        $written = self::feed($pipes[0], $text, $deadline);
        fclose($pipes[0]);

        // The exit code comes from the status poll, not proc_close(): once
        // proc_get_status() has observed the exit, proc_close() reports -1.
        $exitCode = $written ? self::waitForExit($process, $deadline) : null;
        if ($exitCode === null) {
            ProcessReaper::escalate(
                static function (int $signal) use ($process): void {
                    ProcessContainment::terminate($process, $signal);
                },
                static fn (): bool => (proc_get_status($process)['running'] ?? false) !== true,
                self::TERMINATE_GRACE_SECONDS,
                self::KILL_GRACE_SECONDS,
            );
        }

        proc_close($process);

        return $exitCode === 0;
    }

    /**
     * Write all of $text before $deadline without ever blocking on a child
     * that stopped reading.
     *
     * @param resource $stdin
     */
    private static function feed($stdin, string $text, float $deadline): bool
    {
        stream_set_blocking($stdin, false);
        $offset = 0;
        $length = \strlen($text);

        while ($offset < $length) {
            $remaining = $deadline - microtime(true);
            if ($remaining <= 0.0) {
                return false;
            }

            $read = null;
            $write = [$stdin];
            $except = null;
            $seconds = (int) $remaining;
            $micros = (int) (($remaining - $seconds) * 1_000_000);

            if (@stream_select($read, $write, $except, $seconds, $micros) === false) {
                return false;
            }
            if ($write === []) {
                continue;
            }

            $n = @fwrite($stdin, substr($text, $offset, 65_536));
            if ($n === false) {
                return false;
            }
            $offset += $n;
        }

        return true;
    }

    /**
     * The child's exit code once it is gone, or null if $deadline passed first.
     *
     * @param resource $process
     */
    private static function waitForExit($process, float $deadline): ?int
    {
        while (true) {
            $status = proc_get_status($process);
            if (($status['running'] ?? false) !== true) {
                return (int) ($status['exitcode'] ?? -1);
            }

            if (microtime(true) >= $deadline) {
                return null;
            }

            usleep(self::EXIT_POLL_MICROSECONDS);
        }
    }
}
