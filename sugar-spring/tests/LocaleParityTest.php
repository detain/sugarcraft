<?php

declare(strict_types=1);

namespace SugarCraft\Spring\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Locale parity guard (docs-fix-1023 spring i18n genre).
 *
 * `Spring` threw `Lang::t()` messages while `Projectile` threw raw English —
 * half-landed i18n. These pins keep every shipped locale exposing exactly
 * the English key set, every placeholder intact in every translation, and
 * every key actually referenced from src/ (no orphaned or rotting rows).
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

    /**
     * SPR-28 (finding 062): the raw-English guard used to scan Projectile.php
     * with a double-quoted pattern only — a single-quoted thrower anywhere
     * else in src/ sailed through. Now: every src file, both quote styles,
     * any Exception/Error class. P2's claim ("all throws already translated")
     * is what this guard now enforces tree-wide instead of at one door; the
     * offender list must stay EMPTY — if a future change adds one, the
     * message names file:line. (trigger_error deprecation strings are NOT
     * throws and stay exempt by the documented 055 ruling; if that exemption
     * is ever reversed, these quote-style arms already cover it.)
     */
    public function testFailFastGuardsAcrossSrcAreTranslatedNotRawEnglish(): void
    {
        // The original Projectile arms, kept: the half-landed i18n sin this
        // genre was born from must stay pinned at its door.
        $projectile = (string) file_get_contents(dirname(__DIR__) . '/src/Projectile.php');

        $this->assertStringContainsString("Lang::t('projectile.dt_finite'", $projectile);
        $this->assertStringContainsString("Lang::t('projectile.negative_dt'", $projectile);
        $this->assertStringContainsString("Lang::t('projectile.components_finite'", $projectile);

        $root      = dirname(__DIR__);
        $offenders = [];
        foreach (self::sourceFiles() as $file) {
            $src = (string) file_get_contents($file);
            if (preg_match_all('/new\s+\\\\?\w*(?:Exception|Error)\s*\(\s*([\'"])/', $src, $hits, PREG_OFFSET_CAPTURE)) {
                foreach ($hits[0] as $hit) {
                    $line      = substr_count(substr($src, 0, $hit[1]), "\n") + 1;
                    $offenders[] = substr($file, strlen($root) + 1) . ':' . $line . ' -> ' . $hit[0];
                }
            }
        }

        $this->assertSame(
            [],
            $offenders,
            'raw-English throw constructions route around the 16-locale fan-out; wrap the message in Lang::t',
        );
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
                } elseif (str_ends_with($entry, '.php')) {
                    $found[] = $path;
                }
            }
        };
        $walk(dirname(__DIR__) . '/src');

        return $found;
    }
}
