<?php

declare(strict_types=1);

namespace SugarCraft\Vterm\Tests\Cursor;

use PHPUnit\Framework\TestCase;
use SugarCraft\Vterm\Cursor as RendererCursor;
use SugarCraft\Vterm\Cursor\Cursor as EmulatorCursor;

/**
 * vterm-09 / sugar-vterm-074: the emulator snapshot's vocabulary aliases are
 * pure delegation — at() ≡ withRow()+withCol(), hidden()/shown() ≡
 * withVisible(false/true) — and the two Cursor classes answer the same verbs
 * with the same meaning. Zero behaviour change: these pins only lock the
 * alias-to-primitive identity so a future refactor cannot quietly fork it.
 */
final class EmulatorCursorAliasesTest extends TestCase
{
    private function armed(): EmulatorCursor
    {
        // Non-default everything except visible, so a dropped field in the
        // delegation shows up as a diff.
        return new EmulatorCursor(row: 3, col: 7, visible: true, shape: 4, savedRow: 1, savedCol: 2);
    }

    public function testAtIsIdenticalToChainingTheRowColPair(): void
    {
        $c = $this->armed();
        $this->assertEquals($c->withRow(11)->withCol(13), $c->at(11, 13), 'at() is the chain');
        $via = $c->at(11, 13);
        $this->assertSame(11, $via->row);
        $this->assertSame(13, $via->col);
        $this->assertSame(4, $via->shape, 'shape rides through both legs');
        $this->assertSame(1, $via->savedRow);
        $this->assertSame(2, $via->savedCol);
        $this->assertTrue($via->visible);
    }

    public function testHiddenShownAreTheVisiblePrimitiveArms(): void
    {
        $c = $this->armed();
        $this->assertEquals($c->withVisible(false), $c->hidden(), 'hidden() is withVisible(false)');
        $this->assertFalse($c->hidden()->visible);
        $this->assertEquals($c->hidden()->withVisible(true), $c->hidden()->shown(), 'shown() is withVisible(true)');
        $this->assertTrue($c->hidden()->shown()->visible);
        $this->assertEquals($c->hidden(), $c->hidden()->hidden(), 'hidden is idempotent like the primitive');
    }

    public function testTheTwoCursorClassesAnswerTheSameVerbs(): void
    {
        $r = new RendererCursor(row: 3, col: 7, visible: true, shape: 4);
        $e = $this->armed();

        $this->assertEquals([$r->at(11, 13)->row, $r->at(11, 13)->col], [$e->at(11, 13)->row, $e->at(11, 13)->col], 'at() agrees across classes');
        $this->assertSame($r->hidden()->visible, $e->hidden()->visible);
        $this->assertSame($r->shown()->visible, $e->shown()->visible);
        $this->assertTrue((new RendererCursor())->hidden()->shown()->visible, 'root VO legs stay live');
    }
}
