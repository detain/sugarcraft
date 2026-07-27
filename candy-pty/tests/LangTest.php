<?php

declare(strict_types=1);

namespace SugarCraft\Pty\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\I18n\T;
use SugarCraft\Pty\Lang;

final class LangTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        T::reset();
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        T::reset();
    }

    public function testNamespaceIsPty(): void
    {
        $reflection = new \ReflectionClass(Lang::class);
        $const = $reflection->getConstant('NAMESPACE');
        $this->assertSame('pty', $const);
    }

    public function testDirPointsToLangFolderInCandyPty(): void
    {
        $reflection = new \ReflectionClass(Lang::class);
        $dirConst = $reflection->getConstant('DIR');

        // DIR is defined as __DIR__ . '/../lang' relative to src/Lang.php
        // When Lang.php is at src/Lang.php, __DIR__ is src/, so src/../lang = lang/
        $expectedResolved = dirname($reflection->getFileName()) . '/../lang';
        $this->assertSame($expectedResolved, $dirConst);
        $this->assertFileExists($dirConst . '/en.php');
    }

    public function testTReturnsTranslationFromLangFile(): void
    {
        T::reset();
        // Lang::t() internally registers the 'pty' namespace using Lang::DIR
        // which points to the candy-pty/lang directory
        $result = Lang::t('open.posix_openpt_failed', ['rc' => -1, 'errno' => 5]);
        $this->assertStringContainsString('posix_openpt', $result);
        $this->assertStringContainsString('-1', $result);
        $this->assertStringContainsString('5', $result);
    }

    public function testTWithMissingKeyReturnsKey(): void
    {
        T::reset();
        // When key doesn't exist, T::translate returns the raw key
        $result = Lang::t('nonexistent.key', ['name' => 'World']);
        $this->assertSame('pty.nonexistent.key', $result);
    }

    public function testExtendsBaseLang(): void
    {
        $this->assertInstanceOf(\SugarCraft\Core\I18n\Lang::class, new Lang());
    }

    public function testTWithPlaceholderSubstitution(): void
    {
        T::reset();
        // The en.php file has placeholders like {rc} and {errno}
        $result = Lang::t('open.posix_openpt_failed', ['rc' => 42, 'errno' => 5]);
        $this->assertStringContainsString('42', $result);
        $this->assertStringContainsString('5', $result);
    }
}
