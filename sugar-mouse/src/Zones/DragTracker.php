<?php

declare(strict_types=1);

namespace SugarCraft\Mouse\Zones;

use SugarCraft\Core\MouseAction;
use SugarCraft\Core\MouseButton;
use SugarCraft\Core\Msg;
use SugarCraft\Core\Msg\MouseMsg;
use SugarCraft\Core\Msg\MouseWheelMsg;
use SugarCraft\Mouse\Zones\Msg\ZoneDragEndMsg;
use SugarCraft\Mouse\Zones\Msg\ZoneDragMoveMsg;
use SugarCraft\Mouse\Zones\Msg\ZoneDragStartMsg;

/**
 * Tracks press → move → release drag sequences within and across zones.
 *
 * Fork-returning layer — see README §Choosing your tracker idiom.
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
 * Extends bubblezone — upstream ships no drag tracker (its own gap
 * table records drag as manual).
 */
final class DragTracker
{
    /** @var ?string Zone id where the current drag started. */
    private ?string $originZoneId;

    /** @var ?string Zone id the mouse is currently over during the drag. */
    private ?string $currentZoneId;

    /** @var ?MouseButton Button that started the drag; releases of other buttons are ignored. */
    private ?MouseButton $dragButton;

    /**
     * @param Manager $manager Zone manager that has already run scan().
     * @param ?string $originZoneId Zone id where the drag started, null when not dragging.
     * @param ?string $currentZoneId Zone id mouse is currently in, null when not dragging.
     * @param ?MouseButton $dragButton Button that pressed to start the drag; null when
     *                                 not dragging (or when restoring an id-only snapshot).
     */
    public function __construct(
        public readonly Manager $manager,
        ?string $originZoneId = null,
        ?string $currentZoneId = null,
        ?MouseButton $dragButton = null,
    ) {
        $this->originZoneId = $originZoneId;
        $this->currentZoneId = $currentZoneId;
        $this->dragButton = $dragButton;
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
     * - Motion while dragging → crossing a boundary emits one
     *   {@see ZoneDragMoveMsg} for the new zone in the SAME call.
     * - Release of the drag button → end drag, emit {@see ZoneDragEndMsg}
     *   carrying the origin zone and the zone under the cursor at release
     *   (null when the release happened outside every zone).
     *
     * Button identity: the Press records which button started the drag, and
     * a Release of a DIFFERENT button neither ends the drag nor clears state.
     * Releasing with the drag button always ends the drag — even when the
     * origin zone has been removed from the manager (that end is silent:
     * no message, but the tracker clears so it cannot wedge).
     *
     * Wheel reports never participate in drags (see the gate below).
     *
     * @return array{0: self, 1: ?Msg}
     */
    public function update(MouseMsg $msg): array
    {
        // Wheel reports never participate in drags — they neither start,
        // move, nor end one. (Type gate: core currently delivers wheel
        // reports with action Press.)
        if ($msg instanceof MouseWheelMsg) {
            return [$this, null];
        }

        $hit = $this->manager->anyInBounds($msg);

        // --- Release: end any in-progress drag ---
        if ($msg->action === MouseAction::Release) {
            if ($this->originZoneId === null) {
                return [$this, null];
            }
            if ($this->dragButton !== null && $msg->button !== $this->dragButton) {
                // Release of a button that did not start this drag — inert.
                return [$this, null];
            }
            $origin = $this->manager->get($this->originZoneId);
            // The drag is over regardless of whether we can emit: clear now
            // so a vanished origin zone cannot wedge the tracker forever.
            $tracker = $this->mutate(null, null, null);
            if ($origin === null) {
                // Origin zone disappeared from the registry mid-drag; the
                // drag ends silently with state cleared.
                return [$tracker, null];
            }
            // The end message names the zone under the cursor AT RELEASE,
            // not a stale motion-tracked id, and null when outside all zones.
            return [$tracker, new ZoneDragEndMsg($origin, $hit)];
        }

        // --- Press: start a new drag if inside a zone ---
        if ($msg->action === MouseAction::Press) {
            if ($hit !== null) {
                return [$this->mutate($hit->id, $hit->id, $msg->button), new ZoneDragStartMsg($hit, $hit)];
            }
            return [$this, null];
        }

        // --- Motion: track current zone during an active drag ---
        if ($msg->action === MouseAction::Motion) {
            if ($this->originZoneId === null) {
                // Motion with no active drag — no-op.
                return [$this, null];
            }

            if ($hit === null) {
                // Dragged outside any zone — keep tracking with null current.
                if ($this->currentZoneId !== null) {
                    return [$this->mutate($this->originZoneId, null, $this->dragButton), null];
                }
                return [$this, null];
            }

            // Same zone as current — no transition.
            if ($hit->id === $this->currentZoneId) {
                return [$this, null];
            }

            // Zone boundary crossing during drag: one call, one Move.
            $origin = $this->manager->get($this->originZoneId);
            if ($origin !== null) {
                return [$this->mutate($this->originZoneId, $hit->id, $this->dragButton), new ZoneDragMoveMsg($origin, $hit)];
            }
        }

        return [$this, null];
    }

    /**
     * Read-only accessor for the button that started the current drag.
     * Returns null when not dragging or when state came from an id-only
     * {@see self::withZoneIds()} restore.
     */
    public function dragButton(): ?MouseButton
    {
        return $this->dragButton;
    }

    /**
     * Return a new tracker with a different manager.
     */
    public function withManager(Manager $manager): self
    {
        return new self($manager, $this->originZoneId, $this->currentZoneId, $this->dragButton);
    }

    /**
     * Return a new tracker with specific zone ids.
     * Intended for state snapshot/restore. An id-only restore cannot
     * carry button identity, so releases are accepted from any button
     * until the next Press re-arms the tracker.
     */
    public function withZoneIds(?string $origin, ?string $current): self
    {
        return $this->mutate($origin, $current, null);
    }

    /**
     * Clone with explicit state.
     */
    private function mutate(?string $originZoneId, ?string $currentZoneId, ?MouseButton $dragButton): self
    {
        return new self($this->manager, $originZoneId, $currentZoneId, $dragButton);
    }
}
