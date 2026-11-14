<?php

declare(strict_types=1);

namespace SugarCraft\Git;

/**
 * The launcher's terminal-access decision, as one pure truth table.
 *
 * `stream_isatty()` asks about the STREAM — a descriptor flag, not the
 * environment behind it. That distinction is the whole finding: under
 * mintty (Git for Windows' default terminal) native Windows PHP's std
 * streams are NAMED PIPES in front of a fully working console, so the raw
 * flag refuses a boot sugar-core could serve (candy-git-055); while
 * `candy-git < file` leaves stdout a real tty and starves the key channel
 * with an EOF pipe (candy-git-124).
 *
 * POSIX needs no delegation on the output side: there the flag never lies,
 * and probing /dev/tty for a piped stdout would HIJACK a `candy-git | cat`
 * caller's frame onto an unrelated screen instead of respecting the pipe —
 * so that shape refuses, naming the pipe. Windows and one-sided redirects
 * delegate to the caller's probe (Tty::openTty: CONIN$/CONOUT$ or
 * /dev/tty) and refuse only when no terminal is reachable at all.
 */
final class TerminalGate
{
    /**
     * @param callable():?array{0:resource,1:resource} $openTty lazily opens
     *        the controlling terminal; consulted only when delegation can
     *        help (never probed on the plain-pipe POSIX path).
     * @return array{boot: bool, streams: ?array{0:resource, 1:resource}, key: ?string}
     *         `streams` null means "boot on STDIN/STDOUT"; `key` names the
     *         refusal message when boot is false.
     */
    public static function decide(bool $outTty, bool $inTty, bool $isWindows, callable $openTty): array
    {
        if ($outTty && $inTty) {
            return ['boot' => true, 'streams' => null, 'key' => null];
        }

        if (!$isWindows && !$outTty) {
            // A real redirect on the screen side: deliberate pipe or cron.
            return ['boot' => false, 'streams' => null, 'key' => 'cli.piped_stdout'];
        }

        $tty = $openTty();

        if ($tty === null) {
            // Windows without a console at all keeps the classic message;
            // a POSIX one-sided redirect says which end is missing.
            return ['boot' => false, 'streams' => null, 'key' => $isWindows ? 'cli.needs_tty' : 'cli.needs_tty_input'];
        }

        // Full delegation swaps both ends (Windows pair); an output that is
        // already a terminal keeps it and only rescues the key channel.
        return ['boot' => true, 'streams' => $outTty ? [$tty[0], STDOUT] : $tty, 'key' => null];
    }
}
