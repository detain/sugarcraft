<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Tui;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\Util\Width;
use SugarCraft\Crush\Agents\Agent;
use SugarCraft\Crush\Agents\AgentManager;
use SugarCraft\Crush\App\App;
use SugarCraft\Crush\Chat;
use SugarCraft\Crush\Providers\ProviderInterface;
use SugarCraft\Crush\Renderer;
use SugarCraft\Crush\Skills\SkillRegistry;
use SugarCraft\Crush\Theme;
use SugarCraft\Crush\Tui\AgentDisplayState;
use SugarCraft\Crush\Tui\AgentViewPane;
use SugarCraft\Crush\Tui\Components\AgentDashboardPane;
use SugarCraft\Crush\Tui\Pane;
use SugarCraft\Crush\Tui\Renderer as ShellRenderer;

/**
 * Two width defects in {@see AgentViewPane}, both about a number that was
 * true of one thing and used for another.
 *
 * **E53 — the truncator and the width measure disagreed.**
 * `visualWidth()` delegates to {@see Width::string()}, which runs a ZWJ state
 * machine over the WHOLE string, so the family emoji U+1F468 ZWJ U+1F469 ZWJ
 * U+1F467 measures 2 cells. `truncate()` walked `preg_split('//u')` and summed
 * `charWidth()` per CODEPOINT, which scores the same sequence 6. Two answers
 * for one string. The direction was USUALLY safe — the per-codepoint sum is
 * usually the larger, so the loop over-spent its budget and cut early — but
 * not always, and the entry this test was written from got that wrong.
 * `Width::string()` charges +2 for `<emoji> ZWJ`, crediting the emoji its ZWJ
 * state machine skipped, where the per-codepoint sum charges `1 + 0`; on those
 * inputs the WHOLE-string measure is the larger and the old truncator's
 * `$visualWidth <= $maxWidth` early return handed an over-wide string back.
 * Measured at 087a3179, `truncate(U+1F1E6 U+11A8 U+2764 ZWJ U+1F1F8, 4)` came
 * back **5 cells**, and 400,000 fuzzed calls gave 727 over-runs against 0 for
 * the cluster loop. On top of that, cutting inside the sequence emitted a
 * **dangling ZWJ**: a joiner with nothing after it, which is a rendering
 * hazard on its own account and not merely a lost character.
 *
 * **E54 — `render(..., $width, ...)` returns `$width + 4` cells.**
 * `$width` is handed to `Style::width()`, which sizes the CONTENT box; the
 * rounded border (2 cells) and `padding(0, 1)` (2 more) are drawn outside it.
 * That holds whenever the composed row body FITS `$width`, which is the domain
 * the sweeps below state instead of claiming an invariant. With an ASCII
 * operation it was already `+4` at `$width` = 20/28/30/40/43/44/58/60/80/98,
 * populated and empty alike. With a wide-cluster operation it USED TO come
 * back `$width + 6` at six of those ten — 2 cells over the `$width + 4` due —
 * because `render()`'s `$opBudget = max(5, $width - name - 60)` floor let the
 * body outgrow the box and the wrap then fell inside a 2-cell cluster. That
 * second half was pre-existing, not a regression of the CHROME_WIDTH work:
 * the same ten numbers came out of `087a3179`. It is recorded as E64 and it
 * is FIXED — `render()` now clamps the composed label to the cells actually
 * left after the metrics — so what the sweep asserts today is `$width + 4` at
 * every one of the ten, wide-cluster payload included, and over every width
 * from 20 to 140.
 *
 * Two units get confused here, so both are spelled out: the ABSOLUTE figure
 * was `$width + 6` (26 cells at `$width` = 20, measured), and the EXCESS over
 * the due `$width + 4` was `+2`. Re-measured against `70a4efb3`'s pane over
 * 20..140, that excess was +2 at every width from 20 to 44 and +1 at 45, for
 * both the skin-toned thumb and the flag; above 45 there was none.
 *
 * The overhead is now named — {@see AgentViewPane::CHROME_WIDTH} — and both
 * callers subtract it through {@see AgentViewPane::contentWidth()} instead of
 * each writing its own literal, which is how they came to disagree.
 *
 * **The clamp's own hazard, found and fixed with it.** The clamp
 * that fixed E64 feeds the WHOLE composed label to `truncate()`, which groups
 * clusters and knows nothing about escape sequences. Where the old code
 * interpolated `$name` and `$agent->status` untruncated — so an escape inside
 * either survived intact — the clamp could cut one in half: measured over
 * widths 1..140 with `name = \e[32mabc\e[0m`, the clamp severed an SGR at 7
 * widths against the old code's 0, and with the same escapes in the status,
 * at 13 against 0. A severed reset leaks colour into the rest of the FRAME.
 * `render()` now takes name, status and operation through
 * `AgentViewPane::stripEscapes()` first, so the precondition the clamp needs
 * is enforced rather than assumed; see
 * {@see self::testTheClampNeverSeversAnEscapeSequenceInAName()}.
 *
 * @see AgentViewPane
 * @see AgentDashboardPane::render()
 * @see \SugarCraft\Crush\Renderer::renderAgentView()
 */
final class AgentViewPaneGeometryTest extends TestCase
{
    /** U+1F468 ZWJ U+1F469 ZWJ U+1F467 — 2 cells whole-string, 6 summed per codepoint. */
    private const FAMILY = "\u{1F468}\u{200D}\u{1F469}\u{200D}\u{1F467}";

    private const ZWJ = "\u{200D}";

    /** U+1F44D + U+1F3FD — thumbs-up with a skin-tone modifier, 4 cells on 8.3. */
    private const THUMB = "\u{1F44D}\u{1F3FD}";

