<?php

declare(strict_types=1);

namespace SugarCraft\Zone\Tests;

use SugarCraft\Zone\Manager;
use PHPUnit\Framework\TestCase;

final class ManagerMotionTrackingTest extends TestCase
{
    public function testSetMotionTrackingTrueReturnsEnableSequence(): void
    {
        $m = Manager::newGlobal();

        $seq = $m->setMotionTracking(true);

        $this->assertSame("\x1b[?1003h", $seq);
    }

    public function testSetMotionTrackingFalseReturnsDisableSequence(): void
    {
        $m = Manager::newGlobal();

        $seq = $m->setMotionTracking(false);

        $this->assertSame("\x1b[?1003l", $seq);
    }
}
