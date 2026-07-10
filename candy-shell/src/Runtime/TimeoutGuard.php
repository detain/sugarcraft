<?php

declare(strict_types=1);

namespace SugarCraft\Shell\Runtime;

use React\EventLoop\LoopInterface;
use React\EventLoop\TimerInterface;

/**
 * Wall-clock deadline for an interactive {@see \SugarCraft\Core\Program} run.
 *
 * gum's `--timeout N` aborts a prompt N seconds after it starts, no matter
 * what the user is doing. candy-core's Program has no built-in deadline and
 * we deliberately keep it that way — a general TUI runtime shouldn't grow a
 * policy knob that only the CandyShell CLI layer needs. Instead each command
 * arms a one-shot React timer on the SAME loop the Program runs on; when it
 * fires we hard-stop the loop (the `$onFire` closure calls `Program::kill()`)
 * and record that the *deadline* — not the user — ended the run, so the
 * caller can emit nothing and exit with {@see EXIT_TIMEOUT} instead of a
 * normal result.
 *
 * A guard armed with `$seconds <= 0` is inert (gum's "0 = no limit"): no
 * timer is scheduled and {@see fired()} stays false forever.
 */
final class TimeoutGuard
{
    /** GNU `timeout(1)` convention: 124 means "the deadline elapsed". */
    public const EXIT_TIMEOUT = 124;

    private bool $fired = false;

    private function __construct(
        private readonly ?LoopInterface $loop,
        private ?TimerInterface $timer,
    ) {
    }

    /**
     * Schedule a one-shot deadline on $loop. When $seconds > 0 the timer
     * flips {@see fired()} and invokes $onFire (typically
     * `fn () => $program->kill()`) exactly once. When $seconds <= 0 the guard
     * is inert — no timer is registered and the run is never interrupted.
     */
    public static function arm(LoopInterface $loop, float $seconds, \Closure $onFire): self
    {
        if ($seconds <= 0.0) {
            return new self(null, null);
        }

        $guard = new self($loop, null);
        $guard->timer = $loop->addTimer($seconds, static function () use ($guard, $onFire): void {
            $guard->fired = true;
            // One-shot: the timer has spent itself, so there is nothing left
            // for a later disarm() to cancel.
            $guard->timer = null;
            $onFire();
        });

        return $guard;
    }

    /** True once the deadline elapsed and $onFire ran. */
    public function fired(): bool
    {
        return $this->fired;
    }

    /**
     * Cancel a still-pending deadline. Safe (and cheap) to call after the
     * timer already fired, when the guard was never armed, or more than once
     * — the guard forgets its timer either way, so this is idempotent.
     */
    public function disarm(): void
    {
        if ($this->timer !== null && $this->loop !== null) {
            $this->loop->cancelTimer($this->timer);
        }
        $this->timer = null;
    }
}
