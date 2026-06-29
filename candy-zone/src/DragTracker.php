<?php

declare(strict_types=1);

namespace SugarCraft\Zone;

use SugarCraft\Core\Msg;
use SugarCraft\Core\Msg\MouseMsg;
use SugarCraft\Zone\Msg\ZoneDragEndMsg;
use SugarCraft\Zone\Msg\ZoneDragMoveMsg;
use SugarCraft\Zone\Msg\ZoneDragStartMsg;

/**
 * Tracks press → move → release drag sequences within and across zones.
 *
 * Wraps a {@see Manager}. The manager must have already run {@see Manager::scan()}
 * to populate its zone registry before the tracker can detect drags.
 *
 * Usage:
 *
 *   $tracker = new DragTracker($manager);
 *   [$tracker, $msg] = $tracker->update($mouseMsg);
 *   if ($msg instanceof ZoneDragStartMsg) { ... }
 *
 * Mirrors bubblezone's `NewZoneDragTracker`.
 */
final class DragTracker
{
    /** @var ?string Zone id where the current drag started. */
    private ?string $originZoneId;

    /** @var ?string Zone id the mouse is currently over during the drag. */
    private ?string $currentZoneId;

    /**
     * @param Manager $manager Zone manager that has already run scan().
     * @param ?string $originZoneId Zone id where the drag started, null when not dragging.
     * @param ?string $currentZoneId Zone id mouse is currently in, null when not dragging.
     */
    public function __construct(
        public readonly Manager $manager,
        ?string $originZoneId = null,
        ?string $currentZoneId = null,
    ) {
        $this->originZoneId = $originZoneId;
        $this->currentZoneId = $currentZoneId;
    }

    /**
     * Read-only accessor for the zone the drag started from.
     * Returns null when no drag is in progress.
     */
    public function originZoneId(): ?string
    {
        return $this->originZoneId;
    }

    /**
     * Read-only accessor for the zone the mouse is currently in.
     * Returns null when cursor is not inside any tracked zone.
     */
    public function currentZoneId(): ?string
    {
        return $this->currentZoneId;
    }

    /**
     * Resolve the origin zone from the manager's registry.
     * Returns null when not dragging or when the zone was removed.
     */
    public function originZone(): ?Zone
    {
        return $this->originZoneId !== null
            ? $this->manager->get($this->originZoneId)
            : null;
    }

    /**
     * Resolve the current zone from the manager's registry.
     * Returns null when cursor is not inside any tracked zone.
     */
    public function currentZone(): ?Zone
    {
        return $this->currentZoneId !== null
            ? $this->manager->get($this->currentZoneId)
            : null;
    }

    /**
     * Process a mouse event and detect drag state transitions.
     *
     * - Press inside a zone → start drag, emit {@see ZoneDragStartMsg}.
     * - Motion while dragging → emit {@see ZoneDragMoveMsg}.
     * - Release while dragging → end drag, emit {@see ZoneDragEndMsg}.
     *
     * When the cursor crosses a zone boundary during a drag, subsequent
     * moves emit the move message with the updated current zone while
     * the origin zone stays fixed.
     *
     * Moving directly from zone A to zone B while dragging emits the
     * move for A first; call update() again to receive the move for B.
     *
     * @return array{0: self, 1: ?Msg}
     */
    public function update(MouseMsg $msg): array
    {
        $hit = $this->manager->anyInBounds($msg);

        // --- Release: end any in-progress drag ---
        if ($msg->action === \SugarCraft\Core\MouseAction::Release) {
            if ($this->originZoneId !== null) {
                $origin = $this->manager->get($this->originZoneId);
                $current = $this->currentZoneId !== null
                    ? $this->manager->get($this->currentZoneId)
                    : $origin;
                if ($origin !== null && $current !== null) {
                    return [$this->mutate(null, null), new ZoneDragEndMsg($origin, $current)];
                }
            }
            return [$this, null];
        }

        // --- Press: start a new drag if inside a zone ---
        if ($msg->action === \SugarCraft\Core\MouseAction::Press) {
            if ($hit !== null) {
                return [$this->mutate($hit->id, $hit->id), new ZoneDragStartMsg($hit, $hit)];
            }
            return [$this, null];
        }

        // --- Motion: track current zone during an active drag ---
        if ($msg->action === \SugarCraft\Core\MouseAction::Motion) {
            if ($this->originZoneId === null) {
                // Motion with no active drag — no-op.
                return [$this, null];
            }

            if ($hit === null) {
                // Dragged outside any zone — keep tracking with null current.
                if ($this->currentZoneId !== null) {
                    return [$this->mutate($this->originZoneId, null), null];
                }
                return [$this, null];
            }

            // Same zone as current — no transition.
            if ($hit->id === $this->currentZoneId) {
                return [$this, null];
            }

            // Zone boundary crossing during drag.
            $origin = $this->manager->get($this->originZoneId);
            if ($origin !== null) {
                return [$this->mutate($this->originZoneId, $hit->id), new ZoneDragMoveMsg($origin, $hit)];
            }
        }

        return [$this, null];
    }

    /**
     * Return a new tracker with a different manager.
     */
    public function withManager(Manager $manager): self
    {
        return new self($manager, $this->originZoneId, $this->currentZoneId);
    }

    /**
     * Return a new tracker with specific zone ids.
     * Intended for state snapshot/restore.
     */
    public function withZoneIds(?string $origin, ?string $current): self
    {
        return $this->mutate($origin, $current);
    }

    /**
     * Clone with optional overrides.
     */
    private function mutate(?string $originZoneId, ?string $currentZoneId): self
    {
        return new self($this->manager, $originZoneId, $currentZoneId);
    }
}
