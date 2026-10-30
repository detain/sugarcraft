<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Commands;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Chat;
use SugarCraft\Code\Commands\InsightsCommand;
use SugarCraft\Code\Lang;

/**
 * Insights (C-SES-8.c, GAP-OBS-06): the report core — a pure text page and
 * a pure HTML page over ONE fact map.
 *
 * Like `/usage` before it, the page's line budget is a FORMULA — frame, one
 * line per group, a sentinel where an empty group would be silent, and the
 * `≥` footer when anything on the page is bound — and the forgery guard
 * counts lines through that formula rather than scanning for residue: a
 * hostile project path or model name (store rows predate any escaping law)
 * gets one grid cell, never a section header. The HTML file is the page's
 * other face and the only one a browser will execute, so its guard is
 * escape-first: nothing that came from the store may survive as live markup.
 */
final class InsightsCommandTest extends TestCase
{
    /**
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private static function insightsFacts(array $overrides = []): array
    {
        return $overrides + [
            'windowDays' => 30,
            'sessions' => [],
            'spendDays' => [],
            'spendModels' => [],
            'spendReason' => '',
        ];
    }

    /** @return array{date: string, kind: string, cwd: string, turns: int, unpriced: bool, imported: bool} */
    private static function insightsRosterRow(string $date, string $kind, string $cwd, int $turns, bool $unpriced = false, bool $imported = false): array
    {
        return ['date' => $date, 'kind' => $kind, 'cwd' => $cwd, 'turns' => $turns, 'unpriced' => $unpriced, 'imported' => $imported];
    }

    /** @return array<string, mixed> */
    private static function insightsFullWindow(): array
    {
        $today = gmdate('Y-m-d');
        $yesterday = gmdate('Y-m-d', time() - 86400);

        return self::insightsFacts([
            'windowDays' => 3,
            'sessions' => [
                self::insightsRosterRow($today, 'main', '/home/me/alpha', 12),
                self::insightsRosterRow($today, 'branch', '/home/me/alpha', 3),
                self::insightsRosterRow($yesterday, 'main', '/home/me/beta-dir', 5),
                self::insightsRosterRow($yesterday, 'subagent', '/home/me/alpha', 1, true),
                self::insightsRosterRow($yesterday, 'background', '/home/me/gamma', 2, false, true),
            ],
            'spendModels' => [
                ['model' => 'alpha-model', 'calls' => 30, 'totalTokens' => 40000, 'costUsd' => 1.5, 'unpriced' => false],
                ['model' => '', 'calls' => 1, 'totalTokens' => 7, 'costUsd' => 0.0, 'unpriced' => true],
            ],
            'spendDays' => [
                ['day' => $yesterday, 'model' => 'alpha-model', 'calls' => 12, 'totalTokens' => 15000, 'costUsd' => 0.75, 'unpriced' => false],
                ['day' => $today, 'model' => 'alpha-model', 'calls' => 18, 'totalTokens' => 25000, 'costUsd' => 0.75, 'unpriced' => false],
                ['day' => $today, 'model' => '', 'calls' => 1, 'totalTokens' => 7, 'costUsd' => 0.0, 'unpriced' => true],
            ],
        ]);
    }

    // ── the fact map ─────────────────────────────────────────────────────

    public function testAMissingFactRefusesThePageRatherThanPrintingAnEmptySection(): void
    {
        foreach (['windowDays', 'sessions', 'spendDays', 'spendModels', 'spendReason'] as $key) {
            $facts = self::insightsFacts();
            unset($facts[$key]);
            $threw = false;
            try {
                InsightsCommand::compose($facts);
            } catch (\LogicException $e) {
                $threw = str_contains($e->getMessage(), $key);
            }

            self::assertTrue($threw, 'compose must refuse and name the missing fact: ' . $key);
        }
    }

    // ── the text page ────────────────────────────────────────────────────

