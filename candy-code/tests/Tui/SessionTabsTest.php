<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Tui;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Tui\SessionTab;
use SugarCraft\Code\Tui\SessionTabs;

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

    public function testUpdateTabAttention(): void
    {
        $tabs = (new SessionTabs())->openTab('s1', 'Session 1');
        $this->assertSame('idle', $tabs->activeTab()?->attention);

        $tabs2 = $tabs->updateTabAttention('s1', 'waiting');
        $this->assertSame('waiting', $tabs2->activeTab()?->attention);
        // The ledger is immutable: the original row keeps its state.
        $this->assertSame('idle', $tabs->activeTab()?->attention);
    }

    public function testUpdateTabAttentionUnknownIdIsANoOp(): void
    {
        $tabs = (new SessionTabs())->openTab('s1', 'Session 1');
        $this->assertSame($tabs, $tabs->updateTabAttention('ghost', 'failed'));
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

    /**
     * Ctrl+Tab arrives as the synthetic CSI 1;5I or as the real bytes a
     * terminal sends (kitty CSI 9;5u, xterm modifyOtherKeys CSI 27;5;9~).
     * Each one must land on the same tab the synthetic constant lands on.
     *
     * @return iterable<string, array{string}>
     */
    public static function forwardTabKeys(): iterable
    {
        yield 'synthetic CSI 1;5I' => [SessionTabs::CTRL_TAB];
        yield 'kitty CSI 9;5u' => ["\x1b[9;5u"];
        yield 'xterm modifyOtherKeys CSI 27;5;9~' => ["\x1b[27;5;9~"];
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function backwardTabKeys(): iterable
    {
        yield 'synthetic CSI 1;6I' => [SessionTabs::CTRL_SHIFT_TAB];
        yield 'kitty CSI 9;6u' => ["\x1b[9;6u"];
        yield 'xterm modifyOtherKeys CSI 27;6;9~' => ["\x1b[27;6;9~"];
    }

    /** @dataProvider forwardTabKeys */
    public function testEveryCtrlTabEncodingCyclesForwardLikeTheSyntheticKey(string $key): void
    {
        $tabs = (new SessionTabs())
            ->openTab('s1', 'Session 1')
            ->openTab('s2', 'Session 2');
        $expected = $tabs->handleKey(SessionTabs::CTRL_TAB);
        $actual = $tabs->handleKey($key);

        $this->assertNotNull($actual);
        $this->assertSame($expected?->activeTab()?->id, $actual->activeTab()?->id);
        $this->assertNotSame('s2', $actual->activeTab()?->id);
    }

    /** @dataProvider backwardTabKeys */
    public function testEveryCtrlShiftTabEncodingCyclesBackwardLikeTheSyntheticKey(string $key): void
    {
        $tabs = (new SessionTabs())
            ->openTab('s1', 'Session 1')
            ->openTab('s2', 'Session 2');
        $expected = $tabs->handleKey(SessionTabs::CTRL_SHIFT_TAB);
        $actual = $tabs->handleKey($key);

        $this->assertNotNull($actual);
        $this->assertSame('s1', $expected?->activeTab()?->id);
        $this->assertSame($expected?->activeTab()?->id, $actual->activeTab()?->id);
    }

    public function testRealCtrlTabBytesStepOneTabForwardFromTheFirstTab(): void
    {
        $tabs = (new SessionTabs())
            ->openTab('s1', 'Session 1')
            ->openTab('s2', 'Session 2')
            ->setActiveTab('s1');
        $stepped = $tabs->handleKey("\x1b[9;5u");

        $this->assertNotNull($stepped);
        $this->assertSame('s2', $stepped->activeTab()?->id);
    }

    /**
     * Only the Tab-with-Ctrl encodings are claimed. Ctrl+Enter, Alt+Tab and
     * a Ctrl+Tab in the wrong CSI family must not cycle the strip.
     *
     * @return iterable<string, array{string}>
     */
    public static function unrelatedKeys(): iterable
    {
        yield 'kitty ctrl+enter CSI 13;5u' => ["\x1b[13;5u"];
        yield 'xterm ctrl+enter CSI 27;5;13~' => ["\x1b[27;5;13~"];
        yield 'kitty alt+tab CSI 9;3u' => ["\x1b[9;3u"];
        yield 'xterm alt+tab CSI 27;3;9~' => ["\x1b[27;3;9~"];
        yield 'kitty ctrl+alt+tab CSI 9;7u' => ["\x1b[9;7u"];
    }

    /** @dataProvider unrelatedKeys */
    public function testNonCtrlTabSequencesAreNotClaimed(string $key): void
    {
        $tabs = (new SessionTabs())->openTab('s1', 'Session 1');
        $this->assertNull($tabs->handleKey($key));
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
