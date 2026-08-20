<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Tui;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\Util\Ansi;
use SugarCraft\Core\Util\Width;
use SugarCraft\Crush\Agents\Agent;
use SugarCraft\Crush\Agents\AgentManager;
use SugarCraft\Crush\Agents\SubAgent;
use SugarCraft\Crush\App\App;
use SugarCraft\Crush\Chat;
use SugarCraft\Crush\Providers\ProviderInterface;
use SugarCraft\Crush\Skills\SkillRegistry;
use SugarCraft\Crush\Theme;
use SugarCraft\Crush\Tui\Components\AgentSplitColumn;
use SugarCraft\Crush\Tui\Pane;
use SugarCraft\Crush\Tui\Renderer;
use SugarCraft\Crush\Tui\SplitDirection;

/**
 * The split-pane compositor, wired (crush_code.md Phase 8 item 4).
 *
 * Three things are under test and they fail for three different reasons:
 *
 * - ACTIVATION. `liveOutputs()` non-empty and a wide enough terminal, nothing
 *   else. A test that only asserted "the tile is present when an agent is
 *   talking" would pass just as happily if the split were permanently on, so
 *   the silent-agent and narrow-terminal cases are asserted as hard absences.
 * - WIDTH ARITHMETIC. Every assertion here is on a MEASURED cell count or on a
 *   specific box-drawing character landing in a specific column — never on
 *   "the output contains a divider", which the pane borders satisfy on their
 *   own. The rounded box's top-right corner sitting at exactly the last column
 *   is the assertion that dies when a border goes missing from the sum: an
 *   under-counted chrome allowance makes the tile too wide and the frame clip
 *   eats the corner, so presence-of-content would still pass.
 * - DIRECTION. A horizontal split puts the agent tile BELOW the band, on rows
 *   that start at column 0. The corner-column assertion is what distinguishes
 *   that from the vertical layout actually asked for.
 * - REACHABILITY. The activation source has to see the agents production
 *   actually produces, and stop seeing them when they stop. Both are asserted
 *   against the WORKFLOW shape — an agent that was never registered — because
 *   that is the only shape a real run has.
 *
 * ## What the assertion count here is and is not
 *
 * Stated because a big number invites the wrong inference. Roughly 90% of this
 * file's assertions come from two loops: the 221-width sweep in
 * {@see testTheShellBandNeverDropsBelowItsFloorWhereverTheSplitActivates()} and
 * the per-line width scan in
 * {@see testNoFrameLineExceedsTheTerminalWidthAtAnyWidth()}. PHPUnit 10 also
 * counts `assertLessThanOrEqual`/`assertGreaterThanOrEqual` as TWO each (they
 * are composite `LogicalOr` constraints), so the reported figure is close to
 * double the number of assert CALLS.
 *
 * The sweep is worth keeping and is not worth mistaking for coverage: it buys
 * four distinct properties (a split at every width from 80 to 300, none below
 * 80, the band never starved, every tile row exactly `cols` wide), and its
 * band-floor check is monotone — only the minimum, at `cols = 80`, is
 * load-bearing. What it deliberately does NOT pin is the sizing policy: it
 * asserts `band + 1 + column == cols`, which holds for any divisor.
 * {@see testAgentColumnWidthIsTheFlooredThirdNotTheRoundedOne()} is the two
 * assertions that do pin it, and mutating `intdiv($cols, 3)` to
 * `(int) round($cols / 3)` survives every other test in this file.
 *
 * @see \SugarCraft\Crush\Tui\Renderer::renderView()
 * @see \SugarCraft\Crush\Tui\Components\AgentSplitColumn
 * @see \SugarCraft\Crush\Agents\AgentManager::liveOutputs()
 */
final class AgentSplitCompositorTest extends TestCase
{
    /** Rounded border top-right corner, {@see \SugarCraft\Sprinkles\Border::rounded()}. */
    private const CORNER_TOP_RIGHT = "\u{256E}";

    /** Rounded border top-left corner. */
    private const CORNER_TOP_LEFT = "\u{256D}";

    private ProviderInterface $provider;

