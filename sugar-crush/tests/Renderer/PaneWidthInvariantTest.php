<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Renderer;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\ImageOverlay;
use SugarCraft\Core\Util\Width;
use SugarCraft\Mosaic\ImageLayer;
use SugarCraft\Mosaic\Mosaic;
use SugarCraft\Shine\Renderer as Markdown;
use SugarCraft\Crush\Chat;
use SugarCraft\Crush\Message;
use SugarCraft\Crush\Palette\PaletteState;
use SugarCraft\Crush\Renderer;
use SugarCraft\Crush\Theme;
use SugarCraft\Crush\ToolResult;
use SugarCraft\Crush\Tests\Support\HomeSandboxTrait;

/**
 * **No row the transcript emits may be wider than the terminal.**
 *
 * This is the assertion whose absence let a user-reported bug live in a suite
 * of 7,387 tests: nothing measured a rendered row's width against
 * `Chat::cols()`. `Renderer::renderView()` computed the pane width correctly
 * and handed it to `renderHistory()`, `renderToolResults()` and
 * `renderDiff()` — and then built the Markdown renderer with no width at all.
 * CandyShine's word wrap is opt-in and off by default, so a 200-column reply
 * came out as a 200-column ROW.
 *
 * Why that is corruption rather than an ugly line, in both directions:
 *
 * - STANDALONE (`Chat::view()`): the over-wide row is soft-wrapped by the
 *   TERMINAL. candy-core's `Renderer` repaints rows with an absolute
 *   `cursorTo($row, 1)` and has no concept of scrolling, so every row after
 *   the wrap slides down one physical line and later repaints land on stale
 *   coordinates — the "text ends up in the status bar" family.
 * - HOSTED (`bin/sugarcrush` → `App::view()` → `Tui\Renderer::renderView()` →
 *   `ChatPane::renderView()`): the over-wide row instead meets
 *   `Style::width()`, which in candy-sprinkles TRUNCATES rather than wraps, so
 *   the reply is silently cut off mid-sentence. That is the reported symptom,
 *   verbatim: "long response lines … are not wrapped but cut off … at the end
 *   its clearly got more to say but the next line is blank and the line
 *   unrelated". The blank line is the Markdown paragraph break and the
 *   "unrelated" line is the next paragraph.
 *
 * ## Three exemptions, all measured and all pre-existing
 *
 * 1. **The status bar** — `renderStatusBar()` fits it by choosing narrower
 *    forms or dropping segments and deliberately never truncates it, because it
 *    carries a `markPane()` sentinel PAIR and a cut between them makes
 *    `Scan::parse()` throw. Its narrowest possible form is 54 columns idle / 36
 *    in flight, so on a terminal narrower than that the bar overflows by design.
 *    Those widths are swept and pinned by {@see StatusBarSpendTest}; this file
 *    drops that row rather than re-litigating them — by PREDICATE
 *    ({@see isStatusBar()}) and not by position, because with an overlay open
 *    the frame's last line is not the bar. See {@see transcriptRows()} for the
 *    measurement.
 * 2. **{@see NARROW_FLOOR}** — `renderView()` floors the content width at
 *    `max(20, cols - 6)`, so a terminal under 26 columns gets a pane wider than
 *    itself. That floor predates this fix and is asserted as a BOUND below
 *    ({@see testEvenBelowTheContentWidthFloorTheFrameIsHeldToTheFloor}) rather
 *    than exempted, because "26" and "204" are very different failures.
 * 3. **Overlay rows** — `renderView()`'s choke point holds `$body`; a palette or
 *    permission prompt is composited over the finished frame by `Veil` at its
 *    own natural width, which is 54 columns and does not shrink. So an overlay
 *    overflows a terminal narrower than that, and this bundle does not widen
 *    itself to fix the overlay renderer. Pinned rather than exempted by
 *    {@see testAnOverlayRowIsNeitherMistakenForTheStatusBarNorSilentlyUnmeasured()}.
 *
 * Two more bounds worth stating plainly, because a reader is otherwise entitled
 * to read the headline as unconditional:
 *
 * - **The height rule has one exception.** `$available = max(1, rows - 1)` plus
 *   the status bar means a ONE-row terminal gets a TWO-row frame.
 *   {@see testWrappingDoesNotMakeTheFrameTallerThanTheTerminal()} sweeps 8/20/40
 *   and would fail at 1; the exception is pre-existing and pinned by
 *   {@see testAOneRowTerminalGetsATwoRowFrameWhichIsTheHeightRulesOneException()}.
 * - **The wide-table trade is not "strictly better".** Holding a 195-column
 *   table to a 100-column pane keeps every cell (nothing is deleted) and looks
 *   WORSE than the cut did: the header row loses its right `│`, the separator is
 *   cut mid-run, and the border rows wrap into several rows of dashes. Better on
 *   the axis that matters for a bug about lost text, visibly worse on the page.
 *
 * @see Renderer::fitToPane()
 * @see Renderer::wrapToPane()
 * @see Renderer::balanceSgr()
 * @see Renderer::renderStreamingTurn()
 */
final class PaneWidthInvariantTest extends TestCase
{
    use HomeSandboxTrait;

    /**
     * Widest frame `renderView()` can produce for a terminal narrower than it:
     * the `max(20, …)` content floor plus `SHELL_CHROME_COLS`.
     */
    private const NARROW_FLOOR = 26;

    private string $homeSandbox = '';

    protected function setUp(): void
    {
        // Constructing a Chat walks the skill trees under HOME; sandbox both
        // spellings so no assertion here depends on what this machine has
        // installed (see HomeSandboxTrait).
        $this->homeSandbox = $this->useHomeSandbox(
            sys_get_temp_dir() . '/pane_width_home_' . uniqid('', true),
        );
        // One test below renders with clicks off; cleared on both sides so a
        // leaked value cannot make any OTHER test here measure the wrong layout.
        putenv('SUGARCRUSH_DISABLE_MOUSE_CLICKS');
        Renderer::clearZones();
    }

    protected function tearDown(): void
    {
        putenv('SUGARCRUSH_DISABLE_MOUSE_CLICKS');
        Renderer::clearZones();
        $this->restoreHomeSandbox();
        @rmdir($this->homeSandbox);

        parent::tearDown();
    }

    // =====================================================================
    // The fixtures, and the proof that they reproduce
    // =====================================================================

