<?php

declare(strict_types=1);

namespace SugarCraft\Files\Tests;

use SugarCraft\Files\Entry;
use SugarCraft\Files\Sort;
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
        for ($i = 0; $i < 7; $i++) {
            $cur = $cur->cycle();
            $seen[] = $cur->value;
        }
        $this->assertSame(
            ['name-asc', 'name-desc', 'mtime-asc', 'mtime-desc', 'size-asc', 'size-desc', 'ext-asc', 'ext-desc'],
            $seen,
        );
        $this->assertSame(Sort::NameAsc, $cur->cycle(), 'cycle wraps back to name-asc');
    }

    /**
     * CF-46 (closes 082): a fixture built so every ordering pressure
     * is exercised — two no-suffix names (a leading-dot file is NOT a
     * suffix), a case-folded jpg pair that must interleave, a same-mtime
     * md pair needing the name tiebreak, and a directory whose empty
     * suffix must not let it drift out of the dirs-first block.
     *
     * @return list<Entry>
     */
    private function extFixture(): array
    {
        return [
            Entry::parent(),
            new Entry('b.md',      false, 1, 10),
            new Entry('note',      false, 1, 99),
            new Entry('.bashrc',   false, 1, 1),
            new Entry('assets',    true,  0, 40),
            new Entry('a.md',      false, 1, 10),
            new Entry('photo.JPG', false, 1, 7),
            new Entry('z.md',      false, 1, 5),
            new Entry('notes.jpg', false, 1, 2),
            new Entry('x.php',     false, 1, 3),
        ];
    }

    public function testExtAscIsTotalOrderSuffixThenMtimeThenName(): void
    {
        $names = array_map(fn(Entry $e) => $e->name, Sort::ExtAsc->apply($this->extFixture()));
        $this->assertSame(
            ['..', 'assets', '.bashrc', 'note', 'notes.jpg', 'photo.JPG', 'z.md', 'a.md', 'b.md', 'x.php'],
            $names,
        );
        // Re-sorting the settled result changes nothing — determinism is
        // the whole point of the mtime+name tiebreak chain.
        $settled = array_map(fn(Entry $e) => $e->name, Sort::ExtAsc->apply(
            array_map(fn(string $n) => $this->entryByName($n), $names)
        ));
        $this->assertSame($names, $settled);
    }

    public function testExtDescMirrorsWithinDirsFirst(): void
    {
        $names = array_map(fn(Entry $e) => $e->name, Sort::ExtDesc->apply($this->extFixture()));
        $this->assertSame(
            ['..', 'assets', 'x.php', 'b.md', 'a.md', 'z.md', 'photo.JPG', 'notes.jpg', 'note', '.bashrc'],
            $names,
        );
    }

    private function entryByName(string $name): Entry
    {
        foreach ($this->extFixture() as $e) {
            if ($e->name === $name) {
                return $e;
            }
        }
        $this->fail("no fixture entry named $name");
    }

    public function testExtensionHelperIsTheSortKey(): void
    {
        $this->assertSame('', Sort::extension('.bashrc'), 'a leading dot is the name, not a suffix');
        $this->assertSame('', Sort::extension('note'));
        $this->assertSame('gz', Sort::extension('archive.tar.gz'), 'the LAST dot wins');
        $this->assertSame('jpg', Sort::extension('photo.JPG'), 'case-folded');
    }
}