    protected function setUp(): void
    {
        parent::setUp();
        Renderer::resetSizeCache();
        $this->provider = $this->createMock(ProviderInterface::class);
        $this->provider->method('name')->willReturn('TestProvider');
    }

    protected function tearDown(): void
    {
        Renderer::resetSizeCache();
        parent::tearDown();
    }

    // =====================================================================
    // Activation policy
    // =====================================================================

    public function testSplitAppearsWhenARegisteredAgentHasLiveOutput(): void
    {
        $frame = $this->frame($this->appWithLiveAgent('reviewer', "scanning src\nfound 3 issues"), 120, 40);

        self::assertStringContainsString('found 3 issues', $frame);
        self::assertStringContainsString('reviewer', $frame);
    }

    public function testNoSplitWhenEveryRegisteredAgentIsSilent(): void
    {
        // The agent exists and has a sub-agent; the sub-agent has produced
        // nothing, which is exactly what liveOutputs() omits.
        //
        // The bare name is NOT the discriminator and asserting on it would be
        // a false negative waiting to happen: the hosted chat's own agent
        // strip prints "● reviewer [working]" for any REGISTERED agent, split
        // or no split. The tile — a rounded box titled with the agent's
        // name — is the thing only the compositor draws.
        $frame = $this->frame($this->appWithLiveAgent('reviewer', ''), 120, 40);

        self::assertNull($this->tileTopBorder($frame));
        self::assertStringNotContainsString('found 3 issues', $frame);
    }

    public function testTheSplitIsWhatNarrowsTheChatColumn(): void
    {
        // The absence assertions above would all still pass if the compositor
        // drew its tile over a band that never shrank. The chat box's own
        // right edge is the measurement that says the band was re-laid out.
        $silent = $this->chatBoxRightEdge($this->frame($this->appWithLiveAgent('reviewer', ''), 120, 40));
        $live = $this->chatBoxRightEdge($this->frame($this->appWithLiveAgent('reviewer', "scanning src\nfound 3 issues"), 120, 40));

        self::assertSame(119, $silent, 'Without a split the chat box must reach the last column.');
        self::assertSame(78, $live, 'With a 40-cell agent column and a 1-cell divider the band ends at 78.');
    }

    public function testTheSidebarIsAQuarterOfTheBandNotAQuarterOfTheTerminal(): void
    {
        // `leftSidebar()` takes a quarter of what it is GIVEN, so handing it
        // the terminal width instead of the band width leaves it sized for a
        // layout that is not being drawn — 34 cells inside a 79-cell band, 43%
        // of it rather than 30%. The frame stays exactly `cols` wide either
        // way, so only the sidebar's own right edge shows the difference.
        $silent = $this->boxRightEdge($this->frame($this->appWithLiveAgent('reviewer', ''), 120, 40), "\u{256D} files ");
        $live = $this->boxRightEdge($this->frame($this->appWithLiveAgent('reviewer', "scanning src\nfound 3 issues"), 120, 40), "\u{256D} files ");

        self::assertSame(33, $silent, 'Unsplit, the sidebar is a quarter of 120 plus its chrome.');
        self::assertSame(23, $live, 'Split, the sidebar must re-lay out against the 79-cell band.');
    }

    public function testNoSplitWithoutAnAgentManager(): void
    {
        $frame = $this->frame($this->appWithoutAgents(), 120, 40);

        self::assertStringNotContainsString('found 3 issues', $frame);
    }

    public function testAgentDashboardPaneIsNeverSplit(): void
    {
        // Pane::Agents is already the full-width live-agent view; splitting it
        // would show the same buffers twice, and renderView() diverts before
        // the compositor is reached.
        $app = $this->appWithLiveAgent('reviewer', "scanning src\nfound 3 issues")
            ->withPane(Pane::Agents);

        $frame = $this->frame($app, 120, 40);

        self::assertNull($this->tileTopBorder($frame));
    }

