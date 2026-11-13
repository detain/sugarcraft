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
    public function testMotionTrackingAnsiTrueReturnsEnableSequence(): void
    {
        $m = Manager::newGlobal();

        $seq = $m->motionTrackingAnsi(true);

        $this->assertSame("\x1b[?1003h\x1b[?1006h", $seq);
    }

    public function testMotionTrackingAnsiFalseReturnsDisableSequence(): void
    {
        $m = Manager::newGlobal();

        $seq = $m->motionTrackingAnsi(false);

        $this->assertSame("\x1b[?1006l\x1b[?1003l", $seq);
    }

    /**
     * The deprecated setter-shaped alias must stay byte-identical to the
     * honest name until it retires — a consumer mid-migration cannot see
     * two different wires for one manager.
     */
    public function testDeprecatedSetMotionTrackingAliasDelegatesByteIdentical(): void
    {
        $m = Manager::newGlobal();

        $this->assertSame($m->motionTrackingAnsi(true), $m->setMotionTracking(true));
        $this->assertSame($m->motionTrackingAnsi(false), $m->setMotionTracking(false));
    }
}
