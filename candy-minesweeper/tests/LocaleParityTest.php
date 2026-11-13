<?php

declare(strict_types=1);

namespace SugarCraft\Minesweeper\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Locale parity guard: the 15 non-en locales once
 * shipped only the two board.* keys while the six custom.* keys silently
 * fell back to English. en.php is the source of truth — every locale must
 * carry exactly its key set.
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

    public function testEnglishSourceOfTruthCarriesAllKeyFamilies(): void
    {
        $en = require __DIR__ . '/../lang/en.php';
        $this->assertArrayHasKey('board.rows_shape_mismatch', $en);
        foreach (['min_rows', 'min_cols', 'max_rows', 'max_cols', 'min_mines', 'max_mines', 'min_area', 'label'] as $suffix) {
            $this->assertArrayHasKey("custom.$suffix", $en);
        }
        // The ui.* family is what makes MAJOR-2 (i18n dormant) stay fixed:
        // every visible game string must live here, never inline in code.
        foreach (['status', 'boom', 'cleared', 'help_rules', 'help_moves', 'help_board', 'help_session', 'picker_title', 'picker_custom', 'picker_hint', 'form_width', 'form_height', 'form_mines', 'form_hint'] as $suffix) {
            $this->assertArrayHasKey("ui.$suffix", $en);
        }
    }

    /**
     * Roster-count arm: the data provider above can only
     * compare files that exist — deleting a whole locale would silently
     * shrink the shipped set to 15 and every leg would stay green. The
     * shipped count is pinned HERE so a removed (or added) locale file goes
     * red on its own, and the provider roster below names the expected codes.
     */
    #[DataProvider('localeFiles')]
    public function testEveryLocaleCarriesTheEnglishPlaceholderTokens(string $code, string $path): void
    {
        // msw-35 (finding -062): parity of KEYS never implied parity of the
        // {placeholder} slots inside the values. The status line is the
        // most-visible string in the app (mines/flags/remaining/time all
        // interpolate) and a dropped token renders as literal brace garbage
        // every frame with all the old legs green. Prospective guard: all
        // sixteen locales are clean today — deleting any token from any
        // value must redden this test by name.
        $en = require __DIR__ . '/../lang/en.php';
        $locale = require $path;
        foreach ((array) $en as $key => $english) {
            $mine = static function (string $value): array {
                preg_match_all('/\{\w+\}/', $value, $m);
                $tokens = $m[0];
                sort($tokens);
                return $tokens;
            };
            $this->assertSame(
                $mine((string) $english),
                $mine((string) $locale[$key]),
                "locale '$code' must interpolate key '$key' with exactly the english {tokens}",
            );
        }
    }

    public function testTheShippedLocaleRosterIsExactlySixteenFiles(): void
    {
        $codes = array_keys(self::localeFiles());
        sort($codes);
        $this->assertSame([
            'ar', 'cs', 'de', 'en', 'es', 'fr', 'it', 'ja',
            'ko', 'nl', 'pl', 'pt', 'pt-br', 'ru', 'tr', 'zh-cn',
        ], $codes, 'shipped locale roster changed — a count change must be a deliberate edit here');
    }
}