    /**
     * The split has to activate for an agent that was NEVER REGISTERED,
     * because that is the only kind a real run produces.
     *
     * {@see \SugarCraft\Crush\Workflows\WorkflowEngine::executeParallelStage()}
     * builds ad-hoc `Agent`s named `$task->name ?? $task->agentType`
     * (`WorkflowEngine.php:1254`) and hands the `SubAgent`s to
     * `AgentManager::executeAll()`, which files them under the SUB-AGENT map
     * and never calls `register()`. `liveOutputs()` used to iterate the
     * registered map, so this frame had no split at all — every other
     * activation test in this file registered its agent and so could not see
     * it.
     */
    public function testSplitAppearsForAWorkflowSpawnedAgentThatWasNeverRegistered(): void
    {
        $manager = $this->managerWithWorkflowAgent('style-fixer', 'rewriting Foo.php');
        $app = App::new($this->provider, 'test-model')->withChat(new Chat(agentManager: $manager));

        $this->assertNull($manager->get('style-fixer'), 'never registered, by construction');

        $frame = $this->frame($app, 120, 40);

        self::assertNotNull(
            $this->tileTopBorder($frame, 'style-fixer'),
            'A workflow-spawned agent must open the split.',
        );
        self::assertStringContainsString('rewriting Foo.php', $frame);
    }

    /**
     * And it has to close again. Nothing clears `SubAgent::$output`, so an
     * activation policy that filtered only on "has text" opened a column on the
     * session's first workflow and never took it down.
     */
    public function testSplitClosesOnceTheAgentReachesATerminalState(): void
    {
        $manager = $this->managerWithWorkflowAgent('style-fixer', 'rewriting Foo.php');
        $app = App::new($this->provider, 'test-model')->withChat(new Chat(agentManager: $manager));

        self::assertNotNull($this->tileTopBorder($this->frame($app, 120, 40), 'style-fixer'));

        foreach ($manager->subAgentsOf('style-fixer') as $subAgent) {
            $subAgent->status = SubAgent::STATUS_COMPLETE;
        }

        $frame = $this->frame($app, 120, 40);
        self::assertNull($this->tileTopBorder($frame, 'style-fixer'), 'A finished agent must not hold the column open.');
        self::assertStringNotContainsString('rewriting Foo.php', $frame);
        self::assertSame(119, $this->chatBoxRightEdge($frame), 'The band must go back to the full width.');
    }

    // =====================================================================
    // Width arithmetic
    // =====================================================================

    public function testTileTopRightCornerLandsOnTheTerminalsLastColumn(): void
    {
        // The whole border sum in one assertion: band + 1 divider + agent
        // column == cols, and the agent column is wide enough for the tile's
        // chrome. Drop a border anywhere and the corner is clipped away.
        foreach ([80, 100, 120, 200] as $cols) {
            $frame = $this->frame($this->appWithLiveAgent('reviewer', "scanning src\nfound 3 issues"), $cols, 40);

            $tile = $this->tileTopBorder($frame);
            self::assertNotNull($tile, "No agent tile top border rendered at cols={$cols}.");

            self::assertSame(
                $cols,
                Width::string($tile['line']),
                "Tile top-border row must be exactly {$cols} cells wide.",
            );
            self::assertSame(
                self::CORNER_TOP_RIGHT,
                mb_substr(rtrim($tile['line']), -1),
                "Tile's top-right corner must be the row's last cell at cols={$cols}.",
            );
        }
    }

    public function testTileIsToTheRightOfTheShellBandNotBelowIt(): void
    {
        // A horizontal split would put the tile on its own rows starting at
        // column 0; a vertical one leaves shell content to its left.
        $frame = $this->frame($this->appWithLiveAgent('reviewer', "scanning src\nfound 3 issues"), 120, 40);

        $tile = $this->tileTopBorder($frame);
        self::assertNotNull($tile, 'No agent tile top border rendered.');
        self::assertGreaterThan(
            0,
            $tile['col'],
            'The agent tile must begin past column 0 — a tile at column 0 means the split went horizontal.',
        );
        self::assertStringContainsString(
            'chat',
            mb_substr($tile['line'], 0, $tile['col']),
            'The shell band must occupy the cells to the tile’s left.',
        );
    }

