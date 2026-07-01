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
        foreach (\array_values($items) as $i => $item) {
            $result[] = $item instanceof Item
                ? $item
                : new FilteredItem($i + 1, (string) $item);
        }
        return $result;
    }
}
