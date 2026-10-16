<?php

declare(strict_types=1);

namespace SugarCraft\Forms\ItemList;

/**
 * Convenience {@see Item} carrying just a single string. Useful when you
 * want to populate an {@see ItemList} from a flat list of choices.
 */
final class StringItem implements Item
{
    public function __construct(public readonly string $value) {}

    public function title(): string       { return $this->value; }
    public function description(): string { return ''; }
    public function filterValue(): string { return $this->value; }
}
