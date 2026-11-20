<?php

declare(strict_types=1);

namespace CandyCode\Bundles\Discord\Tests\I18n;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Catalog parity for the candy-discord bundle's own `lang/` (plugin-owned under O4,
 * plan_plugin.md 4.11): every shipped locale carries exactly the English keys
 * with the same {placeholders}, every literal `channels.discord.*` key the
 * `Lang::t()` call sites ask for exists in English, and every English key is
 * reached by a literal in src/ (no orphaned string).
 */
final class LangParityTest extends TestCase
{
    private const LANG_DIR = __DIR__ . '/../../lang';
    private const SRC_DIR = __DIR__ . '/../../src';
    private const PREFIX = 'channels.discord.';

    /** @return array<string, array{string}> */
    public static function localeProvider(): array
    {
        $cases = [];
        foreach (\glob(self::LANG_DIR . '/*.php') ?: [] as $file) {
            $locale = \basename($file, '.php');
            $cases[$locale] = [$locale];
        }

        return $cases;
    }

    /** @return list<string> */
    private static function sources(): array
    {
        $out = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(self::SRC_DIR, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            if ($file->getExtension() === 'php') {
                $out[] = $file->getPathname();
            }
        }
        \sort($out);

        return $out;
    }

    public function testEnglishIsShipped(): void
    {
        self::assertArrayHasKey('en', self::localeProvider(), 'lang/en.php is the source of truth and must exist');
    }

    #[DataProvider('localeProvider')]
    public function testEveryLocaleCarriesEveryEnglishKey(string $locale): void
    {
        $en = require self::LANG_DIR . '/en.php';
        $translations = require self::LANG_DIR . "/{$locale}.php";

        // Lookup is by key, so declaration order is irrelevant: compare as sets.
        $expected = \array_keys($en);
        $actual = \array_keys($translations);
        \sort($expected);
        \sort($actual);

        self::assertSame($expected, $actual, "lang/{$locale}.php key set must mirror lang/en.php exactly");
    }

    #[DataProvider('localeProvider')]
    public function testPlaceholdersSurviveTranslation(string $locale): void
    {
        $en = require self::LANG_DIR . '/en.php';
        $translations = require self::LANG_DIR . "/{$locale}.php";

        foreach ($en as $key => $english) {
            \preg_match_all('/\{(\w+)\}/', $english, $expected);
            \preg_match_all('/\{(\w+)\}/', (string) ($translations[$key] ?? ''), $actual);
            self::assertSame($expected[1], $actual[1], "placeholder set for '{$key}' differs in lang/{$locale}.php");
        }
    }

    /**
     * The attachment skip notice is read by the user without any other context,
     * so each reason and the unnamed fallback must ride the locale's own words.
     * Parity and placeholder checks cannot see an English value copied into a
     * catalog, so this compares each non-English value with the English one.
     */
    /** @return array<string, array{string}> */
    public static function nonEnglishLocaleProvider(): array
    {
        return \array_filter(self::localeProvider(), static fn (string $locale): bool => $locale !== 'en', \ARRAY_FILTER_USE_KEY);
    }

    #[DataProvider('nonEnglishLocaleProvider')]
    public function testAttachmentSkipPathIsTranslatedOutOfEnglish(string $locale): void
    {
        $en = require self::LANG_DIR . '/en.php';
        $translations = require self::LANG_DIR . "/{$locale}.php";

        $untranslated = [];
        foreach ($en as $key => $english) {
            if (!\str_starts_with($key, self::PREFIX . 'attachments.reason.') && $key !== self::PREFIX . 'attachments.unnamed') {
                continue;
            }
            if (($translations[$key] ?? $english) === $english) {
                $untranslated[] = $key;
            }
        }

        self::assertSame([], $untranslated, "lang/{$locale}.php carries English text for the attachment skip path");
    }

    public function testEveryLiteralKeyAskedBySrcExistsInEnglish(): void
    {
        $en = require self::LANG_DIR . '/en.php';
        $missing = [];
        foreach (self::sources() as $file) {
            \preg_match_all("/Lang::t\\(\\s*'(" . \preg_quote(self::PREFIX, '/') . "[A-Za-z0-9_.]+)'/", (string) \file_get_contents($file), $hits);
            foreach ($hits[1] as $key) {
                if (!\array_key_exists($key, $en)) {
                    $missing[$key] = true;
                }
            }
        }

        self::assertSame([], \array_keys($missing), 'Lang::t() keys in src with no lang/en.php entry');
    }

    public function testEveryEnglishKeyIsReachedByALiteralInSrc(): void
    {
        $en = require self::LANG_DIR . '/en.php';
        $literals = [];
        foreach (self::sources() as $file) {
            \preg_match_all("/'(" . \preg_quote(self::PREFIX, '/') . "[A-Za-z0-9_.]+)'/", (string) \file_get_contents($file), $hits);
            foreach ($hits[1] as $key) {
                $literals[$key] = true;
            }
        }

        $orphans = [];
        foreach (\array_keys($en) as $key) {
            if (!isset($literals[$key])) {
                $orphans[] = $key;
            }
        }

        self::assertSame([], $orphans, 'lang/en.php keys no src file names literally');
    }
}
