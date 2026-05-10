<?php

declare(strict_types=1);

namespace SugarCraft\Bits\Tests\Tabs;

use PHPUnit\Framework\TestCase;
use SugarCraft\Bits\Tabs\Tabs;
use SugarCraft\Bits\Tabs\TabsKeyMap;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;

final class TabsTest extends TestCase
{
    private function tabs(array $labels = []): Tabs
    {
        return Tabs::new($labels ?: ['Home', 'Profile', 'Settings']);
    }

    private function focused(Tabs $t = null): Tabs
    {
        $t = $t ?? $this->tabs();
        [$t, ] = $t->focus();
        return $t;
    }

    // ── Initial state ────────────────────────────────────────────────────────

    public function testInitialActiveIsZero(): void
    {
        $t = $this->tabs();
        $this->assertSame(0, $t->active());
        $this->assertSame(['Home', 'Profile', 'Settings'], $t->labels());
    }

    public function testDefaultStyles(): void
    {
        $t = $this->tabs();
        $this->assertNotNull($t->activeStyle);
        $this->assertNotNull($t->inactiveStyle);
    }

    public function testDefaultDivider(): void
    {
        $t = $this->tabs();
        $this->assertSame(' │ ', $t->divider);
    }

    // ── Keyboard navigation ──────────────────────────────────────────────────

    public function testTabAdvancesWhenFocused(): void
    {
        $t = $this->focused();
        $this->assertSame(0, $t->active());

        [$t, ] = $t->update(new KeyMsg(KeyType::Tab));
        $this->assertSame(1, $t->active());
    }

    public function testShiftTabGoesBackWhenFocused(): void
    {
        $t = $this->focused();
        [$t, ] = $t->update(new KeyMsg(KeyType::Tab));
        [$t, ] = $t->update(new KeyMsg(KeyType::Tab));
        $this->assertSame(2, $t->active());

        [$t, ] = $t->update(new KeyMsg(KeyType::Tab, shift: true));
        $this->assertSame(1, $t->active());
    }

    public function testKeysIgnoredWhenUnfocused(): void
    {
        $t = $this->tabs();
        [$t, ] = $t->update(new KeyMsg(KeyType::Tab));
        $this->assertSame(0, $t->active());
    }

    public function testJumpToTab1(): void
    {
        $t = $this->focused();
        [$t, ] = $t->update(new KeyMsg(KeyType::Char, '1'));
        $this->assertSame(0, $t->active());
    }

    public function testJumpToTab2(): void
    {
        $t = $this->focused();
        [$t, ] = $t->update(new KeyMsg(KeyType::Char, '2'));
        $this->assertSame(1, $t->active());
    }

    public function testJumpToTab3(): void
    {
        $t = $this->focused();
        [$t, ] = $t->update(new KeyMsg(KeyType::Char, '3'));
        $this->assertSame(2, $t->active());
    }

    public function testJumpToTabOutOfRangeIgnored(): void
    {
        $t = $this->focused(); // 3 tabs: 0,1,2
        [$t, ] = $t->update(new KeyMsg(KeyType::Char, '5'));
        $this->assertSame(0, $t->active());
    }

    // ── Wrap-around ──────────────────────────────────────────────────────────

    public function testTabWrapsAtEnd(): void
    {
        $t = $this->focused();
        // Advance from 0 → 1 → 2 → wrap → 0
        [$t, ] = $t->update(new KeyMsg(KeyType::Tab));
        [$t, ] = $t->update(new KeyMsg(KeyType::Tab));
        $this->assertSame(2, $t->active());
        [$t, ] = $t->update(new KeyMsg(KeyType::Tab));
        $this->assertSame(0, $t->active());
    }

    public function testShiftTabWrapsAtStart(): void
    {
        $t = $this->focused();
        $this->assertSame(0, $t->active());
        [$t, ] = $t->update(new KeyMsg(KeyType::Tab, shift: true));
        $this->assertSame(2, $t->active());
    }

    public function testNoWrapClampsAtEnd(): void
    {
        $t = $this->focused()->noWrap();
        [$t, ] = $t->update(new KeyMsg(KeyType::Tab));
        [$t, ] = $t->update(new KeyMsg(KeyType::Tab));
        $this->assertSame(2, $t->active());
        [$t, ] = $t->update(new KeyMsg(KeyType::Tab));
        $this->assertSame(2, $t->active()); // clamped, not wrapped
    }

