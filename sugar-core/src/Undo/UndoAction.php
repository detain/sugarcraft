<?php

declare(strict_types=1);

namespace SugarCraft\Core\Undo;

/**
 * Represents a single undoable action with its type, payload, and label.
 */
final readonly class UndoAction
{
    public function __construct(
        public UndoActionType $type,
        public array $payload,
        public string $label,
    ) {
    }

    /**
     * Fleet-convention factory (lib-reviews-1102 M37): docs and siblings
     * teach `UndoAction::new(...)`; the constructor-only shape violated
     * the ::new() law every other SugarCraft value object follows.
     *
     * @param array<string, mixed> $payload
     */
    public static function new(UndoActionType $type, array $payload, string $label): self
    {
        return new self($type, $payload, $label);
    }
}
