<?php

declare(strict_types=1);

namespace SugarCraft\Widgets\Progress;

use SugarCraft\Spring\Spring;
use SugarCraft\Core\Cmd;
use SugarCraft\Core\Model;
use SugarCraft\Core\Msg;
use SugarCraft\Core\Util\Color;
use SugarCraft\Core\Util\ColorProfile;

/**
 * Spring-physics-driven progress bar.
 *
 * Wraps a static {@see Progress} with `(currentPercent, velocity,
 * targetPercent)` state that's integrated forward via a SugarSpring
 * {@see Spring} on every animation tick. Mirrors charmbracelet/bubbles'
 * `Progress` Model — the `*WithCmd` verbs return the model plus a Cmd
 * that schedules the first tick (plain `setPercent`/`incrPercent`/
 * `decrPercent` are the value-verb spellings); subsequent ticks come
 * back through `update()` and re-issue themselves until the bar
 * settles within tolerance of the target.
 *
 * ```php
 * $bar = AnimatedProgress::new()->withWidth(40)->withDefaultGradient();
 * [$bar, $cmd] = $bar->setPercentWithCmd(0.5);   // schedule the animation
 * // dispatch $cmd via the Program; SpringTickMsg's flow back into update().
 * ```
 *
 * `isAnimating()` is true while a transition is in flight.
 */
final class AnimatedProgress implements Model
{
    private const TOLERANCE = 0.0005;

    /** Cached spring instance — reused across ticks to avoid per-tick allocation. */
    private ?Spring $spring = null;

    /** Stable per-instance routing id, mirrored from the Timer/Stopwatch M12 pattern. */
    public readonly int $id;

    /** Generation counter bumped by each new transition; in-flight stale ticks are refused. */
    public readonly int $epoch;

    private static int $nextId = 0;

    public function __construct(
        public readonly Progress $progress,
        public readonly float $current,
        public readonly float $velocity,
        public readonly float $target,
        public readonly float $angularFrequency,
        public readonly float $dampingRatio,
        public readonly float $fps,
        public readonly bool $animating,
        ?int $id = null,
        int $epoch = 0,
    ) {
        $this->id = $id ?? ++self::$nextId;
        $this->epoch = $epoch;
    }

    public static function new(
        int $width = 40,
        float $angularFrequency = 6.0,
        float $dampingRatio = 1.0,
        float $fps = 60.0,
    ): self {
        return new self(
            progress:         Progress::new()->withWidth($width),
            current:          0.0,
            velocity:         0.0,
            target:           0.0,
            angularFrequency: $angularFrequency,
            dampingRatio:     $dampingRatio,
            fps:              $fps,
            animating:        false,
        );
    }

    public function init(): ?\Closure
    {
        return null;
    }

    public function update(Msg $msg): array
    {
        if (
            !($msg instanceof SpringTickMsg)
            || $msg->id !== $this->id
            || $msg->epoch !== $this->epoch
        ) {
            return [$this, null];
        }
        if (!$this->animating) {
            return [$this, null];
        }
        [$pos, $vel] = $this->getSpring()->update($this->current, $this->velocity, $this->target);

        // Settle when both position is near the target and velocity is small.
        $settled = abs($pos - $this->target) < self::TOLERANCE && abs($vel) < self::TOLERANCE;
        $next = $this->copy(
            current:   $pos,
            velocity:  $vel,
            animating: !$settled,
        );
        if ($settled) {
            return [$next->copy(current: $this->target, velocity: 0.0), null];
        }
        return [$next, $next->scheduleTick()];
    }

    /**
     * Lazily create and cache a Spring instance tuned to the current fps,
     * angular frequency, and damping ratio. The cached instance is reused
     * across ticks to avoid the per-tick allocation of a new Spring.
     */
    private function getSpring(): Spring
    {
        return $this->spring ??= new Spring(
            Spring::fps(self::tickRate($this->fps)),
            $this->angularFrequency,
            $this->dampingRatio,
        );
    }

    /**
     * The single clock: the integer tick rate both the spring integration
     * and the tick scheduling are priced from, so the integrator's assumed
     * frame cadence and the actual dispatch cadence can never disagree
     * (a sub-1.0 fps floors to 1 — never to the fatal 0).
     */
    private static function tickRate(float $fps): int
    {
        return is_finite($fps) ? max(1, (int) $fps) : 60;
    }

    /** NaN renders as an empty bar, never a full one; mirrors Progress::clampPercent(). */
    private static function clampPercent(float $v): float
    {
        return is_nan($v) ? 0.0 : max(0.0, min(1.0, $v));
    }

    /**
     * Move toward `$percent` in animated steps (value verb — pairs with
     * {@see view()}; the animation chain is armed by the WithCmd forms
     * below or by the self-reissuing ticks inside `update()` once a chain
     * is in flight). Bumps the generation epoch, so ticks still in flight
     * from the superseded transition are refused.
     */
    public function setPercent(float $percent): self
    {
        $clamped = self::clampPercent($percent);
        return $this->copy(target: $clamped, animating: true, epoch: $this->epoch + 1);
    }