    public function testNoWrapClampsAtStart(): void
    {
        $t = $this->focused()->noWrap();
        $this->assertSame(0, $t->active());
        [$t, ] = $t->update(new KeyMsg(KeyType::Tab, shift: true));
        $this->assertSame(0, $t->active()); // clamped, not wrapped
    }

    // ── Rendering ────────────────────────────────────────────────────────────

    public function testViewRendersAllTabs(): void
    {
        $t = $this->tabs();
        $view = $t->view();
        $this->assertStringContainsString('Home', $view);
        $this->assertStringContainsString('Profile', $view);
        $this->assertStringContainsString('Settings', $view);
    }

    public function testViewRendersDividerBetweenTabs(): void
    {
        $t = $this->tabs();
        $view = $t->view();
        $this->assertStringContainsString('│', $view);
    }

    public function testViewEmptyWhenNoLabels(): void
    {
        $t = Tabs::new([]);
        $this->assertSame('', $t->view());
    }

    public function testViewTruncatesWhenWidthExceeded(): void
    {
        $t = Tabs::new(['Home', 'Profile', 'Settings'])->withWidth(20);
        $view = $t->view();
        $this->assertLessThanOrEqual(20, mb_strlen($view, 'UTF-8'));
        $this->assertSame('…', mb_substr($view, -1, 1, 'UTF-8'));
    }

    public function testViewWithZeroWidthDoesNotTruncate(): void
    {
        $t = Tabs::new(['Home', 'Profile', 'Settings'])->withWidth(0);
        $view = $t->view();
        $this->assertStringContainsString('Home', $view);
        $this->assertStringContainsString('Profile', $view);
        $this->assertStringNotContainsString('…', $view);
    }

    // ── Focus / blur ─────────────────────────────────────────────────────────

    public function testFocusReturnsFocusedTabs(): void
    {
        $t = $this->tabs();
        $this->assertFalse($t->focused);
        [$t, ] = $t->focus();
        $this->assertTrue($t->focused);
    }

    public function testBlurClearsFocus(): void
    {
        $t = $this->focused();
        $t = $t->blur();
        $this->assertFalse($t->focused);
    }

    // ── with* mutators ───────────────────────────────────────────────────────

    public function testWithActive(): void
    {
        $t = $this->tabs()->withActive(2);
        $this->assertSame(2, $t->active());
    }

    public function testWithActiveClampsToLast(): void
    {
        $t = $this->tabs()->withActive(99);
        $this->assertSame(2, $t->active());
    }

    public function testWithActiveClampsToZero(): void
    {
        $t = $this->tabs()->withActive(-1);
        $this->assertSame(0, $t->active());
    }

    public function testWithLabels(): void
    {
        $t = $this->tabs()->withLabels(['A', 'B']);
        $this->assertSame(['A', 'B'], $t->labels());
        $this->assertSame(0, $t->active());
    }

    public function testWithLabelsClampsActiveWhenShorter(): void
    {
        $t = Tabs::new(['A', 'B', 'C'])->withActive(2)->withLabels(['X']);
        $this->assertSame(['X'], $t->labels());
        $this->assertSame(0, $t->active()); // clamped from 2 to 0
    }

    public function testWithDivider(): void
    {
        $t = $this->tabs()->withDivider(' / ');
        $this->assertSame(' / ', $t->divider);
        $view = $t->view();
        $this->assertStringContainsString(' / ', $view);
    }

    public function testWithKeyMap(): void
    {
        $km = TabsKeyMap::noWrap();
        $t = $this->tabs()->withKeyMap($km);
        $this->assertSame($km, $t->keyMap);
        // The bindings exist; wrap is controlled by Tabs, not KeyMap
        $this->assertTrue($km->nextTab->matches(new KeyMsg(KeyType::Tab)));
    }

    public function testWithWidth(): void
    {
        $t = $this->tabs()->withWidth(50);
        $this->assertSame(50, $t->width);
    }

    public function testWithWidthRejectsNegative(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->tabs()->withWidth(-1);
    }

    // ── Constructor validation ───────────────────────────────────────────────

