<?php

declare(strict_types=1);

namespace SugarCraft\ColorProfile\Probe;

use SugarCraft\Core\Util\Proc\WindowsPipes;

/**
 * Single source of truth for locating the `infocmp` terminfo binary.
 *
 * Probe, AsyncProbe and TerminalProbe each carried their own copy of the
 * same two-path sniff; they now all delegate here so the answer to
 * "where is infocmp?" cannot drift between call sites. The admission
 * rule itself — `is_file() && is_executable()` — is shared by design
 * with sugar-image's `Renderer\ChafaBinary` roster gate: both locate
 * the same class of candidate, a fixed roster of
 * absolute paths, so the same two-part gate decides them and neither
 * may loosen toward `is_file()` alone without the other following in
 * the same change.
 *
 * The result is memoized per process — the filesystem layout a probe sees
 * at first call is the layout every later call reports. {@see reset()}
 * clears the memo (test isolation only).
 *
 * The class also owns the name-shape gate every exec site consults before
 * handing a TERM to the binary ({@see isSafeTermName()}): the question
 * "may this TERM reach infocmp at all?" has exactly one answer, so it lives
 * beside "where is infocmp?" rather than tripling across Probe, AsyncProbe
 * and TerminalProbe.
 *
 * And it owns the ONE bounded execution surface ({@see runBounded()}): the
 * deadline, the keep-a-prefix output cap, the array-form argv launch, and
 * the per-TERM verdict table every synchronous surface shares — so "how
 * long may infocmp take, and how much of it may we keep" cannot drift
 * between callers either.
 */
final class InfocmpBinary
{
    /**
     * Candidate absolute paths, checked in order — the existing two first so
     * the hot path on glibc hosts stays two-stat-stable, then the published
     * alternate layouts: NixOS system profile, FreeBSD/Nix-multi-user local
     * seat, Termux (Android) prefix. Absolute-only IS the security posture:
     * no PATH scanning happens here or anywhere in src (a rostered path the
     * attacker cannot rename into is the whole point), and each candidate
     * still faces the is_file AND is_executable gate.
     */
    private const CANDIDATES = [
        '/usr/bin/infocmp',
        '/bin/infocmp',
        '/run/current-system/sw/bin/infocmp',
        '/usr/local/bin/infocmp',
        '/data/data/com.termux/files/usr/bin/infocmp',
    ];

    /**
     * Name shape a TERM must have to reach infocmp: alnum first byte, then
     * the characters real terminfo entries actually use (letters, digits,
     * `.`, `_`, `+`, `-`).
     */
    private const TERM_NAME_PATTERN = '/^[A-Za-z0-9][A-Za-z0-9._+-]*$/';

    /**
     * Keep-a-prefix stdout ceiling shared by every bounded infocmp read —
     * the same 8 KiB the async pump carries (see {@see AsyncProbe}'s
     * STDOUT_CAP_BYTES, which aliases this constant). Real single-terminal
     * `infocmp -1` dumps stay far below it; past it the child is noise.
     */
    public const OUTPUT_CAP_BYTES = 8192;

    /** Wall-clock ceiling for one bounded synchronous launch. */
    public const TIMEOUT_SECONDS = 5.0;

    /** Grace window per rung of the SIGTERM → SIGKILL escalation ladder. */
    public const GRACE_SECONDS = 1.0;

    private static ?string $path = null;

    /**
     * Per-lowercased-TERM dump memo behind {@see runBounded()} — the ONE
     * shared table every sync exec surface answers from (a null entry is a
     * recorded miss: absent binary, deadline overrun or signal kill, never
     * re-execed either; a failed launch mints no entry at all).
     * Bounded cardinality via {@see self::MEMO_MAX_ENTRIES} — see memoWrite().
     *
     * @var array<string, string|null>
     */
    private static array $dumpMemo = [];

