<?php

declare(strict_types=1);

namespace SugarCraft\Top\Tests\Docs;

use SugarCraft\Top\Theme\ThemeRegistry;

/**
 * README's theme list is the bundled `themes/` directory. Dropping in or
 * removing a `.theme` file without the README edit goes red here.
 */
final class ReadmeThemeListDriftTest extends ReadmeDriftTestCase
{
    public function testReadmeThemeListMatchesTheThemesDirectory(): void
    {
        $this->assertReadmeBlock('themes', ReadmeTables::themeList());
    }

    public function testRegistryListsEveryBundledFileAfterTheBuiltins(): void
    {
        $names = ThemeRegistry::fromDirs(ThemeRegistry::bundledDir())->names();
        $this->assertSame(['Default', 'TTY'], array_slice($names, 0, 2));
        $this->assertCount(count(ReadmeTables::themeFiles()) + 2, $names, 'the registry skips a bundled file the README lists');
    }
}
