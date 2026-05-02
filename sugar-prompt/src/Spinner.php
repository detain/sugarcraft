<?php

declare(strict_types=1);

namespace CandyCore\Prompt;

use CandyCore\Bits\Spinner\Spinner as BitsSpinner;
use CandyCore\Bits\Spinner\Style as SpinnerStyle;

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
            $action();
            exit(0);
        }
        // Parent: animate until the child exits.
        $frame = 0;
        $titlePrefix = $this->title === '' ? '' : ' ' . $this->title;
        $interval = $this->style->interval();
        $usleepInterval = (int) round($interval * 1_000_000);
        $isTty = function_exists('stream_isatty') && stream_isatty(STDERR);
        while (true) {
            $glyph = $this->style->frames[$frame % count($this->style->frames)];
            if ($isTty) {
                fwrite(STDERR, "\r" . $glyph . $titlePrefix);
            }
            usleep(max(50_000, $usleepInterval));
            $status = 0;
            $check = @pcntl_waitpid($pid, $status, WNOHANG);
            if ($check === $pid) {
                break;
            }
            $frame++;
        }
        if ($isTty) {
            // Erase the spinner line cleanly.
            fwrite(STDERR, "\r\x1b[2K");
        }
    }
}
