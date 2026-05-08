<?php

declare(strict_types=1);

namespace SugarCraft\Mosaic;

/**
 * i18n facade for candy-mosaic.
 *
 * Mirrors the pattern used in other SugarCraft libs: thin wrapper over
 * {@see \SugarCraft\Core\I18n\T} with the namespace baked in. Callers
 * use `Lang::t('key', ['param' => $value])`.
 */
final class Lang
{
    private static ?object $t = null;

    /**
     * Translate a message key with optional parameter substitution.
     *
     * @param string $key     Message key in dot-notation (e.g. 'image_source.file_not_found')
     * @param array  $params  Substitution parameters
     */
    public static function t(string $key, array $params = []): string
    {
        if (self::$t === null) {
            self::$t = new \SugarCraft\Core\I18n\T('SugarCraft\\Mosaic\\Lang');
        }

        return self::$t->translate($key, $params);
    }
}
