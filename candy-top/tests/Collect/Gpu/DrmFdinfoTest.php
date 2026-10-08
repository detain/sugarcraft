<?php

declare(strict_types=1);

namespace SugarCraft\Top\Tests\Collect\Gpu;

use PHPUnit\Framework\TestCase;
use SugarCraft\Top\Collect\Gpu\DrmFdinfo;
use SugarCraft\Top\Collect\Sentinel;

final class DrmFdinfoTest extends TestCase
{
    private GpuTree $tree;

    private float $now = 100.0;

    protected function setUp(): void
    {
        $this->tree = GpuTree::of();
    }

    protected function tearDown(): void
    {
        $this->tree->destroy();
    }

    private function scanner(float $rescan = DrmFdinfo::RESCAN_INTERVAL, ?\Closure $readlink = null, int $budget = DrmFdinfo::DISCOVERY_BUDGET): DrmFdinfo
    {
        return DrmFdinfo::new($this->tree->paths(), fn (): float => $this->now, $readlink, $rescan, $budget);
    }

    public function testFirstScanListsClientsWithoutRates(): void
    {
        $this->tree->fd(1001, 5, 'i915-t0.txt');
        $this->tree->fd(1001, 6, 'not-drm.txt', '/dev/null');
        [$scan] = $this->scanner()->sample();

        $this->assertTrue($scan->ran);
        $this->assertFalse($scan->measured);
        $this->assertCount(1, $scan->clients);
        $c = $scan->clients[0];
        $this->assertSame(1001, $c->pid);
        $this->assertSame('0000:00:02.0', $c->pdev);
        $this->assertSame('7', $c->clientId);
        $this->assertSame('i915', $c->driver);
        $this->assertFalse($c->measured());
        $this->assertSame([], $c->engines);
        $this->assertSame(Sentinel::UNMEASURED_INT, $c->memory, 'iGPU: system0 only, no device-local region');
        $this->assertSame(Sentinel::UNMEASURED, $scan->utilization('0000:00:02.0'));
    }

    public function testI915BusyNanosecondsBecomeRatesWithCapacity(): void
    {
        $this->tree->fd(1001, 5, 'i915-t0.txt');
        [, $scanner] = $this->scanner()->sample();
        $this->now += 2.0;
        $this->tree->fd(1001, 5, 'i915-t1.txt');
        [$scan] = $scanner->sample();

        $this->assertTrue($scan->measured);
        $e = $scan->clients[0]->engines;
        $this->assertEqualsWithDelta(50.0, $e['render'], 1e-9, 'Δ1e9 ns over 2 s');
        $this->assertEqualsWithDelta(5.0, $e['copy'], 1e-9);
        $this->assertEqualsWithDelta(25.0, $e['video'], 1e-9, 'Δ1e9 ns / 2 s / capacity 2');
        $this->assertSame(0.0, $e['video-enhance']);
        $this->assertEqualsWithDelta(50.0, $scan->utilization('0000:00:02.0'), 1e-9, 'busiest engine class');
        $this->assertSame(0.0, $scan->utilization('0000:09:00.0'), 'no client on a device = measured idle');
    }

    public function testXeCyclesOverTotalCycles(): void
    {
        $this->tree->fd(2002, 9, 'xe-t0.txt');
        [, $scanner] = $this->scanner()->sample();
        $this->now += 1.0;
        $this->tree->fd(2002, 9, 'xe-t1.txt');
        [$scan] = $scanner->sample();

        $e = $scan->clients[0]->engines;
        $this->assertEqualsWithDelta(25.0, $e['rcs'], 1e-9, 'Δ25000 / Δ100000 cycles');
        $this->assertEqualsWithDelta(40.0, $e['vcs'], 1e-9, 'Δ80000 / Δ100000 / capacity 2');
        $this->assertSame(12 * 1048576, $scan->clients[0]->memory, 'drm-resident-vram0');
        $this->assertEqualsWithDelta(40.0, $scan->utilization('0000:08:00.0'), 1e-9);
    }

