<?php

declare(strict_types=1);

namespace SugarCraft\GifView\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Locale parity guard for the "16 locales" promise: all 15 non-en locales
 * once shipped only `cli.usage` + `cli.no_autoload` while the refusal keys
 * silently fell back to English — and their usage lines predated the
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
     * --help tells its speakers the flag does not exist. The roster is
     * DERIVED from the parser source (m2: a literal list here meant the
     * next accepted flag could slip through 16 locales again) — value
     * flags must appear with their `=`, bare flags by name.
     */
    #[DataProvider('localeFiles')]
    public function testEveryUsageLineNamesEveryAcceptedFlag(string $code, string $path): void
    {
        $locale = require $path;
        foreach (self::derivedFlagSpellings() as $spelling) {
            $this->assertStringContainsString(
                $spelling,
                $locale['cli.usage'],
                "locale '$code' cli.usage must mention $spelling (derived from src/Cli.php)",
            );
        }
    }

    /**
     * gifview-010 rider: the usage line names the binary exactly once —
     * `用法：candy-gifview candy-gifview <gif> …` in ja/zh-cn duplicated the
     * command after the colon where every other locale says it once. A
     * duplicated literal here is transcription noise, not copy.
     */
    #[DataProvider('localeFiles')]
    public function testEveryUsageLineNamesTheBinaryExactlyOnce(string $code, string $path): void
    {
        $locale = require $path;
        $this->assertSame(
            1,
            substr_count($locale['cli.usage'], 'candy-gifview'),
            "locale '$code' cli.usage must name the binary once, not mirror it",
        );
    }

    /**
     * Every refusal key the code actually speaks must exist in en.php, and
     * en.php may not carry undisclosed silent keys either (bidirectional
     * census — the fleet REVERSE/PARITY genre). The sole tolerated
     * call-site-free key is cli.no_autoload, printed by the bin shim before
     * autoload exists, where translation cannot run (disclosed in README).
     */
    public function testEnglishSourceOfTruthCarriesExactlyTheSpokenKeyFamily(): void
    {
        $en = require __DIR__ . '/../lang/en.php';
        $spoken = self::derivedSpokenKeys();

        $this->assertGreaterThanOrEqual(
            14,
            count($spoken),
            'the Lang::t call-site derivation went blind — the source shape changed',
        );

        foreach ($spoken as $key) {
            $this->assertArrayHasKey($key, $en, "src/ speaks $key but en.php does not carry it");
        }

        $silent = array_diff(array_keys($en), $spoken);
        $this->assertSame(
            ['cli.no_autoload'],
            array_values($silent),
            'en.php grew a key no code path prints (or lost the disclosed no_autoload exception)',
        );
    }

    /**
     * Long-option spellings Cli::main() accepts, derived from its source:
     * the allow-list (value + bare flags), the value-taking switch cases
     * (which the usage line must show with `=`), and the literal
     * `=== '--x'` short-circuits (--help/--version).
     *
     * @return list<string>
     */
    private static function derivedFlagSpellings(): array
    {
        $src = self::cliSource();

        self::assertSame(
            1,
            preg_match("/in_array\(\\\$flag, \[([^\]]+)\], true\)/", $src, $allow),
            'the accepted-flag allow-list shape changed — update the derivation, not the roster',
        );
        preg_match_all("/'([a-z-]+)'/", $allow[1], $names);

        preg_match_all("/=== '--([a-z-]+)'/", $src, $short);
        self::assertGreaterThanOrEqual(2, count($short[1]), 'the --help/--version literals went unhidden');

        preg_match_all("/case '([a-z-]+)':/", $src, $valueCases);
        $takesValue = array_intersect($names[1], $valueCases[1]);
        self::assertCount(3, $takesValue, 'exactly fps/speed/duration consume values');

        $spellings = [];
        foreach ($names[1] as $flag) {
            $spellings[] = in_array($flag, $takesValue, true) ? "--$flag=" : "--$flag";
        }
        foreach ($short[1] as $flag) {
            $spellings[] = "--$flag";
        }

        $spellings = array_values(array_unique($spellings));
        sort($spellings);

        return $spellings;
    }

    /**
     * Every literal Lang::t('cli.…') key asked for anywhere under src/.
     *
     * @return list<string>
     */
    private static function derivedSpokenKeys(): array
    {
        $keys = [];
        $files = array_merge(
            (array) glob(__DIR__ . '/../src/*.php'),
            (array) glob(__DIR__ . '/../src/*/*.php'),
        );
        foreach ($files as $file) {
            $src = (string) file_get_contents((string) $file);
            if (preg_match_all("/Lang::t\\('((?:cli|lang)\\.[a-z_]+)'/", $src, $m)) {
                $keys = array_merge($keys, $m[1]);
            }
        }

        $keys = array_values(array_unique($keys));
        sort($keys);

        return $keys;
    }

    private static function cliSource(): string
    {
        $src = file_get_contents(__DIR__ . '/../src/Cli.php');
        self::assertIsString($src);

        return $src;
    }
}