    /**
     * Command-carrying companion of {@see setPercent()}: the new model plus
     * the Cmd that fires the first tick. Subsequent ticks re-fire
     * automatically inside `update()` until the bar settles.
     *
     * @return array{0:self, 1:?\Closure}
     */
    public function setPercentWithCmd(float $percent): array
    {
        $next = $this->setPercent($percent);
        return [$next, $next->scheduleTick()];
    }

    /** Value verb; see {@see setPercent()}. Mirrors charmbracelet/bubbles `IncrPercent`. */
    public function incrPercent(float $delta): self
    {
        return $this->setPercent($this->target + $delta);
    }

    /** @return array{0:self, 1:?\Closure} */
    public function incrPercentWithCmd(float $delta): array
    {
        return $this->setPercentWithCmd($this->target + $delta);
    }

    /** Value verb; see {@see setPercent()}. Mirrors charmbracelet/bubbles `DecrPercent`. */
    public function decrPercent(float $delta): self
    {
        return $this->setPercent($this->target - $delta);
    }

    /** @return array{0:self, 1:?\Closure} */
    public function decrPercentWithCmd(float $delta): array
    {
        return $this->setPercentWithCmd($this->target - $delta);
    }

    /** Snap the displayed percent to `$p` instantly without animating. */
    public function jumpTo(float $p): self
    {
        $clamped = self::clampPercent($p);
        return $this->copy(
            current:   $clamped,
            velocity:  0.0,
            target:    $clamped,
            animating: false,
            epoch:     $this->epoch + 1,
        );
    }

    public function view(): string
    {
        return $this->progress->withPercent($this->current)->view();
    }

    public function isAnimating(): bool { return $this->animating; }

    /** Tunables — all forward to the wrapped Progress. */
    public function withWidth(int $w): self                  { return $this->copy(progress: $this->progress->withWidth($w)); }
    public function withRunes(string $f, string $e): self    { return $this->copy(progress: $this->progress->withRunes($f, $e)); }
    public function withShowPercent(bool $on): self          { return $this->copy(progress: $this->progress->withShowPercent($on)); }
    public function withFillColor(?Color $c): self           { return $this->copy(progress: $this->progress->withFillColor($c)); }
    public function withEmptyColor(?Color $c): self          { return $this->copy(progress: $this->progress->withEmptyColor($c)); }
    public function withColorProfile(ColorProfile $p): self  { return $this->copy(progress: $this->progress->withColorProfile($p)); }
    public function withGradient(?Color $a, ?Color $b): self { return $this->copy(progress: $this->progress->withGradient($a, $b)); }
    public function withSolidFill(Color $c): self            { return $this->copy(progress: $this->progress->withSolidFill($c)); }
    public function withDefaultGradient(): self              { return $this->copy(progress: $this->progress->withDefaultGradient()); }
    public function withPercentFormat(string $f): self       { return $this->copy(progress: $this->progress->withPercentFormat($f)); }

    /** Spring tunables. */
    public function withSpringOptions(float $angularFrequency, float $dampingRatio): self
    {
        return $this->copy(angularFrequency: $angularFrequency, dampingRatio: $dampingRatio);
    }

    public function withFps(float $fps): self
    {
        return $this->copy(fps: max(1.0, $fps));
    }

    private function scheduleTick(): \Closure
    {
        $interval = 1.0 / self::tickRate($this->fps);
        return Cmd::tick($interval, fn(): Msg => new SpringTickMsg($this->id, $this->epoch));
    }

    /**
     * Immutable rebuild. The spring cache survives exactly when the
     * three tuning fields are untouched — withSpringOptions()/withFps()
     * pass values, which drops the cache so the next getSpring() re-tunes.
     */
    private function copy(
        ?Progress $progress = null,
        ?float $current = null,
        ?float $velocity = null,
        ?float $target = null,
        ?float $angularFrequency = null,
        ?float $dampingRatio = null,
        ?float $fps = null,
        ?bool $animating = null,
        ?int $epoch = null,
    ): self {
        $next = new self(
            progress:         $progress         ?? $this->progress,
            current:          $current          ?? $this->current,
            velocity:         $velocity         ?? $this->velocity,
            target:           $target           ?? $this->target,
            angularFrequency: $angularFrequency ?? $this->angularFrequency,
            dampingRatio:     $dampingRatio     ?? $this->dampingRatio,
            fps:              $fps              ?? $this->fps,
            animating:        $animating        ?? $this->animating,
            id:               $this->id,
            epoch:            $epoch            ?? $this->epoch,
        );
        if ($angularFrequency === null && $dampingRatio === null && $fps === null) {
            $next->spring = $this->spring;
        }
        return $next;
    }

    public function subscriptions(): ?\SugarCraft\Core\Subscriptions
    {
        return null;
    }
}
