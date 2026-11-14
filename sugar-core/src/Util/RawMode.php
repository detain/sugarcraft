<?php

declare(strict_types=1);

namespace SugarCraft\Core\Util;

use SugarCraft\Core\Lang;
use SugarCraft\Core\Util\Tty\PosixBackend;

/**
 * Portable raw-mode toggle for one stream's terminal device.
 *
 * Flips a tty between canonical (line-buffered, echoing) mode and raw
 * mode (one byte at a time, no echo) by shelling out to `stty -f` with
 * the device path resolved from the passed stream. This is the
 * lightweight path for one-shot interactive CLIs (pickers, prompts)
 * that only need to read a handful of keys without standing up a full
 * event loop.
 *
 * Both methods guard on {@see TtyDetect::isAtty()} and are safe no-ops
 * when the stream is not a terminal (piped input, CI, tests) — they
 * never shell out in that case. On a real terminal every `stty`
 * invocation is checked: a missing or failing `stty` throws with a
 * named message rather than silently leaving the discipline untouched
 * (sugar-core-054: the old shell_exec result was discarded, so a host
 * without `stty` "enabled raw mode" while changing nothing).
 *
 * State preservation is exact: enable() captures the device's
 * `stty -g` snapshot first and disable() restores THAT — never
 * `stty sane`, which would overwrite the user's own terminal
 * settings with a generic guess. A disable() without a matching
 * enable() touches nothing. A shutdown backstop restores any snapshot
 * still pending when the process exits without disable(), so a fatal
 * in consumer code cannot strand the terminal in raw mode.
 *
 * Signal generation is deliberately NOT disabled. Consumers of this
 * helper arm SIGINT as their cancel channel by design (the in-repo
 * picker runs a signal handler for exactly that reason); clearing
 * ISIG here would silently turn those Ctrl+C presses into bytes.
 *
 * Windows has no `stty`: the toggle is a documented no-op there, and
 * the console-API path belongs to the Tty backends (sc-02's story).
 *
 * Contrast with sugar-pty's `PosixTermios`: that is the FFI/termios
 * path which operates on an arbitrary PTY file descriptor and is far
 * heavier. Reach for RawMode when you only need the simple, portable
 * stty toggle and don't want the sugar-pty dependency.
 *
 * @see TtyDetect::isAtty()
 * @see \SugarCraft\Pty\Posix\PosixTermios
 */
final class RawMode
{
    /**
     * Snapshots taken by enable(), keyed by resolved device path.
     *
     * The outermost enable() of a nesting pair owns the entry: an inner
     * enable must not overwrite the pre-user-state snapshot with an
     * already-raw one, and the inner disable therefore restores.
     *
     * @var array<string, string>
     */
    private static array $savedSnapshots = [];

    private static bool $backstopArmed = false;

    private function __construct()
    {
    }

    /**
     * Put the stream's terminal into raw mode: disable canonical input
     * and echo, switch output flow control off (`-ixon`), and deliver
     * each byte immediately (`min 1 time 0`).
     *
     * The device's `stty -g` snapshot is captured first and restored by
     * {@see disable()} (or by the shutdown backstop if the process ends
     * without a disable call).
     *
     * No-op when $stream is not a TTY (piped/CI input) or on Windows
     * (no stty; the console path lives in the Tty backends). On a TTY,
     * failure is loud: an unresolvable device path or a failing `stty`
     * throws rather than half-applying.
     *
     * @param resource $stream The interactive input stream (default STDIN)
     *
     * @throws \RuntimeException when the stream is a TTY but names no
     *                           stty-targetable device, or `stty` fails
     */
    public static function enable($stream = STDIN): void
    {
        if (PHP_OS_FAMILY === 'Windows' || !TtyDetect::isAtty($stream)) {
            return;
        }
        $device = self::devicePath($stream);
        if ($device === null) {
            throw new \RuntimeException(Lang::t('raw_mode.stream_not_mappable', [
                'stream' => \is_resource($stream) ? \get_resource_type($stream) : \get_debug_type($stream),
            ]));
        }
        $snapshot = self::stty($device, '-g', expectAnswer: true);
        self::$savedSnapshots[$device] ??= $snapshot;
        self::armBackstop();
        // Literal recipe: this exact contiguous string is pinned by the
        // source-gate test. -ixon must be off so Ctrl+S (XOFF) arrives as
        // an ordinary byte (prompt libraries bind it for forward search).
        // ISIG deliberately stays enabled — see the class note above; the
        // recipe must never clear it.
        self::stty($device, '-icanon -echo -ixon min 1 time 0');
    }

