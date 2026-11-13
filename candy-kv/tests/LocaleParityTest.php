<?php

declare(strict_types=1);

namespace SugarCraft\Kv\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Kv\Support\PluralChooser;

/**
 * Locale-parity guard for candy-kv.
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

            // The only sanctioned superset: the CLDR plural forms a locale's
            // own rule can reach (PluralChooser::EXTRA_SUFFIXES). Anything
            // else beyond en.php is still a stale/unknown key.
            $expected = \array_merge(\array_keys($reference), self::pluralExtras($code));
            $missing = \array_values(\array_diff($expected, \array_keys($data)));
            $extra = \array_values(\array_diff(\array_keys($data), $expected));

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
                if (self::isPluralKey($key) && $actual === ['count']) {
                    // A plural-family `_one` may carry {count} where English
                    // spells a literal 1: Russian's `one` also covers 21, 31...
                    $expected = $actual;
                }
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

    public function testPluralExtrasAreDocumentedAndCarryCount(): void
    {
        $locales = self::allLocales();
        foreach (PluralChooser::EXTRA_SUFFIXES as $code => $suffixes) {
            $this->assertArrayHasKey($code, $locales, "plural extras name an unshipped locale '{$code}'");
            foreach (PluralChooser::BASES as $base) {
                foreach ($suffixes as $suffix) {
                    $key = "cli.{$base}_{$suffix}";
                    $this->assertArrayHasKey($key, $locales[$code], "{$code}: {$key}");
                    $placeholders = self::placeholders($locales[$code][$key]);
                    // `zero`/`two` forms may name the number in words.
                    $this->assertContains($placeholders, [['count'], []], "{$code}: {$key}");
                    if (!\in_array($suffix, ['zero', 'two'], true)) {
                        $this->assertSame(['count'], $placeholders, "{$code}: {$key} must interpolate the number");
                    }
                }
            }
        }
        foreach (['en', 'de', 'ja'] as $code) {
            $this->assertSame([], self::pluralExtras($code), "{$code} has no extra plural forms");
        }
    }

    /**
     * pt is European Portuguese and pt-br Brazilian: each keeps its own
     * vocabulary, so a copy-sweep that "fixes" one toward the other (the
     * two catalogs started as near-twins) reddens here.
     */
    public function testPortugueseVariantsKeepTheirOwnVocabulary(): void
    {
        $locales = self::allLocales();
        $pt = \implode("\n", $locales['pt']);
        $br = \implode("\n", $locales['pt-br']);

        foreach (['Banco de dados', 'arquivo', 'excluíd', 'sobrescrit', 'Você', 'você', 'mais legível'] as $brazilianism) {
            $this->assertStringNotContainsString($brazilianism, $pt, "pt carries the Brazilian form '{$brazilianism}'");
        }
        foreach (['Base de dados', 'ficheiro', 'eliminad', 'Queria dizer', 'substituíd'] as $europeanism) {
            $this->assertStringNotContainsString($europeanism, $br, "pt-br carries the European form '{$europeanism}'");
        }
        foreach (['Base de dados', 'ficheiro', 'eliminad', 'substituíd'] as $needle) {
            $this->assertStringContainsString($needle, $pt, "pt lost its European '{$needle}'");
        }
        foreach (['Banco de dados', 'arquivo', 'excluíd', 'sobrescrit', 'você'] as $needle) {
            $this->assertStringContainsString($needle, $br, "pt-br lost its Brazilian '{$needle}'");
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

    /** @return list<string> */
    private static function pluralExtras(string $code): array
    {
        $keys = [];
        foreach (PluralChooser::EXTRA_SUFFIXES[$code] ?? [] as $suffix) {
            foreach (PluralChooser::BASES as $base) {
                $keys[] = "cli.{$base}_{$suffix}";
            }
        }

        return $keys;
    }

    private static function isPluralKey(string $key): bool
    {
        return \preg_match('/_(?:one|zero|two|few|many)$/', $key) === 1;
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
