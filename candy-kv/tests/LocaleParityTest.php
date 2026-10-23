<?php

declare(strict_types=1);

namespace SugarCraft\Kv\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Locale-parity guard for candy-kv (D4, docs-fix-1023).
 *
 * candy-kv ships 16 locales; a key added to en.php without the matching
 * translation in every other locale silently degrades those users to the
 * raw key (Lang::t falls back to the key itself). This guard re-derives
 * the en key set from lang/en.php and asserts, for every other locale:
 *
 *  - the same key set (bidirectional: no missing keys, no stale extras);
 *  - the same placeholder tokens ({bin}, {key}, ...) per key, so a
 *    translation cannot drop or invent an interpolation;
 *  - non-empty string values.
 *
 * en.php is the source of truth; the test file list is derived from the
 * lang/ directory itself, so adding a locale file automatically brings it
 * under the guard.
 */
final class LocaleParityTest extends TestCase
{
    /**
     * All locale files, keyed by basename => parsed contents.
     *
     * @return array<string, array<string, string>>
     */
    private static function allLocales(): array
    {
        $out = [];
        $files = \glob(\dirname(__DIR__) . '/lang/*.php');
        \sort($files);
        foreach ($files as $file) {
            $data = require $file;
            self::assertIsArray($data, \basename($file) . ' must return an array');
            $out[\basename($file, '.php')] = $data;
        }

        return $out;
    }

    public function testEnglishLocaleExistsAndIsNotEmpty(): void
    {
        $locales = self::allLocales();

        $this->assertArrayHasKey('en', $locales);
        $this->assertNotEmpty($locales['en']);
    }

    public function testSixteenLocalesShip(): void
    {
        $this->assertCount(16, self::allLocales());
    }

    public function testEveryLocaleCarriesExactlyTheEnglishKeySet(): void
    {
        $locales = self::allLocales();
        $reference = $locales['en'];

        foreach ($locales as $code => $data) {
            if ($code === 'en') {
                continue;
            }

            $missing = \array_values(\array_diff(\array_keys($reference), \array_keys($data)));
            $extra = \array_values(\array_diff(\array_keys($data), \array_keys($reference)));

            $this->assertSame([], $missing, "locale '{$code}' is missing keys: " . \implode(', ', $missing));
            $this->assertSame([], $extra, "locale '{$code}' carries keys unknown to en.php: " . \implode(', ', $extra));
        }
    }

    public function testPlaceholdersMatchEnglishPerKey(): void
    {
        $locales = self::allLocales();
        $reference = $locales['en'];

        foreach ($locales as $code => $data) {
            if ($code === 'en') {
                continue;
            }

            foreach ($reference as $key => $englishValue) {
                if (!isset($data[$key])) {
                    continue; // reported by the key-set test above
                }

                $expected = self::placeholders($englishValue);
                $actual = self::placeholders((string) $data[$key]);
                \sort($expected);
                \sort($actual);

                $this->assertSame(
                    $expected,
                    $actual,
                    "locale '{$code}' key '{$key}' must interpolate exactly: " . \implode(', ', $expected)
                );
            }
        }
    }

    public function testEveryValueIsANonEmptyString(): void
    {
        foreach (self::allLocales() as $code => $data) {
            foreach ($data as $key => $value) {
                $this->assertIsString($value, "locale '{$code}' key '{$key}' must be a string");
                $this->assertNotSame('', \trim($value), "locale '{$code}' key '{$key}' must not be empty");
            }
        }
    }

    /**
     * Ordered, de-duplicated placeholder token names in a message value.
     *
     * @return list<string>
     */
    private static function placeholders(string $value): array
    {
        \preg_match_all('/\{([a-z_]+)\}/', $value, $m);

        return \array_values(\array_unique($m[1]));
    }
}
