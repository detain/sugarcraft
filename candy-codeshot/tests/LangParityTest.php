<?php

declare(strict_types=1);

namespace SugarCraft\CodeShot\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\I18n\T;

/**
 * Locale-catalog parity guard for candy-codeshot.
 *
 * Same genre as the candy-code LangParityTest: every locale shipped in
 * lang/ must mirror the English key set exactly (bidirectional), every
 * placeholder set must match per key, and every Lang::t() call site in
 * src/ plus bin/candy-codeshot must name a key the English catalog
 * actually carries. A message added to bin/ without the matching lang/
 * edit goes red here.
 */
final class LangParityTest extends TestCase
{
    private const LANG_DIR = __DIR__ . '/../lang';

    private ?string $previousLocale = null;

    protected function setUp(): void
    {
        $this->previousLocale = T::locale();
        T::setLocale('en');
    }

    protected function tearDown(): void
    {
        if ($this->previousLocale !== null) {
            T::setLocale($this->previousLocale);
        }
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function localeProvider(): array
    {
        $cases = [];
        foreach (glob(self::LANG_DIR . '/*.php') ?: [] as $file) {
            $cases[basename($file, '.php')] = [basename($file, '.php')];
        }

        return $cases;
    }

    public function testEnglishCatalogCarriesTheSeventeenCliKeys(): void
    {
        $en = require self::LANG_DIR . '/en.php';
        $this->assertCount(17, $en);
        $this->assertArrayHasKey('cli.usage', $en);
        foreach (['missing_value', 'bad_number', 'png_ignores', 'highlight_rejected', 'format_mismatch'] as $key) {
            $this->assertArrayHasKey('cli.' . $key, $en, "new bin message '$key' must live in en.php");
        }
    }

    /**
     * @dataProvider localeProvider
     */
    public function testEveryLocaleMirrorsTheEnglishKeySet(string $locale): void
    {
        $en  = require self::LANG_DIR . '/en.php';
        $cat = require self::LANG_DIR . '/' . $locale . '.php';

        $missing = array_diff(array_keys($en), array_keys($cat));
        $extra   = array_diff(array_keys($cat), array_keys($en));
        $this->assertSame([], $missing, "locale '$locale' is missing keys");
        $this->assertSame([], $extra, "locale '$locale' carries keys English does not have");
    }

    /**
     * @dataProvider localeProvider
     */
    public function testPlaceholdersMatchEnglishPerKey(string $locale): void
    {
        $en  = require self::LANG_DIR . '/en.php';
        $cat = require self::LANG_DIR . '/' . $locale . '.php';

        foreach ($en as $key => $english) {
            $expected = self::placeholders($english);
            $actual   = self::placeholders($cat[$key]);
            $this->assertSame(
                $expected,
                $actual,
                "locale '$locale' key '$key' must interpolate exactly [" . implode(', ', $expected) . ']',
            );
        }
    }

    /**
     * @dataProvider localeProvider
     */
    public function testEveryLocaleValueIsNonEmptyString(string $locale): void
    {
        $cat = require self::LANG_DIR . '/' . $locale . '.php';
        foreach ($cat as $key => $value) {
            $this->assertIsString($value, "$locale/$key must be a string");
            $this->assertNotSame('', trim($value), "$locale/$key must not be blank");
        }
    }

    public function testEveryCallSiteKeyExistsInTheEnglishCatalog(): void
    {
        $en = require self::LANG_DIR . '/en.php';

        $files = array_merge(
            glob(__DIR__ . '/../src/*.php') ?: [],
            glob(__DIR__ . '/../src/*/*.php') ?: [],
            [__DIR__ . '/../bin/candy-codeshot'],
        );

        $used = [];
        foreach ($files as $file) {
            $source = file_get_contents($file);
            $this->assertIsString($source, "unreadable census source: $file");
            preg_match_all("/Lang::t\(\s*'([^']+)'/", $source, $matches);
            foreach ($matches[1] as $key) {
                $used[$key] = true;
            }
        }

        $this->assertNotEmpty($used, 'the census must find Lang::t call sites');
        foreach (array_keys($used) as $key) {
            $this->assertArrayHasKey($key, $en, "call site asks for '$key' but en.php does not define it");
        }
    }

    public function testUnknownKeyFallsBackToTheRawNamespacedKey(): void
    {
        // The documented T contract: a missing translation surfaces visibly
        // as the raw key rather than throwing or blanking the message.
        $this->assertSame(
            'codeshot.cli.no.such.key',
            \SugarCraft\CodeShot\Lang::t('cli.no.such.key'),
        );
    }

    /**
     * @return list<string>
     */
    private static function placeholders(string $value): array
    {
        preg_match_all('/\{(\w+)\}/', $value, $matches);
        $found = array_values(array_unique($matches[1]));
        sort($found);

        return $found;
    }
}
