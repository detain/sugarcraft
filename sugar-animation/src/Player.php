<?php

declare(strict_types=1);

namespace SugarCraft\Animation;

use SugarCraft\Core\Cmd;
use SugarCraft\Core\Concerns\Mutable;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Model;
use SugarCraft\Core\Msg;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Core\Msg\WindowSizeMsg;

/**
 * E-03: GIF player as a SugarCraft Model driven by an ABSOLUTE schedule.
 * Loads every frame up-front (the decoder caps at 256); frame k is due at
 * `anchor + C[k]` where C is the prefix sum of the frame periods (modulo one
 * cycle of total length T), and each TickMsg re-arms the loop for the NEXT
 * frame's deadline — `max(nextDue - now, MIN_TICK_CS)`, never a fresh full
 * delay. A slow render or a stalled loop therefore costs lateness, not
 * drift: the following tick resolves its index straight from the elapsed
 * position and skips ahead when frames fell behind (the ArtLoop philosophy —
 * the displayed frame is a total function of elapsed time). Before E-03 the
 * Player re-armed the current frame's full delay on every tick, so the
 * per-frame period was `delay + handling + render time` and playback
 * silently stretched under load — the docblock here used to promise
 * rate-stability the schedule did not deliver.
 *
 * E-09: rate controls are {@see withFixedFps()} (uniform 1/fps period
 * replacing the GIF delays on the same absolute schedule, mirroring chafa's
 * `anim_fps > 0 ? 1000/fps : delay` branch, tools/chafa/chafa.c:855-859) and
 * {@see withMaxSeconds()} (a final schedule deadline that quits playback;
 * bounded by the model's own clock comparison — no wall-clock timers, no
 * kill). The clock is injectable via the `now:` seam so tests drive the
 * schedule with a fake clock; production reads `hrtime(true)` monotonic
 * nanoseconds.
 *
 * Keys: space — pause/resume.  ←/→ — manual step.  q/esc — quit.
 */
final class Player implements Model
{
    use Mutable;

    /**
     * Floor for every scheduled tick arm, in centiseconds.  {@see Frame} is
     * public API and documents `delay 0` as "no delay specified" (the GIF
     * Graphic Control Extension's inherit-the-previous marker), so a host
     * may legitimately hand us 0 — and a 0-second arm fires as fast as the
     * loop can run: a busy-loop flood, not playback. Under E-03 the floor is
     * applied twice, both times at the scheduler: when building the period
     * table (`framePeriod()`, so zero-delay frames still get a real slot)
     * and when clamping a re-arm whose deadline already passed (so catch-up
     * skips forward at most one frame per MIN_TICK_CS slice rather than
     * spinning). Rejected alternative: chafa's chicle loader rewrites every
     * GIF zero to a fixed 50 ms (tools/chafa/chicle-gif-loader.c:228-231) —
     * that would fight the Decoder's spec-honest inherit-previous policy.
     */
    private const MIN_TICK_CS = 1;

    /**
     * chicle's own ceiling for "--fps max" (CHICLE_ANIM_FPS_MAX,
     * chicle-options.h:60) — a cadence this fast is indistinguishable from
     * "burn frames without sleeping" behind the MIN_TICK_CS arm floor.
     */
    public const MAX_FPS = 100000.0;

    /**
     * @param list<Frame> $frames
     * @param ?\Closure():float $now E-09/E-03 clock seam: monotonic seconds;
     *        null (production) reads `hrtime(true) / 1e9`.
     * @param ?float $fixedFps E-09 uniform cadence, frames per second; null = follow GIF delays.
     * @param ?float $maxSeconds E-09 total play-time bound; playback quits at the final deadline.
     * @param ?float $anchor E-03 absolute-schedule zero point in clock units
     *        (the virtual instant at which frame 0 of the current cycle pass
     *        was due). Derived state — every public entry point leaves it
     *        null; the Player sets it on the first tick, on pause/resume, and
     *        on manual steps via {@see anchored()}.
     */
    public function __construct(
        public readonly array $frames,
        public readonly int $index = 0,
        public readonly bool $paused = false,
        public readonly string $preset = Renderer::PRESET_SOLID,
        public readonly ?Renderer $renderer = null,
        private readonly ?\Closure $now = null,
        public readonly ?float $fixedFps = null,
        public readonly ?float $maxSeconds = null,
        private readonly ?float $anchor = null,
    ) {
        // Fail fast: a Player that cannot display its start frame is a wiring
        // bug at construction time, not a state to carry into update()/view().
        // Empty frames with index 0 stay legal — init()/view() handle them.
        $inRange = $frames === [] ? $index === 0 : ($index >= 0 && $index < count($frames));
        if ($inRange === false) {
            throw new \InvalidArgumentException(
                'sugar-animation: Player start index ' . $index . ' out of range for ' . count($frames) . ' frame(s)',
            );
        }
    }

