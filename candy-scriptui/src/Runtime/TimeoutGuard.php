<?php

declare(strict_types=1);

namespace SugarCraft\ScriptUi\Runtime;

use React\EventLoop\LoopInterface;
use React\EventLoop\TimerInterface;

/**
 * Wall-clock deadline for an interactive {@see \SugarCraft\Core\Program} run.
 *
 * gum's `--timeout N` aborts a prompt N seconds after it starts, no matter
 * what the user is doing. sugar-core's Program has no built-in deadline and
 * we deliberately keep it that way — a general TUI runtime shouldn't grow a
 * policy knob that only the CandyScriptui CLI layer needs. Instead each command
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
     * Strict parse of a `--timeout` option value into armable seconds.
     *
     * A deadline flag must fail LOUD, never open: a bare `(float)` cast
     * maps a typo like `--timeout abc` to 0.0, and 0.0 means "no limit"
     * here — the run would then hang exactly when the caller asked for a
     * bound. Accepted shapes (scriptui-27): a non-negative finite number
     * (null/absent = inert), or a Go time.Duration string — 5s, 250ms,
     * 1m30s, 1.5h, µs/micro spellings included. Everything else refuses.
     *
     * @param mixed $raw     Raw option value (string|int|float|null).
     * @param float $seconds Out-param: the parsed deadline in seconds
     *                       (0.0 = inert) whenever true is returned.
     */
    public static function tryParseSeconds(mixed $raw, float &$seconds): bool
    {
        $seconds = 0.0;
        if ($raw === null) {
            return true;
        }
        if (is_int($raw)) {
            $seconds = (float) $raw;
            return $seconds >= 0.0;
        }
        if (is_float($raw)) {
            if (!is_finite($raw)) {
                return false;
            }
            $seconds = $raw;
            return $seconds >= 0.0;
        }
        if (!is_string($raw)) {
            return false;
        }
        if (is_numeric($raw)) {
            $parsed = (float) $raw;
            if (!is_finite($parsed) || $parsed < 0.0) {
                return false;
            }
            $seconds = $parsed;
            return true;
        }
        // scriptui-27 (finding 023): gum's --timeout is a Go
        // time.Duration — `5s`, `250ms`, `1m30s`, `1.5h` are the shapes
        // users actually type; the bare-number reading only covered the
        // seconds unit. A duration suffix is accepted in every Go
        // spelling (ns/us/µs/ms/s/m/h, decimals with . or ,); compound
        // sequential forms sum. Garbage still REFUSES — the loud-not-open
        // rule above is unchanged.
        return self::parseGoDuration($raw, $seconds);
    }

    private static function parseGoDuration(string $raw, float &$seconds): bool
    {
        $spec = strtolower(trim($raw));
        if ($spec === '') {
            return false;
        }
        // µ (U+00B5) and μ (U+03BC) both spell Go's micro second.
        $spec = strtr($spec, ["\u{00B5}" => 'u', "\u{03BC}" => 'u', ',' => '.']);
        $mult = ['ns' => 1e-9, 'us' => 1e-6, 'ms' => 1e-3, 's' => 1.0, 'm' => 60.0, 'h' => 3600.0];
        $total = 0.0;
        $pos   = 0;
        $len   = strlen($spec);
        while ($pos < $len) {
            if (
                !preg_match('/\G([0-9]+(?:\.[0-9]+)?)(ns|us|ms|s|m|h)/', $spec, $m, PREG_OFFSET_CAPTURE, $pos)
            ) {
                return false;
            }
            $total += (float) $m[1][0] * $mult[$m[2][0]];
            $pos   += strlen($m[0][0]);
        }
        if ($total > PHP_FLOAT_MAX || !is_finite($total)) {
            return false;
        }
        $seconds = $total;
        return true;
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
