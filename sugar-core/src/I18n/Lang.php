<?php

declare(strict_types=1);

namespace SugarCraft\Core\I18n;

use SugarCraft\Core\I18n\T;

/**
 * Original: the Charmbracelet Go ecosystem has no i18n base class to
 * mirror — this bootstrap is PHP-side infrastructure (AGENTS: where no
 * upstream exists the library is original work).
 * Base class for per-library Lang bootstrapping.
 * Subclasses define NAMESPACE and DIR constants; this class handles T registration.
 * Registration is idempotent via T::register(), so we call it on every t() call
 * to ensure the namespace is registered even after T::reset() in tests.
 */
abstract class Lang
{
    /** Subclass must override with its library's translation namespace. */
    protected const NAMESPACE = '';
    /** Subclass must override with its library's lang/ directory path. */
    protected const DIR = '';

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
