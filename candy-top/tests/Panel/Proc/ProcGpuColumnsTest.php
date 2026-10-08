<?php

declare(strict_types=1);

namespace SugarCraft\Top\Tests\Panel\Proc;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SugarCraft\Top\Collect\Gpu\Accelerators;
use SugarCraft\Top\Collect\Memory;
use SugarCraft\Top\Config\Config;
use SugarCraft\Top\Panel\Proc\ProcEntry;
use SugarCraft\Top\Panel\Proc\ProcFilter;
use SugarCraft\Top\Panel\Proc\ProcGpuColumns;
use SugarCraft\Top\Panel\Proc\ProcSorter;
use SugarCraft\Top\Panel\Proc\ProcTable;
use SugarCraft\Top\Panel\Proc\ProcTree;
use SugarCraft\Top\Panel\Proc\ProcView;
use SugarCraft\Top\Source\CollectorSource;
use SugarCraft\Top\Source\Fake\FakeGpuProcesses;
use SugarCraft\Top\Tests\Support\ProcRows;

/**
 * btop #1552 in the proc table: the GMem / Gpu% column geometry, the cell
 * labels, the gpu / gpu memory sorts (plain + tree branch totals), the
 * proc_gpu_only filter, and the collect-time gate.
 */
final class ProcGpuColumnsTest extends TestCase
{
    /** @param array<string, mixed> $o */
    private static function gpuEntry(int $pid, float $gpu, int $gpuMem, array $o = []): ProcEntry
    {
        $p = ProcRows::process($pid, $o);

        return ProcEntry::of($p, $p->cpu, $p->ioRead, $p->ioWrite, $gpu, $gpuMem);
    }

    // ---- geometry ------------------------------------------------------------

    /** The pre-#1552 formula, verbatim, for the no-GPU regression check. */
    private static function legacy(int $width, bool $graphs): array
    {
        $user = $width < 75 ? 5 : 10;
        $thread = $width < 75 ? -1 : 4;
        $io = $width < 90 ? -1 : 14;
        $ioUsed = $io > 0 ? $io : 0;
        $prog = $width > 70 ? 16 : ($width > 55 ? 8 : $width - $user - $thread - $ioUsed - 33);
        $cmd = $width > 55 ? $width - $prog - $user - $thread - $ioUsed - 33 : -1;
        $tree = $width - $user - $thread - $ioUsed - 23;
        if (!$graphs) {
            $cmd += 5;
            $tree += 5;
        }

        return ['user' => $user, 'thread' => $thread, 'prog' => $prog, 'cmd' => $cmd, 'tree' => $tree, 'io' => $io];
    }

    public function testWithoutGpuDataTheLayoutIsUnchanged(): void
    {
        foreach ([true, false] as $graphs) {
            for ($w = 20; $w <= 260; $w++) {
                $sz = ProcView::sizes($w, $graphs);
                $this->assertSame(self::legacy($w, $graphs), array_intersect_key($sz, self::legacy($w, $graphs)), "width $w");
                $this->assertSame([-1, -1, 0], [$sz['gpu'], $sz['gmem'], $sz['ggraph']]);
            }
        }
    }

    /** @return iterable<string, array{int, bool, bool, int, int, int, int}> width, cpu graphs, gpu graphs => gpu, gmem, io, cmd */
    public static function thresholds(): iterable
    {
        yield 'below Gpu%' => [85, true, true, -1, -1, -1, 22];
        yield 'Gpu% + graph' => [86, true, true, 6, -1, -1, 12];
        yield 'below GMem' => [91, true, true, 6, -1, -1, 17];
        yield 'GMem' => [92, true, true, 6, 6, -1, 12];
        yield 'IO waits for room' => [105, true, true, 6, 6, -1, 25];
        yield 'IO back' => [106, true, true, 6, 6, 14, 12];
        yield 'no gpu graphs: Gpu%' => [81, true, false, 6, -1, -1, 12];
        yield 'no gpu graphs: GMem' => [87, true, false, 6, 6, -1, 12];
        yield 'no cpu graphs: Gpu%' => [81, false, true, 6, -1, -1, 12];
        yield 'never in the narrow layout' => [74, false, false, -1, -1, -1, 26];
        yield 'command floor in the wide layout' => [75, false, false, -1, -1, -1, 17];
        yield 'lowest Gpu% of all' => [76, false, false, 6, -1, -1, 12];
    }

    #[DataProvider('thresholds')]
    public function testColumnsAppearOnlyWhileTheCommandKeepsTwelveCells(int $w, bool $cpuGraphs, bool $gpuGraphs, int $gpu, int $gmem, int $io, int $cmd): void
    {
        $sz = ProcView::sizes($w, $cpuGraphs, true, $gpuGraphs);
        $this->assertSame([$gpu, $gmem, $io], [$sz['gpu'], $sz['gmem'], $sz['io']]);
        $this->assertSame($cmd, $sz['cmd']);
        $this->assertSame($sz['gpu'] > 0 && $gpuGraphs ? 5 : 0, $sz['ggraph']);
    }

