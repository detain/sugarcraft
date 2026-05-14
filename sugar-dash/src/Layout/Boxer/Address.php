<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Layout\Boxer;

/**
 * Address type for identifying nodes in the boxer layout tree.
 */
final class Address
{
    private function __construct(
        private readonly string $value,
    ) {}

    public static function root(): self
    {
        return new self('root');
    }

    public static function child(self $parent, int $index): self
    {
        return new self($parent->value . '.' . $index);
    }

    public static function fromString(string $value): self
    {
        return new self($value);
    }

    public function toString(): string
    {
        return $this->value;
    }

    public function getParent(): ?self
    {
        $parts = explode('.', $this->value);
        if (count($parts) <= 1) {
            return null;
        }
        array_pop($parts);
        return new self(implode('.', $parts));
    }

    public function getChild(int $index): self
    {
        return new self($this->value . '.' . $index);
    }
}
