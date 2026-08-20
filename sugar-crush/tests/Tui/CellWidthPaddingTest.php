<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Tui;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\Util\Width;
use SugarCraft\Crush\Theme;
use SugarCraft\Crush\Tui\AgentDisplayState;
use SugarCraft\Crush\Tui\AgentViewPane;
use SugarCraft\Crush\Tui\SplitLayout;

/**
 * Padding in a TUI must be measured in terminal CELLS, never in bytes.
 *
 * Both classes under test had already been made cell-aware on their
 * TRUNCATION path — {@see SplitLayout} grew `truncateToWidth()` and
 * {@see AgentViewPane} grew `truncate()`, each walking `preg_split('//u')`
 * and consulting a `charWidth()` table. Neither class's PADDING path was
 * converted with it, so both kept a byte-counting `str_pad()`/`strlen()`
 * next to a cell-counting truncator. The tell-tale `strlen()` was even
 * deleted from `SplitLayout` while the defect it named stayed, which is why
 * these are behavioural assertions on rendered geometry rather than greps
 * for a function name.
 *
 * Every assertion below is stated over CELL columns, computed with
 * {@see Width::string()} — the same ANSI-stripping, grapheme-aware measure
 * the terminal itself approximates. A byte-based measure would make several
 * of these pass vacuously.
 *
 * @see SplitLayout::render()
 * @see AgentViewPane::render()
 */
final class CellWidthPaddingTest extends TestCase
{
    /** Palette every rendered-geometry assertion below is stated against. */
    private static function theme(): Theme
    {
        return Theme::byName('dark');
    }

    /**
     * Cell column at which `$needle` starts inside `$row`, or -1 if absent.
     *
     * Measured by taking the byte prefix before the needle and asking
     * {@see Width::string()} how many cells it occupies, so the answer is in
     * the same unit the layout code is supposed to be reasoning in.
     */
    private static function cellColumnOf(string $row, string $needle): int
    {
        $bytePos = mb_strpos($row, $needle, 0, 'UTF-8');
        if ($bytePos === false) {
            return -1;
        }

        return Width::string(mb_substr($row, 0, $bytePos, 'UTF-8'));
    }

    // =========================================================================
    // SplitLayout — the divider column is the padding's observable
    // =========================================================================

    /**
     * The vertical divider must land in ONE cell column for every row.
     *
     * This is the direct observable of the padding bug: `str_pad()` counts
     * bytes, so a row whose left pane holds any multibyte character is padded
     * with too FEW spaces and the divider drifts LEFT. `éé` is the sharpest
     * case — two cells of text carried in four bytes, so the divider lands two
     * columns early — and it is the case a wide-character-only test would
     * miss entirely, because `é` is multibyte WITHOUT being wide.
     */
    public function testTheVerticalDividerLandsInOneCellColumnForEveryRowWhateverTheEncoding(): void
    {
        // Three left-hand rows of IDENTICAL cell width (2) but different byte
        // lengths (2, 4, 3). Any measure that agrees with the terminal must
        // put the divider in the same column for all three.
        $layout = SplitLayout::vertical("ab\n\u{e9}\u{e9}\n\u{4e2d}", "R1\nR2\nR3");
        $rows = explode("\n", $layout->render(21, 3));

        [$leftWidth] = $layout->paneSizes(21);

        $this->assertCount(3, $rows);
        foreach ($rows as $i => $row) {
            $this->assertSame(
                $leftWidth,
                self::cellColumnOf($row, "\u{2502}"),
                sprintf('row %d put the divider in the wrong cell column', $i),
            );
        }
    }

    /**
     * A wide (CJK) character is the second half of the same defect.
     *
     * Stated separately from the accented case on purpose: a fix that reached
     * for `mb_strlen()` instead of a cell-width table would repair the `é` row
     * and leave this one broken, since `\u{4e2d}` is ONE codepoint occupying
     * TWO cells. Both tests must hold for the fix to be the right one.
     */
    public function testAWideCharacterIsPaddedByItsCellWidthNotItsCodepointCount(): void
    {
        $layout = SplitLayout::vertical("\u{4e2d}\u{4e2d}\nabcd", 'R');
        $rows = explode("\n", $layout->render(21, 2));

        [$leftWidth] = $layout->paneSizes(21);

        // Row 0 is 2 codepoints / 6 bytes / 4 cells; row 1 is 4 of each.
        $this->assertSame($leftWidth, self::cellColumnOf($rows[0], "\u{2502}"));
        $this->assertSame($leftWidth, self::cellColumnOf($rows[1], "\u{2502}"));
    }

