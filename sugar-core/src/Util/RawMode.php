<?php

declare(strict_types=1);

namespace SugarCraft\Core\Util;

/**
 * Portable raw-mode toggle for the *controlling* terminal.
 *
 * Flips the controlling tty between canonical (line-buffered, echoing)
 * mode and raw mode (one byte at a time, no echo) by shelling out to
 * `stty`. This is the lightweight path for one-shot interactive CLIs
 * (pickers, prompts) that only need to read a handful of keys from
 * STDIN without standing up a full event loop.
 *
 * Both methods guard on {@see TtyDetect::isAtty()} and are safe no-ops
 * when the stream is not a terminal (piped input, CI, tests) — they
 * never shell out and never throw in that case.
 *
 * The toggle covers canonical mode, echo, and output flow control — the
 * input half of "raw". Signal generation stays ON: Ctrl+C continues to
 * raise SIGINT here rather than arriving as a byte. Prompt libraries that
 * abort on Ctrl+C as a key must clear ISIG themselves (see {@see enable()}).
 *
 * Contrast with sugar-pty's `PosixTermios`: that is the FFI/termios
 * path which operates on an arbitrary PTY file descriptor and is far
 * heavier. Reach for RawMode when you only need the simple, portable
 * stty toggle on the controlling terminal and don't want the sugar-pty
 * dependency.
 *
 * @see TtyDetect::isAtty()
 * @see \SugarCraft\Pty\PosixTermios
 */
final class RawMode
{
    private function __construct()
    {
    }

    /**
     * Put the controlling terminal into raw mode: disable canonical
     * input and echo, switch output flow control off (`-ixon`), and
     * deliver each byte immediately (`min 1 time 0`).
     *
     * Flow control is switched off so Ctrl+S (0x13, XOFF) arrives as an
     * ordinary byte instead of freezing the terminal — prompt libraries
     * bind it for forward history search.
     *
     * Signal generation is deliberately NOT disabled. Consumers of this
     * helper arm SIGINT as their cancel channel by design (the in-repo
     * picker runs a signal handler for exactly that reason); clearing
     * ISIG here would silently turn those Ctrl+C presses into bytes.
     * Prompts that need Ctrl+C as an abort key run their own
     * `stty -icanon -echo -isig ...` recipe instead.
     *
     * No-op when $stream is not a TTY, so callers can invoke this
     * unconditionally in interactive code paths without breaking
     * piped/CI input.
     *
     * @param resource $stream The interactive input stream (default STDIN)
     */
    public static function enable($stream = STDIN): void
    {
        if (!TtyDetect::isAtty($stream)) {
            return;
        }
        shell_exec('stty -icanon -echo -ixon min 1 time 0 2>/dev/null');
    }

    /**
     * Restore the controlling terminal to a sane canonical state
     * (`stty sane`), undoing {@see enable()}.
     *
     * No-op when $stream is not a TTY.
     *
     * @param resource $stream The interactive input stream (default STDIN)
     */
    public static function disable($stream = STDIN): void
    {
        if (!TtyDetect::isAtty($stream)) {
            return;
        }
        shell_exec('stty sane 2>/dev/null');
    }
}
