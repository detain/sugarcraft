<?php

declare(strict_types=1);

namespace SugarCraft\Stickers\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Verifies that the sugar-stickers i18n infrastructure is correctly wired.
 *
 * sugar-stickers is a purely computational layout library (FlexBox, Table)
 * with no user-facing natural-language strings rendered at runtime.
 * This test confirms the infrastructure exists and is loadable;
 * no Lang::t() calls exist in src/ to validate against.
 *
 * This test verifies:
 * 1. lang/en.php exists and returns an array.
 * 2. src/Lang.php exists and is loadable.
 * 3. Lang::t() is callable and returns a string.
 */
final class LangCoverageTest extends TestCase
{
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

    public function testLangFacadeExists(): void
    {
        $this->assertFileExists(__DIR__ . '/../src/Lang.php');
    }

    public function testLangTCallable(): void
    {
        $langFile = __DIR__ . '/../src/Lang.php';
        $this->assertFileExists($langFile);

        require_once $langFile;
        $this->assertTrue(\method_exists(\SugarCraft\Stickers\Lang::class, 't'));
    }

    public function testLangTReturnsString(): void
    {
        require_once __DIR__ . '/../src/Lang.php';
        $result = \SugarCraft\Stickers\Lang::t('nonexistent_key');
        $this->assertIsString($result);
    }
}
