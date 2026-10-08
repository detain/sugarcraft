<?php

declare(strict_types=1);

namespace SugarCraft\Top\Tests\Panel\Proc;

use PHPUnit\Framework\TestCase;
use SugarCraft\Top\Collect\ContainerRef;
use SugarCraft\Top\Config\Config;
use SugarCraft\Top\Panel\Proc\ProcEntry;
use SugarCraft\Top\Panel\Proc\ProcSorter;
use SugarCraft\Top\Panel\Proc\ProcTable;
use SugarCraft\Top\Panel\Proc\ProcTree;
use SugarCraft\Top\Tests\Support\ProcRows;

/**
 *   1 init
 *   ├─ 10 sshd
 *   │   └─ 11 bash
 *   │       ├─ 12 vim      (cpu 30)
 *   │       └─ 13 top      (cpu 1)
 *   └─ 20 cron            (cpu 5)
 *   2 kthreadd
 *   99 orphan (ppid 4242 does not exist → a root)
 */
final class ProcTreeTest extends TestCase
{
    /** @return list<ProcEntry> */
    private static function family(): array
    {
        return [
            ProcRows::entry(1, ['name' => 'init', 'cpu' => 1.0, 'mem' => 100, 'threads' => 1]),
            ProcRows::entry(2, ['name' => 'kthreadd', 'mem' => 0]),
            ProcRows::entry(10, ['ppid' => 1, 'name' => 'sshd', 'cpu' => 2.0, 'mem' => 10, 'threads' => 2]),
            ProcRows::entry(11, ['ppid' => 10, 'name' => 'bash', 'cpu' => 3.0, 'mem' => 20, 'threads' => 1]),
            ProcRows::entry(12, ['ppid' => 11, 'name' => 'vim', 'cpu' => 30.0, 'mem' => 40, 'threads' => 3]),
            ProcRows::entry(13, ['ppid' => 11, 'name' => 'top', 'cpu' => 1.0, 'mem' => 5, 'threads' => 1]),
            ProcRows::entry(20, ['ppid' => 1, 'name' => 'cron', 'cpu' => 5.0, 'mem' => 7, 'threads' => 1]),
            ProcRows::entry(99, ['ppid' => 4242, 'name' => 'orphan']),
        ];
    }

    /**
     * @param array<int, bool> $collapsed
     * @return list<ProcEntry>
     */
    private static function tree(string $sorting = 'pid', bool $reverse = true, string $filter = '', array $collapsed = [], bool $aggregate = false, bool $ctr = false, ?array $entries = null): array
    {
        $entries = ProcSorter::sort($entries ?? self::family(), $sorting, $reverse, true);

        return ProcTree::build($entries, $sorting, $reverse, $filter, $ctr, $aggregate, $collapsed);
    }

    /** @param list<ProcEntry> $rows @return list<string> */
    private static function lines(array $rows): array
    {
        return array_map(static fn (ProcEntry $e): string => $e->prefix . $e->pid() . ' ' . $e->process->name, $rows);
    }

    public function testPrefixesAndDepth(): void
    {
        $rows = self::tree();
        $this->assertSame([
            '[-]─1 init',
            ' │ [-]─10 sshd',
            ' │  │ [-]─11 bash',
            ' │  │     ├─12 vim',
            ' │  │     └─13 top',
            ' │  └─20 cron',
            ' ├─2 kthreadd',
            ' └─99 orphan',
        ], self::lines($rows));
        $this->assertSame([0, 1, 2, 3, 3, 1, 0, 0], array_map(static fn (ProcEntry $e): int => $e->depth, $rows));
    }

    public function testSiblingsSortByBranchTotalsSoCollapsingNeverMovesABranch(): void
    {
        // cpu direct, descending: sshd's branch (2+3+30+1 = 36) beats cron (5)
        // although sshd alone (2) does not (#1791a).
        $open = self::tree('cpu direct', false);
        $this->assertSame([1, 10, 11, 12, 13, 20, 2, 99], ProcRows::pids($open));
        $closed = self::tree('cpu direct', false, collapsed: [10 => true]);
        $this->assertSame([1, 10, 20, 2, 99], ProcRows::pids($closed), 'collapsed sshd keeps its place');
        $reversed = self::tree('cpu direct', true);
        $this->assertSame([2, 99, 1, 20, 10, 11, 13, 12], ProcRows::pids($reversed));
    }