    /** U+1F1E6 U+1F1F8 — a regional-indicator pair, 2 cells on 8.3. */
    private const FLAG = "\u{1F1E6}\u{1F1F8}";

    /** Content widths every geometry assertion below is stated over. */
    private const WIDTHS = [20, 28, 30, 40, 43, 44, 58, 60, 80, 98];

    protected function setUp(): void
    {
        parent::setUp();
        ShellRenderer::resetSizeCache();
    }

    protected function tearDown(): void
    {
        ShellRenderer::resetSizeCache();
        parent::tearDown();
    }

    private static function theme(): Theme
    {
        return Theme::byName('dark');
    }

    /** Invoke the private truncator directly; the budget is not reachable from `render()`. */
    private static function truncate(string $text, int $budget): string
    {
        return (string) (new \ReflectionMethod(AgentViewPane::class, 'truncate'))
            ->invoke(null, $text, $budget);
    }

    /** ANSI-stripped body of a rendered row, for assertions about codepoints. */
    private static function plain(string $row): string
    {
        return preg_replace('/\x1b\[[0-9;:]*[A-Za-z]/', '', $row) ?? '';
    }

    // =========================================================================
    // E53 — the truncator iterates graphemes, not codepoints
    // =========================================================================

    /**
     * The exact reproduction, and the reason this is a hazard rather than a
     * cosmetic under-fill.
     *
     * `self::FAMILY . 'cde'` is 5 cells whole-string, so a 4-cell budget makes
     * `truncate()` enter its loop (the `$visualWidth <= $maxWidth` early
     * return is what kept the BARE family from reproducing this at budget 4 —
     * a 2-cell string never reaches the loop at all, and stating the
     * reproduction without the trailing context would be a fixture shaped like
     * the property instead of like the bug).
     *
     * Per codepoint the loop scored U+1F468 as 2, the ZWJ as 0, and then broke
     * on U+1F469 — leaving `U+1F468 ZWJ …`, 3 cells of a 4-cell budget with a
     * joiner hanging off the end. Per grapheme the sequence is one 2-cell unit
     * that either fits whole or is dropped whole, so the same budget now buys
     * `FAMILY . 'c' . '…'` — 4 cells, sequence intact.
     */
    public function testTruncatingIntoAZwjSequenceNoLongerLeavesTheJoinerDangling(): void
    {
        $out = self::truncate(self::FAMILY . 'cde', 4);

        $this->assertStringNotContainsString(
            self::ZWJ . "\u{2026}",
            $out,
            'truncator emitted a zero-width joiner with nothing joined to it',
        );
        $this->assertStringContainsString(
            self::FAMILY,
            $out,
            'the ZWJ sequence was split even though it fits the budget whole',
        );
        $this->assertSame(4, Width::string($out), 'grapheme truncation should fill the budget exactly here');
    }

    /**
     * The same claim wherever the cut lands, which is the part a single
     * reproduction cannot carry.
     *
     * A ZWJ sequence placed after 0..6 leading columns straddles the budget
     * boundary at a different offset each time; exactly one of those offsets
     * is the one that used to split it. Asserting over the whole sweep means
     * the guard does not depend on having guessed the offset right.
     */
    public function testNoBudgetOffsetCanSplitAZwjSequence(): void
    {
        for ($lead = 0; $lead <= 6; $lead++) {
            $text = str_repeat('a', $lead) . self::FAMILY . 'zzz';
            for ($budget = 1; $budget <= $lead + 6; $budget++) {
                $out = self::truncate($text, $budget);

                $this->assertDoesNotMatchRegularExpression(
                    '/\x{200D}(?![\x{1F300}-\x{1FAFF}])/u',
                    $out,
                    sprintf('lead %d, budget %d left a dangling joiner', $lead, $budget),
                );
                $this->assertLessThanOrEqual(
                    $budget,
                    Width::string($out),
                    sprintf('lead %d, budget %d over-ran its budget', $lead, $budget),
                );
            }
        }
    }

    /**
     * End-to-end through `render()`, so the guard is not purely a statement
     * about a private method.
     *
     * At width 80 with a 3-cell name the operation budget is
     * `max(5, 80 - 3 - 60)` = 17 — the same arithmetic
     * {@see CellWidthPaddingTest} pins — and 14 leading columns put the family
     * emoji astride it: the old loop took U+1F468 (14 + 2 = 16, inside the
     * `$maxWidth - 1` allowance) and its joiner, then broke on U+1F469.
     */
    public function testARenderedOperationNeverEndsWithADanglingJoiner(): void
    {
        $agent = AgentDisplayState::new(
            'abc',
            'working',
            str_repeat('a', 14) . self::FAMILY . 'zzz',
            42,
            1234,
            0.0042,
        );

        $row = self::plain(explode("\n", AgentViewPane::render([$agent], 0, 80, 5, self::theme()))[1]);

        $this->assertDoesNotMatchRegularExpression(
            '/\x{200D}(?![\x{1F300}-\x{1FAFF}])/u',
            $row,
            'a rendered agent row carried a zero-width joiner with nothing joined to it',
        );
    }

    // =========================================================================
    // E54 — the chrome overhead is named, and both callers subtract it
    // =========================================================================

