<?php

declare(strict_types=1);

namespace SugarCraft\Dashboard\Layout\Boxer;

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

    public function parent(): ?self
    {
        $parts = explode('.', $this->value);
        if (count($parts) <= 1) {
            return null;
        }
        array_pop($parts);
        return new self(implode('.', $parts));
    }

    /**
     * BC forwarder for the pre-rename accessor name.
     *
     * @deprecated The `get`-prefixed form breaks the house bare-accessor
     *     convention; kept only as a BC forwarder. Use
     *     {@see self::parent()} instead. REMOVED-IN: 2.0.0.
     */
    public function getParent(): ?self { return $this->parent(); }

    public function getChild(int $index): self
    {
        return new self($this->value . '.' . $index);
    }
}