    /**
     * Ceiling on {@see self::$dumpMemo} entries. A long-lived server that
     * honors attacker-influenced TERM shapes must not creep this table for
     * the life of the process: the grammar gate (isSafeTermName) bounds a
     * NAME's shape, not its cardinality — every distinct safe-shaped TERM
     * mints an entry, misses included. Full ⇒ clear (one bulk reset, not
     * an LRU), because re-derivation is cheap and time-bounded by the
     * deadline ladder on the other side of this door.
     */
    private const MEMO_MAX_ENTRIES = 256;

    /**
     * Absolute path to infocmp, or null when no roster candidate is a
     * present AND executable file. The executable leg is what keeps this
     * gate from drifting from sugar-image's ChafaBinary roster gate —
     * see the class docblock's shared-rule note.
     */
    public static function path(): ?string
    {
        if (self::$path !== null) {
            return self::$path === '' ? null : self::$path;
        }

        foreach (self::CANDIDATES as $candidate) {
            if (\is_file($candidate) && \is_executable($candidate)) {
                self::$path = $candidate;

                return $candidate;
            }
        }

        self::$path = '';

        return null;
    }

    /**
     * The ONE bounded infocmp execution surface, shared by every surface
     * (synchronous {@see Probe}, the {@see TerminalProbe} phase-2 leg, and
     * future callers). The pre-CP-11 shape carried two unbounded waits:
     * the child was untimed (a wedged infocmp blocked the caller for its
     * whole life) and its output was slurped without limit. Both arms are
     * now bounded, and the answer is memoized per TERM so a per-tick
     * consumer multiplies nothing.
     *
     * Contract (documented in the README's detection section):
     *  - array-form argv launch — NO shell is involved, so the old POSIX
     *    only `2>/dev/null` redirect string is gone; stderr is a piped
     *    descriptor drained alongside stdout and closed in `finally`;
     *  - at most {@see self::OUTPUT_CAP_BYTES} stdout bytes are kept (a
     *    keep-a-prefix read; later bytes drain to EOF and are discarded,
     *    so the child never blocks on a full pipe);
     *  - wall-clock deadline of $timeoutSeconds (default
     *    {@see self::TIMEOUT_SECONDS}) with a SIGTERM → grace → SIGKILL
     *    escalation ladder, {@see self::GRACE_SECONDS} per rung;
     *  - fail-open: null on absent binary, unshaped TERM (the name gate is
     *    enforced AT THE DOOR, not only in callers), launch failure, deadline
     *    overrun, or signal kill — the caller's chain answer stands;
     *  - the child always closes: pipes `fclose` + `proc_close` in
     *    `finally` (repo child-lifetime law).
     *
     * Verdicts are memoized per lowercased TERM for the process lifetime: a
     * completed child (any exit code), an absent binary, and a child the
     * deadline ladder or a signal killed (a recorded miss, so a wedged binary
     * is never re-execed per tick). A failed launch is transient (fork or fd
     * pressure) and mints no entry (CP-13). The cardinality ceiling is
     * documented at {@see self::MEMO_MAX_ENTRIES} (full ⇒ clear).
     * {@see reset()} drains the table (test isolation).
     *
     * @param float|null $timeoutSeconds test seam for a shorter deadline
     */
    public static function runBounded(string $term, ?float $timeoutSeconds = null): ?string
    {
        if ($term === '' || !self::isSafeTermName($term)) {
            // Fail-closed at the exec door itself: a TERM infocmp would
            // read as its own option never reaches the binary, no matter
            // which surface asked.
            return null;
        }

        $key = strtolower($term);
        if (array_key_exists($key, self::$dumpMemo)) {
            return self::$dumpMemo[$key];
        }

        $binary = self::path();
        if ($binary === null) {
            return self::memoWrite($key, null);
        }

        $launched = false;
        $dump = self::execOnce($binary, $term, $timeoutSeconds ?? self::TIMEOUT_SECONDS, $launched);

        // A failed launch says nothing about the TERM, so it mints no entry and
        // the next call may spawn again (CP-13).
        return $launched ? self::memoWrite($key, $dump) : null;
    }