    /**
     * The `+4` stated against the constant rather than a literal, so the
     * constant cannot drift away from the geometry it describes.
     *
     * The fixture is ASCII **on purpose**, and the purpose is worth naming so
     * the next reader does not mistake it for the whole property. The `+4` is
     * not unconditional as ARITHMETIC: it holds whenever the composed row body
     * fits `$width`, and an ASCII operation always made it fit here because it
     * truncates to whole cells and wraps on cell boundaries. When the body
     * outgrew the box AND carried a wide cluster, the row came back wider.
     * `render()` now clamps the body so it cannot outgrow the box (E64), which
     * is what {@see
     * testAWideClusterOperationNoLongerOverrunsTheChromeGeometryAtTheOperationFloor()}
     * measures — over the payloads that used to break it rather than over the
     * one that never could.
     */
    public function testARenderedPaneIsExactlyChromeWidthWiderThanTheContentWidthItWasHanded(): void
    {
        $agents = [
            AgentDisplayState::new('plain', 'working', 'ascii operation here', 42, 1234, 0.0042),
            AgentDisplayState::new('two', 'waiting', 'another operation', 7, 99, 0.1),
        ];

        foreach (self::WIDTHS as $width) {
            foreach ([$agents, []] as $list) {
                $rows = explode("\n", AgentViewPane::render($list, 0, $width, 6, self::theme()));
                $widths = array_map(static fn(string $row): int => Width::string($row), $rows);

                $this->assertSame(
                    array_fill(0, count($widths), $width + AgentViewPane::CHROME_WIDTH),
                    $widths,
                    sprintf(
                        'content width %d, %s list: rows were %s',
                        $width,
                        $list === [] ? 'empty' : 'populated',
                        implode('/', $widths),
                    ),
                );
            }
        }
    }

    /**
     * The same sweep with a wide-cluster operation — the case that used to
     * break the `+4`, inverted now that E64 is fixed.
     *
     * The sweep above ran an ASCII fixture over these widths and came back
     * `+4` at every one, and an ASCII fixture over an ASCII-shaped property is
     * the trap this file was written to avoid elsewhere. Swap in a skin-toned
     * thumb or a flag and 6 of the 10 widths used to break it: `render()`
     * returned `$w + 6` at 20/28/30/40/43/44 (26/34/36/46/49/50 against
     * 24/32/34/44/47/48 due) and `$w + 4` at 58/60/80/98. Those ten numbers
     * are kept below as `$beforeE64`, and they are DOCUMENTATION, not
     * enforcement — this used to say they were "asserted to be GONE, so the
     * fix cannot be silently reverted into a test that only says +4", and that
     * claim was false. The guard that made it was
     * `assertNotSame($beforeE64[$w], $widest)` sitting after
     * `assertSame($w + CHROME_WIDTH, $widest)`: once the first assertion
     * passes, `$widest` IS `$w + 4`, so on the six widths where `$beforeE64`
     * differs from `$w + 4` the second could not fail, and on the other four
     * it was skipped. Twelve assertions that could never fire. A suite holding
     * only the fixed pane cannot re-derive what the broken one returned, so
     * nothing here can enforce the historical figures; what does the work is
     * the `+4` assertion itself, and this test earns its place by driving it
     * over the wide-cluster payloads the ASCII sweep above cannot reach. The
     * historical number is carried into the failure message instead, where it
     * tells a future reader which defect a red line means.
     *
     * The cause was `render()`'s `$opBudget = max(5, $width - name - 60)`.
     * With a 3-cell name the floor of 5 binds below 69 content columns, so
     * `leftSection` plus `rightSection` exceeded `$width` and the body no
     * longer fitted the box `Style::width()` was sizing; where the cut fell
     * inside a 2-cell cluster the finished row came back wider than the box's
     * own border. `render()` now clamps the label to the cells actually left
     * after the metrics, so the body fits by construction.
     *
     * The over-run was PRE-EXISTING, not a regression of the CHROME_WIDTH
     * work: running the old sweep against `087a3179`'s AgentViewPane gave the
     * same ten numbers.
     */
    public function testAWideClusterOperationNoLongerOverrunsTheChromeGeometryAtTheOperationFloor(): void
    {
        // Content width => the widest row this used to produce. +6 where the
        // operation floor let the body outgrow the box, +4 above it.
        $beforeE64 = [
            20 => 26, 28 => 34, 30 => 36, 40 => 46, 43 => 49,
            44 => 50, 58 => 62, 60 => 64, 80 => 84, 98 => 102,
        ];
        $this->assertSame(self::WIDTHS, array_keys($beforeE64), 'the sweep and the historical map must agree');

        foreach ([self::THUMB, self::FLAG] as $label => $payload) {
            foreach (self::WIDTHS as $width) {
                $agent = AgentDisplayState::new('abc', 'working', str_repeat($payload, 3), 42, 1234, 0.0042);
                $rows = explode("\n", AgentViewPane::render([$agent], 0, $width, 6, self::theme()));
                $widest = max(array_map(static fn(string $row): int => Width::string($row), $rows));

                $this->assertSame(
                    $width + AgentViewPane::CHROME_WIDTH,
                    $widest,
                    sprintf(
                        'payload #%d at content width %d: widest row was %d; before E64 was fixed this returned %d',
                        $label,
                        $width,
                        $widest,
                        $beforeE64[$width],
                    ),
                );
            }
        }
    }

