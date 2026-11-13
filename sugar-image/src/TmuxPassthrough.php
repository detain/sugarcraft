<?php

declare(strict_types=1);

namespace SugarCraft\Image;

use SugarCraft\Core\Util\Proc\WindowsPipes;
use SugarCraft\Image\Support\TmuxBinary;

/**
 * Transactional tmux `allow-passthrough` management.
 *
 * Inside tmux, the pane swallows DCS/APC/OSC graphics envelopes unless the
 * window's `allow-passthrough` option is `on` (or `all`) — the decorated
 * renderer output simply never reaches the outer terminal and the user sees
 * a blank line. Chafa flips the option for the run and restores it on exit
 * (mirrors chafa `tools/chafa/chicle-options.c:1800-1871`
 * `chicle_apply/retire_passthrough_workarounds_tmux`, retired at
 * `tools/chafa/chafa.c:1124`); sugar-image previously only documented the
 * manual `set -g allow-passthrough on` in {@see TmuxPassthroughDecorator}.
 *
 * Transaction shape — pane-scoped, a DELIBERATE divergence from chicle:
 * chicle's own commands carry NO target flag (:1817 show, :1830 set,
 * :1855 restore, :1860 -u), so tmux treats them as writes to the
 * session-GLOBAL option, and :1813-1816 is its re-entry guard, not a
 * scope precedent. We target the pane because an untargeted flip would
 * change every window of a shared server for the life of the render —
 * least-surprise blast radius, one pane per graphics run.
 *
 *  - {@see ensure()} asks `tmux show-options -pqv allow-passthrough`. A
 *    non-empty answer is the current value; an empty answer means the
 *    option was never set. Values `on`/`all` already pass through and are
 *    left alone (chicle-options.c:1811-1814 "don't mess with it if we
 *    don't have to"); anything else gets `set-option -p allow-passthrough on`
 *    and the prior value remembered.
 *  - The child's EXIT CODE is part of the answer (SI-39): `0` is a current
 *    value, `3` is tmux's `-q` grammar for a known-but-unset option, and
 *    anything else (an unknown option, an unresolvable pane target, a dead
 *    server) is NOT-INFORMATIVE — never silently misread as "unset".
 *  - On a non-informative pane answer, ensure() makes ONE documented
 *    fallback attempt at the session-GLOBAL scope (`show-options -gqv` /
 *    `set-option -g`), for servers and attach contexts where the pane
 *    scope is unusable but the option itself is alive. The fallback is a
 *    wider blast radius — every window of the server, for the life of the
 *    render — so it runs only after the pane scope refuses to answer,
 *    and {@see retire()} restores it under exactly the same ledger law
 *    (`-gu` clears a previously-unset global, a remembered value is
 *    re-`set` on the same scope it was read from). A DEAD TRANSPORT (no
 *    binary, deadline overrun, flood) is scope-agnostic — one socket
 *    serves both scopes — so it is never retried. If neither scope can
 *    answer, ensure() returns false WITHOUT attempting any write:
 *    exit-aware silence, not a fabricated set-and-fail dance.
 *  - {@see retire()} restores the remembered value on the scope the
 *    transaction landed, or unsets it with `-u` — UNSET is restored with
 *    `-u` ONLY when the option was previously unset, never by writing a
 *    literal that tmux would keep as an explicit value.
 *  - A process-lifetime restore is registered with `register_shutdown_function()`
 *    the first time a change lands, so a caller that forgets to retire still
 *    leaves the pane as it found it (chicle keeps the same guarantee via its
 *    single exit path in chafa.c).
 *
 * Fail-soft law: nothing here ever throws. Outside tmux, with the binary
 * absent, with a pane that cannot be targeted, or against an unparseable
 * answer, ensure() returns false and touches nothing — graphics degrade to
 * the original silence instead of the run dying over a preference toggle.
 * The whole feature is opt-outable with {@see Detect::setAutoPassthrough()}
 * for embedders who own their tmux configuration.
 *
 * Mirrors the tmux allow-passthrough option dance as documented for
 * graphics-in-tmux workflows (the kitty protocol's tmux guidance); the
 * transactional ledger — single owner, scope-aware restore, lease — is
 * SugarCraft's. Neither chafa nor x/mosaic arbitrates tmux state.
 */