    /**
     * The fix may not buy alignment by overrunning the frame.
     *
     * This project treats an over-wide row as broken FUNCTIONALITY, not as
     * polish: the diff renderer paints exactly one line per terminal row, so a
     * row rendering wider than its budget corrupts the frame below it. The
     * padding bug under-pads, so this invariant held before the fix too — it
     * is here to stop the fix from trading one defect for a worse one, and it
     * is stated over the TOTAL width the caller asked for, not over either
     * pane's share.
     */
    public function testNoRenderedRowExceedsTheTotalCellBudgetItWasGiven(): void
    {
        foreach ([12, 21, 40, 81] as $total) {
            $layout = SplitLayout::vertical(
                "\u{4e2d}\u{4e2d}\u{4e2d}\u{4e2d}\n\u{e9}\u{e9}\u{e9}\u{e9}\u{e9}\nplain ascii",
                "\u{4e2d}\u{e9}right\nmore\nrows",
            );

            foreach (explode("\n", $layout->render($total, 3)) as $i => $row) {
                $this->assertLessThanOrEqual(
                    $total,
                    Width::string($row),
                    sprintf('row %d overran the %d-cell budget', $i, $total),
                );
            }
        }
    }

    // =========================================================================
    // AgentViewPane — the right-hand column is the padding's observable
    // =========================================================================

    /**
     * Two agent names of equal CELL width must produce identical geometry,
     * however many bytes those cells happen to take.
     *
     * `AgentViewPane` spends the row's width budget twice through a byte
     * measure: `strlen($name)` sizes the operation column and
     * `strlen($rightSection)` sizes the right-pad. A name of the same visible
     * width but more bytes therefore steals budget that is not there, and the
     * elapsed/usage column slides. Asserting the elapsed column's CELL
     * position pins the geometry a user actually sees, rather than pinning
     * that some particular helper was called.
     */
    public function testTheElapsedColumnLandsInOneCellColumnForNamesOfEqualCellWidth(): void
    {
        // "abc" 3 bytes, "\u{e9}bc" 4 bytes, "\u{4e2d}c" 4 bytes — all 3 cells wide.
        $columns = [];
        foreach (['abc', "\u{e9}bc", "\u{4e2d}c"] as $name) {
            $agent = AgentDisplayState::new($name, 'working', 'refactoring the parser', 42, 1234, 0.0042);
            $rows = explode("\n", AgentViewPane::render([$agent], 0, 80, 5, self::theme()));

            // Row 0 is the top border, row 1 the single agent, row 2 the bottom.
            $columns[$name] = self::cellColumnOf($rows[1], '42s');
        }

        $this->assertNotContains(-1, $columns, 'the elapsed readout vanished from a row');
        $this->assertCount(
            1,
            array_unique($columns),
            'names of equal cell width put the elapsed column in different places: '
                . json_encode($columns, JSON_UNESCAPED_UNICODE),
        );
    }

    /**
     * The right-hand readout must land AT the right edge, not merely land
     * consistently.
     *
     * This assertion exists because the one above is not enough on its own,
     * and a mutation proved it: restoring the byte-counting `str_pad()` while
     * leaving the rest of the fix in place kept all three encodings AGREEING
     * with each other and stayed green. The reason is worth recording — the
     * left section is styled, so its SGR bytes push `strlen()` past the pad
     * target and `str_pad()` returns the string untouched. The pad does not
     * mis-pad, it does not run at all, identically for every encoding. A test
     * that only compares encodings to each other cannot see that.
     *
     * So pin the geometry against the FRAME instead. The gap between the end
     * of the right section and the row's right edge is a fixed 3 cells at
     * every width — one trailing content column the pad target leaves (`- 1`),
     * plus one column of `padding(0, 1)` and one of border. With the pad
     * inert, that gap grows to whatever the row happens not to fill.
     */
    public function testTheRightHandReadoutIsFlushedToTheFrameEdgeNotLeftWhereverItFell(): void
    {
        foreach ([60, 80] as $width) {
            $agent = AgentDisplayState::new('abc', 'working', 'refactoring the parser', 42, 1234, 0.0042);
            $rightSection = $agent->elapsedDisplay() . '  ' . $agent->usageDisplay();

            $row = explode("\n", AgentViewPane::render([$agent], 0, $width, 5, self::theme()))[1];

            $gap = Width::string($row)
                - self::cellColumnOf($row, '42s')
                - Width::string($rightSection);

            $this->assertSame(
                3,
                $gap,
                sprintf('at width %d the right readout sat %d cells from the frame edge', $width, $gap),
            );
        }
    }

