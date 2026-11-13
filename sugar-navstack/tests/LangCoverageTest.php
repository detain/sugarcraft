<?php

declare(strict_types=1);

namespace SugarCraft\NavStack\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\I18n\Lang as BaseLang;
use SugarCraft\NavStack\Lang;

/**
 * Verifies that the sugar-navstack i18n facade and its lang registry load.
 *
 * Unlike other LangCoverageTest implementations in the SugarCraft monorepo,
 * this test does NOT assert that Lang::t() keys exist in src/ because
 * sugar-navstack's source files are purely computational (no user-facing
 * strings beyond visual glyphs like separator/truncator which are not
 * translatable text). The translation keys in lang/en.php are provided
 * for future use if consumers need customizable separators/truncators.
 *
 * This test verifies:
 * 1. lang/en.php exists and returns an array.
 * 2. All documented translation keys are present in lang/en.php.
 * 3. The Lang facade CLASS resolves, extends the core base, and its
 *    registry can answer a lookup (see {@see testLangFacadeResolves()} —
 *    an assertFileExists on the source path would stay green for a renamed
 *    or broken class, which is exactly what it used to prove nothing about).
 */
final class LangCoverageTest extends TestCase
{
    private static array $translationKeys = [];

    public static function setUpBeforeClass(): void
    {
        $langFile = __DIR__ . '/../lang/en.php';
        $translations = require $langFile;
        \assert(\is_array($translations));
        self::$translationKeys = \array_keys($translations);
    }

    public function testLangFileExists(): void
    {
        $this->assertFileExists(__DIR__ . '/../lang/en.php');
    }

    public function testLangFileReturnsArray(): void
    {
        $langFile = __DIR__ . '/../lang/en.php';
        $result = require $langFile;
        $this->assertIsArray($result);
    }

    public function testSeparatorKeyPresent(): void
    {
        $langFile = __DIR__ . '/../lang/en.php';
        $translations = require $langFile;
        $this->assertArrayHasKey('separator', $translations);
    }

    public function testTruncatorKeyPresent(): void
    {
        $langFile = __DIR__ . '/../lang/en.php';
        $translations = require $langFile;
        $this->assertArrayHasKey('truncator', $translations);
    }

    public function testLangFacadeResolves(): void
    {
        // A loadable class (not a file path): renaming or breaking
        // src/Lang.php must redden this pin, which the file-exists
        // shape it replaces never could.
        $this->assertTrue(\class_exists(Lang::class), 'NavStack Lang facade class must resolve');
        $this->assertTrue(
            \is_subclass_of(Lang::class, BaseLang::class),
            'NavStack Lang facade must extend the core Lang base',
        );

        // Light touch on the public surface: the registry is dormant BY
        // POLICY (Lang.php docblock), so assert only that a lookup answers
        // with a string and does not crash — never the value.
        $answered = Lang::t('separator');
        $this->assertIsString($answered);
        $missing = Lang::t('no-such-key-' . __METHOD__);
        $this->assertIsString($missing, 'Unknown keys must fall back to the raw key string');
    }
}