    public function testAmdgpuLegacyMemoryKeysAndPerProcessRows(): void
    {
        $this->tree->fd(3003, 4, 'amdgpu-legacy-t0.txt');
        $this->tree->fd(3004, 4, 'amdgpu-t0.txt');
        [, $scanner] = $this->scanner()->sample();
        $this->now += 2.0;
        $this->tree->fd(3003, 4, 'amdgpu-legacy-t1.txt');
        [$scan] = $scanner->sample();

        $rows = $scan->processes(['0000:03:00.0' => 5]);
        $this->assertCount(2, $rows);
        $this->assertSame(3003, $rows[0]->pid);
        $this->assertSame(5, $rows[0]->gpuIndex);
        $this->assertSame('0000:03:00.0', $rows[0]->gpuUuid);
        $this->assertEqualsWithDelta(75.0, $rows[0]->utilization, 1e-9, 'gfx Δ1.5e9 ns / 2 s beats compute 25 %');
        $this->assertSame(1048576, $rows[0]->usedMemory, 'drm-memory-vram 1024 KiB');
        $this->assertSame(0.0, $rows[1]->utilization, 'gfx counter flat');
        $this->assertSame(6 * 1048576, $rows[1]->usedMemory, 'drm-resident-vram wins over drm-total');
        $this->assertSame([], $scan->processes(['0000:00:02.0' => 0]), 'only the requested devices');
    }

    public function testSharedClientCountsOnceAcrossFdsAndProcesses(): void
    {
        $this->tree->fd(1001, 5, 'i915-t0.txt');
        $this->tree->fd(1001, 7, 'i915-t0.txt', '/dev/dri/card0');
        $this->tree->fd(1002, 3, 'i915-t0.txt');
        [, $scanner] = $this->scanner()->sample();
        $this->now += 2.0;
        foreach ([[1001, 5], [1001, 7], [1002, 3]] as [$pid, $fd]) {
            $this->tree->fd($pid, $fd, 'i915-t1.txt');
        }
        [$scan] = $scanner->sample();

        $this->assertCount(1, $scan->clients, 'one drm-client-id');
        $this->assertSame(1001, $scan->clients[0]->pid, 'first pid in scan order owns it');
        $this->assertEqualsWithDelta(50.0, $scan->utilization('0000:00:02.0'), 1e-9, 'not 150');
    }

    public function testExitedClientDropsOutInsteadOfGoingNegative(): void
    {
        $this->tree->fd(1001, 5, 'i915-t0.txt');
        $this->tree->fd(2002, 9, 'xe-t0.txt');
        [, $scanner] = $this->scanner()->sample();
        $this->now += 1.0;
        unlink($this->tree->root . '/proc/1001/fdinfo/5');
        $this->tree->fd(2002, 9, 'xe-t1.txt');
        [$scan] = $scanner->sample();

        $this->assertCount(1, $scan->clients);
        $this->assertSame(0.0, $scan->utilization('0000:00:02.0'));
    }

    public function testCounterResetSkipsTheEngine(): void
    {
        $this->tree->fd(1001, 5, 'i915-t1.txt');
        [, $scanner] = $this->scanner()->sample();
        $this->now += 2.0;
        $this->tree->fd(1001, 5, 'i915-t0.txt');
        [$scan] = $scanner->sample();

        $this->assertArrayNotHasKey('render', $scan->clients[0]->engines);
        $this->assertSame(0.0, $scan->clients[0]->engines['video-enhance']);
    }

    public function testDiscoveryRunsOnlyOnTheRescanCadence(): void
    {
        $links = 0;
        $readlink = static function (string $path) use (&$links): ?string {
            $links++;
            $t = @readlink($path);

            return $t === false ? null : $t;
        };
        $this->tree->fd(1001, 5, 'i915-t0.txt');
        $this->tree->fd(1001, 6, 'not-drm.txt', '/dev/null');
        $scanner = $this->scanner(10.0, $readlink);

        [, $scanner] = $scanner->sample();
        $this->assertSame(2, $links, 'discovery: one readlink per fd');
        $this->tree->fd(1005, 1, 'xe-t0.txt');
        $this->now += 5.0;
        [$scan, $scanner] = $scanner->sample();
        $this->assertSame(2, $links, 'between discoveries only known fdinfo files are read');
        $this->assertCount(1, $scan->clients, 'a client opened since discovery waits for the next one');
        $this->now += 5.0;
        [$scan] = $scanner->sample();
        $this->assertSame(5, $links, 'rescan after RESCAN_INTERVAL');
        $this->assertCount(2, $scan->clients);
    }

    public function testFdReusedByANonDrmFileIsDropped(): void
    {
        $this->tree->fd(1001, 5, 'i915-t0.txt');
        [, $scanner] = $this->scanner()->sample();
        $this->tree->write('proc/1001/fdinfo/5', GpuTree::sample('not-drm.txt'));
        $this->now += 1.0;
        [$scan] = $scanner->sample();

        $this->assertSame([], $scan->clients);
    }

