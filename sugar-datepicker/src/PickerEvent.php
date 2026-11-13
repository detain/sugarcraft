<?php

declare(strict_types=1);

namespace SugarCraft\DatePicker;

/**
 * Wire vocabulary for the events the TEA {@see Model} records.
 *
 * Class constants (the house enum-lite pattern — see sugar-core's Cmd): the
 * string VALUES are published wire contract. Hosts compare against these
 * constants instead of hand-copying literals; the value-stability pins in the
 * suite guarantee the vocabulary never silently changes.
 *
 * Recording-channel ruling (dp-20): events are emitted ONLY by the TEA
 * Model's update() loop. {@see DatePicker::handleKey()} is the non-TEA
 * imperative surface and records nothing by design — wiring a store around
 * direct handleKey()/with*() calls is a host-side concern (diff the picker
 * states yourself, or route the keys through a Model).
 */
final class PickerEvent
{
    /** Cursor index changed; payload `{index, date}` (date null on an empty cell). */
    public const CURSOR_MOVED = 'cursor_moved';

    /** Range mode's first Enter armed the start (unset → set); payload `{date}`. */
    public const RANGE_STARTED = 'range_started';

    /** Selection changed; payload `{date}` (single) or `{date, rangeStart, rangeEnd}` (range completed). */
    public const DATE_SELECTED = 'date_selected';

    /** Single-mode selection went from set to unset; empty payload. */
    public const DATE_CLEARED = 'date_cleared';

    /**
     * A completed range was re-armed by another Enter (dp-09): the end was
     * dropped and the start moved to a fresh day; payload `{from, to}`
     * (to may equal from — same-day restart is still a completed→pending
     * transition).
     */
    public const RANGE_RESTARTED = 'range_restarted';

    /**
     * Range endpoints went from armed to fully cleared (Escape or bound
     * tightening), pending or completed alike (dp-09); payload `{from}`.
     */
    public const RANGE_CLEARED = 'range_cleared';

    /**
     * A bare Enter that could not select (dp-09); payload `{date, reason}`
     * with reason out_of_bounds (dimmed day under the cursor) or empty_cell
     * (blank lead-in/trailing cell — refused symmetrically in both modes,
     * dp-26; `date` is null there). The announcement of a refusal does NOT
     * make polling optional — state stays authoritative; this is the error
     * state a host may now surface without diffing picker state.
     */
    public const SELECTION_REFUSED = 'selection_refused';

    /** The viewed month stepped (PageUp/PageDown within bounds, dp-09); payload `{year, month}`. */
    public const MONTH_CHANGED = 'month_changed';

    private function __construct()
    {
    }
}
