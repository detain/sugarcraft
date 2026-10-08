<?php

declare(strict_types=1);

namespace SugarCraft\Top\Tests\Overlay;

use SugarCraft\Top\Input\KeyTable;
use SugarCraft\Top\Overlay\HelpMenu;

/** btop Menu::helpMenu: geometry, paging, closing. */
final class HelpMenuTest extends OverlayTestCase
{
    public function testGeometryIsBtops(): void
    {
        $n = count(KeyTable::rows());
        [$x, $y, $height, $pages] = HelpMenu::geometry(80, 24);
        $this->assertSame(1, $x, '80 / 2 - 39');
        $this->assertSame(max(1, 12 - 4 - intdiv($n, 2)), $y);
        $this->assertSame(min(18, $n + 3), $height);
        $this->assertSame((int) ceil($n / ($height - 3)), $pages);
        $this->assertSame(1, HelpMenu::geometry(120, 80)[3], 'a tall terminal fits one page');
    }

    public function testPagingWrapsBothWays(): void
    {
        $ctx = self::ctx(80, 24);
        $pages = HelpMenu::geometry(80, 24)[3];
        $this->assertGreaterThan(1, $pages);
        foreach (['down', 'j', 'page_down', 'tab'] as $k) {
            $r = HelpMenu::new()->update(self::key($k), $ctx);
            $this->assertInstanceOf(HelpMenu::class, $r->overlay);
            $this->assertSame(1, $r->overlay->page, $k);
        }
        foreach (['up', 'k', 'page_up', 'shift_tab'] as $k) {
            $r = HelpMenu::new()->update(self::key($k), $ctx);
            $this->assertInstanceOf(HelpMenu::class, $r->overlay);
            $this->assertSame($pages - 1, $r->overlay->page, $k . ' wraps to the last page');
        }
        $r = HelpMenu::new()->update(self::wheel(true), $ctx);
        $this->assertInstanceOf(HelpMenu::class, $r->overlay);
        $this->assertSame(1, $r->overlay->page);

        $one = HelpMenu::new()->update(self::key('down'), self::ctx(120, 80));
        $this->assertInstanceOf(HelpMenu::class, $one->overlay);
        $this->assertSame(0, $one->overlay->page, 'one page: nothing to turn');
    }

    public function testCloseKeys(): void
    {
        foreach (['escape', 'q', 'h', 'backspace', 'space', 'enter'] as $k) {
            $this->assertNull(HelpMenu::new()->update(self::key($k), self::ctx())->overlay, $k);
        }
        $this->assertNull(HelpMenu::new()->update(self::click(3, 3), self::ctx())->overlay);
        $this->assertNotNull(HelpMenu::new()->update(self::key('x'), self::ctx())->overlay);
        $this->assertSame([80, 24], HelpMenu::new()->minSize());
    }
}