final class TmuxPassthrough
{
    /** The tmux option this transaction manages. */
    private const OPTION = 'allow-passthrough';

    /** Values that already pass graphics through — never touched (chicle-options.c:1827). */
    private const ALREADY_PASSTHROUGH = ['on', 'all'];

    /** Complete set of values tmux can answer for this toggle. */
    private const KNOWN_VALUES = ['on', 'off', 'all'];

    /** Wall deadline per tmux invocation — a socket round-trip, not a job. */
    private const EXEC_DEADLINE_SECONDS = 1.0;

    /** Grace between SIGTERM and SIGKILL while escalating a runaway child. */
    private const TERM_GRACE_SECONDS = 0.25;

    /** Hard cap on captured stdout before the child is killed (answers are short). */
    private const MAX_OUTPUT_BYTES = 65536;

    /** Scope flag of a pane-targeted transaction (the primary, least-surprise scope). */
    private const SCOPE_PANE = 'p';

    /** Scope flag of the documented global fallback (wider blast radius, SI-39). */
    private const SCOPE_GLOBAL = 'g';

    /** tmux `-q` grammar: exit code for a KNOWN option that is currently unset. */
    private const EXIT_KNOWN_UNSET = 3;

    /** Which scope the open transaction actually owns; reset when it closes. */
    private static string $scope = self::SCOPE_PANE;

    /**
     * Seconds an open latch may keep vouching for the option without
     * looking at it again. The process-global latch was once permanent,
     * so a mid-session `set-option allow-passthrough off` elsewhere on a
     * long-lived server kept the decorator emitting graphics envelopes the
     * pane then swallowed: silent image loss pinned behind a cached true.
     * On expiry ensure() re-queries and, when needed, re-applies (SI-40).
     */
    private const REQUERY_SECONDS = 30.0;

    /**
     * Env seam overriding {@see REQUERY_SECONDS} in seconds (a negative or
     * unparsable value falls back to the default; `0` means EVERY ensure()
     * re-verifies — the strongest honesty mode, at one show per render
     * bracket). Same override idiom as TmuxBinary::ENV_OVERRIDE; embedders
     * that own their tmux configuration would rather opt out wholesale
     * with Detect::setAutoPassthrough(false).
     */
    private const ENV_REQUERY_SECONDS = 'SUGAR_IMAGE_TMUX_PASSTHROUGH_TTL';

    /** Monotonic moment the current latch stops vouching; null = no lease. */
    private static ?float $leaseExpiresAt = null;

    /** True once ensure() has completed for this process (idempotence latch). */
    private static bool $applied = false;

    /** True only when WE set the option — retire() restores nothing without it. */
    private static bool $changed = false;

    /** The value seen before the change; null means the option was previously unset. */
    private static ?string $original = null;

    /** Shutdown-restore registration latch (register_shutdown_function is once-only here). */
    private static bool $shutdownRegistered = false;

    private function __construct()
    {
    }

