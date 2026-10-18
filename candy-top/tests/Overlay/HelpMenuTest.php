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
        $this->assertSame(117, HelpMenu::widthFor(120), 'the +50% box caps out at 117');
        $this->assertSame(117, HelpMenu::widthFor(300));
        $this->assertSame(2, HelpMenu::columnsFor(78));
        $this->assertSame(2, HelpMenu::columnsFor(109));
        $this->assertSame(3, HelpMenu::columnsFor(110));
        $this->assertSame(3, HelpMenu::columnsFor(117));
        $this->assertSame(37, HelpMenu::columnWidth(78, 2), '(78 - 2 - 2) / 2');
        $this->assertSame(37, HelpMenu::columnWidth(117, 3), 'floor((117 - 2 - 4) / 3)');
    }

    public function testGeometryIsBtops(): void
    {
        $n = count(KeyTable::rows());
        [$x, $y, $height, $pages] = HelpMenu::geometry(80, 24);
        $this->assertSame(1, $x, 'max(1, 80/2 - 78/2) — the 78 box centred in 80');
        $this->assertSame(max(1, 12 - 4 - intdiv($n, 2)), $y);
        $this->assertSame(min(18, $n + 3), $height);
        // The box is 78 wide here, so both columns are 37 cells and every
        // description wraps word-aware under its 20-cell key column at
        // descW = 17: 10 section headings + 167 row lines (8 single, 12
        // doubled, 25 trebled, 15 four-lined) = 177 grid lines, over a
        // 2 x (18 - 3) = 30-line page.
        $this->assertSame(6, $pages, 'grid lines over the two-column page, not the flat 60-row roster');

        [$wx, $wy, $wh, $wp] = HelpMenu::geometry(120, 40);
        $this->assertSame(2, $wx, 'max(1, 120/2 - 117/2)');
        $this->assertSame(max(1, 20 - 4 - intdiv($n, 2)), $wy);
        $this->assertSame(min(34, $n + 3), $wh);
        $this->assertSame(2, $wp, '177 grid lines over a 3 x (34 - 3) = 93-line page');

        $this->assertSame(1, HelpMenu::geometry(120, 80)[3], 'a tall terminal fits the whole 177-line grid on one 180-line page');
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
