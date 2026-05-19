<?php

declare(strict_types=1);

namespace SugarCraft\Toast;

use SugarCraft\Core\I18n\T;

/**
 * Per-library translation facade for sugar-toast.
 *
 * Wraps the shared {@see \SugarCraft\Core\I18n\T} registry with the
 * `'toast'` namespace baked in. Translated strings live in
 * {@see ../lang/en.php}.
 *
 * @see \SugarCraft\Wishlist\Lang for the same pattern in sugar-wishlist.
 * @see \SugarCraft\Calendar\Lang for the same pattern in sugar-calendar.
 * @see \SugarCraft\Table\Lang for the same pattern in sugar-table.
 */
final class Lang
{
    private const NAMESPACE = 'toast';
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
