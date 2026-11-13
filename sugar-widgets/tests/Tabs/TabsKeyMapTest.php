<?php

declare(strict_types=1);

namespace SugarCraft\Widgets\Tests\Tabs;

use PHPUnit\Framework\TestCase;
use SugarCraft\Widgets\Key\Binding;
use SugarCraft\Widgets\Tabs\TabsKeyMap;

final class TabsKeyMapTest extends TestCase
{
    public function testDefaultFactoryReturnsInstance(): void
    {
        $km = TabsKeyMap::new();
        $this->assertInstanceOf(TabsKeyMap::class, $km);
    }

    public function testDefaultHasNextTabBinding(): void
    {
        $km = TabsKeyMap::new();
        $this->assertInstanceOf(Binding::class, $km->nextTab);
        $this->assertSame(['tab'], $km->nextTab->keys);
    }

    public function testDefaultHasPrevTabBinding(): void
    {
        $km = TabsKeyMap::new();
        $this->assertInstanceOf(Binding::class, $km->prevTab);
        $this->assertSame(['shift+tab'], $km->prevTab->keys);
    }

    public function testDefaultHasNineJumpBindings(): void
    {
        $km = TabsKeyMap::new();
        $this->assertCount(9, $km->jumpBindings);
        foreach ($km->jumpBindings as $i => $binding) {
            $this->assertInstanceOf(Binding::class, $binding);
            $this->assertSame([(string) ($i + 1)], $binding->keys);
        }
    }

    public function testShortHelpReturnsNavBindings(): void
    {
        $km = TabsKeyMap::new();
        $short = $km->shortHelp();
        $this->assertCount(2, $short);
        $this->assertSame($km->nextTab, $short[0]);
        $this->assertSame($km->prevTab, $short[1]);
    }

    public function testFullHelpReturnsNavPlusJumpColumns(): void
    {
        $km = TabsKeyMap::new();
        $full = $km->fullHelp();
        // fullHelp = [shortHelp (2 bindings), jumpCols (3 columns: 4+4+1)]
        $this->assertCount(4, $full);
        $this->assertSame([$km->nextTab, $km->prevTab], $full[0]);
        $this->assertCount(4, $full[1]);
        $this->assertCount(4, $full[2]);
        $this->assertCount(1, $full[3]);
    }

    public function testJumpBindingsHaveCorrectHelpDescriptions(): void
    {
        $km = TabsKeyMap::new();
        foreach ($km->jumpBindings as $i => $binding) {
            $this->assertSame((string) ($i + 1), $binding->help->key);
            $this->assertSame("tab " . ($i + 1), $binding->help->desc);
        }
    }

    public function testNextTabHelpDesc(): void
    {
        $km = TabsKeyMap::new();
        $this->assertSame('tab', $km->nextTab->help->key);
        $this->assertSame('next tab', $km->nextTab->help->desc);
    }

    public function testPrevTabHelpDesc(): void
    {
        $km = TabsKeyMap::new();
        $this->assertSame('shift+tab', $km->prevTab->help->key);
        $this->assertSame('prev tab', $km->prevTab->help->desc);
    }

    public function testInstancesAreIndependent(): void
    {
        $km1 = TabsKeyMap::new();
        $km2 = TabsKeyMap::new();
        $this->assertNotSame($km1, $km2);
        $this->assertNotSame($km1->nextTab, $km2->nextTab);
    }

    public function testJumpBindingsTotalCountIsEleven(): void
    {
        $km = TabsKeyMap::new();
        $allBindings = [$km->nextTab, $km->prevTab, ...$km->jumpBindings];
        $this->assertCount(11, $allBindings);
    }

    public function testHelpLabelsResolveThroughLangInDefaultLocale(): void
    {
        $km = TabsKeyMap::new();
        $this->assertSame('next tab', $km->nextTab->help->desc);
        $this->assertSame('prev tab', $km->prevTab->help->desc);
        $this->assertSame('tab 3', $km->jumpBindings[2]->help->desc);
        $this->assertStringNotContainsString('{index}', $km->jumpBindings[0]->help->desc);
    }

    public function testHelpLabelsAreLocalizedWhenLocaleSwitched(): void
    {
        // The README promise: label copy resolves through Lang. The Help
        // overlay shows these descs verbatim, so every locale gets the
        // translated strings, not English literals.
        \SugarCraft\Core\I18n\T::setLocale('de');
        try {
            $km = TabsKeyMap::new();
            $this->assertSame('nächster Tab', $km->nextTab->help->desc);
            $this->assertSame('vorheriger Tab', $km->prevTab->help->desc);
            $this->assertSame('Tab 7', $km->jumpBindings[6]->help->desc);
        } finally {
            \SugarCraft\Core\I18n\T::setLocale('en');
        }
        $this->assertSame('next tab', TabsKeyMap::new()->nextTab->help->desc, 'locale state restored');
    }
}
