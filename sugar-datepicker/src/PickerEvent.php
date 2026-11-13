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
    /** Cursor index changed; payload `{index}`. */
    public const CURSOR_MOVED = 'cursor_moved';

    /** Range mode's first Enter armed the start (unset → set); payload `{date}`. */
    public const RANGE_STARTED = 'range_started';

    /** Selection changed; payload `{date}` (single) or `{date, rangeStart, rangeEnd}` (range completed). */
    public const DATE_SELECTED = 'date_selected';

    /** Single-mode selection went from set to unset; empty payload. */
    public const DATE_CLEARED = 'date_cleared';

    private function __construct()
    {
    }
}
