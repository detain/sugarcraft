<?php

declare(strict_types=1);

namespace SugarCraft\Top\Tests\Theme;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SugarCraft\Core\Util\Color;
use SugarCraft\Dash\Foundation\GradientStore;
use SugarCraft\Top\Theme\ThemeConfig;

final class ThemeConfigTest extends TestCase
{
    private static function hex(?Color $c): ?string
    {
        return $c === null ? null : sprintf('#%02x%02x%02x', $c->r, $c->g, $c->b);
    }

    public function testDefaultThemeHasBtops48Keys(): void
    {
        self::assertCount(48, ThemeConfig::DEFAULT_THEME);
        self::assertCount(9, ThemeConfig::FAMILIES);
        foreach (ThemeConfig::FAMILIES as $f) {
            foreach (['_start', '_mid', '_end'] as $s) {
                self::assertArrayHasKey($f . $s, ThemeConfig::DEFAULT_THEME);
            }
        }
    }

    /** Spot pins copied by hand from btop_theme.cpp Default_theme. */
    #[DataProvider('defaultPins')]
    public function testDefaultValuesPinnedAgainstBtop(string $key, string $raw, string $resolved): void
    {
        self::assertSame($raw, ThemeConfig::DEFAULT_THEME[$key]);
        self::assertSame($resolved, self::hex(ThemeConfig::new()->color($key)));
    }

    /** @return iterable<array{string,string,string}> */
    public static function defaultPins(): iterable
    {
        yield ['main_bg', '#00', '#000000'];
        yield ['main_fg', '#cc', '#cccccc'];
        yield ['title', '#ee', '#eeeeee'];
        yield ['hi_fg', '#b54040', '#b54040'];
        yield ['selected_bg', '#6a2f2f', '#6a2f2f'];
        yield ['inactive_fg', '#40', '#404040'];
        yield ['graph_text', '#60', '#606060'];
        yield ['proc_misc', '#0de756', '#0de756'];
        yield ['cpu_box', '#556d59', '#556d59'];
        yield ['div_line', '#30', '#303030'];
        yield ['temp_end', '#ff40b6', '#ff40b6'];
        yield ['cpu_mid', '#cbc06c', '#cbc06c'];
        yield ['download_start', '#291f75', '#291f75'];
        yield ['process_end', '#d45454', '#d45454'];
        yield ['proc_follow_bg', '#4040b5', '#4040b5'];
        yield ['proc_banner_bg', '#7b407b', '#7b407b'];
        yield ['followed_fg', '#ee', '#eeeeee'];
    }

    public function testDefaultName(): void
    {
        self::assertSame('Default', ThemeConfig::new()->name());
    }

    #[DataProvider('colorForms')]
    public function testParseColorForms(string $value, ?string $expected): void
    {
        self::assertSame($expected, self::hex(ThemeConfig::parseColor($value)));
    }

    /** @return iterable<array{string,?string}> */
    public static function colorForms(): iterable
    {
        yield 'rrggbb' => ['#1a2B3c', '#1a2b3c'];
        yield 'gray' => ['#7f', '#7f7f7f'];
        yield 'decimal' => ['10 20 30', '#0a141e'];
        yield 'decimal extra spaces' => ['  10   20 30', '#0a141e'];
        yield 'decimal clamped' => ['300 -5 128', '#ff0080'];
        yield 'decimal stoi trailing junk' => ['12px 0 0', '#0c0000'];
        yield 'hex bad digit' => ['#gg0000', null];
        yield 'hex wrong length' => ['#abc', null];
        yield 'hash only' => ['#', null];
        yield 'decimal two fields' => ['1 2', null];
        yield 'decimal non numeric' => ['a b c', null];
    }

    public function testMissingKeysFallBackToDefault(): void
    {
        $t = ThemeConfig::fromSource(['main_fg' => '#112233']);
        self::assertSame('#112233', self::hex($t->color('main_fg')));
        self::assertSame('#b54040', self::hex($t->color('hi_fg')));
        self::assertSame('#4897d4', self::hex($t->color('temp_start')));
    }