    /**
     * The bound stated over the whole range rather than over ten chosen
     * widths, because "ten chosen widths" is how E54's `+4` came to be written
     * up as an invariant in the first place.
     *
     * 20 through 140 is the range the E64 sweep was measured over. What that
     * sweep found before the fix: the wide-cluster payloads over-ran at every
     * width from 20 to 45 inclusive — 26 of the 121, not the 6 the ten-width
     * table showed — and the excess was NOT always +2 either: it is +2 from 20
     * to 44 and +1 at 45.
     *
     * **What this test does NOT establish, said plainly.** It generalises over
     * ONE axis. The width moves; the name (`abc`), the status (`working`) and
     * the metrics (`42s  1,234 tok | $0.0042`) are pinned, and the operation
     * is drawn from four hand-picked payloads. That is the same
     * fixture-shaped-like-the-property trap this file names elsewhere, moved
     * one axis over, and it is stated rather than fixed because the honest
     * generalisation FAILS today: `render()`'s clamp makes the body fit by
     * `Width::string`, and `Width::string` is not the measure the box uses.
     * `Style::render()` expands a tab to four spaces
     * (`candy-sprinkles/src/Style.php:969-970`) after the clamp has scored it
     * 0, so `operation = "\t" . U+1F3FD` — two codepoints — makes this pane
     * return `$width + 6` at 117 of these 121 widths. That is NOT a
     * regression: the pre-clamp pane at `70a4efb3` returns `$width + 6` at 120
     * of the same 121. It is a width-authority divergence, recorded as E69,
     * and widening this sweep is blocked on it rather than on anything in this
     * class.
     */
    public function testEveryContentWidthFrom20To140IsExactlyChromeWidthWiderThanItsBox(): void
    {
        $payloads = [
            'thumb' => str_repeat(self::THUMB, 3),
            'flag'  => str_repeat(self::FLAG, 3),
            'ascii' => 'ascii operation here',
            'family' => str_repeat(self::FAMILY, 3),
        ];

        foreach ($payloads as $name => $payload) {
            for ($width = 20; $width <= 140; $width++) {
                $agent = AgentDisplayState::new('abc', 'working', $payload, 42, 1234, 0.0042);
                $rows = explode("\n", AgentViewPane::render([$agent], 0, $width, 6, self::theme()));
                $widest = max(array_map(static fn(string $row): int => Width::string($row), $rows));

                $this->assertSame(
                    $width + AgentViewPane::CHROME_WIDTH,
                    $widest,
                    sprintf('%s payload at content width %d: widest row was %d', $name, $width, $widest),
                );
            }
        }
    }

    /**
     * What the clamp actually costs at the narrow end, spelled out rather than
     * left to "it fits now".
     *
     * 20 content columns cannot hold `● abc [working]` (15 cells), a 5-cell
     * operation and 24 cells of `42s  1,234 tok | $0.0042`. Something has to
     * give, and the choice is that the METRICS give first: a row that cannot
     * say which agent it is has nothing left to say, while elapsed without
     * usage is still a whole reading. Below even that, the label itself is
     * truncated.
     */
    public function testTheNarrowPaneDropsTheUsageColumnBeforeTheAgentsIdentity(): void
    {
        $agent = AgentDisplayState::new('abc', 'working', 'compiling', 42, 1234, 0.0042);

        $body = static function (int $width) use ($agent): string {
            $rows = explode("\n", AgentViewPane::render([$agent], 0, $width, 6, self::theme()));

            return trim(self::plain($rows[1]), "\u{2502} ");
        };

        // Wide: everything is there.
        $this->assertStringContainsString('1,234 tok | $0.0042', $body(80));
        $this->assertStringContainsString('compiling', $body(80));

        // 40 columns: usage will not fit beside the identity, elapsed still does.
        $this->assertStringNotContainsString('tok', $body(40));
        $this->assertStringContainsString('42s', $body(40));
        $this->assertStringContainsString('abc [working]', $body(40));

        // 20 columns: the label itself is cut, and the identity is what
        // survives longest.
        $this->assertStringContainsString('abc [working]', $body(20));
        $this->assertStringNotContainsString('compiling', $body(20));
    }

    /**
     * The clamp that fixed E64 must not cut an escape sequence in half.
     *
     * This is a REGRESSION test in the literal sense: the behaviour it pins
     * was correct before the clamp and wrong after it, for three commits. The
     * old code interpolated `$name` and `$agent->status` into `$leftSection`
     * untruncated, so an escape inside either reached the terminal intact; the
     * clamp feeds the WHOLE composed label to `truncate()`, which groups
     * clusters and knows nothing about escapes. An ESC byte measures 0 cells
     * so it joins the unit before it, while the `[`, the digits and the final
     * `m` are ordinary 1-cell units the loop may cut between — at width 10 the
     * row carried `\e[32mabc` then a bare `\e` then the ellipsis, and at
     * width 12 `…abc\e[0` then the ellipsis, a RESET cut in half whose colour
     * then bleeds into the rest of the frame rather than into one row.
     *
     * Measured over widths 1..140 before the fix: 7 severed widths with the
     * escapes in the name (3,4,5,6,10,11,12) and 13 with them in the status
     * (8,9,10,11,19,20,21,24,25,26,45,46,47), against 0 for both at
     * `70a4efb3`. The fix is `AgentViewPane::stripEscapes()`, applied to name,
     * status and operation before anything measures or cuts them, so the
     * precondition is enforced by the code rather than asserted of data
     * nothing validates — `$name` is `$agent->name` verbatim
     * (`Renderer.php:1663`), straight off the Agent registry and out of
     * imported foreign presets.
     *
     * The check is deliberately "no ESC survives stripping well-formed CSI",
     * not "the output equals X": it is the SEVERED sequence that is the
     * hazard, and a test pinning exact bytes would go red for cosmetic changes
     * that are not it.
     */
    public function testTheClampNeverSeversAnEscapeSequenceInAName(): void
    {
        $green = "\x1b[32m";
        $reset = "\x1b[0m";

        $fixtures = [
            'name'      => AgentDisplayState::new($green . 'abc' . $reset, 'working', 'building the thing', 65, 1234, 0.0042),
            'status'    => AgentDisplayState::new('abc', $green . 'working' . $reset, 'building the thing', 65, 1234, 0.0042),
            'operation' => AgentDisplayState::new('abc', 'working', $green . 'building the thing' . $reset, 65, 1234, 0.0042),
        ];

        foreach ($fixtures as $where => $agent) {
            $severed = [];

            for ($width = 1; $width <= 140; $width++) {
                $frame = AgentViewPane::render([$agent], -1, $width, 6, self::theme());

                // Drop every WELL-FORMED CSI; any ESC still standing was cut.
                if (str_contains(self::plain($frame), "\x1b")) {
                    $severed[] = $width;
                }
            }

            $this->assertSame(
                [],
                $severed,
                sprintf('escape in the %s was severed at widths %s', $where, implode(',', $severed)),
            );
        }
    }

