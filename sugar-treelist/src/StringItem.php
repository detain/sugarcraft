<?php

declare(strict_types=1);

namespace SugarCraft\TreeList;

/**
 * Plain-string adapter that satisfies \Stringable for use as a list item.
 */
final class StringItem implements \Stringable
{
    public readonly string $value;

    public function __construct(string $value)
    {
        $this->value = $value;
    }

    /**
     * House factory (sugar-treelist-027): `StringItem::new($s)` mirrors the
     * constructor. Model's intake doors normalize plain strings internally,
     * so hand-wrapping is rarely needed; typed slots use this.
     */
    public static function new(string $value): self
    {
        return new self($value);
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
