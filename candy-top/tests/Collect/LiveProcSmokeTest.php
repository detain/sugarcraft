<?php

declare(strict_types=1);

namespace SugarCraft\Top\Tests\Collect;

use PHPUnit\Framework\TestCase;
use SugarCraft\Top\Collect\Battery;
use SugarCraft\Top\Collect\Cpu;
use SugarCraft\Top\Collect\DiskIo;
use SugarCraft\Top\Collect\Freq;
use SugarCraft\Top\Collect\Memory;
use SugarCraft\Top\Collect\Mounts;
use SugarCraft\Top\Collect\Net;
use SugarCraft\Top\Collect\ProcList;
use SugarCraft\Top\Collect\Temp;

/**
 * Reads the real /proc and /sys of the machine running the suite. Asserts
 * only shape and invariants, never values: the point is that every
 * collector parses the live kernel ABI without warnings or throws.
 */
final class LiveProcSmokeTest extends TestCase
{
    protected function setUp(): void
    {
        if (PHP_OS_FAMILY !== 'Linux' || !is_readable('/proc/stat')) {
            $this->markTestSkipped('needs a Linux /proc');
        }
    }

    public function testEveryCollectorSamplesTheLiveHost(): void
    {
        [$cpu] = Cpu::new()->sample();
        $this->assertGreaterThan(0, $cpu->coreCount());
        $this->assertGreaterThanOrEqual(0.0, $cpu->total);
        $this->assertLessThanOrEqual(100.0, $cpu->total);

        [$mem] = Memory::new()->sample();
        $this->assertTrue($mem->measured());
        $this->assertGreaterThan(0, $mem->used);
        $this->assertLessThanOrEqual($mem->total, $mem->used);

        [, $net] = Net::new()->sample();
        [$netSnap] = $net->sample();
        $this->assertArrayHasKey('lo', $netSnap->interfaces);
        $this->assertNotNull($netSnap->selected());

        [, $io] = DiskIo::new()->sample();
        $io->sample();

        [$mounts] = Mounts::new()->sample();
        foreach ($mounts->mounts as $mount) {
            $this->assertGreaterThanOrEqual(0, $mount->free);
        }

        [, $procs] = ProcList::new()->sample();
        [$procSnap] = $procs->sample();
        $self = null;
        foreach ($procSnap->processes as $p) {
            $this->assertGreaterThanOrEqual(0.0, $p->cpu);
            if ($p->pid === getmypid()) {
                $self = $p;
            }
        }
        $this->assertNotNull($self, 'the test runner sees itself');
        $this->assertStringContainsString('php', $self->cmd . $self->name);

        Temp::new()->sample();
        Battery::new()->sample();
        Freq::new()->sample();
    }
}
