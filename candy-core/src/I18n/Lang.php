<?php

declare(strict_types=1);

namespace SugarCraft\Core\I18n;

use SugarCraft\Core\I18n\T;

/**
 * Mirrors charmbracelet/<repo>.BaseLang.
 * Base class for per-library Lang bootstrapping.
 * Subclasses define NAMESPACE and DIR constants; this class handles T registration.
 * Registration is idempotent via T::register(), so we call it on every t() call
 * to ensure the namespace is registered even after T::reset() in tests.
 */
abstract class Lang
{
    /**
     * Translate a message key within this namespace.
     *
     * @param string $key    Dot-separated key relative to namespace (e.g. 'errors.not_found')
     * @param array  $params Interpolation parameters
     * @return string Translated string
     */
    public static function t(string $key, array $params = []): string
    {
        $ns = static::NAMESPACE;

        T::register($ns, static::DIR);

        return T::translate($ns . '.' . $key, $params);
    }
}
