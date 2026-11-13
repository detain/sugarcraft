<?php

declare(strict_types=1);

namespace SugarCraft\QuickPick;

/**
 * Factory for converting raw input into Item instances.
 *
 * Strings are wrapped as FilteredItem with a 1-based ordinal.
 * Items already implementing Item are passed through unchanged.
 *
 * Fail-fast ingress (-012): anything else is refused with an
 * `InvalidArgumentException` naming the offending index and type. The
 * former string-cast of the whole domain meant `withItems([null])`
 * shipped a selectable empty-string row, `[[]]` emitted a PHP Warning
 * plus the literal 'Array' row, and `[new stdClass]` raised an
 * uncatchable-by-accident Error from deep inside the constructor.
 */
final class ItemFactory
{
    /**
     * @param array<array-key, Item|string> $items
     * @return list<Item>
     */
    public function coerce(array $items): array
    {
        $result      = [];
        $stringIndex = 0;
        foreach ($items as $key => $item) {
            if ($item instanceof Item) {
                $result[] = $item;
                continue;
            }
            if (\is_string($item)) {
                $result[] = new FilteredItem(++$stringIndex, $item);
                continue;
            }
            throw new \InvalidArgumentException(\sprintf(
                'ItemFactory::coerce() accepts only Item or string; entry %s carries %s',
                (string) $key,
                \get_debug_type($item),
            ));
        }
        return $result;
    }
}