    /**
     * Flip `allow-passthrough` on for this pane if it is not already passing
     * through, remembering the prior state for {@see retire()}.
     *
     * Idempotent: the second call in the same process is a no-op that
     * reports the first call's outcome, mirroring chicle's
     * `g_return_val_if_fail(original == NULL && is_changed == FALSE)`
     * re-entry guard (chicle-options.c:1813-1814).
     *
     * @return bool true when the pane is known (already was, or now is) to pass graphics through
     */
    public static function ensure(): bool
    {
        // Guard clauses first: opted out, or not in tmux at all, costs zero processes.
        if (!Detect::autoPassthrough()) {
            return false;
        }
        if (getenv('TMUX') === false) {
            return false;
        }
        if (self::$applied) {
            // The latch vouches only for its lease (SI-40): inside the
            // window the no-process fast path answers; at expiry the
            // option is re-read (and re-applied if it was flipped away)
            // so a mid-session toggle elsewhere cannot keep asserting
            // envelopes the pane has stopped passing through.
            if (self::$leaseExpiresAt === null || hrtime(true) / 1_000_000_000 <= self::$leaseExpiresAt) {
                return true;
            }

            return self::revalidate();
        }

        // Exit-aware answer legs (SI-39): the pane child's exit code is part
        // of the answer — 0 carries the value, 3 is tmux's `-q` grammar for
        // a KNOWN-but-unset option, anything else (unknown option,
        // unresolvable target, dead server) is NOT-INFORMATIVE and earns ONE
        // documented global-scope retry instead of being misread as "was
        // unset" and paying a fabricated set-write. A dead transport (null:
        // binary refused, deadline, flood) is scope-agnostic — the same
        // socket would serve both — so it ends the story with zero writes.
        $answer = self::showOption(self::SCOPE_PANE);
        if ($answer === null) {
            return false;
        }
        if ($answer[0]) {
            self::$scope = self::SCOPE_PANE;
        } else {
            $answer = self::showOption(self::SCOPE_GLOBAL);
            if ($answer === null || !$answer[0]) {
                return false; // neither scope can answer: no write is even tried
            }
            self::$scope = self::SCOPE_GLOBAL;
        }

        $value = $answer[1];
        $wasUnset = $value === '';
        if (!$wasUnset && !in_array($value, self::KNOWN_VALUES, true)) {
            return false; // an answer we cannot restore faithfully — hands off
        }
        if (!$wasUnset && in_array($value, self::ALREADY_PASSTHROUGH, true)) {
            self::$applied = true;
            self::$leaseExpiresAt = hrtime(true) / 1_000_000_000 + self::leaseSeconds();
            return true; // already fine; leave the option exactly as found
        }

        $set = self::$scope === self::SCOPE_PANE
            ? self::runTmux(['set-option', '-p', self::OPTION, 'on'])
            : self::runTmux(['set-option', '-g', self::OPTION, 'on']);
        if ($set === null || $set['exit'] !== 0) {
            return false;
        }

        self::$applied = true;
        self::$changed = true;
        self::$original = $wasUnset ? null : $value;
        self::$leaseExpiresAt = hrtime(true) / 1_000_000_000 + self::leaseSeconds();
        if (!self::$shutdownRegistered) {
            self::$shutdownRegistered = true;
            register_shutdown_function([self::class, 'retire']);
        }

        return true;
    }

    /**
     * Re-read the option behind an expired lease (SI-40). The restore
     * ledger ($original/$changed/$scope) survives the re-query untouched:
     * revalidating is not a new transaction, it is the same one proving
     * it is still worth vouching for.
     */
    private static function revalidate(): bool
    {
        $answer = self::showOption(self::$scope);
        if ($answer === null || !$answer[0]) {
            return false; // cannot verify: stop vouching, keep the ledger for the shutdown restore
        }

        $value = $answer[1];
        if (in_array($value, self::ALREADY_PASSTHROUGH, true)) {
            self::$leaseExpiresAt = hrtime(true) / 1_000_000_000 + self::leaseSeconds();
            return true; // still passing — renewed lease, zero writes
        }
        if ($value !== '' && !in_array($value, self::KNOWN_VALUES, true)) {
            return false; // someone wrote an unrestorable value: hands off, exactly as at intake
        }

        // Flipped off (or unset) mid-session: re-apply on the transaction's
        // scope. A transaction that originally only READ an already-on
        // option now owns the flip-back it is performing, with the flipper's
        // current value remembered so retire still lands faithfully — and
        // the takeover registers the shutdown restore too, exactly as a
        // first-set does: from this moment a forgotten retire must still
        // leave the pane as the flipper left it.
        if (!self::$changed) {
            self::$changed = true;
            self::$original = $value === '' ? null : $value;
            if (!self::$shutdownRegistered) {
                self::$shutdownRegistered = true;
                register_shutdown_function([self::class, 'retire']);
            }
        }

        $set = self::$scope === self::SCOPE_PANE
            ? self::runTmux(['set-option', '-p', self::OPTION, 'on'])
            : self::runTmux(['set-option', '-g', self::OPTION, 'on']);
        if ($set === null || $set['exit'] !== 0) {
            return false;
        }

        self::$leaseExpiresAt = hrtime(true) / 1_000_000_000 + self::leaseSeconds();

        return true;
    }

    /**
     * Lease length in seconds: {@see ENV_REQUERY_SECONDS} when it parses
     * to a non-negative number, the default otherwise (fail-safe to the
     * conservative window, never to "always expired" on junk input).
     */
    private static function leaseSeconds(): float
    {
        $raw = getenv(self::ENV_REQUERY_SECONDS);
        if ($raw === false || !is_numeric($raw)) {
            return self::REQUERY_SECONDS;
        }
        $seconds = (float) $raw;

        return $seconds < 0 ? self::REQUERY_SECONDS : $seconds;
    }

