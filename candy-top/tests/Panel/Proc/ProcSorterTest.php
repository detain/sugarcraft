<?php

declare(strict_types=1);

namespace SugarCraft\Top\Tests\Panel\Proc;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SugarCraft\Top\Config\Schema;
use SugarCraft\Top\Panel\Proc\ProcEntry;
use SugarCraft\Top\Panel\Proc\ProcSorter;
use SugarCraft\Top\Tests\Support\ProcRows;

final class ProcSorterTest extends TestCase
{
    /** @return list<ProcEntry> */
    private static function cast(): array
    {
        return [
            ProcRows::entry(30, ['name' => 'bravo', 'cmd' => '/b', 'user' => 'mia', 'threads' => 4, 'mem' => 300, 'cpu' => 2.0, 'cpuC' => 9.0, 'ioR' => 10.0, 'ioW' => 1.0]),
            ProcRows::entry(10, ['name' => 'alpha', 'cmd' => '/c', 'user' => 'zed', 'threads' => 9, 'mem' => 100, 'cpu' => 7.0, 'cpuC' => 1.0, 'ioR' => -1.0, 'ioW' => 50.0]),
            ProcRows::entry(20, ['name' => 'Charlie', 'cmd' => '/a', 'user' => 'ann', 'threads' => 1, 'mem' => 200, 'cpu' => 5.0, 'cpuC' => 5.0, 'ioR' => 30.0, 'ioW' => 30.0]),
        ];
    }

    /** @return iterable<string, array{string, list<int>, list<int>}> */
    public static function keys(): iterable
    {
        // btop_shared.cpp proc_sorter: [default order, reversed order]
        yield 'pid' => ['pid', [30, 20, 10], [10, 20, 30]];
        yield 'name (bytewise: upper case first)' => ['name', [20, 10, 30], [30, 10, 20]];
        yield 'command' => ['command', [20, 30, 10], [10, 30, 20]];
        yield 'threads' => ['threads', [10, 30, 20], [20, 30, 10]];
        yield 'user' => ['user', [20, 30, 10], [10, 30, 20]];
        yield 'memory' => ['memory', [30, 20, 10], [10, 20, 30]];
        yield 'cpu direct' => ['cpu direct', [10, 20, 30], [30, 20, 10]];
        yield 'cpu lazy' => ['cpu lazy', [30, 20, 10], [10, 20, 30]];
        yield 'io read (unknown sorts as 0)' => ['io read', [20, 30, 10], [10, 30, 20]];
        yield 'io write' => ['io write', [10, 20, 30], [30, 20, 10]];
        yield 'io total' => ['io total', [20, 10, 30], [30, 10, 20]];
    }

    /**
     * @param list<int> $default
     * @param list<int> $reversed
     */
    #[DataProvider('keys')]
    public function testEverySortKeyBothDirections(string $sorting, array $default, array $reversed): void
    {
        $this->assertSame($default, ProcRows::pids(ProcSorter::sort(self::cast(), $sorting, false)));
        $this->assertSame($reversed, ProcRows::pids(ProcSorter::sort(self::cast(), $sorting, true)));
    }

    public function testEverySchemaSortValueHasAComparator(): void
    {
        foreach (Schema::PROC_SORTING as $sorting) {
            $this->assertNotNull(ProcSorter::comparator($sorting, false), $sorting);
        }
        $this->assertNull(ProcSorter::comparator('bogus', false));
        $this->assertSame([30, 10, 20], ProcRows::pids(ProcSorter::sort(self::cast(), 'bogus', false)), 'unknown key keeps the order');
    }

    public function testSortIsStableSoTiesKeepThePreviousOrder(): void
    {
        $rows = [
            ProcRows::entry(5, ['mem' => 7]),
            ProcRows::entry(3, ['mem' => 7]),
            ProcRows::entry(9, ['mem' => 7]),
            ProcRows::entry(1, ['mem' => 8]),
        ];
        $this->assertSame([1, 5, 3, 9], ProcRows::pids(ProcSorter::sort($rows, 'memory', false)));
    }

    /**
     * @param list<float> $cpu
     * @return list<ProcEntry> in descending cpu_c order, so "cpu lazy" keeps it before rotating
     */
    private static function lazy(array $cpu): array
    {
        $out = [];
        foreach ($cpu as $i => $c) {
            $out[] = ProcRows::entry(100 + $i, ['cpu' => $c, 'cpuC' => 1000.0 - $i]);
        }

        return $out;
    }

    /** @param list<ProcEntry> $rows @return list<float> */
    private static function cpus(array $rows): array
    {
        return array_map(static fn (ProcEntry $e): float => $e->cpu, $rows);
    }

    public function testLazyRotationPullsHogsOverTheBarForward(): void
    {
        // max over the first six stays 10, so the bar at row six is 10.
        // Every later hog lands at the SAME offset (btop never advances it),
        // so the last one pulled ends up first.
        $sorted = ProcSorter::sort(self::lazy([5, 3, 2, 1, 0, 0, 0, 40, 0, 35, 31]), 'cpu lazy', false);
        $this->assertSame([31.0, 35.0, 40.0, 5.0, 3.0, 2.0, 1.0, 0.0, 0.0, 0.0, 0.0], self::cpus($sorted));
    }

    public function testLazyBarIsTheMaxOfTheTopSixWhenAbove30(): void
    {
        // 50 within the top six: pulled to the front at once, then the bar
        // becomes 50, so the later 40 and 35 stay where cpu_c put them.
        $sorted = ProcSorter::sort(self::lazy([5, 50, 3, 2, 1, 0, 0, 40, 0, 35]), 'cpu lazy', false);
        $this->assertSame([50.0, 5.0, 3.0, 2.0, 1.0, 0.0, 0.0, 40.0, 0.0, 35.0], self::cpus($sorted));
    }

    public function testLazyLeadingHogsStayPut(): void
    {
        $sorted = ProcSorter::sort(self::lazy([45, 35, 2, 1, 0, 0, 0, 0]), 'cpu lazy', false);
        $this->assertSame([45.0, 35.0, 2.0, 1.0, 0.0, 0.0, 0.0, 0.0], self::cpus($sorted));
    }

    public function testLazyRotatesAtMostElevenRows(): void
    {
        $sorted = ProcSorter::sort(self::lazy([...array_fill(0, 7, 0.0), ...array_fill(0, 15, 20.0)]), 'cpu lazy', false);
        $front = array_slice(self::cpus($sorted), 0, 12);
        $this->assertSame([...array_fill(0, 11, 20.0), 0.0], $front);
    }

    public function testLazyRotationIsOffWhenReversedOrInTree(): void
    {
        $rows = self::lazy([5, 3, 2, 1, 0, 0, 0, 40]);
        $this->assertSame(40.0, ProcSorter::sort($rows, 'cpu lazy', false)[0]->cpu);
        $this->assertSame(5.0, ProcSorter::sort($rows, 'cpu lazy', false, true)[0]->cpu, 'tree: no rotation');
        $this->assertSame(40.0, ProcSorter::sort($rows, 'cpu lazy', true)[0]->cpu, 'reversed: cpu_c ascending, no rotation');
    }
}