    public function testNoFrameLineExceedsTheTerminalWidthAtAnyWidth(): void
    {
        // The render invariant the compositor is most likely to break: the
        // diff renderer paints one line per row, so an over-wide row wraps and
        // desynchronises everything below it.
        $app = $this->appWithLiveAgent('reviewer', "scanning src\nfound 3 issues\nand another line\nand one more");

        foreach ([40, 60, 79, 80, 81, 100, 121, 180, 181, 240] as $cols) {
            $frame = $this->frame($app, $cols, 30);
            foreach (explode("\n", $frame) as $i => $line) {
                self::assertLessThanOrEqual(
                    $cols,
                    Width::string($this->stripped($line)),
                    "Line {$i} exceeds {$cols} cells.",
                );
            }
            self::assertLessThanOrEqual(30, substr_count($frame, "\n") + 1, "Frame taller than 30 rows at cols={$cols}.");
        }
    }

    public function testAnOverWideBandIsClippedAnsiAwareSoNoStyleLeaksAcrossTheDivider(): void
    {
        // Reachability, stated plainly: no shell pane produces a band wider
        // than its budget today, so this drives `composeAgentSplit()` directly
        // rather than through `renderView()`. The clip is a guard against a
        // pane that mis-sizes itself — `AgentViewPane` demonstrably does, E54
        // in `docs/plans/crush_code_hardening_backlog.md` — and an untested
        // guard is a guard that quietly stops working.
        //
        // {@see SplitLayout} has a truncator of its own, so the row comes out
        // the right WIDTH either way and a width assertion alone would pass on
        // a broken clip. What only the ANSI-aware clip gets right is the SGR
        // state at the divider: SplitLayout's own truncator walks raw
        // codepoints, so it cuts the trailing reset off and the band's colour
        // paints the divider and bleeds into the agent column.
        $band = "\u{1b}[31m" . str_repeat('X', 400) . "\u{1b}[0m";

        $row = explode("\n", $this->composeAgentSplit(
            $this->appWithLiveAgent('reviewer', "scanning src\nfound 3 issues"),
            $band,
            bandCols: 79,
            agentCols: 40,
            cols: 120,
            rows: 6,
        ))[0];

        $beforeDivider = explode("\u{2502}", $row, 2)[0];

        self::assertSame(79, Width::string($this->stripped($beforeDivider)), 'Band must be cut to its budget.');
        self::assertStringEndsWith(
            "\u{1b}[0m",
            rtrim($beforeDivider),
            'The band must hand the divider a reset SGR state, not an open colour.',
        );
        self::assertLessThanOrEqual(120, Width::string($this->stripped($row)));
    }

    public function testWideCharacterOutputStillFitsTheColumn(): void
    {
        // Cell width, not byte or codepoint count: CJK is 2 cells per glyph
        // and combining accents are 0, and a pad that counted either wrong
        // pushes the divider off its column.
        $app = $this->appWithLiveAgent('reviewer', "日本語のテキストがここにあります\ne\u{0301}e\u{0301}e\u{0301} accents");

        $frame = $this->frame($app, 100, 30);
        foreach (explode("\n", $frame) as $line) {
            self::assertLessThanOrEqual(100, Width::string($this->stripped($line)));
        }
    }

    // =====================================================================
    // Narrow-terminal edges
    // =====================================================================

    public function testNoSplitBelowTheMinimumTerminalWidth(): void
    {
        $app = $this->appWithLiveAgent('reviewer', "scanning src\nfound 3 issues");

        self::assertStringNotContainsString('found 3 issues', $this->frame($app, 79, 40));
        self::assertStringContainsString('found 3 issues', $this->frame($app, 80, 40));
    }

    public function testTheShellBandNeverDropsBelowItsFloorWhereverTheSplitActivates(): void
    {
        // Pins SPLIT_MIN_TOTAL_COLS against the band floor across the whole
        // range rather than at the two widths that happen to be interesting:
        // wherever a split activates, the shell band must still hold a sidebar
        // (20) plus a chat column (24).
        $app = $this->appWithLiveAgent('reviewer', "scanning src\nfound 3 issues");

        $activated = 0;
        for ($cols = 20; $cols <= 300; $cols += 1) {
            $tile = $this->tileTopBorder($this->frame($app, $cols, 30));
            if ($tile === null) {
                self::assertLessThan(80, $cols, "Split declined at cols={$cols}, which is over the floor.");

                continue;
            }

            $activated++;
            // The tile starts one cell past the divider, so the band is the
            // cells before it minus that divider.
            $bandCols = $tile['col'] - 1;
            self::assertGreaterThanOrEqual(44, $bandCols, "Shell band starved at cols={$cols}.");
            self::assertSame($cols, Width::string($tile['line']), "Tile row mis-sized at cols={$cols}.");
        }

        self::assertSame(221, $activated, 'The split must activate at every width from 80 to 300.');
    }

