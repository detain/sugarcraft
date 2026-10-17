<?php

declare(strict_types=1);

namespace SugarCraft\Core\Util;

/**
 * Simple no-op logger for when logging is not needed.
 * Does not implement PSR-3 LoggerInterface to avoid adding psr/log as a dependency.
 */
final class NullLogger
{
    public function emergency(\Stringable|string $_message, array $_context = []): void
    {
        // No-op
    }

    public function alert(\Stringable|string $_message, array $_context = []): void
    {
        // No-op
    }

    public function critical(\Stringable|string $_message, array $_context = []): void
    {
        // No-op
    }

    public function error(\Stringable|string $_message, array $_context = []): void
    {
        // No-op
    }

    public function warning(\Stringable|string $_message, array $_context = []): void
    {
        // No-op
    }

    public function notice(\Stringable|string $_message, array $_context = []): void
    {
        // No-op
    }

    public function info(\Stringable|string $_message, array $_context = []): void
    {
        // No-op
    }

    public function debug(\Stringable|string $_message, array $_context = []): void
    {
        // No-op
    }

    public function log(mixed $_level, \Stringable|string $_message, array $_context = []): void
    {
        // No-op
    }
}
