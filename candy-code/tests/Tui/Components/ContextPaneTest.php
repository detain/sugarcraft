<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Tui\Components;

use SugarCraft\Core\Util\Ansi;
use SugarCraft\Core\Util\ColorProfile;
use SugarCraft\Core\Util\Width;
use SugarCraft\Code\Context\ContextBreakdown;
use SugarCraft\Code\Context\ContextCategories;
use SugarCraft\Code\Context\ContextCategory;
use SugarCraft\Code\Lang;
use SugarCraft\Code\Theme;
use SugarCraft\Code\Tui\Components\PaneFrame;
use SugarCraft\Code\Tui\Components\ContextPane;
use SugarCraft\Code\Tui\Components\ContextPaneModel;
use SugarCraft\Code\Util\TokenCount;
use PHPUnit\Framework\TestCase;
use SugarCraft\Style\Style;

/**
 * The pure context sidebar box (P-CTX.c): its pins are structural — row
 * budget, width containment over a terminal sweep, the glyph distinctness
 * that carries the categories under NO_COLOR, drill placement, the
 * "measuring…" cold state, and the live-turn override of the Messages row
 * and the total. Colour distinctness is impossible for eleven categories
 * (a palette holds eight or nine distinct colours at best), so the box
 * promises instead what the risk list asks for: adjacent rows never share
 * a colour in any shipped palette, walked live over every theme here.
 */
final class ContextPaneTest extends TestCase
{
    private const BOX_WIDTH = 40;
    private const TALL_ROWS = 40;

    protected function tearDown(): void
    {
        Style::setDefaultProfile(null);
        PaneFrame::resetProfileCacheForTesting();
    }

    public function testCollapsedBoxIsExactlyFourRows(): void
    {
        $frame = ContextPane::renderModel(self::modelWithSnapshot(), Theme::byName('dark'), self::BOX_WIDTH, 4);

        self::assertCount(4, explode("\n", $frame));
    }

    public function testCollapsedShowsBarAndTotalWithExpandMarker(): void
    {
        $model = self::modelWithSnapshot();
        [$frame, $keys] = ContextPane::layoutModel($model, Theme::byName('dark'), self::BOX_WIDTH, 4);
        $body = self::bodyOf($frame);

        self::assertCount(2, $body);
        self::assertSame([null, ContextPane::TOGGLE_KEY], $keys);
        // `81K / 200K · 41% ▸` shape: compact figures, the percent, the ▸.
        $total = trim(Ansi::strip($body[1]), ' │');
        self::assertStringContainsString(TokenCount::compact((int) $model->usedTokens()), $total);
        self::assertStringContainsString(TokenCount::compact(200000), $total);
        self::assertStringContainsString('· ' . $model->percentOfWindow() . '%', $total);
        self::assertStringEndsWith('▸', $total);
        // The bar is the snapshot's apportioned runs, one row.
        self::assertStringContainsString('█', Ansi::strip($body[0]));
    }

    public function testNothingExceedsTheBoxOverAWideTerminalSweep(): void
    {
        foreach ([true, false] as $expanded) {
            foreach ([20, 33, 48, 64, 80] as $width) {
                foreach ([self::modelWithSnapshot(), self::measuringModel()] as $label => $model) {
                    $frame = ContextPane::renderModel(
                        $model->withExpanded($expanded),
                        Theme::byName('dark'),
                        $width,
                        self::TALL_ROWS
                    );
                    foreach (explode("\n", $frame) as $line) {
                        self::assertLessThanOrEqual(
                            $width,
                            Width::of(Ansi::strip($line)),
                            "row overflow at width $width, expanded=" . var_export($expanded, true) . ", model $label"
                        );
                    }
                }
            }
        }
        $this->addToAssertionCount(1);
    }

    public function testEveryCategoryCarriesItsOwnGlyphAndTheySurviveNoColor(): void
    {
        $glyphs = array_map(
            static fn (ContextCategory $case): string => ContextPane::glyph($case),
            ContextCategory::cases()
        );
        self::assertCount(11, $glyphs);
        self::assertSame($glyphs, array_unique($glyphs, SORT_REGULAR), 'two categories share a glyph');

        Style::setDefaultProfile(ColorProfile::Ascii);
        PaneFrame::resetProfileCacheForTesting();
        $frame = ContextPane::renderModel(
            self::modelWithSnapshot()->withExpanded(true),
            Theme::byName('dark'),
            self::BOX_WIDTH,
            self::TALL_ROWS
        );

        // NO_COLOR drops the SGR per colour — the text (and every glyph) stands.
        self::assertStringNotContainsString("\x1b[38", $frame);
        $plain = Ansi::strip($frame);
        foreach (ContextCategory::cases() as $case) {
            self::assertStringContainsString(
                ContextPane::glyph($case),
                $plain,
                'glyph of ' . $case->name . ' is invisible without colour'
            );
        }
    }

