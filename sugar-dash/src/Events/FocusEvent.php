<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Events;

/**
 * Focus event.
 */
final class FocusEvent extends Event
{
    public function __construct(
        int $timestamp,
        public readonly bool $gained,
    ) {
        parent::__construct($timestamp);
    }

    public function getType(): string
    {
        return 'focus';
    }
}
