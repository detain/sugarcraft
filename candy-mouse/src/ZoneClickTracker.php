<?php

declare(strict_types=1);

namespace SugarCraft\Mouse;

/**
 * Deduplicate MouseDown/Up pairs so callers receive a single ClickResult
 * per logical click — suppressing spurious extra Press events fired during
 * drag and discarding mismatched Press+Release on different zones.
 *
 * State machine per button:
 *   idle       → Press  → waiting (store zone+button)
 *   waiting    → Press  → waiting (replace pending with new zone — last press wins)
 *   waiting    → Release on same zone+button → emit ClickResult, idle
 *   waiting    → Release on different zone   → clear state, idle
 *   any state  → Drag   → ignored
 *   any state  → Scroll → pass-through (no click)
 *
 * There are two ways to supply the press zone:
 *   1. Preferred (one-call):  track($event, $scanner->hit($event->x, $event->y))
 *   2. Legacy (two-call):   track($event);  setPressZone($zone, $button)
 *
 * Mirrors bubblezone issue #10 improvement (zone-level click dedup).
 */
final class ZoneClickTracker
{
    /**
     * @var array<int, array{zone:Zone|null, button:int}> button => pending state
     */
    private array $pending = [];

    /**
     * Feed a mouse event and receive a ClickResult if the event completes
     * a clean Press+Release pair on the same zone.
     *
     * @param MouseEvent     $event   The mouse event to process.
     * @param Zone|null      $hitZone Pre-resolved zone from scanner->hit().
     *                                When provided, the press/release pair is
     *                                self-contained and setPressZone() is
     *                                not needed.  Pass null to use the legacy
     *                                two-call pattern.
     *
     * @return ClickResult|null null when no click completes this tick.
     */
    public function track(MouseEvent $event, ?Zone $hitZone = null): ?ClickResult
    {
        $btn = $event->button;

        // Scroll is never a click — pass through as null.
        if ($event->action === MouseAction::Scroll) {
            return null;
        }

        // Drag during a pending press — suppress, keep waiting.
        if ($event->action === MouseAction::Drag) {
            return null;
        }

        if ($event->action === MouseAction::Press) {
            // Second press on the same button replaces the pending zone —
            // last press wins.  This matches the documented "replace" semantics.
            $this->pending[$btn] = ['zone' => $hitZone, 'button' => $btn];
            return null;
        }

        if ($event->action === MouseAction::Release) {
            if (!isset($this->pending[$btn])) {
                // Release without a preceding press — ignore.
                return null;
            }
            $pending = $this->pending[$btn];

            // If no zone was recorded (Press hit nothing), ignore.
            if ($pending['zone'] === null) {
                unset($this->pending[$btn]);
                return null;
            }

            // Release on a different zone — return null but keep pending
            // so the next release on the correct zone can still emit.
            if (!$pending['zone']->inBounds($event)) {
                return null;
            }

            // Same zone — emit click and clear pending.
            unset($this->pending[$btn]);
            return new ClickResult($pending['zone'], $btn);
        }

        return null;
    }

    /**
     * Inject the zone that was hit at the time of the press event.
     * Call this immediately after track(Press) when you have the zone.
     */
    public function setPressZone(Zone $zone, int $button): void
    {
        if (isset($this->pending[$button])) {
            $this->pending[$button]['zone'] = $zone;
        }
    }
}