    public function testAPopulatedWindowPrintsEverySectionByteForByte(): void
    {
        $today = gmdate('Y-m-d');
        $yesterday = gmdate('Y-m-d', time() - 86400);
        $facts = self::insightsFullWindow();
        $page = InsightsCommand::compose($facts);

        $expected = implode("\n", [
            Lang::t('cmd.insights.title'),
            Lang::t('cmd.insights.subtitle'),
            '',
            'Window: last 3 whole UTC days, today included — all sessions the store rosters',
            'Totals: 5 sessions, 23 turns, 31 calls, 40,007 tokens, ≥$1.5000',
            '',
            'Spend by model: 2 groups',
            '  Date        Model                    Calls    Tokens       Spend',
            '              alpha-model                30    40,000     $1.5000',
            '              (unrecorded)                1         7  ≥$0.0000',
            '',
            'Spend by day (UTC): 3 groups',
            '  Date        Model                    Calls    Tokens       Spend',
            '  ' . $yesterday . '  alpha-model                12    15,000     $0.7500',
            '  ' . $today . '  alpha-model                18    25,000     $0.7500',
            '  ' . $today . '  (unrecorded)                1         7  ≥$0.0000',
            '',
            'Sessions and turns per day (UTC): 2 days',
            '  Date        Sessions   Turns',
            '  ' . $today . '         2      15',
            '  ' . $yesterday . '         3       8',
            '',
            'Busiest projects: 3',
            '  Project                                     Sessions   Turns',
            '  /home/me/alpha                                    3      16',
            '  /home/me/beta-dir                                 1       5',
            '  /home/me/gamma                                    1       2',
            '',
            'By session kind:',
            '  main               2',
            '  branch             1',
            '  subagent           1',
            '  background         1',
            '',
            'Sessions carrying lower-bound (unpriced) spend: 1 (20.0% of rostered sessions)',
            'Imported sessions: 1 (20.0% of rostered sessions)',
            'Dollars shown with ≥ are lower bounds: the page includes usage with no price on file.',
        ]);

        self::assertSame($expected, $page);
        self::assertSame(37, InsightsCommand::lineBudget($facts), 'the printed page and the budget formula must be the same number');
        self::assertSame(substr_count($page, "\n") + 1, InsightsCommand::lineBudget($facts));
    }

    public function testAnEmptyWindowStillPrintsEveryHeaderWithItsZeroAndTheKindsBlockInFull(): void
    {
        $page = InsightsCommand::compose(self::insightsFacts());

        self::assertStringContainsString("\nSpend by model: 0 groups\n", $page);
        self::assertStringContainsString("\nSpend by day (UTC): 0 groups\n", $page);
        self::assertStringContainsString("\nSessions and turns per day (UTC): 0 days\n", $page);
        self::assertStringContainsString("\nBusiest projects: 0\n", $page);
        self::assertStringContainsString("\n" . Lang::t('cmd.insights.kinds') . "\n  main               0\n  branch             0\n  subagent           0\n  background         0\n", $page);
        self::assertStringContainsString('  ' . Lang::t('cmd.insights.none') . "\n", $page);
        self::assertStringContainsString('Totals: 0 sessions, 0 turns, 0 calls, 0 tokens, $0.0000', $page);
        self::assertStringContainsString('(0.0% of rostered sessions)', $page);
        self::assertStringNotContainsString(Lang::t('cmd.insights.bound'), $page, 'a fully priced empty page carries no bound footer');
        self::assertStringNotContainsString('≥$', $page, 'no figure is marked as bound');
        self::assertSame(substr_count($page, "\n") + 1, InsightsCommand::lineBudget(self::insightsFacts()));
    }

