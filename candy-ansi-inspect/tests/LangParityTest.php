<?php

declare(strict_types=1);

namespace SugarCraft\AnsiInspect\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Catalogue-parity guard for the `lang/` directory (step AIP-18).
 *
 * WHY: the README advertises sixteen shipped catalogues whose keys and
 * `{placeholder}` tokens mirror `en.php`, and the CLI routes both
 * tool-printed messages through `Lang::t()` in the caller's locale.
 * None of that was pinned: a dropped key, a renamed placeholder, or a
 * locale file that stopped returning a string all used to stay green —
 * the lookup chain (exact → base → `en`) quietly hid drift until a
 * user hit the raw key. This test re-derives the roster from disk, so
 * adding a locale (or amputating a key from one) fails here with the
 * exact file and key named. Placeholder discipline is checked per key
 * because `Lang::t()` interpolates by token: `{path}` renamed to
 * `{file}` in a translation would interpolate to nothing.
 */
final class LangParityTest extends TestCase
{
    /** @return array<string, string> */
    private static function load(string $code): array
    {
        $path = dirname(__DIR__) . '/lang/' . $code . '.php';
        self::assertFileExists($path, 'locale catalogue missing: ' . $path);

        $catalogue = require $path;
        self::assertIsArray($catalogue, 'catalogue must return an array: ' . $code . '.php');

        return $catalogue;
    }

    /** @return list<string> */
    private static function placeholders(string $value): array
    {
        preg_match_all('/\{([A-Za-z0-9_]+)\}/', $value, $matches);
        $tokens = $matches[1];
        sort($tokens);

        return $tokens;
    }

    public function testEveryCatalogueMirrorsTheEnglishKeySet(): void
    {
        $files = glob(dirname(__DIR__) . '/lang/*.php');
        self::assertIsArray($files);
        self::assertNotEmpty($files);

        $english = self::load('en');
        $expected = array_keys($english);
        sort($expected);

        foreach ($files as $path) {
            $code = basename($path, '.php');
            $catalogue = self::load($code);
            $actual = array_keys($catalogue);
            sort($actual);

            self::assertSame(
                $expected,
                $actual,
                "catalogue {$code}.php key set diverges from en.php",
            );
            foreach ($catalogue as $key => $value) {
                self::assertIsString($value, "{$code}.php[{$key}] must be a string");
                self::assertNotSame('', $value, "{$code}.php[{$key}] must not be empty");
            }
        }
    }

    public function testPlaceholderTokensMatchEnglishPerKey(): void
    {
        $english = self::load('en');

        foreach (glob(dirname(__DIR__) . '/lang/*.php') ?: [] as $path) {
            $code = basename($path, '.php');
            $catalogue = self::load($code);
            foreach ($english as $key => $englishValue) {
                self::assertSame(
                    self::placeholders($englishValue),
                    self::placeholders((string) $catalogue[$key]),
                    "catalogue {$code}.php[{$key}] placeholder tokens diverge from en.php",
                );
            }
        }
    }

    public function testTranslationsAreNotSilentEnglishCopies(): void
    {
        // Guard the transport, not the taste: at least the non-English
        // catalogues must have moved *some* string, or the sweep above
        // could pass while the whole directory fossilised into English.
        $english = self::load('en');

        foreach (glob(dirname(__DIR__) . '/lang/*.php') ?: [] as $path) {
            $code = basename($path, '.php');
            if ($code === 'en') {
                continue;
            }
            $catalogue = self::load($code);
            $differs = false;
            foreach ($english as $key => $value) {
                if ((string) $catalogue[$key] !== (string) $value) {
                    $differs = true;
                    break;
                }
            }
            self::assertTrue($differs, "catalogue {$code}.php is a byte-copy of en.php");
        }
    }
}
