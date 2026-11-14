<?php

declare(strict_types=1);

namespace SugarCraft\Readline\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\Util\Width;
use SugarCraft\Readline\History\InMemoryHistory;
use SugarCraft\Readline\Key;
use SugarCraft\Readline\TextPrompt;

/**
 * S-05 (findings 004, 025, 102): the auxiliary rows — completion hint,
 * fish-style autosuggest, incremental search echo — must obey the same
 * cell budget as the main row: pad in CELLS (not codepoints), anchor the
 * dim tail at the end of the visible window (not the caret column), and
 * clip into the remaining cells so an auxiliary row can never wrap the
 * repaint the windowing exists to protect. The window leg also exercises
 * fitToDisplay() with CJK/emoji buffers (the old pins were ASCII-only).
 */
final class TextPromptAuxRowsTest extends TestCase
{
    private function typed(string $label, string $value): TextPrompt
    {
        $p = TextPrompt::new($label);
        foreach (mb_str_split($value, 1, 'UTF-8') as $ch) {
            $p = $p->handleChar($ch);
        }
        return $p;
    }

    /** Assert every painted row of $view fits in $bound cells. */
    private function assertRowsFit(string $view, int $bound, string $why = ''): void
    {
        foreach (explode("\n", $view) as $i => $row) {
            $this->assertLessThanOrEqual(
                $bound,
                Width::of($row),
                "row {$i} exceeds the {$bound}-cell bound ({$why}): " . json_encode(Width::of($row))
            );
        }
    }

    /** Remove SGR codes so tests can assert on the plain painted text. */
    private function plain(string $s): string
    {
        return (string) preg_replace('/\x1b\[[0-9;]*m/', '', $s);
    }

    public function testHintPadIsCellAwareAndAnchoredAtWindowEnd(): void
    {
        // '名前: ' is 4 codepoints but 6 cells; '日本' is 2 codepoints but 4 cells.
        // Old pad = 4 + 2 = 6 (codepoints, caret column); correct pad = 6 + 4 = 10,
        // anchored at the END of the visible window (cursor already at end here).
        $p = $this->typed('名前: ', '日本')
            ->withCompletions(['日本語です']);
        $lines = explode("\n", $p->view());
        $this->assertCount(2, $lines, 'hint row must be present');
        preg_match('/^( *)/u', $this->plain($lines[1]), $m);
        $pad = strlen($m[1]);
        $this->assertSame(10, $pad, 'pad counts CELLS of label + visible window, not codepoints');
        $this->assertStringContainsString('語です', $lines[1], 'dim tail continues the buffer, anchored at window end');
    }

    public function testHintTailClippedIntoRemainingCells(): void
    {
        $p = $this->typed('> ', 'abcdef')
            ->withCompletions(['abcdefghijklmnopqrstuvwxyz'])
            ->withDisplayWidth(20);
        $lines = explode("\n", $p->view());
        $this->assertCount(2, $lines);
        $this->assertStringContainsString('…', $lines[1], 'the over-long suggestion tail is clipped');
        $this->assertStringNotContainsString('qrstuvwxyz', $this->plain($lines[1]), 'clipped tail must not reach the wire');
        $this->assertRowsFit($p->view(), 20, 'hint row');
    }

    public function testAutoSuggestRowClippedToBound(): void
    {
        $h = new InMemoryHistory();
        $h->push('git checkout --theirs origin/main/branch-name');
        $p = $this->typed('> ', 'git ch')
            ->withHistory($h)
            ->withAutoSuggest(true)
            ->withDisplayWidth(24);
        $this->assertRowsFit($p->view(), 24, 'autosuggest row');
        $lines = explode("\n", $p->view());
        $this->assertGreaterThanOrEqual(2, count($lines));
        $this->assertStringContainsString('…', end($lines), 'dim tail clips inside the bound');
    }

    public function testSearchRowNeverWrapsWithCjkEntryAndQuery(): void
    {
        $h = new InMemoryHistory();
        $h->push('検索クエリを含む日本語の非常に長い履歴エントリです');
        $p = TextPrompt::new('> ')->withHistory($h)->withDisplayWidth(40)
            ->handleKey(Key::CtrlR);
        foreach (mb_str_split('検索クエリ', 1, 'UTF-8') as $ch) {
            $p = $p->handleChar($ch);
        }
        $view = $p->view();
        $plain = $this->plain($view);
        $this->assertStringContainsString('(reverse-i-search)', $plain);
        $this->assertStringContainsString('…', $plain, 'the matched entry clips to the remaining cells');
        $this->assertRowsFit($view, 40, 'search row');
        $this->assertStringContainsString('検', $plain, 'query head is anchored, not clipped away');
    }

    public function testWindowLeftClipKeepsCaretVisibleForWideBuffers(): void
    {
        // -102 leg: 10 CJK chars (20 cells) in a 14-cell viewport, caret at
        // index 5 — the window must left-clip (leading '…', a 1-cell cost),
        // right-clip, and still contain the character under the caret.
        $p = $this->typed('> ', '一二三四五六七八九十');
        for ($i = 0; $i < 5; $i++) {
            $p = $p->handleKey(Key::Left); // caret now at index 5
        }
        $p = $p->withDisplayWidth(14);
        $main = explode("\n", $p->view())[0];
        $plain = $this->plain($main);
        $this->assertMatchesRegularExpression('/^> ….*…$/u', $plain, 'both-ends clip marks on a mid-caret CJK window');
        $this->assertLessThanOrEqual(14, Width::of($main));
        $this->assertStringContainsString('六', $plain, 'character at the caret stays in the window');
        $this->assertStringNotContainsString('一', $plain, 'left-clipped head must not reach the wire');
    }

    public function testEmojiWindowBudgetCountsTwoCellClusters(): void
    {
        // -102 leg (emoji): 5 × 2-cell clusters in a 10-cell bound with a
        // 1-cell label: budget = 10 - 1 - 3 = 6 cells = exactly 3 clusters,
        // leading marker only (right end reached with the caret at the end).
        $p = $this->typed('a', '🎉🎉🎉🎉🎉')->withDisplayWidth(10);
        $main = explode("\n", $p->view())[0];
        $this->assertStringStartsWith('a…', $this->plain($main), 'left clip applies, and … costs a single cell');
        $this->assertLessThanOrEqual(10, Width::of($main));
        $this->assertSame(3, substr_count($this->plain($main), '🎉'), 'per-cluster budget admits exactly 3 of the 5');
    }

    public function testEverySurfaceFitsTheBoundProperty(): void
    {
        $h = new InMemoryHistory();
        $h->push('日本語の非常に長い履歴エントリです');
        $h->push('a very long ascii history entry that goes on and on');
        $shapes = [
            ['名前: ', 'こんにちは世界テスト', '>'],
            ['> ', 'a very long ascii buffer that would certainly wrap a twelve cell window', 'x'],
            ['🎉 ', '🎉🎉🎉🎉🎉🎉🎉🎉🎉🎉', 'm'],
        ];
        foreach ($shapes as [$label, $buffer, $compFirst]) {
            $p = $this->typed($label, $buffer)
                ->withCompletions([$compFirst . str_repeat('zz', 30)])
                ->withHistory($h)
                ->withAutoSuggest(true)
                ->withDisplayWidth(12);
            $this->assertRowsFit($p->view(), 12, "buffer '{$buffer}'");
            $searching = $p->handleKey(Key::CtrlR);
            $this->assertRowsFit($searching->view(), 12, "search over '{$buffer}'");
        }
    }
}
