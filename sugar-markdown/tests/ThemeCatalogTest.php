<?php

declare(strict_types=1);

namespace SugarCraft\Markdown\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Markdown\Theme;

final class ThemeCatalogTest extends TestCase
{
    private const BUNDLED = ['solarized', 'monokai', 'github'];

    public function testTheCatalogIsTheStockRosterFollowedByTheBundledFiles(): void
    {
        $this->assertSame(
            array_merge(Theme::names(), self::BUNDLED),
            array_keys(Theme::catalog()),
        );
    }

    public function testStockEntriesAreTheStockInstances(): void
    {
        foreach (Theme::names() as $name) {
            $this->assertTrue(Theme::isStock(Theme::catalog()[$name]), $name);
        }
    }

    public function testTheCatalogLoadsEachEntryOnce(): void
    {
        $first = Theme::catalog();
        $second = Theme::catalog();

        foreach ($first as $slug => $theme) {
            $this->assertSame($theme, $second[$slug], $slug);
        }
    }

    public function testEveryCatalogEntryRoundTripsThroughItsOwnName(): void
    {
        foreach (Theme::catalog() as $slug => $theme) {
            $this->assertSame($theme, Theme::catalogByName((string) $slug), $slug);
        }
    }

    public function testLooseNamesFindTheSameTheme(): void
    {
        $this->assertSame(Theme::tokyoNight(), Theme::catalogByName('tokyo-night'));
        $this->assertSame(Theme::tokyoNight(), Theme::catalogByName('tokyonight'));
        $this->assertSame(Theme::tokyoNight(), Theme::catalogByName('Tokyo_Night'));
        $this->assertSame(Theme::tokyoNight(), Theme::catalogByName(' TOKYO NIGHT '));
        $this->assertSame(Theme::catalog()['monokai'], Theme::catalogByName('MONOKAI'));
    }

    public function testBundledFilesLoadAsTheirOwnThemeFiles(): void
    {
        foreach (self::BUNDLED as $slug) {
            $this->assertEquals(
                Theme::fromFile(dirname(__DIR__) . '/themes/' . $slug . '.json'),
                Theme::catalog()[$slug],
                $slug,
            );
        }
    }

    public function testBundledThemesCarryTheirStyles(): void
    {
        // Monokai's code block is a Monokai green on a dark backdrop, so a
        // loader that dropped the file's styles would show up here.
        $this->assertStringContainsString("\x1b[", Theme::catalog()['monokai']->codeBlock->render('x'));
        $this->assertNotSame(
            Theme::catalog()['monokai']->codeBlock->render('x'),
            Theme::catalog()['github']->codeBlock->render('x'),
        );
    }

    public function testUnknownNamesAndEmptyNamesAreNull(): void
    {
        $this->assertNull(Theme::catalogByName('nope'));
        $this->assertNull(Theme::catalogByName(''));
        // The CLI aliases belong to the consumer, not to the catalog.
        $this->assertNull(Theme::catalogByName('no'));
        $this->assertNull(Theme::catalogByName('auto-no'));
    }
}
