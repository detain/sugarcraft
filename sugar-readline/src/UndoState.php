<?php

declare(strict_types=1);

namespace SugarCraft\Readline;

/**
 * A single buffer state inside the UndoManager node chain.
 *
 * @internal storage detail of UndoManager; not part of the public surface.
 */
final readonly class UndoState
{
    public function __construct(
        public string $state,
        public ?self $older,
    ) {
    }
}
