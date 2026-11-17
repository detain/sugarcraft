<?php

declare(strict_types=1);

namespace SugarCraft\Markdown\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Markdown\Renderer;
use SugarCraft\Markdown\Theme;

/**
 * The theme catalog: stock roster plus the bundled glamour-style files that
 * candy-mdview used to carry, and the glamour `styles.json` door.
 */
final class ThemeCatalogTest extends TestCase
{
    public function testCatalogIsTheStockRosterFollowedByTheBundledFiles(): void
    {
        $catalog = Theme::catalog();

        $this->assertSame(Theme::names(), \array_slice($catalog, 0, \count(Theme::names())));
        $this->assertSame(['github', 'monokai', 'solarized'], \array_slice($catalog, \count(Theme::names())));
        $this->assertSame(\array_values(\array_unique($catalog)), $catalog);
    }

    public function testEveryCatalogNameLoadsAndRenders(): void
    {
        foreach (Theme::catalog() as $name) {
            $theme = Theme::fromCatalog($name);
            $this->assertInstanceOf(Theme::class, $theme, $name);
            $this->assertStringContainsString('Title', Renderer::renderMarkdown("# Title\n", $theme), $name);
        }
    }

    public function testBundledFilesExistOnDisk(): void
    {
        foreach (['github', 'monokai', 'solarized'] as $name) {
            $path = Theme::bundledPath($name);
            $this->assertNotNull($path, $name);
            $this->assertFileExists($path);
        }
    }

    public function testBundledPathIgnoresStockAndUnknownNames(): void
    {
        $this->assertNull(Theme::bundledPath('dark'));
        $this->assertNull(Theme::bundledPath('no-such-theme'));
        $this->assertNull(Theme::bundledPath(''));
    }

    public function testBundledNameLookupIsCaseAndWhitespaceInsensitive(): void
    {
        $this->assertEquals(Theme::fromFile(Theme::bundledPath('monokai')), Theme::fromCatalog(' MONOKAI '));
    }

    public function testStockNamesResolveToTheStockInstances(): void
    {
        $this->assertSame(Theme::tokyoNight(), Theme::fromCatalog('tokyo-night'));
        $this->assertSame(Theme::dark(), Theme::fromCatalog('dark'));
    }

    public function testUnknownNameIsNullSoTheCallerChoosesTheFallback(): void
    {
        $this->assertNull(Theme::fromCatalog('no-such-theme'));
    }

    public function testGlamourJsonAcceptsTheDocumentSchema(): void
    {
        $theme = Theme::fromGlamourJson('{"document":{"block_prefix":"\\n","color":"#ffffff"}}');

        $this->assertInstanceOf(Theme::class, $theme);
        $this->assertSame("\n", $theme->documentBlockPrefix);
    }

    public function testGlamourJsonRefusesTheFlatSchema(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Theme::fromGlamourJson('{"heading1":{"foreground":"#ffffff"}}');
    }

    public function testGlamourJsonRefusesAListAndBadJson(): void
    {
        foreach (['[]', '[1,2]', '{not json'] as $bad) {
            try {
                Theme::fromGlamourJson($bad);
                $this->fail("expected refusal for {$bad}");
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    /**
     * candy-mdview's --theme door accepted these spellings before the catalog
     * moved here; dropping them would refuse flags that worked yesterday.
     */
    public function testCatalogKeepsCandyMdviewAliasesForPlainAndNotty(): void
    {
        $this->assertEquals(Theme::plain(), Theme::fromCatalog('no'));
        $this->assertEquals(Theme::notty(), Theme::fromCatalog('auto-no'));
        $this->assertEquals(Theme::plain(), Theme::fromCatalog('NO'));
        $this->assertEquals(Theme::notty(), Theme::fromCatalog('auto_no'));
        $this->assertEquals(Theme::tokyoNight(), Theme::fromCatalog('tokyo_night'));
        $this->assertNull(Theme::fromCatalog('autono'), 'only the spelled-out aliases answer');
    }
}
