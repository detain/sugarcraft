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

    public function testEnglishCatalogCarriesTheTwentyFiveCliKeys(): void
    {
        $en = require self::LANG_DIR . '/en.php';
        $this->assertCount(25, $en);
        $this->assertArrayHasKey('cli.usage', $en);
        foreach (['missing_value', 'bad_number', 'png_ignores', 'highlight_rejected', 'format_mismatch', 'input_too_large', 'render_failed', 'pixels_exceeded', 'font_too_large', 'read_failed_stdin'] as $key) {
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
     * @dataProvider localeProvider
     */
    public function testExitTwoCauseListIsCompleteInEveryLocale(string $locale): void
    {
        // candy-codeshot-020 (cs-15): every non-English locale shipped the
        // exit-2 explanation truncated to three causes plus a literal "...",
        // hiding exactly the actionable cases (missing value, bad number,
        // missing ext-gd, PNG-to-terminal) behind an ellipsis. English is
        // the canon: a locale line may name its causes in its own words and
        // order, but it must carry at least as many comma-separated causes
        // as English and must never hide one behind "..." or "…".
        $en  = require self::LANG_DIR . '/en.php';
        $cat = require self::LANG_DIR . '/' . $locale . '.php';

        $causes = static function (string $usage): string {
            if (!preg_match('/^  2\b.*(?:\n {5,}\S.*)*/m', $usage, $m)) {
                return '';
            }
            $line = trim(preg_replace('/\s*\n\s*/', ' ', $m[0]) ?? $m[0]);
            $open  = strpos($line, '(');
            $close = strrpos($line, ')');
            return $open !== false && $close !== false && $close > $open
                ? (string) substr($line, $open + 1, $close - $open - 1)
                : $line;
        };

        $split = static fn (string $body): array => array_values(array_filter(
            array_map('trim', preg_split('/[,，、،]/', $body) ?: []),
            static fn (string $token): bool => $token !== '',
        ));

        $enCount = count($split($causes($en['cli.usage'])));
        $this->assertGreaterThanOrEqual(7, $enCount, 'English itself must keep the full seven-cause list');

        $tokens = $split($causes($cat['cli.usage']));
        $this->assertGreaterThanOrEqual(
            $enCount,
            count($tokens),
            "locale '$locale' exit-2 line must name every English cause (>= $enCount), got: " . implode(' | ', $tokens),
        );
        foreach ($tokens as $token) {
            $this->assertFalse(
                (bool) preg_match('/\.\.\.|…/', $token),
                "locale '$locale' must not hide exit-2 causes behind an ellipsis",
            );
        }
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
