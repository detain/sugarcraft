<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Tui;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Tui\ToolTailSlots;

/**
 * The tool-tail holding pen (C-TOOL-3.a): newest-sequence-wins per call,
 * out-of-order frames dropped, the empty call id ignored, and the PreviewSlots
 * leak-law backstop (at most MAX_SLOTS newest) holding even when abandoned
 * calls never settle.
 */
final class ToolTailSlotsTest extends TestCase
{
    /** The store's private cap, mirrored here as the test's own witness. */
    private const BACKSTOP = 8;

    protected function setUp(): void
    {
        ToolTailSlots::clearAll();
    }

    protected function tearDown(): void
    {
        ToolTailSlots::clearAll();
    }

    public function testTheNewestTailAndFlagsLandTogether(): void
    {
        ToolTailSlots::set('c1', 'first tail', false, 1);

        $slot = ToolTailSlots::peek('c1');
        $this->assertIsArray($slot);
        $this->assertSame('first tail', $slot['tail']);
        $this->assertFalse($slot['truncated']);
        $this->assertSame(1, $slot['seq']);
        $this->assertNull(ToolTailSlots::peek('absent'), 'a call with no frame has no row to paint');
        $this->assertSame(1, ToolTailSlots::count());
    }

    public function testAnEmptyCallIdIsIgnored(): void
    {
        ToolTailSlots::set('', 'ghost', true, 7);

        $this->assertSame(0, ToolTailSlots::count(), 'an anonymous frame must not mint a slot');
        $this->assertNull(ToolTailSlots::peek(''));
    }

    public function testOutOfOrderFramesNeverRepaintBackwards(): void
    {
        ToolTailSlots::set('c1', 'late but final', true, 9);
        ToolTailSlots::set('c1', 'stale echo', false, 3);

        $slot = ToolTailSlots::peek('c1');
        $this->assertSame('late but final', $slot['tail'], 'a dropped frame must not strand the row on older bytes');
        $this->assertSame(9, $slot['seq']);

        ToolTailSlots::set('c1', 'replayed twin', false, 9);
        $this->assertSame('late but final', ToolTailSlots::peek('c1')['tail'], 'an equal sequence is a replay, not news');

        ToolTailSlots::set('c1', 'fresh frame', false, 10);
        $this->assertSame('fresh frame', ToolTailSlots::peek('c1')['tail'], 'the next frame must land');
    }

    public function testTheBackstopKeepsOnlyTheNewestSlots(): void
    {
        for ($i = 1; $i <= self::BACKSTOP + 3; $i++) {
            ToolTailSlots::set('call-' . $i, 'tail ' . $i, false, 1);
        }

        $this->assertSame(self::BACKSTOP, ToolTailSlots::count(), 'abandoned calls cannot accumulate');
        $this->assertNull(ToolTailSlots::peek('call-1'), 'the oldest slot makes room');
        $this->assertNull(ToolTailSlots::peek('call-3'));
        $this->assertIsArray(ToolTailSlots::peek('call-4'), 'everything newer than the eviction line survives');
        $this->assertIsArray(ToolTailSlots::peek('call-' . (self::BACKSTOP + 3)));
    }

    public function testClearRemovesTheSettledCall(): void
    {
        ToolTailSlots::set('c1', 'done', false, 1);
        ToolTailSlots::set('c2', 'still running', false, 1);

        ToolTailSlots::clear('c1');
        $this->assertNull(ToolTailSlots::peek('c1'));
        $this->assertIsArray(ToolTailSlots::peek('c2'), 'settling one call must not blind the others');

        ToolTailSlots::clearAll();
        $this->assertSame(0, ToolTailSlots::count());
    }
}
