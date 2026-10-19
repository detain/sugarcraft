<?php

declare(strict_types=1);

namespace SugarCraft\Top\Tests\Overlay;

use SugarCraft\Top\Input\KeyTable;
use SugarCraft\Top\Overlay\HelpMenu;

/** btop Menu::helpMenu: adaptive geometry, grid paging, closing. */
final class HelpMenuTest extends OverlayTestCase
{
    public function testWidthIsAdaptive(): void
    {
        $this->assertSame(78, HelpMenu::widthFor(80), 'btop 78 at the 80-column minimum');
        $this->assertSame(78, HelpMenu::widthFor(79), 'never below 78');
        $this->assertSame(98, HelpMenu::widthFor(100), 'mid-size: cols - 2');
        $this->assertSame(118, HelpMenu::widthFor(120), 'cols - 2 again — only a 166-wide terminal or wider saturates the cap');
        $this->assertSame(148, HelpMenu::widthFor(150), 'mid-range: cols - 2');
        $this->assertSame(166, HelpMenu::widthFor(168), 'the 166 ceiling engages from 168 columns up (cols - 2 reaches it)');
        $this->assertSame(166, HelpMenu::widthFor(170), 'the 166 ceiling');
        $this->assertSame(166, HelpMenu::widthFor(300));
        $this->assertSame(2, HelpMenu::columnsFor(78));
        $this->assertSame(2, HelpMenu::columnsFor(109));
        $this->assertSame(2, HelpMenu::columnsFor(110), 'the third column is gone — every width gets the framed pair');
        $this->assertSame(2, HelpMenu::columnsFor(166));
        $this->assertSame(37, HelpMenu::columnWidth(78, 2), '(78 - 2 - 2) / 2');
        $this->assertSame(81, HelpMenu::columnWidth(166, 2), '(166 - 2 - 2) / 2 — 79 cells inside the frame');
        $this->assertSame(59, HelpMenu::columnWidth(166, 2) - 2 - HelpMenu::KEY_COL, 'the widest box leaves a 59-cell description column — zero roster descriptions wrap in it');
    }

    public function testGeometryIsBtops(): void
    {
        $n = count(KeyTable::rows());
        [$x, $y, $height, $pages] = HelpMenu::geometry(80, 24);
        $this->assertSame(1, $x, 'max(1, 80/2 - 78/2) — the 78 box centred in 80');
        $this->assertSame(max(1, 12 - 4 - intdiv($n, 2)), $y);
        $this->assertSame(min(18, $n + 3), $height);
        // The box is 78 wide here: framed columns are 35 cells inside the
        // side borders, descriptions wrap under a 20-cell key column at
        // descW = 15, and boxes are atomic — sections over one column's
        // 15 rows continue as fresh framed segments, so the plan costs 9
        // pages of 2 x (18 - 3) rows where the flat line stream took 6.
        $this->assertSame(9, $pages, 'framed box flow, not the flat 177-line grid');

        [$wx, $wy, $wh, $wp] = HelpMenu::geometry(120, 40);
        $this->assertSame(1, $wx, 'max(1, 120/2 - 118/2)');
        $this->assertSame(max(1, 20 - 4 - intdiv($n, 2)), $wy);
        $this->assertSame(min(34, $n + 3), $wh);
        $this->assertSame(3, $wp, 'sections over a 2 x (34 - 3) = 62-row page: General+Panels+Navigation, Process list..Overlays, Mouse+Info');

        $this->assertSame(1, HelpMenu::geometry(120, 80)[3], 'a tall terminal fits every section box on one 120-row page');
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