    /**
     * The strip is the identity on escape-free text, which is why no geometry
     * figure in this file moved when it was introduced, and it leaves the
     * visible bytes of a non-CSI escape alone rather than swallowing them.
     */
    public function testStrippingEscapesLeavesEscapeFreeTextByteIdentical(): void
    {
        $strip = static fn(string $t): string => (string) (new \ReflectionMethod(AgentViewPane::class, 'stripEscapes'))
            ->invoke(null, $t);

        foreach (['', 'abc', 'a b [c]  d', self::FAMILY, self::THUMB, self::FLAG, "tab\there", '(B', "\x07"] as $plain) {
            $this->assertSame($plain, $strip($plain));
        }

        $this->assertSame('abc', $strip("\x1b[32mabc\x1b[0m"));
        $this->assertSame('abc', $strip("\x1b]0;title\x07abc"));
        // Bare ESC and a non-CSI escape: the ESC goes, what it prefixed stays.
        $this->assertSame('ab', $strip("a\x1bb"));
        $this->assertSame('(B', $strip("\x1b(B"));
        $this->assertSame('', $strip("\x1b[3"));
    }

    /**
     * `contentWidth()` is the subtraction both callers now make, and it is the
     * only place the overhead is spelled out.
     */
    public function testContentWidthSubtractsTheChromeAndHonoursItsFloor(): void
    {
        $this->assertSame(4, AgentViewPane::CHROME_WIDTH);
        $this->assertSame(76, AgentViewPane::contentWidth(80, 40));
        $this->assertSame(40, AgentViewPane::contentWidth(44, 40));
        // Below the floor the floor wins — deliberately, and it is why a
        // terminal narrower than floor + chrome still needs clipWidth().
        $this->assertSame(40, AgentViewPane::contentWidth(20, 40));
        $this->assertSame(96, AgentViewPane::contentWidth(100, 20));
    }

    /**
     * The dashboard pane's `$width` is documented as "total columns the pane
     * may occupy, borders included", and {@see \SugarCraft\Crush\Tui\Renderer}
     * hands it the terminal's full `$cols`. It subtracted 2 — the border — and
     * forgot `padding(0, 1)`'s other 2, so BOTH of its paths returned
     * `$width + 2`: the empty-list `AgentViewPane::render()` call and the
     * `box()` frame, which repeats the same border-plus-padding geometry.
     *
     * Only `clipWidth(clipTail(...), $cols)` — and there are two such `$frame`
     * assignments in the shell renderer, not one — kept the over-run off the
     * screen. Backstops are not budgets.
     */
    public function testTheAgentDashboardPaneFitsTheOutsideWidthItWasHanded(): void
    {
        $provider = $this->createMock(ProviderInterface::class);
        $manager = new AgentManager($provider, new SkillRegistry());
        $manager->register(new Agent(
            name: 'reviewer',
            description: 'Reviews code for bugs',
            prompt: 'You are a reviewer.',
            model: 'claude-sonnet-4-6',
            provider: 'anthropic',
            tools: [],
            skillNames: [],
            hooks: [],
            isActive: true,
        ));

        $populated = App::new($provider, 'test-model')
            ->withPane(Pane::Agents)
            ->withChat(new Chat(agentManager: $manager));
        $empty = App::new($provider, 'test-model')
            ->withPane(Pane::Agents)
            ->withChat(new Chat());

        foreach ([30, 60, 100] as $width) {
            foreach (['populated' => $populated, 'empty' => $empty] as $label => $app) {
                $rows = explode("\n", AgentDashboardPane::render($app, $width, 12));
                $widest = max(array_map(static fn(string $row): int => Width::string($row), $rows));

                $this->assertSame(
                    $width,
                    $widest,
                    sprintf('%s dashboard at pane width %d was %d cells wide', $label, $width, $widest),
                );
            }
        }
    }

    /**
     * Naming the overhead must not move a single byte of what the
     * in-transcript strip already draws.
     *
     * `renderAgentView()` passed `max(40, $cols - 4)` and now passes
     * `AgentViewPane::contentWidth($cols, 40)`, which is the same arithmetic —
     * but "the same arithmetic" is exactly the sort of claim this project
     * keeps catching itself getting wrong, so it is pinned against output
     * captured from the pre-change tree rather than argued from the source.
     *
     * 60 columns and up, NOT 44. This test was written over 44/60/80/120 and
     * 44 has since moved — see
     * {@see testRenderAgentViewAt44ColumnsMovedExactlyOnceAndThatWasE64()},
     * which pins both halves of that move. 44 is the boundary where
     * `contentWidth($cols, 40)` is exact, so the strip there is handed a
     * content width of 40, which is inside the range E64's operation floor
     * was breaking; the three wider captures are outside it and are still
     * byte-for-byte what the pre-CHROME_WIDTH tree drew.
     *
     * BELOW 44 the `max(40, …)` floor still makes the strip wider than the
     * terminal — unchanged, on purpose — and is still left to `clipWidth()`.
     * This test therefore says nothing about widths under 44, and the name
     * says so.
     */
    public function testRenderAgentViewIsByteIdenticalAtAndAbove60Columns(): void
    {
        $provider = $this->createMock(ProviderInterface::class);
        $manager = new AgentManager($provider, new SkillRegistry());
        $manager->register(new Agent(
            name: 'reviewer',
            description: 'Reviews code for bugs',
            prompt: 'You are a reviewer.',
            model: 'claude-sonnet-4-6',
            provider: 'anthropic',
            tools: [],
            skillNames: [],
            hooks: [],
            isActive: true,
        ));

        $render = new \ReflectionMethod(Renderer::class, 'renderAgentView');

        foreach (self::preChangeFrames() as $cols => $expected) {
            if ($cols < 60) {
                continue;
            }

            $chat = (new Chat(agentManager: $manager))->withSize($cols, 40);

            $this->assertSame(
                $expected,
                base64_encode((string) $render->invoke(null, $chat)),
                sprintf('the in-transcript agent strip changed at %d columns', $cols),
            );
        }
    }

