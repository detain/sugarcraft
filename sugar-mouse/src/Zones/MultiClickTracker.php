<?php

declare(strict_types=1);

namespace SugarCraft\Mouse\Zones;

use SugarCraft\Core\Msg;
use SugarCraft\Core\Msg\MouseMsg;
use SugarCraft\Core\Msg\MouseWheelMsg;
use SugarCraft\Mouse\Zones\Msg\DoubleClickMsg;
use SugarCraft\Mouse\Zones\Msg\TripleClickMsg;

/**
 * Tracks rapid successive clicks (double / triple) within a zone.
 *
 * Wraps a {@see Manager}. The manager must have already run {@see Manager::scan()}
 * to populate its zone registry before the tracker can detect clicks.
 *
 * Usage:
 *
 *   $counter = new ClickCounter($manager);
 *   [$counter, $msg] = $counter->update($mouseMsg);
 *   if ($msg instanceof DoubleClickMsg) { ... }
 *   if ($msg instanceof TripleClickMsg) { ... }
 *
 * Extends bubblezone — upstream ships no double/triple-click machinery
 * (its own gap table records click count as not built-in).
 *
 * dedup overlap with {@see \SugarCraft\Mouse\ZoneClickTracker} deferred: the
 * primitive's ZoneClickTracker does Press+Release single-click dedup, NOT the
 * interval-based double/triple streak counting this class implements.
 * Routing this counter's single-click detection through it would change
 * the emitted DoubleClickMsg/TripleClickMsg timing, so the delegation is
 * intentionally not done. This class hit-tests via {@see Manager}, whose
 * geometry already flows through the primitive SSOT.
 */
final class ClickCounter
{
    /** How many consecutive clicks have occurred in the current streak. */
    private int $clickCount = 0;

    /** Timestamp (seconds, float) of the last click. */
    private float $lastClickTime = 0.0;

    /** Zone id of the last click, used to detect same-zone rapid clicks. */
    private ?string $lastClickZoneId = null;

    /** Streak clock (see ctor); normalized so the property type is legal.
     *  null selects the monotonic default, never a wall-clock read. */
    private readonly ?\Closure $now;

    /**
     * @param Manager $manager Zone manager that has already run scan().
     * @param int $clickIntervalMs Window in milliseconds within which successive
     *                              clicks count toward a double/triple (default 500ms).
     *                              The window is a pointer-timing tolerance: apps
     *                              should expose it as a setting so users with tremor
     *                              or reduced dexterity can widen the streak (README,
     *                              Accessibility).
     * @param ?callable $now Clock returning "now" in seconds as a float.
     *                       Defaults to a monotonic clock
     *                       ({@see hrtime()}) when null. The seam lets
     *                       tests drive interval expiry deterministically
     *                       instead of sleeping (a real-sleep test is CI
     *                       noise waiting to happen under co-shard load).
     *                       The default is deliberately NOT wall-clock:
     *                       an NTP step during a streak could otherwise
     *                       jump the elapsed figure backwards (swallowing
     *                       the streak) or far forwards (coalescing two
     *                       streaks into one).
     */
    public function __construct(
        public readonly Manager $manager,
        public readonly int $clickIntervalMs = 500,
        ?callable $now = null,
    ) {
        // 'callable' is not a legal property type — normalize to Closure.
        $this->now = $now === null ? null : \Closure::fromCallable($now);
    }

    private function nowSeconds(): float
    {
        $clock = $this->now;
        return $clock !== null ? $clock() : hrtime(true) / 1e9;
    }

    /**
     * Read-only accessor for the current click streak count.
     * Returns 0 when no click streak is in progress.
     */
    public function clickCount(): int
    {
        return $this->clickCount;
    }

    /**
     * Process a mouse press event and detect double/triple click streaks.
     *
     * Only press events advance the streak; other mouse actions are no-ops.
     * Wheel events are excluded by TYPE, not by action: sugar-core's
     * InputReader currently stamps wheel reports with
     * {@see \SugarCraft\Core\MouseAction::Press}, so an action-only check
     * would let every wheel tick advance the streak (a scroll across a zone
     * would "double-click" it). The {@see MouseWheelMsg} gate is this layer's
     * defence; the root cause is the core seam (InputReader hardcodes Press).
     * When two presses occur in the same zone within the click interval,
     * a {@see DoubleClickMsg} is emitted on the second press, and a
     * {@see TripleClickMsg} is emitted on the third press. The streak
     * resets after the interval expires or when a press occurs in a
     * different zone.
     *
     * @return array{0: self, 1: ?Msg}
     */
    public function update(MouseMsg $msg): array
    {
        // Wheel reports are scrolls, not clicks — never advance the streak.
        // (Type gate, not action gate: core currently delivers wheel with
        // action Press — see the method docblock.)
        if ($msg instanceof MouseWheelMsg) {
            return [$this, null];
        }

        // Only presses advance the streak.
        if ($msg->action !== \SugarCraft\Core\MouseAction::Press) {
            return [$this, null];
        }

        $hit = $this->manager->anyInBounds($msg);

        // Click outside any zone — no streak possible.
        if ($hit === null) {
            return [$this->withState(0, 0.0, null), null];
        }

        $now = $this->nowSeconds();

        // Detect zone change: starting a new streak.
        if ($hit->id !== $this->lastClickZoneId) {
            return [$this->withState(1, $now, $hit->id), null];
        }

        // Same zone — check if within interval.
        $elapsedMs = ($now - $this->lastClickTime) * 1000;

        if ($elapsedMs > $this->clickIntervalMs) {
            // Interval expired — restart streak.
            return [$this->withState(1, $now, $hit->id), null];
        }

        // Within interval — advance streak.
        $newCount = $this->clickCount + 1;

        if ($newCount === 3) {
            // Triple click.
            return [$this->withState(3, $now, $hit->id), new TripleClickMsg($hit)];
        }

        if ($newCount === 2) {
            // Double click.
            return [$this->withState(2, $now, $hit->id), new DoubleClickMsg($hit)];
        }

        // More than triple — just keep counting but only emit once per threshold.
        return [$this->withState($newCount, $now, $hit->id), null];
    }

    /**
     * Return a new counter with a different manager, carrying the click
     * streak across (mirrors {@see ZoneHoverTracker::withManager()}):
     * rebinding the manager re-points hit-testing, it does not erase the
     * user's in-progress double-click.
     */
    public function withManager(Manager $manager): self
    {
        $new = new self($manager, $this->clickIntervalMs, $this->now);
        $new->clickCount      = $this->clickCount;
        $new->lastClickTime   = $this->lastClickTime;
        $new->lastClickZoneId = $this->lastClickZoneId;
        return $new;
    }

    /**
     * Clone with the streak state replaced. Named fields — the old
     * array/`$new->$k = $v` shape accepted any key at runtime (a typo
     * silently invented a property) and the docblock's "come from
     * get_object_vars()" claim was fiction: the readonly ctor properties
     * were set by the ctor, never by the loop.
     */
    private function withState(int $clickCount, float $lastClickTime, ?string $lastClickZoneId): self
    {
        $new = new self($this->manager, $this->clickIntervalMs, $this->now);
        $new->clickCount      = $clickCount;
        $new->lastClickTime   = $lastClickTime;
        $new->lastClickZoneId = $lastClickZoneId;
        return $new;
    }
}
