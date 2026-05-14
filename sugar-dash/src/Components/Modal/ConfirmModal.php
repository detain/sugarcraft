<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Components\Modal;

use SugarCraft\Dash\Foundation\Item;

/**
 * A confirmation modal dialog.
 */
final class ConfirmModal implements Item
{
    public function __construct(
        private readonly string $message,
        private readonly string $confirmLabel = 'Confirm',
        private readonly string $cancelLabel = 'Cancel',
        private readonly bool $isDanger = false,
    ) {}

    public static function new(string $message): self
    {
        return new self(message: $message);
    }

    public static function danger(string $message): self
    {
        return new self(message: $message, confirmLabel: 'Delete', cancelLabel: 'Cancel', isDanger: true);
    }

    public function render(): string
    {
        $confirm = $this->isDanger ? '⚠ ' . $this->confirmLabel : $this->confirmLabel;
        return $this->message . ' [' . $confirm . '] [' . $this->cancelLabel . ']';
    }
}
