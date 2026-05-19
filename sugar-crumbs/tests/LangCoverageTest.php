<?php

declare(strict_types=1);

namespace SugarCraft\Crumbs\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Verifies that the sugar-crumbs i18n infrastructure is correctly wired.
 *
 * Unlike other LangCoverageTest implementations in the SugarCraft monorepo,
 * this test does NOT assert that Lang::t() keys exist in src/ because
 * sugar-crumbs's source files are purely computational (no user-facing
 * strings beyond visual glyphs like separator/truncator which are not
 * translatable text). The translation keys in lang/en.php are provided
 * for future use if consumers need customizable separators/truncators.
 *
 * This test verifies:
 * 1. lang/en.php exists and returns an array.
 * 2. All documented translation keys are present in lang/en.php.
 * 3. The Lang facade exists.
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

    public function testLangFacadeExists(): void
    {
        $this->assertFileExists(__DIR__ . '/../src/Lang.php');
    }
}