    public function testAgentColumnIsCappedOnAVeryWideTerminal(): void
    {
        // Without the cap a 300-column terminal would hand 100 columns to a
        // four-line peek tile and take them off the transcript.
        $tile = $this->tileTopBorder($this->frame($this->appWithLiveAgent('reviewer', "scanning src\nfound 3 issues"), 300, 30));

        self::assertNotNull($tile, 'No agent tile rendered at 300 columns.');
        self::assertSame(300 - 60, $tile['col'], 'Agent column must be capped at 60 cells.');
    }

    /**
     * The agent column is `intdiv($cols, 3)`, not `round($cols / 3)`.
     *
     * Two assertions, at the two widths where the two divisors disagree — the
     * mutation survives every other test in this file, including the 221-width
     * sweep, because that sweep pins the SUM (`band + 1 + column == cols`) and
     * a sum holds under any sizing policy. `cols = 80` is the one that matters
     * most: it is {@see \SugarCraft\Crush\Tui\Renderer::SPLIT_MIN_TOTAL_COLS}
     * itself, where `round` would take 27 cells and leave the band at 52.
     *
     * Tile column is `cols - agentCols`, so 80 - 26 = 54 (round: 53) and
     * 101 - 33 = 68 (round: 67).
     */
    public function testAgentColumnWidthIsTheFlooredThirdNotTheRoundedOne(): void
    {
        $app = $this->appWithLiveAgent('reviewer', "scanning src\nfound 3 issues");

        self::assertSame(54, $this->tileTopBorder($this->frame($app, 80, 30))['col'] ?? null);
        self::assertSame(68, $this->tileTopBorder($this->frame($app, 101, 30))['col'] ?? null);
    }

    // =====================================================================
    // AgentSplitColumn in isolation
    // =====================================================================

    public function testColumnHoldsItsExactWidthBudget(): void
    {
        $manager = $this->managerWithLiveAgent('reviewer', "scanning src\nfound 3 issues");

        foreach ([24, 30, 40, 60] as $width) {
            $column = AgentSplitColumn::render($manager->liveOutputs(), $manager, Theme::default(), $width, 20);

            $widest = 0;
            foreach (explode("\n", $column) as $line) {
                $widest = max($widest, Width::string($this->stripped($line)));
            }

            self::assertSame($width, $widest, "Column must fill exactly {$width} cells.");
            self::assertSame(
                self::CORNER_TOP_RIGHT,
                mb_substr(rtrim($this->stripped(explode("\n", $column)[0])), -1),
                "Tile border must survive the clip at width {$width} — a wrong chrome allowance eats it.",
            );
        }
    }

    public function testColumnHoldsItsRowBudget(): void
    {
        $manager = $this->managerWithLiveAgent('reviewer', "a\nb\nc\nd\ne\nf");

        foreach ([1, 2, 3, 5, 8, 20] as $rows) {
            $column = AgentSplitColumn::render($manager->liveOutputs(), $manager, Theme::default(), 40, $rows);
            self::assertLessThanOrEqual($rows, substr_count($column, "\n") + 1, "Column taller than {$rows} rows.");
        }
    }

    public function testColumnStopsBeforeSplittingATileAndSaysHowManyItHid(): void
    {
        $manager = $this->managerWithLiveAgents([
            'alpha' => "one\ntwo",
            'beta' => "three\nfour",
            'gamma' => "five\nsix",
        ]);

        // A peek tile of a two-line buffer is 5 rows (2 border + header + 2).
        // 8 rows holds one whole tile and cannot hold two, leaving room for
        // the summary line.
        $column = AgentSplitColumn::render($manager->liveOutputs(), $manager, Theme::default(), 40, 8);
        $plain = $this->stripped($column);

        self::assertStringContainsString('alpha', $plain);
        self::assertStringNotContainsString('beta', $plain);
        self::assertStringContainsString('+ 2 more agent(s)', $plain);
        self::assertLessThanOrEqual(8, substr_count($column, "\n") + 1);
    }

