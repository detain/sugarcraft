<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Tui;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\Util\Width;
use SugarCraft\Crush\App\App;
use SugarCraft\Crush\Chat;
use SugarCraft\Crush\Message;
use SugarCraft\Crush\Providers\ProviderInterface;
use SugarCraft\Crush\Renderer as LiveRenderer;
use SugarCraft\Crush\ToolResult;
use SugarCraft\Crush\Tui\Pane;
use SugarCraft\Crush\Tui\Renderer as TuiRenderer;
use SugarCraft\Constraints\Dock\Side;

/**
 * A frame row must never occupy more TERMINAL columns than the frame has.
 *
 * The reported bug: while a live "💭 Thinking…" block painted, the docked
 * right ToolsPane lost its closing border on the rows whose chat content
 * carried a default-emoji-presentation glyph (the ✅ in "1. ✅ the p-arm is
 * gated…"). The user's terminal wraps a row the moment its PHYSICAL width
 * exceeds cols, and candy-core's width table — the classic Markus-Kuhn
 * ranges — scores U+2705 (and its BMP emoji-presentation siblings) as 1 cell
 * where every modern terminal lays out 2. A row composed to exactly 200
 * LOGICAL cells was therefore 201 PHYSICAL cells: the wrap slid every
 * following frame row one column left, and the panes' right borders fell off
 * the screen exactly where the paste shows them missing.
 *
 * The frame is measured with an INDEPENDENT physical oracle — per-codepoint
 * `mb_strwidth` — because the sugar-vterm cell grid delegates to the very same
 * `Width::string()` table and could not falsify it (this repo has that
 * delegation pinned; see the L5 precedent in {@see PaneTest}).
 */
final class ThinkingFrameBorderAlignmentTest extends TestCase
{
    private const COLS = 200;
    private const ROWS = 60;

    /**
     * The glyph mix of the user's paste — the emoji-presentation members are
     * what the width table must learn to count as 2 cells.
     */
    private const PASTE_GLYPHS = ['✅', '✔', '✖', '💭', '·', '—', '“', '”', '▸', '◌', '`'];

    private ProviderInterface $provider;

    protected function setUp(): void
    {
        parent::setUp();

        putenv('SUGARCRUSH_DISABLE_MOUSE_CLICKS');
        TuiRenderer::setSize(self::COLS, self::ROWS);
        TuiRenderer::chromeScanner()->clear();
        LiveRenderer::scanner()->clear();
        LiveRenderer::setZoneOrigin(0, 0);
        App::resetPaneDragController();

        $this->provider = $this->createMock(ProviderInterface::class);
        $this->provider->method('name')->willReturn('TestProvider');
    }

    protected function tearDown(): void
    {
        TuiRenderer::chromeScanner()->clear();
        LiveRenderer::scanner()->clear();
        LiveRenderer::setZoneOrigin(0, 0);
        App::resetPaneDragController();
        TuiRenderer::setSize(200, 60);

        parent::tearDown();
    }

    /**
     * @testdox Every paste glyph counts the SAME cells under Width::of and mb_strwidth
     *
     * The double-oracle law from the L5 icon fix, run over the user's exact
     * character mix: the two tables may only disagree where the project has
     * adjudicated a departure, and this roster is the incident's evidence.
     */
    public function testPasteGlyphRosterAgreesAcrossBothWidthOracles(): void
    {
        $disagreements = [];
        foreach (self::PASTE_GLYPHS as $glyph) {
            $logical = Width::of($glyph);
            $physical = mb_strwidth($glyph, 'UTF-8');
            if ($logical !== $physical) {
                $disagreements[] = sprintf(
                    'U+%04X: Width::of() says %d cell(s), mb_strwidth() says %d',
                    mb_ord($glyph, 'UTF-8'),
                    $logical,
                    $physical,
                );
            }
        }

        $this->assertSame([], $disagreements, 'a content glyph is measured differently by the frame builder and by the terminal');
    }

    /**
     * @testdox No frame row with a live thinking block exceeds the terminal width PHYSICALLY
     *
     * Cast: a live thinking trace carrying the paste's glyph mix (long
     * unbroken filler forces wrapped rows that run the frame's full logical
     * width), plus finished tool calls so the docked right ToolsPane paints.
     * The invariant is the physical one: what the terminal lays out must fit,
     * row by row, or the borders after the over-wide cell are off by a column
     * — the ragged right edge of the report.
     */
    public function testLiveThinkingFrameNeverExceedsColumnsPhysically(): void
    {
        $frame = $this->renderCast();

        $this->assertStringContainsString('✅', $frame, 'the cast must reach the frame or this test proves nothing');

        $overruns = [];
        foreach (explode("\n", $frame) as $index => $row) {
            $printable = self::printable($row);
            $physical = self::physicalWidth($printable);
            if ($physical > self::COLS) {
                $overruns[] = sprintf(
                    'row %d: physical width %d > %d (logical %d)',
                    $index + 1,
                    $physical,
                    self::COLS,
                    Width::of($printable),
                );
            }
        }

        $this->assertSame([], $overruns, 'at least one frame row wraps in the terminal, shifting every border on and below it');
    }

