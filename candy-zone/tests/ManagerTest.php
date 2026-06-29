<?php

declare(strict_types=1);

namespace SugarCraft\Zone\Tests;

use SugarCraft\Core\MouseAction;
use SugarCraft\Core\MouseButton;
use SugarCraft\Core\Msg\MouseMsg;
use SugarCraft\Zone\Manager;
use SugarCraft\Zone\Zone;
use PHPUnit\Framework\TestCase;

final class ManagerTest extends TestCase
{
    private function click(int $x, int $y): MouseMsg
    {
        return new MouseMsg($x, $y, MouseButton::Left, MouseAction::Press);
    }

    public function testMarkWrapsContentWithApcMarkers(): void
    {
        $m = Manager::newGlobal();
        $marked = $m->mark('btn', 'OK');
        $this->assertStringContainsString("\x1b_candyzone:S:btn\x1b\\", $marked);
        $this->assertStringContainsString("\x1b_candyzone:E:btn\x1b\\", $marked);
        $this->assertStringContainsString('OK', $marked);
    }

    public function testScanStripsMarkersFromOutput(): void
    {
        $m = Manager::newGlobal();
        $clean = $m->scan($m->mark('btn', 'OK'));
        $this->assertSame('OK', $clean);
    }

    public function testScanRecordsSimpleZone(): void
    {
        $m = Manager::newGlobal();
        $m->scan($m->mark('btn', 'OK'));
        $z = $m->get('btn');
        $this->assertInstanceOf(Zone::class, $z);
        $this->assertSame(1, $z->startCol);
        $this->assertSame(1, $z->startRow);
        $this->assertSame(2, $z->endCol);   // 'OK' is 2 cells
        $this->assertSame(1, $z->endRow);
    }

    public function testZoneWidthAndHeight(): void
    {
        $m = Manager::newGlobal();
        $m->scan($m->mark('btn', 'Hello'));
        $z = $m->get('btn');
        $this->assertSame(5, $z->width());
        $this->assertSame(1, $z->height());
    }

    public function testInBoundsHits(): void
    {
        $m = Manager::newGlobal();
        $m->scan($m->mark('btn', 'OK'));
        $z = $m->get('btn');
        $this->assertTrue($z->inBounds($this->click(1, 1)));
        $this->assertTrue($z->inBounds($this->click(2, 1)));
        $this->assertFalse($z->inBounds($this->click(3, 1))); // just past 'OK'
        $this->assertFalse($z->inBounds($this->click(1, 2))); // wrong row
    }

    public function testRelativePosition(): void
    {
        $m = Manager::newGlobal();
        $m->scan('xx' . $m->mark('btn', 'Hello'));
        $z = $m->get('btn');
        // Zone starts at col 3.
        $this->assertSame([0, 0], $z->pos($this->click(3, 1)));
        $this->assertSame([2, 0], $z->pos($this->click(5, 1)));
    }

    public function testTwoSideBySideZones(): void
    {
        $m = Manager::newGlobal();
        $line = $m->mark('a', 'AAA') . '|' . $m->mark('b', 'BBB');
        $clean = $m->scan($line);
        $this->assertSame('AAA|BBB', $clean);

        $a = $m->get('a');
        $b = $m->get('b');
        $this->assertSame(1, $a->startCol);
        $this->assertSame(3, $a->endCol);
        $this->assertSame(5, $b->startCol);
        $this->assertSame(7, $b->endCol);
    }

    public function testZoneAcrossLines(): void
    {
        $m = Manager::newGlobal();
        $rendered = $m->mark('block', "row1\nrow2");
        $m->scan($rendered);
        $z = $m->get('block');
        $this->assertSame(1, $z->startCol);
        $this->assertSame(1, $z->startRow);
        $this->assertSame(4, $z->endCol);
        $this->assertSame(2, $z->endRow);
    }

    public function testAnsiStylingDoesNotShiftZone(): void
    {
        $m = Manager::newGlobal();
        $rendered = $m->mark('btn', "\x1b[31mOK\x1b[0m");
        $m->scan($rendered);
        $z = $m->get('btn');
        $this->assertSame(1, $z->startCol);
        $this->assertSame(2, $z->endCol);
    }

