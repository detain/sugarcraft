<?php

declare(strict_types=1);

namespace SugarCraft\Top\Tests\Source;

use PHPUnit\Framework\TestCase;
use SugarCraft\Top\Collect\Vm;
use SugarCraft\Top\Collect\VmFleetSnapshot;
use SugarCraft\Top\Collect\VmGuest;
use SugarCraft\Top\Panel\Vms\VmCard;
use SugarCraft\Top\Source\Fake\FakeProcList;
use SugarCraft\Top\Source\Fake\FakeVms;

final class FakeVmsTest extends TestCase
{
    public function testDeterministicVariedFleet(): void
    {
        [$a, $next] = FakeVms::new()->sample();
        [$b] = FakeVms::new()->sample();
        $this->assertEquals($a, $b, 'same step, same numbers');
        $this->assertInstanceOf(VmFleetSnapshot::class, $a);
        $this->assertSame(7, $a->count());
        $this->assertSame(FakeVms::HOST_MEM, $a->hostMem);
        [$c] = $next->sample();
        $this->assertNotEquals($a, $c, 'the load moves');
        foreach ($a->guests as $g) {
            $this->assertSame($g->name, Vm::scope($g->path)['name'] ?? null, 'a real machined scope path');
            $this->assertGreaterThan(0, $g->vcpus);
            $this->assertGreaterThan(0, $g->memBytes);
            $this->assertGreaterThanOrEqual(0.0, $g->cpu);
            $this->assertLessThanOrEqual(100.0, $g->cpu);
            $this->assertGreaterThanOrEqual(0.0, $g->netRx);
        }
        $starved = array_filter($a->guests, static fn (VmGuest $g): bool => ($g->pressure()[1] ?? 0) >= VmCard::PSI_WARN);
        $this->assertNotEmpty($starved, 'the demo shows the pressure badge');
    }

    public function testWeb01IsTheCtrDemoGuest(): void
    {
        [$fleet] = FakeVms::new()->sample();
        [$procs] = FakeProcList::demo(8)->withContainers()->sample();
        $paths = [];
        foreach ($procs->processes as $p) {
            if ($p->container?->engine === Vm::ENGINE) {
                $paths[] = $p->container->cgroupPath;
            }
        }
        $this->assertContains('/machine.slice/machine-qemu\x2d1\x2dweb01.scope', $paths);
        $this->assertNotNull($fleet->find('/machine.slice/machine-qemu\x2d1\x2dweb01.scope'));
    }
}