    public function testNoProcIsAnEmptyScan(): void
    {
        [$scan] = $this->scanner()->sample();

        $this->assertSame([], $scan->clients);
        $this->assertSame([], $scan->processes(['0000:00:02.0' => 0]));
    }

    public function testDiscoveryIsAmortizedBehindAPersistentCursor(): void
    {
        $links = [];
        $readlink = static function (string $path) use (&$links): ?string {
            $links[] = $path;
            $t = @readlink($path);

            return $t === false ? null : $t;
        };
        // pids 10..14 hold 3 non-DRM fds each; the only GPU client is the HIGHEST pid.
        foreach (range(10, 14) as $pid) {
            foreach ([1, 2, 3] as $fd) {
                $this->tree->fd($pid, $fd, 'not-drm.txt', '/dev/null');
            }
        }
        $this->tree->fd(99, 4, 'i915-t0.txt');
        $scanner = $this->scanner(10.0, $readlink, 5);

        $seenAt = null;
        for ($i = 1; $i <= 6; $i++) {
            $links = [];
            [$scan, $scanner] = $scanner->sample();
            $this->assertLessThanOrEqual(5, count($links), 'per-sample budget (listings + readlinks)');
            if ($seenAt === null && $scan->clients !== []) {
                $seenAt = $i;
            }
            $this->now += 1.0;
        }
        // 6 listings + 16 readlinks = 22 units / 5 per sample → reached on sample 5.
        $this->assertSame(5, $seenAt, 'the highest pid is reached — no starvation');
        $this->assertSame(1, $scanner->passes());
    }

    public function testBudgetSpentMidPidResumesAtTheSameFd(): void
    {
        $links = [];
        $readlink = static function (string $path) use (&$links): ?string {
            $links[] = basename($path);
            $t = @readlink($path);

            return $t === false ? null : $t;
        };
        foreach ([1, 2, 3, 4, 5] as $fd) {
            $this->tree->fd(7, $fd, $fd === 5 ? 'i915-t0.txt' : 'not-drm.txt', $fd === 5 ? '/dev/dri/renderD128' : '/dev/null');
        }
        $scanner = $this->scanner(10.0, $readlink, 3);
        [$scan, $scanner] = $scanner->sample();
        $this->assertSame(['1', '2'], $links, '1 listing + 2 readlinks');
        $this->assertSame([], $scan->clients);
        [, $scanner] = $scanner->sample();
        $this->assertSame(['1', '2', '3', '4', '5'], $links, 'resumed at fd 3, never re-read 1-2');
        [$scan] = $scanner->sample();
        $this->assertCount(1, $scan->clients);
    }

    public function testUnreadableFdDirsMakeTheScanAPartialLowerBound(): void
    {
        $this->tree->write('proc/500/status', "Name:\tsshd\n"); // pid dir, no readable fd dir
        $this->tree->fd(1001, 5, 'i915-t0.txt');
        [, $scanner] = $this->scanner()->sample();
        $this->now += 2.0;
        $this->tree->fd(1001, 5, 'i915-t1.txt');
        [$scan] = $scanner->sample();

        $this->assertTrue($scan->partial);
        $this->assertTrue($scan->complete);
        $this->assertEqualsWithDelta(50.0, $scan->utilization('0000:00:02.0'), 1e-9, 'visible clients: a lower bound');
        $this->assertSame(0.0, $scan->utilization('0000:08:00.0'), 'no visible client on a partial scan: 0 as a lower bound');
        $this->assertTrue($scan->lowerBound());
    }

    public function testIdleIsZeroOnlyAfterACompleteUnrestrictedPass(): void
    {
        foreach (range(1, 4) as $pid) {
            $this->tree->fd($pid, 1, 'not-drm.txt', '/dev/null');
        }
        $scanner = $this->scanner(10.0, null, 3);
        [, $scanner] = $scanner->sample();
        [$scan, $scanner] = $scanner->sample();
        $this->assertTrue($scan->measured);
        $this->assertFalse($scan->complete, 'first pass still walking');
        $this->assertSame(Sentinel::UNMEASURED, $scan->utilization('0000:00:02.0'));
        [$scan] = $scanner->sample();
        $this->assertTrue($scan->complete);
        $this->assertFalse($scan->partial);
        $this->assertSame(0.0, $scan->utilization('0000:00:02.0'));
    }
}
