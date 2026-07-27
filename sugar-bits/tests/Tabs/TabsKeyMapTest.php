<?php

declare(strict_types=1);

namespace SugarCraft\Bits\Tests\Tabs;

use PHPUnit\Framework\TestCase;
use SugarCraft\Bits\Key\Binding;
use SugarCraft\Bits\Tabs\TabsKeyMap;

final class TabsKeyMapTest extends TestCase
{
    public function testDefaultFactoryReturnsInstance(): void
    {
        $km = TabsKeyMap::default();
        $this->assertInstanceOf(TabsKeyMap::class, $km);
    }

    public function testNoWrapFactoryReturnsInstance(): void
    {
        $km = TabsKeyMap::noWrap();
        $this->assertInstanceOf(TabsKeyMap::class, $km);
    }

    public function testDefaultHasNextTabBinding(): void
    {
        $km = TabsKeyMap::default();
        $this->assertInstanceOf(Binding::class, $km->nextTab);
        $this->assertSame(['tab'], $km->nextTab->getKeys());
    }

    public function testDefaultHasPrevTabBinding(): void
    {
        $km = TabsKeyMap::default();
        $this->assertInstanceOf(Binding::class, $km->prevTab);
        $this->assertSame(['shift+tab'], $km->prevTab->getKeys());
    }

    public function testDefaultHasNineJumpBindings(): void
    {
        $km = TabsKeyMap::default();
        $this->assertCount(9, $km->jumpBindings);
        foreach ($km->jumpBindings as $i => $binding) {
            $this->assertInstanceOf(Binding::class, $binding);
            $this->assertSame([(string) ($i + 1)], $binding->getKeys());
        }
    }

    public function testNoWrapHasNineJumpBindings(): void
    {
        $km = TabsKeyMap::noWrap();
        $this->assertCount(9, $km->jumpBindings);
    }

    public function testShortHelpReturnsNavBindings(): void
    {
        $km = TabsKeyMap::default();
        $short = $km->shortHelp();
        $this->assertCount(2, $short);
        $this->assertSame($km->nextTab, $short[0]);
        $this->assertSame($km->prevTab, $short[1]);
    }

    public function testFullHelpReturnsNavPlusJumpColumns(): void
    {
        $km = TabsKeyMap::default();
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
        $km = TabsKeyMap::default();
        foreach ($km->jumpBindings as $i => $binding) {
            $this->assertSame((string) ($i + 1), $binding->help->key);
            $this->assertSame("tab " . ($i + 1), $binding->help->desc);
        }
    }

    public function testNextTabHelpDesc(): void
    {
        $km = TabsKeyMap::default();
        $this->assertSame('tab', $km->nextTab->help->key);
        $this->assertSame('next tab', $km->nextTab->help->desc);
    }

    public function testPrevTabHelpDesc(): void
    {
        $km = TabsKeyMap::default();
        $this->assertSame('shift+tab', $km->prevTab->help->key);
        $this->assertSame('prev tab', $km->prevTab->help->desc);
    }

    public function testInstancesAreIndependent(): void
    {
        $km1 = TabsKeyMap::default();
        $km2 = TabsKeyMap::default();
        $this->assertNotSame($km1, $km2);
        $this->assertNotSame($km1->nextTab, $km2->nextTab);
    }

    public function testJumpBindingsTotalCountIsEleven(): void
    {
        $km = TabsKeyMap::default();
        $allBindings = [$km->nextTab, $km->prevTab, ...$km->jumpBindings];
        $this->assertCount(11, $allBindings);
    }
}