    /**
     * Sole write site of the dump memo, enforcing the cardinality ceiling:
     * at {@see self::MEMO_MAX_ENTRIES} the table clears before the write.
     * Both verdict shapes (answer and recorded miss) pass through here so
     * the cap cannot be drifted around by a future second writer.
     */
    private static function memoWrite(string $key, ?string $value): ?string
    {
        if (count(self::$dumpMemo) >= self::MEMO_MAX_ENTRIES) {
            self::$dumpMemo = [];
        }

        return self::$dumpMemo[$key] = $value;
    }

    /**
     * Single bounded launch behind {@see runBounded()}; see it for the
     * contract. Returns the capped dump, or null when the deadline ladder
     * had to intervene or a signal ended the child (a partial dump is not a
     * verdict).
     *
     * $launched reports whether a child was actually started: false means the
     * launch itself failed, which the caller must not memoize (CP-13).
     */
    private static function execOnce(string $binary, string $term, float $timeoutSeconds, bool &$launched): ?string
    {
        $pipes = [];
        $handle = @\proc_open(
            [$binary, '-1', $term],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            null,
            self::childEnvironment($term),
        );

        if (!\is_resource($handle)) {
            return null;
        }

        $launched = true;
        $stdout = '';
        $pastCap = false;
        $escalation = 0;
        $signaled = false;
        $eofSeen = false;
        $deadline = \microtime(true) + $timeoutSeconds;

        try {
            \stream_set_blocking($pipes[1], false);
            \stream_set_blocking($pipes[2], false);

            // Windows (an MSYS infocmp from Git for Windows): the pipes stay
            // blocking and an fread on the empty stderr parks until the child
            // exits, so the deadline below was never consulted. Read only what
            // fstat reports waiting there; EOF is the exit, sampled before the
            // reads, plus empty pipes.
            $windows = WindowsPipes::runtimeNeedsIt();

            while (true) {
                $exited = $windows && !\proc_get_status($handle)['running'];
                $chunk = $windows ? WindowsPipes::readAvailable($pipes[1], 8192) : \fread($pipes[1], 8192);
                if (\is_string($chunk) && $chunk !== '') {
                    if (!$pastCap) {
                        $room = self::OUTPUT_CAP_BYTES - \strlen($stdout);
                        if (\strlen($chunk) > $room) {
                            $stdout .= \substr($chunk, 0, $room);
                            $pastCap = true;
                        } else {
                            $stdout .= $chunk;
                        }
                    }
                }
                // stderr drains and discards: an unread error pipe is how a
                // merely chatty infocmp turns into a blocked child.
                $windows ? WindowsPipes::readAvailable($pipes[2], 8192) : \fread($pipes[2], 8192);

                if ($windows
                    ? $exited && WindowsPipes::available($pipes[1]) === 0 && WindowsPipes::available($pipes[2]) === 0
                    : \feof($pipes[1]) && \feof($pipes[2])
                ) {
                    $eofSeen = true;

                    break;
                }

                if (\microtime(true) >= $deadline) {
                    if ($escalation === 0) {
                        $escalation = 1;
                        $deadline = \microtime(true) + self::GRACE_SECONDS;
                        \proc_terminate($handle);

                        continue;
                    }
                    if ($escalation === 1) {
                        $escalation = 2;
                        $deadline = \microtime(true) + self::GRACE_SECONDS;
                        \proc_terminate($handle, \defined('SIGKILL') ? \SIGKILL : 9);

                        continue;
                    }

                    return null;
                }

                \usleep(10000);
            }
        } finally {
            foreach ($pipes as $pipe) {
                if (\is_resource($pipe)) {
                    @\fclose($pipe);
                }
            }
            // proc_close() hands back the signal number for a signal death, the
            // same value an exit code N carries, so it cannot tell a kill from
            // an exit. proc_get_status() does: it reports 'signaled' for a dead
            // child. EOF on the pipes arrives before the parent can reap, so
            // wait (within the deadline) for the child to settle first.
            $status = self::reapAfterEof($handle, $deadline, $eofSeen);
            if ($status['running']) {
                // Both pipes reached EOF before the deadline, yet the child is
                // still alive past it. Run the same TERM → KILL ladder the loop
                // runs: without it proc_close() below blocks for the child's
                // whole lifetime, and the verdict it would return is a miss.
                $escalation = 2;
                \proc_terminate($handle);
                $status = self::awaitExit($handle, \microtime(true) + self::GRACE_SECONDS);
                if ($status['running']) {
                    \proc_terminate($handle, \defined('SIGKILL') ? \SIGKILL : 9);
                    $status = self::awaitExit($handle, \microtime(true) + self::GRACE_SECONDS);
                }
            }
            $signaled = $status['signaled'];
            \proc_close($handle);
        }

        // A child that only ended after the TERM/KILL ladder answered nothing
        // trustworthy, and a child a signal killed printed only a partial dump:
        // both fail open to the caller's chain answer.
        return $escalation > 0 || $signaled ? null : $stdout;
    }