    /**
     * The same claim for the operation column, which is budgeted separately.
     *
     * `$opBudget` is computed from `strlen($name)`, so a byte-heavy name
     * shortens the operation text before the right-pad ever runs. Pinning the
     * rendered operation string catches that even if the right-pad were fixed
     * on its own — the two byte measures are independent defects in one method
     * and a fix to either alone leaves this red.
     */
    public function testTheOperationColumnIsBudgetedInCellsSoEqualWidthNamesTruncateIdentically(): void
    {
        $operations = [];
        foreach (['abc', "\u{e9}bc", "\u{4e2d}c"] as $name) {
            $agent = AgentDisplayState::new($name, 'working', 'refactoring the parser deeply', 42, 1234, 0.0042);
            // Width 80 on purpose. `$opBudget` is `max(5, $width - name - 60)`,
            // so at width 60 the clamp fires for EVERY name and all three
            // truncate to 5 cells — the assertion would pass while proving
            // nothing. At 80 the budgets are 17 / 16 / 16 and the clamp is out
            // of the picture, which is what makes this test able to fail.
            $rows = explode("\n", AgentViewPane::render([$agent], 0, 80, 5, self::theme()));

            // Everything between the status bracket and the elapsed readout.
            $plain = preg_replace('/\x1b\[[0-9;:]*[A-Za-z]/', '', $rows[1]) ?? '';
            $operations[$name] = trim(mb_substr(
                $plain,
                (int) mb_strpos($plain, ']') + 1,
                (int) mb_strpos($plain, '42s') - (int) mb_strpos($plain, ']') - 1,
            ));
        }

        $this->assertCount(
            1,
            array_unique($operations),
            'names of equal cell width truncated the operation differently: '
                . json_encode($operations, JSON_UNESCAPED_UNICODE),
        );
    }

    /**
     * Every rendered row is the SAME cell width, and that width does not move
     * when the content turns multibyte.
     *
     * State the domain precisely, because the obvious phrasing of this
     * assertion is false: `$width` is **not** the pane's outside width. It is
     * the width handed to `Style::width()`, which sizes the CONTENT box, and
     * the rounded border (2 cells) plus `padding(0, 1)` (2 cells) are drawn
     * outside it — so `render(..., $width, ...)` returns rows of `$width + 4`
     * cells, for ASCII and multibyte alike, empty list included.
     *
     * ONE of the two callers subtracts that overhead; the other does not, and
     * saying "both do" would be this project's most-repeated defect — a number
     * true of one thing written next to a different thing.
     * {@see \SugarCraft\Crush\Renderer::renderAgentView()} passes
     * `max(40, $cols - 4)`, which compensates exactly at 44 columns and above.
     * {@see \SugarCraft\Crush\Tui\Components\AgentDashboardPane::render()}
     * passes `max(20, $width - 2)`, which does not compensate at all: measured,
     * both of its paths — the empty-list `AgentViewPane::render()` call and the
     * `box()` frame around a populated list, which repeats the same
     * border-plus-padding geometry — return `$width + 2` cells for the
     * `$width` they were handed (pane width 30 -> 32, 60 -> 62, 100 -> 102).
     *
     * That over-run is real but not observable: every dashboard frame goes out
     * through `clipWidth()` at {@see \SugarCraft\Crush\Tui\Renderer} (the
     * `clipWidth(clipTail(...), $cols)` call that builds `$frame`), which
     * trims the two cells back off before the diff renderer ever sees them.
     * Recorded rather than fixed here on purpose — this file is about the
     * PADDING measure, and widening it into the dashboard's width arithmetic
     * would be a different change. See
     * docs/plans/crush_code_hardening_backlog.md.
     *
     * Pinning the exact `+4` rather than a `<=` bound is the point: a `<=`
     * assertion would keep passing if the pad started under-filling again,
     * which is the very defect this file exists for. Uniformity across rows is
     * the render invariant the diff renderer depends on — it paints one line
     * per terminal row, so a row that disagrees with its neighbours about
     * width corrupts the frame.
     */
    public function testEveryAgentRowIsTheSameCellWidthWhateverTheEncoding(): void
    {
        $agents = [
            AgentDisplayState::new("\u{4e2d}\u{4e2d}\u{4e2d}\u{4e2d}", 'working', "\u{4e2d}\u{4e2d} wide operation", 42, 1234, 0.0042),
            AgentDisplayState::new("\u{e9}\u{e9}\u{e9}\u{e9}\u{e9}", 'waiting', "accented \u{e9}peration", 7, 99, 0.1),
            AgentDisplayState::new('plain', 'failed', 'ascii operation', 7, 99, 0.1),
        ];

        foreach ([40, 60, 80] as $width) {
            $widths = array_map(
                static fn(string $row): int => Width::string($row),
                explode("\n", AgentViewPane::render($agents, 0, $width, 6, self::theme())),
            );

            $this->assertSame(
                array_fill(0, count($widths), $width + 4),
                $widths,
                sprintf('rows disagreed about their width at content width %d', $width),
            );
        }
    }
}