    public function testAdjacentRowsNeverShareAColourInAnyShippedPalette(): void
    {
        $cases = ContextCategory::cases();
        foreach (['dark', 'light', 'dracula', 'tokyoNight', 'ansi'] as $name) {
            $theme = Theme::byName($name);
            for ($i = 0, $n = count($cases) - 1; $i < $n; $i++) {
                self::assertNotSame(
                    ContextPane::color($cases[$i], $theme)->toHex(),
                    ContextPane::color($cases[$i + 1], $theme)->toHex(),
                    "palette $name: rows " . $cases[$i]->name . '/' . $cases[$i + 1]->name . ' collide'
                );
            }
        }
    }

    public function testExpandedListsEveryCategoryRowWithKeysAndMarkers(): void
    {
        [$frame, $keys] = ContextPane::layoutModel(
            self::modelWithSnapshot()->withExpanded(true),
            Theme::byName('dark'),
            self::BOX_WIDTH,
            self::TALL_ROWS
        );
        $body = self::bodyOf($frame);
        $plain = array_map(static fn (string $line): string => Ansi::strip($line), $body);

        // bar + 11 category rows + footer + dump: nothing drilled.
        self::assertCount(14, $body);
        self::assertSame(null, $keys[0]);
        self::assertSame(ContextPane::ROW_KEY_PREFIX . 'mcp-tools', $keys[3]);
        self::assertSame(ContextPane::ROW_KEY_PREFIX . 'messages', $keys[9]);
        self::assertSame(ContextPane::DUMP_KEY, $keys[count($keys) - 1]);

        $messages = $plain[9];
        self::assertStringContainsString(ContextCategory::Messages->label(), $messages);
        self::assertStringContainsString('~' . TokenCount::compact(40000), $messages);
        // Drillable (the MCP row carries subs), plain otherwise.
        self::assertStringContainsString('▸', $plain[3]);
        self::assertStringNotContainsString('▸', $messages);
        self::assertStringContainsString('not measured', $plain[1]); // SystemPrompt, sections null
        self::assertStringContainsString('cache ' . Lang::t('tui.context.unmeasured'), $plain[12]);
        self::assertStringContainsString(Lang::t('tui.context.dump'), $plain[13]);
    }

    public function testDrilledCategoryShowsItsSubRowsInPlace(): void
    {
        $model = self::modelWithSnapshot()->withExpanded(true)->withDrill(ContextCategory::McpTools);
        [, $keys] = ContextPane::layoutModel($model, Theme::byName('dark'), self::BOX_WIDTH, self::TALL_ROWS);
        $plain = array_map(static fn (string $line): string => Ansi::strip($line), self::bodyOf(
            ContextPane::renderModel($model, Theme::byName('dark'), self::BOX_WIDTH, self::TALL_ROWS)
        ));

        // Sub rows land directly under their parent and carry null keys.
        $at = self::rowStartingWith($plain, 'alpha');
        self::assertSame(ContextPane::ROW_KEY_PREFIX . 'mcp-tools', $keys[$at - 1]);
        self::assertStringContainsString('~' . TokenCount::compact(500), $plain[$at]);
        self::assertNull($keys[$at]);
        // The instruction sub-row is the one whose NOTE carries the whole
        // message: the not-sent note stands in place of any figure (the
        // narrow box truncates it, so pin its opening wording).
        self::assertStringContainsString(
            substr(Lang::t('cmd.context.category.mcp.not-sent'), 0, 16),
            $plain[$at + 2]
        );
        self::assertNull($keys[$at + 2]);
        // The bytes precedence leg needs the MEMORY drill: its file sub
        // renders a byte figure, never a token figure.
        $memoryPlain = array_map(static fn (string $line): string => Ansi::strip($line), self::bodyOf(
            ContextPane::renderModel(
                self::modelWithSnapshot()->withExpanded(true)->withDrill(ContextCategory::MemoryFiles),
                Theme::byName('dark'),
                self::BOX_WIDTH,
                self::TALL_ROWS
            )
        ));
        $file = self::rowStartingWith($memoryPlain, 'AGENTS.md');
        self::assertStringContainsString('4 KB', $memoryPlain[$file]);
        self::assertStringNotContainsString('not measured', $memoryPlain[$file]);
    }

    public function testColdStateReadsMeasuringAndNeverAFabricatedTotal(): void
    {
        [$frame, $keys] = ContextPane::layoutModel(self::measuringModel(), Theme::byName('dark'), self::BOX_WIDTH, 4);
        $body = array_map(static fn (string $line): string => Ansi::strip($line), self::bodyOf($frame));

        // Body rows keep the box's side runes; match on the text they carry.
        self::assertStringContainsString(Lang::t('tui.context.measuring'), $body[0]);
        // The window is configured, so it shows; the usage leg is "not measured".
        self::assertStringContainsString(Lang::t('tui.context.unmeasured'), $body[1]);
        self::assertStringNotContainsString('0 / 200K', $body[1]);
        self::assertSame([null, ContextPane::TOGGLE_KEY], $keys);

        // Expanded cold state is the bar slot alone — no invented rows.
        [, $coldKeys] = ContextPane::layoutModel(
            self::measuringModel()->withExpanded(true),
            Theme::byName('dark'),
            self::BOX_WIDTH,
            self::TALL_ROWS
        );
        self::assertSame([null], $coldKeys);
    }

