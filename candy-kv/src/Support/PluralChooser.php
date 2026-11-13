<?php

declare(strict_types=1);

namespace SugarCraft\Kv\Support;

use SugarCraft\Core\I18n\T;
use SugarCraft\Kv\Lang;

/**
 * Picks the CLDR cardinal plural form for a count message.
 *
 * The CLI's counted lines (`deleted`, `imported`, `overwrote`,
 * `would_delete`, `pruned`) shipped as a two-way `n === 1` switch, which
 * is wrong for Russian (21 entries takes the singular), Polish and Czech
 * (2-4 take a third form) and Arabic (six forms). Catalog keys are
 * `cli.<base>_<suffix>`; the `other` category keeps the historic `_n`
 * suffix so every existing key and caller stays valid, and a locale only
 * carries the extra suffixes its own rule can reach (see
 * {@see self::EXTRA_SUFFIXES}, pinned by LocaleParityTest).
 *
 * Rules are the CLDR integer cardinals; fractional counts never occur here.
 */
final class PluralChooser
{
    /**
     * Suffixes a locale carries BEYOND the `_one`/`_n` pair every locale
     * ships. Locales not listed use exactly that pair.
     *
     * @var array<string, list<string>>
     */
    public const EXTRA_SUFFIXES = [
        'ru' => ['few'],
        'pl' => ['few'],
        'cs' => ['few'],
        'ar' => ['zero', 'two', 'few', 'many'],
    ];

    /** The counted-message bases the CLI emits. */
    public const BASES = ['deleted', 'imported', 'overwrote', 'would_delete', 'pruned'];

    /**
     * CLDR category (`zero|one|two|few|many|other`) for a count.
     */
    public static function category(string $locale, int $n): string
    {
        $n = \abs($n);
        $lang = \strtolower((string) \preg_split('/[-_.@]/', $locale, 2)[0]);
        $m10 = $n % 10;
        $m100 = $n % 100;

        return match ($lang) {
            'ru', 'uk' => $m10 === 1 && $m100 !== 11 ? 'one'
                : ($m10 >= 2 && $m10 <= 4 && ($m100 < 12 || $m100 > 14) ? 'few' : 'many'),
            'pl' => $n === 1 ? 'one'
                : ($m10 >= 2 && $m10 <= 4 && ($m100 < 12 || $m100 > 14) ? 'few' : 'many'),
            'cs', 'sk' => $n === 1 ? 'one' : ($n >= 2 && $n <= 4 ? 'few' : 'other'),
            'ar' => match (true) {
                $n === 0 => 'zero',
                $n === 1 => 'one',
                $n === 2 => 'two',
                $m100 >= 3 && $m100 <= 10 => 'few',
                $m100 >= 11 => 'many',
                default => 'other',
            },
            default => $n === 1 ? 'one' : 'other',
        };
    }

    /**
     * Catalog key for `$base` at count `$n`, falling back toward `_n`
     * when the locale does not carry the chosen suffix (a `many` that a
     * Russian catalog folds into `_n`, an English `few`).
     */
    public static function key(string $base, int $n, ?string $locale = null): string
    {
        $locale ??= T::locale();
        $category = self::category($locale, $n);
        $lang = \strtolower((string) \preg_split('/[-_.@]/', $locale, 2)[0]);
        $carried = self::EXTRA_SUFFIXES[$lang] ?? [];

        if ($category === 'one') {
            return "cli.{$base}_one";
        }
        if (\in_array($category, $carried, true)) {
            return "cli.{$base}_{$category}";
        }

        return "cli.{$base}_n";
    }

    /** Translated counted line for `$base` at `$n` (always passes `{count}`). */
    public static function t(string $base, int $n): string
    {
        return Lang::t(self::key($base, $n), ['count' => $n]);
    }
}
