<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Support;

use PHPUnit\Framework\TestCase;
use React\EventLoop\Loop;
use React\EventLoop\StreamSelectLoop;

/**
 * The census must see a KNOWN POSITIVE before its "nothing armed" is trusted
 * (an absence assertion by a blind census is indistinguishable from a clean one).
 */
final class SharedLoopResidueGuardTest extends TestCase
{
    public function testCensusSeesAnArmedTimerAndTheGuardRecordsAndClearsIt(): void
    {
        $this->assertSame(0, \array_sum(SharedLoopResidue::census()));

        $before = SharedLoopResidueGuard::offenders();
        Loop::addTimer(60.0, static function (): void {});
        $this->assertSame(1, SharedLoopResidue::census()['timers']);

        SharedLoopResidueGuard::check('probe::leak');
        $recorded = SharedLoopResidueGuard::offenders();
        $this->assertArrayHasKey('probe::leak', $recorded);
        $this->assertStringContainsString('1 timer(s)', $recorded['probe::leak']);

        // Loop was replaced, so nothing is armed any more.
        $this->assertSame(0, \array_sum(SharedLoopResidue::census()));
        $this->assertInstanceOf(StreamSelectLoop::class, Loop::get());

        // Drop the probe so the real run does not fail on this deliberate leak.
        $ref = new \ReflectionProperty(SharedLoopResidueGuard::class, 'offenders');
        $ref->setValue(null, $before);
    }
}
