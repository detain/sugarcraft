<?php

declare(strict_types=1);

namespace SugarCraft\Mouse\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Mouse\Lang;
use SugarCraft\Mouse\Mark;

/**
 * The per-library i18n facade and its catalogue.
 *
 * PHP fatals at class-compile time when a subclass narrows the visibility
 * of an inherited constant: Lang once declared `private const NAMESPACE`
 * / `DIR` against the base's `protected` ones, which made
 * SugarCraft\Mouse\Lang a hard fatal on load.  These tests make the
 * reference (and therefore the compile) unavoidable.
 *
 * Every user-facing exception message in src/ is routed through
 * {@see Lang::t()}; the catalogue tests below pin that each key used in
 * src/ exists in lang/en.php and that every catalogue entry is used, so a
 * locale pass has the full set to translate and nothing stale.
 */
final class LangTest extends TestCase
{
    private const LANG_DIR = __DIR__ . '/../lang';
    private const SRC_DIR  = __DIR__ . '/../src';

    public function testLangClassLoads(): void
    {
        // class_exists() triggers the autoloader, which compiles
        // src/Lang.php — a visibility narrowing would fatal right here.
        self::assertTrue(class_exists(Lang::class));
    }

    public function testUnknownKeyResolvesToRawNamespacedKey(): void
    {
        // A miss in every locale file returns the raw namespaced key —
        // visibly, loudly, never an exception.
        self::assertSame('mouse.missing.key', Lang::t('missing.key'));
    }

    public function testKnownKeyIsTranslatedAndInterpolated(): void
    {
        $msg = Lang::t('mark.id_too_long', ['max' => 256, 'len' => 300]);

        self::assertSame('Mark id exceeds 256 bytes (got 300).', $msg);
    }

    /**
     * @return list<string>
     */
    private static function keysUsedInSrc(): array
    {
        $keys = [];
        foreach (glob(self::SRC_DIR . '/*.php') ?: [] as $file) {
            preg_match_all("/Lang::t\\('([^']+)'/", (string) file_get_contents($file), $m);
            array_push($keys, ...$m[1]);
        }
        $keys = array_values(array_unique($keys));
        sort($keys);
        return $keys;
    }

    public function testEveryKeyUsedInSrcExistsInEveryLocale(): void
    {
        $used = self::keysUsedInSrc();
        self::assertNotSame([], $used);

        foreach (glob(self::LANG_DIR . '/*.php') ?: [] as $file) {
            $catalogue = require $file;
            foreach ($used as $key) {
                self::assertArrayHasKey($key, $catalogue, basename($file) . ' is missing ' . $key);
            }
        }
    }

    public function testEveryEnglishKeyIsUsedInSrc(): void
    {
        $catalogue = require self::LANG_DIR . '/en.php';
        $keys = array_keys($catalogue);
        sort($keys);

        self::assertSame(self::keysUsedInSrc(), $keys);
    }

    public function testThrownMessageComesFromCatalogue(): void
    {
        try {
            Mark::zone('a b', 'x');
            self::fail('invalid id must throw');
        } catch (\InvalidArgumentException $e) {
            self::assertStringStartsWith('Mark id must match /', $e->getMessage());
            self::assertStringContainsString("got 'a b'", $e->getMessage());
            self::assertStringNotContainsString('{', $e->getMessage());
        }
    }
}
