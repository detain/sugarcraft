<?php

declare(strict_types=1);

namespace SugarCraft\Core\Util\Clipboard;

use SugarCraft\Core\Util\Ansi;
use SugarCraft\Core\Util\Executable\Locator;
use SugarCraft\Core\Util\Proc\BoundedShutdown;

/**
 * Hand copied text to the host's clipboard tool, alongside the OSC 52 write.
 *
 * OSC 52 alone is not enough on the setups a terminal application actually
 * runs in. tmux's default `set-clipboard external` IGNORES an application's
 * OSC 52 — only tmux itself may set the outer terminal's clipboard — so a
 * copy made inside tmux goes nowhere; many terminals additionally ship with
 * OSC 52 writes off. `tmux load-buffer -w -` is the documented way in: it
 * fills a tmux paste buffer AND forwards the text to the outer terminal
 * through tmux's own OSC 52. Outside tmux the platform tool is tried, in the
 * order Neovim's clipboard provider probes: pbcopy, wl-copy, xclip, xsel.
 *
 * DEGRADATION LAW — the parity half. The OSC 52 sequence is the portable
 * channel that needs no tool, so it is NEVER this class's failure mode:
 * {@see osc52()} hands back the exact bytes `Cmd::setClipboard`/
 * `Ansi::setClipboard` already emit, the caller fwrites them to the terminal
 * unconditionally, and the host-tool attempts ride ALONGSIDE that write. A
 * host with no tool here is not a broken copy — it is the pre-existing
 * OSC-52-only behaviour, unchanged.
 *
 * ONE TOOL, FIRST MATCH, BEST EFFORT. A copy is a convenience riding on a
 * mouse release: nothing here throws at runtime (missing tools, failed exits
 * and wedged children are recorded outcomes, not exceptions), the probe stops
 * at the first tool that accepts the text, and the whole spawn is held to the
 * caller's deadline with the TERM→KILL ladder so a wedged X forward cannot
 * freeze a TUI.
 *
 * STDOUT/STDERR GO TO THE NULL DEVICE, NOT PIPES. `xclip` and `wl-copy` fork
 * a daemon that keeps serving the selection after the command returns; a
 * daemon holding our pipe's write end would make every drain wait out the
 * deadline. Only stdin is a pipe, and it is closed as soon as the text is in.
 *
 * NO SHELL IS EVER SPAWNED. Every invocation is a direct argv-array
 * `proc_open()` whose first element is a binary already located and verified
 * by {@see Locator} — so the AGENTS.md `escapeshellarg()` gotcha (written for
 * shell-string assembly) is honoured in its stronger form: no byte of the
 * copied text, and no PATH-derived binary name, is ever parsed by a shell.
 * Locating before spawning additionally keeps PHP's missing-binary warning
 * away from a suite that runs with `failOnWarning`.
 */
final class Clipboard
{
    /** Default wall-clock budget for one copy attempt, spawn to reap. */
    public const TIMEOUT_SECONDS = 1.0;

    /** Seconds a wedged clipboard tool gets on SIGTERM before the ladder escalates. */
    public const TERMINATE_GRACE_SECONDS = 0.25;

    /** Seconds to confirm the SIGKILL landed. */
    public const KILL_GRACE_SECONDS = 0.25;

    /** Granularity of the exit-status poll, in microseconds. */
    private const EXIT_POLL_MICROSECONDS = 5_000;

    /**
     * Test seam: replaces the spawn. Receives the argv and the text, returns
     * whether the copy succeeded. Null (the default) runs the real tool.
     *
     * @var (\Closure(list<string>, string): bool)|null
     */
    private static ?\Closure $runner = null;

    private function __construct()
    {
    }