    /**
     * One content class per entry, each chosen because it reaches the renderer
     * by a different route:
     *
     * - `prose` / `long word` go through CandyShine's paragraph wrap, which the
     *   width now switches on.
     * - `fenced code` / `wide table` are the ones CandyShine deliberately does
     *   NOT wrap ("they have their own width semantics"), so they are what
     *   proves threading the width in is not on its own the fix.
     * - `cjk` / `emoji` are double-width cells, where a byte- or
     *   character-counting fitter passes and a cell-counting one is required.
     *
     * @return array<string, string>
     */
    private static function contentClasses(): array
    {
        return [
            'prose' => 'This is a long paragraph of ordinary prose of the kind a coding agent emits '
                . 'constantly while explaining a change it just made, and it carries no hard break '
                . 'anywhere along its length.',
            'long word' => 'Identifier: ' . str_repeat('Aa', 90),
            'fenced code' => "Here is the patch:\n\n```php\n"
                . '$config = ' . str_repeat("['key' => 'value'], ", 9) . "[];\n"
                . "ok();\n```\n\nand a trailing sentence.",
            'wide table' => "| name | detail |\n|---|---|\n| widget | "
                . str_repeat('a fairly long descriptive cell ', 6) . "|",
            // ONE styled run wide enough to be split, which the `fenced code`
            // fixture above is not: its short tokens are individually reset, so
            // every wrap point there happens to land between two balanced runs.
            'one long styled run' => "```php\n\$sql = '"
                . str_repeat('SELECT alpha FROM widgets WHERE id = 1; ', 5) . "';\n```",
            // Double-width cells inside a fence, sized so a CHARACTER count reads
            // as fitting a 194-column pane (120 chars) while the CELLS do not
            // (240). CandyShine never wraps a fence, so this is the fixture that
            // proves the fitter measures cells rather than characters.
            'cjk in a fence' => "```\n" . str_repeat('日本語', 40) . "\n```",
            // A long list item, which is the case that distinguishes CandyShine's
            // block-aware wrap from a flat row fitter: the continuation has to
            // hang under the bullet's TEXT, not under the bullet.
            'long list item' => "- first bullet item that is deliberately long enough to need "
                . "wrapping at eighty columns and then some more words\n- short one",
            // The PROSE twin of 'one long styled run': a single emphasised clause
            // wide enough that CandyShine's own paragraph wrap splits it, which
            // is the case the fenced fixture cannot reach now that prose is
            // wrapped upstream of the fitter. See
            // testAWrappedBoldClauseInProseKeepsItsBoldOnEveryContinuationRow().
            'one long bold prose clause' => 'Note: **this whole emphasised clause is long enough '
                . 'that it has to be broken across two rows of a sixty column pane** and then it '
                . 'ends with a few more words of ordinary prose after it.',
            // A markdown link, which CandyShine renders as an OSC 8 hyperlink -
            // an escape class `SgrState` does not track at all. Only wrappable
            // now that the width is threaded in.
            'one long linked label' => 'See [this documentation link label which is long enough '
                . 'that it will need to wrap across two rows of the pane](https://example.com/x) '
                . 'for the details of what changed.',
            'cjk' => str_repeat('日本語のテキストです', 20),
            'emoji' => str_repeat('🎉🚀✨', 60),
            // The two emoji shapes plain 2-cell emoji do NOT cover, and the
            // reason 7,370 assertions of this file missed a real violation:
            // `Width::of()` and the `nextCluster()` scanner inside
            // `Width::wrapAnsi()`/`truncateAnsi()` are two different
            // accountings, and they only disagree on clusters made of MORE than
            // one codepoint. 🎉 is one codepoint and both read it as 2 cells.
            //
            //  - 👍🏽 is a base plus a skin-tone modifier: `Width::of()` reads 4
            //    cells (2 + 2, one per codepoint), the scanner reads 2.
            //  - 🇺🇸 is a regional-indicator PAIR: `Width::of()` reads 2, the
            //    scanner reads 1.
            //
            // Either way a wrap that reported success came back at twice the
            // budget — measured before the fix, 80 thumbs at cols=100 produced
            // rows of 194 cells. See Renderer::wrapToPane().
            'skin tone emoji' => str_repeat("\u{1F44D}\u{1F3FD}", 80),
            'flag emoji' => str_repeat("\u{1F1FA}\u{1F1F8}", 60),
        ];
    }

    /** @return iterable<string, array{0: int, 1: string}> */
    public static function widthAndContentProvider(): iterable
    {
        // 20 is below the content-width floor (see NARROW_FLOOR); 40/80/100 are
        // real terminals; 200 is wider than any fixture, which is the case a
        // fitter that wraps unconditionally would get wrong.
        foreach ([20, 40, 80, 100, 200] as $cols) {
            foreach (self::contentClasses() as $name => $content) {
                yield "{$name} at {$cols} cols" => [$cols, $content];
            }
        }
    }

    /**
     * Lesson from an earlier round: a reproduction fixture that fails to
     * reproduce makes the test that uses it pass against the broken code. Every
     * fixture above is therefore checked to produce an over-wide row when
     * rendered the way the defect rendered it — through CandyShine with NO wrap
     * width — before any of them is trusted as evidence.
     *
     * 100 columns, because that is the terminal the bug was reported and
     * measured on.
     *
     * @dataProvider contentClassProvider
     */
    public function testEveryFixtureReallyDoesOverflowWhenTheWidthIsNotThreadedIn(string $content): void
    {
        $unwrapped = (new Markdown())->render($content);

        self::assertGreaterThan(
            100,
            self::widestRow($unwrapped),
            'this fixture does not overflow a 100-column pane even unwrapped, so it proves nothing',
        );
    }

    /** @return iterable<string, array{0: string}> */
    public static function contentClassProvider(): iterable
    {
        foreach (self::contentClasses() as $name => $content) {
            yield $name => [$content];
        }
    }

    // =====================================================================
    // The invariant itself
    // =====================================================================

    /**
     * The whole point of this file. Every row of a settled frame, at every
     * width, for every content class.
     *
     * @dataProvider widthAndContentProvider
     */
    public function testNoRowOfASettledFrameIsWiderThanTheTerminal(int $cols, string $content): void
    {
        $chat = new Chat(
            history: [Message::user('explain'), Message::assistant($content)],
            rows: 40,
            cols: $cols,
        );

        self::assertRowsFit(Renderer::render($chat), $cols);
    }

    /**
     * The same assertion for a STREAMING frame, which is a separate code path:
     * `renderStreamingTurn()` builds its own Markdown renderer and used to
     * receive no width at all, so a reply was over-wide for the whole duration
     * of the turn and only "fixed itself" once the turn settled.
     *
     * @dataProvider widthAndContentProvider
     */
    public function testNoRowOfAStreamingFrameIsWiderThanTheTerminal(int $cols, string $content): void
    {
        $chat = new Chat(
            history: [Message::user('explain')],
            rows: 40,
            cols: $cols,
            inFlight: true,
            streamingText: $content,
        );

        self::assertRowsFit(Renderer::render($chat), $cols);
    }

    /**
     * `renderStreamingTurn()`'s docblock promises that "the moment the turn
     * lands, the text does not visibly re-flow into a different shape". With
     * the width threaded into only one of the two Markdown sites that promise
     * is false, and neither width assertion above catches it on its own — so
     * the two shapes are compared directly.
     */
    public function testAStreamingTurnAndTheSettledTurnWrapToTheSameShape(): void
    {
        $text = self::contentClasses()['prose'];

        $settled = new Chat(history: [Message::assistant($text)], rows: 40, cols: 100);
        $streaming = new Chat(history: [], rows: 40, cols: 100, inFlight: true, streamingText: $text);

        self::assertSame(
            self::proseRows(Renderer::render($settled), $text),
            self::proseRows(Renderer::render($streaming), $text),
            'a streaming reply must already be wrapped the way the settled one will be',
        );
    }

    /**
     * The floor is a bound, not a licence. Below 26 columns the frame cannot be
     * held to the terminal (see NARROW_FLOOR), but it must still be held to the
     * floor — the unfixed renderer produced 204-column rows here just as it did
     * at 100.
     *
     * @dataProvider contentClassProvider
     */
    public function testEvenBelowTheContentWidthFloorTheFrameIsHeldToTheFloor(string $content): void
    {
        $chat = new Chat(history: [Message::assistant($content)], rows: 40, cols: 20);

        self::assertLessThanOrEqual(
            self::NARROW_FLOOR,
            max(array_map([self::class, 'rowWidth'], self::transcriptRows(Renderer::render($chat)))),
            'a 20-column terminal must be held to the floor; unfixed this measured 180-406 columns',
        );
    }

    // =====================================================================
    // The user's report, pinned
    // =====================================================================

    /**
     * The reported case, in the reporter's own words, on the terminal width
     * they were measured at. Two things have to hold at once, and the fix is
     * only a fix if both do: the row fits, AND every word survives — the
     * hosted path's failure mode was silent truncation, so an invariant met by
     * deleting the tail would satisfy the width assertion and reproduce the
     * complaint.
     */
    public function testTheReportedReplyWrapsInsteadOfBeingCutOff(): void
    {
        $reported = 'long response lines in the response output are not wrapped but cut off, and '
            . 'at the end its clearly got more to say but the next line is blank and the line '
            . 'unrelated to what came before it.';

        $chat = new Chat(
            history: [Message::user('why is my output cut off?'), Message::assistant($reported)],
            rows: 40,
            cols: 100,
        );
        $frame = Renderer::render($chat);

        self::assertRowsFit($frame, 100);

        $painted = self::plain($frame);
        foreach (explode(' ', $reported) as $word) {
            self::assertStringContainsString(
                rtrim($word, ',.'),
                $painted,
                'the reply was fitted by deleting content, which is the bug being fixed',
            );
        }
    }

