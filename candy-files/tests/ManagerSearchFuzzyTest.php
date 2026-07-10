<?php

declare(strict_types=1);

namespace SugarCraft\Files\Tests;

use SugarCraft\Files\Entry;
use SugarCraft\Files\Manager;
use PHPUnit\Framework\TestCase;

/**
 * Covers the candy-fuzzy-backed file search/filter path.
 *
 * The listing below is chosen so fuzzy behaviour is distinguishable from the
 * historical exact-substring filter: e.g. "rdme" is a subsequence of
 * "README.md" but not a substring, and "man" is a substring of "human.txt"
 * yet only a scattered subsequence of "main.php".
 */
final class ManagerSearchFuzzyTest extends TestCase
{
    private function fakeFs(): \Closure
    {
        $tree = [
            '/' => [
                new Entry('README.md', false, 100, 0),
                new Entry('main.php',  false, 200, 0),
                new Entry('Makefile',  false, 300, 0),
                new Entry('models',    true,    0, 0),
                new Entry('human.txt', false, 400, 0),
                new Entry('notes.txt', false, 500, 0),
            ],
        ];
        return static fn(string $path): array => $tree[$path] ?? [];
    }

    private function start(): Manager
    {
        return Manager::start('/', '/', $this->fakeFs());
    }

    private function names(Manager $m): array
    {
        return array_map(static fn(Entry $e) => $e->name, $m->searchResults);
    }

    public function testEmptyQueryReturnsAllEntries(): void
    {
        $m = $this->start()->search('');
        $this->assertSame('', $m->searchQuery);
        $this->assertCount(6, $m->searchResults);
        $this->assertSame(
            ['README.md', 'main.php', 'Makefile', 'models', 'human.txt', 'notes.txt'],
            $this->names($m),
        );
    }

    public function testNonMatchingQueryReturnsNone(): void
    {
        $m = $this->start()->search('qqq');
        $this->assertSame('qqq', $m->searchQuery);
        $this->assertSame([], $m->searchResults);
        $this->assertSame(0, $m->searchCursor);
    }

    /**
     * Fuzzy-specific: "rdme" is a non-contiguous subsequence of "README.md"
     * (r-e-a-d-m-e) so fuzzy matching finds it, but the old substring filter
     * (str_contains) would not — this test FAILS if the wiring reverts.
     */
    public function testFuzzyMatchesNonContiguousSubsequence(): void
    {
        $m = $this->start()->search('rdme');
        $this->assertNotEmpty($m->searchResults);
        $this->assertContains('README.md', $this->names($m));
    }

    /**
     * Fuzzy-specific: "man" is a substring of "human.txt" but only a scattered
     * subsequence of "main.php". Fuzzy matching returns BOTH and ranks the
     * contiguous "human.txt" first; the substring filter would drop "main.php"
     * entirely — the assertContains('main.php') FAILS on revert.
     */
    public function testFuzzyRanksContiguousMatchAboveScattered(): void
    {
        $m = $this->start()->search('man');
        $names = $this->names($m);
        $this->assertContains('human.txt', $names);
        $this->assertContains('main.php', $names);
        $this->assertSame('human.txt', $names[0], 'contiguous match should rank first');
        $this->assertSame(
            array_search('human.txt', $names, true) < array_search('main.php', $names, true),
            true,
            'human.txt should outrank main.php',
        );
    }
}
