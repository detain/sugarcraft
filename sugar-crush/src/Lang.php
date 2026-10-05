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

    /**
     * Select the process locale from the environment, once, at launch, the
     * way POSIX ranks it: the first of `LC_ALL`, `LC_MESSAGES`, `LANG` that is
     * set and non-empty decides — `LANG=de_DE.UTF-8` reads `de-de`, then `de`,
     * then English per key.
     *
     * Nothing else selects one — T starts at `en` and its detection is
     * opt-in — so without this call every shipped translation stays unseen.
     * `bin/sugarcrush` makes it before anything is parsed or printed.
     * Not {@see T::detect()} itself: that one skips a `C` it finds and reads
     * on, so `LC_ALL=C` under `LANG=de_DE` would pick German, while the
     * deciding variable naming `C`/`POSIX` (with or without an encoding, e.g.
     * `C.UTF-8`) means the untranslated messages, English here. So does no
     * variable at all, or a value that is not a language tag (`en`, `pt-br`,
     * `zh-hant-tw`): the tag becomes part of a `lang/<tag>.php` path.
     *
     * Returns the locale it selected.
     */
    public static function useLaunchLocale(): string
    {
        $locale = 'en';
        foreach (['LC_ALL', 'LC_MESSAGES', 'LANG'] as $variable) {
            $raw = $_SERVER[$variable] ?? \getenv($variable);
            if (!\is_string($raw) || $raw === '') {
                continue;
            }
            // `fr_FR.UTF-8@euro` → `fr-fr`; `C.UTF-8` → `c`.
            $tag = \strtolower(\str_replace('_', '-', (string) \preg_replace('/[.@].*$/', '', $raw)));
            if ($tag !== 'c' && $tag !== 'posix' && \preg_match('/^[a-z]{2,3}(?:-[a-z0-9]{2,8})*$/', $tag) === 1) {
                $locale = $tag;
            }
            break;
        }
        T::setLocale($locale);

        return $locale;
    }
}
