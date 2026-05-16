<?php

declare(strict_types=1);

namespace SugarCraft\Vcr\Hook;

use SugarCraft\Vcr\Event;

/**
 * Callback invoked during cassette recording to inspect, modify, or
 * annotate events before they are persisted.
 *
 * Mirrors charmbracelet/x/vcr HookFunc.
 */
interface Hook
{
    /**
     * Called before an event is written to the cassette.
     *
     * Return a (potentially modified) Event to continue, or null to
     * skip writing this event entirely.
     *
     * @param Event $event The event about to be written
     * @return Event|null Modified event, or null to suppress
     */
    public function beforeSave(Event $event): ?Event;

    /**
     * Called after an event has been written to the cassette.
     *
     * This is fire-and-forget — throwing will not affect the recording.
     *
     * @param Event $event The event that was written
     */
    public function afterCapture(Event $event): void;
}
