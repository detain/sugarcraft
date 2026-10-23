<?php

declare(strict_types=1);

namespace SugarCraft\Markdown\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Locale parity guard (docs-fix-1023 markdown i18n row).
 *
 * Fifteen of the sixteen shipped locales used to carry three of
 * en.php's fifteen keys — the rest silently fell back to English.
 * These pins keep every shipped locale exposing exactly the English
 * key set, every placeholder intact in every translation, and every
 * key actually referenced from src/ (no orphaned or rotting rows).
 */
final class LocaleParityTest extends TestCase
{
    private const LANG_DIR = __DIR__ . '/../lang';

    /** @return array<string, array<string, string>> */
    private static function allLocales(): array
    {
        $files = [];
        foreach (scandir(self::LANG_DIR) ?: [] as $entry) {
            if (str_ends_with($entry, '.php')) {
                $code         = substr($entry, 0, -4);
                $strings      = require self::LANG_DIR . '/' . $entry;
                $files[$code] = is_array($strings) ? $strings : [];
            }
        }
        ksort($files);

        return $files;
    }

    /**
     * @return iterable<string, array{0:string, 1:array<string,string>}>
     */
    public static function localeProvider(): iterable
    {
        foreach (self::allLocales() as $code => $strings) {
            yield $code => [$code, $strings];
        }
    }

    public function testEveryLocaleFileExistsIncludingEnglish(): void
    {
        $files = self::allLocales();
        $this->assertArrayHasKey('en', $files);
        // The shipped locale set: en plus fifteen translations (incl. pt-br).
        $this->assertCount(16, $files, 'unexpected locale file set: ' . implode(',', array_keys($files)));
    }

    /**
     * @dataProvider localeProvider
     * @param array<string,string> $strings
     */
    public function testEveryLocaleCarriesExactlyTheEnglishKeys(string $code, array $strings): void
    {
        $keys = array_keys($strings);
        sort($keys);

        $expected = array_keys(self::allLocales()['en']);
        sort($expected);

        $this->assertSame($expected, $keys, "locale {$code} drifts from the English key set");
    }

    /**
     * @dataProvider localeProvider
     * @param array<string,string> $strings
     */
    public function testEveryLocalePreservesEveryPlaceholder(string $code, array $strings): void
    {
        $english = self::allLocales()['en'];
        foreach ($strings as $key => $value) {
            preg_match_all('/\{([A-Za-z_][A-Za-z_0-9]*)\}/', $value, $mine);
            preg_match_all('/\{([A-Za-z_][A-Za-z_0-9]*)\}/', $english[$key] ?? '', $theirs);
            sort($mine[1]);
            sort($theirs[1]);
            $this->assertSame(
                $theirs[1],
                $mine[1],
                "locale {$code} key {$key} changes the placeholder set",
            );
        }
    }

    /**
     * @dataProvider localeProvider
     * @param array<string,string> $strings
     */
    public function testEveryLocaleValueIsItsOwnLanguage(string $code, array $strings): void
    {
        if ($code === 'en') {
            $this->assertTrue(true, 'en is the source of truth');
            return;
        }
        $english = self::allLocales()['en'];
        $carried = 0;
        foreach ($strings as $key => $value) {
            if ($value !== ($english[$key] ?? null)) {
                $carried++;
            }
        }
        $this->assertGreaterThanOrEqual(
            count($strings) - 2,
            $carried,
            "locale {$code} ships mostly untranslated copies of the English strings",
        );
    }

    public function testEveryEnglishKeyIsReferencedFromSource(): void
    {
        $referenced = [];
        foreach (self::sourceFiles() as $file) {
            preg_match_all('/Lang::t\(\'([A-Za-z_.]+)\'/', (string) file_get_contents($file), $hits);
            foreach ($hits[1] as $key) {
                $referenced[$key] = true;
            }
        }
        ksort($referenced);

        $keys = array_keys(self::allLocales()['en']);
        sort($keys);

        $this->assertSame($keys, array_keys($referenced), 'en.php keys and Lang::t() call sites drifted apart');
    }

    /** @return list<string> every php file under src/ */
    private static function sourceFiles(): array
    {
        $found = [];
        $walk  = static function (string $dir) use (&$walk, &$found): void {
            foreach (scandir($dir) ?: [] as $entry) {
                if ($entry === '.' || $entry === '..') {
                    continue;
                }
                $path = $dir . '/' . $entry;
                if (is_dir($path)) {
                    $walk($path);
                } elseif (str_ends_with($path, '.php')) {
                    $found[] = $path;
                }
            }
        };
        $walk(dirname(__DIR__) . '/src');
        sort($found);

        return $found;
    }
}