    public function testConstructorRejectsNegativeActive(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Tabs(
            active: -1,
            activeStyle: \SugarCraft\Sprinkles\Style::new(),
            inactiveStyle: \SugarCraft\Sprinkles\Style::new(),
            divider: ' │ ',
            keyMap: TabsKeyMap::default(),
            focused: false,
            wrap: true,
            width: 80,
            zoneManager: null,
            labels: ['Home', 'Profile'],
            scrollOffset: 0,
        );
    }

    public function testConstructorRejectsActiveBeyondLabels(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Tabs(
            active: 5,
            activeStyle: \SugarCraft\Sprinkles\Style::new(),
            inactiveStyle: \SugarCraft\Sprinkles\Style::new(),
            divider: ' │ ',
            keyMap: TabsKeyMap::default(),
            focused: false,
            wrap: true,
            width: 80,
            zoneManager: null,
            labels: ['Home', 'Profile'],
            scrollOffset: 0,
        );
    }

    public function testEmptyLabelsAllowsAnyActive(): void
    {
        // Empty labels should not throw even with active=0
        $t = new Tabs(
            active: 0,
            activeStyle: \SugarCraft\Sprinkles\Style::new(),
            inactiveStyle: \SugarCraft\Sprinkles\Style::new(),
            divider: ' │ ',
            keyMap: TabsKeyMap::default(),
            focused: false,
            wrap: true,
            width: 80,
            zoneManager: null,
            labels: [],
            scrollOffset: 0,
        );
        $this->assertSame([], $t->labels());
    }

    // ── TabsKeyMap ───────────────────────────────────────────────────────────

    public function testKeyMapDefaultHasNextAndPrevBindings(): void
    {
        $km = TabsKeyMap::default();
        $this->assertTrue($km->nextTab->enabled());
        $this->assertTrue($km->prevTab->enabled());
        $this->assertCount(9, $km->jumpBindings);
    }

    public function testKeyMapNoWrapHasBindings(): void
    {
        $km = TabsKeyMap::noWrap();
        $this->assertTrue($km->nextTab->enabled());
        $this->assertTrue($km->prevTab->enabled());
    }

    public function testJumpBindingKeys(): void
    {
        $km = TabsKeyMap::default();
        $this->assertSame('1', $km->jumpBindings[0]->getKeys()[0]);
        $this->assertSame('9', $km->jumpBindings[8]->getKeys()[0]);
    }

    public function testShortHelp(): void
    {
        $km = TabsKeyMap::default();
        $help = $km->shortHelp();
        $this->assertCount(2, $help);
    }

    public function testFullHelp(): void
    {
        $km = TabsKeyMap::default();
        $help = $km->fullHelp();
        $this->assertNotEmpty($help);
    }

    // ── Zone / mouse support ─────────────────────────────────────────────────

    public function testViewContainsZoneMarkersWhenManagerIsSet(): void
    {
        $manager = \SugarCraft\Zone\Manager::newPrefix('test');
        $t = $this->tabs()->withWidth(200)->withZoneManager($manager);
        $view = $t->view();
        // Zone markers are APC sequences: ESC _ "candyzone:S:prefix:id" ESC \
        // Prefix is concatenated directly, so "test" + "tab-0" = "testtab-0".
        $this->assertStringContainsString("\x1b_", $view);
        $this->assertStringContainsString('candyzone:S:testtab-0', $view);
        $this->assertStringContainsString('candyzone:S:testtab-1', $view);
    }

    public function testViewWithoutManagerHasNoZoneMarkers(): void
    {
        $t = $this->tabs();
        $view = $t->view();
        $this->assertStringNotContainsString('candyzone:', $view);
    }

    public function testMsgZoneInBoundsActivatesMatchingTab(): void
    {
        $manager = \SugarCraft\Zone\Manager::newPrefix('test');
        $t = $this->tabs()->withZoneManager($manager);
        [$t, ] = $t->focus();

        // Click on tab-1 (Profile).
        $zone = new \SugarCraft\Zone\Zone('test-tab-1', 7, 1, 14, 1);
        $mouse = new \SugarCraft\Core\Msg\MouseMsg(10, 1, \SugarCraft\Core\MouseButton::Left, \SugarCraft\Core\MouseAction::Press);
        $msg = new \SugarCraft\Zone\MsgZoneInBounds($zone, $mouse);
        [$t, ] = $t->update($msg);

        $this->assertSame(1, $t->active());
    }