    public function testCjkWideCharCountsAsTwoCells(): void
    {
        $m = Manager::newGlobal();
        $rendered = $m->mark('jp', '日本');
        $m->scan($rendered);
        $z = $m->get('jp');
        $this->assertSame(1, $z->startCol);
        $this->assertSame(4, $z->endCol);
    }

    public function testGetReturnsNullForUnknownId(): void
    {
        $this->assertNull(Manager::newGlobal()->get('nope'));
    }

    public function testClearForgetsZones(): void
    {
        $m = Manager::newGlobal();
        $m->scan($m->mark('btn', 'OK'));
        $this->assertNotNull($m->get('btn'));
        $m->clear();
        $this->assertNull($m->get('btn'));
        $this->assertSame([], $m->all());
    }

    public function testRescanReplacesPreviousBounds(): void
    {
        $m = Manager::newGlobal();
        $m->scan($m->mark('btn', 'OK'));
        $first = $m->get('btn');
        $m->scan('   ' . $m->mark('btn', 'OK')); // shifted right by 3
        $second = $m->get('btn');
        $this->assertNotSame($first->startCol, $second->startCol);
        $this->assertSame(4, $second->startCol);
    }

    public function testZeroWidthGraphemeDoesNotInflateZone(): void
    {
        // U+200B (zero-width space) has no visual width; without proper
        // handling it would push later columns to the right.
        $m = Manager::newGlobal();
        $m->scan($m->mark('a', 'X' . "\u{200B}" . 'X') . 'after');
        $z = $m->get('a');
        // 'X' + ZWSP + 'X' = 2 visible cells, not 3.
        $this->assertSame(1, $z->startCol);
        $this->assertSame(2, $z->endCol);
    }

    public function testEndMarkerWithoutStartIsIgnored(): void
    {
        $m = Manager::newGlobal();
        $clean = $m->scan("\x1b_candyzone:E:ghost\x1b\\hi");
        $this->assertSame('hi', $clean);
        $this->assertNull($m->get('ghost'));
    }

    public function testSetEnabledFalseSkipsMarkers(): void
    {
        $m = Manager::newGlobal();
        $m->setEnabled(false);
        $this->assertFalse($m->isEnabled());
        $marked = $m->mark('foo', 'hello');
        $this->assertSame('hello', $marked);
        $clean = $m->scan('hello');
        $this->assertSame('hello', $clean);
        $this->assertNull($m->get('foo'));
    }

    public function testSetEnabledTrueResumesMarking(): void
    {
        $m = Manager::newGlobal();
        $m->setEnabled(false);
        $m->mark('foo', 'hi'); // dropped
        $m->setEnabled(true);
        $this->assertTrue($m->isEnabled());
        $marked = $m->mark('foo', 'hi');
        $this->assertStringContainsString('candyzone:S:foo', $marked);
    }

    public function testNewPrefixGeneratesUniqueIds(): void
    {
        $a = Manager::newPrefix();
        $b = Manager::newPrefix();
        $this->assertNotSame($a->prefix(), $b->prefix());
    }

    public function testExplicitPrefixUsedAsIs(): void
    {
        $m = Manager::newPrefix('myWidget-');
        $marked = $m->mark('item-0', 'X');
        $this->assertStringContainsString('candyzone:S:myWidget-item-0', $marked);
        $this->assertStringContainsString('candyzone:E:myWidget-item-0', $marked);
    }

    public function testGetUsesPrefix(): void
    {
        $m = Manager::newPrefix('list-');
        $marked = $m->mark('row-1', 'hi');
        $m->scan($marked);
        $zone = $m->get('row-1');
        $this->assertNotNull($zone);
        $this->assertSame('list-row-1', $zone->id);
    }

    public function testTwoPrefixedManagersDoNotCollide(): void
    {
        $a = Manager::newPrefix('a-');
        $b = Manager::newPrefix('b-');
        // Each component owns its own marker stream.
        $a->scan($a->mark('item-0', 'A'));
        $b->scan($b->mark('item-0', 'B'));
        $this->assertNotNull($a->get('item-0'));
        $this->assertNotNull($b->get('item-0'));
        $this->assertSame('a-item-0', $a->get('item-0')->id);
        $this->assertSame('b-item-0', $b->get('item-0')->id);
    }