    public function testCollapsedBranchAbsorbsItsDescendantsAndHidesThem(): void
    {
        $rows = self::tree(collapsed: [11 => true]);
        $this->assertSame(['[-]─1 init', ' │ [-]─10 sshd', ' │  │ [+]─11 bash', ' │  └─20 cron', ' ├─2 kthreadd', ' └─99 orphan'], self::lines($rows));
        $bash = $rows[2];
        $this->assertTrue($bash->collapsed);
        $this->assertEqualsWithDelta(34.0, $bash->cpu, 1e-9);
        $this->assertSame(65, $bash->mem);
        $this->assertSame(5, $bash->threads);
        $this->assertSame(2.0, $rows[1]->cpu, 'an expanded parent keeps its own value');
    }

    public function testDeadChildrenAreNotAbsorbed(): void
    {
        $entries = self::family();
        $entries[5] = ProcRows::entry(13, ['ppid' => 11, 'name' => 'top', 'cpu' => 50.0, 'state' => 'X']);
        $bash = self::tree(collapsed: [11 => true], entries: $entries)[2];
        $this->assertEqualsWithDelta(33.0, $bash->cpu, 1e-9);
    }

    public function testAggregateSumsChildrenWithoutHiding(): void
    {
        $rows = self::tree(aggregate: true);
        $this->assertCount(8, $rows);
        $this->assertEqualsWithDelta(42.0, $rows[0]->cpu, 1e-9, 'init = 1 + sshd branch 36 + cron 5');
        $this->assertEqualsWithDelta(34.0, $rows[2]->cpu, 1e-9);
    }

    public function testFilterShowsMatchesAtDepthZeroWithTheirSubtrees(): void
    {
        $rows = self::tree(filter: 'bash');
        $this->assertSame([11, 12, 13], ProcRows::pids($rows));
        $this->assertSame(0, $rows[0]->depth);
        // sshd is hidden, so bash's header is empty; bash is sshd's last
        // child, so its own children indent by "   " (btop _collect_prefixes).
        $this->assertSame(['[-]─11 bash', '    ├─12 vim', '    └─13 top'], self::lines($rows));
    }

    public function testContainerFilterHidesInTreeView(): void
    {
        $entries = self::family();
        $entries[] = ProcRows::entry(30, ['ppid' => 1, 'name' => 'pyworker', 'container' => new ContainerRef('docker', 'abc', 'abc', '/d')]);
        $this->assertContains(30, ProcRows::pids(self::tree(entries: $entries)));
        $this->assertNotContains(30, ProcRows::pids(self::tree(ctr: true, entries: $entries)));
    }

    public function testToggleAllCollapsesThenExpandsEveryNonRoot(): void
    {
        $entries = self::family();
        $collapsed = ProcTree::toggleAll($entries, []);
        $this->assertTrue($collapsed[10]);
        $this->assertArrayNotHasKey(1, $collapsed, 'roots are never touched');
        $this->assertSame([1, 10, 20, 2, 99], ProcRows::pids(self::tree(collapsed: $collapsed)));
        $expanded = ProcTree::toggleAll($entries, $collapsed);
        $this->assertFalse($expanded[10]);
        $this->assertCount(8, self::tree(collapsed: $expanded));
    }

    public function testToggleChildren(): void
    {
        $collapsed = ProcTree::toggleChildren(self::family(), [], 1);
        $this->assertSame([10 => true, 20 => true], $collapsed);
        $this->assertSame([10 => false, 20 => false], ProcTree::toggleChildren(self::family(), $collapsed, 1));
    }

    public function testAutoCollapseOnlyBelowTheRootsChildren(): void
    {
        // bash (depth 2) has 2 children; sshd (a child of root init) is exempt.
        $this->assertSame([11 => true], ProcTree::autoCollapse(self::family(), [], 2));
        $this->assertSame([], ProcTree::autoCollapse(self::family(), [], 3));
        $this->assertSame([], ProcTree::autoCollapse(self::family(), [], 0), '0 disables it');
    }

    public function testEmptyAndTableIntegration(): void
    {
        $this->assertSame([], ProcTree::build([], 'pid', false, '', false, false, []));
        $config = Config::new()->with('proc_tree', true)->with('proc_sorting', 'pid')->with('proc_reversed', true);
        [$rows, $sorted] = ProcTable::build(self::family(), $config, []);
        $this->assertSame([1, 10, 11, 12, 13, 20, 2, 99], ProcRows::pids($rows));
        $this->assertCount(8, $sorted);
        $this->assertNotSame(ProcTable::key($config, 0), ProcTable::key($config, 1), 'the tree version is part of the memo key');
    }

    public function testPlainTableFiltersAfterSorting(): void
    {
        $config = Config::new()->with('proc_sorting', 'pid')->with('proc_filter', 'sh');
        [$rows, $sorted] = ProcTable::build(self::family(), $config, []);
        $this->assertSame([11, 10], ProcRows::pids($rows), 'bash and sshd, pid descending');
        $this->assertCount(8, $sorted, 'filtered entries keep their sorted place');
    }
}