    public function init(): ?\Closure
    {
        if ($this->frames === [] || $this->paused) {
            return null;
        }
        return Cmd::tick($this->framePeriod($this->index), static fn (): Msg => new TickMsg());
    }

    public function update(Msg $msg): array
    {
        if ($msg instanceof KeyMsg) {
            if ($msg->type === KeyType::Escape
                || ($msg->type === KeyType::Char && $msg->rune === 'q')
                || ($msg->ctrl && $msg->rune === 'c')) {
                return [$this, Cmd::quit()];
            }
            if ($msg->type === KeyType::Space) {
                if ($this->paused === false) {
                    return [$this->mutate(['paused' => true]), null];
                }
                // E-03: resume re-anchors the schedule so the frame shown
                // when we paused gets a FRESH full period — paused wall time
                // never accrues (the anchor is index-derived).
                $next = $this->anchored($this->index, $this->clockNow())->mutate(['paused' => false]);

                return [$next, Cmd::tick($next->framePeriod($next->index), static fn (): Msg => new TickMsg())];
            }
            if ($msg->type === KeyType::Right) {
                $n = count($this->frames);
                if ($n === 0) {
                    return [$this, null];
                }
                return [$this->anchored(($this->index + 1) % $n, $this->clockNow()), null];
            }
            if ($msg->type === KeyType::Left) {
                $n = count($this->frames);
                if ($n === 0) {
                    return [$this, null];
                }
                return [$this->anchored(($this->index - 1 + $n) % $n, $this->clockNow()), null];
            }
            if ($msg->type === KeyType::Char && $msg->rune === 'd') {
                return [$this->withPreset(
                    $this->preset === Renderer::PRESET_SOLID
                        ? Renderer::PRESET_DENSITY
                        : Renderer::PRESET_SOLID,
                ), null];
            }
        }
        if ($msg instanceof TickMsg && $this->paused === false && $this->frames !== []) {
            $now = $this->clockNow();
            // E-09: the duration bound is a position on the schedule, not a
            // wall-clock timer: total play time is `now - anchor` (cycle-
            // monotonic while unpaused), and crossing maxSeconds quits.
            if ($this->anchor !== null && $this->maxSeconds !== null && ($now - $this->anchor) >= $this->maxSeconds) {
                return [$this, Cmd::quit()];
            }
            return $this->advanceSchedule($now);
        }
        if ($msg instanceof WindowSizeMsg) {
            // Re-clamp renderer to new window size, reserving one row for the
            // status line. Floor at 0: a 0-row WindowSizeMsg during a terminal
            // tear-down would otherwise feed withConstraints a negative row
            // limit, which it now rejects outright.
            $renderer = Renderer::withConstraints(max(0, $msg->rows - 1), $msg->cols);
            return [$this->mutate(['renderer' => $renderer]), null];
        }
        return [$this, null];
    }

    public function view(): string
    {
        if ($this->frames === []) {
            return "(no frames)\n";
        }
        $frame = $this->frames[$this->index];
        $renderer = $this->renderer ?? Renderer::new();
        $pic   = $renderer->renderFrame($frame, $this->preset);
        $total = count($this->frames);
        $status = sprintf(
            "frame %d/%d  ·  %s  ·  %s   space pause   ←/→ step   d preset   q quit",
            $this->index + 1, $total, $this->preset,
            $this->paused ? 'paused' : 'playing',
        );
        return $pic . "\n" . $status . "\n";
    }

    /**
     * E-09: override the per-frame GIF delays with a uniform cadence of
     * $fps frames per second on the same absolute schedule. Immutable.
     * Re-anchors the schedule to the currently shown frame so a mid-play
     * switch never triggers an instant catch-up jump.
     */
    public function withFixedFps(float $fps): self
    {
        if (!is_finite($fps) || $fps <= 0.0) {
            throw new \InvalidArgumentException(
                'sugar-animation: Player::withFixedFps() requires a positive finite fps, got ' . var_export($fps, true),
            );
        }
        $next = $this->mutate(['fixedFps' => $fps]);
        if ($next->anchor === null) {
            return $next;
        }
        return $next->anchored($next->index, $next->clockNow());
    }

