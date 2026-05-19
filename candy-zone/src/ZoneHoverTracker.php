<?php

declare(strict_types=1);

namespace SugarCraft\Zone;

use SugarCraft\Core\Msg;
use SugarCraft\Core\Msg\MouseMsg;
use SugarCraft\Zone\Msg\ZoneEnterMsg;
use SugarCraft\Zone\Msg\ZoneExitMsg;

/**
 * Tracks which zone the cursor is in across mouse-move events and
 * emits enter/exit messages on boundary crossings.
 *
 * Wraps a {@see Manager}. The manager must have already run {@see Manager::scan()}
 * to populate its zone registry before the tracker can detect hovers.
 *
 * Usage:
 *
 *   $tracker = new ZoneHoverTracker($manager);
 *   [$tracker, $msg] = $tracker->update($mouseMsg);
 *   if ($msg instanceof ZoneEnterMsg) { ... }
 *
 * Mirrors bubblezone's `NewZoneHoverTracker`.
 */
final class ZoneHoverTracker
{
    /** @var ?string Zone id currently hovered, null when cursor is in no zone. */
    private ?string $currentZoneId;

    /**
     * @param Manager $manager Zone manager that has already run scan().
     * @param ?string $currentZoneId Currently hovered zone id, null initially.
     */
    public function __construct(
        public readonly Manager $manager,
        ?string $currentZoneId = null,
    ) {
        $this->currentZoneId = $currentZoneId;
    }

    /**
     * Read-only accessor for the currently hovered zone id.
     * Returns null when the cursor is not inside any tracked zone.
     */
    public function currentZoneId(): ?string
    {
        return $this->currentZoneId;
    }

    /**
     * Resolve the currently hovered zone from the manager's registry.
     * Returns null when the cursor is not inside any zone.
     */
    public function currentZone(): ?Zone
    {
        return $this->currentZoneId !== null
            ? $this->manager->get($this->currentZoneId)
            : null;
    }

    /**
     * Process a mouse event and detect zone boundary crossings.
     *
     * When the cursor moves into a zone from outside (or from a different
     * zone), returns a {@see ZoneEnterMsg}. When the cursor leaves a zone
     * entirely, returns a {@see ZoneExitMsg}. When the cursor stays
     * within the same zone, returns null.
     *
     * Moving directly from zone A to zone B emits the exit for A first;
     * call update() again to receive the enter for B.
     *
     * @return array{0: self, 1: ?Msg} [updated tracker, optional hover msg]
     */
    public function update(MouseMsg $msg): array
    {
        $hit = $this->manager->anyInBounds($msg);

        if ($hit === null) {
            // Cursor is not in any zone.
            if ($this->currentZoneId !== null) {
                $exited = $this->manager->get($this->currentZoneId);
                if ($exited !== null) {
                    return [$this->mutate(null), new ZoneExitMsg($exited)];
                }
            }
            return [$this, null];
        }

        // Cursor is in a zone.
        if ($hit->id === $this->currentZoneId) {
            // Still inside the same zone — no transition.
            return [$this, null];
        }

        // Zone boundary crossing detected.
        // If there was a previous zone, emit its exit first.
        // The caller calls update() again to receive the enter for the new zone.
        if ($this->currentZoneId !== null) {
            $exited = $this->manager->get($this->currentZoneId);
            if ($exited !== null) {
                // Clear currentZoneId so the next update sees a null-to-new
                // transition (enter) rather than an same-id no-op.
                return [$this->mutate(null), new ZoneExitMsg($exited)];
            }
        }

        // Entering a new zone.
        return [$this->mutate($hit->id), new ZoneEnterMsg($hit)];
    }

    /**
     * Return a new tracker with a different manager (useful when composing
     * multiple zone-aware components each with their own prefixed manager).
     */
    public function withManager(Manager $manager): self
    {
        return new self($manager, $this->currentZoneId);
    }

    /**
     * Return a new tracker with a specific current zone id.
     * Intended for state snapshot/restore rather than direct use.
     */
    public function withCurrentZoneId(string $id): self
    {
        return $this->mutate($id);
    }

    /**
     * Clone with optional overrides.
     *
     * @param ?string $currentZoneId Override for the current zone id.
     */
    private function mutate(?string $currentZoneId): self
    {
        return new self($this->manager, $currentZoneId);
    }
}