    /**
     * Hand the device back the termios snapshot {@see enable()} took.
     *
     * Without a snapshot from a live enable() this changes NOTHING: the
     * old `stty sane` fallback destroyed the user's own terminal
     * settings on every teardown (sugar-core-054), and a generic guess
     * is still a guess. Nested enable()s share the outermost snapshot,
     * so only the last disable() (the one that consumes the entry)
     * restores.
     *
     * No-op when $stream is not a TTY or on Windows.
     *
     * @param resource $stream The interactive input stream (default STDIN)
     *
     * @throws \RuntimeException when the stream is a TTY but names no
     *                           stty-targetable device, or restoring the
     *                           snapshot fails
     */
    public static function disable($stream = STDIN): void
    {
        if (PHP_OS_FAMILY === 'Windows' || !TtyDetect::isAtty($stream)) {
            return;
        }
        $device = self::devicePath($stream);
        if ($device === null) {
            throw new \RuntimeException(Lang::t('raw_mode.stream_not_mappable', [
                'stream' => \is_resource($stream) ? \get_resource_type($stream) : \get_debug_type($stream),
            ]));
        }
        if (!isset(self::$savedSnapshots[$device])) {
            return;
        }
        $snapshot = self::$savedSnapshots[$device];
        unset(self::$savedSnapshots[$device]);
        self::stty($device, $snapshot);
    }

    /**
     * The device path $stream's descriptor names, or null when it names
     * none that `stty -f` can target.
     *
     * Resolves the stream's file descriptor via the Tty seam and reads
     * the platform descriptor table (`/proc/self/fd`, `/dev/fd`).
     * Descriptor targets that are anonymous kernels objects
     * (`pipe:[123]`, `socket:[456]`) have no path to re-open and
     * produce null, as do streams with no descriptor at all. A tty
     * stream reaching null is a refusing host (no descriptor table);
     * callers throw with a named message rather than falling back to
     * the inherited controlling terminal — that fallback was the
     * original wrong-fd bug.
     */
    public static function devicePath($stream): ?string
    {
        $fd = PosixBackend::descriptorForStream($stream);
        if ($fd === null) {
            return null;
        }
        foreach (['/proc/self/fd', '/dev/fd'] as $table) {
            if (!\is_dir($table)) {
                continue;
            }
            $link = @\readlink($table . '/' . $fd);
            if (!\is_string($link) || $link === '' || $link[0] !== '/') {
                continue;
            }
            if (\str_contains($link, '[')) {
                // Anonymous kernel object — pipe/socket/anon_inode.
                return null;
            }
            return $link;
        }

        return null;
    }

    /**
     * Run `stty` against one device and return stdout, throwing with a
     * named message on any failure (missing binary, non-zero exit, and —
     * when $expectAnswer — an empty answer). Mode-SET invocations legally
     * print nothing, so they pass $expectAnswer = false.
     *
     * exec() rather than shell_exec(): the exit status is the contract
     * and shell_exec discards it. Every argument is escaped per the
     * repo rule; the trailing stderr redirect keeps a failing stty's
     * chatter out of the user's screen — the exception names the mode
     * string and exit code, which is the actionable half.
     */
    private static function stty(string $device, string $mode, bool $expectAnswer = false): string
    {
        // Each setting is its own argv word (GNU stty rejects a single
        // combined settings string); every token is escaped individually.
        $tokens = \preg_split('/\s+/', $mode, -1, \PREG_SPLIT_NO_EMPTY) ?: [];
        $command = 'stty -F ' . \escapeshellarg($device)
            . ($tokens === [] ? '' : ' ' . \implode(' ', \array_map('\escapeshellarg', $tokens)))
            . ' 2>/dev/null';
        $output = [];
        $status = -1;
        \exec($command, $output, $status);
        if ($status !== 0) {
            throw new \RuntimeException(Lang::t('raw_mode.stty_failed', [
                'mode' => $mode,
                'status' => (string) $status,
            ]));
        }
        $answer = \trim(\implode("\n", $output));
        if ($expectAnswer && $answer === '') {
            throw new \RuntimeException(Lang::t('raw_mode.stty_failed', [
                'mode' => $mode,
                'status' => 'empty',
            ]));
        }

        return $answer;
    }

    /**
     * Restore any still-pending snapshot at process shutdown, once.
     *
     * Best-effort by design: a consumer fatal between enable() and
     * disable() would otherwise strand the user's shell in raw mode.
     * Failures during shutdown are swallowed — there is no caller left
     * to name them to, and the terminal is no worse off than without
     * the backstop.
     */
    private static function armBackstop(): void
    {
        if (self::$backstopArmed) {
            return;
        }
        self::$backstopArmed = true;
        \register_shutdown_function(static function (): void {
            foreach (self::$savedSnapshots as $device => $snapshot) {
                unset(self::$savedSnapshots[$device]);
                @\exec('stty -F ' . \escapeshellarg($device) . ' ' . \escapeshellarg($snapshot) . ' 2>/dev/null');
            }
        });
    }
}
