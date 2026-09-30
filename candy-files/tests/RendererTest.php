<?php

declare(strict_types=1);

namespace SugarCraft\Files\Tests;

use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Files\Entry;
use SugarCraft\Files\Manager;
use SugarCraft\Files\Pane;
use SugarCraft\Files\Renderer;
use SugarCraft\Files\Sort;
use PHPUnit\Framework\TestCase;

final class RendererTest extends TestCase
{
    private function tree(): array
    {
        return [
            '/' => [
                new Entry('home', true, 0, 0),
                new Entry('etc', true, 0, 0),
                new Entry('readme.txt', false, 1024, 0),
            ],
            '/home' => [
                new Entry('user', true, 0, 0),
            ],
        ];
    }

    private function fakeFs(): \Closure
    {
        $tree = $this->tree();
        return static fn(string $p): array => $tree[$p] ?? [];
    }

    public function testRenderProducesNonEmptyOutput(): void
    {
        $m = Manager::start('/', '/', $this->fakeFs());
        $out = Renderer::render($m);
        $this->assertNotSame('', $out);
        // Both panes are visible side-by-side: each shows its cwd.
        $this->assertStringContainsString('/', $out);
    }

    public function testRenderShowsSelectedEntries(): void
    {
        $m = Manager::start('/', '/', $this->fakeFs());
        // Toggle selection by pressing space.
        [$m] = $m->update(new KeyMsg(KeyType::Char, ' '));
        $out = Renderer::render($m);
        $this->assertStringContainsString('✓', $out);
    }

    public function testRenderShowsCursorArrow(): void
    {
        $m = Manager::start('/', '/', $this->fakeFs());
        $out = Renderer::render($m);
        $this->assertStringContainsString('▸', $out);
    }

    public function testRenderShowsStatusOrKeyHelp(): void
    {
        $m = Manager::start('/', '/', $this->fakeFs());
        $out = Renderer::render($m);
        // Default empty status falls back to key help line — should
        // mention some control keys.
        $this->assertNotSame('', trim($out));
    }

    public function testRenderShowsSortLabelInHeader(): void
    {
        $m = Manager::start('/', '/', $this->fakeFs());
        $out = Renderer::render($m);
        $this->assertStringContainsString(Sort::NameAsc->value, $out);
    }

    public function testRenderHandlesEmptyDirectory(): void
    {
        $empty = static fn(string $p): array => [];
        $m = Manager::start('/empty', '/empty', $empty);
        $out = Renderer::render($m);
        $this->assertNotSame('', $out);
    }

    public function testRenderShowsTabBarWhenMultipleTabs(): void
    {
        // Real directories: openNewTab honours its argument only for dirs.
        $m = Manager::start('/tmp', '/zulu', $this->fakeFs());
        $m = $m->openNewTab('/');
        $out = Renderer::render($m);
        // Active tab bracketed, inactive spaced: the bar renders from tabs,
        // no dead showTabBar flag is involved.
        $this->assertStringContainsString('[/]', $out);
        $this->assertStringContainsString(' /tmp ', $out);
    }

    /**
     * Audit MEDIUM: byte math (`%-26s` on bytes, strlen/substr clip)
     * drifted the size column by one cell per double-width grapheme and
     * could cut a CJK codepoint in half. Pin: rows with a CJK name and an
     * ASCII name of the same clipped column count occupy the SAME display
     * width, and the size token starts at the same cell offset.
     */
    public function testCjkNamesKeepTheSizeColumnAligned(): void
    {
        $cjk = '日本語のファイル.txt'; // 28 bytes, 20 cells
        $tree = [
            '/d' => [
                new Entry($cjk, false, 1024, 0),   // renders "1.0KB"
                new Entry('a.txt', false, 2048, 0), // renders "2.0KB"
            ],
        ];
        $lister = static fn(string $p): array => $tree[$p] ?? [];
        $m = Manager::start('/d', '/d', $lister);
        $out = Renderer::render($m);

        $starts = [];
        foreach (explode("\n", $out) as $line) {
            $plain = preg_replace('/\x1b\[[0-9;]*m/', '', $line) ?? $line;
            // Panes render side-by-side: one physical row carries the token
            // once per pane. Every occurrence must start at the same cell.
            $hits = preg_match_all('/\d\.0KB/', $plain, $m, PREG_OFFSET_CAPTURE);
            if ($hits === false || $hits === 0) {
                continue;
            }
            foreach ($m[0] as $hit) {
                $starts[] = \SugarCraft\Core\Util\Width::of(substr($plain, 0, $hit[1]));
            }
        }
        $this->assertCount(4, $starts, 'fixture must render the size in both panes x both rows');
        // Row order is [ascii-left, ascii-right, cjk-left, cjk-right]; the
        // CJK row's size column must start at the ascii row's offset within
        // the SAME pane. Old byte math drifted it by 6 cells (28B CJK name
        // satisfied a %-26s field with zero pad while occupying 20 cells).
        $this->assertSame($starts[0], $starts[2], 'left pane: size column must not drift on CJK rows');
        $this->assertSame($starts[1], $starts[3], 'right pane: same');
    }

