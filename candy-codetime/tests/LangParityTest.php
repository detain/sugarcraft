<?php

declare(strict_types=1);

namespace SugarCraft\CodeTime\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Locale parity guard: every shipped translation must cover the full English
 * key set with non-empty values, byte-identical {placeholder} multisets, and
 * no residue of the retired product name. This is the genre that caught the
 * era when the 15 non-en locales each carried 1 translated key of 21.
 */
final class LangParityTest extends TestCase
{
    /** @return array<string, array<string,string>> */
    private static function allLocales(): array
    {
        $out = [];
        foreach (glob(dirname(__DIR__) . '/lang/*.php') ?: [] as $file) {
            $out[basename($file, '.php')] = include $file;
        }
        ksort($out);
        return $out;
    }

    /** @return list<string> */
    private static function nonEnglish(): array
    {
        return array_values(array_filter(
            array_keys(self::allLocales()),
            static fn(string $loc): bool => $loc !== 'en',
        ));
    }

    public static function localeProvider(): array
    {
        $rows = [];
        foreach (self::nonEnglish() as $locale) {
            $rows[$locale] = [$locale];
        }
        return $rows;
    }

    /**
     * @dataProvider localeProvider
     */
    public function testLocaleCarriesExactlyTheEnglishKeySet(string $locale): void
    {
        $locales = self::allLocales();
        $english = array_keys($locales['en']);
        sort($english);
        $keys = array_keys($locales[$locale]);
        sort($keys);
        $this->assertSame(
            $english,
            $keys,
            "locale {$locale} diverges from the English key set",
        );
    }

    /**
     * @dataProvider localeProvider
     */
    public function testLocaleValuesAreTranslatedAndPlaceholderFaithful(string $locale): void
    {
        $locales = self::allLocales();
        foreach ($locales[$locale] as $key => $value) {
            $this->assertIsString($value, "{$locale}/{$key} is not a string");
            $this->assertNotSame('', trim($value), "{$locale}/{$key} is empty");
            preg_match_all('/\{([a-z_]+)\}/', $value, $mine);
            preg_match_all('/\{([a-z_]+)\}/', $locales['en'][$key], $theirs);
            sort($mine[1]);
            sort($theirs[1]);
            $this->assertSame(
                $theirs[1],
                $mine[1],
                "{$locale}/{$key} mutates the placeholder set",
            );
        }
    }

    public function testTheRetiredProductNameIsGoneFromEveryString(): void
    {
        foreach (self::allLocales() as $locale => $messages) {
            foreach ($messages as $key => $value) {
                $this->assertDoesNotMatchRegularExpression(
                    '/\btick\b/i',
                    $value,
                    "{$locale}/{$key} still speaks of a 'tick'",
                );
            }
        }
    }

    public function testSixteenLocalesShip(): void
    {
        $this->assertCount(16, self::allLocales());
    }
}