    // =====================================================================
    // The four things that break when a renderer starts cutting rows
    // =====================================================================

    /**
     * Wrapping splits a styled run across rows, and {@see Width::wrapAnsi()}
     * documents that it does NOT emit a reset at the break ("a colour set on
     * line N stays active on line N+1"). Written top to bottom that reads fine;
     * inside this renderer it does not, for two reasons — and the SECOND is the
     * observable one, which is worth stating because the first is what an
     * `isDefault()` check would look for and it never fires:
     *
     * - candy-core repaints only the rows that CHANGED, so a continuation row
     *   repainted alone inherits whatever styling was last on the wire.
     * - measured, the shell's own border closes every row (`Style::render()`
     *   emits the border glyph plus a reset), so an unbalanced row does not
     *   BLEED — it silently drops the styling from every continuation row. The
     *   green string literal of a wrapped code line renders green on its first
     *   row and plain on the rest.
     *
     * The sequence is derived from the first row rather than written down, so
     * this does not pin a theme colour.
     *
     * What this does NOT assert, measured rather than assumed: the trailing
     * `Ansi::reset()` `balanceSgr()` puts on each row. Dropping it changes
     * nothing here — the border closes every row, which is the second bullet
     * above — so it is pinned one level down instead, by
     * {@see testFitToPaneClosesTheStyleEveryRowItEmitsLeavesOpen()}.
     */
    public function testAWrappedStyledRunKeepsItsStylingOnEveryContinuationRow(): void
    {
        $chat = new Chat(
            history: [Message::assistant(self::contentClasses()['one long styled run'])],
            rows: 40,
            cols: 80,
        );

        $rows = array_values(array_filter(
            explode("\n", Renderer::render($chat)),
            static fn(string $row): bool => str_contains(self::plain($row), 'SELECT alpha'),
        ));
        self::assertGreaterThan(1, count($rows), 'the fixture did not wrap, so it proves nothing');

        // The SGR sequence sitting immediately before the literal's opening
        // quote on its first row is the styling every later row of the same
        // literal has to re-establish. Derived, so no theme colour is pinned.
        self::assertSame(
            1,
            preg_match("/(\x1b\[[0-9;]*m)'SELECT/", $rows[0], $m),
            'no SGR sequence precedes the literal on its first row',
        );

        foreach (array_slice($rows, 1) as $index => $row) {
            self::assertStringContainsString(
                $m[1],
                $row,
                'continuation row ' . ($index + 1) . ' lost the styling of the run it continues',
            );
        }
    }

    /**
     * The half of the fix that {@see Renderer::fitToPane()} does NOT subsume,
     * and the reason threading the width into CandyShine is not merely belt and
     * braces: CandyShine wraps a list item with a HANGING INDENT, aligning the
     * continuation under the item's text. A flat row fitter cannot — it has no
     * idea the row is a list item — so with the width dropped from either
     * Markdown site the continuation slides back two columns.
     *
     * Asserted for the streaming turn as well, because that site has its own
     * Markdown instance and its own parameter; a fix applied to one of them
     * leaves the other rendering a different shape, which is the exact re-flow
     * `renderStreamingTurn()`'s docblock promises does not happen.
     */
    public function testALongListItemHangsUnderItsTextInBothASettledAndAStreamingTurn(): void
    {
        $item = self::contentClasses()['long list item'];

        foreach ([
            'settled' => new Chat(history: [Message::assistant($item)], rows: 40, cols: 80),
            'streaming' => new Chat(history: [], rows: 40, cols: 80, inFlight: true, streamingText: $item),
        ] as $state => $chat) {
            $plain = explode("\n", self::plain(Renderer::render($chat)));

            $bulletRow = self::rowContaining($plain, 'first bullet item');
            $continuation = self::rowContaining($plain, 'eighty columns');
            self::assertNotSame($bulletRow, $continuation, "the {$state} item did not wrap");

            self::assertSame(
                mb_strpos($plain[$bulletRow], 'first'),
                mb_strpos($plain[$continuation], 'eighty'),
                "the {$state} turn's list continuation is not aligned under the item's text",
            );
        }
    }

    /**
     * A tool row is the one row in the transcript that is TRUNCATED rather than
     * wrapped, and the reason is not aesthetic: its whole row is a single-line
     * click zone that {@see Renderer::markToolCalls()} locates by
     * `str_contains()` on the label. Wrapping splits the label across two rows
     * and the zone is silently never marked; leaving the model-chosen NAME
     * unbounded pushes the label past the pane so the truncated row no longer
     * contains it either. Both failures are invisible on screen — the row looks
     * fine and simply stops responding to clicks — so they need a test.
     */
    public function testAToolRowTooWideForThePaneIsTruncatedAndStaysClickable(): void
    {
        $chat = new Chat(
            history: [Message::assistant('')->withToolResults([new ToolResult(
                name: str_repeat('AbsurdlyLongToolName', 9),
                result: 'done',
                id: 'call_wide',
            )])],
            rows: 40,
            cols: 100,
        );

        self::assertRowsFit(Renderer::render($chat), 100);

        $zone = Renderer::scanner()->get(Renderer::TOOL_CALL_ZONE_PREFIX . 'call_wide');
        self::assertNotNull($zone, 'the tool row is no longer clickable');
        // Single-row, like every zone this renderer marks: a multi-row zone can
        // lose one sentinel to the height clip and desync the whole scan.
        self::assertSame($zone->startRow, $zone->endRow);
    }

    /**
     * A cut between a zone sentinel PAIR leaves an unmatched open marker, which
     * makes `Scan::parse()` throw — and `scanRoot()` answers a throw by
     * clearing the registry, so the WHOLE frame loses its click zones. Driven
     * with all three producers on screen at once (a tool row, an open palette,
     * and an image marker that is byte-identical to `Sentinel::OPEN`) against
     * content wide enough that the fitter has to act.
     */
    public function testTheZoneScanSurvivesAFrameCarryingToolPaletteAndImageZones(): void
    {
        if (!\extension_loaded('gd')) {
            self::markTestSkipped('candy-mosaic decodes images through ext-gd');
        }

        $chat = new Chat(
            history: [
                Message::assistant(self::contentClasses()['fenced code']),
                Message::assistant('')->withToolResults([new ToolResult(
                    name: 'Doctor',
                    result: str_repeat('a very wide line of tool output ', 8),
                    id: 'call_img',
                    imageBytes: self::pngBytes(),
                )]),
            ],
            rows: 40,
            cols: 100,
            expanded: ['call_img' => true],
            palette: PaletteState::root(),
            mosaic: Mosaic::sixel(),
        );

        $frame = Renderer::render($chat);

        self::assertRowsFit($frame, 100);
        // NOT `assertStringNotContainsString(Sentinel::OPEN, …)`: an image
        // marker cell is `ImageOverlay::MARKER_BASE + id` and MARKER_BASE is
        // U+E000, so the first picture in a frame emits a byte-identical copy
        // of the opening sentinel — and that copy MUST survive to the terminal
        // for `Program::renderFrame()` to resolve into a paint. What may not
        // survive is well-formed zone MARKUP, which `scanRoot()` strips.
        self::assertSame(
            0,
            preg_match('/\x{E000}\/?[A-Za-z0-9._:-]*\x{E001}/u', $frame),
            'a zone sentinel pair leaked to the terminal',
        );
        self::assertNotSame(
            [],
            Renderer::scanner()->all(),
            'the scan threw and the frame lost every click zone',
        );
        self::assertNotNull(
            Renderer::scanner()->get(Renderer::TOOL_CALL_ZONE_PREFIX . 'call_img'),
            'the tool row is no longer clickable, so the fitter split its label',
        );
    }

