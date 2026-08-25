<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Renderer;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Util\Width;
use SugarCraft\Crush\Backend\EchoBackend;
use SugarCraft\Crush\Chat;
use SugarCraft\Crush\Tests\Support\HomeSandboxTrait;

/**
 * **E455 — the chat input box must wrap, not run off the right edge.**
 *
 * A user typing an ordinary long sentence watched the draft leave the
 * terminal. `Renderer::renderInput()` composed `"> "` + draft + block cursor
 * into ONE string and handed it to a bordered `Style` with no `->width()` and
 * no wrap call — and `Style::render()` does not word-wrap, while
 * `Style::width()` only SIZES a box. The input box was the one pane that
 * skipped the choke point every other producer goes through
 * ({@see \SugarCraft\Crush\Renderer::fitToPane()} for the transcript, a
 * hand-rolled `wordwrap()` for the permission prompt).
 *
 * ## Why these assertions and not "the output contains a newline"
 *
 * "Contains a newline" passes on a fix that wraps at the WRONG column — at 200
 * when the terminal is 60, at 12 when it is 60 — and the whole defect is a
 * column, not a line count. So every assertion here is width-driven: each row
 * of the rendered box is measured with `Width::of()` against `Chat::cols()`,
 * which is the same measure and the same authority
 * {@see PaneWidthInvariantTest} holds the transcript to. The row count is
 * asserted too, but only as the SECOND half — a fitter that truncated instead
 * of wrapping would satisfy the width bound alone.
 *
 * ## The three shapes, each reaching the wrap by a different route
 *
 * - **ASCII words** — the reported shape. Space-separated, so the wrap happens
 *   at a word boundary and nothing is hard-broken.
 * - **CJK** — double-width cells, where a byte-counting fitter (`wordwrap()`,
 *   which is exactly what this fix must NOT use) passes while a cell-counting
 *   one is required. `str_repeat('日本語', …)` has no space in it at all, so it
 *   also drives `Width::wrapAnsi()`'s hard-break path.
 * - **cursor mid-draft** — the block cursor is spliced into the draft BEFORE
 *   the wrap, so it is wrapped as the real cell it occupies. It is one cluster
 *   and no break may split it; that it appears exactly once is asserted in
 *   every case here rather than only in its own.
 *
 * ## The continuation indent is PINNED, not incidental
 *
 * The `"> "` prompt costs 2 cells on row 1, and every continuation row pays the
 * same 2-cell indent so the draft occupies one text column all the way down.
 * {@see testTheContinuationRowsAreIndentedUnderTheDraftNotUnderThePrompt()}
 * is what stops that being quietly changed to a left-flush wrap (which reads as
 * a second, separate line of input) or to a 4-cell hang.
 */
final class InputWrapTest extends TestCase
{
    use HomeSandboxTrait;

    private string $homeSandbox = '';

    protected function setUp(): void
    {
        // Constructing a Chat walks the skill trees under HOME; sandbox both
        // spellings so nothing here depends on what this machine has installed.
        $this->homeSandbox = $this->useHomeSandbox(
            sys_get_temp_dir() . '/crush_input_wrap_home_' . bin2hex(random_bytes(6)),
        );
    }

    protected function tearDown(): void
    {
        $this->restoreHomeSandbox();
        @rmdir($this->homeSandbox);

        parent::tearDown();
    }

    /**
     * The reported bug, at three widths so a fix that happens to be right at 80
     * and wrong everywhere else cannot pass.
     *
     * The word list is deliberately ordinary prose: the draft the user actually
     * typed had no long identifier in it, and a fixture built out of one would
     * be testing the hard-break path instead of the reported one.
     */
    public function testALongSpaceSeparatedDraftWrapsInsideTheTerminal(): void
    {
        $draft = implode(' ', array_fill(0, 40, 'wrap'));

        foreach ([60, 80, 120] as $cols) {
            $box = $this->inputBox($this->chat($draft, $cols));

            $this->assertGreaterThan(
                1,
                count($box),
                "at {$cols} columns a 200-character draft still painted a single row - the box did not wrap",
            );
            foreach ($box as $i => $row) {
                $this->assertLessThanOrEqual(
                    $cols,
                    Width::of($row),
                    "at {$cols} columns, input-box row {$i} measures " . Width::of($row) . ' cells',
                );
            }
            $this->assertSame(
                1,
                substr_count(implode('', $box), '█'),
                "at {$cols} columns the block cursor was lost or duplicated by the wrap",
            );
            // The upper bound alone cannot see a fix that wraps too NARROW, and
            // "too narrow" is the direction every arithmetic slip in this
            // budget travels: MEASURED, spelling the chrome as 6 instead of 4
            // paints this draft 5 cells short of the pane at 60 columns, and
            // wrapping at a hard-coded 12 paints it 45 short. Both used to
            // pass. The draft is 40 four-letter words, so a correct wrap always
            // has a row that reaches the budget exactly and the box is exactly
            // as wide as the terminal.
            $this->assertSame(
                $cols,
                self::widestRow($box),
                "at {$cols} columns the box came out " . self::widestRow($box)
                    . ' cells wide - a draft this long must fill the pane, not sit inside it',
            );
        }
    }