    public function testLiveHistoryTokensOverrideTheMessagesRowAndTheTotal(): void
    {
        $model = self::modelWithSnapshot();
        $cold = (int) $model->usedTokens();
        $running = $model->withHistoryTokens(45000)->withStale(true);

        self::assertSame(45000, $running->messageTokens());
        self::assertSame($cold - 40000 + 45000, $running->usedTokens());

        [, $keys] = ContextPane::layoutModel(
            $running->withExpanded(true),
            Theme::byName('dark'),
            self::BOX_WIDTH,
            self::TALL_ROWS
        );
        $plain = array_map(static fn (string $line): string => Ansi::strip($line), self::bodyOf(
            ContextPane::renderModel($running->withExpanded(true), Theme::byName('dark'), self::BOX_WIDTH, self::TALL_ROWS)
        ));

        // Messages row (key index 9 of the unkeyed-expanded walk) followed the live figure...
        self::assertSame(ContextPane::ROW_KEY_PREFIX . 'messages', $keys[9]);
        self::assertStringContainsString('~' . TokenCount::compact(45000), $plain[9]);
        // ...the total too...
        $collapsed = Ansi::strip(self::bodyOf(ContextPane::renderModel(
            $running,
            Theme::byName('dark'),
            self::BOX_WIDTH,
            4
        ))[1]);
        self::assertStringContainsString(TokenCount::compact($cold - 40000 + 45000), $collapsed);
        // ...and a settled (non-stale) snapshot keeps the measured figure instead.
        $settled = Ansi::strip(self::bodyOf(ContextPane::renderModel(
            $model->withHistoryTokens(45000),
            Theme::byName('dark'),
            self::BOX_WIDTH,
            4
        ))[1]);
        self::assertStringContainsString(TokenCount::compact($cold), $settled);
    }

    public function testFooterCarriesCacheAndPruningLegsWithoutFabrication(): void
    {
        $model = self::modelWithSnapshot()->withExpanded(true)->withCachePercent(94)->withPrunedTokens(4200);
        $plain = array_map(static fn (string $line): string => Ansi::strip($line), self::bodyOf(
            ContextPane::renderModel($model, Theme::byName('dark'), self::BOX_WIDTH, self::TALL_ROWS)
        ));

        self::assertStringContainsString('cache 94%', $plain[12]);
        self::assertStringContainsString('· −' . TokenCount::compact(4200) . ' pruned', $plain[12]);

        // With no pruning measured the pruned leg is absent, not zero.
        $bare = array_map(static fn (string $line): string => Ansi::strip($line), self::bodyOf(
            ContextPane::renderModel(
                self::modelWithSnapshot()->withExpanded(true),
                Theme::byName('dark'),
                self::BOX_WIDTH,
                self::TALL_ROWS
            )
        ));
        self::assertStringNotContainsString('pruned', $bare[12]);
    }

    private static function modelWithSnapshot(): ContextPaneModel
    {
        return new ContextPaneModel(categories: self::snapshotFixture(), historyTokens: 40000, window: 200000);
    }

    private static function measuringModel(): ContextPaneModel
    {
        return new ContextPaneModel(categories: null, historyTokens: 1200, window: 200000);
    }

    private static function snapshotFixture(): ContextCategories
    {
        return ContextCategories::from(new ContextBreakdown(
            window: 200000,
            sections: null,
            toolCount: 5,
            toolTokens: 1500,
            historyTokens: 40000,
            historyMessages: 12,
            uiOnlyRows: 0,
            largest: [],
            lastCache: null,
            sessionCache: null,
            instructionFiles: ['files' => [['path' => 'AGENTS.md', 'bytes' => 4096, 'personal' => false]]],
            mcpToolCount: 2,
            mcpToolTokens: 800,
            mcpServers: [['name' => 'alpha', 'tokens' => 500]],
        ));
    }

    /**
     * The index of the first body row whose text starts with $needle once
     * its outer whitespace is gone.
     *
     * @param list<string> $plain
     */
    private static function rowStartingWith(array $plain, string $needle): int
    {
        foreach ($plain as $index => $line) {
            if (str_starts_with(trim($line, " │"), $needle)) {
                return $index;
            }
        }

        self::fail('no context-pane row starts with ' . $needle);
    }

    /**
     * The box's body lines — everything between the border's top and bottom
     * runes, in order, styled bytes intact.
     *
     * @return list<string>
     */
    private static function bodyOf(string $frame): array
    {
        $lines = explode("\n", $frame);

        return array_slice($lines, 1, count($lines) - 2);
    }
}
