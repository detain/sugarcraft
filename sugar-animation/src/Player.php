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
use SugarCraft\Core\Util\Width;

/**
 * E-03: GIF player as a SugarCraft Model driven by an ABSOLUTE schedule.
 * Loads every frame up-front (the decoder caps at 256); frame k is due at
 * `anchor + C[k]` where C is the prefix sum of the frame periods (modulo one
 * cycle of total length T), and each TickMsg re-arms the loop for the NEXT
 * frame's deadline — `max(nextDue - now, MIN_TICK_CS)` — never a fresh copy
 * of the CURRENT frame's delay. While the elapsed position resolves to a
 * frame OTHER than the one on screen the table stays absolute from the
 * kickoff origin: an overrun costs lateness, not drift, and the tick skips
 * straight to its landing frame (the ArtLoop philosophy — on that path the
 * displayed frame is a total function of elapsed time). The table DOES
 * rescale at the wrap boundary, however: when the elapsed position lands
 * back inside the window of the frame already on screen — which every stall
 * of at least one full cycle does, as does a pump fired inside the current
 * window — the tick force-advances exactly ONE frame and re-anchors the
 * epoch to wall-clock now, so that frame holds a fresh full period. Post-
 * wrap the cadence (the per-lap sequence of holds) is therefore preserved
 * while the epoch is re-based, bounding the catch-up to one advance per
 * tick. The naive alternative — continuing the absolute table without re-
 * basing — was rejected because the force-advanced frame would survive only
 * the sliver of window left before its table deadline (arbitrarily short
 * partial holds at every stall boundary); the re-anchor is the shipped
 * choice and PlayerScheduleTest pins the resulting index/arm stream against
 * an independent walk. Before E-03 the
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
 * E-10: a watching Player ({@see withWatch()}) re-runs its probe every
 * {@see WATCH_POLL_SECONDS} on the same self-arming tick-chain discipline
 * the schedule uses — chicle's `--watch` re-stat cycle
 * (tools/chafa/chafa.c:896-940) as an event-loop timer, never a sleep. A
 * probe that reports a new frame stream rebinds the Player in place via
 * {@see withFrames()}: index, lap counter and epoch reset with the file,
 * playback policy (preset, cadence, bounds, loops) survives — the
 * destroy-and-recreate parity chicle reaches by literally destroying the
 * media loader between files.
 *
 * Keys: space — pause/resume.  ←/→ — manual step.  d — toggle preset.
 * q / Esc / Ctrl+C — quit (SA-P1, audit 047: the full bound set, mirroring
 * the README table — update() honors every row of it).
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
     * E-10: how often a watching Player re-runs its probe, in seconds —
     * chicle's exact poll cadence for `--watch` (g_usleep(10000) at
     * tools/chafa/chafa.c:926, counted in the chafa clone @ 413b76e4d),
     * delivered here as an event-loop timer
     * instead of a sleep. Deviations from chicle, both deliberate: its
     * 250 ms backoff while the file is missing (:932) is not copied (the
     * probe owner decides what a failed stat costs; a stat(2) of a missing
     * path is cheap), and its re-decode of the file on EVERY poll because
     * "we can't rely on timestamps" (:915-916) is replaced by the host
     * probe's own change token — the recommended token is dev+ino+size+
     * mtime_ns, which catches renames-in-place and same-named rewrites a
     * coarse-mtime check would miss.
     */
    public const WATCH_POLL_SECONDS = 0.01;

    /**
     * SA-P1 (audit 013): the separator between the status core and the
     * key-hint tail. Three cells — a real gap, not a word boundary, so the
     * tail can be dropped without the core reading cramped.
     */
    private const STATUS_GAP = '   ';

    /**
     * SA-P1 (audit 013): column floor at or above which the status line
     * keeps its U+2190/U+2192 arrows. Those arrows — like the U+00B7
     * separators — are East-Asian-Ambiguous: sugar-core Width counts each
     * as one cell, but a wide-ambiguous terminal renders it as two, so the
     * English line measured ~76 cells here can reach 82 rendered. Below the
     * floor the hint tail swaps to pure-ASCII arrows (width 1 on every
     * honest terminal) before any abbreviation rung is taken — the
     * advertised 82+ "best at" width from the README.
     */
    private const STATUS_UNICODE_FLOOR = 82;

    /**
     * The six seam parameters — `$now`, `$anchor`, `$lapsCarried`, `$watch`,
     * `$elapsedCarried`, `$epochBasePos` — are internal wiring (test clock,
     * schedule anchoring, lap carry across a reload, watch rebind,
     * played-time carry across a re-anchor, the live epoch's establishment
     * position), not caller vocabulary: hosts build Players via
     * {@see self::start()} and the fluent setters, which thread the seams
     * themselves. They stay public in the signature deliberately — promoted
     * readonly state beats a parallel private-property ceremony — and the
     * reload paths carry them via mutate().
     *
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
     * @param ?int $loops E-02 total playthroughs allowed: null (default) plays
     *        forever — the unauthored-GIF policy of browsers and chicle, and
     *        the shape {@see Decoder::loopCount()} answers for a stream with
     *        no NETSCAPE extension; N >= 1 plays exactly N times and then
     *        quits on the tick that completes the Nth lap (libnsgif
     *        animation_complete semantics, namzug16/libnsgif gif.c:1854).
     * @param int $lapsCarried E-02 playthroughs already completed before the
     *        one the schedule is inside. Derived state — like `$anchor` it is
     *        only ever moved by {@see advanceSchedule()}: each automatic wrap
     *        of the schedule counts one lap, a manual ←/→ step never does
     *        (user scrubbing is not a playthrough), and a frame-stream
     *        replacement resets it with the index.
     * @param ?\Closure():?array{frames: list<Frame>, loops: ?int} $watch
     *        E-10 watch seam: re-runs every {@see WATCH_POLL_SECONDS} while
     *        the Player lives; returning null means "nothing changed", and
     *        returning a frame list means "reload onto it" (the probe has
     *        already read and decoded the changed file — the Player only
     *        rebinds state). The closure must be cheap: it runs inside the
     *        event loop on every poll.
     * @param float $elapsedCarried SA-P6 (audit 008): play time already carried
     *        across every schedule re-anchor — pause/resume, manual ←/→, the
     *        early-fire force-advance, withFixedFps rebases — so
     *        {@see withMaxSeconds()} bounds TOTAL play time as documented:
     *        periodic interaction cannot launder the bound. Paused wall time
     *        never accrues (the pause freezes the played total into this carry
     *        and drops the epoch, so the resume re-anchor folds nothing for
     *        the paused span), and a frame-stream reload is not a budget reset
     *        either. Derived state — threaded by mutate(), like $anchor.
     * @param ?float $epochBasePos SA-P6 (audit 008): the schedule position
     *        (C[k]) the current play epoch was established at. `now - $anchor`
     *        is the schedule position, not elapsed play time — a re-anchor
     *        hands the shown frame a fresh full window that sits `C[k]` into
     *        the schedule, so the real play time of the live segment is
     *        `(now - $anchor) - $epochBasePos`. Null whenever no epoch is live
     *        (initial, paused, post-reload); derived, threaded by mutate().
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
        public readonly ?int $loops = null,
        private readonly int $lapsCarried = 0,
        private readonly ?\Closure $watch = null,
        // SA-P6 (audit 008): play time carried across schedule re-anchors —
        // see the @param note above; derived state, threaded by mutate().
        private readonly float $elapsedCarried = 0.0,
        // SA-P6 (audit 008): the schedule position (C[k]) the live epoch was
        // established at; (now - anchor) - basePos is the REAL play time of
        // the live segment. Derived state, threaded by mutate().
        private readonly ?float $epochBasePos = null,
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
        $schedule = ($this->frames === [] || $this->paused)
            ? null
            : Cmd::tick($this->framePeriod($this->index), static fn (): Msg => new TickMsg());
        if ($this->watch === null) {
            return $schedule;
        }

        return $schedule === null ? $this->armWatch() : Cmd::batch($schedule, $this->armWatch());
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
                    // SA-P6 (audit 008): pausing freezes the played total
                    // into the carry and drops the epoch — everything the
                    // schedule accrued while playing survives the re-anchor,
                    // and everything after this instant is paused wall time,
                    // which the resume re-anchor folds nothing for.
                    return [$this->mutate([
                        'paused'         => true,
                        'elapsedCarried' => $this->playSeconds($this->clockNow()) ?? 0.0,
                        'anchor'         => null,
                        'epochBasePos'   => null,
                    ]), null];
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
            // wall-clock timer: no timer is armed, so a stalled loop cannot
            // overshoot silently either. SA-P6 (audit 008): the position is
            // the TOTAL played time — carried segments re-anchored away plus
            // the current one — so pausing and stepping periodically no
            // longer launder the bound; time spent paused never accrues
            // (the pause froze the carry at the pause instant).
            $played = $this->playSeconds($now);
            if ($this->maxSeconds !== null && $played !== null && $played >= $this->maxSeconds) {
                return [$this, Cmd::quit()];
            }
            return $this->advanceSchedule($now);
        }
        if ($msg instanceof WatchMsg) {
            // E-10: the poll runs while paused too — a changed file while
            // paused shows frame 0 of the NEW file on the still-paused
            // screen, which is what a watching user came here to see.
            return $this->onWatch();
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
        // SA-P2 (audit 034): no renderer yet means no WindowSizeMsg has ever
        // landed — the pre-fix fallback rendered the frame at its NATIVE
        // height into a terminal that cannot show it, so the first real size
        // message rearranged the whole screen (finding 019's layout jump).
        // Seed the classic conservative box instead: 80 columns, 24 rows
        // minus the status line. Hosts with true adaptivity pass a renderer
        // (Renderer::withAdaptiveSize(reservedRows: 1)) or receive the
        // WindowSizeMsg itself; either way this default only shrinks, never
        // outgrows, a real terminal.
        $renderer = $this->renderer ?? Renderer::withConstraints(23, 80);
        $budget   = $renderer->cols();
        if ($this->frames === []) {
            // SA-P1 (audit 020): the empty state rides the same Lang voice
            // and the same clamp, composed WITH its quit-hint tail — space
            // and step do nothing without frames, so only the way out is
            // advertised.
            return $this->fitTail(
                Lang::t('player.no_frames'),
                Lang::t('player.quit_hint'),
                $budget,
            ) . "\n";
        }
        $frame = $this->frames[$this->index];
        $pic   = $renderer->renderFrame($frame, $this->preset);

        return $pic . "\n" . $this->statusLine($budget) . "\n";
    }

    /**
     * SA-P1 (audits 009/013): the one-line status overlay — counter,
     * preset, playback state, key hints — built from Lang keys and clamped
     * against the renderer's column budget. Narrow terminals take
     * progressive abbreviation, not mid-word cuts: at or above the unicode
     * floor the full arrowed hint; then the ASCII-arrow hint; then the
     * hint tail dropped entirely; truncation only as the last resort.
     *
     * @param ?int $budget columns available, null = unconstrained host.
     */
    private function statusLine(?int $budget): string
    {
        $core = Lang::t('player.status', [
            'current' => $this->index + 1,
            'total'   => count($this->frames),
            // renderFrame treats everything that is not the density preset
            // as solid, so naming the non-density value "solid" tells the
            // truth of what is on screen even for a host that stored an
            // arbitrary string before SA-R1's enum lands.
            'preset'  => Lang::t($this->preset === Renderer::PRESET_DENSITY ? 'player.preset.density' : 'player.preset.solid'),
            'state'   => Lang::t($this->paused ? 'player.paused' : 'player.playing'),
        ]);
        $unicode = $core . self::STATUS_GAP . Lang::t('player.hint', ['arrows' => '←/→']);
        if ($budget === null || ($budget >= self::STATUS_UNICODE_FLOOR && Width::string($unicode) <= $budget)) {
            return $unicode;
        }
        $ascii = $core . self::STATUS_GAP . Lang::t('player.hint', ['arrows' => '<->']);
        if (Width::string($ascii) <= $budget) {
            return $ascii;
        }
        if (Width::string($core) <= $budget) {
            return $core;
        }

        return Width::truncate($core, $budget);
    }

    /**
     * SA-P1 (audit 020): head plus an optional tail, dropped or truncated
     * to the budget the same progressive way the status line is built.
     *
     * @param ?int $budget columns available, null = unconstrained host.
     */
    private function fitTail(string $head, string $tail, ?int $budget): string
    {
        $full = $head . self::STATUS_GAP . $tail;
        if ($budget === null || Width::string($full) <= $budget) {
            return $full;
        }
        if (Width::string($head) <= $budget) {
            return $head;
        }

        return Width::truncate($head, $budget);
    }

    /**
     * E-09: override the per-frame GIF delays with a uniform cadence of
     * $fps frames per second on the same absolute schedule. Immutable.
     * Re-anchors the schedule to the currently shown frame so a mid-play
     * switch never triggers an instant catch-up jump. Rates above
     * {@see self::MAX_FPS} clamp to that advertised ceiling (chicle's
     * CHICLE_ANIM_FPS_MAX): past the MIN_TICK_CS arm floor a faster request
     * is indistinguishable from the ceiling, so the ceiling is made real
     * rather than left a doc promise.
     */
    public function withFixedFps(float $fps): self
    {
        if (!is_finite($fps) || $fps <= 0.0) {
            throw new \InvalidArgumentException(
                'sugar-animation: Player::withFixedFps() requires a positive finite fps, got ' . var_export($fps, true),
            );
        }
        if ($fps > self::MAX_FPS) {
            $fps = self::MAX_FPS;
        }
        $next = $this->mutate(['fixedFps' => $fps]);
        if ($next->anchor === null) {
            return $next;
        }
        return $next->anchored($next->index, $next->clockNow());
    }

    /**
     * E-09: stop playback once the schedule position (total play time; time
     * spent paused never accrues) reaches $seconds. SA-P6 (audit 008) makes
     * the "total" literal: every re-anchor — pause/resume, manual step, the
     * early-fire force-advance, a cadence swap — folds its played segment
     * into the elapsed carry the check compares (playSeconds()), so the
     * bound can no longer be laundered by interacting with it. The bound
     * lives in the model and is checked on each tick — no wall-clock timer
     * is armed for it, so a stalled loop cannot overshoot silently either.
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

    /**
     * E-02: cap playback at $plays total playthroughs (null = forever, the
     * default). The stop happens INSIDE the schedule — on the tick whose
     * position crosses the final lap boundary the Player returns
     * {@see Cmd::quit()} while still displaying the last shown frame — so no
     * wall-clock timer is armed for the bound (same E-09 law as
     * {@see withMaxSeconds()}): a stalled loop cannot overshoot silently.
     */
    public function withLoops(?int $plays): self
    {
        if ($plays !== null && $plays < 1) {
            throw new \InvalidArgumentException(
                'sugar-animation: Player::withLoops() requires null or a play count >= 1, got ' . $plays,
            );
        }

        return $this->mutate(['loops' => $plays]);
    }

    /**
     * E-02: play the frame stream exactly once and quit. This is the honest
     * counterpart of libnsgif's remark that "the only way to play the
     * animation once is not to have this extension at all" (namzug16/libnsgif gif.c:862-871):
     * because an unauthored stream reports infinite from
     * {@see Decoder::loopCount()}, a host that wants one pass must ask the
     * Player for it, not read it off the bytes.
     */
    public function playOnce(): self
    {
        return $this->withLoops(1);
    }

    /**
     * E-10: attach a watch probe. The closure is re-run every
     * {@see WATCH_POLL_SECONDS} (see the constant's docblock for the chicle
     * parity and the disclosed deviations); null = "no change", a frame
     * list = "reload onto it now". Returns a new Player — the seam is
     * state, not mutation.
     *
     * @param \Closure():?array{frames: list<Frame>, loops: ?int} $probe
     */
    public function withWatch(\Closure $probe): self
    {
        return $this->mutate(['watch' => $probe]);
    }

    /**
     * E-10: rebind the playback to a different frame stream IN PLACE — the
     * reload half of the watch cycle, exposed publicly so hosts can swap
     * frames without rebuilding the Player. The position restarts (index 0,
     * carried laps 0, epoch unanchored so the first tick of the new stream
     * anchors by the E-03 next-arrival law); playback policy — preset,
     * renderer, cadence, duration bound, loop bound, and the watch seam
     * itself — survives. A caller that wants the loop bound re-derived from
     * the new bytes chains {@see withLoops()} onto the result. The asymmetry
     * is the contract: the manual swap keeps the caller's bound because only
     * the caller knows its intent, while the WatchMsg reload in
     * {@see self::update()} re-derives the bound from the fresh bytes on
     * purpose, so a file whose loop extension changed mid-session gets its
     * new bound without the caller lifting a finger (pinned by
     * PlayerWatchTest). SA-P6 (audit 008): the duration bound rides this
     * survive-list as a carried total too — the live segment folds into the
     * elapsed carry before the epoch drops, so swapping streams mid-run is
     * not a budget reset either.
     *
     * @param list<Frame> $frames
     */
    public function withFrames(array $frames): self
    {
        // SA-P6 (audit 008): fold the live segment first — a position
        // restart must not launder the maxSeconds bound.
        $carried = $this->playSeconds($this->clockNow()) ?? 0.0;

        return $this->mutate([
            'frames' => array_values($frames),
            'index' => 0,
            'lapsCarried' => 0,
            'anchor' => null,
            'elapsedCarried' => $carried,
            'epochBasePos' => null,
        ]);
    }

    public function subscriptions(): ?\SugarCraft\Core\Subscriptions
    {
        return null;
    }

    /**
     * E-09: the duration bound measured so far ON THE CURRENT SCHEDULE
     * (null before the schedule is anchored, i.e. before the first tick) —
     * the raw epoch position the E-03 cadence pins observe. The maxSeconds
     * BOUND proper measures total play time across re-anchors — see
     * {@see self::playSeconds()}; SA-P6 (audit 008) fixed that bound to
     * carry, not this accessor.
     */
    public function playedSeconds(): ?float
    {
        if ($this->anchor === null) {
            return null;
        }

        return max(0.0, $this->clockNow() - $this->anchor);
    }

    /**
     * SA-P6 (audit 008): the total-play-time reading at $now — carried
     * segments folded at every re-anchor plus the live epoch segment; null
     * for a schedule that never anchored and carries nothing. This is what
     * {@see withMaxSeconds()} bounds: paused wall time never accrues (the
     * pause froze the carry at the pause instant), and periodic pausing or
     * stepping no longer launders the bound.
     */
    private function playSeconds(float $now): ?float
    {
        if ($this->anchor === null) {
            return $this->elapsedCarried > 0.0 ? $this->elapsedCarried : null;
        }

        return $this->elapsedCarried + max(0.0, ($now - $this->anchor) - ($this->epochBasePos ?? 0.0));
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
     *
     * SA-P6 (audit 008): a re-anchor moves the epoch, so the segment it
     * replaces is folded into the elapsed carry first — the schedule
     * position restarts, the total play time the bound measures does not.
     * The fold is exactly what makes "cycle-monotonic while unpaused" true
     * across re-anchors again: manual steps and the early-fire force-advance
     * no longer zero out progress toward maxSeconds.
     */
    private function anchored(int $showing, float $now): self
    {
        $cumulative = $this->cumulative();
        // SA-P6 (audit 008): fold the REAL play time of the epoch being
        // replaced — its schedule position (now - anchor) minus the
        // position the epoch was established at. A re-anchor gives the
        // shown frame a fresh full window; that virtual head-start must
        // not count as play.
        $carried = $this->elapsedCarried + ($this->anchor === null
            ? 0.0
            : max(0.0, ($now - $this->anchor) - ($this->epochBasePos ?? 0.0)));

        return $this->mutate([
            'index'          => $showing,
            'anchor'         => $now - $cumulative[$showing],
            'elapsedCarried' => $carried,
            'epochBasePos'   => $cumulative[$showing],
        ]);
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
     * SA-P6 (audit 059): per-instance prefix-sum memo. A Player is an
     * immutable value — frames and cadence cannot move under a live
     * instance — so cumulative() is a pure function of $this and pays at
     * most once per instance (the force-advance leg reads it from both
     * advanceSchedule and anchored). A WeakMap lets each table die with its
     * Player, so the memo never pins a superseded schedule past its
     * lifetime; per-tick garbage on the repaint path is what -059 named.
     *
     * @var \WeakMap<self, list<float>>|null
     */
    private static ?\WeakMap $scheduleTables = null;

    /**
     * Prefix sums C[0..n] of the frame periods; C[n] is one full cycle.
     *
     * @return list<float>
     */
    private function cumulative(): array
    {
        self::$scheduleTables ??= new \WeakMap();
        $table = self::$scheduleTables[$this] ?? null;
        if ($table !== null) {
            return $table;
        }

        $c = [0.0];
        $n = count($this->frames);
        for ($i = 0; $i < $n; $i++) {
            $c[] = $c[$i] + $this->framePeriod($i);
        }
        self::$scheduleTables[$this] = $c;

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
        $unanchored = $this->anchor === null;
        $anchor = $unanchored ? $now - $cumulative[($this->index + 1) % $n] : $this->anchor;
        // The first tick of an un-anchored Player lands "exactly now" by
        // contract, so its schedule position IS the table offset — reading it
        // from the table instead of round-tripping $now - ($now - offset)
        // takes the clock out of the expression. The subtraction misrounds by
        // up to half an ulp of $now, which scales with uptime: on a freshly
        // booted CI runner (~1e1-1e2 s of hrtime) the shifted position both
        // flips the branch lottery below and leaks ~1e-14 into the arm, so a
        // pinned 30cs delay arrived as 0.2999999999999949 (E-04 parity pin,
        // red on one 8.3 leg, green on an 8.4 twin of the same commit).
        $pos = $unanchored ? $cumulative[($this->index + 1) % $n] : max(0.0, $now - $anchor);
        $cycle = (int) floor($pos / $total);
        $within = $pos - $cycle * $total;

        // E-02 schedule-driven stop: while the epoch is stable, `cycle` IS the
        // count of laps completed since it, so a lap bound is checked straight
        // off it — before any landing work, keeping the last shown frame on
        // screen exactly as libnsgif's animation_complete stops at the
        // boundary (namzug16/libnsgif gif.c:1854).
        if ($this->loops !== null && $this->lapsCarried + $cycle >= $this->loops) {
            return [$this, Cmd::quit()];
        }

        // SA-P6 (audit 059): binary search for the landing frame — the table
        // is strictly increasing (every period >= MIN_TICK_CS), so the
        // largest k with C[k] <= within resolves in log2(n) probes; the
        // result is identical to the old linear reverse scan, frame for
        // frame (the catch-up corpus re-proved it unmoved at landing).
        $lo = 0;
        $hi = $n - 1;
        while ($lo < $hi) {
            $mid = intdiv($lo + $hi, 2) + 1;
            if ($cumulative[$mid] <= $within) {
                $lo = $mid;
            } else {
                $hi = $mid - 1;
            }
        }
        $landing = $lo;
        if ($landing !== $this->index) {
            // Schedule-driven arrival: catch-up may have skipped frames.
            $changes = ['index' => $landing];
            if ($unanchored) {
                $changes['anchor'] = $anchor;
                // SA-P6 (audit 008): the first tick establishes the SCHEDULE
                // ORIGIN, not a re-anchor — the epoch starts at position 0 and
                // frame 0's window counts as real play time (this is exactly
                // what the E-09 quit-on-tie bound measures: total play = raw
                // schedule position on a clean stream). So the live segment's
                // real play time is the full (now - anchor): basePos 0.
                $changes['epochBasePos'] = 0.0;
            }
            $next = $this->mutate($changes);

            // Arm from schedule-relative values: round-tripping through the
            // absolute anchor would cancel away the nanoseconds a real
            // hrtime() carries (ulp at ~1e9 s exceeds a centisecond). On the
            // un-anchored first tick the landing frame is due exactly now, so
            // its next deadline IS the landing frame's own period — taken
            // straight from the schedule instead of the prefix-sum difference
            // (C[2] - C[1] is not framePeriod(1) bit-for-bit in binary float,
            // e.g. 0.31 - 0.0100000000000051 ≠ 0.3).
            if ($unanchored) {
                return [$next, $this->armFromRel($this->framePeriod($landing))];
            }
            $nextDueRel = $cycle * $total + ($landing + 1 < $n ? $cumulative[$landing + 1] : $total);

            return [$next, $this->armFromRel($nextDueRel - $pos)];
        }

        // Early fire: force-advance one frame and make its hold start now.
        $landing = ($this->index + 1) % $n;
        // E-02: the re-anchor below resets the epoch, which zeroes `cycle` —
        // so if this forced advance itself steps over the lap boundary
        // (index was the final frame) the lap it completed must be carried
        // explicitly, or the epoch rebasing would erase it from the count.
        $carried = $this->lapsCarried + ($this->index === $n - 1 ? 1 : 0);
        if ($this->loops !== null && $carried >= $this->loops) {
            return [$this, Cmd::quit()];
        }

        return [$this->anchored($landing, $now)->mutate(['lapsCarried' => $carried]), $this->armFromRel($this->framePeriod($landing))];
    }

    /**
     * E-10: one watch poll. A probe failure or an empty reload never swaps
     * the display (chicle's `if (media_loader)` guard: a file mid-rewrite
     * keeps playing the last good stream rather than flashing "no frames");
     * the chain re-arms in every outcome except an un-armed Player, so the
     * watch outlives transient corruption exactly as the CLI loop did.
     *
     * @return array{Player, ?\Closure}
     */
    private function onWatch(): array
    {
        $probe = $this->watch;
        if ($probe === null) {
            return [$this, null];
        }
        // SA-P4 (audit 011): a throwing probe must not brick the pump — the
        // display stays, the chain re-arms, and the next poll gets its
        // chance (the same shape as the null "nothing changed" answer).
        try {
            $reload = $probe();
        } catch (\Throwable) {
            return [$this, $this->armWatch()];
        }
        if ($reload === null || $reload['frames'] === []) {
            return [$this, $this->armWatch()];
        }
        // SA-P4 (audit 011): a non-positive loop count from a host probe is
        // read as forever, not handed raw to the withLoops() door (which
        // throws) — bricking a watch reload over an ambiguous 0 would be the
        // second bug. Netscape's own semantics spell 0 = forever; negatives
        // are host confusion and get the same lenient reading.
        $plays = $reload['loops'] !== null && $reload['loops'] < 1 ? null : $reload['loops'];
        // SA-P4 (audit 018): unchanged-poll coalescing. A probe that re-arms
        // with the IDENTICAL stream (element-identity frames from a memoised
        // host probe, same loop bound) re-arms WITHOUT a rebind — index,
        // epoch and display carry on. Only a real change restarts playback.
        // chicle re-decodes every 10 ms and rebinds every time; a host that
        // wants the coalescing memoises its probe (the identity test is
        // reference-honest by design, not content-equality churn).
        if ($reload['frames'] === $this->frames && $plays === $this->loops) {
            return [$this, $this->armWatch()];
        }
        $next = $this->withFrames($reload['frames'])->withLoops($plays);

        return [$next, $this->armWatch()];
    }

    private function armWatch(): \Closure
    {
        return Cmd::tick(self::WATCH_POLL_SECONDS, static fn (): Msg => new WatchMsg());
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
