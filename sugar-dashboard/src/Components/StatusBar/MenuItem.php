<?php

declare(strict_types=1);

namespace SugarCraft\Dashboard\Components\StatusBar;

use SugarCraft\Dashboard\Foundation\Item;

/**
 * A menu item for use in status bar menus.
 */
final class MenuItem implements Item
{
    public function __construct(
        private readonly string $label,
        private readonly ?string $shortcut = null,
        private readonly ?\SugarCraft\Dashboard\Components\StatusBar\StatusIndicator $indicator = null,
    ) {}

    public static function new(string $label): self
    {
        return new self(label: $label);
    }

    public function render(): string
    {
        $result = $this->label;
        if ($this->shortcut !== null) {
            $result .= ' [' . $this->shortcut . ']';
        }
        return $result;
    }
}