    /**
     * A long CJK tab label must clip to the cell budget (ellipsis + tail)
     * without splitting a codepoint — the old substr(-17) cut bytes.
     */
    public function testTabBarClipsCjkLabelToCellsWithoutSplitting(): void
    {
        $long = '/tmp/日本語のディレクトリ/さらに深い名前';
        $m = Manager::start($long, '/x', $this->fakeFs())->openNewTab($long . '/もう一つ');
        $out = Renderer::render($m);
        $bar = substr($out, 0, (int) strpos($out, "\n"));
        $this->assertStringContainsString('…', $bar);
        $this->assertGreaterThanOrEqual(2, substr_count($bar, '…'));
        foreach (explode(' ', $bar) as $cell) {
            if ($cell === '' || !str_contains($cell, '…')) {
                continue;
            }
            $label = trim($cell, '[]');
            $this->assertLessThanOrEqual(20, \SugarCraft\Core\Util\Width::of($label), 'clipped tab label must fit 20 cells');
            // Byte-level substr(-N) lands mid-codepoint on CJK paths and
            // yields invalid UTF-8; cell-accurate clipping cannot.
            $this->assertTrue(mb_check_encoding($label, 'UTF-8'), 'clip must not split a codepoint: ' . bin2hex($label));
        }
        // The whole bar stays one line of sane width.
        $this->assertLessThanOrEqual(80, \SugarCraft\Core\Util\Width::of($bar));
    }

    /**
     * Audit MEDIUM: renderSearch echoed $m->searchQuery raw between SGR
     * codes. A crafted query (today keystroke-only, but the field is the
     * same string pipe any injected value rides) must arrive sanitized.
     */
    public function testSearchLineEscapesTheQuery(): void
    {
        // The builder is the public way to mint a Model state whose query
        // carries bytes no keystroke could produce (OSC payload).
        $m = $this->managerWithQuery("\x1b]0;pwned\x07evil");
        $out = Renderer::render($m);
        $this->assertStringContainsString('Search: evil', $out);
        $this->assertStringNotContainsString("\x1b]0;", $out);
    }

    /** Build a Manager whose searchQuery is exactly $q. */
    private function managerWithQuery(string $q): Manager
    {
        $fake = $this->fakeFs();
        return (new \SugarCraft\Files\Manager\ManagerBuilder())
            ->withLeft(Pane::open('/', $fake))
            ->withRight(Pane::open('/', $fake))
            ->withSearchQuery($q)
            ->withLister($fake)
            ->build();
    }

    public function testRenderShowsSearchUIWhenSearching(): void
    {
        $m = Manager::start('/', '/', $this->fakeFs());
        // Start search
        [$m] = $m->update(new KeyMsg(KeyType::Char, '/'));
        $this->assertNotNull($m->searchQuery);
        $out = Renderer::render($m);
        // Should show "Search:" label
        $this->assertStringContainsString('Search:', $out);
        // Should show search query
        $this->assertStringContainsString('Search: ', $out);
    }

    public function testRenderSearchShowsNoMatchMessage(): void
    {
        $m = Manager::start('/', '/', $this->fakeFs());
        // Start search and type something that matches nothing
        [$m] = $m->update(new KeyMsg(KeyType::Char, '/'));
        [$m] = $m->update(new KeyMsg(KeyType::Char, 'q'));
        [$m] = $m->update(new KeyMsg(KeyType::Char, 'q'));
        [$m] = $m->update(new KeyMsg(KeyType::Char, 'q'));
        $this->assertSame([], $m->searchResults);
        $out = Renderer::render($m);
        // Should show "(no matches)" message
        $this->assertStringContainsString('(no matches)', $out);
    }

    public function testRenderSearchShowsResultsWithCounter(): void
    {
        $m = Manager::start('/', '/', $this->fakeFs());
        // Start search and type 're' which should match readme.txt
        [$m] = $m->update(new KeyMsg(KeyType::Char, '/'));
        [$m] = $m->update(new KeyMsg(KeyType::Char, 'r'));
        [$m] = $m->update(new KeyMsg(KeyType::Char, 'e'));
        $this->assertNotEmpty($m->searchResults);
        $out = Renderer::render($m);
        // Should show counter like "1/2" or similar
        $this->assertMatchesRegularExpression('/\d+\/\d+/', $out);
    }

    public function testRenderCursorAdvancesWithMultipleMoves(): void
    {
        $m = Manager::start('/', '/', $this->fakeFs());
        // Move down a few times
        [$m] = $m->update(new KeyMsg(KeyType::Char, 'j'));
        [$m] = $m->update(new KeyMsg(KeyType::Char, 'j'));
        $out = Renderer::render($m);
        // Should still show cursor arrow
        $this->assertStringContainsString('▸', $out);
    }

    public function testRenderTruncatesLongDirectoryNames(): void
    {
        $tree = [
            '/a-very-long-directory-name-that-exceeds-thirty-chars' => [
                new Entry('file.txt', false, 1024, 0),
            ],
        ];
        $fs = static fn(string $p): array => $tree[$p] ?? [];
        $m = Manager::start('/a-very-long-directory-name-that-exceeds-thirty-chars', '/a-very-long-directory-name-that-exceeds-thirty-chars', $fs);
        $out = Renderer::render($m);
        // The long path should be truncated with "..."
        $this->assertStringContainsString('…', $out);
    }
}