    /**
     * @testdox The right-hand frame border sits on one constant physical column
     *
     * The user-visible shape of the same invariant: every row that closes
     * with frame chrome must land that chrome on the frame's last column.
     * Before the fix the over-wide rows wrapped in the terminal, so the
     * chrome the compositor had placed at column 200 began painting beyond
     * the edge — the paste's short-rows-keep-`│`-long-rows-lose-it pattern.
     */
    public function testRightFrameBorderKeepsOneConstantPhysicalColumn(): void
    {
        $frame = $this->renderCast();

        $chrome = ['│', '╮', '╯', '┐', '┘', '┤'];
        $columns = [];
        foreach (explode("\n", $frame) as $index => $row) {
            $printable = rtrim(self::printable($row));
            $cps = preg_split('//u', $printable, -1, PREG_SPLIT_NO_EMPTY) ?: [];
            if ($cps === [] || !in_array($cps[count($cps) - 1], $chrome, true)) {
                continue;
            }
            $columns['row ' . ($index + 1)] = self::physicalWidth($printable);
        }

        $this->assertGreaterThanOrEqual(3, count($columns), 'too few chrome-closing rows sampled to prove constancy');

        // The cast paints TWO vertical chrome edges — the chat/tools seam and
        // the frame's right edge — so the law is per-edge: the columns actually
        // observed must be a small constant set whose MAX is exactly the frame
        // edge. Before the fix the emoji-inflated rows pushed their closing
        // chrome past it (physically COLS + 1), which is the drift this pins.
        $distinct = array_values(array_unique($columns));
        sort($distinct);
        $this->assertSame(self::COLS, max($distinct), 'no border ever reaches the frame edge?');
        $this->assertSame([min($distinct), self::COLS], $distinct, 'closing chrome drifts across more than the cast\'s two pane edges');
    }

    // =====================================================================
    // Cast + measurement helpers
    // =====================================================================

    private function renderCast(): string
    {
        // One unbroken run per line: the live-thinking wrapper hard-splits it
        // at exactly the pane's logical width, so every wrapped row carries a
        // full 200-cell budget — and each ✅ embedded in the filler inflates
        // that row past the edge on terminals that render emoji at 2 cells.
        $thought = implode("\n", [
            'Review pass results for the modal row lockstep convention — the p-arm mirrors the a-arm shape '
                . 'exactly: ✅ gated on question null and persistScope non-null, so the modal shows the p row '
                . 'only when persistScope exists, matching the file convention with no changes needed. '
                . str_repeat('filler·token—chain', 12),
            '1. ✅ permissionPersistPatterns: no dead scope parameter, subjectless native guard, full-call match '
                . 'test — the “check” landed green on the first detached run, no reruns, no flake. '
                . str_repeat('reviewpass/evidence', 10),
            '2. ✔ the quoteValue substitute path keeps Infotext self-rows LEGACY-clean; ✖ would have flagged the '
                . 'orphan Variation gate had the roster drifted between the two settle arms. '
                . str_repeat('x', 40),
        ]);

        $chat = new Chat(
            history: [
                Message::user('run the review pass'),
                Message::assistant('Review pass results:')->withToolResults([
                    ToolResult::ok('Bash', 'ran', 'call_1')
                        ->withDescription('Verify modal row lockstep comment'),
                    ToolResult::error('Edit', 'refused by protect-files rule', 'call_2')
                        ->withDescription('file_path: "/home/sites/sugarcraft/sugar-crush/src/Tui/Components/ToolsPane.php"'),
                    ToolResult::ok('Bash', 'ran', 'call_3')
                        ->withDescription('✅ Start full suite detached from process group and tail the journal'),
                ]),
            ],
            reasoningText: $thought,
        );

        $app = App::new($this->provider, 'test-model')
            ->withChat($chat->withSize(self::COLS, self::ROWS))
            ->setPaneSide(Pane::Tools, Side::Right);

        return TuiRenderer::renderView($app, self::COLS, self::ROWS)->body;
    }

    /**
     * Strip SGR, OSC hyperlinks and the sugar-mouse zone marks.
     *
     * Zone marks are U+E000…U+E001 sentinel runs — metadata the house scan
     * strips before measuring (they cost ~27 cells of chrome each); charging
     * them to the physical width would accuse rows the terminal paints fine.
     */
    private static function printable(string $row): string
    {
        $plain = preg_replace('/\x1b\[[0-9;?]*[A-Za-z]/', '', $row) ?? $row;
        $plain = preg_replace('/\x1b\]8;[^\x07\x1b]*(\x07|\x1b\\\\)/', '', $plain) ?? $plain;

        // Group-wrapped: the '(' keeps this literal out of the GlobDialect
        // glob-shaped corpus (lane-pe precedent) — behaviour unchanged.
        return preg_replace('/\x{e000}(?:[^\x{e001}]*)\x{e001}/u', '', $plain) ?? $plain;
    }

    /**
     * Terminal-side width: the sum of per-codepoint `mb_strwidth`, i.e. what
     * the emulator advances, NOT what candy's own table claims.
     */
    private static function physicalWidth(string $row): int
    {
        $cells = 0;
        foreach (preg_split('//u', $row, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $cp) {
            $cells += mb_strwidth($cp, 'UTF-8');
        }

        return $cells;
    }
}