    public function testColumnIsEmptyWithoutLiveOutput(): void
    {
        self::assertSame('', AgentSplitColumn::render([], null, Theme::default(), 40, 20));
    }

    public function testColumnStripsControlBytesAndPrivateUseSentinelsFromAgentOutput(): void
    {
        // Agent output is model- and tool-authored. A CR would return the
        // cursor to column 0 and let the buffer overwrite the tile border; a
        // U+E000 is where candy-core's image markers and candy-mouse's zone
        // sentinels both begin.
        $manager = $this->managerWithLiveAgent('reviewer', "clean\u{E000}text\rOVERWRITE\x1b[2J");

        $column = AgentSplitColumn::render($manager->liveOutputs(), $manager, Theme::default(), 40, 20);
        $plain = $this->stripped($column);

        self::assertStringNotContainsString("\u{E000}", $column);
        self::assertStringNotContainsString("\r", $column);
        self::assertStringNotContainsString('[2J', $plain);
        self::assertStringContainsString('cleantext', $plain);
    }

    // =====================================================================
    // Split entry-point plumbing
    // =====================================================================

    public function testRenderForCurrentEnvironmentHonoursTheCallersSizeWithoutAMultiplexer(): void
    {
        // getTerminalSize()'s cached probe must not win over a WindowSizeMsg
        // size the caller passed in. SplitLayout pads the FIRST pane to its
        // budget and appends the second verbatim, so the divider's column —
        // not the row width — is what the size decides.
        Renderer::setSize(200, 60);

        $tall = implode("\n", array_fill(0, 12, 'L'));
        $out = Renderer::renderForCurrentEnvironment($tall, 'R', SplitDirection::Vertical, 40, 5);

        self::assertSame(20, mb_strpos(explode("\n", $out)[0], "\u{2502}"), 'Divider must sit at half of 40, not half of 200.');
        self::assertSame(5, substr_count($out, "\n") + 1, 'Height must clip to the 5 rows passed, not the 60 cached.');
        foreach (explode("\n", $out) as $line) {
            self::assertLessThanOrEqual(40, Width::string($line));
        }
    }

    public function testRenderForCurrentEnvironmentHonoursTheCallersProportions(): void
    {
        // 30/40 puts the divider at column 30, not at the 1/2 default's 20.
        $out = Renderer::renderForCurrentEnvironment('L', 'R', SplitDirection::Vertical, 40, 3, 30, 40);

        self::assertSame(30, mb_strpos(explode("\n", $out)[0], "\u{2502}"));
    }

    public function testRenderWithSplitHonoursAnExplicitSize(): void
    {
        Renderer::setSize(200, 60);

        $tall = implode("\n", array_fill(0, 12, 'L'));
        $out = Renderer::renderWithSplit($tall, 'R', SplitDirection::Vertical, 1, 2, 30, 4);

        self::assertSame(15, mb_strpos(explode("\n", $out)[0], "\u{2502}"), 'Divider must sit at half of 30, not half of 200.');
        self::assertSame(4, substr_count($out, "\n") + 1, 'Height must clip to the 4 rows passed, not the 60 cached.');
    }

    // =====================================================================
    // Helpers
    // =====================================================================

    private function frame(App $app, int $cols, int $rows): string
    {
        return Renderer::renderView($app, $cols, $rows)->body;
    }

    /** {@see Renderer::composeAgentSplit()}, which is private by design. */
    private function composeAgentSplit(
        App $app,
        string $band,
        int $bandCols,
        int $agentCols,
        int $cols,
        int $rows,
    ): string {
        $method = new \ReflectionMethod(Renderer::class, 'composeAgentSplit');

        return $method->invoke(
            null,
            $app,
            $app->chat?->agentManager()?->liveOutputs() ?? [],
            $band,
            $bandCols,
            $agentCols,
            $cols,
            $rows,
        );
    }

    private function stripped(string $s): string
    {
        return Ansi::strip($s);
    }