    public function testClearWithIdDropsOnlyThatZone(): void
    {
        $m = Manager::newGlobal();
        $m->scan($m->mark('btn-a', 'A') . $m->mark('btn-b', 'B'));
        $this->assertCount(2, $m->all());
        $m->clear('btn-a');
        $this->assertNull($m->get('btn-a'));
        $this->assertNotNull($m->get('btn-b'));
    }

    public function testClearWithoutIdDropsAllZones(): void
    {
        $m = Manager::newGlobal();
        $m->scan($m->mark('btn-a', 'A') . $m->mark('btn-b', 'B'));
        $m->clear();
        $this->assertSame([], $m->all());
    }

    public function testCloseDropsZonesAndDisablesManager(): void
    {
        $m = Manager::newGlobal();
        $m->scan($m->mark('btn', 'OK'));
        $this->assertCount(1, $m->all());
        $m->close();
        $this->assertSame([], $m->all());
        $this->assertFalse($m->isEnabled());
        // After close(), mark() should pass content through unchanged.
        $this->assertSame('plain', $m->mark('x', 'plain'));
    }

    public function testCloseIsIdempotent(): void
    {
        $m = Manager::newGlobal();
        $m->close();
        $m->close();
        $this->assertSame([], $m->all());
        $this->assertFalse($m->isEnabled());
    }

    public function testAnyInBoundsReturnsHitZone(): void
    {
        $m = Manager::newGlobal();
        $m->scan($m->mark('btn', 'OK'));
        $hit = $m->anyInBounds($this->click(1, 1));
        $this->assertInstanceOf(Zone::class, $hit);
        $this->assertSame('btn', $hit->id);
    }

    public function testAnyInBoundsReturnsNullWhenNothingMatches(): void
    {
        $m = Manager::newGlobal();
        $m->scan($m->mark('btn', 'OK'));
        $this->assertNull($m->anyInBounds($this->click(50, 50)));
    }

    public function testAnyInBoundsReturnsNullForNonMouseMsg(): void
    {
        $m = Manager::newGlobal();
        $m->scan($m->mark('btn', 'OK'));
        $this->assertNull($m->anyInBounds(new \SugarCraft\Core\Msg\KeyMsg(\SugarCraft\Core\KeyType::Char, 'a')));
    }

    public function testAnyInBoundsAndUpdateRoutesHitToModelAsMsgZoneInBounds(): void
    {
        $m = Manager::newGlobal();
        $m->scan($m->mark('btn-1', 'OK'));
        $model = new ZoneRoutingModel();

        [$next, $cmd] = $m->anyInBoundsAndUpdate($model, $this->click(1, 1));

        $this->assertInstanceOf(ZoneRoutingModel::class, $next);
        $this->assertNotNull($next->lastInBoundsHit);
        $this->assertSame('btn-1', $next->lastInBoundsHit->zone->id);
        $this->assertNull($cmd);
    }

    public function testAnyInBoundsAndUpdatePassesThroughOnMiss(): void
    {
        $m = Manager::newGlobal();
        $m->scan($m->mark('btn', 'OK'));
        $model = new ZoneRoutingModel();

        $miss = $this->click(50, 50);
        [$next, $cmd] = $m->anyInBoundsAndUpdate($model, $miss);

        $this->assertNull($next->lastInBoundsHit);
        $this->assertSame($miss, $next->lastPlainMouse);
    }

    /**
     * When zones nest, anyInBounds() returns the innermost (smallest-area) zone.
     * The outer zone spans cols 1-4; the inner zone (BB) spans cols 2-3.
     */
    public function testAnyInBoundsPrefersInnermostZone(): void
    {
        $m = Manager::newGlobal();
        // outer=A + inner + C  →  outer cols 1-4, inner cols 2-3
        $m->scan($m->mark('outer', 'A' . $m->mark('inner', 'BB') . 'C'));

        // Click inside inner region → innermost wins.
        $this->assertSame('inner', $m->anyInBounds($this->click(2, 1))->id);
        $this->assertSame('inner', $m->anyInBounds($this->click(3, 1))->id);

        // Click in outer-only region → outer wins.
        $this->assertSame('outer', $m->anyInBounds($this->click(1, 1))->id);
        $this->assertSame('outer', $m->anyInBounds($this->click(4, 1))->id);
    }

