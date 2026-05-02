<?php

declare(strict_types=1);

namespace CandyCore\SuperCandy\Tests;

use CandyCore\SuperCandy\Entry;
use CandyCore\SuperCandy\Sort;
use PHPUnit\Framework\TestCase;

final class SortTest extends TestCase
{
    /** @return list<Entry> */
    private function fixture(): array
    {
        return [
            Entry::parent(),
            new Entry('zebra.txt',   false, 1024,  100),
            new Entry('alpha.txt',   false, 8192,  200),
            new Entry('subdir',      true,  0,     50),
            new Entry('beta.txt',    false, 2048,  150),
            new Entry('Zoo',         true,  0,     75),
        ];
    }

    public function testNameAscDirectoriesFirstThenCaseInsensitive(): void
    {
        $sorted = Sort::NameAsc->apply($this->fixture());
        $names  = array_map(fn(Entry $e) => $e->name, $sorted);
        // .. always first, then dirs sorted case-insensitively, then files.
        $this->assertSame(['..', 'subdir', 'Zoo', 'alpha.txt', 'beta.txt', 'zebra.txt'], $names);
    }

    public function testNameDescReversesFilesAndDirsIndividually(): void
    {
        $sorted = Sort::NameDesc->apply($this->fixture());
        $names  = array_map(fn(Entry $e) => $e->name, $sorted);
        $this->assertSame('..', $names[0]);
        // Dirs come first; within them descending: Zoo > subdir
        $this->assertSame(['Zoo', 'subdir'], array_slice($names, 1, 2));
        // Files descending: zebra > beta > alpha
        $this->assertSame(['zebra.txt', 'beta.txt', 'alpha.txt'], array_slice($names, 3));
    }

    public function testMtimeAscOrdersByMtimeAfterDirs(): void
    {
        $sorted = Sort::MtimeAsc->apply($this->fixture());
        $files = array_filter($sorted, fn(Entry $e) => !$e->isDir);
        $files = array_values($files);
        $this->assertSame('zebra.txt',   $files[0]->name); // mtime 100
        $this->assertSame('beta.txt',    $files[1]->name); // mtime 150
        $this->assertSame('alpha.txt',   $files[2]->name); // mtime 200
    }

    public function testSizeDescOrdersByBytesAfterDirs(): void
    {
        $sorted = Sort::SizeDesc->apply($this->fixture());
        $files = array_values(array_filter($sorted, fn(Entry $e) => !$e->isDir));
        $this->assertSame(8192, $files[0]->size);
        $this->assertSame(2048, $files[1]->size);
        $this->assertSame(1024, $files[2]->size);
    }

    public function testCycleVisitsAllOrders(): void
    {
        $seen = [Sort::NameAsc->value];
        $cur = Sort::NameAsc;
        for ($i = 0; $i < 5; $i++) {
            $cur = $cur->cycle();
            $seen[] = $cur->value;
        }
        $this->assertSame(
            ['name-asc', 'name-desc', 'mtime-asc', 'mtime-desc', 'size-asc', 'size-desc'],
            $seen,
        );
        $this->assertSame(Sort::NameAsc, $cur->cycle(), 'cycle wraps back to name-asc');
    }
}