    /**
     * E-09: stop playback once the schedule position (total play time; time
     * spent paused never accrues) reaches $seconds. The bound lives in the
     * model and is checked on each tick — no wall-clock timer is armed for
     * it, so a stalled loop cannot overshoot silently either.
     */
    public function withMaxSeconds(float $seconds): self
    {
        if (!is_finite($seconds) || $seconds <= 0.0) {
            throw new \InvalidArgumentException(
                'sugar-animation: Player::withMaxSeconds() requires a positive finite number of seconds, got ' . var_export($seconds, true),
            );
        }
        return $this->mutate(['maxSeconds' => $seconds]);
    }

    public function subscriptions(): ?\SugarCraft\Core\Subscriptions
    {
        return null;
    }

    /**
     * E-09: the duration bound measured so far on the current schedule
     * (null before the schedule is anchored, i.e. before the first tick).
     */
    public function playedSeconds(): ?float
    {
        if ($this->anchor === null) {
            return null;
        }
        return max(0.0, $this->clockNow() - $this->anchor);
    }

    private function withPreset(string $preset): self
    {
        return $this->mutate(['preset' => $preset]);
    }

    /**
     * Return a copy whose schedule is anchored so frame $showing is exactly
     * due at clock time $now: the first tick, every manual step, and every
     * resume pass through here, which is what keeps the deadline
     * index-derived (E-03 pause/resume safety).
     */
    private function anchored(int $showing, float $now): self
    {
        $cumulative = $this->cumulative();

        return $this->mutate(['index' => $showing, 'anchor' => $now - $cumulative[$showing]]);
    }

    /**
     * Period (seconds) the frame at $i is scheduled to hold: uniform 1/fps
     * under E-09's fixed cadence, otherwise the GIF centisecond delay
     * floored at MIN_TICK_CS (zero-delay frames keep a real slot).
     */
    private function framePeriod(int $i): float
    {
        if ($this->fixedFps !== null) {
            return 1.0 / $this->fixedFps;
        }

        return max($this->frames[$i]->delay, self::MIN_TICK_CS) / 100.0;
    }

    /**
     * Prefix sums C[0..n] of the frame periods; C[n] is one full cycle.
     *
     * @return list<float>
     */
    private function cumulative(): array
    {
        $c = [0.0];
        $n = count($this->frames);
        for ($i = 0; $i < $n; $i++) {
            $c[] = $c[$i] + $this->framePeriod($i);
        }

        return $c;
    }

    /**
     * E-03 core: resolve the schedule position at $now. The first tick of a
     * segment anchors so the NEXT frame's arrival is "now" (init()'s arm has
     * just elapsed); from then on the index is a pure function of elapsed
     * time — late ticks skip ahead (catch-up), while a tick that fires
     * inside the current frame's window (the loop answered early, or tests
     * pumping ticks faster than the schedule) still advances one frame for
     * liveness, re-anchoring so the forced frame gets a fresh full period.
     *
     * @return array{Player, \Closure}
     */
    private function advanceSchedule(float $now): array
    {
        $n = count($this->frames);
        $cumulative = $this->cumulative();
        $total = $cumulative[$n];
        $anchor = $this->anchor ?? $now - $cumulative[($this->index + 1) % $n];
        $pos = max(0.0, $now - $anchor);
        $cycle = (int) floor($pos / $total);
        $within = $pos - $cycle * $total;

        $landing = 0;
        for ($k = $n - 1; $k >= 0; $k--) {
            if ($within >= $cumulative[$k]) {
                $landing = $k;
                break;
            }
        }
        if ($landing !== $this->index) {
            // Schedule-driven arrival: catch-up may have skipped frames.
            $changes = ['index' => $landing];
            if ($this->anchor === null) {
                $changes['anchor'] = $anchor;
            }
            $next = $this->mutate($changes);
            $nextDueRel = $cycle * $total + ($landing + 1 < $n ? $cumulative[$landing + 1] : $total);

            // Arm from schedule-relative values: round-tripping through the
            // absolute anchor would cancel away the nanoseconds a real
            // hrtime() carries (ulp at ~1e9 s exceeds a centisecond).
            return [$next, $this->armFromRel($nextDueRel - $pos)];
        }

        // Early fire: force-advance one frame and make its hold start now.
        $landing = ($this->index + 1) % $n;
        $next = $this->anchored($landing, $now);

        return [$next, $this->armFromRel($this->framePeriod($landing))];
    }

    /**
     * Arm the next tick $distance seconds ahead, floored at MIN_TICK_CS so
     * a late fire still spins the schedule forward without flooding the
     * loop (the floor doubles as the catch-up rate limiter).
     */
    private function armFromRel(float $distance): \Closure
    {
        return Cmd::tick(max($distance, self::MIN_TICK_CS / 100.0), static fn (): Msg => new TickMsg());
    }

    private function clockNow(): float
    {
        $now = $this->now;

        return $now !== null ? (float) $now() : hrtime(true) / 1e9;
    }
}
