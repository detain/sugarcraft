<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Events;

/**
 * Resize event.
 */
final class ResizeEvent extends Event
{
    public function __construct(
        int $timestamp,
        public readonly int $width,
        public readonly int $height,
    ) {
        parent::__construct($timestamp);
    }

    public function getType(): string
    {
        return 'resize';
    }
}