    /**
     * Wrapping makes the transcript TALLER — the same prose that was one row is
     * now three — so the height clip is load-bearing in a way it was not
     * before. An earlier fix established that the frame must CLIP to the
     * terminal height rather than merely pad to it, because candy-core's
     * absolute `cursorTo()` is clamped by the terminal and distinct rows then
     * collide on the last physical line.
     *
     * Swept at 8/20/40 and NOT at 1: `$available = max(1, rows - 1)` plus the
     * status bar makes a one-row terminal a two-row frame, which predates this
     * bundle and is pinned as the exception it is by
     * {@see testAOneRowTerminalGetsATwoRowFrameWhichIsTheHeightRulesOneException()}
     * rather than quietly excluded by where this sweep happens to start.
     *
     * @dataProvider contentClassProvider
     */
    public function testWrappingDoesNotMakeTheFrameTallerThanTheTerminal(string $content): void
    {
        foreach ([8, 20, 40] as $rows) {
            $chat = new Chat(
                history: [Message::user('explain'), Message::assistant($content)],
                rows: $rows,
                cols: 60,
            );

            self::assertCount(
                $rows,
                explode("\n", Renderer::render($chat)),
                "the frame is not exactly {$rows} rows tall",
            );
        }
    }

    /**
     * The other half of the height interaction: the transcript now scrolls
     * through rows the fitter created, so `scrollOffset()` has to be a distance
     * in RENDERED rows. A frame that scrolled by logical lines while painting
     * physical rows would be the same bug wearing different clothes.
     */
    public function testScrollingAWrappedTranscriptMovesByExactlyThatManyRenderedRows(): void
    {
        $history = [];
        for ($turn = 0; $turn < 8; $turn++) {
            $history[] = Message::user("question {$turn}");
            $history[] = Message::assistant("Answer {$turn}: " . str_repeat('padding words here ', 14));
        }
        $chat = new Chat(history: $history, rows: 20, cols: 80);

        $pinned = explode("\n", Renderer::render($chat));
        self::assertGreaterThan(0, Renderer::maxScrollOffset(), 'the fixture does not overflow one screen');

        foreach ([1, 3] as $offset) {
            $scrolled = explode("\n", Renderer::render($chat->withScrollOffset($offset)));

            self::assertCount(count($pinned), $scrolled, 'scrolling changed the frame height');
            self::assertSame(
                array_slice($pinned, 0, 6),
                array_slice($scrolled, $offset, 6),
                "an offset of {$offset} did not move the window by {$offset} rendered rows",
            );
        }

        // Both ENDS of the range, because the ceiling itself is what
        // `Chat::update()` clamps the wheel against: a ceiling three rows short
        // makes the three oldest rendered rows permanently unreachable, and a
        // suite that only asserts `maxScrollOffset() > 0` and offsets 1 and 3
        // cannot tell. Measured: with this fixture the ceiling is 51, the first
        // turn appears only at 51 and the last only at 0.
        $ceiling = Renderer::maxScrollOffset();
        self::assertStringContainsString(
            'question 0',
            self::plain(Renderer::render($chat->withScrollOffset($ceiling))),
            'the oldest turn is not reachable at maxScrollOffset(), so part of the transcript is lost',
        );
        self::assertStringContainsString(
            'Answer 7',
            self::plain(Renderer::render($chat->withScrollOffset(0))),
            'the newest turn is not visible at offset 0',
        );
    }

    /**
     * The narrow end of the same rule, and the case that makes
     * {@see Renderer::fitToPane()}'s tool-row branch reachable at all: below
     * about 26 columns the status word alone ("⊘ interrupted" is 13 cells)
     * leaves the bounded tool name no room, so the row overflows the pane even
     * with a one-character name and the fitter has to act on it.
     *
     * It must TRUNCATE there, not wrap, and the reason is the one-row-per-call
     * convention rather than the zone: a tool row is an AFFORDANCE, not content,
     * and all a cut costs it is the tail of a status word the strikethrough
     * already conveys. Wrapping it instead spends a second physical row on
     * repeating that word, and `markToolCalls()` marks only the row carrying the
     * label — so the second row is a lookalike that does not respond to clicks.
     */
    public function testAnInterruptedToolRowStaysClickableAtTheNarrowestTerminal(): void
    {
        $chat = new Chat(
            history: [Message::assistant('')->withToolResults([new ToolResult(
                name: 'BashCommandRunner',
                result: '',
                id: 'call_stopped',
                error: Chat::INTERRUPTED_TOOL_CALL,
            )])],
            rows: 20,
            cols: 20,
        );

        $frame = Renderer::render($chat);

        self::assertLessThanOrEqual(
            self::NARROW_FLOOR,
            max(array_map([self::class, 'rowWidth'], self::transcriptRows($frame))),
        );

        $zone = Renderer::scanner()->get(Renderer::TOOL_CALL_ZONE_PREFIX . 'call_stopped');
        self::assertNotNull($zone, 'the interrupted tool row is no longer clickable');
        self::assertSame($zone->startRow, $zone->endRow);

        // One row per call: the line straight after the tool row is already the
        // result body. Wrapped instead of cut, that line is the tail of the
        // status word ("interrupted") on a lookalike row no click reaches.
        // Scanner rows are 1-based; the frame's lines are not.
        $plain = explode("\n", self::plain($frame));
        self::assertStringContainsString(
            'Tool call',
            $plain[$zone->startRow],
            'the tool row spilled onto a second, unclickable line instead of being cut',
        );
    }

    /**
     * The same rule for PROSE, and this is the case the fenced-code test above
     * does NOT reach — which matters because the width thread-through made
     * prose the COMMON case: CandyShine now does the wrapping itself, so a
     * wrapped paragraph arrives at {@see Renderer::fitToPane()} already fitting
     * and takes its fast path. Measured before the fix, at cols=60:
     *
     *     row 3 …  Note: <ESC>[1mthis whole emphasised clause is long enough that  │
     *     row 4 …  it has to be broken across two rows of a sixty column          │
     *
     * `ESC[1m` opened on row 3 and closed nowhere: the right border glyph
     * inherited the bold and row 4 — the continuation of the SAME bold clause —
     * rendered plain. That is a regression the width fix itself introduced, and
     * it is why {@see Renderer::balanceSgr()} runs over the whole block rather
     * than only over the rows the fitter wrapped.
     */
    public function testAWrappedBoldClauseInProseKeepsItsBoldOnEveryContinuationRow(): void
    {
        $chat = new Chat(
            history: [Message::assistant(self::contentClasses()['one long bold prose clause'])],
            rows: 40,
            cols: 60,
        );

        $rows = array_values(array_filter(
            explode("\n", Renderer::render($chat)),
            static fn(string $row): bool => str_contains(self::plain($row), 'emphasised clause')
                || str_contains(self::plain($row), 'broken across'),
        ));
        self::assertGreaterThan(1, count($rows), 'the clause did not wrap, so it proves nothing');

        self::assertStringContainsString(
            "\x1b[1m",
            $rows[0],
            'the fixture is not bold on its first row, so it proves nothing',
        );
        foreach (array_slice($rows, 1) as $index => $row) {
            self::assertMatchesRegularExpression(
                '/\x1b\[[0-9;]*1m/',
                $row,
                'continuation row ' . ($index + 1) . ' of the bold clause renders plain',
            );
        }
    }

    /**
     * A markdown link is an OSC 8 hyperlink, and `SgrState` does not track OSC
     * at all — it looks only at `Token::CSI` with `final === 'm'`, and
     * `Ansi::reset()` does NOT close a link. Newly reachable for the same
     * reason as the bold clause above: before the width was threaded in, the
     * label never wrapped. Measured at cols=60:
     *
     *     row 3 opens=1 closes=0 |… See <ESC>]8;;https://example.com/x<ST><ESC>[4m…|
     *     row 4 opens=-1 closes=1 |…wrap across two rows of the pane<ESC>[0m<ESC>]8;;<ST>|
     *
     * candy-core repaints only the rows that CHANGED, so row 3 painted alone
     * opens a link and never closes it and every later cell on the screen joins
     * the link — in iTerm2, WezTerm, VTE and Kitty alike.
     *
     * Also pinned: SGR 58 (underline colour) is likewise untracked by
     * `SgrState`. Nothing in this renderer or in CandyShine emits it, so it is
     * named in {@see Renderer::balanceSgr()} and deliberately not chased.
     */
    public function testEveryRowOfAWrappedHyperlinkOpensAndClosesItsOwnLink(): void
    {
        $chat = new Chat(
            history: [Message::assistant(self::contentClasses()['one long linked label'])],
            rows: 40,
            cols: 60,
        );

        $rows = array_values(array_filter(
            explode("\n", Renderer::render($chat)),
            static fn(string $row): bool => str_contains($row, "\x1b]8;;"),
        ));
        self::assertGreaterThan(1, count($rows), 'the link label did not wrap, so it proves nothing');

        foreach ($rows as $index => $row) {
            $opens = preg_match_all('/\x1b\]8;;[^\x1b\x07]+(?:\x1b\\\\|\x07)/', $row);
            $closes = preg_match_all('/\x1b\]8;;(?:\x1b\\\\|\x07)/', $row);
            self::assertSame(
                $opens,
                $closes,
                "row {$index} leaves an OSC 8 hyperlink open, so every later cell joins the link",
            );
        }
    }