    /**
     * Try this host's clipboard tools for $text and report every attempt.
     *
     * Attempts stop at the first success (one tool owning the clipboard is
     * the whole job); with no tool succeeding the list carries every probe —
     * and the caller still owes the {@see osc52()} write, which is the
     * portable channel this class only augments.
     *
     * @return list<array{mechanism: string, argv: list<string>, succeeded: bool}>
     */
    public static function copy(string $text, float $timeoutSeconds = self::TIMEOUT_SECONDS): array
    {
        if ($text === '') {
            return [];
        }

        $results = [];

        foreach (self::candidates() as $argv) {
            $succeeded = self::run($argv, $text, $timeoutSeconds);

            $results[] = [
                'mechanism' => basename($argv[0]),
                'argv' => $argv,
                'succeeded' => $succeeded,
            ];

            if ($succeeded) {
                break;
            }
        }

        return $results;
    }

    /**
     * True when any row of a {@see copy()} result succeeded — so consumers
     * never re-derive the fold, and an empty list (no tool on this host, or
     * empty text) reads honestly as false.
     *
     * @param list<array{mechanism: string, argv: list<string>, succeeded: bool}> $results
     */
    public static function succeeded(array $results): bool
    {
        return in_array(true, array_column($results, 'succeeded'), true);
    }

    /**
     * The raw OSC 52 sequence for $text — byte-identical to
     * {@see Ansi::setClipboard()} by delegation, pinned by a test so the two
     * channels can never drift apart. The caller fwrites these regardless of
     * what {@see copy()} achieved: see the degradation law in the class
     * doc-block.
     */
    public static function osc52(string $text, string $selection = 'c'): string
    {
        return Ansi::setClipboard($text, $selection);
    }

    /**
     * The argv lists this environment offers, in probe order, each already
     * located on PATH so a spawn never targets a missing binary.
     *
     * Inside tmux (`TMUX` set, or `TERM_PROGRAM=tmux` as exported by tmux and
     * its wrappers) ONLY tmux is tried: it owns the visible clipboard story,
     * and the `-w` form (tmux >= 3.2) is the half that reaches the outer
     * terminal — an older tmux rejects `-w`, and the plain form still leaves
     * the text in a paste buffer for `prefix ]`.
     *
     * @return list<list<string>>
     */
    public static function candidates(): array
    {
        return self::plan(PHP_OS_FAMILY, self::environment(), static fn (string $binary): ?string => Locator::locate($binary));
    }

    /**
     * The probe-order derivation, exposed as a pure function of (OS family,
     * environment, locator) so every branch of the matrix — Darwin included
     * — is pinned deterministically on any host.
     *
     * @param array<string, string> $environment the clipboard-relevant vars
     *        (`TMUX`, `TERM_PROGRAM`, `WAYLAND_DISPLAY`, `DISPLAY`); a
     *        missing key is the empty string, an empty value is "off".
     * @param callable(string): (?string) $locate resolves a binary name to
     *        its absolute path, or null when absent.
     *
     * @return list<list<string>>
     */
    public static function plan(string $osFamily, array $environment, callable $locate): array
    {
        /** @var list<list<string>> $found */
        $found = [];
        $add = static function (string $binary, string ...$args) use (&$found, $locate): void {
            $path = $locate($binary);
            if ($path !== null && $path !== '') {
                $found[] = [$path, ...$args];
            }
        };

        if ((string) ($environment['TMUX'] ?? '') !== '' || (string) ($environment['TERM_PROGRAM'] ?? '') === 'tmux') {
            $add('tmux', 'load-buffer', '-w', '-');
            $add('tmux', 'load-buffer', '-');

            return $found;
        }

        if ($osFamily === 'Darwin') {
            $add('pbcopy');
        }
        if ((string) ($environment['WAYLAND_DISPLAY'] ?? '') !== '') {
            $add('wl-copy');
        }
        if ((string) ($environment['DISPLAY'] ?? '') !== '') {
            $add('xclip', '-selection', 'clipboard');
            $add('xsel', '--clipboard', '--input');
        }

        return $found;
    }

