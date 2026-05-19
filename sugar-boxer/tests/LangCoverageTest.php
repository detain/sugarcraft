<?php

declare(strict_types=1);

namespace SugarCraft\Boxer\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Verifies that the sugar-boxer i18n infrastructure is correctly wired.
 *
 * Unlike other LangCoverageTest implementations in the SugarCraft monorepo,
 * this test does NOT assert that Lang::t() keys exist in src/ because
 * sugar-boxer's source files are purely computational (no user-facing
 * strings). The translation keys in lang/en.php are provided for future
 * use when alignment labels need to be displayed to end users.
 *
 * This test verifies:
 * 1. lang/en.php exists and returns an array.
 * 2. All documented translation keys are present in lang/en.php.
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

    public function testAllHorizontalAlignKeysPresent(): void
    {
        $langFile = __DIR__ . '/../lang/en.php';
        $translations = require $langFile;
        $this->assertArrayHasKey('align.left',   $translations);
        $this->assertArrayHasKey('align.center', $translations);
        $this->assertArrayHasKey('align.right',  $translations);
    }

    public function testAllVerticalAlignKeysPresent(): void
    {
        $langFile = __DIR__ . '/../lang/en.php';
        $translations = require $langFile;
        $this->assertArrayHasKey('valign.top',    $translations);
        $this->assertArrayHasKey('valign.middle', $translations);
        $this->assertArrayHasKey('valign.bottom', $translations);
    }

    public function testLangFacadeExists(): void
    {
        $this->assertFileExists(__DIR__ . '/../src/Lang.php');
    }
}