    /**
     * The image markers, at the widths where they sit exactly ON
     * {@see Renderer::fitToPane()}'s `<=` boundary rather than comfortably
     * inside it.
     *
     * `renderToolImage()` sizes the reserved box at
     * `max(8, min(IMAGE_COLS = 40, $contentWidth))`, so on any terminal of 46
     * columns or fewer that is `$contentWidth` ITSELF — the marker row measures
     * exactly the pane width. One `<` where the code has `<=`, or a lost fast
     * path, and the row takes the wrap branch, where `Width::wrapAnsi()`'s
     * `rtrim()` deletes the reserved padding outright:
     *
     *     PRISTINE cols=46  bytes=92  width=46
     *     `<` instead of `<=`  bytes=72  width=26
     *
     * Nothing measured this before, because the only other test putting a
     * marker on screen runs at cols=100 — where `min(40, 94) = 40 < 94` and the
     * boundary is never reached.
     *
     * The palette is deliberately NOT open here, unlike in that test: an
     * overlay at these widths is over-wide for reasons of its own (see
     * {@see testAnOverlayRowIsNeitherMistakenForTheStatusBarNorSilentlyUnmeasured()})
     * and would drown out what this asserts.
     */
    public function testAnImageMarkerRowSurvivesTheFitterAtTheBoundaryWidths(): void
    {
        if (!\extension_loaded('gd')) {
            self::markTestSkipped('candy-mosaic decodes images through ext-gd');
        }

        // cols=8 is not a real terminal, it is the case that makes the CONTENT
        // FLOOR's value load-bearing: the box is `max(8, min(40, $contentWidth))`,
        // so a floor below 8 reserves MORE cells than the pane has and the
        // fitter then rewrites them. Measured with the floor dropped to 1, the
        // marker block was gone at cols=8, 12 and 20 alike.
        foreach ([8, 20, 26, 34, 46] as $cols) {
            $chat = new Chat(
                history: [Message::assistant('')->withToolResults([new ToolResult(
                    name: 'Doctor',
                    result: str_repeat('a very wide line of tool output ', 8),
                    id: 'call_img',
                    imageBytes: self::pngBytes(),
                )])],
                rows: 40,
                cols: $cols,
                expanded: ['call_img' => true],
                mosaic: Mosaic::sixel(),
            );

            $frame = Renderer::render($chat);
            $marker = null;
            foreach (explode("\n", $frame) as $row) {
                if (preg_match('/[\x{E000}-\x{F8FF}]/u', $row) === 1) {
                    $marker = $row;
                    break;
                }
            }
            self::assertNotNull($marker, "no image marker row at cols={$cols}");

            // Byte-identical to what ImageOverlay itself would emit for this
            // box: the marker cell plus every one of the reserved padding cells
            // the runtime paints the picture over.
            $box = max(8, min(40, max(20, $cols - 6)));
            self::assertStringContainsString(
                ImageOverlay::markerBlock(0, $box, 1),
                $marker,
                "the fitter rewrote the reserved image cells at cols={$cols}",
            );
            self::assertSame(
                max($cols, self::NARROW_FLOOR),
                self::rowWidth($marker),
                "the marker row lost cells at cols={$cols}",
            );

            // The reserved footprint the RUNTIME will paint over, read off the
            // View rather than recomputed here: it must fit the pane, because a
            // box wider than the pane is one the fitter is entitled to rewrite.
            $placements = Renderer::renderView($chat)->images;
            self::assertCount(1, $placements, "no image was registered at cols={$cols}");
            self::assertLessThanOrEqual(
                max(20, $cols - 6),
                reset($placements)->widthCells,
                "the reserved image box is wider than the pane at cols={$cols}",
            );
        }

        // And the boundary itself, at the level the boundary lives at: a row
        // measuring EXACTLY the pane width comes back byte-identical. In a
        // finished frame the shell's own padding hides a violation here — it
        // re-pads the rtrimmed row to the box width and the bytes come back —
        // so the fast path is pinned where it is decided.
        $fit = new \ReflectionMethod(Renderer::class, 'fitToPane');
        foreach ([20, 28, 40] as $paneWidth) {
            $row = ImageOverlay::markerBlock(0, $paneWidth, 1);
            self::assertSame($paneWidth, Width::of($row), 'the fixture is not on the boundary');
            self::assertSame(
                $row,
                $fit->invoke(null, $row, $paneWidth),
                "a row exactly {$paneWidth} cells wide was rewritten instead of passed through",
            );
        }
    }

    /**
     * The tool row is cut rather than wrapped with the MOUSE OFF too, and this
     * is the state an earlier revision got wrong while its docblock claimed the
     * two classifications "cannot disagree".
     *
     * `recordToolCallZone()` deliberately records nothing when
     * `Chat::mouseClicksEnabled()` is false (or when the model-supplied id is
     * outside `ZONE_ID_CHARSET`), so a fitter reading the CLICK registry to
     * answer a LAYOUT question saw no tool rows at all and wrapped this one.
     * Measured at cols=20 with clicks off, before the fix:
     *
     *      2 w=20 |│  🔧 tool: B ⊘    │|
     *      3 w=20 |│  interrupted     │|   ← wrapped, not cut
     *
     * That second row is the lookalike
     * {@see testAnInterruptedToolRowStaysClickableAtTheNarrowestTerminal()}
     * documents as unacceptable — and with clicks off it cannot even be
     * explained away as clickable. The layout must not depend on whether the
     * mouse is on, so the two frames are compared directly.
     */
    public function testAToolRowIsCutNotWrappedWithMouseClicksDisabledToo(): void
    {
        $chat = fn(): Chat => new Chat(
            history: [Message::assistant('')->withToolResults([new ToolResult(
                name: 'BashCommandRunner',
                result: '',
                id: 'call_stopped',
                error: Chat::INTERRUPTED_TOOL_CALL,
            )])],
            rows: 20,
            cols: 20,
        );

        $withMouse = self::plain(Renderer::render($chat()));

        putenv('SUGARCRUSH_DISABLE_MOUSE_CLICKS=1');
        Renderer::clearZones();
        $withoutMouse = self::plain(Renderer::render($chat()));
        putenv('SUGARCRUSH_DISABLE_MOUSE_CLICKS');

        // One row per call: the line straight after the tool row is the result
        // body, not the tail of the status word on a lookalike second row.
        $rows = explode("\n", $withoutMouse);
        $toolRow = self::rowContaining($rows, '🔧 tool:');
        self::assertStringContainsString(
            'Tool call',
            $rows[$toolRow + 1],
            'the tool row wrapped into a lookalike second row once clicks were off',
        );
        self::assertSame(
            $withMouse,
            $withoutMouse,
            'the transcript LAYOUT changed with the mouse, which it must not',
        );
    }

