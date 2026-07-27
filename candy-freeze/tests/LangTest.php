<?php

declare(strict_types=1);

namespace SugarCraft\Freeze\Tests;

use SugarCraft\Freeze\Lang;
use PHPUnit\Framework\TestCase;

final class LangTest extends TestCase
{
    public function testNamespaceIsFreeze(): void
    {
        $reflection = new \ReflectionClass(Lang::class);
        $constant = $reflection->getConstant('NAMESPACE');
        $this->assertSame('freeze', $constant);
    }

    public function testDirPointsToLangDirectory(): void
    {
        $reflection = new \ReflectionClass(Lang::class);
        $constant = $reflection->getConstant('DIR');

        $this->assertSame(realpath(__DIR__ . '/../lang'), realpath($constant));
    }

    public function testDirExists(): void
    {
        $reflection = new \ReflectionClass(Lang::class);
        $constant = $reflection->getConstant('DIR');

        $this->assertDirectoryExists($constant);
    }

    public function testEnLocaleExists(): void
    {
        $reflection = new \ReflectionClass(Lang::class);
        $constant = $reflection->getConstant('DIR');

        $enFile = $constant . '/en.php';
        $this->assertFileExists($enFile);
    }

    public function testLangExtendsBaseLang(): void
    {
        $lang = new Lang();
        $this->assertInstanceOf(\SugarCraft\Core\I18n\Lang::class, $lang);
    }

    public function testLangClassIsFinal(): void
    {
        $reflection = new \ReflectionClass(Lang::class);
        $this->assertTrue($reflection->isFinal());
    }

    public function testTranslationLookupReturnsString(): void
    {
        $lang = new Lang();
        $result = $lang->t('app.title');

        $this->assertIsString($result);
    }

    public function testTranslationLookupFallsBackToRawKeyWhenNotFound(): void
    {
        $lang = new Lang();
        $result = $lang->t('nonexistent.translation.key');

        $this->assertSame('freeze.nonexistent.translation.key', $result);
    }

    public function testTranslationWithPlaceholders(): void
    {
        $lang = new Lang();
        // The freeze.lang.not_found key might exist or fall back
        $result = $lang->t('app.title', ['name' => 'Test']);

        $this->assertIsString($result);
    }
}
