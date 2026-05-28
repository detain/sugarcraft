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
 *   waiting    → Press  → waiting (new zone, ignore)
 *   waiting    → Release on same zone+button → emit ClickResult, idle
 *   waiting    → Release on different zone   → clear state, idle
 *   any state  → Drag   → ignored
 *   any state  → Scroll → pass-through (no click)
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
     * @return ClickResult|null null when no click completes this tick.
     */
    public function track(MouseEvent $event): ?ClickResult
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
            $this->pending[$btn] = ['zone' => null, 'button' => $btn];
            // We need the zone — caller should call scanner->hit() first
            // and pass the zone in. Store what we have; zone will be set
            // by the caller via withZone().
            return null;
        }

        if ($event->action === MouseAction::Release) {
            if (!isset($this->pending[$btn])) {
                // Release without a preceding press — ignore.
                return null;
            }
            $pending = $this->pending[$btn];
            unset($this->pending[$btn]);

            // If no zone was recorded (Press hit nothing), ignore.
            if ($pending['zone'] === null) {
                return null;
            }

            // Release on a different zone — clear and discard.
            if (!$pending['zone']->inBounds($event)) {
                return null;
            }

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
