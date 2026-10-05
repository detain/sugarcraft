<?php

declare(strict_types=1);

namespace SugarCraft\Crush;

use SugarCraft\Core\I18n\Lang as BaseLang;
use SugarCraft\Core\I18n\T;

/**
 * Per-library translation facade for sugar-crush.
 *
 * Wraps the shared {@see \SugarCraft\Core\I18n\T} registry with the `'crush'`
 * namespace baked in, so `t('settings.category.model')` resolves
 * `crush.settings.category.model` through exact locale → base language →
 * `en` → raw key. Translated strings live in {@see ../lang/en.php}, the
 * source of truth every other `lang/<locale>.php` mirrors key for key
 * (`tests/LangParityTest.php`).
 *
 * USER-FACING TEXT ONLY. Tool descriptions, prompt layers and tool errors are
 * read by the model and stay English whatever the locale: translating them
 * changes what the model is told, not what the user reads. The documentation
 * drift guards compare docs against English registry text, so they pin `en`.
 *
 * @extends BaseLang
 */
final class Lang extends BaseLang
{
    protected const NAMESPACE = 'crush';
    protected const DIR = __DIR__ . '/../lang';

    /**
     * `$fn()` run with the process locale pinned to `$locale`, and the
     * previous locale restored however it returns.
     *
     * What a GENERATED page is built under: `docs/COMMANDS.md`, README's
     * roster and `docs/SETTINGS.md` are derived from registry text that is
     * now translated, and a generator run under an operator's `LANG` must
     * still write the English the drift tests compare against.
     *
     * @template TReturn
     * @param callable(): TReturn $fn
     * @return TReturn
     */
    public static function inLocale(string $locale, callable $fn): mixed
    {
        $previous = T::locale();
        T::setLocale($locale);
        try {
            return $fn();
        } finally {
            T::setLocale($previous);
        }
    }
}