    /**
     * Double-width cells. `wordwrap()` — the wrong tool, and the one a reader
     * reaching for "just wrap it" picks first — counts BYTES, so it would break
     * this draft after roughly a third of the cells it should and the box would
     * come out about a third of the terminal wide. Measuring in cells is the
     * whole assertion.
     *
     * PHP 8.3.6 on this box; the cell widths come from candy-core's
     * `Width::of()`, not from the platform.
     */
    public function testACjkDraftWrapsByCellsAndNotByBytes(): void
    {
        $cols = 60;
        $box = $this->inputBox($this->chat(str_repeat('日本語', 40), $cols));

        $this->assertGreaterThan(1, count($box), 'a 240-cell CJK draft did not wrap');
        foreach ($box as $i => $row) {
            $this->assertLessThanOrEqual(
                $cols,
                Width::of($row),
                "CJK input-box row {$i} measures " . Width::of($row) . ' cells',
            );
        }
        // The byte-counting failure is a box far NARROWER than the pane, not a
        // wider one, so the width bound above cannot see it on its own: 240
        // cells of text is 720 bytes, and a byte wrap at 54 would paint 14 rows
        // of ~18 cells. Assert the box actually fills the pane it was given.
        //
        // The bound is $cols and not `$cols - 2`. WHAT IT SAID, with the
        // slack: that two cells of give were harmless. WHAT IS TRUE NOW: two
        // cells is exactly the magnitude of the arithmetic slips this budget
        // suffers, and MEASURED, `INPUT_CHROME_COLS` spelled 6 instead of 4
        // lands this draft on 58 - it PASSED the old bound with the box
        // painted two cells narrower than the terminal at every width. WHY A
        // LOWER BOUND STILL EARNS ITS PLACE: unchanged, and it is the half the
        // upper bound cannot do - a byte wrap fails NARROW, not wide.
        $this->assertSame(
            $cols,
            self::widestRow($box),
            'the CJK draft came out ' . self::widestRow($box) . ' cells wide against a ' . $cols
                . '-column pane - either the fitter is counting bytes, or the width budget is wrong',
        );
    }

    /**
     * The block cursor is a real cell that is spliced in before the wrap, so a
     * mid-draft cursor changes where every later row breaks. It must still be
     * exactly one glyph, on the row the arrow keys put it on, and no row may
     * grow because of it.
     */
    public function testAMidDraftCursorSurvivesTheWrapAsOneCell(): void
    {
        $cols = 60;
        $chat = $this->chat(implode(' ', array_fill(0, 40, 'wrap')), $cols);
        for ($i = 0; $i < 100; $i++) {
            [$chat] = $chat->update(new KeyMsg(KeyType::Left));
        }

        $box = $this->inputBox($chat);
        $joined = implode("\n", $box);

        $this->assertSame(1, substr_count($joined, '█'), 'the cursor was split, lost or duplicated');
        $this->assertGreaterThan(1, count($box), 'the draft did not wrap with the cursor mid-draft');
        foreach ($box as $i => $row) {
            $this->assertLessThanOrEqual($cols, Width::of($row), "row {$i} is wider than the terminal");
        }
        // Not on the last row: 100 Lefts from the end of a 200-character draft
        // lands the caret well inside the first half of it.
        $this->assertStringNotContainsString('█', (string) end($box), 'the cursor did not move with the draft');
    }

    /**
     * The indent contract. Row 1 carries the `"> "` prompt; every continuation
     * row carries two spaces, so the draft's first cell is in the same column
     * on every row of the box.
     */
    public function testTheContinuationRowsAreIndentedUnderTheDraftNotUnderThePrompt(): void
    {
        $box = $this->inputBox($this->chat(implode(' ', array_fill(0, 40, 'wrap')), 60));

        $inner = array_map(static fn (string $row): string => self::insideBorder($row), $box);
        // Rows 0 and count-1 of the STYLE output are the box's own border rows,
        // dropped by inputBox(); what is left is content.
        $this->assertStringStartsWith('> wrap', $inner[0], 'row 1 must carry the prompt');
        for ($i = 1, $n = count($inner); $i < $n; $i++) {
            $this->assertStringStartsWith(
                '  wrap',
                $inner[$i],
                "continuation row {$i} is not indented under the draft's text column",
            );
        }
    }

    /**
     * A draft short enough not to wrap must render byte-identically to the
     * pre-fix single-row form. This is the control: without it, every assertion
     * above is satisfied by a fitter that wraps everything at column 1.
     */
    public function testAShortDraftIsStillOneRowWithThePromptAndTheCursor(): void
    {
        $box = $this->inputBox($this->chat('abcd', 60));

        $this->assertCount(1, $box, 'a four-character draft must not wrap');
        $this->assertStringContainsString('> abcd█', $box[0]);
    }

