<?php

declare(strict_types=1);

namespace SugarCraft\GifView\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Locale parity guard for the "16 locales" promise: all 15 non-en locales
 * once shipped only `cli.usage` + `cli.no_autoload` while the four refusal
 * keys silently fell back to English — and their usage lines predated the
 * --watch / --help flags the CLI actually accepts. en.php is the source of
 * truth: every locale must carry exactly its key set, with name-based
 * placeholder parity (SOV languages may reorder {flag}/{value} inside a
 * sentence; Lang::t substitutes by name, so only the multiset must match).
 */
final class LocaleParityTest extends TestCase
{
    /** @return array<string, array{0: string, 1: string}> */
    public static function localeFiles(): array
    {
        $cases = [];
        foreach (glob(__DIR__ . '/../lang/*.php') ?: [] as $path) {
            $code = basename($path, '.php');
            $cases[$code] = [$code, $path];
        }

        return $cases;
    }

    #[DataProvider('localeFiles')]
    public function testEveryLocaleCarriesExactlyTheEnglishKeySet(string $code, string $path): void
    {
        $en = require __DIR__ . '/../lang/en.php';
        $locale = require $path;
        $this->assertSame(
            array_keys($en),
            array_keys($locale),
            "locale '$code' key set/order must match en.php",
        );
    }

    #[DataProvider('localeFiles')]
    public function testEveryTranslationIsANonEmptyString(string $code, string $path): void
    {
        $locale = require $path;
        foreach ($locale as $key => $value) {
            $this->assertIsString($value, "$code: $key is not a string");
            $this->assertNotSame('', trim($value), "$code: $key is empty");
        }
    }

    #[DataProvider('localeFiles')]
    public function testEveryLocaleCarriesTheEnglishPlaceholders(string $code, string $path): void
    {
        $en = require __DIR__ . '/../lang/en.php';
        $locale = require $path;
        foreach ($en as $key => $english) {
            preg_match_all('/\{(\w+)\}/', $english, $expected);
            preg_match_all('/\{(\w+)\}/', $locale[$key], $actual);
            sort($expected[1]);
            sort($actual[1]);
            $this->assertSame(
                $expected[1],
                $actual[1],
                "locale '$code' key '$key' must use exactly the en placeholders",
            );
        }
    }

    /**
     * The usage string is the flag roster: a locale that omits --watch or
     * --help tells its speakers the flag does not exist.
     */
    #[DataProvider('localeFiles')]
    public function testEveryUsageLineNamesEveryAcceptedFlag(string $code, string $path): void
    {
        $locale = require $path;
        foreach (['--fps=', '--speed=', '--duration=', '--watch', '--help'] as $flag) {
            $this->assertStringContainsString(
                $flag,
                $locale['cli.usage'],
                "locale '$code' cli.usage must mention $flag",
            );
        }
    }

    public function testEnglishSourceOfTruthCarriesTheRefusalKeyFamily(): void
    {
        $en = require __DIR__ . '/../lang/en.php';
        foreach (['cli.usage', 'cli.no_autoload', 'cli.bad_flag', 'cli.bad_value', 'cli.too_many', 'cli.unexpected_value'] as $key) {
            $this->assertArrayHasKey($key, $en);
        }
    }
}
