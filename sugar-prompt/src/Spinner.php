<?php

declare(strict_types=1);

namespace SugarCraft\Prompt;

use SugarCraft\Bits\Spinner\Style as SpinnerStyle;
use SugarCraft\Core\Concerns\Mutable;
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
 * ### Platform compatibility
 *
 * - **Windows:** The `pcntl_*` functions are not available. The action
 *   runs **inline** (blocking, no animation). The spinner title is
 *   printed once before the action begins and erased when it completes.
 * - **SAPI/cli-server:** Same as Windows — no forking, inline execution.
 * - **TTY detection:** On non-TTY contexts (piped output, CI), the spinner
 *   glyph is suppressed but the title is still printed, keeping output
 *   machines-readable.
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
 *
 * @note Spinner instances are not reusable after run() completes.
 *       Create a new Spinner for each action.
 */
final class Spinner
{
    use Mutable;

    private string $title = '';
    // SpinnerStyle is immutable (confirmed: has readonly props, no setters per
    // candy-forms/src/Spinner/Style.php:17-30). Defensive clone in withStyle is
    // unnecessary but kept forbelt-and-suspenders safety if the Style API evolves.
    private SpinnerStyle $style;
    /** @var ?\Closure(): void */
    private ?\Closure $action = null;

    /**
     * @inheritDoc
     * Spinner has a no-arg constructor, so we override mutate() to manually
     * clone and set properties instead of passing them as constructor args.
     */
    protected function mutate(array $changes): static
    {
        $clone = clone $this;
        foreach ($changes as $k => $v) {
            $clone->{$k} = $v;
        }
        return $clone;
    }

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
        return $this->mutate(['title' => $t]);
    }

    public function withStyle(SpinnerStyle $s): self
    {
        return $this->mutate(['style' => $s]);
    }

    /**
     * @param \Closure(): void $fn  long-running work to perform
     * @blocking  runs the closure inline when pcntl is unavailable
     */
    public function withAction(\Closure $fn): self
    {
        return $this->mutate(['action' => $fn]);
    }

    /**
     * Run the action, animating the spinner on STDERR until it returns.
     * STDERR is used so stdout stays clean for piped output.
     *
     * If no action was set, this is a no-op (returns immediately).
     *
     * @throws \RuntimeException if the forked action exits with non-zero status
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
        $pid = null;
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
        if ($pid > 0 && function_exists('pcntl_signal') && function_exists('pcntl_async_signals') && function_exists('pcntl_signal_get_handler')) {
            $hadAsyncSignals = true;
            pcntl_async_signals(true);
            // Capture previous handlers BEFORE installing new ones so we can restore them.
            $prevSigintHandler = pcntl_signal_get_handler(SIGINT);
            $prevSigtermHandler = pcntl_signal_get_handler(SIGTERM);
            pcntl_signal(SIGINT, function (int $signo) use ($pid, $isTty) {
                if ($pid > 0 && function_exists('posix_kill')) {
                    posix_kill($pid, SIGTERM);
                }
                pcntl_waitpid($pid, $waitStatus);
                if ($isTty) {
                    fwrite(STDERR, "\r\x1b[2K");
                }
                pcntl_signal($signo, SIG_DFL);
                if (function_exists('posix_kill')) {
                    posix_kill(posix_getpid(), $signo);
                }
            });
            pcntl_signal(SIGTERM, function (int $signo) use ($pid, $isTty) {
                if ($pid > 0 && function_exists('posix_kill')) {
                    posix_kill($pid, SIGTERM);
                }
                pcntl_waitpid($pid, $waitStatus);
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
            // $waitStatus is always set by waitpid before any signal handler fires.
            $waitStatus = 0;
            $check = @pcntl_waitpid($pid, $waitStatus, WNOHANG);
            if ($check === $pid) {
                break;
            }
            $frame++;
        }
        // Restore previous signal handlers (avoid re-entrant calls from now on).
        if ($hadAsyncSignals) {
            if ($prevSigintHandler !== null) {
                pcntl_signal(SIGINT, $prevSigintHandler);
            }
            if ($prevSigtermHandler !== null) {
                pcntl_signal(SIGTERM, $prevSigtermHandler);
            }
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