    /**
     * Restore what {@see ensure()} changed. Safe to call any number of times;
     * a retire with nothing to restore succeeds without touching a process.
     *
     * The unset-vs-off polarity is chicle-options.c:1853-1861 verbatim in
     * behaviour: a remembered value is re-`set`, a remembered UNSET is
     * cleared with `-u` (writing any literal would leave an explicit value
     * where none existed before).
     *
     * @return bool true when there was nothing to restore or the restore landed
     */
    public static function retire(): bool
    {
        if (!self::$applied) {
            return true;
        }
        if (!self::$changed) {
            self::$applied = false;
            return true;
        }

        $restore = match (true) {
            self::$scope === self::SCOPE_GLOBAL && self::$original === null
                => ['set-option', '-gu', self::OPTION],
            self::$scope === self::SCOPE_GLOBAL
                => ['set-option', '-g', self::OPTION, self::$original],
            self::$original === null
                => ['set-option', '-pu', self::OPTION],
            default => ['set-option', '-p', self::OPTION, self::$original],
        };

        $done = self::runTmux($restore);
        if ($done === null || $done['exit'] !== 0) {
            return false; // state kept: a later retire() (or the shutdown hook) retries
        }

        self::$applied = false;
        self::$changed = false;
        self::$original = null;
        self::$scope = self::SCOPE_PANE;

        return true;
    }

    /**
     * Whether the transaction is currently open (ensure() landed, retire()
     * has not). Introspection for callers that bracket rendering themselves.
     */
    public static function isApplied(): bool
    {
        return self::$applied;
    }

    /**
     * Ask one scope for the option value, honouring tmux's exit grammar.
     *
     * @return array{0:bool,1:string}|null [informative, value]; null when
     *     the transport itself is dead (exec refused, deadline, flood) —
     *     that failure is scope-agnostic and must not be retried. The
     *     [false,''] arm is an INFORMED refusal: the child ran and answered
     *     with an exit that is neither 0 (value) nor 3 (known-but-unset
     *     under -q), which is what makes SI-39's global fallback sound.
     */
    private static function showOption(string $scope): ?array
    {
        $shown = self::runTmux($scope === self::SCOPE_PANE
            ? ['show-options', '-pqv', self::OPTION]
            : ['show-options', '-gqv', self::OPTION]);
        if ($shown === null) {
            return null;
        }
        if ($shown['exit'] === 0) {
            return [true, trim($shown['stdout'])];
        }
        if ($shown['exit'] === self::EXIT_KNOWN_UNSET) {
            return [true, ''];
        }

        return [false, ''];
    }

