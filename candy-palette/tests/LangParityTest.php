<?php

declare(strict_types=1);

namespace SugarCraft\Palette\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Locale parity guard (audit #4): every shipped locale must cover every key
 * the src actually asks Lang::t() for, with the same {placeholders} as en.
 */
final class LangParityTest extends TestCase
{
    private const LOCALES = [
        'ar', 'cs', 'de', 'en', 'es', 'fr', 'it', 'ja', 'ko',
        'nl', 'pl', 'pt-br', 'pt', 'ru', 'tr', 'zh-cn',
    ];

    public static function localeProvider(): array
    {
        $cases = [];
        foreach (self::LOCALES as $locale) {
            $cases[$locale] = [$locale];
        }
        return $cases;
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('localeProvider')]
    public function testEveryLocaleCarriesEveryEnglishKey(string $locale): void
    {
        $en = require __DIR__ . '/../lang/en.php';
        $translations = require __DIR__ . "/../lang/{$locale}.php";

        // Lookup is by key, so declaration order is irrelevant — compare as sets.
        $expected = array_keys($en);
        $actual = array_keys($translations);
        sort($expected);
        sort($actual);

        $this->assertSame(
            $expected,
            $actual,
            "lang/{$locale}.php key set must mirror lang/en.php exactly",
        );
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('localeProvider')]
    public function testPlaceholdersSurviveTranslation(string $locale): void
    {
        $en = require __DIR__ . '/../lang/en.php';
        $translations = require __DIR__ . "/../lang/{$locale}.php";

        foreach ($en as $key => $english) {
            preg_match_all('/\{(\w+)\}/', $english, $expected);
            preg_match_all('/\{(\w+)\}/', $translations[$key], $actual);
            $this->assertSame(
                $expected[1],
                $actual[1],
                "placeholder set for '{$key}' differs in lang/{$locale}.php",
            );
        }
    }

    public function testEveryKeyAskedBySrcExistsInEnglish(): void
    {
        $en = require __DIR__ . '/../lang/en.php';
        $asked = [];

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(__DIR__ . '/../src', \FilesystemIterator::SKIP_DOTS),
        );
        foreach ($iterator as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            preg_match_all("/Lang::t\(\s*'([^']+)'/", (string) file_get_contents($file->getPathname()), $hits);
            foreach ($hits[1] as $key) {
                $asked[$key] = true;
            }
        }

        $this->assertNotEmpty($asked, 'census would be vacuous if src asked for no keys');
        foreach (array_keys($asked) as $key) {
            $this->assertArrayHasKey($key, $en, "Lang::t('{$key}') in src has no en.php entry");
        }
    }
}
