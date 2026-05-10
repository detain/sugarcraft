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
    private function tabs(): Tabs
    {
        return Tabs::new(['Home', 'Profile', 'Settings']);
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
            labels: ['Home', 'Profile'],
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
            labels: ['Home', 'Profile'],
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
            labels: [],
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
}
