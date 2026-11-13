<?php

declare(strict_types=1);

namespace SugarCraft\Mouse\Zones;

use SugarCraft\Core\Msg;
use SugarCraft\Core\Msg\MouseMsg;
use SugarCraft\Core\Msg\MouseWheelMsg;
use SugarCraft\Mouse\Zones\Msg\ZoneEnterMsg;
use SugarCraft\Mouse\Zones\Msg\ZoneExitMsg;

/**
 * Tracks which zone the cursor is in across mouse-move events and
 * emits enter/exit messages on boundary crossings.
 *
 * Fork-returning layer — see README §Choosing your tracker idiom.
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
 * Extends bubblezone — upstream ships no hover tracker (its own gap
 * table records hover as manual via motion events).
 */
final class ZoneHoverTracker
{
    /** @var ?string Zone id currently hovered, null when cursor is in no zone. */
    private ?string $currentZoneId;

    /** @var ?string Zone id whose Enter is owed: set when a boundary crossing
     * emitted Exit(A) alone (the cursor already sat over B), consumed by the
     * next update() feed. */
    private ?string $pendingEnterId;

    /**
     * House `::new()` factory - same signature as the public
     * constructor, which stays public for BC (SM-S31, gate G12
     * house-convention half).
     */
    public static function new(Manager $manager, ?string $currentZoneId = null, ?string $pendingEnterId = null): self
    {
        return new self($manager, $currentZoneId, $pendingEnterId);
    }


    /**
     * @param Manager $manager Zone manager that has already run scan().
     * @param ?string $currentZoneId Currently hovered zone id, null initially.
     */
    public function __construct(
        public readonly Manager $manager,
        ?string $currentZoneId = null,
        ?string $pendingEnterId = null,
    ) {
        $this->currentZoneId = $currentZoneId;
        $this->pendingEnterId = $pendingEnterId;
    }

    /**
     * The zone the cursor is over when an Exit(A) was just emitted and the
     * matching Enter(B) is still owed — the one-message-per-call contract
     * strands it until a re-feed. Reports the pending Zone WITHOUT consuming
     * it: re-feed update() (synthetic Motion at the same coordinates works,
     * see README's hover re-dispatch recipe) to receive the real Enter.
     */
    public function pendingEnter(): ?Zone
    {
        return $this->pendingEnterId !== null
            ? $this->manager->get($this->pendingEnterId)
            : null;
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
     * call update() again to receive the enter for B (or read
     * {@see pendingEnter()} — it reports the owed zone without consuming
     * it). When the hovered zone has been REMOVED from the registry the
     * tracker clears silently: an Exit must name a live {@see Zone} object
     * the manager no longer holds, and an id-only Exit is a BC wire change
     * (gated, not shipped). The state still re-arms either way — the old
     * dangling-id branch wedged hover off forever.
     *
     * Wheel reports never move the hover: scrolling is not a cursor move
     * (the cursor does not teleport to the wheel coordinate), and core
     * currently stamps wheel events with action Press, so the exclusion is
     * by {@see MouseWheelMsg} type, not by action.
     *
     * @return array{0: self, 1: ?Msg} [updated tracker, optional hover msg]
     */
    public function update(MouseMsg $msg): array
    {
        if ($msg instanceof MouseWheelMsg) {
            return [$this, null];
        }

        $hit = $this->manager->anyInBounds($msg);

        if ($hit === null) {
            // Cursor is not in any zone.
            if ($this->currentZoneId !== null) {
                $exited = $this->manager->get($this->currentZoneId);
                if ($exited !== null) {
                    return [$this->mutate(null, null), new ZoneExitMsg($exited)];
                }
                // Hovered zone vanished from the registry: no Exit can be
                // built (the object is gone) but the state must clear —
                // returning $this here wedged hover on the dangling id.
                return [$this->mutate(null, null), null];
            }
            // Nothing hovered; drop an owed Enter the cursor moved away from.
            return [$this->pendingEnterId === null ? $this : $this->mutate(null, null), null];
        }

        // Cursor is in a zone.
        if ($hit->id === $this->currentZoneId) {
            // Still inside the same zone — no transition.
            return [$this, null];
        }

        // Zone boundary crossing detected.
        // If there was a previous zone, emit its exit first.
        // The caller calls update() again to receive the enter for the new
        // zone (or reads pendingEnter()); a vanished previous zone exits
        // silently — Enter(B) still fires below so state never wedges.
        if ($this->currentZoneId !== null) {
            $exited = $this->manager->get($this->currentZoneId);
            if ($exited !== null) {
                // Clear currentZoneId and remember the owed enter so the
                // next update() sees a null-to-new transition rather than
                // an same-id no-op.
                return [$this->mutate(null, $hit->id), new ZoneExitMsg($exited)];
            }
        }

        // Entering a new zone.
        return [$this->mutate($hit->id, null), new ZoneEnterMsg($hit)];
    }

    /**
     * Return a new tracker with a different manager (useful when composing
     * multiple zone-aware components each with their own prefixed manager).
     */
    public function withManager(Manager $manager): self
    {
        return $this->mutate($this->currentZoneId, $this->pendingEnterId, $manager);
    }

    /**
     * Return a new tracker with a specific current zone id.
     * Intended for state snapshot/restore rather than direct use.
     * Pass null to clear the hovered state; an owed Enter is dropped (a
     * restored id replaces the enter flow, it does not continue it).
     */
    public function withCurrentZoneId(?string $id): self
    {
        return $this->mutate($id, null);
    }

    /**
     * Clone with optional overrides.
     *
     * @param ?string $currentZoneId Override for the current zone id.
     * @param ?string $pendingEnterId Override for the owed-enter zone id.
     * @param ?Manager $manager Override for the manager (null keeps the
     *                          current one — the seam behind withManager()).
     */
    private function mutate(?string $currentZoneId, ?string $pendingEnterId, ?Manager $manager = null): self
    {
        return new self($manager ?? $this->manager, $currentZoneId, $pendingEnterId);
    }
}
