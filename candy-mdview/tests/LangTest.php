<?php

declare(strict_types=1);

namespace SugarCraft\MdView\Tests;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use SugarCraft\MdView\Lang;

/**
 * @covers \SugarCraft\MdView\Lang
 */
final class LangTest extends TestCase
{
    public function testNamespaceConstantIsMdView(): void
    {
        $rc = new ReflectionClass(Lang::class);
        $ns = $rc->getConstant('NAMESPACE');
        $this->assertSame('mdview', $ns);
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
        $this->assertSame('unknown theme: mystery — JSON style files belong in --theme-config', $result);
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
        $this->assertSame('unknown theme: tokyo-night — JSON style files belong in --theme-config', $result);
    }

    public function testTTranslatesRenderStyleOverridesThemeKey(): void
    {
        $result = Lang::t('render.style_overrides_theme', ['style' => 'dark', 'theme' => 'light']);
        $this->assertSame('note: --style "dark" overrides --theme "light"', $result);
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
        $this->assertSame('mdview.render.does_not_exist', $result);
    }

    public function testTDotNotationRoutesToCorrectNamespace(): void
    {
        // Lang::t('render.unknown_theme') becomes T::translate('mdview.render.unknown_theme').
        $full = Lang::t('render.unknown_theme', ['name' => 'nope']);
        // If routing were broken the key would be returned unchanged.
        $this->assertNotSame('render.unknown_theme', $full);
        $this->assertStringContainsString('nope', $full);
    }

    public function testInputLaneErrorKeysCarryTheirEnglishTexts(): void
    {
        // KM5: the usage-error vocabulary execute() throws must exist in
        // en with the exact texts the tests and docs quote.
        $this->assertSame(
            'no input: pass a file argument, pipe Markdown to standard input, or run with --help',
            Lang::t('render.no_input_tty')
        );
        $this->assertSame(
            'no input: standard input is empty',
            Lang::t('render.no_input_empty')
        );
        $this->assertSame(
            'open {path}: no such file',
            Lang::t('render.file_missing')
        );
        $this->assertSame(
            'open {path}: is a directory',
            Lang::t('render.file_is_directory')
        );
        $this->assertSame(
            'open {path}: cannot read file',
            Lang::t('render.file_unreadable')
        );
        $this->assertSame(
            'refusing to render {path}: not a text file',
            Lang::t('render.binary_input')
        );
        $this->assertSame(
            'input exceeds {limit} bytes: {path}',
            Lang::t('render.input_too_large')
        );
        $this->assertSame(
            'invalid --width value: {value} (expected an integer between 0 and 9999; 0 disables wrapping)',
            Lang::t('render.width_invalid')
        );
    }

    public function testInputLaneKeysInterpolateTheirPlaceholders(): void
    {
        $this->assertSame(
            'open /tmp/candy-mdview-ghost.md: no such file',
            Lang::t('render.file_missing', ['path' => '/tmp/candy-mdview-ghost.md'])
        );
        $this->assertSame(
            'open ' . sys_get_temp_dir() . ': is a directory',
            Lang::t('render.file_is_directory', ['path' => sys_get_temp_dir()])
        );
        $this->assertStringContainsString(
            '67108864',
            Lang::t('render.input_too_large', ['path' => '/big.md', 'limit' => 67_108_864])
        );
        $this->assertStringContainsString(
            '/big.md',
            Lang::t('render.input_too_large', ['path' => '/big.md', 'limit' => 67_108_864])
        );
        $this->assertStringContainsString(
            'abc',
            Lang::t('render.width_invalid', ['value' => 'abc'])
        );
    }
}
