<?php

declare(strict_types=1);

namespace CandyCore\Core\Msg;

use CandyCore\Core\Msg;

/**
 * Reply to a {@see \CandyCore\Core\Cmd::requestBackgroundColor()}.
 * Mirrors {@see ForegroundColorMsg} for the OSC 11 query — useful for
 * picking a contrasting theme via {@see self::isDark()}.
 */
final class BackgroundColorMsg implements Msg
{
    public function __construct(
        public readonly int $r,
        public readonly int $g,
        public readonly int $b,
    ) {}

    /**
     * Returns true when the reported background looks dark, so callers
     * can pick "light-on-dark" colours. Mirrors lipgloss v2's
     * `HasDarkBackground`.
     */
    public function isDark(): bool
    {
        $lum = 0.2126 * ($this->r / 255) + 0.7152 * ($this->g / 255) + 0.0722 * ($this->b / 255);
        return $lum < 0.5;
    }

    public function hex(): string
    {
        return sprintf('#%02x%02x%02x', $this->r, $this->g, $this->b);
    }
}