    /**
     * Inject a spawn replacement (tests only). Returns the previously
     * installed runner so a caller can restore it.
     *
     * @param (\Closure(list<string>, string): bool)|null $runner
     * @return (\Closure(list<string>, string): bool)|null
     */
    public static function useRunnerForTesting(?\Closure $runner): ?\Closure
    {
        $previous = self::$runner;
        self::$runner = $runner;

        return $previous;
    }

    /**
     * The env vars {@see candidates()} reads, in one snapshot so the plan
     * derives from a consistent view rather than five `getenv()` calls that
     * could straddle an edit.
     *
     * @return array<string, string>
     */
    private static function environment(): array
    {
        $environment = [];
        foreach (['TMUX', 'TERM_PROGRAM', 'WAYLAND_DISPLAY', 'DISPLAY'] as $name) {
            $environment[$name] = (string) getenv($name);
        }

        return $environment;
    }

    /**
     * Feed $text to one candidate and judge its exit, bounded end to end.
     *
     * @param list<string> $argv
     */
    private static function run(array $argv, string $text, float $timeoutSeconds): bool
    {
        if (self::$runner !== null) {
            return (self::$runner)($argv, $text);
        }

        $nullDevice = PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';

        /** @var array<int, resource> $pipes */
        $pipes = [];
        $process = @proc_open(
            $argv,
            [
                0 => ['pipe', 'r'],
                1 => ['file', $nullDevice, 'w'],
                2 => ['file', $nullDevice, 'w'],
            ],
            $pipes,
        );
        if (!\is_resource($process)) {
            return false;
        }

        $deadline = \hrtime(true) / 1_000_000_000 + $timeoutSeconds;
        $written = self::feed($pipes[0], $text, $deadline);
        fclose($pipes[0]);

        // The exit code comes from the status poll, not proc_close(): once
        // proc_get_status() has observed the exit, proc_close() reports -1.
        $exitCode = $written ? self::waitForExit($process, $deadline) : null;
        if ($exitCode !== 0) {
            // Covers both shapes: never exited (deadline passed — escalate to
            // free the deadline) and exited nonzero (already gone — the
            // ladder fast-paths out without spending a signal).
            BoundedShutdown::terminateAndAwaitExit(
                $process,
                self::TERMINATE_GRACE_SECONDS,
                self::KILL_GRACE_SECONDS,
                BoundedShutdown::groupId($process),
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
            $remaining = $deadline - \hrtime(true) / 1_000_000_000;
            if ($remaining <= 0.0) {
                return false;
            }

            $read = null;
            $write = [$stdin];
            $except = null;
            $seconds = (int) $remaining;
            $micros = (int) (($remaining - $seconds) * 1_000_000);

            $ready = @stream_select($read, $write, $except, $seconds, $micros);
            if ($ready === false) {
                return false;
            }
            // Judge readiness on select()'s documented return count, not on
            // the by-reference $write it rewrites: $write === [] and
            // $ready === 0 are the same fact here (one stream in one set),
            // but the count is what the signature promises. Zero means the
            // select timed out with no ready writer — loop so the deadline
            // guard at the top of the iteration can end the wait.
            if ($ready === 0) {
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
     * The child's exit code once it is gone, or null if $deadline passed
     * first — a bounded poll, never a blocking wait.
     *
     * @param resource $process
     */
    private static function waitForExit($process, float $deadline): ?int
    {
        while (true) {
            // The resource is alive until the caller's proc_close() runs
            // after this returns, and proc_get_status() on a live resource
            // always answers the full fixed-shape array — mid-reap polls
            // report running=false with exitcode filled, never a missing
            // offset. Read the keys directly.
            $status = proc_get_status($process);
            if ($status['running'] !== true) {
                return (int) $status['exitcode'];
            }

            if (\hrtime(true) / 1_000_000_000 >= $deadline) {
                return null;
            }

            usleep(self::EXIT_POLL_MICROSECONDS);
        }
    }
}
