<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Grid;

/**
 * Paste event.
 */
final class PasteEvent extends Event
{
    public function __construct(
        int $timestamp,
        public readonly string $text,
    ) {
        parent::__construct($timestamp);
    }

    public function getType(): string
    {
        return 'paste';
    }
}