    /**
     * The tool-label bound is not a width assertion, and this is the test that
     * says what it is FOR.
     *
     * Every width assertion in this file is satisfied with `renderToolResults()`'s
     * `$labelRoom` arithmetic wrong, because {@see Renderer::hardFit()} truncates
     * an over-wide tool row regardless of what the bound computed — so the
     * invariant holds and the row is in-pane because it was CUT. Measured,
     * driving {@see Renderer::renderToolResults()} with a name far longer than
     * any budget:
     *
     *     pristine                      row = $width exactly, status word whole
     *     drop the trailing `- 1`       row = $width + 1  → the cut shaves the status word's last cell
     *     drop `- Width::of($status)`   row = $width + 13 → the cut eats "⊘ interrupted" whole
     *
     * Both of those keep every `assertLessThanOrEqual` in this file green. So
     * what is asserted here is that the CUT NEVER HAS TO FIRE: the row arrives
     * at the fitter already fitting, and it is byte-for-byte the row the bound
     * predicts — prefix, the name truncated to exactly `$labelRoom` cells, one
     * space, and the whole status word.
     *
     * An EQUALITY is legitimate here for the same reason it is in
     * {@see testTheFitterFillsThePaneRatherThanMerelyStayingUnderIt()}: the name
     * cannot wrap or end early, so the bound is the only thing that decides this
     * row's width. `$labelRoom >= 1` is asserted as a fixture precondition,
     * because below that the `max(1, …)` clamp takes over and the row is
     * over-wide by design — that range is
     * {@see testTheNarrowestToolRowKeepsAtLeastOneCellOfItsName()}'s.
     */
    public function testAToolRowArrivesAtTheFitterAlreadyFittingSoTheCutNeverHasToFire(): void
    {
        $prefix = self::toolRowPrefix();
        $hardFit = new \ReflectionMethod(Renderer::class, 'hardFit');
        $name = str_repeat('AbsurdlyLongToolName', 9);

        foreach ([['✓ ok', null], ['⊘ interrupted', Chat::INTERRUPTED_TOOL_CALL]] as [$status, $error]) {
            foreach ([26, 40, 60, 94] as $width) {
                $labelRoom = $width - Width::of($prefix) - Width::of($status) - 1;
                self::assertGreaterThanOrEqual(
                    1,
                    $labelRoom,
                    "the fixture is in the clamped range at width {$width}, so it proves nothing here",
                );

                [$row] = self::toolRow($name, $error, $width);

                // The bound's whole job: no cut is needed, so the row is
                // exactly the pane and the fitter's tool-row branch is never
                // reached for it.
                self::assertSame(
                    $width,
                    Width::of($row),
                    "the \"{$status}\" tool row reaches the fitter at the wrong width for a {$width}-cell pane",
                );
                self::assertSame(
                    $row,
                    (string) $hardFit->invoke(null, $row, $width),
                    "hardFit() had to cut the \"{$status}\" row at width {$width}, so the label bound is wrong",
                );
                // And the row is the predicted one rather than merely a fitting
                // one: the status word is whole (the cut takes it from the END,
                // so an over-wide row loses the word the strikethrough is
                // explaining) and the name stops exactly where the bound says.
                self::assertSame(
                    $prefix . mb_substr($name, 0, $labelRoom) . ' ' . $status,
                    self::plain($row),
                    "the \"{$status}\" row at width {$width} is not the row the bound predicts",
                );
            }
        }
    }

    /**
     * The `max(1, $labelRoom)` clamp, at the only widths where it does anything
     * — and the one assertion here that is about the row's IDENTITY rather than
     * its width.
     *
     * The clamped range is DERIVED rather than guessed. The bound runs out of
     * room when `Width::of(TOOL_ROW_PREFIX) + Width::of($status) + 1 >= $width`,
     * which for the 9-cell prefix and `⊘ interrupted` (13 cells) is every width
     * up to and including 23; and `renderView()` floors the content width at
     * `max(20, cols - 6)`, so the range a real terminal can reach is exactly
     * 20-23. There the row cannot fit whatever the bound says and `hardFit()`
     * cuts it — the deliberate narrow-terminal overflow
     * {@see testAnInterruptedToolRowStaysClickableAtTheNarrowestTerminal()}
     * documents.
     *
     * What the clamp buys is the one cell it refuses to give up. Relaxed to
     * `max(0, …)` the name is truncated to NOTHING and the row renders
     * `🔧 tool:  ⊘ interrupted` — one cell NARROWER, so every width assertion in
     * this file still passes while the row has lost the only thing on it that
     * says which tool this was. That is why this is asserted as the row's
     * minimum COMPOSITION (prefix + one cell of name + space + status) and not
     * as a bound.
     */
    public function testTheNarrowestToolRowKeepsAtLeastOneCellOfItsName(): void
    {
        $prefix = self::toolRowPrefix();
        $status = '⊘ interrupted';
        $exhausted = Width::of($prefix) + Width::of($status) + 1;
        $hardFit = new \ReflectionMethod(Renderer::class, 'hardFit');

        self::assertSame(
            23,
            $exhausted,
            'the prefix or the status word changed width, so the clamped range must be re-derived',
        );

        // 20 is `renderView()`'s content floor, $exhausted the last width at
        // which the bound has nothing left to give.
        foreach (range(20, $exhausted) as $width) {
            [$row, $head] = self::toolRow('BashCommandRunner', Chat::INTERRUPTED_TOOL_CALL, $width);

            self::assertSame(
                $prefix . 'B' . ' ' . $status,
                self::plain($row),
                "the clamp gave up the name's last cell at width {$width}",
            );
            self::assertSame(
                Width::of($prefix) + 1 + 1 + Width::of($status),
                Width::of($row),
                "the narrowest tool row is not the minimum composition the clamp permits at width {$width}",
            );
            // The cut is real at these widths — 24 cells into a 20-column pane
            // — so the row's other invariant is worth stating here rather than
            // only at the frame level: it must still carry the head
            // `recordToolCallZone()` recorded, or the row goes on looking fine
            // and silently stops answering clicks.
            self::assertStringContainsString(
                $head,
                (string) $hardFit->invoke(null, $row, $width),
                "the cut at width {$width} ate into the recorded click-zone head",
            );
        }
    }

    /**
     * Two things about the overlay path, one of which is this file's own bug and
     * one of which is out of scope but must not be silently unasserted.
     *
     * 1. An overlay row is NOT the status bar. {@see transcriptRows()} used to
     *    pop the last row unconditionally; with the palette open at cols=40 and
     *    rows=20 the last row is `│  Appearance …│· /exit or ^C to quit`, so
     *    the invariant was applied to one row fewer than the frame has.
     * 2. The overlay path is over-wide at narrow terminals and this bundle does
     *    NOT fix it: `renderView()`'s choke point holds `$body`, and the palette
     *    box is composited by `Veil` afterwards at its own natural width. So the
     *    behaviour is PINNED rather than exempted — swept, the palette fits from
     *    cols=60 up (widest 60, then 77 from 80 up) and overflows below it
     *    (cols=40 → 56). If a later change widens or narrows that, this fails
     *    and someone decides deliberately.
     */
    public function testAnOverlayRowIsNeitherMistakenForTheStatusBarNorSilentlyUnmeasured(): void
    {
        $history = [Message::user('hi'), Message::assistant('a reply of prose words here')];

        // (1) The palette reaches the last line at rows=20/cols=40, so nothing
        // may be dropped as if it were the status bar.
        $frame = Renderer::render(new Chat(
            history: $history,
            rows: 20,
            cols: 40,
            palette: PaletteState::root(),
        ));
        self::assertCount(
            count(explode("\n", $frame)),
            self::transcriptRows($frame),
            'an overlay row was dropped as if it were the status bar',
        );

        // (2a) From 60 columns up the invariant genuinely holds with the palette
        // open, so it is asserted rather than assumed.
        foreach ([60, 100] as $cols) {
            self::assertRowsFit(Renderer::render(new Chat(
                history: $history,
                rows: 40,
                cols: $cols,
                palette: PaletteState::root(),
            )), $cols);
        }

        // (2b) And below it, the current width is pinned as the out-of-scope
        // fact it is.
        $narrow = Renderer::render(new Chat(
            history: $history,
            rows: 40,
            cols: 40,
            palette: PaletteState::root(),
        ));
        self::assertSame(
            56,
            self::widestRow(implode("\n", self::transcriptRows($narrow))),
            'the palette overlay width changed; it is over-wide at cols=40 by design of Veil, '
            . 'not of fitToPane, and this bundle does not fix it',
        );
    }

