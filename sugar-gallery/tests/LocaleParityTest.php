<?php

declare(strict_types=1);

namespace SugarCraft\Gallery\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Locale parity guard for the docs-review-1023 M2 finding: lang/ once shipped
 * ONLY en.php with zero Lang::t callers anywhere — a dormant phantom i18n
 * surface. The family is now wired (src/Lang.php + Rail's empty-state lookup)
 * and backfilled to the 16-locale set the fleet ships; en.php is the source
 * of truth — every locale must carry exactly its key set, in order.
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

    public function testTheSixteenLocaleFamilyIsPresent(): void
    {
        self::assertSame(
            ['ar', 'cs', 'de', 'en', 'es', 'fr', 'it', 'ja', 'ko', 'nl', 'pl', 'pt-br', 'pt', 'ru', 'tr', 'zh-cn'],
            array_keys(self::localeFiles()),
            'the shipped locale set drifted (add the file or retire it in all places at once)',
        );
    }

    #[DataProvider('localeFiles')]
    public function testEveryLocaleCarriesExactlyTheEnglishKeySet(string $code, string $path): void
    {
        $en = require __DIR__ . '/../lang/en.php';
        $locale = require $path;
        self::assertSame(
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
            self::assertIsString($value, "$code: $key is not a string");
            self::assertNotSame('', trim($value), "$code: $key is empty");
        }
    }

    public function testEnglishSourceOfTruthCarriesTheRailEmptyKey(): void
    {
        $en = require __DIR__ . '/../lang/en.php';
        self::assertSame(['rail.empty'], array_keys($en), 'en.php is the key-set source of truth');
        self::assertSame('(no items)', $en['rail.empty']);
    }
}