    /**
     * Run one tmux subcommand, argv-array form (no shell in between).
     *
     * Bounded: the read loop carries {@see EXEC_DEADLINE_SECONDS}, an
     * overrunning child walks the house TERM→grace-poll→KILL ladder, and
     * EVERY path ends in proc_close() so no child or zombie outlives this
     * call (child-lifetime law; see tools/check-child-lifetimes.php).
     * stderr goes to /dev/null — failure is reported by the exit code alone,
     * and a reader-less stderr pipe would SIGPIPE the child mid-command.
     *
     * @param list<string> $args
     * @return array{stdout:string,exit:int}|null null when the command never completed
     */
    private static function runTmux(array $args): ?array
    {
        $binary = TmuxBinary::path();
        if ($binary === null) {
            // Fail-soft law: no gate-passing tmux means no transaction,
            // never a PATH-searched gamble (the bare-name door this
            // resolution exists to close).
            return null;
        }
        $descriptorSpec = [
            // NUL on Windows, which has no /dev/null.
            0 => ['file', \DIRECTORY_SEPARATOR === '\\' ? 'NUL' : '/dev/null', 'r'],
            1 => ['pipe', 'w'],
            2 => ['file', \DIRECTORY_SEPARATOR === '\\' ? 'NUL' : '/dev/null', 'w'],
        ];
        $pipes = [];
        $proc = @proc_open(array_merge([$binary], $args), $descriptorSpec, $pipes);
        if (!is_resource($proc)) {
            foreach ($pipes ?? [] as $pipe) {
                if (is_resource($pipe)) {
                    fclose($pipe);
                }
            }
            return null;
        }

        $stdout = '';
        $completed = false;
        $deadline = hrtime(true) / 1_000_000_000 + self::EXEC_DEADLINE_SECONDS;
        if (WindowsPipes::runtimeNeedsIt()) {
            // A Windows pipe defeats the select loop below (ready while
            // empty, then a parked fread); read what fstat reports waiting
            // until the child exits, under the same deadline.
            $drained = WindowsPipes::drainUntilExit([1 => $pipes[1]], WindowsPipes::runningProbe($proc), microtime(true) + self::EXEC_DEADLINE_SECONDS, self::MAX_OUTPUT_BYTES + 1);
            $stdout = $drained['buffers'][1];
            $completed = !$drained['timedOut'] && strlen($stdout) <= self::MAX_OUTPUT_BYTES;
        }
        while (!WindowsPipes::runtimeNeedsIt()) {
            $remaining = $deadline - hrtime(true) / 1_000_000_000;
            if ($remaining <= 0) {
                break;
            }
            $read = [$pipes[1]];
            $write = null;
            $except = null;
            $ready = @stream_select($read, $write, $except, (int) $remaining, (int) (($remaining - (int) $remaining) * 1_000_000));
            if ($ready === false) {
                break; // signal-interrupted select: treat the command as blown
            }
            if ($ready === 0) {
                continue; // select spent its slice: the deadline check bounds us
            }
            $chunk = fread($pipes[1], 8192);
            if ($chunk === false) {
                $completed = true; // read error reads as end-of-stream here; exit code judges
                break;
            }
            $stdout .= $chunk;
            if (strlen($stdout) > self::MAX_OUTPUT_BYTES) {
                break; // an answer this long is not a toggle value; kill and bail
            }
            if (feof($pipes[1])) {
                $status = proc_get_status($proc);
                if (!$status['running']) {
                    $completed = true;
                    break;
                }
            }
        }
        fclose($pipes[1]);

        if (!$completed) {
            self::terminateBounded($proc);
            proc_close($proc);
            return null;
        }

        // EOF is reached; the child still has to exit for a stable exit code.
        $status = proc_get_status($proc);
        if ($status['running']) {
            self::terminateBounded($proc);
        }

        return ['stdout' => $stdout, 'exit' => proc_close($proc)];
    }

    /**
     * SIGTERM, poll through the grace window, SIGKILL, poll for the reap.
     * Never waits longer than TERM_GRACE_SECONDS × 2 in the worst case.
     *
     * Signals are INTEGER LITERALS (15, 9), never the `SIGTERM`/`SIGKILL`
     * constants, because those are only defined with ext-pcntl or
     * ext-posix loaded — the same rule sugar-core's BoundedShutdown walks
     * (sugar-core/src/Util/Proc/BoundedShutdown.php:34-37). proc_terminate()
     * accepts the numbers directly, so no signal-extension dependency is
     * added to the lib.
     *
     * @param resource $proc
     */
    private static function terminateBounded($proc): void
    {
        $status = proc_get_status($proc);
        if (!$status['running']) {
            return;
        }

        proc_terminate($proc, 15);
        if (self::awaitExit($proc, self::TERM_GRACE_SECONDS)) {
            return;
        }

        // Signal 9 as an integer literal — see the doc-block above.
        // proc_terminate carries the escalation too, so this file needs no
        // ext-posix at all (posix_kill would, and the posix extension is
        // not a declared requirement of sugar-image).
        proc_terminate($proc, 9);
        self::awaitExit($proc, self::TERM_GRACE_SECONDS);
    }

    /**
     * Poll the child's status until it reports not-running or the budget
     * expires. One poll happens before the first sleep and one after the
     * last, so the return is the measured liveness, not a hope.
     *
     * @param resource $proc
     */
    private static function awaitExit($proc, float $budgetSeconds): bool
    {
        $deadline = hrtime(true) / 1_000_000_000 + $budgetSeconds;
        do {
            if (!proc_get_status($proc)['running']) {
                return true;
            }
            usleep(10_000);
        } while (hrtime(true) / 1_000_000_000 < $deadline);

        return !proc_get_status($proc)['running'];
    }
}
