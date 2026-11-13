<?php

declare(strict_types=1);

namespace SugarCraft\Mouse;

/**
 * Deduplicate press/release pairs so callers receive one ClickResult per
 * logical click — suppressing spurious extra Press events fired during a
 * drag and discarding mismatched Press+Release pairs on different zones.
 *
 * Mutable layer — see README §Choosing your tracker idiom.
 *
 * State machine per button:
 *   idle       → Press  → waiting (store zone+button)
 *   waiting    → Press  → waiting (replace pending with new zone — last press wins)
 *   waiting    → Release whose fresh re-hit is the same zone+button → emit ClickResult, idle
 *   waiting    → Release on a different zone, or onto nothing in the
 *                current frame → clear state, idle
 *   any state  → Drag   → ignored
 *   any state  → Scroll → ignored (no click; nothing is passed on —
 *                a scroll must never complete a press it did not start)
 *
 * There are two ways to supply the press zone:
 *   1. Preferred (one-call):  track($event, $scanner->hit($event->x, $event->y))
 *   2. Legacy (two-call, deprecated):  track($event);  setPressZone($zone, $button)
 *
 * Either way the RELEASE call must carry its own fresh re-resolution of the
 * release coordinate against the CURRENT frame: the click fires only when
 * that fresh zone agrees with the press zone on id and box (the
 * release-agreement gate).  A re-render between press and release that moved the
 * zone or slid another control under the cursor drops the pending press —
 * a lost click beats firing the wrong control (permission-grant zones are
 * dispatched by positional ids and reflow across frames).
 *
 * Mirrors the zone-level click deduplication added in bubblezone issue #10.
 */
final class ZoneClickTracker
{
    /**
     * @var array<int, array{zone:Zone|null, button:int}> button => pending state
     */
    private array $pending = [];

    /**
     * House `::new()` factory - same signature as the public
     * constructor, which stays public for BC (SM-S31, gate G12
     * house-convention half).
     */
    public static function new(): self
    {
        return new self();
    }

    /**
     * Feed a mouse event and receive a ClickResult if the event completes
     * a clean Press+Release pair on the same zone.
     *
     * @param MouseEvent     $event   The mouse event to process.
     * @param Zone|null      $hitZone Pre-resolved zone from
     *                                scanner->hit($event->x, $event->y) for
     *                                THIS event.  On Press it records the
     *                                press-time zone.  On Release it must be
     *                                a FRESH re-resolution against the
     *                                current frame — the click fires only if
     *                                it agrees with the stored press zone
     *                                (release-agreement gate, see class docs).
     *                                Pass null on Press to use the legacy
     *                                two-call pattern.
     *
     * @return ClickResult|null null when no click completes this tick.
     *                          SILENT BY DESIGN: a forgotten re-hit zone on
     *                          the Release call, a zone that moved between
     *                          frames, and a press that hit nothing all
     *                          collapse to the same null — a lost click
     *                          beats firing the wrong control. Use
     *                          {@see self::observeRelease()} to ask why
     *                          without consuming the pending press.
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
            if (isset($this->pending[$btn]) === false) {
                // Release without a preceding press — ignore.
                return null;
            }
            $pending = $this->pending[$btn];

            // If no zone was recorded (Press hit nothing), ignore.
            if ($pending['zone'] === null) {
                unset($this->pending[$btn]);
                return null;
            }

            // Release on a different zone — clear state and return null per
            // the documented state machine: "waiting → Release on different zone
            // → clear state, idle".
            if ($pending['zone']->inBounds($event) === false) {
                unset($this->pending[$btn]);
                return null;
            }

            // Release-agreement gate: the stored press box alone is not enough.
            // A re-render between Press and Release can slide a different
            // control (or nothing) under these coordinates while the press
            // box still contains them, and dispatch acts on the LIVE model —
            // so the release's fresh re-hit must name the very same zone
            // (id + box) the press stored.  Null here means the current scan
            // resolved to nothing: the zone vanished, drop the pending press.
            if ($hitZone === null || self::agrees($pending['zone'], $hitZone) === false) {
                unset($this->pending[$btn]);
                return null;
            }

            // Same zone under both the press box and the current scan —
            // emit click and clear pending.
            unset($this->pending[$btn]);
            return new ClickResult($pending['zone'], $btn);
        }

        return null;
    }

    /**
     * Read-only view of the release-agreement gate: would a track() call
     * with THIS release event and re-hit zone complete the click? Answers
     * without consuming the pending press. A caller can distinguish "I
     * forgot to re-resolve" (false with null, true once passed the fresh
     * hit) from "the zone genuinely moved" (false either way). The silent
     * null from track() cannot tell those apart, and misclick-protection
     * consumers want to know which one they hit.
     *
     * Non-Release events, or buttons with no pending press, report false.
     */
    public function observeRelease(MouseEvent $event, ?Zone $hitZone = null): bool
    {
        if ($event->action !== MouseAction::Release) {
            return false;
        }

        $btn = $event->button;
        if (isset($this->pending[$btn]) === false) {
            return false;
        }

        $pending = $this->pending[$btn];
        if ($pending['zone'] === null) {
            return false;
        }

        if ($pending['zone']->inBounds($event) === false) {
            return false;
        }

        return $hitZone !== null && self::agrees($pending['zone'], $hitZone);
    }

    /**
     * Inject the zone that was hit at the time of the press event.
     * Call this immediately after track(Press) when you have the zone.
     * The Release call must still carry the fresh re-hit zone for the
     * Release-agreement gate — setPressZone() only stores the press half.
     *
     * @deprecated Two-call order-coupled vestige of the press half (SM-S13):
     *             calling it BEFORE track(Press) is a silent no-op (there is
     *             no pending button to attach to yet), and calling it twice
     *             or late silently races the press state. Prefer the one-call
     *             pattern track($press, $scanner->hit(...)). The method stays
     *             working for existing callers; its misordered no-op is
     *             pinned by tests, not fixed into a throw.
     */
    public function setPressZone(Zone $zone, int $button): void
    {
        if (isset($this->pending[$button]) === true) {
            $this->pending[$button]['zone'] = $zone;
        }
    }

    /**
     * Value agreement between the press-time zone and the release's fresh
     * re-resolution.  The registry is rebuilt every frame, so the two scans
     * hand out distinct Zone instances for one control — object identity is
     * meaningless here and agreement is id plus the exact bounding box.
     * Same id at a different box still does not agree: the coordinate then
     * covers different content from where the press landed (a reflow
     * moved it).
     */
    private static function agrees(Zone $press, Zone $current): bool
    {
        return $press->id === $current->id
            && $press->startCol === $current->startCol
            && $press->startRow === $current->startRow
            && $press->endCol === $current->endCol
            && $press->endRow === $current->endRow;
    }
}
