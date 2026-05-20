<?php

declare(strict_types=1);

namespace SugarCraft\Log\Hook;

use SugarCraft\Log\Level;

/**
 * Log hook contract — invoked when a log entry is emitted.
 *
 * Allows intercepting log events for side-effects (dispatching to
 * external services, enriching context, filtering, etc.).
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