    /**
     * Every geometry assertion in this file is an upper bound, and an upper
     * bound alone does not say the pane is USED: a fitter that wrapped at half
     * the pane width, or at `$width - 6`, satisfies all of them. So the two
     * fixtures that CANNOT wrap early are asserted to fill the pane exactly.
     *
     * "Cannot wrap early" is what makes an equality assertion legitimate here:
     * a 180-character unbreakable identifier and a run of double-width CJK in a
     * fence have no word boundaries for CandyShine (or for `wrapAnsi()`) to
     * prefer, so every row but the last is filled to the cell. Ordinary prose
     * cannot be pinned that tightly — its last word lands where it lands — so
     * it is bounded from below instead, at both the settled and the streaming
     * site, since each has its own width parameter and a fix applied to one
     * leaves the other wrapping to a different shape.
     */
    public function testTheFitterFillsThePaneRatherThanMerelyStayingUnderIt(): void
    {
        // 20 is below NARROW_FLOOR, and it is what pins the floor's VALUE from
        // the other side: with the floor dropped to 1 the frame is 20 columns
        // wide here instead of 26.
        foreach ([20, 40, 60, 80, 100] as $cols) {
            foreach (['long word', 'cjk in a fence'] as $class) {
                $frame = Renderer::render(new Chat(
                    history: [Message::assistant(self::contentClasses()[$class])],
                    rows: 40,
                    cols: $cols,
                ));

                self::assertSame(
                    max($cols, self::NARROW_FLOOR),
                    self::widestRow(implode("\n", self::transcriptRows($frame))),
                    "\"{$class}\" does not fill a {$cols}-column pane, so the wrap width is wrong",
                );
            }
        }

        $prose = self::contentClasses()['prose'];
        foreach ([60, 80, 100] as $cols) {
            foreach ([
                'settled' => new Chat(history: [Message::assistant($prose)], rows: 40, cols: $cols),
                'streaming' => new Chat(history: [], rows: 40, cols: $cols, inFlight: true, streamingText: $prose),
            ] as $state => $chat) {
                $widest = self::widestRow(implode("\n", self::transcriptRows(Renderer::render($chat))));

                self::assertLessThanOrEqual($cols, $widest);
                self::assertGreaterThan(
                    $cols - 8,
                    $widest,
                    "the {$state} turn's prose wraps well short of the {$cols}-column pane",
                );
            }
        }
    }

    /**
     * The cluster fixtures are WRAPPED, not cut — the same "both things at once"
     * this file asserts for the reported reply, applied to the case where the
     * bound is hardest to hold.
     *
     * Worth its own test because the cheap way to satisfy the width invariant on
     * a row whose clusters `wrapAnsi()` and `Width::of()` disagree about is to
     * cut it, and a cut passes every assertion above. Measured with
     * {@see Renderer::wrapToPane()}'s retry disabled, 60 flags at cols=100 came
     * back as 50 — the invariant met by deleting ten of them.
     */
    public function testTheClusterFixturesAreWrappedRatherThanCut(): void
    {
        foreach ([
            'skin tone emoji' => "\u{1F44D}\u{1F3FD}",
            'flag emoji' => "\u{1F1FA}\u{1F1F8}",
        ] as $class => $glyph) {
            $content = self::contentClasses()[$class];

            foreach ([40, 100] as $cols) {
                $frame = Renderer::render(new Chat(
                    history: [Message::assistant($content)],
                    rows: 40,
                    cols: $cols,
                ));

                self::assertSame(
                    mb_substr_count($content, $glyph),
                    mb_substr_count($frame, $glyph),
                    "\"{$class}\" was fitted to {$cols} columns by deleting clusters",
                );
            }
        }
    }

    /**
     * {@see Renderer::hardFit()}, the cut of last resort, pinned directly —
     * because with {@see Renderer::wrapToPane()}'s retry converging on every
     * fixture in this file, no frame reaches it. It is kept rather than dropped
     * for the reason every fail-closed arm in this renderer is kept: it is what
     * makes the width bound unconditional rather than dependent on the retry
     * happening to succeed, and the cost of keeping it is one function call.
     *
     * The measurement that makes it necessary, and the reason the obvious
     * one-liner is not enough: `Width::truncateAnsi()` walks its own cluster
     * scanner, so asking it for 10 cells of regional-indicator flags returns 20
     * as `Width::of()` counts them. A backstop measured with the wrong
     * instrument is not a backstop.
     */
    public function testTheHardFitBackstopHoldsTheBoundWhereTruncateAnsiAloneDoesNot(): void
    {
        $flags = str_repeat("\u{1F1FA}\u{1F1F8}", 10);

        self::assertGreaterThan(
            10,
            Width::of(Width::truncateAnsi($flags, 10)),
            'candy-core stopped disagreeing with itself, so this backstop may be reconsidered',
        );

        $hardFit = new \ReflectionMethod(Renderer::class, 'hardFit');
        foreach ([1, 5, 10, 19] as $width) {
            self::assertLessThanOrEqual(
                $width,
                Width::of((string) $hardFit->invoke(null, $flags, $width)),
                "hardFit() did not hold {$width} cells",
            );
        }
    }

    /**
     * {@see Renderer::fitToPane()}'s fast path hands a fitting row back
     * BYTE-IDENTICAL, and the whole image-marker argument in that method's
     * docblock rests on it — so the claim is pinned here instead of left as
     * prose nothing measures.
     *
     * The tempting simplification is to drop the branch and run every row
     * through `Width::truncateAnsi($row, $width)`: a row that already fits
     * cannot be cut, so the two must be the same thing. They are not, and the
     * reason is a THIRD disagreement between candy-core's two width
     * accountings — one the cluster measurements in
     * {@see Renderer::wrapToPane()} do not cover, and one that outlives PHP
     * 8.4's `grapheme_str_split()` because it is not about clusters at all.
     *
     * `Width::of()` measures `Ansi::strip($row)`, and `strip()` consumes a
     * TWO-BYTE escape whose second byte is an ECMA-48 Fe final (0x40-0x5f —
     * ESC-backslash, `ESC P`, `ESC M`) as zero cells. `truncateAnsi()`'s scanner
     * passes through `ESC [` and `ESC ]` only, so it reads that second byte as
     * one VISIBLE cell. Measured on `a` + ESC-backslash repeated ten times:
     *
     *     Width::of($row)            = 10  → the fast path accepts the row
     *     Width::truncateAnsi($row, 10) → 5 cells, 15 of the 30 bytes
     *
     * So the simplification deletes half the visible cells of a row that fit —
     * exactly the silent-truncation class this whole file exists for, in the one
     * branch whose contract is to touch nothing.
     *
     * Pinned at the branch rather than through a frame, and the reason is
     * measured rather than assumed: NO path into this renderer delivers such a
     * row today. An assistant reply, a fenced block, inline code, a user message
     * and a diff body were each rendered carrying twelve ESC-backslash pairs and the
     * finished frame contained zero of them — CandyShine and the untrusted
     * scrubbers between them take the escape out. The branch is kept for the
     * reason {@see testTheHardFitBackstopHoldsTheBoundWhereTruncateAnsiAloneDoesNot()}
     * keeps the backstop: a pass-through that is one only because every upstream
     * scrubber happens to hold is one refactor away from cutting real text, and
     * the cost of keeping it is one comparison.
     */
    public function testTheFitterPassesAFittingRowThroughUntouchedWhereTruncateAnsiWouldCutIt(): void
    {
        $fit = new \ReflectionMethod(Renderer::class, 'fitToPane');

        foreach (["a\x1b\\", "a\x1bP", "a\x1bM"] as $unit) {
            $row = str_repeat($unit, 10);
            $width = Width::of($row);
            $escape = 'ESC ' . substr($unit, -1);

            self::assertSame(
                10,
                $width,
                "the {$escape} fixture is not on the fast path, so it proves nothing",
            );
            self::assertLessThan(
                $width,
                Width::of(Width::truncateAnsi($row, $width)),
                "candy-core stopped disagreeing with itself about {$escape}, so this branch may be reconsidered",
            );
            self::assertSame(
                $row,
                (string) $fit->invoke(null, $row, $width),
                "a row that already fits was rewritten instead of passed through ({$escape})",
            );
        }
    }

