<?php

declare(strict_types=1);

namespace SugarCraft\Widgets\Tests\Tabs;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\MouseAction;
use SugarCraft\Core\MouseButton;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Core\Msg\MouseMsg;
use SugarCraft\Core\Util\Color;
use SugarCraft\Mouse\Mark;
use SugarCraft\Mouse\Scan;
use SugarCraft\Mouse\Scanner;
use SugarCraft\Mouse\Sentinel;
use SugarCraft\Mouse\Zones\Manager;
use SugarCraft\Mouse\Zones\MsgZoneInBounds;
use SugarCraft\Mouse\Zones\Zone;
use SugarCraft\Style\Style;
use SugarCraft\Widgets\Tabs\TabClosedMsg;
use SugarCraft\Widgets\Tabs\Tabs;
use SugarCraft\Widgets\Tabs\TabSpan;

/**
 * P2-2: closable and badged tabs, moveTab, the span API, Mark zones, the
 * interactive factory and the empty-bar placeholder.
 */
final class TabsInteractiveTest extends TestCase
{
    /** A bar with unstyled tabs, so frames compare as plain text. */
    private function plain(array $labels = ['Home', 'Inbox', 'Settings'], int $width = 0): Tabs
    {
        return Tabs::new($labels, $width)->withActiveStyle(Style::new());
    }

    private function ctrlW(): KeyMsg
    {
        return new KeyMsg(KeyType::Char, 'w', ctrl: true);
    }

    private function zoneClick(string $id): MsgZoneInBounds
    {
        $mouse = new MouseMsg(1, 1, MouseButton::Left, MouseAction::Press);
        return new MsgZoneInBounds(new Zone($id, 1, 1, 2, 1), $mouse);
    }

    // ── (a) closable tabs ────────────────────────────────────────────────────

    public function testNotClosableByDefault(): void
    {
        $t = $this->plain();
        $this->assertFalse($t->closable);
        $this->assertSame(' Home  │  Inbox  │  Settings ', $t->view());
    }

    public function testClosableBarDrawsCloseAffordanceOnEveryTab(): void
    {
        $t = $this->plain()->withClosable();
        $this->assertTrue($t->closable);
        $this->assertSame(' Home ×  │  Inbox ×  │  Settings × ', $t->view());
    }

    public function testWithClosableFalseRestoresPlainBar(): void
    {
        $t = $this->plain()->withClosable()->withClosable(false);
        $this->assertSame(' Home  │  Inbox  │  Settings ', $t->view());
    }

    public function testCustomCloseGlyph(): void
    {
        $t = $this->plain(['A'])->withClosable()->withCloseGlyph('x');
        $this->assertSame('x', $t->closeGlyph);
        $this->assertSame(' A x ', $t->view());
    }

    public function testEmptyCloseGlyphThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('tab close glyph must not be empty');
        $this->plain()->withCloseGlyph('');
    }

    public function testCloseGlyphIsSanitized(): void
    {
        $t = $this->plain(['A'])->withClosable()->withCloseGlyph("\x1b[31mx");
        $this->assertStringNotContainsString("\x1b", $t->closeGlyph);
    }

    public function testCloseKeyClosesActiveTabAndEmitsClosedMsg(): void
    {
        $t = $this->plain()->withClosable()->withActive(1)->focus();
        [$next, $cmd] = $t->update($this->ctrlW());

        $this->assertInstanceOf(Tabs::class, $next);
        $this->assertSame(['Home', 'Settings'], $next->labels());
        $this->assertSame(1, $next->active(), 'the right-hand neighbor takes over');
        $this->assertNotNull($cmd);
        $msg = $cmd();
        $this->assertInstanceOf(TabClosedMsg::class, $msg);
        $this->assertSame(1, $msg->index);
        $this->assertSame('Inbox', $msg->label);
        $this->assertSame($t->zoneKey, $msg->zoneKey);
    }

    public function testCloseKeyIgnoredWhenNotClosable(): void
    {
        $t = $this->plain()->focus();
        [$next, $cmd] = $t->update($this->ctrlW());
        $this->assertSame($t, $next);
        $this->assertNull($cmd);
    }

    public function testCloseKeyIgnoredWhenUnfocused(): void
    {
        $t = $this->plain()->withClosable();
        [$next, $cmd] = $t->update($this->ctrlW());
        $this->assertSame(['Home', 'Inbox', 'Settings'], $next->labels());
        $this->assertNull($cmd);
    }

    public function testCloseZoneClickClosesTabOnUnfocusedBar(): void
    {
        $manager = Manager::newPrefix('m');
        $t = $this->plain()->withClosable()->withZoneManager($manager);
        $this->assertFalse($t->focused);

        [$next, $cmd] = $t->update($this->zoneClick('m' . $t->zoneKey . 'close-2'));

        $this->assertSame(['Home', 'Inbox'], $next->labels());
        $this->assertFalse($next->focused, 'a close click does not grant focus');
        $this->assertSame(2, $cmd()->index);
    }

    public function testCloseZoneIgnoredWhenNotClosable(): void
    {
        $manager = Manager::newPrefix('m');
        $t = $this->plain()->withZoneManager($manager);
        [$next, $cmd] = $t->update($this->zoneClick('m' . $t->zoneKey . 'close-0'));
        $this->assertSame($t, $next);
        $this->assertNull($cmd);
    }

    public function testClosableBarMarksCloseZoneSeparately(): void
    {
        $manager = Manager::newPrefix('m');
        $t = $this->plain(['A', 'B'])->withClosable()->withZoneManager($manager);
        $view = $t->view();
        $this->assertStringContainsString(Sentinel::OPEN . 'm' . $t->zoneKey . 'tab-1' . Sentinel::CLOSE . ' B ', $view);
        $this->assertStringContainsString(Sentinel::OPEN . 'm' . $t->zoneKey . 'close-1' . Sentinel::CLOSE . '× ', $view);
        $this->assertSame(' A ×  │  B × ', Scan::strip($view));
    }

    public function testCloseTabKeepsActiveTabWhenAnotherCloses(): void
    {
        $t = $this->plain()->withActive(2);
        $next = $t->closeTab(0);
        $this->assertSame(['Inbox', 'Settings'], $next->labels());
        $this->assertSame(1, $next->active(), 'Settings stays active at its new index');

        $after = $t->closeTab(1)->closeTab(0);
        $this->assertSame(['Settings'], $after->labels());
        $this->assertSame(0, $after->active());
    }

    public function testClosingLastActiveTabActivatesLeftNeighbor(): void
    {
        $next = $this->plain()->withActive(2)->closeTab(2);
        $this->assertSame(['Home', 'Inbox'], $next->labels());
        $this->assertSame(1, $next->active());
    }

    public function testClosingOnlyTabLeavesEmptyBar(): void
    {
        $next = $this->plain(['Only'])->withBadge(0, '1')->closeTab(0);
        $this->assertSame([], $next->labels());
        $this->assertSame([], $next->badges);
        $this->assertSame(0, $next->active());
        $this->assertSame('', $next->view());
    }

    public function testCloseTabIsImmutable(): void
    {
        $t = $this->plain();
        $next = $t->closeTab(0);
        $this->assertNotSame($t, $next);
        $this->assertSame(['Home', 'Inbox', 'Settings'], $t->labels());
    }

    public function testCloseTabOutOfRangeThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('tab index out of range');
        $this->plain()->closeTab(3);
    }

    public function testCloseTabOnEmptyBarThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Tabs::new()->closeTab(0);
    }

    public function testCloseShiftsBadgesWithTheirTabs(): void
    {
        $t = $this->plain()->withBadge(2, '9')->closeTab(0);
        $this->assertSame([1 => '9'], $t->badges);
    }

    public function testTabClosedMsgFactory(): void
    {
        $msg = TabClosedMsg::new(3, 'Logs', 'bar-');
        $this->assertSame(3, $msg->index);
        $this->assertSame('Logs', $msg->label);
        $this->assertSame('bar-', $msg->zoneKey);
    }

    // ── (b) badged tabs ──────────────────────────────────────────────────────

    public function testBadgeRendersAfterLabel(): void
    {
        $t = $this->plain()->withBadge(1, '3');
        $this->assertSame('3', $t->badge(1));
        $this->assertNull($t->badge(0));
        $this->assertSame(' Home  │  Inbox 3  │  Settings ', $t->view());
    }

    public function testBadgeSitsBeforeCloseAffordance(): void
    {
        $t = $this->plain(['Inbox'])->withBadge(0, '!')->withClosable();
        $this->assertSame(' Inbox ! × ', $t->view());
    }

    public function testNullOrEmptyBadgeRemovesIt(): void
    {
        $t = $this->plain()->withBadge(0, '1')->withBadge(1, '2');
        $this->assertSame([0 => '1', 1 => '2'], $t->badges);
        $this->assertSame([1 => '2'], $t->withBadge(0, null)->badges);
        $this->assertSame([0 => '1'], $t->withBadge(1, '')->badges);
    }

    public function testBadgeOutOfRangeThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('tab index out of range');
        $this->plain()->withBadge(5, '1');
    }

    public function testBadgeIsImmutable(): void
    {
        $t = $this->plain();
        $next = $t->withBadge(0, '1');
        $this->assertNotSame($t, $next);
        $this->assertSame([], $t->badges);
    }

    public function testBadgeIsSanitized(): void
    {
        $t = $this->plain(['A'])->withBadge(0, "\x1b[31m!" . Sentinel::OPEN);
        $view = $t->view();
        $this->assertStringNotContainsString("\x1b[31m", $view);
        $this->assertStringNotContainsString(Sentinel::OPEN, $view);
    }

    public function testBadgeStyleAppliesToBadgeOnly(): void
    {
        $red = Style::new()->foreground(Color::hex('#ff0000'));
        $t = $this->plain(['A'])->withBadge(0, '3')->withBadgeStyle($red);
        $this->assertSame($red, $t->badgeStyle);
        $this->assertSame(' A ' . $red->render('3 '), $t->view());
        $this->assertSame(' A 3 ', $t->withBadgeStyle(null)->view());
    }

    public function testBadgeWidensTheTabForScrolling(): void
    {
        // ' A ' fits a 3-wide window alone; with a badge the tab is wider,
        // so the window still holds it (first tab is always kept) but the
        // reported span grows by the badge cells.
        $t = $this->plain(['Alpha', 'Beta']);
        $this->assertSame(7, $t->spans()[0]->width());
        $this->assertSame(10, $t->withBadge(0, '12')->spans()[0]->width());
    }

    public function testWithLabelsDropsBadgesPastTheNewEnd(): void
    {
        $t = $this->plain()->withBadge(0, 'a')->withBadge(2, 'c')->withLabels(['X', 'Y']);
        $this->assertSame([0 => 'a'], $t->badges);
    }

    // ── (c) moveTab ──────────────────────────────────────────────────────────

    public function testMoveTabForward(): void
    {
        $t = $this->plain(['a', 'b', 'c', 'd'])->moveTab(0, 2);
        $this->assertSame(['b', 'c', 'a', 'd'], $t->labels());
    }

    public function testMoveTabBackward(): void
    {
        $t = $this->plain(['a', 'b', 'c', 'd'])->moveTab(3, 1);
        $this->assertSame(['a', 'd', 'b', 'c'], $t->labels());
    }

    public function testActiveTabFollowsTheMove(): void
    {
        $t = $this->plain(['a', 'b', 'c'])->withActive(0)->moveTab(0, 2);
        $this->assertSame(2, $t->active());
        $this->assertSame('a', $t->labels()[$t->active()]);
    }

    public function testActiveTabBetweenShiftsWithTheMove(): void
    {
        $t = $this->plain(['a', 'b', 'c'])->withActive(1)->moveTab(0, 2);
        $this->assertSame('b', $t->labels()[$t->active()]);
        $this->assertSame(0, $t->active());

        $u = $this->plain(['a', 'b', 'c'])->withActive(1)->moveTab(2, 0);
        $this->assertSame('b', $u->labels()[$u->active()]);
        $this->assertSame(2, $u->active());
    }

    public function testBadgesFollowTheMove(): void
    {
        $t = $this->plain(['a', 'b', 'c'])->withBadge(0, '!')->withBadge(1, '2')->moveTab(0, 2);
        $this->assertSame([0 => '2', 2 => '!'], $t->badges);
    }

    public function testMoveTabToSameIndexIsNoOp(): void
    {
        $t = $this->plain();
        $this->assertSame($t, $t->moveTab(1, 1));
    }

    public function testMoveTabIsImmutable(): void
    {
        $t = $this->plain();
        $next = $t->moveTab(0, 1);
        $this->assertNotSame($t, $next);
        $this->assertSame(['Home', 'Inbox', 'Settings'], $t->labels());
    }

    public function testMoveTabOutOfRangeThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('tab index out of range');
        $this->plain()->moveTab(0, 3);
    }

    public function testMoveTabNegativeSourceThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->plain()->moveTab(-1, 0);
    }

    public function testMoveTabScrollsToKeepActiveVisible(): void
    {
        $labels = ['One', 'Two', 'Three', 'Four', 'Five', 'Six'];
        $t = $this->plain($labels, 20)->withActive(0)->moveTab(0, 5);
        $this->assertSame(5, $t->active());
        $this->assertGreaterThanOrEqual($t->scrollOffset, 5);
        $this->assertLessThanOrEqual($t->scrollEnd, 5);
    }

    // ── (d) span API ─────────────────────────────────────────────────────────

    public function testSpansReportEachTabsCells(): void
    {
        // ' Home ' [0,6) · ' │ ' · ' Inbox ' [9,16) · ' │ ' · ' Settings ' [19,29)
        $spans = $this->plain()->spans();
        $this->assertCount(3, $spans);
        $this->assertSame([0, 0, 6, null], [$spans[0]->index, $spans[0]->start, $spans[0]->end, $spans[0]->closeStart]);
        $this->assertSame([1, 9, 16], [$spans[1]->index, $spans[1]->start, $spans[1]->end]);
        $this->assertSame([2, 19, 29], [$spans[2]->index, $spans[2]->start, $spans[2]->end]);
    }

    public function testSpansReportCloseAffordanceCells(): void
    {
        // ' Home ' then '× ' → close affordance starts at cell 6.
        $span = $this->plain()->withClosable()->spans()[0];
        $this->assertSame(0, $span->start);
        $this->assertSame(8, $span->end);
        $this->assertSame(6, $span->closeStart);
    }

    public function testSpansSkipScrolledOutTabsAndPriceTheLeftEllipsis(): void
    {
        $labels = ['One', 'Two', 'Three', 'Four', 'Five', 'Six'];
        $t = $this->plain($labels, 20)->withScrollOffset(2);
        $spans = $t->spans();
        $this->assertSame(2, $spans[0]->index);
        // '…' + ' │ ' occupy four cells before the first visible tab.
        $this->assertSame(4, $spans[0]->start);
        $this->assertSame($t->scrollEnd, $spans[count($spans) - 1]->index);
    }

    public function testSpansMatchZonesTheManagerScans(): void
    {
        $manager = Manager::newPrefix('s');
        $t = $this->plain(['Home', 'Inbox', 'Settings', 'Logs'], 30)
            ->withBadge(1, '7')
            ->withClosable()
            ->withZoneManager($manager);
        $manager->scan($t->view());

        foreach ($t->spans() as $span) {
            $zone = $manager->get('s' . $t->zoneKey . "tab-{$span->index}");
            $this->assertNotNull($zone, "tab {$span->index} has a zone");
            $this->assertSame($span->start + 1, $zone->startCol, 'zones are 1-based');
            if ($span->closeStart !== null) {
                $close = $manager->get('s' . $t->zoneKey . "close-{$span->index}");
                $this->assertNotNull($close);
                $this->assertSame($span->closeStart + 1, $close->startCol);
                $this->assertSame($span->end, $close->endCol);
            } else {
                $this->assertSame($span->end, $zone->endCol);
            }
        }
    }

    public function testSpansOfEmptyBarAreEmpty(): void
    {
        $this->assertSame([], Tabs::new()->spans());
    }

    public function testTabAtResolvesColumns(): void
    {
        $t = $this->plain();
        $this->assertSame(0, $t->tabAt(0));
        $this->assertSame(0, $t->tabAt(5));
        $this->assertNull($t->tabAt(7), 'the divider is not a tab');
        $this->assertSame(1, $t->tabAt(9));
        $this->assertSame(2, $t->tabAt(28));
        $this->assertNull($t->tabAt(29));
        $this->assertNull($t->tabAt(-1));
    }

    public function testClickAtActivatesTabOnUnfocusedBar(): void
    {
        $t = $this->plain();
        [$next, $cmd] = $t->clickAt(10);
        $this->assertSame(1, $next->active());
        $this->assertFalse($next->focused);
        $this->assertNull($cmd);
    }

    public function testClickAtCloseAffordanceClosesTab(): void
    {
        $t = $this->plain()->withClosable();
        [$next, $cmd] = $t->clickAt(6);
        $this->assertSame(['Inbox', 'Settings'], $next->labels());
        $this->assertSame(0, $cmd()->index);
    }

    public function testClickAtDividerLeavesBarUnchanged(): void
    {
        $t = $this->plain();
        [$next, $cmd] = $t->clickAt(7);
        $this->assertSame($t, $next);
        $this->assertNull($cmd);
    }

    public function testTabSpanValueObject(): void
    {
        $span = new TabSpan(index: 2, start: 4, end: 10, closeStart: 8);
        $this->assertSame(6, $span->width());
        $this->assertTrue($span->contains(4));
        $this->assertTrue($span->contains(9));
        $this->assertFalse($span->contains(10));
        $this->assertFalse($span->onClose(7));
        $this->assertTrue($span->onClose(8));
        $this->assertFalse((new TabSpan(0, 0, 5))->onClose(4));
    }

    // ── (e) Mark zones ───────────────────────────────────────────────────────

    public function testMarkPublishesUnprefixedZones(): void
    {
        $t = $this->plain(['A', 'B'])->withMark(Mark::new());
        $view = $t->view();
        $this->assertStringContainsString(Sentinel::OPEN . $t->zoneKey . 'tab-0' . Sentinel::CLOSE . ' A ', $view);
        $this->assertStringContainsString(Sentinel::OPEN . $t->zoneKey . 'tab-1' . Sentinel::CLOSE . ' B ', $view);
        $this->assertSame(' A  │  B ', Scan::strip($view));
    }

    public function testDisabledMarkEmitsNoSentinels(): void
    {
        $t = $this->plain(['A', 'B'])->withMark(Mark::disabled());
        $this->assertSame(' A  │  B ', $t->view());
    }

    public function testScannerHitOnMarkZoneRoutesThroughClickZone(): void
    {
        $t = $this->plain()->withClosable()->withMark(Mark::new());
        $scanner = Scanner::new()->scan($t->view());
        $span = $t->spans()[2];

        $hit = $scanner->hit($span->start + 1, 1);
        $this->assertNotNull($hit);
        [$next, $cmd] = $t->clickZone($hit->id);
        $this->assertSame(2, $next->active());
        $this->assertNull($cmd);

        $closeHit = $scanner->hit($span->closeStart + 1, 1);
        [$closed, $closeCmd] = $t->clickZone($closeHit->id);
        $this->assertSame(['Home', 'Inbox'], $closed->labels());
        $this->assertInstanceOf(TabClosedMsg::class, $closeCmd());
    }

    public function testClickZoneIgnoresForeignIds(): void
    {
        $t = $this->plain()->withMark(Mark::new());
        $other = $this->plain()->withMark(Mark::new());
        [$next, $cmd] = $t->clickZone($other->zoneKey . 'tab-1');
        $this->assertSame($t, $next);
        $this->assertNull($cmd);
        $this->assertSame($t, $t->clickZone($t->zoneKey . 'tab-x')[0]);
    }

    public function testClickZoneIgnoresTabsOutsideTheWindow(): void
    {
        $t = $this->plain(['One', 'Two', 'Three', 'Four', 'Five', 'Six'], 20)->withMark(Mark::new());
        [$next] = $t->clickZone($t->zoneKey . 'tab-5');
        $this->assertSame(0, $next->active());
    }

    public function testClickZoneWithoutMarkupIsInert(): void
    {
        $t = $this->plain();
        $this->assertSame($t, $t->clickZone($t->zoneKey . 'tab-1')[0]);
    }

    public function testWithMarkNullDetaches(): void
    {
        $t = $this->plain(['A'])->withMark(Mark::new())->withMark(null);
        $this->assertNull($t->mark);
        $this->assertSame(' A ', $t->view());
    }

    public function testManagerTakesPrecedenceOverMark(): void
    {
        $manager = Manager::newPrefix('mgr');
        $t = $this->plain(['A'])->withMark(Mark::new())->withZoneManager($manager);
        $this->assertStringContainsString(Sentinel::OPEN . 'mgr' . $t->zoneKey . 'tab-0', $t->view());
    }

    public function testMarkedClippedBarKeepsWholeMarkerPairs(): void
    {
        $t = Tabs::new(['Supercalifragilistic'], 10)->withClosable()->withMark(Mark::new());
        $view = $t->view();
        $this->assertSame(substr_count($view, Sentinel::OPEN), 2 * count(Scan::parse($view)));
        $this->assertLessThanOrEqual(10, \SugarCraft\Core\Util\Width::string(Scan::strip($view)));
    }

    // ── (f) Tabs::interactive ────────────────────────────────────────────────

    public function testInteractiveWithManager(): void
    {
        $manager = Manager::newPrefix('i');
        $t = Tabs::interactive(['A', 'B'], $manager);
        $this->assertSame($manager, $t->zoneManager);
        $this->assertNull($t->mark);
        $this->assertFalse($t->focused);
        $this->assertSame(0, $t->width);

        [$next, $cmd] = $t->update($this->zoneClick('i' . $t->zoneKey . 'tab-1'));
        $this->assertInstanceOf(Tabs::class, $next);
        $this->assertSame(1, $next->active());
        $this->assertNull($cmd);
    }

    public function testInteractiveWithMark(): void
    {
        $mark = Mark::new();
        $t = Tabs::interactive(['A', 'B'], $mark, 40);
        $this->assertSame($mark, $t->mark);
        $this->assertNull($t->zoneManager);
        $this->assertSame(40, $t->width);
        $this->assertSame(1, $t->clickZone($t->zoneKey . 'tab-1')[0]->active());
    }

    public function testInteractiveUpdateReturnsTabsForKeys(): void
    {
        $t = Tabs::interactive(['A', 'B'], Manager::newPrefix('k'))->focus();
        [$next, $cmd] = $t->update(new KeyMsg(KeyType::Tab));
        $this->assertInstanceOf(Tabs::class, $next);
        $this->assertSame(1, $next->active());
        $this->assertNull($cmd);
    }

    public function testInteractiveClosableFlowEndToEnd(): void
    {
        $manager = Manager::newPrefix('e');
        $t = Tabs::interactive(['Chat', 'Logs', 'Diff'], $manager)->withClosable()->withBadge(1, '4');
        $manager->scan($t->view());

        $close = $manager->get('e' . $t->zoneKey . 'close-1');
        $this->assertNotNull($close);
        $mouse = new MouseMsg($close->startCol, 1, MouseButton::Left, MouseAction::Press);
        [$t, $cmd] = $manager->anyInBoundsAndUpdate($t, $mouse);

        $this->assertSame(['Chat', 'Diff'], $t->labels());
        $this->assertSame([], $t->badges);
        $this->assertSame('Logs', $cmd()->label);
    }

    // ── (g) the empty-bar placeholder ────────────────────────────────────────

    public function testEmptyBarRendersNothingByDefault(): void
    {
        $this->assertSame('', Tabs::new()->placeholder);
        $this->assertSame('', Tabs::new()->view());
    }

    public function testPlaceholderRendersOnEmptyBar(): void
    {
        $this->assertSame('no sessions', Tabs::new()->withPlaceholder('no sessions')->view());
    }

    public function testPlaceholderUsesInactiveStyle(): void
    {
        $dim = Style::new()->faint();
        $t = Tabs::new()->withInactiveStyle($dim)->withPlaceholder('none');
        $this->assertSame($dim->render('none'), $t->view());
    }

    public function testPlaceholderIsClippedToWidth(): void
    {
        $this->assertSame('no s…', Tabs::new([], 5)->withPlaceholder('no sessions')->view());
    }

    public function testPlaceholderIsSanitized(): void
    {
        $view = Tabs::new()->withPlaceholder("none\x1b[31m" . Sentinel::OPEN)->view();
        $this->assertStringNotContainsString("\x1b[31m", $view);
        $this->assertStringNotContainsString(Sentinel::OPEN, $view);
    }

    public function testPlaceholderIgnoredWhileTabsExist(): void
    {
        $t = $this->plain(['A'])->withPlaceholder('none');
        $this->assertSame(' A ', $t->view());
        $this->assertSame('none', $t->closeTab(0)->view());
    }
}
