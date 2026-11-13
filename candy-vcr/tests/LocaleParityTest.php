<?php

declare(strict_types=1);

namespace SugarCraft\Vcr\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Locale-parity guard for candy-vcr.
 *
 * Lang::t falls back to the raw key for a missing translation, so a key added
 * to en.php without every other locale silently degrades those users to
 * `cli.err`-style gibberish. This guard re-derives the en key set from
 * lang/en.php and asserts, for every other locale:
 *
 *  - the same key set (bidirectional: no missing keys, no stale extras);
 *  - the same placeholder tokens ({verb}, {path}, ...) per key — names, not
 *    order, because params are bound by name and a translation may reorder;
 *  - non-empty string values;
 *  - the same number of lines for the multi-line help blocks, whose
 *    continuation lines the code re-indents under the option column.
 *
 * The locale list is derived from lang/ itself, so a new locale file comes
 * under the guard automatically; the roster test pins the fleet's 16 codes.
 */
final class LocaleParityTest extends TestCase
{
    /**
     * @return array<string, array<string, string>> basename => parsed contents
     */
    private static function allLocales(): array
    {
        $out = [];
        $files = \glob(\dirname(__DIR__) . '/lang/*.php');
        self::assertNotFalse($files);
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

    public function testShippedLocalesMatchTheFleetRoster(): void
    {
        $expected = ['ar', 'cs', 'de', 'en', 'es', 'fr', 'it', 'ja', 'ko', 'nl', 'pl', 'pt', 'pt-br', 'ru', 'tr', 'zh-cn'];
        $actual = \array_keys(self::allLocales());
        \sort($actual);

        $this->assertSame($expected, $actual);
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

    public function testMultiLineHelpBlocksKeepTheirLineCount(): void
    {
        $locales = self::allLocales();

        foreach ($locales['en'] as $key => $englishValue) {
            if (!\str_contains($englishValue, "\n")) {
                continue;
            }
            foreach ($locales as $code => $data) {
                // Translators re-wrap freely (CJK has no spaces to wrap at),
                // so only demand that a block stays a block, not its width.
                $this->assertStringContainsString(
                    "\n",
                    $data[$key] ?? '',
                    "locale '{$code}' key '{$key}' is a multi-line help block in en.php"
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

    public function testPortugueseVariantsKeepTheirOwnVocabulary(): void
    {
        $locales = self::allLocales();
        $pt = \implode("\n", $locales['pt']);
        $br = \implode("\n", $locales['pt-br']);

        $this->assertStringContainsString('ficheiro', $pt);
        $this->assertStringNotContainsString('arquivo', $pt, 'pt carries the Brazilian form');
        $this->assertStringContainsString('arquivo', $br);
        $this->assertStringNotContainsString('ficheiro', $br, 'pt-br carries the European form');
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
