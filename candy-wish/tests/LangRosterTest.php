<?php

declare(strict_types=1);

namespace SugarCraft\Wish\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Roster guard for the i18n locale files (audit LOW-16).
 *
 * Every non-en locale must carry EXACTLY the key set of lang/en.php —
 * missing keys silently fall back to English (harmless at runtime but
 * the roster drifts and translators lose track), and extra keys name
 * lookups that no longer exist in en. Placeholders ({reason}, {timeout},
 * …) must match en verbatim per key or interpolation breaks.
 *
 * Mirrors the repo's documentation-drift-guard philosophy: the roster
 * is pinned by the test, not by convention.
 */
final class LangRosterTest extends TestCase
{
    /** @return array<string,array{0:string}> */
    public static function localeProvider(): array
    {
        $cases = [];
        foreach (glob(\dirname(__DIR__) . '/lang/*.php') ?: [] as $file) {
            $code = basename($file, '.php');
            if ($code === 'en') {
                continue;
            }
            $cases[$code] = [$file];
        }
        if (\count($cases) < 15) {
            throw new \LogicException('all fifteen non-en locales must exist, found ' . \count($cases));
        }

        return $cases;
    }

    /**
     * @dataProvider localeProvider
     */
    public function testLocaleCarriesExactlyTheEnglishKeySet(string $file): void
    {
        $en = include \dirname(__DIR__) . '/lang/en.php';
        $loc = include $file;
        $this->assertIsArray($en);
        $this->assertIsArray($loc);

        $missing = array_diff(array_keys($en), array_keys($loc));
        $extra = array_diff(array_keys($loc), array_keys($en));

        $this->assertSame([], $missing, basename($file) . ' is missing keys: ' . implode(', ', $missing));
        $this->assertSame([], $extra, basename($file) . ' carries keys en.php no longer has: ' . implode(', ', $extra));
    }

    /**
     * @dataProvider localeProvider
     */
    public function testPlaceholdersMatchEnglishVerbatim(string $file): void
    {
        $en = include \dirname(__DIR__) . '/lang/en.php';
        $loc = include $file;

        foreach ($en as $key => $englishValue) {
            if (!\is_string($englishValue) || !array_key_exists($key, $loc) || !\is_string($loc[$key])) {
                continue;
            }
            preg_match_all('/\{(\w+)\}/', $englishValue, $expected);
            preg_match_all('/\{(\w+)\}/', $loc[$key], $actual);
            $this->assertSame(
                $expected[1],
                $actual[1],
                \sprintf('%s: placeholder set for "%s" must match en', basename($file), $key),
            );
        }
    }

    public function testEveryEnglishKeyHasANonEmptyValue(): void
    {
        $en = include \dirname(__DIR__) . '/lang/en.php';
        foreach ($en as $key => $value) {
            $this->assertIsString($value, "en.php value for {$key} must be a string");
            $this->assertNotSame('', trim($value), "en.php value for {$key} must not be blank");
        }
    }

    public function testNoEnglishKeyIsDeadInSource(): void
    {
        // LOW-16b discipline: keys without a Lang::t producer drift
        // unnoticed. Scan src/ for every literal key referenced via
        // Lang::t('…') and demand the en roster contains nothing else.
        $en = include \dirname(__DIR__) . '/lang/en.php';
        $used = [];
        $rii = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(\dirname(__DIR__) . '/src'));
        foreach ($rii as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }
            $src = (string) file_get_contents($file->getPathname());
            if (preg_match_all("/Lang::t\(\s*'([^']+)'/", $src, $m)) {
                foreach ($m[1] as $key) {
                    $used[$key] = true;
                }
            }
        }
        $dead = array_diff(array_keys($en), array_keys($used));
        $this->assertSame([], $dead, 'en.php keys with no Lang::t producer in src/: ' . implode(', ', $dead));
    }
}