    public function testMsgZoneInBoundsIgnoredWhenUnfocused(): void
    {
        $manager = \SugarCraft\Zone\Manager::newPrefix('test');
        $t = $this->tabs()->withZoneManager($manager);
        // Not focused.

        $zone = new \SugarCraft\Zone\Zone('test-tab-1', 7, 1, 14, 1);
        $mouse = new \SugarCraft\Core\Msg\MouseMsg(10, 1, \SugarCraft\Core\MouseButton::Left, \SugarCraft\Core\MouseAction::Press);
        $msg = new \SugarCraft\Zone\MsgZoneInBounds($zone, $mouse);
        [$t, ] = $t->update($msg);

        $this->assertSame(0, $t->active()); // unchanged
    }

    public function testMsgZoneInBoundsOutsideVisibleRangeIgnored(): void
    {
        $manager = \SugarCraft\Zone\Manager::newPrefix('test');
        // Tab 2 is outside the default scroll window when width is small.
        $t = $this->tabs()->withWidth(15)->withZoneManager($manager);
        [$t, ] = $t->focus();

        $zone = new \SugarCraft\Zone\Zone('test-tab-2', 30, 1, 38, 1);
        $mouse = new \SugarCraft\Core\Msg\MouseMsg(35, 1, \SugarCraft\Core\MouseButton::Left, \SugarCraft\Core\MouseAction::Press);
        $msg = new \SugarCraft\Zone\MsgZoneInBounds($zone, $mouse);
        [$t, ] = $t->update($msg);

        $this->assertSame(0, $t->active()); // unchanged — tab-2 not visible
    }

    public function testWithZoneManagerReturnsNewInstance(): void
    {
        $manager = \SugarCraft\Zone\Manager::newPrefix('x');
        $t1 = $this->tabs();
        $t2 = $t1->withZoneManager($manager);
        $this->assertNotSame($t1, $t2);
        $this->assertNull($t1->zoneManager);
        $this->assertNotNull($t2->zoneManager);
    }

    // ── Scroll / overflow ────────────────────────────────────────────────────

    public function testViewShowsRightEllipsisWhenTabsExceedWidth(): void
    {
        $t = $this->tabs()->withWidth(20); // " Home  │  Profile  │  Settings " won't fit
        $view = $t->view();
        $this->assertStringContainsString('…', $view);
    }

    public function testViewShowsLeftEllipsisWhenScrolledRight(): void
    {
        $t = $this->tabs()->withWidth(15)->withScrollOffset(1);
        $view = $t->view();
        $this->assertStringContainsString('…', $view);
    }

    public function testViewNoEllipsisWhenAllTabsFit(): void
    {
        $t = $this->tabs()->withWidth(80);
        $view = $t->view();
        $this->assertStringNotContainsString('…', $view);
    }

    public function testWithScrollOffsetReturnsNewInstance(): void
    {
        $t1 = $this->tabs();
        $t2 = $t1->withScrollOffset(1);
        $this->assertNotSame($t1, $t2);
        $this->assertSame(0, $t1->scrollOffset);
        $this->assertSame(1, $t2->scrollOffset);
    }

    public function testAdjustScrollBringsActiveTabIntoView(): void
    {
        $t = $this->tabs()
            ->withWidth(15)
            ->withScrollOffset(0)
            ->withActive(2);
        // Tab 2 is beyond visible range for width=15; activating it should auto-scroll.
        $this->assertGreaterThanOrEqual(2, $t->scrollOffset);
    }

    public function testTabNavigationAdjustsScrollToKeepActiveVisible(): void
    {
        $t = $this->tabs(['A', 'B', 'C', 'D', 'E'])
            ->withWidth(10)
            ->withScrollOffset(0)
            ->focus()[0];

        // With width=10 and labels ['A','B','C','D','E'], each ' A ' = 3 cells.
        // At scrollOffset=0: only tab A fits (cursor=3), tab B needs 9 total (fits),
        // tab C needs 15 (> 10), so scrollEnd=1. Tab 3 is outside [0,1].
        $this->assertSame(1, $t->scrollEnd, 'Sanity: scrollEnd=1 at offset=0, width=10');

        // Activate tab 3, which is outside the visible window.
        $t = $t->withActive(3);
        // scrollOffset should auto-adjust to bring tab 3 into view.
        $this->assertGreaterThanOrEqual(3, $t->scrollOffset);
    }
}
