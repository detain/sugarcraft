<?php

declare(strict_types=1);

namespace SugarCraft\Log\Hook;

use SugarCraft\Log\Level;

/**
 * Log hook contract — invoked when a log entry is emitted.
 *
 * Side-effects only: hooks run after the write with copies of the record
 * (dispatching to external services, counters, alerts) — they cannot modify
 * or filter what was already written.
 */
interface Hook
{
    /**
     * Called when a log event fires at the given level.
     *
     * @param Level        $level    The SugarCraft\Log\Level of the event.
     * @param string       $psrLevel The PSR-3 level string of the event.
     * @param string       $message  The primary log message.
     * @param array<mixed> $context  Key/value pairs attached to the entry.
     */
    public function onLevel(Level $level, string $psrLevel, string $message, array $context): void;
}