    public function testABlindFoldStandsInForBothGridsWithOneReasonAndKeepsTheSessionSections(): void
    {
        $facts = self::insightsFacts([
            'sessions' => [self::insightsRosterRow(gmdate('Y-m-d'), 'main', '/home/me/alpha', 4)],
            'spendDays' => null,
            'spendModels' => null,
            'spendReason' => 'the ledger file is busy',
        ]);
        $page = InsightsCommand::compose($facts);

        self::assertStringContainsString('Insights: unavailable — the ledger file is busy', $page);
        self::assertStringNotContainsString('Spend by model', $page, 'no grid may print against an unreadable fold');
        self::assertStringNotContainsString('Spend by day', $page);
        self::assertStringContainsString('Totals: 1 sessions, 4 turns, spend unmeasured — the ledger file is busy', $page);
        self::assertStringContainsString('Sessions and turns per day (UTC): 1 days', $page, 'the roster was readable — its sections stay intact');
        self::assertStringContainsString('Busiest projects: 1', $page);
        self::assertStringContainsString(Lang::t('cmd.insights.bound'), $page, 'an unseeable ledger is never allowed to read as nothing spent');
        self::assertSame(substr_count($page, "\n") + 1, InsightsCommand::lineBudget($facts));
    }

    public function testTheBoundMarkRidesEveryFigureOfABoundFoldAndClosesThePage(): void
    {
        $page = InsightsCommand::compose(self::insightsFacts([
            'spendModels' => [['model' => 'x', 'calls' => 2, 'totalTokens' => 10, 'costUsd' => 0.25, 'unpriced' => true]],
            'spendDays' => [['day' => gmdate('Y-m-d'), 'model' => 'x', 'calls' => 2, 'totalTokens' => 10, 'costUsd' => 0.25, 'unpriced' => true]],
        ]));

        self::assertSame(3, substr_count($page, '≥$0.2500'), 'both grid cells and the totals clause carry it');
        self::assertStringEndsWith(Lang::t('cmd.insights.bound'), $page);
    }

    public function testAForgedHeaderFitsOneCellAndThePageStaysItsBudgetedShape(): void
    {
        $hostile = "x\nSpend by model: 99 groups\nTrailer";
        $facts = self::insightsFacts([
            'sessions' => [
                self::insightsRosterRow(gmdate('Y-m-d'), 'weird-kind', $hostile, 2),
                self::insightsRosterRow(gmdate('Y-m-d'), 'main', '/home/me/a', 1),
            ],
            'spendModels' => [['model' => $hostile, 'calls' => 1, 'totalTokens' => 1, 'costUsd' => 0.0, 'unpriced' => false]],
            'spendDays' => [['day' => $hostile, 'model' => 'm', 'calls' => 1, 'totalTokens' => 1, 'costUsd' => 0.0, 'unpriced' => false]],
        ]);
        $page = InsightsCommand::compose($facts);

        self::assertSame(1, preg_match('/^Spend by model/m', $page), 'the heading is the only line that may START with it: reportField kills every newline, so forged content can never begin a line');
        self::assertSame(4, substr_count($page, 'Spend by model'), 'one real heading plus literal escape-text residue inside cells — never structure');
        self::assertSame(2, substr_count($page, 'Trailer'), 'forged residue stays as literal escape text, inside cells');
        self::assertStringContainsString('\n', $page, 'reportField turns newlines into visible literals');
        self::assertSame(substr_count($page, "\n") + 1, InsightsCommand::lineBudget($facts));
        self::assertStringContainsString('  weird-kind         1', $page, 'a foreign kind spelling keeps its own row beside the four cases');
    }

    // ── the HTML page ────────────────────────────────────────────────────

