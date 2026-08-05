<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Tui;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Tui\SessionTab;
use SugarCraft\Crush\Tui\SessionTabs;

/**
 * @internal
 */
final class SessionTabsTest extends TestCase
{
    public function testOpenTabMakesNewTabActive(): void
    {
        $tabs = new SessionTabs();
        $tabs2 = $tabs->openTab('session-1', 'Test Session');
        $this->assertSame('session-1', $tabs2->activeTab()?->id);
        $this->assertSame('Test Session', $tabs2->activeTab()?->sessionName);
    }

    public function testOpenTabDeactivatesPrevious(): void
    {
        $tabs = (new SessionTabs())->openTab('s1', 'Session 1');
        $tabs2 = $tabs->openTab('s2', 'Session 2');
        $this->assertFalse($tabs2->activeTab()?->id === 's1');
        $this->assertTrue($tabs2->activeTab()?->id === 's2');
    }

    public function testCloseTabActivatesNext(): void
    {
        $tabs = (new SessionTabs())
            ->openTab('s1', 'Session 1')
            ->openTab('s2', 'Session 2');
        $tabs2 = $tabs->closeTab('s2');
        $this->assertSame('s1', $tabs2->activeTab()?->id);
    }

    public function testCloseTabNoOpOnLastTab(): void
    {
        $tabs = new SessionTabs();
        $id = $tabs->activeTab()?->id ?? '';
        $result = $tabs->closeTab($id);
        $this->assertSame($tabs, $result);
    }

    public function testCloseTabNoOpForNonExistentId(): void
    {
        $tabs = new SessionTabs();
        $result = $tabs->closeTab('nonexistent');
        $this->assertSame($tabs, $result);
    }

    public function testSetActiveTabSwitchesCorrectly(): void
    {
        $tabs = (new SessionTabs())
            ->openTab('s1', 'Session 1')
            ->openTab('s2', 'Session 2');
        $tabs2 = $tabs->setActiveTab('s1');
        $this->assertSame('s1', $tabs2->activeTab()?->id);
    }

    public function testSetActiveTabNoOpForNonExistentId(): void
    {
        $tabs = (new SessionTabs())->openTab('s1', 'Session 1');
        $result = $tabs->setActiveTab('nonexistent');
        $this->assertSame($tabs, $result);
    }

    public function testDetachTab(): void
    {
        $tabs = (new SessionTabs())->openTab('s1', 'Session 1');
        $tabs2 = $tabs->detachTab('s1');
        $this->assertTrue($tabs2->activeTab()?->isDetached);
    }

    public function testReattachTab(): void
    {
        $tabs = (new SessionTabs())
            ->openTab('s1', 'Session 1')
            ->detachTab('s1');
        $tabs2 = $tabs->reattachTab('s1');
        $this->assertFalse($tabs2->activeTab()?->isDetached);
    }

    public function testUpdateTabSummary(): void
    {
        $tabs = (new SessionTabs())->openTab('s1', 'Session 1');
        $tabs2 = $tabs->updateTabSummary('s1', 'Working on auth');
        $this->assertSame('Working on auth', $tabs2->activeTab()?->agentSummary);
    }

    public function testCount(): void
    {
        $tabs = (new SessionTabs())
            ->openTab('s1', 'Session 1')
            ->openTab('s2', 'Session 2');
        $this->assertSame(3, $tabs->count()); // default + 2 opened
    }

    public function testHandleKeyCtrlTabCyclesForward(): void
    {
        $tabs = (new SessionTabs())
            ->openTab('s1', 'Session 1')
            ->openTab('s2', 'Session 2');
        $tabs2 = $tabs->handleKey(SessionTabs::CTRL_TAB);
        $this->assertNotNull($tabs2);
    }

    public function testHandleKeyCtrlShiftTabCyclesBackward(): void
    {
        $tabs = (new SessionTabs())
            ->openTab('s1', 'Session 1')
            ->openTab('s2', 'Session 2');
        $tabs2 = $tabs->handleKey(SessionTabs::CTRL_SHIFT_TAB);
        $this->assertNotNull($tabs2);
    }

    public function testHandleKeyReturnsNullForUnrecognized(): void
    {
        $tabs = new SessionTabs();
        $result = $tabs->handleKey('q');
        $this->assertNull($result);
    }

    public function testImmutability(): void
    {
        $tabs = new SessionTabs();
        $tabs2 = $tabs->openTab('s1', 'Session 1');
        $this->assertNotSame($tabs, $tabs2);
        $this->assertSame(1, $tabs->count());
        $this->assertSame(2, $tabs2->count());
    }

    public function testActiveTabReturnsNullWhenNoTabs(): void
    {
        // Default SessionTabs always has at least one tab from constructor
        $tabs = new SessionTabs();
        $this->assertNotNull($tabs->activeTab());
    }

    public function testTabsReturnsAllTabs(): void
    {
        $tabs = (new SessionTabs())
            ->openTab('s1', 'Session 1')
            ->openTab('s2', 'Session 2');
        $this->assertCount(3, $tabs->tabs()); // default + 2 opened
    }

    public function testCloseTabWhenActiveIsNotLast(): void
    {
        $tabs = (new SessionTabs())
            ->openTab('s1', 'Session 1')
            ->openTab('s2', 'Session 2')
            ->setActiveTab('s1');
        $tabs2 = $tabs->closeTab('s1');
        $this->assertSame('s2', $tabs2->activeTab()?->id);
    }
}
