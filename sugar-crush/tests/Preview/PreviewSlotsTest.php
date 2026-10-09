<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Preview;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Media\PreviewSlots;

/**
 * The preview holding pen's contract (W2.4): single-slot replace-not-append,
 * the empty call id is refused as a key, and every leak path is closed -
 * settle-clear, session-clear, and the bounded-map backstop for frames whose
 * call abandoned mid-render.
 */
final class PreviewSlotsTest extends TestCase
{
    protected function setUp(): void
    {
        PreviewSlots::clearAll();
    }

    protected function tearDown(): void
    {
        PreviewSlots::clearAll();
    }

    public function testANewFrameReplacesTheSlotInsteadOfAppending(): void
    {
        PreviewSlots::set('call-1', 'Zmlyc3Q=', 0.1, 9.0);
        PreviewSlots::set('call-1', 'c2Vjb25k', 0.6, 4.0);

        $this->assertSame(1, PreviewSlots::count(), 'progressive previews replace in place');
        $slot = PreviewSlots::peek('call-1');
        $this->assertNotNull($slot);
        $this->assertSame('c2Vjb25k', $slot['frameB64'], 'the newest frame owns the slot');
        $this->assertSame(0.6, $slot['progress']);
        $this->assertSame(4.0, $slot['eta']);
    }

    public function testAnUnknownCallPeeksAsNull(): void
    {
        $this->assertNull(PreviewSlots::peek('never-seen'));
    }

    public function testAnEmptyCallIdIsRefusedAsAKey(): void
    {
        PreviewSlots::set('', 'Zg==', 0.5, 1.0);

        $this->assertSame(0, PreviewSlots::count(), 'no slot may be keyed on nothing');
    }

    public function testSettlingACallClearsOnlyItsOwnSlot(): void
    {
        PreviewSlots::set('call-1', 'QQ==', 0.2, null);
        PreviewSlots::set('call-2', 'Qg==', 0.8, 2.0);

        PreviewSlots::clear('call-1');

        $this->assertNull(PreviewSlots::peek('call-1'));
        $this->assertNotNull(PreviewSlots::peek('call-2'), 'a sibling render in flight keeps its preview');
    }

    public function testClearAllDropsEverything(): void
    {
        PreviewSlots::set('call-1', 'QQ==', null, null);
        PreviewSlots::set('call-2', 'Qg==', null, null);

        PreviewSlots::clearAll();

        $this->assertSame(0, PreviewSlots::count());
    }

    public function testTheMapIsBoundedAgainstAbandonedCalls(): void
    {
        // Leak-law backstop: ten abandoned renders (a killed turn per call)
        // must not accumulate ten frames; the oldest slot evicts as the newest
        // arrives.
        for ($n = 1; $n <= 10; $n++) {
            PreviewSlots::set('call-' . (string) $n, 'Zg==', 0.5, 1.0);
        }

        $this->assertSame(8, PreviewSlots::count(), 'at most eight newest slots survive');
        $this->assertNull(PreviewSlots::peek('call-1'), 'the oldest frame gave its slot up');
        $this->assertNotNull(PreviewSlots::peek('call-10'), 'the newest owns its slot');
    }

    public function testAProgressOnlySlotCarriesNoFrameAndStillPeeks(): void
    {
        // A server without preview images still reports percent/eta - the
        // caption row needs the slot even with frameB64 null.
        PreviewSlots::set('call-1', null, 0.42, 8.0);

        $slot = PreviewSlots::peek('call-1');
        $this->assertNotNull($slot);
        $this->assertNull($slot['frameB64']);
        $this->assertSame(0.42, $slot['progress']);
    }
}
