<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Components\Select;

/**
 * Represents a single option in a select dropdown.
 *
 * Used by Select, ComboBox, Dropdown, and Radio components
 * to represent individual selectable items.
 */
final class Option
{
    public function __construct(
        public readonly string $label,
        public readonly ?string $value = null,
        public readonly ?string $icon = null,
        public readonly bool $disabled = false,
    ) {}

    /**
     * Create an option from an array.
     *
     * @param array{label: string, value?: string, icon?: string, disabled?: bool} $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            label: $data['label'],
            value: $data['value'] ?? null,
            icon: $data['icon'] ?? null,
            disabled: $data['disabled'] ?? false,
        );
    }

    /**
     * Convert to array representation.
     *
     * @return array{label: string, value?: string, icon?: string, disabled?: bool}
     */
    public function toArray(): array
    {
        $result = ['label' => $this->label];

        if ($this->value !== null) {
            $result['value'] = $this->value;
        }

        if ($this->icon !== null) {
            $result['icon'] = $this->icon;
        }

        if ($this->disabled) {
            $result['disabled'] = true;
        }

        return $result;
    }
}
