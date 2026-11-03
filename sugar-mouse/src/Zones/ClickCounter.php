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
 * Mirrors bubblezone's `NewZoneClickTracker`.
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

    /** Timestamp (microseconds) of the last click. */
    private float $lastClickTime = 0.0;

    /** Zone id of the last click, used to detect same-zone rapid clicks. */
    private ?string $lastClickZoneId = null;

    /**
     * @param Manager $manager Zone manager that has already run scan().
     * @param int $clickIntervalMs Window in milliseconds within which successive
     *                              clicks count toward a double/triple (default 500ms).
     */
    public function __construct(
        public readonly Manager $manager,
        public readonly int $clickIntervalMs = 500,
    ) {}

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
            return [$this->mutate(['clickCount' => 0, 'lastClickTime' => 0.0, 'lastClickZoneId' => null]), null];
        }

        $now = microtime(true);

        // Detect zone change: starting a new streak.
        if ($hit->id !== $this->lastClickZoneId) {
            return [$this->mutate(['clickCount' => 1, 'lastClickTime' => $now, 'lastClickZoneId' => $hit->id]), null];
        }

        // Same zone — check if within interval.
        $elapsedMs = ($now - $this->lastClickTime) * 1000;

        if ($elapsedMs > $this->clickIntervalMs) {
            // Interval expired — restart streak.
            return [$this->mutate(['clickCount' => 1, 'lastClickTime' => $now, 'lastClickZoneId' => $hit->id]), null];
        }

        // Within interval — advance streak.
        $newCount = $this->clickCount + 1;

        if ($newCount === 3) {
            // Triple click.
            return [$this->mutate(['clickCount' => 3, 'lastClickTime' => $now, 'lastClickZoneId' => $hit->id]), new TripleClickMsg($hit)];
        }

        if ($newCount === 2) {
            // Double click.
            return [$this->mutate(['clickCount' => 2, 'lastClickTime' => $now, 'lastClickZoneId' => $hit->id]), new DoubleClickMsg($hit)];
        }

        // More than triple — just keep counting but only emit once per threshold.
        return [$this->mutate(['clickCount' => $newCount, 'lastClickTime' => $now, 'lastClickZoneId' => $hit->id]), null];
    }

    /**
     * Return a new counter with a different manager.
     */
    public function withManager(Manager $manager): self
    {
        return new self($manager, $this->clickIntervalMs);
    }

    /**
     * Clone with optional overrides via array-merge pattern.
     *
     * Note: ClickCounter's private mutable fields (clickCount, lastClickTime,
     * lastClickZoneId) are not constructor parameters — they are assigned
     * directly on the new instance after construction.  Public readonly
     * constructor properties (manager, clickIntervalMs) come from get_object_vars().
     */
    private function mutate(array $changes): static
    {
        $new = new self(
            manager: $this->manager,
            clickIntervalMs: $this->clickIntervalMs,
        );
        foreach ($changes as $k => $v) {
            $new->$k = $v;
        }
        return $new;
    }
}
