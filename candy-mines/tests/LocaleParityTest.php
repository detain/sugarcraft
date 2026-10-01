<?php

declare(strict_types=1);

namespace SugarCraft\Mines\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Locale parity guard for the audit M2 finding: the 15 non-en locales once
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

    public function testEnglishSourceOfTruthCarriesBothKeyFamilies(): void
    {
        $en = require __DIR__ . '/../lang/en.php';
        $this->assertArrayHasKey('board.rows_shape_mismatch', $en);
        foreach (['min_rows', 'min_cols', 'max_rows', 'max_cols', 'min_mines', 'max_mines'] as $suffix) {
            $this->assertArrayHasKey("custom.$suffix", $en);
        }
    }
}
