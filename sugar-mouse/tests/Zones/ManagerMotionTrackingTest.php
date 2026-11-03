<?php

declare(strict_types=1);

namespace SugarCraft\Mouse\Tests\Zones;

use SugarCraft\Mouse\Zones\Manager;
use PHPUnit\Framework\TestCase;

final class ManagerMotionTrackingTest extends TestCase
{
    /**
     * Byte pins on purpose (not Ansi:: calls): if sugar-core ever swaps
     * its mouseAllMotion* pairing, these go red and this lib decides —
     * a silently drifted wire contract is what the mode review caught.
     */
    public function testSetMotionTrackingTrueReturnsEnableSequence(): void
    {
        $m = Manager::newGlobal();

        $seq = $m->setMotionTracking(true);

        $this->assertSame("\x1b[?1003h\x1b[?1006h", $seq);
    }

    public function testSetMotionTrackingFalseReturnsDisableSequence(): void
    {
        $m = Manager::newGlobal();

        $seq = $m->setMotionTracking(false);

        $this->assertSame("\x1b[?1006l\x1b[?1003l", $seq);
    }
}