    public function testColumnsAreMonotoneInWidthAndNeverStarveTheCommand(): void
    {
        foreach ([[true, true], [true, false], [false, true], [false, false]] as [$cg, $gg]) {
            $seen = ['gpu' => false, 'gmem' => false];
            for ($w = 40; $w <= 260; $w++) {
                $sz = ProcView::sizes($w, $cg, true, $gg);
                foreach (['gpu', 'gmem'] as $k) {
                    if ($seen[$k]) {
                        $this->assertGreaterThan(0, $sz[$k], "$k vanished again at $w");
                    }
                    $seen[$k] = $seen[$k] || $sz[$k] > 0;
                }
                if ($sz['gpu'] > 0) {
                    $this->assertGreaterThanOrEqual(ProcView::MIN_CMD, $sz['cmd'], "command column at $w");
                    $used = 6 + $sz['ggraph'] + ($sz['gmem'] > 0 ? 6 : 0) + ($sz['io'] > 0 ? 14 : 0);
                    $legacy = self::legacy($w, $cg);
                    $this->assertSame($legacy['cmd'] + ($legacy['io'] > 0 ? 14 : 0) - $used, $sz['cmd'], "cells come out of cmd at $w");
                    $this->assertSame($legacy['tree'] + ($legacy['io'] > 0 ? 14 : 0) - $used, $sz['tree'], "and out of tree at $w");
                }
            }
            $this->assertTrue($seen['gpu'] && $seen['gmem']);
        }
    }

    // ---- labels ----------------------------------------------------------------

    public function testGpuLabel(): void
    {
        $this->assertSame('-', ProcView::gpuLabel(0.0, 0), 'no GPU time and no memory');
        $this->assertSame('0.0', ProcView::gpuLabel(0.0, 4096), 'memory only: a measured idle');
        $this->assertSame('12.3', ProcView::gpuLabel(12.34, 0));
        $this->assertSame('100', ProcView::gpuLabel(100.0, 0), '"100.0" cut to 4 drops the point');
        $this->assertSame('100', ProcView::gpuLabel(250.0, 0), 'a collapsed branch sum still clamps');
        $this->assertSame('5.0', ProcView::gpuLabel(5.0, 0));
    }

    public function testGpuMemLabel(): void
    {
        $this->assertSame('-', ProcView::gpuMemLabel(0));
        $this->assertSame('-', ProcView::gpuMemLabel(-1));
        $this->assertSame('6.0G', ProcView::gpuMemLabel(6 * 1024 ** 3));
        $this->assertSame('180M', ProcView::gpuMemLabel(180 * 1024 ** 2));
    }

    // ---- sorting ----------------------------------------------------------------

    public function testGpuSortsDescendByDefault(): void
    {
        $entries = [self::gpuEntry(1, 5.0, 900), self::gpuEntry(2, 50.0, 100), self::gpuEntry(3, 0.0, 0)];
        $this->assertSame([2, 1, 3], ProcRows::pids(ProcSorter::sort($entries, 'gpu', false)));
        $this->assertSame([3, 1, 2], ProcRows::pids(ProcSorter::sort($entries, 'gpu', true)));
        $this->assertSame([1, 2, 3], ProcRows::pids(ProcSorter::sort($entries, 'gpu memory', false)));
        $this->assertSame([3, 2, 1], ProcRows::pids(ProcSorter::sort($entries, 'gpu memory', true)));
    }

    public function testTreeSortsSiblingsByGpuBranchTotalsAndCollapseAbsorbsGpu(): void
    {
        // 1 ─┬─ 10 (idle) ── 11 (gpu 40, 2 GiB)
        //    └─ 20 (gpu 30, 1 GiB)
        $entries = [
            self::gpuEntry(1, 0.0, 0),
            self::gpuEntry(10, 0.0, 0, ['ppid' => 1]),
            self::gpuEntry(11, 40.0, 2048, ['ppid' => 10]),
            self::gpuEntry(20, 30.0, 1024, ['ppid' => 1]),
        ];
        $tree = static fn (string $sort, array $collapsed = [], bool $aggregate = false): array => ProcTree::build(
            ProcSorter::sort($entries, $sort, false, true),
            $sort,
            false,
            '',
            false,
            $aggregate,
            $collapsed,
        );
        $this->assertSame([1, 10, 11, 20], ProcRows::pids($tree('gpu')), '#1791a: branch 10 carries 11\'s 40 %');
        $this->assertSame([1, 10, 11, 20], ProcRows::pids($tree('gpu memory')));
        $collapsed = $tree('gpu', [10 => true]);
        $this->assertSame([1, 10, 20], ProcRows::pids($collapsed));
        $this->assertSame(40.0, $collapsed[1]->gpu, 'the collapsed parent absorbs its child (PR: gpu_p += )');
        $this->assertSame(2048, $collapsed[1]->gpuMem);
        $aggregated = $tree('pid', [], true);
        $this->assertSame(70.0, $aggregated[0]->gpu, 'proc_aggregate sums gpu into the root');
        $this->assertSame(3072, $aggregated[0]->gpuMem);
    }