    /**
     * The agent tile's top-border row, located by the TITLED corner.
     *
     * A bare `╭` is not specific enough to test on: the files sidebar, the
     * chat box, the input box and the in-transcript agents strip all draw one
     * too, and an earlier draft of this file asserted against the files
     * sidebar's corner while believing it was the tile's. `╭ <name> ` — the
     * corner plus the title {@see \SugarCraft\Crush\Tui\AgentOutputPane}
     * stamps into the border — occurs exactly once per tile.
     *
     * @return array{row: int, col: int, line: string}|null Row index, visual
     *         start column, and the ANSI-stripped row.
     */
    private function tileTopBorder(string $frame, string $name = 'reviewer'): ?array
    {
        foreach (explode("\n", $frame) as $row => $line) {
            $plain = $this->stripped($line);
            $col = mb_strpos($plain, self::CORNER_TOP_LEFT . ' ' . $name . ' ');
            if ($col !== false) {
                return ['row' => $row, 'col' => $col, 'line' => $plain];
            }
        }

        return null;
    }

    /** Visual column of the chat box's right border, from its titled top row. */
    private function chatBoxRightEdge(string $frame): ?int
    {
        return $this->boxRightEdge($frame, "\u{250C} chat ", "\u{2510}");
    }

    /**
     * Visual column where the box whose top row starts with `$titledCorner`
     * closes. Titled corners are used throughout rather than a bare corner
     * because several boxes share a row.
     */
    private function boxRightEdge(string $frame, string $titledCorner, string $closingCorner = "\u{256E}"): ?int
    {
        foreach (explode("\n", $frame) as $line) {
            $plain = $this->stripped($line);
            $start = mb_strpos($plain, $titledCorner);
            if ($start === false) {
                continue;
            }

            $end = mb_strpos($plain, $closingCorner, $start);

            return $end === false ? null : $end;
        }

        return null;
    }

    private function appWithoutAgents(): App
    {
        return App::new($this->provider, 'test-model')->withChat(new Chat());
    }

    private function appWithLiveAgent(string $name, string $output): App
    {
        return App::new($this->provider, 'test-model')
            ->withChat(new Chat(agentManager: $this->managerWithLiveAgent($name, $output)));
    }

    /**
     * A manager holding one live, UNREGISTERED agent — the workflow shape.
     *
     * The SubAgent is filed exactly as {@see AgentManager::executeAll()}'s
     * first loop files it (`AgentManager.php:681`), which is the only thing
     * `WorkflowEngine::executeParallelStage()` does with one.
     */
    private function managerWithWorkflowAgent(string $name, string $output): AgentManager
    {
        $manager = new AgentManager($this->createMock(ProviderInterface::class), new SkillRegistry());

        $subAgent = new SubAgent(
            id: 'fix-1-' . $name,
            agent: new Agent(
                name: $name,
                description: 'fix the style',
                prompt: '',
                model: 'claude-sonnet-4-6',
                provider: 'anthropic',
                tools: [],
                skillNames: [],
                hooks: [],
                isActive: true,
            ),
            task: 'fix the style',
        );
        $subAgent->status = SubAgent::STATUS_STREAMING;
        $subAgent->output = $output;

        $property = new \ReflectionProperty(AgentManager::class, 'subAgents');
        $property->setValue($manager, [$subAgent->id => $subAgent]);

        return $manager;
    }

    private function managerWithLiveAgent(string $name, string $output): AgentManager
    {
        return $this->managerWithLiveAgents([$name => $output]);
    }

    /**
     * @param array<string, string> $outputs
     */
    private function managerWithLiveAgents(array $outputs): AgentManager
    {
        $manager = new AgentManager($this->createMock(ProviderInterface::class), new SkillRegistry());

        foreach ($outputs as $name => $output) {
            $manager->register(new Agent(
                name: $name,
                description: 'Reviews code',
                prompt: 'You are a reviewer.',
                model: 'claude-sonnet-4-6',
                provider: 'anthropic',
                tools: [],
                skillNames: [],
                hooks: [],
                isActive: true,
            ));

            $subAgent = $manager->createSubAgent($name, 'check things');
            $subAgent->output = $output;
        }

        return $manager;
    }
}
