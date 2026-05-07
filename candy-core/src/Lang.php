<?php

declare(strict_types=1);

namespace SugarCraft\Core;

use SugarCraft\Core\I18n\T;

/**
 * Per-library translation facade for candy-core.
 *
 * Wraps {@see \SugarCraft\Core\I18n\T} with the `'core'` namespace baked
 * in so that call sites stay short:
 *
 * ```php
 * throw new \InvalidArgumentException(
 *     Lang::t('color.invalid_hex', ['hex' => $hex])
 * );
 * ```
 *
 * The first call registers candy-core's `lang/` directory with the
 * shared {@see T} registry; subsequent calls are no-ops, so this helper
 * stays cheap to use anywhere user-facing text is generated.
 *
 * Every SugarCraft library ships its own `Lang` class with the same
 * shape — only the namespace string and the lang directory differ.
 */
final class Lang
{
    /** Namespace prefix used for all keys looked up via {@see t()}. */
    private const NAMESPACE = 'core';

    /** Absolute path to candy-core's lang directory. */
    private const DIR = __DIR__ . '/../lang';

    /**
     * Translate a candy-core key.
     *
     * @param string                          $key    Sub-key without the
     *                                                `core.` prefix, e.g.
     *                                                `'color.invalid_hex'`.
     * @param array<string, string|int|float> $params Placeholder values.
     */
    public static function t(string $key, array $params = []): string
    {
        T::register(self::NAMESPACE, self::DIR);
        return T::translate(self::NAMESPACE . '.' . $key, $params);
    }
}
