<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SugarCraft\Core\I18n\T;
use SugarCraft\Crush\Config\Settings\SettingCategory;
use SugarCraft\Crush\Lang;

/**
 * Locale parity guard for the `crush` namespace (audit 15b-14, step
 * 15b-14-1): every shipped locale carries every English key with the same
 * {placeholders}, every key src asks Lang::t() for exists in English, and the
 * facade resolves through candy-core's registry.
 *
 * The locale set is the `lang/` directory itself rather than a typed list, so
 * a translation added later is checked the moment its file lands.
 */
final class LangParityTest extends TestCase
{
    private const LANG_DIR = __DIR__ . '/../lang';

    private string $locale;

    protected function setUp(): void
    {
        // English is what these assertions read; a developer's LANG must not
        // decide them, and the registry is process-global, so restore it.
        $this->locale = T::locale();
        T::setLocale('en');
    }

    protected function tearDown(): void
    {
        T::setLocale($this->locale);
    }

    /** @return array<string, array{string}> */
    public static function localeProvider(): array
    {
        $cases = [];
        foreach (glob(self::LANG_DIR . '/*.php') ?: [] as $file) {
            $locale = basename($file, '.php');
            $cases[$locale] = [$locale];
        }

        return $cases;
    }

    public function testEnglishIsShipped(): void
    {
        $this->assertArrayHasKey('en', self::localeProvider(), 'lang/en.php is the source of truth and must exist');
    }

    #[DataProvider('localeProvider')]
    public function testEveryLocaleCarriesEveryEnglishKey(string $locale): void
    {
        $en = require self::LANG_DIR . '/en.php';
        $translations = require self::LANG_DIR . "/{$locale}.php";

        // Lookup is by key, so declaration order is irrelevant — compare as sets.
        $expected = array_keys($en);
        $actual = array_keys($translations);
        sort($expected);
        sort($actual);

        $this->assertSame($expected, $actual, "lang/{$locale}.php key set must mirror lang/en.php exactly");
    }

    #[DataProvider('localeProvider')]
    public function testPlaceholdersSurviveTranslation(string $locale): void
    {
        $en = require self::LANG_DIR . '/en.php';
        $translations = require self::LANG_DIR . "/{$locale}.php";

        foreach ($en as $key => $english) {
            preg_match_all('/\{(\w+)\}/', $english, $expected);
            preg_match_all('/\{(\w+)\}/', (string) ($translations[$key] ?? ''), $actual);
            $this->assertSame($expected[1], $actual[1], "placeholder set for '{$key}' differs in lang/{$locale}.php");
        }
    }

    /**
     * The census is empty until the W11 steps route strings through Lang::t(),
     * so it asserts the MISSING set rather than walking an empty loop: a
     * literal key with no English entry is named, and none passes.
     */
    public function testEveryKeyAskedBySrcExistsInEnglish(): void
    {
        $en = require self::LANG_DIR . '/en.php';
        $missing = [];

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(__DIR__ . '/../src', \FilesystemIterator::SKIP_DOTS),
        );
        foreach ($iterator as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            preg_match_all("/Lang::t\\(\\s*'([^']+)'/", (string) file_get_contents($file->getPathname()), $hits);
            foreach ($hits[1] as $key) {
                if (!array_key_exists($key, $en)) {
                    $missing[$key] = true;
                }
            }
        }

        $this->assertSame([], array_keys($missing), 'Lang::t() keys in src with no lang/en.php entry');
    }

    /**
     * The seeded keys are the ones the settings schema already names (D7), and
     * each must still say what the literal label says — so moving
     * SettingCategory::label() onto Lang::t() is a no-op in English.
     */
    public function testSettingCategoryLabelKeysResolveToTheirEnglishLabels(): void
    {
        foreach (SettingCategory::cases() as $category) {
            $this->assertSame($category->label(), Lang::t($category->labelKey()), "{$category->labelKey()} drifted from its label");
        }
    }

    public function testAnUnknownKeyFallsBackToTheNamespacedRawKey(): void
    {
        $this->assertSame('crush.no.such.key', Lang::t('no.such.key'));
    }

    public function testParamsInterpolateEvenIntoTheFallback(): void
    {
        $this->assertSame('crush.hello Ada', Lang::t('hello {name}', ['name' => 'Ada']));
    }
}