    /**
     * {@see Renderer::balanceSgr()}'s trailing reset, pinned at the only place
     * its effect is observable — which is NOT the hosted frame.
     *
     * Measured: dropping that reset (keeping the inherited prefix) changes no
     * assertion in this file, because the shell's border closes every row
     * anyway. That is a real fact about the composition and it is the reason
     * this test drives {@see Renderer::fitToPane()} directly rather than
     * pretending a frame shows it. The clause STAYS — a renderer whose rows are
     * balanced only because something downstream happens to close them is one
     * refactor away from bleeding colour into the next `eraseLine()`, and the
     * cost of keeping it is one function call — but it is pinned honestly, at
     * the level where it is true.
     */
    public function testFitToPaneClosesTheStyleEveryRowItEmitsLeavesOpen(): void
    {
        $fit = new \ReflectionMethod(Renderer::class, 'fitToPane');
        $rows = explode("\n", (string) $fit->invoke(null, "\x1b[1m" . str_repeat('word ', 40), 40));

        self::assertGreaterThan(1, count($rows), 'the input did not wrap, so it proves nothing');
        foreach ($rows as $index => $row) {
            self::assertStringEndsWith(
                "\x1b[0m",
                $row,
                "row {$index} ends with the bold still open",
            );
            if ($index > 0) {
                self::assertStringStartsWith(
                    "\x1b[",
                    $row,
                    "row {$index} does not re-establish the styling it inherits",
                );
            }
        }
    }

    /**
     * The height claim in this class's docblock, bounded honestly at its one
     * exception.
     *
     * `renderView()` computes `$available = max(1, $rows - 1)` and then appends
     * the status bar, so a ONE-row terminal gets a TWO-row frame. That predates
     * this bundle (the `max(1, …)` and the bar are both older than the fitter)
     * and is not changed here; it is pinned so the exception is a measured fact
     * rather than a sweep that quietly starts at 8.
     */
    public function testAOneRowTerminalGetsATwoRowFrameWhichIsTheHeightRulesOneException(): void
    {
        foreach ([1 => 2, 2 => 2, 3 => 3, 8 => 8] as $rows => $expected) {
            self::assertCount(
                $expected,
                explode("\n", Renderer::render(new Chat(
                    history: [Message::assistant('hello there')],
                    rows: $rows,
                    cols: 60,
                ))),
                "a {$rows}-row terminal produced the wrong frame height",
            );
        }
    }

    // =====================================================================
    // Helpers
    // =====================================================================

    /**
     * The invariant, applied to one frame: every transcript row fits
     * `max($cols, NARROW_FLOOR)`. See the class docblock for why the status bar
     * is dropped and why the floor is part of the bound.
     */
    private static function assertRowsFit(string $frame, int $cols): void
    {
        $budget = max($cols, self::NARROW_FLOOR);

        foreach (self::transcriptRows($frame) as $index => $row) {
            self::assertLessThanOrEqual(
                $budget,
                self::rowWidth($row),
                "row {$index} is wider than the terminal: " . self::plain($row),
            );
        }
    }

    /**
     * Every row of $frame except the status bar, which is fitted rather than
     * cut.
     *
     * Exempted by PREDICATE, not by position, and the difference is a row: an
     * earlier revision popped the last row unconditionally on the grounds that
     * "the frame's last line is the status bar", which is false the moment an
     * overlay is tall enough to reach it. Measured with the palette open at
     * cols=40, the last row was `│  Appearance …│· /exit or ^C to quit` at
     * rows=6 and `╰────╯· /exit …` at rows=20 — an OVERLAY row the invariant
     * was then never applied to.
     *
     * {@see isStatusBar()} is what decides, so an overlay row stays in the set
     * it belongs to.
     */
    private static function transcriptRows(string $frame): array
    {
        $rows = explode("\n", $frame);
        if ($rows !== [] && self::isStatusBar((string) end($rows))) {
            array_pop($rows);
        }

        return array_values($rows);
    }

    /**
     * Is $row the status bar rather than a row of the shell (or of an overlay
     * composited over it)?
     *
     * The bar is the one row `renderView()` emits OUTSIDE the shell's box, so
     * "does it start with box drawing" answers it without pinning any of the
     * bar's own segments — which vary with app state (54 columns idle, 36 in
     * flight, narrower still once segments drop) and are pinned by
     * {@see StatusBarSpendTest} instead. Every other row of the frame starts
     * with the shell's border glyph, or with an overlay box's, because `Veil`
     * composites the overlay over whole rows.
     */
    private static function isStatusBar(string $row): bool
    {
        $first = mb_substr(self::plain($row), 0, 1);

        return $first !== '' && !str_contains('│╭╮╰╯─┌┐└┘', $first);
    }

    /** Index of the first row of $rows whose text contains $needle. */
    private static function rowContaining(array $rows, string $needle): int
    {
        foreach ($rows as $index => $row) {
            if (str_contains($row, $needle)) {
                return $index;
            }
        }

        self::fail("no rendered row contains \"{$needle}\"");
    }

    private static function rowWidth(string $row): int
    {
        return Width::of($row);
    }

    private static function widestRow(string $block): int
    {
        return max(array_map([self::class, 'rowWidth'], explode("\n", $block)));
    }

    /** $frame with its SGR escapes removed — what the reader actually sees. */
    private static function plain(string $frame): string
    {
        return (string) preg_replace('/\x1b\[[0-9;]*[A-Za-z]/', '', $frame);
    }

    /**
     * `Renderer::TOOL_ROW_PREFIX`, read off the class rather than restated, so
     * the arithmetic these tests derive cannot drift from the renderer's.
     */
    private static function toolRowPrefix(): string
    {
        return (string) (new \ReflectionClassConstant(Renderer::class, 'TOOL_ROW_PREFIX'))->getValue();
    }

    /**
     * One tool row as {@see Renderer::renderToolResults()} hands it to
     * {@see Renderer::fitToPane()} — BEFORE the fitter, which is the only place
     * the label bound is observable at all: afterwards {@see Renderer::hardFit()}
     * has made the row fit whether the bound was right or wrong.
     *
     * `$toolRowHeads` is cleared first (`renderView()` does it per frame, and
     * this bypasses `renderView()`) so the head returned is this row's.
     *
     * @return array{0: string, 1: string} the row, and the click-zone head recorded for it
     */
    private static function toolRow(string $name, ?string $error, int $width): array
    {
        $render = new \ReflectionMethod(Renderer::class, 'renderToolResults');
        $heads = new \ReflectionProperty(Renderer::class, 'toolRowHeads');
        $heads->setValue(null, []);

        $block = (string) $render->invoke(
            null,
            Message::assistant('')->withToolResults([new ToolResult(
                name: $name,
                result: 'done',
                id: 'call_bound',
                error: $error,
            )]),
            Theme::default(),
            $width,
            [],
            new ImageLayer(),
            null,
            0,
        );

        return [explode("\n", $block)[0], (string) (((array) $heads->getValue())[0] ?? '')];
    }

    /**
     * The rows of $frame that carry a piece of $text, stripped of styling and
     * box drawing — the wrapped SHAPE of a reply, independent of the chrome
     * around it.
     *
     * @return list<string>
     */
    private static function proseRows(string $frame, string $text): array
    {
        $firstWord = strtok($text, ' ');
        $out = [];
        foreach (explode("\n", self::plain($frame)) as $row) {
            $bare = trim($row, " \u{2502}\u{256d}\u{256e}\u{2570}\u{256f}\u{2500}");
            if ($bare !== '' && (str_contains($text, $bare) || str_starts_with($bare, (string) $firstWord))) {
                $out[] = $bare;
            }
        }

        return $out;
    }

    /** A real, decodable PNG — candy-mosaic rejects anything it cannot decode. */
    private static function pngBytes(): string
    {
        $gd = imagecreatetruecolor(20, 10);
        imagefilledrectangle($gd, 0, 0, 19, 9, (int) imagecolorallocate($gd, 200, 30, 30));
        ob_start();
        imagepng($gd);

        return (string) ob_get_clean();
    }
}