    /**
     * The one width of the four where the in-transcript strip DID move, and
     * both halves of the move, so neither can be quietly walked back.
     *
     * At 44 columns `contentWidth(44, 40)` hands `AgentViewPane::render()` a
     * content width of 40, and 40 is inside the band E64's operation floor was
     * breaking: 17 cells of identity plus a 5-cell operation plus 24 cells of
     * `0s  0 tok | $0.0000` is 46, which does not fit 40. The strip drew
     * `● reviewer [working]  Revi…0s  0 tok | $` — the usage column clipped
     * mid-token, with no gap before it and a cost that reads as `$` rather
     * than as truncated. It now drops the usage column it cannot fit and
     * right-aligns what remains: `● reviewer [working]  Revi…          0s`.
     *
     * Asserted against BOTH captures rather than just the new one, because
     * "this is what it draws now" alone would pass just as happily if the
     * clamp were reverted and the capture re-taken.
     */
    public function testRenderAgentViewAt44ColumnsMovedExactlyOnceAndThatWasE64(): void
    {
        $provider = $this->createMock(ProviderInterface::class);
        $manager = new AgentManager($provider, new SkillRegistry());
        $manager->register(new Agent(
            name: 'reviewer',
            description: 'Reviews code for bugs',
            prompt: 'You are a reviewer.',
            model: 'claude-sonnet-4-6',
            provider: 'anthropic',
            tools: [],
            skillNames: [],
            hooks: [],
            isActive: true,
        ));

        $render = new \ReflectionMethod(Renderer::class, 'renderAgentView');
        $chat = (new Chat(agentManager: $manager))->withSize(44, 40);
        $actual = base64_encode((string) $render->invoke(null, $chat));

        $this->assertNotSame(
            self::preChangeFrames()[44],
            $actual,
            'the 44-column strip is back to its pre-E64 bytes',
        );
        $this->assertSame(self::postE64Frame44(), $actual, 'the 44-column strip moved again');

        // The BOXED row only. `renderAgentView()`'s frame opens with a
        // separate one-line zone-marked strip that this change does not touch
        // and that still carries the full usage figure -- asserting over the
        // whole frame would be a claim about the pane written against a
        // different thing.
        $lines = explode("\n", self::plain(base64_decode($actual, true) ?: ''));
        $boxed = array_values(array_filter($lines, static fn(string $l): bool => str_contains($l, "\u{2502}")));
        $this->assertCount(1, $boxed, 'the pane draws exactly one agent row here');
        $this->assertStringContainsString('reviewer [working]', $boxed[0]);
        $this->assertStringNotContainsString('tok', $boxed[0], 'the usage column does not fit 40 content cells');
        $this->assertSame(44, Width::string($boxed[0]));
    }

    /**
     * `renderAgentView()` at 44 columns as it stands with E64 fixed, base64
     * for the same reason {@see preChangeFrames()} is.
     */
    private static function postE64Frame44(): string
    {
        return '7oCAcGFuZTphZ2VudHPugIEbWzM4OzI7MTYwOzIxNjsxNjBt4pePG1swbSAbWzFtG1szODsyOzE2MDsyMTY7MTYwbXJldmlld2VyG1swbSAbWzM4OzI7MTYwOzIxNjsxNjBtW3dvcmtpbmddG1swbSAbWzM4OzI7MTk3OzIwMTsyMTJtUmV2aWV3cyBjb2RlIGZvciBidWdzG1swbSAbWzM4OzI7MTM5OzE0MzsxNjhtMHMbWzBtIBtbMzg7MjsxMzk7MTQzOzE2OG0wIHRvayB8ICQwLjAwMDAbWzBt7oCAL3BhbmU6YWdlbnRz7oCBCuKVrSBhZ2VudHMg4pSA4pSA4pSA4pSA4pSA4pSA4pSA4pSA4pSA4pSA4pSA4pSA4pSA4pSA4pSA4pSA4pSA4pSA4pSA4pSA4pSA4pSA4pSA4pSA4pSA4pSA4pSA4pSA4pSA4pSA4pSA4pSA4pSA4pSA4pWuCuKUgiAbWzM4OzI7MTM5OzE0MzsxNjhtG1szODsyOzE2MDsyMTY7MTYwbeKXjxtbMG0gcmV2aWV3ZXIgW3dvcmtpbmddICBSZXZp4oCmICAgICAgICAgIBtbMG0bWzM4OzI7MTM5OzE0MzsxNjhtMHMbWzBtICDilIIK4pWw4pSA4pSA4pSA4pSA4pSA4pSA4pSA4pSA4pSA4pSA4pSA4pSA4pSA4pSA4pSA4pSA4pSA4pSA4pSA4pSA4pSA4pSA4pSA4pSA4pSA4pSA4pSA4pSA4pSA4pSA4pSA4pSA4pSA4pSA4pSA4pSA4pSA4pSA4pSA4pSA4pSA4pSA4pWv';
    }

