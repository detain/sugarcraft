<?php

declare(strict_types=1);

namespace SugarCraft\Zone;

use SugarCraft\Core\Concerns\Mutable;
use SugarCraft\Core\Msg;
use SugarCraft\Core\Msg\MouseMsg;
use SugarCraft\Zone\Msg\DoubleClickMsg;
use SugarCraft\Zone\Msg\TripleClickMsg;

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
 */
final class ClickCounter
{
    use Mutable;
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
