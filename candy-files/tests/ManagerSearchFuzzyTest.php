<?php

declare(strict_types=1);

namespace SugarCraft\Files\Tests;

use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Files\Entry;
use SugarCraft\Files\Manager;
use PHPUnit\Framework\TestCase;

/**
 * Covers the sugar-fuzzy-backed file search/filter path.
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

    /**
     * CF-48 (closes 095): a composed (NFC) query must hit a decomposed
     * (NFD) on-disk name — the macOS byte shape — and the result must
     * still carry the RAW entry, since display and selection key off
     * what the filesystem actually stores.
     */
    public function testComposedQueryFindsDecomposedName(): void
    {
        $decomposed = "cafe\u{0301}-menu.txt";
        $tree       = ['/' => [new Entry($decomposed, false, 1, 0)]];
        $m          = Manager::start('/', '/', static fn(string $p): array => $tree[$p] ?? []);

        $found = $m->search("caf\u{00E9}");

        $this->assertNotSame([], $found->searchResults, 'NFC query must find the NFD name');
        $this->assertSame($decomposed, $found->searchResults[0]->name);
    }

    /** CF-48: the reverse direction — NFD query (a pasted macOS name) finds an NFC file. */
    public function testDecomposedQueryFindsComposedName(): void
    {
        $composed = "caf\u{00E9}-menu.txt";
        $tree     = ['/' => [new Entry($composed, false, 1, 0)]];
        $m        = Manager::start('/', '/', static fn(string $p): array => $tree[$p] ?? []);

        $found = $m->search("cafe\u{0301}");

        $this->assertNotSame([], $found->searchResults, 'NFD query must find the NFC name');
        $this->assertSame($composed, $found->searchResults[0]->name);
    }

    /** CF-48: pattern select meets glob and names in the same NFC view. */
    public function testPatternSelectNormalizesBothSides(): void
    {
        $composed = "caf\u{00E9}.txt";
        $tree     = [
            '/' => [
                new Entry($composed, false, 1, 0),
                new Entry('plain.txt', false, 1, 0),
            ],
        ];
        $m = Manager::start('/', '/', static fn(string $p): array => $tree[$p] ?? []);

        [$armed] = $m->update(new KeyMsg(KeyType::Char, '*'));
        foreach (['c', 'a', 'f', 'e', "\u{0301}", '.', 't', 'x', 't'] as $ch) {
            [$armed] = $armed->update(new KeyMsg(KeyType::Char, $ch));
        }
        [$done] = $armed->update(new KeyMsg(KeyType::Enter, ''));

        $this->assertSame([$composed], $done->left->selectedNames(), 'decomposed pattern selects the composed file by its raw name');
    }

    /**
     * C-1 family: bare 0x20 decodes to KeyType::Space, not a printable
     * Char. While the search append leg only accepted Char, a query
     * containing a space was untypeable — file names with spaces are
     * ordinary, so the promise was broken. Drive the real key shape
     * through update(), not the direct search() seam.
     */
    public function testSearchAcceptsSpaceFromTheWire(): void
    {
        $m = $this->start();
        [$m] = $m->update(new KeyMsg(KeyType::Char, '/'));   // enter search
        [$m] = $m->update(new KeyMsg(KeyType::Space, ' '));  // type a space
        $this->assertSame(' ', $m->searchQuery, 'Space must append to the query');
        [$m] = $m->update(new KeyMsg(KeyType::Char, 'm'));   // continue typing
        $this->assertSame(' m', $m->searchQuery);
    }

    public function testEmptyQueryReturnsAllEntries(): void
    {
        $m = $this->start()->search('');
        $this->assertSame('', $m->searchQuery);
        $this->assertCount(6, $m->searchResults);
        // CF-09 re-pin: the corpus is the pane's in-memory listing, so an
        // empty query now returns DISPLAY order (sorted, dirs first) where
        // the old raw-lister call returned unsorted scandir order.
        $this->assertSame(
            ['models', 'human.txt', 'main.php', 'Makefile', 'notes.txt', 'README.md'],
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

    /**
     * CF-09 acceptance: the documented impossible query "keep" types all
     * four keys and reaches its file. Before, the k/j search-cursor hijack
     * ate the first key and the name could never be typed.
     */
    public function testKeepIsFullyTypeableAndFindsItsFile(): void
    {
        $lister = static fn(string $p): array => $p === '/' ? [
            new Entry('keep.txt', false, 10, 0),
            new Entry('join.h', false, 20, 0),
            new Entry('alpha', false, 30, 0),
        ] : [];
        $m = Manager::start('/', '/', $lister);
        [$m] = $m->update(new KeyMsg(KeyType::Char, '/'));
        foreach (['k', 'e', 'e', 'p'] as $ch) {
            [$m] = $m->update(new KeyMsg(KeyType::Char, $ch));
        }
        $this->assertSame('keep', $m->searchQuery);
        // Fuzzy ranking: keep.txt tops the list. Partial scores are a
        // legitimate Smith-Waterman outcome (alpha shares 'a'), so the
        // acceptance is the ranking, not exclusivity.
        $this->assertSame('keep.txt', $this->names($m)[0]);
    }

    /**
     * CF-09 acceptance: zero lister invocations per keystroke. The old
     * search() rescanned the directory on every character (~193 ms/char
     * measured on real trees, and it also bypassed the visibility law).
     */
    public function testSearchIssuesZeroListerCallsPerKeystroke(): void
    {
        $calls = 0;
        $tree = ['/' => [
            new Entry('keep.txt', false, 10, 0),
            new Entry('models', true, 0, 0),
        ]];
        $lister = static function (string $p) use (&$calls, $tree): array {
            $calls++;
            return $tree[$p] ?? [];
        };
        $m = Manager::start('/', '/', $lister);
        $baseline = $calls; // construction-time listing is allowed
        [$m] = $m->update(new KeyMsg(KeyType::Char, '/'));
        foreach (['k', 'e', 'e', 'p'] as $ch) {
            [$m] = $m->update(new KeyMsg(KeyType::Char, $ch));
        }
        [$m] = $m->update(new KeyMsg(KeyType::Down, ''));
        [$m] = $m->update(new KeyMsg(KeyType::Backspace, '')); // 'kee'
        $this->assertSame($baseline, $calls, 'search keystrokes must never touch the lister');
    }

    /**
     * CF-09 (033): the hidden-file visibility law holds inside search.
     * The old raw-lister corpus dumped every dotfile into '/' results —
     * exactly where the UI invites selection — and Enter navigated into
     * one. With showHidden off, hidden names must not surface.
     */
    public function testHiddenLawHoldsInSearch(): void
    {
        $lister = static fn(string $p): array => $p === '/' ? [
            new Entry('.secret', true, 0, 0, isHidden: true),
            new Entry('visible.txt', false, 10, 0),
        ] : [];
        $m = Manager::start('/', '/', $lister);
        [$searching] = $m->update(new KeyMsg(KeyType::Char, '/'));
        $this->assertSame(['visible.txt'], $this->names($searching), 'empty query must honour showHidden');
        [$hunting] = $searching->update(new KeyMsg(KeyType::Char, 's'));
        [$hunting] = $hunting->update(new KeyMsg(KeyType::Char, 'e'));
        [$hunting] = $hunting->update(new KeyMsg(KeyType::Char, 'c'));
        $this->assertNotContains('.secret', $this->names($hunting));
        // Toggling hidden ON (the '.' chord) admits the dotfile — in BROWSE
        // mode: during a search, '.' is ordinary query input (top of the
        // dispatch is the search-mode short-circuit).
        [$browse] = $hunting->update(new KeyMsg(KeyType::Escape, ''));
        [$on] = $browse->update(new KeyMsg(KeyType::Char, '.'));
        $this->assertTrue($on->activePane()->showHidden);
        [$again] = $on->update(new KeyMsg(KeyType::Char, '/'));
        $this->assertContains('.secret', $this->names($again));
    }

    /**
     * CF-09: Enter on a FILE result exits search AND lands the pane cursor
     * on that file — before, the cursor stayed wherever browse mode left
     * it, so the next Enter acted on a different entry than the one chosen.
     */
    public function testEnterOnFileResultLandsCursorOnIt(): void
    {
        $lister = static fn(string $p): array => $p === '/' ? [
            new Entry('home', true, 0, 0),
            new Entry('other.txt', false, 5, 0),
            new Entry('readme', false, 100, 0),
        ] : [];
        $m = Manager::start('/', '/', $lister);
        [$m] = $m->update(new KeyMsg(KeyType::Char, '/'));
        foreach (['r', 'e', 'a', 'd'] as $ch) {
            [$m] = $m->update(new KeyMsg(KeyType::Char, $ch));
        }
        // Top-ranked survivor of "read" is readme.
        $this->assertSame('readme', $this->names($m)[0]);
        [$done] = $m->update(new KeyMsg(KeyType::Enter, ''));
        $this->assertNull($done->searchQuery, 'search must close');
        $this->assertSame('readme', $done->activePane()->currentEntry()?->name,
            'pane cursor must sit on the chosen file');
    }

    /**
     * CF-09: results are hard-capped near the live height. With no height
     * captured the historical 20-row ceiling holds; with termRows the cap
     * follows the viewport that CF-02 established.
     */
    public function testResultsAreCappedToViewportHeight(): void
    {
        $entries = [];
        for ($i = 0; $i < 40; $i++) {
            $entries[] = new Entry(sprintf('file-%02d', $i), false, $i, 0);
        }
        $lister = static fn(string $p): array => $p === '/' ? $entries : [];
        $flat = Manager::start('/', '/', $lister);
        $this->assertCount(20, $flat->search('')->searchResults, 'legacy cap 20 without a live height');

        $pane = \SugarCraft\Files\Pane::open('/', $lister);
        $short = (new \SugarCraft\Files\Manager\ManagerBuilder())
            ->withLister($lister)
            ->withLeft($pane)
            ->withRight($pane)
            ->withTermRows(8)
            ->build();
        $this->assertCount(3, $short->search('')->searchResults,
            'cap must follow termRows-6 with a floor of 3');
    }

    /**
     * CF-41 (audit 052): exitSearch / moveSearchCursor / openSearchResult
     * are public methods with, until now, zero direct pins — the wire arms
     * exercised them only as far as their dispatch test went. The seams
     * here hold the state contracts the dispatch relies on: exitSearch
     * clears the ENTIRE mode (query, results, cursor, and the CF-17 dirs
     * alignment), moveSearchCursor clamps instead of escaping, and
     * openSearchResult routes dir vs file the way the CF-09 landing law
     * demands.
     */
    public function testExitSearchClearsEveryTraceOfTheMode(): void
    {
        $m = $this->start()->search('e');
        $this->assertNotSame([], $m->searchResults, 'premise: results exist to clear');

        $out = $m->exitSearch();
        $this->assertNull($out->searchQuery);
        $this->assertSame([], $out->searchResults);
        $this->assertSame(0, $out->searchCursor);
        $this->assertSame([], $out->searchResultDirs, 'the alignment array must not outlive the rows');
    }

    public function testMoveSearchCursorClampsAtBothEndsAndIsInertWithoutResults(): void
    {
        $m = $this->start()->search('e');
        $count = count($m->searchResults);
        $this->assertGreaterThan(1, $count, 'premise: several hits to walk');

        $this->assertSame(0, $m->moveSearchCursor(-$count)->searchCursor, 'the head clamps, never wraps or goes negative');
        $this->assertSame($count - 1, $m->moveSearchCursor($count)->searchCursor, 'the tail clamps at the last row');

        $idle = $this->start()->moveSearchCursor(1);
        $this->assertNull($idle->searchQuery);
        $this->assertSame([], $idle->searchResults, 'no mode: the seam is inert, not broken');
    }

    public function testOpenSearchResultRoutesDirectoryHitsThroughNavigation(): void
    {
        $m = $this->start()->search('models');
        $this->assertSame('models', $m->searchResults[0]->name, 'premise: the dir hit ranks first for its own name');

        $after = $m->openSearchResult();
        $this->assertSame('/models', $after->activePane()->cwd, 'a dir result NAVIGATES');
        $this->assertNull($after->searchQuery, 'opening also exits the mode (CF-09 landing law)');
    }

    public function testOpenSearchResultLandsTheCursorOnFileHits(): void
    {
        $m = $this->start()->search('README');
        $this->assertSame('README.md', $m->searchResults[0]->name);

        $after = $m->openSearchResult();
        $this->assertNull($after->searchQuery);
        $this->assertSame('/', $after->activePane()->cwd);
        $this->assertSame('README.md', $after->activePane()->currentEntry()?->name,
            'the pane cursor parks ON the file the operator chose');
    }
}
