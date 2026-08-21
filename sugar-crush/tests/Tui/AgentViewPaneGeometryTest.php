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
 * the sweeps below now state instead of claiming an invariant: with an ASCII
 * operation it is `+4` at `$width` = 20/28/30/40/43/44/58/60/80/98, populated
 * and empty alike, and with a wide-cluster operation it is `+6` at six of those
 * ten, because `render()`'s `$opBudget = max(5, $width - name - 60)` floor lets
 * the body outgrow the box and the wrap then falls inside a 2-cell cluster.
 * That second half is pre-existing — the same ten numbers come out of
 * `087a3179` — and is recorded as E64; the sweep asserts it. The
 * overhead is now named — {@see AgentViewPane::CHROME_WIDTH} — and both
 * callers subtract it through {@see AgentViewPane::contentWidth()} instead of
 * each writing its own literal, which is how they came to disagree.
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
     * not unconditional: it holds whenever the composed row body fits
     * `$width`, and an ASCII operation always makes it fit here because it
     * truncates to whole cells and wraps on cell boundaries. When the body
     * outgrows the box AND carries a wide cluster, the row comes back wider —
     * which is what {@see
     * testAWideClusterOperationOverrunsTheChromeGeometryAtTheOperationFloor()}
     * measures rather than avoids.
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
     * The same sweep with a wide-cluster operation, which is where the `+4`
     * stops being an invariant — pinned at the bound it actually has.
     *
     * The sweep above ran an ASCII fixture over these widths and came back
     * `+4` at every one, and an ASCII fixture over an ASCII-shaped property is
     * the trap this file was written to avoid elsewhere. Swap in a skin-toned
     * thumb or a flag and 6 of the 10 widths break it.
     *
     * The cause is `render()`'s `$opBudget = max(5, $width - name - 60)`. With
     * a 3-cell name the floor of 5 binds below 69 content columns, so
     * `leftSection` plus `rightSection` can exceed `$width`; `Style::width()`
     * then wraps a body whose 2-cell clusters do not land on the boundary and
     * the wrapped row measures `$width + 6` instead of `$width + 4`. Measured,
     * the excess is exactly +2 and it is gone from 46 columns up.
     *
     * This is PRE-EXISTING and not a regression of the CHROME_WIDTH work:
     * running the same sweep against `087a3179`'s AgentViewPane gives the same
     * ten numbers. It is recorded as **E64**, `clipWidth(clipTail(...))` is
     * still what keeps it off the screen, and this test exists so that a fix
     * to E64 — or a drift in the floor — is visible rather than silent.
     */
    public function testAWideClusterOperationOverrunsTheChromeGeometryAtTheOperationFloor(): void
    {
        // Content width => measured widest row. +6 where the operation floor
        // lets the body outgrow the box, +4 (i.e. CHROME_WIDTH) above it.
        $expected = [
            20 => 26, 28 => 34, 30 => 36, 40 => 46, 43 => 49,
            44 => 50, 58 => 62, 60 => 64, 80 => 84, 98 => 102,
        ];
        $this->assertSame(self::WIDTHS, array_keys($expected), 'the sweep and the expectation map must agree');

        foreach ([self::THUMB, self::FLAG] as $label => $payload) {
            foreach (self::WIDTHS as $width) {
                $agent = AgentDisplayState::new('abc', 'working', str_repeat($payload, 3), 42, 1234, 0.0042);
                $rows = explode("\n", AgentViewPane::render([$agent], 0, $width, 6, self::theme()));
                $widest = max(array_map(static fn(string $row): int => Width::string($row), $rows));

                $this->assertSame(
                    $expected[$width],
                    $widest,
                    sprintf('payload #%d at content width %d: widest row was %d', $label, $width, $widest),
                );
                // Whatever the wrap does, it never costs more than one wide
                // cluster on top of the chrome.
                $this->assertLessThanOrEqual(
                    $width + AgentViewPane::CHROME_WIDTH + 2,
                    $widest,
                    sprintf('payload #%d at content width %d over-ran by more than one wide cluster', $label, $width),
                );
            }
        }
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
     * 44 columns is the boundary the compensation is exact at; the rest are
     * above it. BELOW 44 the `max(40, …)` floor still makes the strip wider
     * than the terminal — unchanged by this commit, on purpose — and is still
     * left to `clipWidth()`. This test therefore says nothing about widths
     * under 44, and the name says so.
     */
    public function testRenderAgentViewIsByteIdenticalAtAndAbove44Columns(): void
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
            $chat = (new Chat(agentManager: $manager))->withSize($cols, 40);

            $this->assertSame(
                $expected,
                base64_encode((string) $render->invoke(null, $chat)),
                sprintf('the in-transcript agent strip changed at %d columns', $cols),
            );
        }
    }

    /**
     * Output of `Renderer::renderAgentView()` captured from the tree as it
     * stood BEFORE the chrome constant was introduced, base64 so the SGR bytes
     * and the private-use zone sentinels survive the source file intact.
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
