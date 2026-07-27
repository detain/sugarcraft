<?php

declare(strict_types=1);

namespace SugarCraft\Hermit;

/**
 * Factory for converting raw input into Item instances.
 *
 * Strings are wrapped as FilteredItem with a 1-based ordinal.
 * Items already implementing Item are passed through unchanged.
 */
final class ItemFactory
{
    /**
     * @param array<Item|string> $items
     * @return list<Item>
     */
    public function coerce(array $items): array
    {
        $result = [];
        $stringIndex = 0;
        foreach (\array_values($items) as $item) {
            $result[] = $item instanceof Item
                ? $item
                : new FilteredItem(++$stringIndex, (string) $item);
        }
        return $result;
    }
}