    /**
     * The narrow-pane branch of the prompt indent, which nothing pinned and
     * whose failure mode is total erasure rather than an overflow.
     *
     * `renderInput()` drops the `"> "` prompt below the width at which the
     * indent would leave no text column at all. The boundary is `$inner >
     * INPUT_PROMPT_COLS`, and it is strict on purpose: MEASURED on PHP 8.3.6,
     * `Renderer::wrapToPane('hello world█', 0)` returns a single EMPTY row, so
     * spelling that comparison `>=` paints `"> "` and nothing else at 6
     * columns — the draft and the block cursor both vanish while the box, the
     * border and the frame all still look right.
     *
     * Three widths, one either side of the boundary and one on it: at 5 the
     * prompt is already gone, at 6 it is dropped by this branch, at 7 it is
     * affordable and painted.
     */
    public function testTheDraftSurvivesThePaneTooNarrowToAffordThePrompt(): void
    {
        foreach ([5 => '', 6 => '', 7 => '> '] as $cols => $expectedPrompt) {
            $box = $this->inputBox($this->chat('hello world', $cols));
            $inner = self::insideBorder($box[0]);

            $this->assertStringStartsWith(
                $expectedPrompt . 'h',
                $inner,
                "at {$cols} columns the draft's first cell is not painted - row 0 is "
                    . var_export($inner, true),
            );
            $this->assertSame(
                1,
                substr_count(implode('', $box), '█'),
                "at {$cols} columns the block cursor was lost or duplicated",
            );
        }
    }

    // =====================================================================
    // fixtures
    // =====================================================================

    private function chat(string $draft, int $cols): Chat
    {
        return (new Chat(inputBuf: $draft, backend: new EchoBackend()))->withSize($cols, 24);
    }

    /**
     * The CONTENT rows of the input box, border rows dropped.
     *
     * Found by predicate rather than by position: the frame's row count moves
     * with the transcript, the tab strip and the agent pane, so an index would
     * pin this file to an unrelated layout.
     *
     * WHAT THIS SAID: that the box is located by its `╭` corner.
     * WHAT IS TRUE NOW: it never was. The walk below matches `└` and then
     * `┌`, which is what {@see \SugarCraft\Sprinkles\Border::normal()} — the
     * border {@see \SugarCraft\Crush\Renderer::renderInput()} actually asks for
     * — paints; `╭` belongs to the ROUNDED border, which this box does not
     * use. Checked against `Border::normal()` rather than assumed.
     * WHY THE PARAGRAPH STILL EARNS ITS PLACE: the reason for locating the box
     * from the BOTTOM is the load-bearing half and is unchanged — the box is
     * the last border-cornered run in the frame before the status bar, and
     * anything below its closing border (status bar, slash menu, agent pane) is
     * skipped by the same walk.
     *
     * @return list<string>
     */
    private function inputBox(Chat $chat): array
    {
        $rows = array_map(static fn (string $r): string => self::plain($r), explode("\n", $chat->view()));

        // Walk up from the bottom to the input box's closing border, then on to
        // its opening one. Anything below the closing border (status bar, slash
        // menu, agent pane) is skipped by the same walk.
        $close = -1;
        for ($i = count($rows) - 1; $i >= 0; $i--) {
            if (str_starts_with($rows[$i], '└')) {
                $close = $i;
                break;
            }
        }
        self::assertGreaterThan(0, $close, 'no input-box closing border in the frame');

        $open = -1;
        for ($i = $close - 1; $i >= 0; $i--) {
            if (str_starts_with($rows[$i], '┌')) {
                $open = $i;
                break;
            }
        }
        self::assertGreaterThanOrEqual(0, $open, 'no input-box opening border in the frame');
        self::assertGreaterThan($open + 1, $close, 'the input box has no content rows at all');

        return array_values(array_slice($rows, $open + 1, $close - $open - 1));
    }

    /**
     * The widest row of the box, in cells — the measure both width bounds are
     * expressed against, so the two can never drift apart.
     *
     * @param list<string> $box
     */
    private static function widestRow(array $box): int
    {
        $widest = 0;
        foreach ($box as $row) {
            $widest = max($widest, Width::of($row));
        }

        return $widest;
    }

    /** The text of a box content row, its `│` walls and one pad cell removed. */
    private static function insideBorder(string $row): string
    {
        $inner = mb_substr($row, 1);
        $inner = rtrim($inner, '│');

        return ltrim($inner, ' ') === '' ? '' : mb_substr($inner, 1);
    }

    private static function plain(string $row): string
    {
        return (string) preg_replace('/\e\[[0-9;?]*[a-zA-Z]|\e\][^\a\e]*(\a|\e\\\\)/', '', $row);
    }
}
