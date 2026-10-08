<?php

declare(strict_types=1);

namespace SugarCraft\Top\Tests\Source;

use PHPUnit\Framework\TestCase;
use SugarCraft\Top\Collect\ContainerInfo;
use SugarCraft\Top\Collect\DockerSocket;
use SugarCraft\Top\Collect\Process;
use SugarCraft\Top\Collect\ProcSnapshot;
use SugarCraft\Top\Source\Fake\FakeContainers;
use SugarCraft\Top\Source\Fake\FakeProcList;

/**
 * The `--fake` ctr box: the demo fleet's processes carry cgroup tags the
 * live detector recognises, and the fake collector groups them like the
 * live one with deterministic figures.
 */
final class FakeContainersTest extends TestCase
{
    public function testTheFleetIsOptInAndDetectedLikeTheLiveHost(): void
    {
        $plain = FakeProcList::demo(8)->sample()[0];
        $fleet = FakeProcList::demo(8)->withContainers()->sample()[0];
        $this->assertInstanceOf(ProcSnapshot::class, $plain);
        $this->assertInstanceOf(ProcSnapshot::class, $fleet);
        $this->assertCount($plain->count() + 7, $fleet->processes, 'demo() alone keeps the proc goldens\' cast');
        $engines = [];
        foreach ($fleet->processes as $p) {
            if ($p->container !== null && !$p->container->isVm()) {
                $engines[$p->container->engine] = true;
            }
        }
        ksort($engines);
        $this->assertSame(['docker', 'k8s', 'lxc', 'nspawn', 'podman'], array_keys($engines));
    }

    public function testCollectIsDeterministicAndNamesDockerContainers(): void
    {
        $procs = FakeProcList::demo(8)->withContainers()->sample()[0];
        $this->assertInstanceOf(ProcSnapshot::class, $procs);
        [$a, $next] = FakeContainers::new()->collect($procs->processes, $procs->memTotal, 8, false, 10);
        [$b] = FakeContainers::new()->collect($procs->processes, $procs->memTotal, 8, false, 10);
        $this->assertEquals($a, $b);
        $this->assertSame(['9a0c5e21b7d4', 'arch', 'build', 'c41d9e7f0a13', 'pg-main', 'web-1', 'web01'], array_map(static fn (ContainerInfo $c): string => $c->name, $a->containers));
        $vm = $a->containers[6];
        $this->assertSame(['kvm', 4 * 1024 * 1024 * 1024], [$vm->engine, $vm->memLimit], 'the demo guest: engine kvm, limited by its -m');
        [$noVms] = FakeContainers::new()->withVms(false)->collect($procs->processes, $procs->memTotal, 8, false, 10);
        $this->assertCount(6, $noVms->containers, 'ctr_show_vms off: btop\'s containers-only box');
        $web = $a->containers[5];
        $this->assertSame(512 * 1024 * 1024, $web->memLimit);
        $this->assertSame(1, $web->procs);
        $this->assertSame(0, $a->containers[0]->memLimit, 'unlimited → the detail meter uses MemTotal');
        [$again] = $next->collect($procs->processes, $procs->memTotal, 8, false, 10);
        $this->assertCount(2, $again->containers[5]->history, 'the history carries over');
        $this->assertSame('web-1', DockerSocket::name(FakeContainers::response(), '3f2a1b9c0d1e'));
        $this->assertSame([], FakeContainers::new()->collect([], 0, 1, false, 1)[0]->containers);
        $this->assertSame([], FakeContainers::new()->collect([new Process(1, 0, 'x', 'x', 'r', 0, 'S', 1, 0, 1, 1.0, 1.0)], 0, 1, false, 1)[0]->containers);
    }
}
