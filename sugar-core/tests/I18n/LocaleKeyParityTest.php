<?php

declare(strict_types=1);

namespace SugarCraft\Core\Tests\I18n;

use PHPUnit\Framework\TestCase;

/**
 * Guards the i18n key surface (lib-reviews-1102 C8).
 *
 * Every shipped locale must carry EXACTLY the key set of lang/en.php and
 * must keep the {placeholders} of each English value intact — before this
 * guard, fifteen locales shipped 8 of 24 keys, so the shared errors.*
 * trio (the fleet's most-reused validation voice) fell back to English
 * under every non-`en` locale with nothing failing the build.
 */
final class LocaleKeyParityTest extends TestCase
{
    /** @return array<string, array<string, string>> */
    private static function allLocales(): array
    {
        $out = [];
        foreach (glob(__DIR__ . '/../../lang/*.php') ?: [] as $path) {
            $locale = basename($path, '.php');
            /** @var array<string, string> $table */
            $table = require $path;
            $out[$locale] = $table;
        }
        return $out;
    }

    public function testEveryLocaleCarriesExactlyTheEnglishKeySet(): void
    {
        $locales = self::allLocales();
        self::assertNotEmpty($locales, 'no lang/*.php files found');
        $en = $locales['en'] ?? self::fail('lang/en.php missing');

        foreach ($locales as $locale => $table) {
            if ($locale === 'en') {
                continue;
            }
            $missing = array_keys(array_diff_key($en, $table));
            $extra = array_keys(array_diff_key($table, $en));
            self::assertSame(
                [],
                $missing,
                "locale '$locale' is missing keys: " . implode(', ', $missing)
            );
            self::assertSame(
                [],
                $extra,
                "locale '$locale' carries keys absent from en.php: " . implode(', ', $extra)
            );
        }
    }

    public function testEveryTranslationKeepsTheEnglishPlaceholders(): void
    {
        $locales = self::allLocales();
        $en = $locales['en'];

        foreach ($locales as $locale => $table) {
            if ($locale === 'en') {
                continue;
            }
            foreach ($en as $key => $english) {
                if (!isset($table[$key])) {
                    continue; // covered by the key-set test
                }
                preg_match_all('/\{([a-zA-Z0-9_]+)\}/', $english, $want);
                preg_match_all('/\{([a-zA-Z0-9_]+)\}/', $table[$key], $have);
                sort($want[1]);
                sort($have[1]);
                self::assertSame(
                    $want[1],
                    $have[1],
                    "locale '$locale' key '$key' changes the placeholder set"
                );
            }
        }
    }

    public function testTheValidationVoiceIsTranslatedNotJustPresent(): void
    {
        // C8's headline: errors.value_must_be_positive is inherited by
        // every sibling lib that calls Validation::positive(). A locale
        // that ships the key with the English verbatim would pass the
        // set test; this arm proves the shipped locales actually translate
        // the trio (en is excluded by construction).
        $locales = self::allLocales();
        foreach (['errors.value_must_be_positive', 'errors.value_out_of_range', 'errors.negative_not_allowed'] as $key) {
            foreach ($locales as $locale => $table) {
                if ($locale === 'en') {
                    continue;
                }
                self::assertArrayHasKey($key, $table, "locale '$locale' lacks $key");
                if ($locale === 'pt-br') {
                    continue; // intentionally inherits pt wording
                }
                self::assertNotSame(
                    $locales['en'][$key],
                    $table[$key],
                    "locale '$locale' ships $key as untranslated English"
                );
            }
        }
    }
}