    /**
     * Settle the child once the drain loop has ended, and return its last status.
     *
     * Each loop pass checks EOF before the deadline, so EOF can end the loop
     * in the very pass the deadline passed; a child that closed its pipes and
     * then exited on its own still needs time to be reaped. Judging it against
     * the already-expired deadline would read it as running and escalate a
     * finished child, losing its verdict for the process lifetime. After EOF
     * the child is therefore owed at least one grace window to be reaped.
     *
     * @param resource $handle
     *
     * @return array<string,mixed>
     */
    private static function reapAfterEof($handle, float $deadline, bool $eofSeen): array
    {
        $until = $eofSeen ? \max($deadline, \microtime(true) + self::GRACE_SECONDS) : $deadline;

        return self::awaitExit($handle, $until);
    }

    /**
     * Poll the child's status until it stops running or $until passes, and
     * return the last status seen. proc_get_status() is the only reliable
     * reader of 'running' and 'signaled' once the pipes are drained.
     *
     * @param resource $handle
     *
     * @return array<string,mixed>
     */
    private static function awaitExit($handle, float $until): array
    {
        $status = \proc_get_status($handle);
        while ($status['running'] && \microtime(true) < $until) {
            \usleep(10000);
            $status = \proc_get_status($handle);
        }

        return $status;
    }

    /**
     * The environment every infocmp child receives, at every exec surface.
     *
     * `TERMINFO`, `TERMINFO_DIRS` and `HOME` all steer infocmp's database
     * lookup (a search path inspectable via `infocmp -1 -D`), so a child that
     * inherits the parent environment answers from whichever database whoever
     * controls that environment puts there. The minimal child carries only the
     * terminal being queried and a fixed `PATH`; null/empty `$term` omits the
     * key entirely. The README's trust-boundary paragraph is the prose home of
     * this law — change one, change the other.
     *
     * @return array<string,string>
     */
    public static function childEnvironment(?string $term = null): array
    {
        $env = ['PATH' => '/usr/bin:/bin'];

        if ($term !== null && $term !== '') {
            $env = ['TERM' => $term] + $env;
        }

        return $env;
    }

    /**
     * Is $term shaped like a terminal name rather than an infocmp option?
     *
     * `escapeshellarg()` quotes a leading-dash value but cannot make it a
     * non-option to the program itself: `TERM=-D` measured as infocmp
     * exiting 0 and printing its terminfo search path into the probe's
     * stdout (verified for -e/-x/-0/-C/-F/-D). Gate every exec site on this
     * predicate so a value beginning with `-` never reaches the binary;
     * callers keep the env-anchored chain answer when it refuses.
     */
    public static function isSafeTermName(string $term): bool
    {
        return \preg_match(self::TERM_NAME_PATTERN, $term) === 1;
    }

    /**
     * Clear the memoized answers (for testing only): both the binary-path
     * sniff and the per-TERM dump table of {@see runBounded()}.
     *
     * @internal
     */
    public static function reset(): void
    {
        self::$path = null;
        self::$dumpMemo = [];
    }
}
