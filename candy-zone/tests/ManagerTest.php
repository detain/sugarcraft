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

    public function testEndMarkerWithoutStartIsIgnored(): void
    {
        $m = Manager::newGlobal();
        $clean = $m->scan("\x1b_candyzone:E:ghost\x1b\\hi");
        $this->assertSame('hi', $clean);
        $this->assertNull($m->get('ghost'));
    }
}
