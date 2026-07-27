<?php

declare(strict_types=1);

namespace SugarCraft\Bits\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Bits\Lang;
use SugarCraft\Core\I18n\Lang as BaseLang;

final class LangTest extends TestCase
{
    public function testClassExists(): void
    {
        $this->assertTrue(class_exists(Lang::class));
    }

    public function testLangIsFinal(): void
    {
        $reflection = new \ReflectionClass(Lang::class);
        $this->assertTrue($reflection->isFinal());
    }

    public function testExtendsBaseLang(): void
    {
        $reflection = new \ReflectionClass(Lang::class);
        $this->assertSame(BaseLang::class, $reflection->getParentClass()->getName());
    }

    public function testNamespaceConstant(): void
    {
        $reflection = new \ReflectionClass(Lang::class);
        $this->assertSame('bits', $reflection->getConstant('NAMESPACE'));
    }

    public function testDirConstant(): void
    {
        $reflection = new \ReflectionClass(Lang::class);
        $dir = $reflection->getConstant('DIR');
        $this->assertStringContainsString('lang', $dir);
        $this->assertStringContainsString('sugar-bits', $dir);
    }

    public function testCanBeInstantiated(): void
    {
        $lang = new Lang();
        $this->assertInstanceOf(Lang::class, $lang);
    }

    public function testTranslateKnownKey(): void
    {
        $result = Lang::t('progress.width_nonneg');
        $this->assertSame('progress width must be >= 0', $result);
    }

    public function testTranslateAnotherKnownKey(): void
    {
        $result = Lang::t('timer.duration_nonneg');
        $this->assertSame('timer duration must be >= 0', $result);
    }

    public function testTranslateWithParameters(): void
    {
        $result = Lang::t('table.sort_unknown_column', ['column' => '42']);
        $this->assertStringContainsString('42', $result);
        $this->assertStringContainsString('sort column not found', $result);
    }

    public function testTranslateFallbackForUnknownKey(): void
    {
        $result = Lang::t('nonexistent.key');
        $this->assertSame('bits.nonexistent.key', $result);
    }

    public function testTranslateTabsKey(): void
    {
        $result = Lang::t('tabs.bad_index');
        $this->assertSame('tab index out of range', $result);
    }

    public function testTranslateTableKey(): void
    {
        $result = Lang::t('table.dim_nonneg');
        $this->assertSame('table width/height must be >= 0', $result);
    }

    public function testTranslateHelpKey(): void
    {
        $result = Lang::t('help.width_nonneg');
        $this->assertSame('help width must be >= 0', $result);
    }

    public function testStaticFactoryReturnsSameInstance(): void
    {
        $lang1 = new Lang();
        $lang2 = new Lang();
        $this->assertInstanceOf(Lang::class, $lang1);
        $this->assertInstanceOf(Lang::class, $lang2);
    }
}
