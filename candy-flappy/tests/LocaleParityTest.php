<?php

declare(strict_types=1);

namespace SugarCraft\Flappy\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Locale parity guard (flappy-05, candy-minesweeper shape): en.php is the
 * source of truth — every shipped locale must carry exactly its key set,
 * in order, with non-empty values and the same {placeholder} tokens.
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
        // Every string the renderer assembles must live here, never inline
        // in code (flappy-05): the ui./notty./size. families below are the
        // complete visible surface of src/Renderer.php.
        foreach (
            [
                'score', 'best', 'new_best', 'plain_new_best',
                'crash_full', 'crash_short', 'crash_floor',
                'ready_full', 'ready_short',
                'live_full', 'live_short',
                'plain_crash_full', 'plain_crash_short', 'plain_crash_floor',
                'plain_ready_full', 'plain_ready_short',
                'plain_live_full', 'plain_live_short',
                'quit_hint',
                'quarantine', 'plain_quarantine',
            ] as $suffix
        ) {
            $this->assertArrayHasKey("ui.$suffix", $en);
        }
        $this->assertArrayHasKey('notty.line1', $en);
        $this->assertArrayHasKey('notty.line2', $en);
        $this->assertArrayHasKey('size.notice', $en);
        $this->assertArrayHasKey('size.resume', $en);
    }

    #[DataProvider('localeFiles')]
    public function testEveryLocaleCarriesTheEnglishPlaceholderTokens(string $code, string $path): void
    {
        // Parity of KEYS never implies parity of the {placeholder} slots:
        // a dropped token renders as literal brace garbage on the status
        // row. Deleting any token from any value reddens this test by name.
        $en = require __DIR__ . '/../lang/en.php';
        $locale = require $path;
        $tokens = static function (string $value): array {
            preg_match_all('/\{\w+\}/', $value, $m);
            $found = $m[0];
            sort($found);
            return $found;
        };
        foreach ((array) $en as $key => $english) {
            $this->assertSame(
                $tokens((string) $english),
                $tokens((string) $locale[$key]),
                "locale '$code' must interpolate key '$key' with exactly the english {tokens}",
            );
        }
    }

    public function testTheShippedLocaleRosterIsExactlySixteenFiles(): void
    {
        // The data providers above can only compare files that exist —
        // deleting a whole locale would silently shrink the shipped set and
        // every leg would stay green. The roster is pinned HERE by name.
        $codes = array_keys(self::localeFiles());
        sort($codes);
        $this->assertSame([
            'ar', 'cs', 'de', 'en', 'es', 'fr', 'it', 'ja',
            'ko', 'nl', 'pl', 'pt', 'pt-br', 'ru', 'tr', 'zh-cn',
        ], $codes, 'shipped locale roster changed — a count change must be a deliberate edit here');
    }
}
