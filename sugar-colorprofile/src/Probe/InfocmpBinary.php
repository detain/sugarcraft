<?php

declare(strict_types=1);

namespace SugarCraft\ColorProfile\Probe;

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
 * And it owns the ONE bounded execution face ({@see runBounded()}): the
 * deadline, the keep-a-prefix output cap, the array-form argv launch, and
 * the per-TERM verdict table every synchronous surface shares — so "how
 * long may infocmp take, and how much of it may we keep" cannot drift
 * between callers either.
 */
final class InfocmpBinary
{
    /** Candidate absolute paths, checked in order. */
    private const CANDIDATES = ['/usr/bin/infocmp', '/bin/infocmp'];

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
     * recorded miss: absent binary or overrun, never re-execed either).
     *
     * @var array<string, string|null>
     */
    private static array $dumpMemo = [];

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
     * The ONE bounded infocmp execution face, shared by every surface
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
     *    enforced AT THE DOOR, not only in callers), launch failure, or
     *    deadline overrun — the caller's chain answer stands;
     *  - the child always closes: pipes `fclose` + `proc_close` in
     *    `finally` (repo child-lifetime law).
     *
     * Results are memoized per lowercased TERM for the process lifetime —
     * including the null verdicts, so a failing binary is never re-execed
     * either. {@see reset()} drains the table (test isolation).
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
            return self::$dumpMemo[$key] = null;
        }

        return self::$dumpMemo[$key] = self::execOnce($binary, $term, $timeoutSeconds ?? self::TIMEOUT_SECONDS);
    }

    /**
     * Single bounded launch behind {@see runBounded()}; see it for the
     * contract. Returns the capped dump, or null when the deadline ladder
     * had to intervene (a partial dump is not a verdict).
     */
    private static function execOnce(string $binary, string $term, float $timeoutSeconds): ?string
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

        $stdout = '';
        $pastCap = false;
        $escalation = 0;
        $deadline = \microtime(true) + $timeoutSeconds;

        try {
            \stream_set_blocking($pipes[1], false);
            \stream_set_blocking($pipes[2], false);

            while (true) {
                $chunk = \fread($pipes[1], 8192);
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
                \fread($pipes[2], 8192);

                if (\feof($pipes[1]) && \feof($pipes[2])) {
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
            \proc_close($handle);
        }

        // A child that only ended after the TERM/KILL ladder answered
        // nothing trustworthy — fail open to the caller's chain answer.
        return $escalation > 0 ? null : $stdout;
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
