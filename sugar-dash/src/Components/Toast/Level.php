<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Components\Toast;

/**
 * Log levels for toast notifications.
 */
enum Level: string
{
    case Info = 'info';
    case Warning = 'warning';
    case Error = 'error';
    case Success = 'success';

    /**
     * Get the default icon for this level.
     */
    public function icon(): string
    {
        return match ($this) {
            self::Info => 'ℹ',
            self::Warning => '⚠',
            self::Error => '✖',
            self::Success => '✓',
        };
    }

    /**
     * Check if this level represents an error condition.
     */
    public function isError(): bool
    {
        return $this === self::Error;
    }

    /**
     * Check if this level should be highlighted.
     */
    public function isHighlighted(): bool
    {
        return in_array($this, [self::Error, self::Warning], true);
    }
}
