<?php

declare(strict_types=1);

namespace SugarCraft\Core\Undo;

/**
 * Represents a single undoable action with its type, payload, and label.
 */
readonly class UndoAction
{
    public function __construct(
        public UndoActionType $type,
        public array $payload,
        public string $label,
    ) {
    }
}