    /**
     * Output of `Renderer::renderAgentView()` captured from the tree as it
     * stood BEFORE the chrome constant was introduced, base64 so the SGR bytes
     * and the private-use zone sentinels survive the source file intact.
     *
     * Still all four widths. 60/80/120 are what the strip draws today; 44 is
     * kept as the BEFORE half of
     * {@see testRenderAgentViewAt44ColumnsMovedExactlyOnceAndThatWasE64()},
     * which is why the byte-identity sweep skips it rather than this array
     * dropping it.
     *
     * @return array<int, string>
     */
    private static function preChangeFrames(): array
    {
        return [
            44 => '7oCAcGFuZTphZ2VudHPugIEbWzM4OzI7MTYwOzIxNjsxNjBt4pePG1swbSAbWzFtG1szODsyOzE2MDsyMTY7MTYwbXJldmlld2VyG1swbSAbWzM4OzI7MTYwOzIxNjsxNjBtW3dvcmtpbmddG1swbSAbWzM4OzI7MTk3OzIwMTsyMTJtUmV2aWV3cyBjb2RlIGZvciBidWdzG1swbSAbWzM4OzI7MTM5OzE0MzsxNjhtMHMbWzBtIBtbMzg7MjsxMzk7MTQzOzE2OG0wIHRvayB8ICQwLjAwMDAbWzBt7oCAL3BhbmU6YWdlbnRz7oCBCuKVrSBhZ2VudHMg4pSA4pSA4pSA4pSA4pSA4pSA4pSA4pSA4pSA4pSA4pSA4pSA4pSA4pSA4pSA4pSA4pSA4pSA4pSA4pSA4pSA4pSA4pSA4pSA4pSA4pSA4pSA4pSA4pSA4pSA4pSA4pSA4pSA4pSA4pWuCuKUgiAbWzM4OzI7MTM5OzE0MzsxNjhtG1szODsyOzE2MDsyMTY7MTYwbeKXjxtbMG0gcmV2aWV3ZXIgW3dvcmtpbmddICBSZXZp4oCmG1swbRtbMzg7MjsxMzk7MTQzOzE2OG0wcyAgMCB0b2sgfCAkG1swbSDilIIK4pWw4pSA4pSA4pSA4pSA4pSA4pSA4pSA4pSA4pSA4pSA4pSA4pSA4pSA4pSA4pSA4pSA4pSA4pSA4pSA4pSA4pSA4pSA4pSA4pSA4pSA4pSA4pSA4pSA4pSA4pSA4pSA4pSA4pSA4pSA4pSA4pSA4pSA4pSA4pSA4pSA4pSA4pSA4pWv',
            60 => '7oCAcGFuZTphZ2VudHPugIEbWzM4OzI7MTYwOzIxNjsxNjBt4pePG1swbSAbWzFtG1szODsyOzE2MDsyMTY7MTYwbXJldmlld2VyG1swbSAbWzM4OzI7MTYwOzIxNjsxNjBtW3dvcmtpbmddG1swbSAbWzM4OzI7MTk3OzIwMTsyMTJtUmV2aWV3cyBjb2RlIGZvciBidWdzG1swbSAbWzM4OzI7MTM5OzE0MzsxNjhtMHMbWzBtIBtbMzg7MjsxMzk7MTQzOzE2OG0wIHRvayB8ICQwLjAwMDAbWzBt7oCAL3BhbmU6YWdlbnRz7oCBCuKVrSBhZ2VudHMg4pSA4pSA4pSA4pSA4pSA4pSA4pSA4pSA4pSA4pSA4pSA4pSA4pSA4pSA4pSA4pSA4pSA4pSA4pSA4pSA4pSA4pSA4pSA4pSA4pSA4pSA4pSA4pSA4pSA4pSA4pSA4pSA4pSA4pSA4pSA4pSA4pSA4pSA4pSA4pSA4pSA4pSA4pSA4pSA4pSA4pSA4pSA4pSA4pSA4pSA4pWuCuKUgiAbWzM4OzI7MTM5OzE0MzsxNjhtG1szODsyOzE2MDsyMTY7MTYwbeKXjxtbMG0gcmV2aWV3ZXIgW3dvcmtpbmddICBSZXZp4oCmICAgICAgICAgG1swbRtbMzg7MjsxMzk7MTQzOzE2OG0wcyAgMCB0b2sgfCAkMC4wMDAwG1swbSAg4pSCCuKVsOKUgOKUgOKUgOKUgOKUgOKUgOKUgOKUgOKUgOKUgOKUgOKUgOKUgOKUgOKUgOKUgOKUgOKUgOKUgOKUgOKUgOKUgOKUgOKUgOKUgOKUgOKUgOKUgOKUgOKUgOKUgOKUgOKUgOKUgOKUgOKUgOKUgOKUgOKUgOKUgOKUgOKUgOKUgOKUgOKUgOKUgOKUgOKUgOKUgOKUgOKUgOKUgOKUgOKUgOKUgOKUgOKUgOKUgOKVrw==',
            80 => '7oCAcGFuZTphZ2VudHPugIEbWzM4OzI7MTYwOzIxNjsxNjBt4pePG1swbSAbWzFtG1szODsyOzE2MDsyMTY7MTYwbXJldmlld2VyG1swbSAbWzM4OzI7MTYwOzIxNjsxNjBtW3dvcmtpbmddG1swbSAbWzM4OzI7MTk3OzIwMTsyMTJtUmV2aWV3cyBjb2RlIGZvciBidWdzG1swbSAbWzM4OzI7MTM5OzE0MzsxNjhtMHMbWzBtIBtbMzg7MjsxMzk7MTQzOzE2OG0wIHRvayB8ICQwLjAwMDAbWzBt7oCAL3BhbmU6YWdlbnRz7oCBCuKVrSBhZ2VudHMg4pSA4pSA4pSA4pSA4pSA4pSA4pSA4pSA4pSA4pSA4pSA4pSA4pSA4pSA4pSA4pSA4pSA4pSA4pSA4pSA4pSA4pSA4pSA4pSA4pSA4pSA4pSA4pSA4pSA4pSA4pSA4pSA4pSA4pSA4pSA4pSA4pSA4pSA4pSA4pSA4pSA4pSA4pSA4pSA4pSA4pSA4pSA4pSA4pSA4pSA4pSA4pSA4pSA4pSA4pSA4pSA4pSA4pSA4pSA4pSA4pSA4pSA4pSA4pSA4pSA4pSA4pSA4pSA4pSA4pSA4pWuCuKUgiAbWzM4OzI7MTM5OzE0MzsxNjhtG1szODsyOzE2MDsyMTY7MTYwbeKXjxtbMG0gcmV2aWV3ZXIgW3dvcmtpbmddICBSZXZpZXdz4oCmICAgICAgICAgICAgICAgICAgICAgICAgICAbWzBtG1szODsyOzEzOTsxNDM7MTY4bTBzICAwIHRvayB8ICQwLjAwMDAbWzBtICDilIIK4pWw4pSA4pSA4pSA4pSA4pSA4pSA4pSA4pSA4pSA4pSA4pSA4pSA4pSA4pSA4pSA4pSA4pSA4pSA4pSA4pSA4pSA4pSA4pSA4pSA4pSA4pSA4pSA4pSA4pSA4pSA4pSA4pSA4pSA4pSA4pSA4pSA4pSA4pSA4pSA4pSA4pSA4pSA4pSA4pSA4pSA4pSA4pSA4pSA4pSA4pSA4pSA4pSA4pSA4pSA4pSA4pSA4pSA4pSA4pSA4pSA4pSA4pSA4pSA4pSA4pSA4pSA4pSA4pSA4pSA4pSA4pSA4pSA4pSA4pSA4pSA4pSA4pSA4pSA4pWv',
            120 => '7oCAcGFuZTphZ2VudHPugIEbWzM4OzI7MTYwOzIxNjsxNjBt4pePG1swbSAbWzFtG1szODsyOzE2MDsyMTY7MTYwbXJldmlld2VyG1swbSAbWzM4OzI7MTYwOzIxNjsxNjBtW3dvcmtpbmddG1swbSAbWzM4OzI7MTk3OzIwMTsyMTJtUmV2aWV3cyBjb2RlIGZvciBidWdzG1swbSAbWzM4OzI7MTM5OzE0MzsxNjhtMHMbWzBtIBtbMzg7MjsxMzk7MTQzOzE2OG0wIHRvayB8ICQwLjAwMDAbWzBt7oCAL3BhbmU6YWdlbnRz7oCBCuKVrSBhZ2VudHMg4pSA4pSA4pSA4pSA4pSA4pSA4pSA4pSA4pSA4pSA4pSA4pSA4pSA4pSA4pSA4pSA4pSA4pSA4pSA4pSA4pSA4pSA4pSA4pSA4pSA4pSA4pSA4pSA4pSA4pSA4pSA4pSA4pSA4pSA4pSA4pSA4pSA4pSA4pSA4pSA4pSA4pSA4pSA4pSA4pSA4pSA4pSA4pSA4pSA4pSA4pSA4pSA4pSA4pSA4pSA4pSA4pSA4pSA4pSA4pSA4pSA4pSA4pSA4pSA4pSA4pSA4pSA4pSA4pSA4pSA4pSA4pSA4pSA4pSA4pSA4pSA4pSA4pSA4pSA4pSA4pSA4pSA4pSA4pSA4pSA4pSA4pSA4pSA4pSA4pSA4pSA4pSA4pSA4pSA4pSA4pSA4pSA4pSA4pSA4pSA4pSA4pSA4pSA4pSA4pSA4pSA4pSA4pSA4pSA4pSA4pWuCuKUgiAbWzM4OzI7MTM5OzE0MzsxNjhtG1szODsyOzE2MDsyMTY7MTYwbeKXjxtbMG0gcmV2aWV3ZXIgW3dvcmtpbmddICBSZXZpZXdzIGNvZGUgZm9yIGJ1Z3MgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgIBtbMG0bWzM4OzI7MTM5OzE0MzsxNjhtMHMgIDAgdG9rIHwgJDAuMDAwMBtbMG0gIOKUggrilbDilIDilIDilIDilIDilIDilIDilIDilIDilIDilIDilIDilIDilIDilIDilIDilIDilIDilIDilIDilIDilIDilIDilIDilIDilIDilIDilIDilIDilIDilIDilIDilIDilIDilIDilIDilIDilIDilIDilIDilIDilIDilIDilIDilIDilIDilIDilIDilIDilIDilIDilIDilIDilIDilIDilIDilIDilIDilIDilIDilIDilIDilIDilIDilIDilIDilIDilIDilIDilIDilIDilIDilIDilIDilIDilIDilIDilIDilIDilIDilIDilIDilIDilIDilIDilIDilIDilIDilIDilIDilIDilIDilIDilIDilIDilIDilIDilIDilIDilIDilIDilIDilIDilIDilIDilIDilIDilIDilIDilIDilIDilIDilIDilIDilIDilIDilIDilIDilIDila8=',
        ];
    }
}
