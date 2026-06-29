<?php

declare(strict_types=1);

namespace SugarCraft\Prompt;

use SugarCraft\Bits\Spinner\Spinner as BitsSpinner;
use SugarCraft\Bits\Spinner\Style as SpinnerStyle;
use SugarCraft\Core\Util\TtyDetect;

/**
 * Blocking "loading" prompt with a spinner. Mirrors huh's
 * `huh.NewSpinner().Title(...).Action(fn).Run()` — schedules a
 * worker callable, animates a {@see BitsSpinner} while it runs, and
 * returns once the action completes.
 *
 * This is **not** a Bubble Tea Model — it's a tiny driver that spins
 * the spinner via a sleep loop on the main process. Use it for
 * scripts and CLIs (CandyShell, ad-hoc tooling) that want a visible
 * "doing something" indicator without setting up a full Program.
 *
 * ### Fork semantics (pcntl hosts)
 *
 * On hosts with `pcntl_fork`, the action runs in a **forked child
 * process**. This means:
 * - In-memory mutations and return values are **not** visible to the
 *   parent after `run()` returns; communicate results out-of-band
 *   (tempfile, pipe, database, etc.).
 * - If the action throws, the exception is converted to a non-zero
 *   exit code; `run()` throws a `\RuntimeException` with the exit
 *   code. The original exception type and message cannot cross the
 *   fork boundary.
 *
 * Usage:
 *
 * ```php
 * Spinner::new()
 *     ->withTitle('Crunching numbers...')
 *     ->withStyle(SpinnerStyle::dot())
 *     ->withAction(static function () {
 *         // ... long-running work ...
 *     })
 *     ->run();
 * ```
 */
final class Spinner
{
    private string $title = '';
    private SpinnerStyle $style;
    /** @var ?\Closure(): void */
    private ?\Closure $action = null;

    public function __construct()
    {
        $this->style = SpinnerStyle::dot();
    }

    public static function new(): self
    {
        return new self();
    }

    public function withTitle(string $t): self
    {
        $clone = clone $this;
        $clone->title = $t;
        return $clone;
    }

    public function withStyle(SpinnerStyle $s): self
    {
        $clone = clone $this;
        $clone->style = $s;
        return $clone;
    }

    /** @param \Closure(): void $fn  long-running work to perform */
    public function withAction(\Closure $fn): self
    {
        $clone = clone $this;
        $clone->action = $fn;
        return $clone;
    }

    /**
     * Run the action, animating the spinner on STDERR until it returns.
     * STDERR is used so stdout stays clean for piped output.
     *
     * If no action was set, this is a no-op (returns immediately).
     */
    public function run(): void
    {
        if ($this->action === null) {
            return;
        }
        $action = $this->action;
        // Fork-and-spin where pcntl is available; fall back to running
        // the action inline (no animation) elsewhere.
        if (!function_exists('pcntl_fork')) {
            $action();
            return;
        }
        $pid = @pcntl_fork();
        if ($pid === -1) {
            $action();
            return;
        }
        if ($pid === 0) {
            // Child: run the action and exit.
            // Note: exceptions cannot cross the fork boundary; they are
            // converted to a non-zero exit code so the parent can detect
            // failure via the wait status.
            try {
                $action();
                exit(0);
            } catch (\Throwable $e) {
                fwrite(STDERR, $e->getMessage() . "\n");
                exit(1);
            }
        }
        // Parent: animate until the child exits.
        $frame = 0;
        $titlePrefix = $this->title === '' ? '' : ' ' . $this->title;
        $interval = $this->style->interval();
        $usleepInterval = (int) round($interval * 1_000_000);
        $isTty = TtyDetect::isAtty(STDERR);
        // Set up signal handlers to reap the child if the parent is interrupted.
        // $isTty must be declared before the closures are created so it can be
        // captured by use clause.
        $hadAsyncSignals = false;
        $prevSigintHandler = null;
        $prevSigtermHandler = null;
        if (function_exists('pcntl_signal') && function_exists('pcntl_async_signals')) {
            $hadAsyncSignals = true;
            pcntl_async_signals(true);
            $prevSigintHandler = pcntl_signal(SIGINT, function (int $signo) use ($pid, $isTty) {
                if (function_exists('posix_kill')) {
                    posix_kill($pid, SIGTERM);
                }
                pcntl_waitpid($pid, $status);
                if ($isTty) {
                    fwrite(STDERR, "\r\x1b[2K");
                }
                pcntl_signal($signo, SIG_DFL);
                if (function_exists('posix_kill')) {
                    posix_kill(posix_getpid(), $signo);
                }
            });
            $prevSigtermHandler = pcntl_signal(SIGTERM, function (int $signo) use ($pid, $isTty) {
                if (function_exists('posix_kill')) {
                    posix_kill($pid, SIGTERM);
                }
                pcntl_waitpid($pid, $status);
                if ($isTty) {
                    fwrite(STDERR, "\r\x1b[2K");
                }
                pcntl_signal($signo, SIG_DFL);
                if (function_exists('posix_kill')) {
                    posix_kill(posix_getpid(), $signo);
                }
            });
        }
        while (true) {
            $glyph = $this->style->frames[$frame % count($this->style->frames)];
            if ($isTty) {
                fwrite(STDERR, "\r" . $glyph . $titlePrefix);
            }
            usleep(max(50_000, $usleepInterval)); // 50ms floor caps animation at 20fps; stock styles top out at ~12fps (miniDot) so this never bites, but a custom high-fps Style is clamped here.
            $waitStatus = 0;
            $check = @pcntl_waitpid($pid, $waitStatus, WNOHANG);
            if ($check === $pid) {
                break;
            }
            $frame++;
        }
        // Restore signal handlers (avoid re-entrant calls from now on).
        if ($hadAsyncSignals) {
            pcntl_signal(SIGINT, SIG_DFL);
            pcntl_signal(SIGTERM, SIG_DFL);
        }
        if ($isTty) {
            // Erase the spinner line cleanly.
            fwrite(STDERR, "\r\x1b[2K");
        }
        // Reap and check exit status — throw if the child action failed.
        // Note: the original exception type/message cannot cross the fork
        // boundary; only the exit code is available to the parent.
        // $waitStatus was captured in the loop when the child was reaped.
        if (pcntl_wifexited($waitStatus) && pcntl_wexitstatus($waitStatus) !== 0) {
            throw new \RuntimeException('Spinner action failed (exit code ' . pcntl_wexitstatus($waitStatus) . ')');
        }
    }
}
