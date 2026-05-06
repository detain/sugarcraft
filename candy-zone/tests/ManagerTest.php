<?php

declare(strict_types=1);

namespace CandyCore\Zone\Tests;

use CandyCore\Core\MouseAction;
use CandyCore\Core\MouseButton;
use CandyCore\Core\Msg\MouseMsg;
use CandyCore\Zone\Manager;
use CandyCore\Zone\Zone;
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
        $this->assertNull($m->anyInBounds(new \CandyCore\Core\Msg\KeyMsg(\CandyCore\Core\KeyType::Char, 'a')));
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
}

final class ZoneRoutingModel implements \CandyCore\Core\Model
{
    public ?\CandyCore\Zone\MsgZoneInBounds $lastInBoundsHit  = null;
    public ?\CandyCore\Core\Msg\MouseMsg    $lastPlainMouse   = null;

    public function init(): ?\Closure { return null; }

    public function update(\CandyCore\Core\Msg $msg): array
    {
        $next = clone $this;
        if ($msg instanceof \CandyCore\Zone\MsgZoneInBounds) {
            $next->lastInBoundsHit = $msg;
        } elseif ($msg instanceof \CandyCore\Core\Msg\MouseMsg) {
            $next->lastPlainMouse = $msg;
        }
        return [$next, null];
    }

    public function view(): string { return ''; }
}