    public function testOptionalKeysUseDedicatedFallbacks(): void
    {
        $t = ThemeConfig::fromSource([
            'inactive_fg' => '#123456',
            'cpu_start' => '#010101',
            'cpu_mid' => '#020202',
            'cpu_end' => '#030303',
        ]);
        // meter_bg / graph_text inherit inactive_fg, not their Default values.
        self::assertSame('#123456', self::hex($t->color('meter_bg')));
        self::assertSame('#123456', self::hex($t->color('graph_text')));
        // process_* mirror cpu_* when process_start is absent.
        self::assertSame('#010101', self::hex($t->color('process_start')));
        self::assertSame('#020202', self::hex($t->color('process_mid')));
        self::assertSame('#030303', self::hex($t->color('process_end')));
    }

    public function testProcessFallbackOverridesStrayMidWhenStartAbsent(): void
    {
        $t = ThemeConfig::fromSource(['process_mid' => '#abcdef']);
        self::assertSame('#cbc06c', self::hex($t->color('process_mid')));
    }

    public function testProcessStartWithoutMidGetsBlackMidLikeBtop(): void
    {
        $t = ThemeConfig::fromSource([
            'process_start' => '#646464',
            'process_end' => '#c8c8c8',
        ]);
        self::assertNull($t->color('process_mid'));
        // operator[] value-initialised {0,0,0} mid → 3-stop ramp through black.
        self::assertSame('#000000', self::hex($t->at('process', 50)));
        self::assertSame('#646464', self::hex($t->at('process', 0)));
        self::assertSame('#c8c8c8', self::hex($t->at('process', 100)));
    }

    public function testEmptyValueSemantics(): void
    {
        $t = ThemeConfig::fromSource([
            'main_bg' => '',
            'cpu_mid' => '',
            'used_end' => '',
            'title' => '',
        ]);
        self::assertNull($t->color('main_bg'), 'empty main_bg = terminal default');
        self::assertNull($t->color('cpu_mid'), 'empty mid = no stop');
        self::assertNull($t->color('used_end'), 'empty end = no stop');
        self::assertSame('#eeeeee', self::hex($t->color('title')), 'empty single key falls back');
    }

    public function testInvalidHexIsNullButInvalidDecimalFallsBack(): void
    {
        $t = ThemeConfig::fromSource(['hi_fg' => '#zzzzzz', 'title' => '1 2']);
        self::assertNull($t->color('hi_fg'));
        self::assertSame('#eeeeee', self::hex($t->color('title')));
    }

    public function testThemeBackgroundOffForcesTransparentMainBg(): void
    {
        $t = ThemeConfig::fromSource(['main_bg' => '#123456']);
        self::assertSame('#123456', self::hex($t->color('main_bg')));
        $off = $t->withThemeBackground(false);
        self::assertNull($off->color('main_bg'));
        self::assertFalse($off->themeBackground());
        self::assertTrue($t->themeBackground(), 'immutable');
        self::assertSame('#123456', self::hex($off->withThemeBackground(true)->color('main_bg')));
    }

    public function testUnknownKeyThrows(): void
    {
        $this->expectException(\OutOfBoundsException::class);
        ThemeConfig::new()->color('nope');
    }

    public function testFromSourceDropsUnknownKeys(): void
    {
        $t = ThemeConfig::fromSource(['bogus' => '#ffffff', 'title' => '#010203']);
        self::assertSame(['title' => '#010203'], $t->source());
    }

    public function testDefaultGradientEndpointsAndMid(): void
    {
        $t = ThemeConfig::new();
        self::assertSame('#77ca9b', self::hex($t->at('cpu', 0)));
        self::assertSame('#cbc06c', self::hex($t->at('cpu', 50)));
        self::assertSame('#dc4c4c', self::hex($t->at('cpu', 100)));
        self::assertSame('#dc4c4c', self::hex($t->at('cpu', 250)), 'clamped');
        self::assertSame('#77ca9b', self::hex($t->at('cpu', -3)), 'clamped');
    }