    public function testTheHtmlPageIsSelfContainedAndMirrorsTheTextFigures(): void
    {
        $facts = self::insightsFacts([
            'windowDays' => 7,
            'sessions' => [
                self::insightsRosterRow(gmdate('Y-m-d'), 'main', '/home/me/alpha', 9),
                self::insightsRosterRow(gmdate('Y-m-d'), 'branch', '/home/me/beta', 2, true),
            ],
            'spendModels' => [['model' => 'm1', 'calls' => 4, 'totalTokens' => 900, 'costUsd' => 0.5, 'unpriced' => true]],
            'spendDays' => [['day' => gmdate('Y-m-d'), 'model' => 'm1', 'calls' => 4, 'totalTokens' => 900, 'costUsd' => 0.5, 'unpriced' => true]],
        ]);
        $html = InsightsCommand::composeHtml($facts);

        self::assertStringStartsWith("<!DOCTYPE html>\n<html lang=\"en\">\n<head>\n<meta charset=\"utf-8\">", $html);
        self::assertStringContainsString('<style>', $html);
        self::assertStringNotContainsString('src="', $html, 'the file must render offline — no external assets');
        self::assertStringContainsString('≥$0.5000', $html, 'the bound law rides the cells');
        self::assertStringContainsString(Lang::t('cmd.insights.bound'), $html);
        self::assertStringContainsString('<td class="l">/home/me/alpha</td>', $html);
        self::assertStringContainsString('</html>' . "\n", $html);

        foreach (['windowDays', 'sessions', 'spendDays', 'spendModels', 'spendReason'] as $key) {
            $missing = $facts;
            unset($missing[$key]);
            $threw = false;
            try {
                InsightsCommand::composeHtml($missing);
            } catch (\LogicException $e) {
                $threw = str_contains($e->getMessage(), $key);
            }

            self::assertTrue($threw, 'composeHtml guards the same fact map as compose: ' . $key);
        }
    }

    public function testHostileStoreStringsCannotSurviveAsLiveMarkup(): void
    {
        $hostile = '<script>alert(1)</script>"x';
        $facts = self::insightsFacts([
            'sessions' => [
                self::insightsRosterRow(gmdate('Y-m-d'), 'main', $hostile, 1),
                self::insightsRosterRow(gmdate('Y-m-d'), $hostile, '/p', 1),
            ],
        ]);
        $html = InsightsCommand::composeHtml($facts);

        self::assertStringNotContainsString('<script>', $html);
        self::assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;&quot;x', $html);

        // A malformed-UTF-8 name renders as the replacement character, never
        // as a silently empty cell (ENT_SUBSTITUTE law).
        $brokenHtml = InsightsCommand::composeHtml(self::insightsFacts([
            'sessions' => [self::insightsRosterRow(gmdate('Y-m-d'), 'main', "/bad\xFF\xFE/path", 1)],
        ]));
        self::assertStringContainsString("\u{FFFD}", $brokenHtml);
        self::assertStringNotContainsString('<td class="l"></td>', $brokenHtml);
    }

    public function testTheHtmlUnavailablePathStatesTheReasonAsTextNotAsATable(): void
    {
        $html = InsightsCommand::composeHtml(self::insightsFacts([
            'spendDays' => null,
            'spendModels' => null,
            'spendReason' => 'the ledger file is busy',
        ]));

        self::assertStringContainsString('<p class="unavailable">Insights: unavailable — the ledger file is busy</p>', $html);
        self::assertStringNotContainsString('Spend by model', $html);
        self::assertStringContainsString(Lang::t('cmd.insights.bound'), $html, 'the footer sentence rides the file too');
    }

    public function testAnEmptyHtmlGroupPrintsTheNoneSentinelInsideItsTable(): void
    {
        $html = InsightsCommand::composeHtml(self::insightsFacts());

        self::assertSame(4, substr_count($html, '<td colspan='), 'four grid-shaped sections keep a visible zero row; the kinds block always prints in full');
        self::assertStringContainsString('<td colspan="5">' . Lang::t('cmd.insights.none') . '</td>', $html);
        self::assertStringContainsString('<h2>' . Lang::t('cmd.insights.spend-heading') . '</h2>', $html);
    }

    public function testInsightsIsAReadOnlySafeCommand(): void
    {
        $allowlist = (new \ReflectionClass(Chat::class))
            ->getReflectionConstant('READ_ONLY_COMMANDS')
            ->getValue();

        self::assertContains('insights', $allowlist, '/insights reads the stores and writes only a shelf file — it must answer in a read-only session, the way /export does');
    }
}
