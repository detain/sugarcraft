<?php

declare(strict_types=1);

namespace SugarCraft\Top\Tests\Theme;

use PHPUnit\Framework\TestCase;
use SugarCraft\Top\Theme\ThemeConfig;
use SugarCraft\Top\Theme\ThemeEntry;
use SugarCraft\Top\Theme\ThemeRegistry;
use SugarCraft\Top\Theme\TtyTheme;

final class ThemeRegistryTest extends TestCase
{
    private string $tmp;

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir() . '/candy-top-theme-' . bin2hex(random_bytes(4));
        mkdir($this->tmp . '/user', 0777, true);
        mkdir($this->tmp . '/sys', 0777, true);
        file_put_contents($this->tmp . '/sys/beta.theme', "theme[title]=\"#01\"\n");
        file_put_contents($this->tmp . '/sys/alpha.theme', "theme[title]=\"#02\"\n");
        file_put_contents($this->tmp . '/sys/notes.txt', 'ignored');
        file_put_contents($this->tmp . '/sys/.theme', 'ignored: extension() of a bare dotfile is empty');
        file_put_contents($this->tmp . '/user/beta.theme', "theme[title]=\"#03\"\n");
    }

    protected function tearDown(): void
    {
        foreach (['user/beta.theme', 'sys/beta.theme', 'sys/alpha.theme', 'sys/notes.txt', 'sys/.theme'] as $f) {
            @unlink($this->tmp . '/' . $f);
        }
        @rmdir($this->tmp . '/user');
        @rmdir($this->tmp . '/sys');
        @rmdir($this->tmp);
    }

    private function registry(): ThemeRegistry
    {
        return ThemeRegistry::fromDirs($this->tmp . '/user', $this->tmp . '/missing', $this->tmp . '/sys');
    }

    public function testBuiltinsFirstThenSortedWithPriorityShadowing(): void
    {
        $r = $this->registry();
        self::assertSame(['Default', 'TTY', 'alpha', 'beta', 'beta'], $r->names());
        self::assertSame($this->tmp . '/user/beta.theme', $r->entries()[3]->path());
        self::assertSame($this->tmp . '/sys/beta.theme', $r->entries()[4]->path());
        self::assertTrue($r->entries()[0]->isBuiltin());
    }

    public function testFindByStemFilenameAndPath(): void
    {
        $r = $this->registry();
        self::assertSame($this->tmp . '/user/beta.theme', $r->find('beta')?->path(), 'user dir shadows');
        self::assertSame($this->tmp . '/user/beta.theme', $r->find('beta.theme')?->path());
        self::assertSame($this->tmp . '/sys/beta.theme', $r->find($this->tmp . '/sys/beta.theme')?->path());
        self::assertSame('TTY', $r->find('TTY')?->name());
        self::assertNull($r->find('nope'));
    }

    public function testLegacyAbsolutePathMatchesByFilename(): void
    {
        self::assertTrue(ThemeEntry::file('/a/b/nord.theme')->matches('/old/install/nord.theme'));
        self::assertFalse(ThemeEntry::file('/a/b/nord.theme')->matches('rel/nord.theme'));
    }

    public function testLoad(): void
    {
        $r = $this->registry();
        self::assertInstanceOf(TtyTheme::class, $r->load('TTY'));
        $default = $r->load('Default');
        self::assertInstanceOf(ThemeConfig::class, $default);
        self::assertSame('Default', $default->name());
        self::assertSame('Default', $r->load('does-not-exist')->name(), 'btop falls back to Default');
        self::assertSame(0x03, $r->load('beta')->color('title')?->r);
        self::assertSame(0x01, $r->load($this->tmp . '/sys/beta.theme')->color('title')?->r);
        self::assertNull($r->load('alpha', false)->color('main_bg'));
        self::assertNull($r->load('TTY', false)->color('main_bg'));
    }

    public function testCycleWrapsAndHandlesUnknown(): void
    {
        $r = $this->registry();
        self::assertSame('TTY', $r->next('Default')->name());
        self::assertSame('Default', $r->next($this->tmp . '/sys/beta.theme')->name(), 'wraps');
        self::assertSame($this->tmp . '/sys/beta.theme', $r->prev('Default')->path(), 'wraps back');
        self::assertSame('Default', $r->next('unknown')->name());
        self::assertSame($this->tmp . '/sys/beta.theme', $r->prev('unknown')->path());
    }

    public function testCycleThroughShadowedEntryViaConfigValue(): void
    {
        $r = $this->registry();
        $first = $r->next('alpha');
        self::assertSame('beta.theme', $r->configValue($first));
        $second = $r->next($r->configValue($first));
        self::assertSame($this->tmp . '/sys/beta.theme', $second->path());
        self::assertSame($this->tmp . '/sys/beta.theme', $r->configValue($second));
        self::assertSame('Default', $r->next($r->configValue($second))->name());
        self::assertSame('TTY', $r->configValue($r->entries()[1]));
    }

    public function testBareDotThemeFileIsNotListed(): void
    {
        foreach ($this->registry()->entries() as $entry) {
            self::assertNotSame('.theme', $entry->filename());
        }
    }

    public function testLoadFallsBackToDefaultWhenListedFileVanished(): void
    {
        $r = $this->registry();
        unlink($this->tmp . '/sys/alpha.theme');
        $p = $r->load('alpha');
        self::assertInstanceOf(ThemeConfig::class, $p);
        self::assertSame('Default', $p->name());
    }

    public function testTtyModeForcesTtyForAnyTheme(): void
    {
        $r = $this->registry();
        self::assertInstanceOf(TtyTheme::class, $r->load('beta', true, true));
        self::assertInstanceOf(TtyTheme::class, $r->load('Default', true, true));
        self::assertNull($r->load('beta', false, true)->color('main_bg'));
        self::assertInstanceOf(ThemeConfig::class, $r->load('beta', true, false));
    }

    public function testConfigValueComparesByPathNotIdentity(): void
    {
        $r = $this->registry();
        self::assertSame('beta.theme', $r->configValue(ThemeEntry::file($this->tmp . '/user/beta.theme')));
        self::assertSame(
            $this->tmp . '/sys/beta.theme',
            $r->configValue(ThemeEntry::file($this->tmp . '/sys/beta.theme')),
        );
        self::assertSame('Default', $r->configValue(ThemeEntry::builtin('Default')));
    }

    public function testUserDir(): void
    {
        self::assertSame('/x/candy-top/themes', ThemeRegistry::userDir(['XDG_CONFIG_HOME' => '/x/', 'HOME' => '/h']));
        self::assertSame('/h/.config/candy-top/themes', ThemeRegistry::userDir(['HOME' => '/h']));
        self::assertNull(ThemeRegistry::userDir([]));
    }

    public function testNewDiscoversBundledThemesAndCustomDirFirst(): void
    {
        $r = ThemeRegistry::new($this->tmp . '/user', []);
        self::assertCount(2 + 43 + 1, $r->entries());
        self::assertSame(['Default', 'TTY'], array_slice($r->names(), 0, 2));
        self::assertContains('nord', $r->names());
        self::assertContains('HotPurpleTrafficLight', $r->names());
        self::assertSame(dirname(__DIR__, 2) . '/themes', ThemeRegistry::bundledDir());
    }
}