    public function testGradientUsesBtopTruncatingIntegerLaw(): void
    {
        // cpu leg 1 red: 0x77 + 25 * (0xcb - 0x77) / 50 = 119 + 42 = 161 (exact)
        // cpu leg 2 green: 0xc0 + 25 * (0x4c - 0xc0) / 50 = 192 + (-116*25/50 = -58) = 134
        $t = ThemeConfig::new();
        self::assertSame(161, $t->at('cpu', 25)->r);
        self::assertSame(134, $t->at('cpu', 75)->g);
        // Two-stop: #50 → #ff over 100: 80 + 50*175/100 = 80 + 87 (truncated).
        $two = ThemeConfig::fromSource(['cpu_start' => '#50', 'cpu_mid' => '', 'cpu_end' => '#ff']);
        self::assertSame(167, $two->at('cpu', 50)->r);
    }

    public function testStartOnlyGradientFillsWithStart(): void
    {
        $t = ThemeConfig::fromSource(['used_start' => '#ea6962', 'used_mid' => '#000000', 'used_end' => '']);
        foreach ([0, 50, 100] as $p) {
            self::assertSame('#ea6962', self::hex($t->at('used', $p)), 'mid ignored without end');
        }
    }

    public function testDerivedProcGradients(): void
    {
        $t = ThemeConfig::new();
        self::assertSame('#cccccc', self::hex($t->at('proc', 0)));
        self::assertSame('#404040', self::hex($t->at('proc', 100)));
        // Two-stop, no mid: 0xcc + 50 * (0x40 - 0xcc) / 100 = 204 - 70 = 134.
        self::assertSame(134, $t->at('proc', 50)->r);
        self::assertSame('#404040', self::hex($t->at('proc_color', 0)));
        self::assertSame('#80d0a3', self::hex($t->at('proc_color', 100)));
    }

    public function testProcColorFollowsCpuFallbackForProcessStart(): void
    {
        $t = ThemeConfig::fromSource(['cpu_start' => '#0a0b0c']);
        self::assertSame('#0a0b0c', self::hex($t->at('proc_color', 100)));
    }

    public function testInvalidStartYieldsBlankGradient(): void
    {
        $t = ThemeConfig::fromSource(['temp_start' => '#nothex']);
        self::assertTrue($t->hasGradient('temp'));
        self::assertNull($t->at('temp', 40));
        self::assertSame(array_fill(0, 101, null), $t->gradient('temp'));
        self::assertFalse($t->gradients()->has('temp'));
    }

    public function testDerivedGradientWithoutEndIsBlank(): void
    {
        $t = ThemeConfig::fromSource(['inactive_fg' => '#bad']);
        self::assertNull($t->at('proc', 0));
        self::assertNull($t->at('proc_color', 100));
    }

    public function testGradientNamesAndStore(): void
    {
        $t = ThemeConfig::new();
        self::assertSame([...ThemeConfig::FAMILIES, 'proc', 'proc_color'], $t->gradientNames());
        self::assertInstanceOf(GradientStore::class, $t->gradients());
        self::assertCount(101, $t->gradient('download'));
        self::assertFalse($t->hasGradient('nope'));
    }

    public function testUnknownGradientThrows(): void
    {
        $this->expectException(\OutOfBoundsException::class);
        ThemeConfig::new()->at('nope', 1);
    }

    public function testColorsReturnsEveryKeyInDefaultOrder(): void
    {
        self::assertSame(array_keys(ThemeConfig::DEFAULT_THEME), array_keys(ThemeConfig::new()->colors()));
    }

    #[DataProvider('depthCases')]
    public function testIsBackground(string $key, bool $bg): void
    {
        self::assertSame($bg, ThemeConfig::isBackground($key));
    }

    /** @return iterable<array{string,bool}> */
    public static function depthCases(): iterable
    {
        yield ['main_bg', true];
        yield ['selected_bg', true];
        yield ['followed_bg', true];
        yield ['meter_bg', false];
        yield ['main_fg', false];
    }

    public function testFromStringAndFromFile(): void
    {
        $t = ThemeConfig::fromString("theme[title]=\"#010203\"\n", 'inline');
        self::assertSame('inline', $t->name());
        self::assertSame('#010203', self::hex($t->color('title')));

        $f = ThemeConfig::fromFile(dirname(__DIR__, 2) . '/themes/nord.theme');
        self::assertSame('nord', $f->name());
        self::assertSame('#d8dee9', self::hex($f->color('main_fg')));
    }

    public function testFromFileMissingThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        ThemeConfig::fromFile('/nonexistent/x.theme');
    }
}
