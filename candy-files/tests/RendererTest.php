<?php

declare(strict_types=1);

namespace SugarCraft\Files\Tests;

use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Core\Util\Ansi;
use SugarCraft\Core\Util\Width;
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

    /**
     * MAJOR-2 (docs-fix-1023) / CF-01: pane boxes derive from the live
     * terminal width captured via WindowSizeMsg. At 120 cols each pane box
     * is intdiv(118, 2) = 59 outer cells (content 55, separator 55) and the
     * two boxes plus the 2-cell gutter fill the frame exactly. At
     * termCols === 0 (no size seen yet — unit shape) the legacy 40-cell
     * box survives byte-identically, content 36 and all.
     */
    public function testPanesWidenWithTheTerminalAndDefaultToLegacyWidth(): void
    {
        $m = Manager::start('/', '/', $this->fakeFs());
        $legacy = Renderer::render($m);
        // Full-row equality (WELD-H cure for review M1): the legacy
        // separator must survive as ONE byte-exact frame row, not merely a
        // 36-dash substring. CF-01 re-derives the row: the separator now
        // FILLS the content width (36 dashes, no trailing fill), so each
        // pane box is exactly the historical 40 cells and the whole frame
        // is 40 + 2 + 40 = 82 (was 90 — the old row overflowed its own
        // 40-cell split by 4 cells per pane).
        $this->assertContains(
            sprintf('┃ %s ┃  │ %s │', str_repeat('─', 36), str_repeat('─', 36)),
            explode("\n", $legacy),
            'the legacy separator row must survive byte-exact'
        );
        $this->assertStringNotContainsString(str_repeat('─', 55), $legacy);

        [$m] = $m->update(new \SugarCraft\Core\Msg\WindowSizeMsg(120, 40));
        $wide = Renderer::render($m);
        $this->assertContains(
            sprintf('┃ %s ┃  │ %s │', str_repeat('─', 55), str_repeat('─', 55)),
            explode("\n", $wide),
            'resize must widen the pane separator to the live split'
        );
        $this->assertStringNotContainsString(str_repeat('─', 36) . '│', $wide, 'the legacy 36-cell rule must not survive a 120-col frame');
    }

    /**
     * CF-01 property guard: with a live terminal width, NO composed frame
     * line may exceed termCols display cells — in any UI state. The
     * pre-cure frame measured 90 cells on an 80-col terminal (every pane
     * rendered 4 cells past its split), and the unclamped status line
     * carries the full key-help regardless.
     */
    public function testNoFrameLineEverExceedsTerminalWidth(): void
    {
        $tree = [
            '/' => [
                new Entry(str_repeat('x', 200), false, 1234567, 0),
                new Entry('日本語のとても長いファイル名です.txt', false, 42, 0),
                new Entry('短', true, 0, 0),
                new Entry('a moderately long directory name that still has to fit', true, 0, 0),
            ],
            '/短' => [],
        ];
        $lister = static fn(string $p): array => $tree[$p] ?? [];
        foreach ([40, 80, 120, 200] as $cols) {
            [$m] = Manager::start('/', '/', $lister)->update(new \SugarCraft\Core\Msg\WindowSizeMsg($cols, 24));
            $states = ['default' => $m];
            // armed confirm (selection + d)
            [$arm] = $m->update(new KeyMsg(KeyType::Char, ' '));
            [$arm] = $arm->update(new KeyMsg(KeyType::Char, 'd'));
            $states['confirm-armed'] = $arm;
            // open search with results
            [$s] = $m->update(new KeyMsg(KeyType::Char, '/'));
            foreach (['x', 'y', 'z'] as $ch) {
                [$s] = $s->update(new KeyMsg(KeyType::Char, $ch));
            }
            $states['search-open'] = $s;
            foreach ($states as $label => $state) {
                foreach (explode("\n", Renderer::render($state)) as $line) {
                    $cells = Width::of(Ansi::strip($line));
                    $this->assertLessThanOrEqual(
                        $cols,
                        $cells,
                        "CF-01: {$label} at {$cols} cols has a {$cells}-cell line: " . substr(Ansi::strip($line), 0, 60)
                    );
                }
            }
        }
    }

    /** @return list<Entry> */
    private function manyEntries(int $n): array
    {
        $entries = [];
        for ($i = 1; $i <= $n; $i++) {
            $entries[] = new Entry(sprintf('file%02d.txt', $i), false, $i, 0);
        }
        return $entries;
    }

    /**
     * CF-02: with a captured height the frame must fit the viewport — the
     * entry window follows the cursor, fold-hint rows count the hidden
     * entries, and the status line stays the last row.
     */
    public function testViewportWindowsTheEntryListToTheCapturedRows(): void
    {
        $entries = $this->manyEntries(30);
        $lister = static fn(string $p): array => $p === '/' ? $entries : [];
        [$m] = Manager::start('/', '/', $lister)->update(new \SugarCraft\Core\Msg\WindowSizeMsg(80, 24));
        $lines = explode("\n", Renderer::render($m));
        $this->assertLessThanOrEqual(24, count($lines), 'the composed frame must fit the captured rows');
        // Cursor at the top: no above-hint, below-hint counts the hidden tail.
        $frame = implode("\n", $lines);
        $this->assertStringNotContainsString('more above', $frame);
        $this->assertStringContainsString('more below', $frame);
        $this->assertStringContainsString('file01.txt', $frame);
        $this->assertStringNotContainsString('file30.txt', $frame, 'off-window tail must not render');
        $this->assertStringContainsString('focus:', Ansi::strip($lines[count($lines) - 1]), 'status line stays the last row');

        // End: the window follows the cursor — head hidden, tail visible.
        [$m] = $m->update(new KeyMsg(KeyType::End));
        $frame = Renderer::render($m);
        $lines = explode("\n", $frame);
        $this->assertLessThanOrEqual(24, count($lines));
        // Assert on the ACTIVE (left, thick-border) column only: the right
        // pane's cursor never moved, so its own fold hints stay put.
        $leftCol = implode("\n", array_map(
            static fn(string $l): string => explode('│', $l)[0],
            $lines,
        ));
        $this->assertStringContainsString('more above', $leftCol);
        $this->assertStringNotContainsString('more below', $leftCol, 'the left pane sits at the end of its list');
        $this->assertStringContainsString('file30.txt', $leftCol);
        $this->assertStringNotContainsString('file01.txt', $leftCol);
        $this->assertMatchesRegularExpression('/▸\s+file30/', Ansi::strip($leftCol), 'the cursor row is inside the window');
    }

    /**
     * CF-02: the resize arm must not discard msg->rows. Pre-cure the dedup
     * compared cols only, so a vertical drag (rows change, cols stable)
     * returned the same Model and the Program never re-rendered.
     */
    public function testRowsOnlyResizeIsNotDedupedAway(): void
    {
        [$m] = Manager::start('/', '/', $this->fakeFs())->update(new \SugarCraft\Core\Msg\WindowSizeMsg(80, 24));
        $this->assertSame(24, $m->termRows);
        [$same] = $m->update(new \SugarCraft\Core\Msg\WindowSizeMsg(80, 24));
        $this->assertSame($m, $same, 'the identical size still dedupes');
        [$taller] = $m->update(new \SugarCraft\Core\Msg\WindowSizeMsg(80, 30));
        $this->assertNotSame($m, $taller, 'a rows-only resize must dirty the model');
        $this->assertSame(30, $taller->termRows);
    }

    /**
     * CF-02: bare PageUp/PageDown page the cursor by the viewport height
     * (the Ctrl pair keeps cycling tabs — pinned by the existing tab test).
     */
    public function testBarePageKeysPageTheCursor(): void
    {
        $entries = $this->manyEntries(30);
        $lister = static fn(string $p): array => $p === '/' ? $entries : [];
        [$m] = Manager::start('/', '/', $lister)->update(new \SugarCraft\Core\Msg\WindowSizeMsg(80, 24));
        [$m] = $m->update(new KeyMsg(KeyType::PageDown));
        $this->assertSame(18, $m->left->cursor, 'pageSize at 24 rows is 18');
        [$m] = $m->update(new KeyMsg(KeyType::PageDown));
        $this->assertSame(29, $m->left->cursor, 'the page clamps at the last entry');
        [$m] = $m->update(new KeyMsg(KeyType::PageUp));
        $this->assertSame(11, $m->left->cursor);
        [$m] = $m->update(new KeyMsg(KeyType::PageUp));
        $this->assertSame(0, $m->left->cursor);
    }

    /**
     * CF-02 belt: an open search block is not sized by the windowing (that
     * is the search family's step) — the frame floor must still hold it to
     * termRows with the status line on screen.
     */
    public function testFrameFloorHoldsEvenWithAnUnwindowedSearchBlock(): void
    {
        $entries = $this->manyEntries(30);
        $lister = static fn(string $p): array => $p === '/' ? $entries : [];
        [$m] = Manager::start('/', '/', $lister)->update(new \SugarCraft\Core\Msg\WindowSizeMsg(80, 24));
        [$m] = $m->update(new KeyMsg(KeyType::Char, '/'));
        foreach (str_split('file') as $ch) {
            [$m] = $m->update(new KeyMsg(KeyType::Char, $ch));
        }
        $lines = explode("\n", Renderer::render($m));
        $this->assertLessThanOrEqual(24, count($lines), 'frame floor drops head lines, never the status row');
        $this->assertStringContainsString('focus:', Ansi::strip($lines[count($lines) - 1]));
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

    /**
     * N-16: the active-pane cue was border weight only (thick vs normal)
     * — invisible once the frame is linearized or copied as text. Every
     * rendered status line now leads with a localized focus label, and
     * Tab (the swap key) flips it.
     */
    public function testStatusLineLeadsWithTheFocusCue(): void
    {
        $m = Manager::start('/', '/', $this->fakeFs());
        $this->assertStringContainsString('focus: left', Renderer::render($m));

        [$m] = $m->update(new KeyMsg(KeyType::Tab));
        $this->assertSame(1, $m->activeIdx, 'premise: Tab swapped the active pane');
        $this->assertStringContainsString('focus: right', Renderer::render($m));
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

    /**
     * CF-12: on an 80-column terminal the idle status must carry the
     * MINIMAL tier — the 269-cell full roster wrapped to 3-4 rows per
     * frame (defect 017). Terse (149 cells at en) cannot fit 80 either.
     */
    public function testKeyhelpMinimalSelectedAt80Cols(): void
    {
        [$m] = Manager::start('/', '/', $this->fakeFs())
            ->update(new \SugarCraft\Core\Msg\WindowSizeMsg(80, 24));
        $lines = explode("\n", Renderer::render($m));
        $status = Ansi::strip(end($lines));
        $this->assertLessThanOrEqual(80, Width::of($status));
        $this->assertStringContainsString(\SugarCraft\Files\Lang::t('keyhelp.minimal'), $status);
        $this->assertStringNotContainsString('Home/End', $status, 'full roster must not survive at 80');
        $this->assertStringNotContainsString('space select', $status, 'terse must not fit at 80');
    }

    /**
     * CF-12: mid widths take the terse tier (fits), still drop the
     * long-tail chord segments, and never need the clip belt.
     */
    public function testKeyhelpTerseSelectedAt200Cols(): void
    {
        [$m] = Manager::start('/', '/', $this->fakeFs())
            ->update(new \SugarCraft\Core\Msg\WindowSizeMsg(200, 24));
        $lines = explode("\n", Renderer::render($m));
        $status = Ansi::strip(end($lines));
        $this->assertStringContainsString('space select', $status, 'terse segment missing');
        $this->assertStringNotContainsString('Home/End', $status, 'full roster must not fit at 200');
    }

    /** CF-12: very wide terminals keep the complete roster. */
    public function testKeyhelpFullRosterAt400Cols(): void
    {
        [$m] = Manager::start('/', '/', $this->fakeFs())
            ->update(new \SugarCraft\Core\Msg\WindowSizeMsg(400, 24));
        $lines = explode("\n", Renderer::render($m));
        $status = Ansi::strip(end($lines));
        $this->assertStringContainsString('Home/End', $status);
        $this->assertStringContainsString('^tab cycle', $status);
    }

    /** CF-12: with no live width the historical full line is byte-preserved. */
    public function testKeyhelpWithoutLiveWidthKeepsFullRoster(): void
    {
        $out = Ansi::strip(Renderer::render(Manager::start('/', '/', $this->fakeFs())));
        $this->assertStringContainsString('Home/End', $out);
    }

    /**
     * CF-12 acceptance (017): an interpolated unbounded status — the refusal
     * message echoing a 255-byte name — stays a single clamped row.
     */
    public function testLongInterpolatedStatusStaysOneRow(): void
    {
        $fake = $this->fakeFs();
        $m = (new \SugarCraft\Files\Manager\ManagerBuilder())
            ->withLister($fake)
            ->withLeft(Pane::open('/', $fake))
            ->withRight(Pane::open('/', $fake))
            ->withStatus(\SugarCraft\Files\Lang::t('status.mkdir_failed', ['name' => str_repeat('z', 255)]))
            ->withTermCols(80)
            ->withTermRows(24)
            ->build();
        $lines = explode("\n", Renderer::render($m));
        $this->assertCount(1, array_filter($lines, static fn(string $l): bool => str_contains($l, 'zzzz')),
            'long status must occupy exactly one row');
        foreach ($lines as $line) {
            $this->assertLessThanOrEqual(80, Width::of(Ansi::strip($line)));
        }
    }
}