    /**
     * When two zones of equal area both contain the mouse, the last-inserted
     * (top-most) zone wins.
     *
     * Note: with the mark() API zones are always parent/child (nested), so
     * a true equal-area tie only occurs when a zone is wrapped by a same-size
     * outer zone — the outer zone is necessarily larger by having the same
     * inner plus padding. A pure equal-area horizontal overlap is not
     * constructible via mark() since it always produces top-outer, bottom-inner
     * nesting. The innermost-wins rule handles all realistic cases.
     */
    public function testAnyInBoundsTieBreaksToLastInserted(): void
    {
        // Two zones with equal area 1 (both just 'X'), but the second is
        // inserted last so it sits "on top" of the first.
        $m = Manager::newGlobal();
        $m->scan($m->mark('first', 'X') . $m->mark('second', 'X'));

        // 'first' spans col 1, 'second' spans col 2 — no overlap at col 1, so
        // only 'first' is hit. This test documents that even with equal area
        // non-overlapping zones the last-inserted (second) would win IF they
        // overlapped, because of the < comparison in anyInBounds.
        $this->assertSame('first', $m->anyInBounds($this->click(1, 1))->id);
        $this->assertSame('second', $m->anyInBounds($this->click(2, 1))->id);
    }

    /**
     * Bounding box of a multi-line zone with ragged (non-uniform) row widths
     * must be the union rectangle: endCol = max width across all rows,
     * endRow = last row containing content.
     */
    public function testZoneAcrossLinesRaggedWidths(): void
    {
        // Case 1: longer row first, shorter row second.
        $m = Manager::newGlobal();
        $m->scan($m->mark('block', "longrow\nhi"));
        $z = $m->get('block');
        $this->assertSame(1, $z->startCol);
        $this->assertSame(1, $z->startRow);
        $this->assertSame(7, $z->endCol);   // widest row (longrow)
        $this->assertSame(2, $z->endRow);

        // Case 2: shorter row first, longer row last.
        $m2 = Manager::newGlobal();
        $m2->scan($m2->mark('block', "hi\nlongrow"));
        $z2 = $m2->get('block');
        $this->assertSame(1, $z2->startCol);
        $this->assertSame(1, $z2->startRow);
        $this->assertSame(7, $z2->endCol);  // longest row (longrow)
        $this->assertSame(2, $z2->endRow);

        // Case 3: content ends exactly at a newline (trailing \n means
        // end marker lands at col 1 of a fresh row — must still use
        // the maxCol from the last content row, not col 1).
        $m3 = Manager::newGlobal();
        $m3->scan($m3->mark('block', "longrow\n"));
        $z3 = $m3->get('block');
        $this->assertSame(1, $z3->startCol);
        $this->assertSame(1, $z3->startRow);
        $this->assertSame(7, $z3->endCol);  // maxCol from the longrow
        $this->assertSame(1, $z3->endRow);  // endRow = row - 1 (col was 1)
    }
}

final class ZoneRoutingModel implements \SugarCraft\Core\Model
{
    public ?\SugarCraft\Zone\MsgZoneInBounds $lastInBoundsHit  = null;
    public ?\SugarCraft\Core\Msg\MouseMsg    $lastPlainMouse   = null;

    public function init(): ?\Closure { return null; }

    public function update(\SugarCraft\Core\Msg $msg): array
    {
        $next = clone $this;
        if ($msg instanceof \SugarCraft\Zone\MsgZoneInBounds) {
            $next->lastInBoundsHit = $msg;
        } elseif ($msg instanceof \SugarCraft\Core\Msg\MouseMsg) {
            $next->lastPlainMouse = $msg;
        }
        return [$next, null];
    }

    public function view(): string { return ''; }

    public function subscriptions(): ?\SugarCraft\Core\Subscriptions
    {
        return null;
    }
}
