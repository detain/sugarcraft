<?php

declare(strict_types=1);

namespace SugarCraft\Glow\Tests;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use SugarCraft\Glow\Lang;

/**
 * @covers \SugarCraft\Glow\Lang
 */
final class LangTest extends TestCase
{
    public function testNamespaceConstantIsGlow(): void
    {
        $rc = new ReflectionClass(Lang::class);
        $ns = $rc->getConstant('NAMESPACE');
        $this->assertSame('glow', $ns);
    }

    public function testDirConstantPointsToLangDirectory(): void
    {
        $rc = new ReflectionClass(Lang::class);
        $dir = $rc->getConstant('DIR');
        $this->assertSame(realpath(__DIR__ . '/../lang'), realpath($dir));
    }

    public function testTTranslatesRenderUnknownThemeKey(): void
    {
        $result = Lang::t('render.unknown_theme', ['name' => 'mystery']);
        $this->assertSame('unknown theme: mystery', $result);
    }

    public function testTTranslatesRenderThemeConfigUnreadableKey(): void
    {
        $result = Lang::t('render.theme_config_unreadable', ['path' => '/no/such.json']);
        $this->assertSame('theme config not readable: /no/such.json', $result);
    }

    public function testTTranslatesRenderThemeConfigTooLargeKey(): void
    {
        $result = Lang::t('render.theme_config_too_large', ['path' => '/big.json', 'limit' => 1_000_000]);
        $this->assertStringContainsString('/big.json', $result);
        $this->assertStringContainsString('1000000', $result);
    }

    public function testTInterpolatesNameParameterInUnknownTheme(): void
    {
        $result = Lang::t('render.unknown_theme', ['name' => 'tokyo-night']);
        $this->assertSame('unknown theme: tokyo-night', $result);
    }

    public function testTInterpolatesPathParameterInThemeConfigUnreadable(): void
    {
        $result = Lang::t('render.theme_config_unreadable', ['path' => '/etc/my-theme.json']);
        $this->assertSame('theme config not readable: /etc/my-theme.json', $result);
    }

    public function testTInterpolatesPathAndLimitParametersInThemeConfigTooLarge(): void
    {
        $result = Lang::t('render.theme_config_too_large', [
            'path'  => '/huge/theme.json',
            'limit' => 1_000_000,
        ]);
        // Must contain both interpolated values.
        $this->assertStringContainsString('/huge/theme.json', $result);
        $this->assertStringContainsString('1000000', $result);
    }

    public function testTReturnsFullNamespacedKeyForMissingKey(): void
    {
        // T::translate falls back to the full namespaced key when no translation exists.
        $result = Lang::t('render.does_not_exist');
        $this->assertSame('glow.render.does_not_exist', $result);
    }

    public function testTDotNotationRoutesToCorrectNamespace(): void
    {
        // Lang::t('render.unknown_theme') becomes T::translate('glow.render.unknown_theme').
        $full = Lang::t('render.unknown_theme', ['name' => 'nope']);
        // If routing were broken the key would be returned unchanged.
        $this->assertNotSame('render.unknown_theme', $full);
        $this->assertStringContainsString('nope', $full);
    }
}
