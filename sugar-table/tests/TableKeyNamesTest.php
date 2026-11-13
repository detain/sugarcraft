<?php

declare(strict_types=1);

namespace SugarCraft\Table\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Input\Event\KeyEvent;
use SugarCraft\Input\KeyModifier;
use SugarCraft\Table\{Column, Row, RowData, Table};

/** OG-4 / ST-22: dual-spelling key names, handleScrollKey, KeyEvent overload. */
final class TableKeyNamesTest extends TestCase
{
    private function table(): Table
    {
        $rows = [];
        for ($i = 0; $i < 20; $i++) {
            $rows[] = Row::new(RowData::from(['a' => (string) $i]));
        }

        return Table::fromColumns([Column::new('a', 'A', 4)])->withRows($rows)->withViewportHeight(5)->withSelectedIndex(10); // cursor pins the window to 6..10 (ST-15)
    }

    public function testBothSpellingsResolveToTheSameScroll(): void
    {
        $t = $this->table();
        foreach (['arrowUp' => 'ArrowUp', 'arrowDown' => 'ArrowDown', 'pageUp' => 'PageUp', 'pageDown' => 'PageDown', 'home' => 'Home', 'end' => 'End'] as $lower => $pascal) {
            $this->assertSame($t->scrollYForKey($lower), $t->scrollYForKey($pascal), $pascal);
        }
        $this->assertSame(7, $t->scrollYForKey('ArrowDown'));
        $this->assertSame(15, $t->scrollYForKey('END'));
    }

    public function testUnknownKeyIsStillANoOp(): void
    {
        $t = $this->table();
        $this->assertSame(6, $t->scrollYForKey('Enter'));
    }

    public function testHandleScrollKeyAndDeprecatedAliasAgree(): void
    {
        $t = $this->table();
        $this->assertSame($t->handleScrollKey('PageDown')->scrollY(), $t->handleKey(Table::KEY_PAGE_DOWN)->scrollY());
        $this->assertSame(10, $t->handleScrollKey('PageDown')->scrollY());
    }

    public function testHandleScrollKeyIsANoOpWithoutAViewport(): void
    {
        $t = $this->table()->withViewportHeight(0);
        $this->assertSame(0, $t->handleScrollKey('End')->scrollY());
    }

    public function testKeyEventOverload(): void
    {
        $t = $this->table();
        $this->assertSame(7, $t->handleScrollKey(KeyEvent::plain('ArrowDown'))->scrollY());
        $release = new KeyEvent('ReleaseArrowDown', KeyModifier::none(), '');
        $this->assertSame(6, $t->handleScrollKey($release)->scrollY());
        $this->assertSame(6, $t->scrollYForKey($release));
    }
}