    // ---- proc_gpu_only -----------------------------------------------------------

    /** @return list<ProcEntry> */
    private static function mixed(): array
    {
        return [
            self::gpuEntry(1, 0.0, 0, ['name' => 'init']),
            self::gpuEntry(10, 0.0, 0, ['ppid' => 1, 'name' => 'shell']),
            self::gpuEntry(11, 12.0, 0, ['ppid' => 10, 'name' => 'trainer']),
            self::gpuEntry(12, 0.0, 0, ['ppid' => 11, 'name' => 'helper']),
            self::gpuEntry(20, 0.0, 4096, ['ppid' => 1, 'name' => 'xorg']),
        ];
    }

    public function testGpuHidden(): void
    {
        $this->assertFalse(ProcFilter::gpuHidden(self::gpuEntry(1, 0.0, 0), false));
        $this->assertTrue(ProcFilter::gpuHidden(self::gpuEntry(1, 0.0, 0), true));
        $this->assertFalse(ProcFilter::gpuHidden(self::gpuEntry(1, 0.1, 0), true));
        $this->assertFalse(ProcFilter::gpuHidden(self::gpuEntry(1, 0.0, 1), true), 'memory alone counts');
    }

    public function testPlainGpuOnlyKeepsGpuProcessesAndCombinesWithTheTextFilter(): void
    {
        $config = Config::new()->with('proc_sorting', 'pid')->with('proc_reversed', true)->with('proc_gpu_only', true);
        [$rows, $sorted] = ProcTable::build(self::mixed(), $config, [], true);
        $this->assertSame([11, 20], ProcRows::pids($rows));
        $this->assertCount(5, $sorted);
        [$rows] = ProcTable::build(self::mixed(), $config->with('proc_filter', 'xo'), [], true);
        $this->assertSame([20], ProcRows::pids($rows));
    }

    public function testGpuOnlyIsInertWithoutGpuData(): void
    {
        $config = Config::new()->with('proc_sorting', 'pid')->with('proc_gpu_only', true);
        [$rows] = ProcTable::build(self::mixed(), $config, [], false);
        $this->assertCount(5, $rows, 'no per-process GPU data: the filter cannot judge, so it shows all');
        $this->assertSame(
            ProcTable::key($config->with('proc_gpu_only', false), 0, false),
            ProcTable::key($config, 0, false),
            'the memo key only sees the filter while it applies',
        );
        $this->assertNotSame(ProcTable::key($config, 0, true), ProcTable::key($config, 0, false));
    }

    public function testTreeGpuOnlyShowsMatchesAtDepthZeroWithTheirSubtree(): void
    {
        $config = Config::new()->with('proc_sorting', 'pid')->with('proc_reversed', true)->with('proc_tree', true)->with('proc_gpu_only', true);
        [$rows] = ProcTable::build(self::mixed(), $config, [], true);
        // btop _tree_gen: the first match restarts at depth 0 and keeps its children.
        $this->assertSame([11, 12, 20], ProcRows::pids($rows));
        $this->assertSame(0, $rows[0]->depth);
        $this->assertSame(1, $rows[1]->depth);
    }

    // ---- collect-time gate --------------------------------------------------------

    public function testWanted(): void
    {
        $config = Config::new();
        $this->assertTrue(ProcGpuColumns::wanted($config), 'no width: the box may be wide');
        $this->assertFalse(ProcGpuColumns::wanted($config, 66));
        $this->assertTrue(ProcGpuColumns::wanted($config, 86));
        $this->assertFalse(ProcGpuColumns::wanted($config, 85));
        $this->assertTrue(ProcGpuColumns::wanted($config->with('proc_gpu_graphs', false), 81));
        $this->assertTrue(ProcGpuColumns::wanted($config->with('proc_gpu_only', true), 40), 'the filter needs data at any width');
        $this->assertTrue(ProcGpuColumns::wanted($config->with('proc_sorting', 'gpu'), 40));
        $this->assertTrue(ProcGpuColumns::wanted($config->with('proc_sorting', 'gpu memory'), 40));
        $this->assertSame(['gpu', 'gpu memory'], ProcGpuColumns::SORTS);
    }

    public function testTunedSwitchesPerProcessCollectionOnForGpuCollectorsOnly(): void
    {
        $acc = CollectorSource::of(Accelerators::detect());
        $this->assertFalse($acc->collector()->processesEnabled());
        $tuned = ProcGpuColumns::tuned($acc);
        $this->assertInstanceOf(CollectorSource::class, $tuned);
        $this->assertInstanceOf(Accelerators::class, $tuned->collector());
        $this->assertTrue($tuned->collector()->processesEnabled());
        $this->assertSame($tuned, ProcGpuColumns::tuned($tuned), 'already on: unchanged');

        $fake = FakeGpuProcesses::demo();
        $this->assertSame($fake, ProcGpuColumns::tuned($fake));
        $other = CollectorSource::of(Memory::new());
        $this->assertSame($other, ProcGpuColumns::tuned($other));
    }
}
