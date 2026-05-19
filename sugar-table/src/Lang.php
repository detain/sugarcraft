<?php

declare(strict_types=1);

namespace SugarCraft\Table;

use SugarCraft\Core\I18n\T;

/**
 * Per-library translation facade for sugar-table.
 *
 * Wraps the shared {@see \SugarCraft\Core\I18n\T} registry with the
 * `'table'` namespace baked in. Translated strings live in
 * {@see ../lang/en.php}.
 *
 * @see \SugarCraft\Wishlist\Lang for the same pattern in sugar-wishlist.
 * @see \SugarCraft\Calendar\Lang for the same pattern in sugar-calendar.
 */
final class Lang
{
    private const NAMESPACE = 'table';
    private const DIR       = __DIR__ . '/../lang';

    /**
     * @param array<string, string|int|float> $params Placeholder values.
     */
    public static function t(string $key, array $params = []): string
    {
        T::register(self::NAMESPACE, self::DIR);
        return T::translate(self::NAMESPACE . '.' . $key, $params);
    }
}
