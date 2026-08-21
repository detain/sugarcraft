<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Commands;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\Util\Width;
use SugarCraft\Crush\Agents\Agent;
use SugarCraft\Crush\Agents\AgentManager;
use SugarCraft\Crush\Chat;
use SugarCraft\Crush\Commands\AgentsCommand;
use SugarCraft\Crush\Commands\McpAuthCommand;
use SugarCraft\Crush\Commands\TranscriptTable;
use SugarCraft\Crush\MCP\McpAuthStore;
use SugarCraft\Crush\MCP\OAuthClientRegistration;

/**
 * `/agents` and `mcp auth list` hand-built their columns with `strlen()` and
 * `str_repeat(' ', max(1, N - $len))`. Byte length against a cell grid: a
 * name carrying one accented character pushed every later column on that row
 * one position left, and neither expression could clip, so a long
 * model-supplied name emitted a row wider than the pane.
 *
 * These are asserted on the RENDERED bytes rather than on the source, which
 * is the gap {@see NoRawAnsiInTranscriptTest} leaves open: its regex reads
 * `\033[` out of the file, so a table that acquired styling at RUNTIME would
 * pass it while putting escapes back into the transcript.
 *
 * @internal
 */
final class CommandTableRenderingTest extends TestCase
{
    // -------------------------------------------------------------------------
    // /agents
    // -------------------------------------------------------------------------

    /**
     * The defect, stated as a test: two agents whose names differ in BYTE
     * length but not in CELL width must produce rows of identical width.
     * Under `strlen()` the accented row came out narrower by one column per
     * extra byte.
     */
    public function testAgentsListAlignsMultiByteNamesOnTheCellGrid(): void
    {
        $output = $this->runAgents([
            $this->agent('abcdef', 'plain ascii'),
            $this->agent('révisé', 'acentuada'),
        ]);

        $rows = $this->boxRows($output);

        $this->assertCount(6, $rows, 'top + header + separator + 2 rows + bottom');
        $this->assertSame(
            [Width::string($rows[0])],
            array_values(array_unique(array_map(
                static fn (string $r): int => Width::string($r),
                $rows,
            ))),
            "rows differ in width:\n" . implode("\n", $rows),
        );
    }

    /**
     * `révisé` is 6 cells and 8 bytes. Pinning both halves keeps the fixture
     * from silently becoming ASCII and the test from becoming a tautology.
     */
    public function testAgentsFixtureIsActuallyMultiByte(): void
    {
        $this->assertSame(8, \strlen('révisé'));
        $this->assertSame(6, Width::string('révisé'));
    }

    /**
     * The old `str_repeat(" ", max(1, 20 - $nameLen))` had no upper bound, so
     * a 400-character preset name emitted a 400-column row. The table clips
     * to its declared budget instead.
     */
    public function testAgentsListBoundsARowNoMatterHowLongTheAgentNameIs(): void
    {
        $output = $this->runAgents([
            $this->agent(str_repeat('n', 400), str_repeat('d', 400)),
        ]);

        $cap = TranscriptTable::maxCells($this->columnsOf(AgentsCommand::class));
        $rows = $this->boxRows($output);
        $this->assertNotSame([], $rows);

        foreach ($rows as $row) {
            // <= rather than ==: maxCells() is the WORST case, and the Status
            // column auto-fits to the 8 cells `● active` occupies rather than
            // to its 10-cell budget. What must hold is that nothing this
            // command can be handed pushes a row past the cap.
            $this->assertLessThanOrEqual($cap, Width::string($row));
            $this->assertSame(Width::string($rows[0]), Width::string($row));
        }
    }

    /**
     * An agent name is read out of preset frontmatter this process did not
     * write. An escape in one used to reach the transcript verbatim, where it
     * surfaces as literal `[31m`.
     */
    public function testAgentsListStripsEscapesOutOfAModelSuppliedName(): void
    {
        $output = $this->runAgents([
            $this->agent("\033[31mroot", "\033[1mdanger"),
        ]);

        $this->assertStringNotContainsString("\033", $output);
        $this->assertStringContainsString('root', $output);
    }

    public function testAgentsListStillNamesItsAgents(): void
    {
        $output = $this->runAgents([$this->agent('coder', 'writes code')]);

        $this->assertStringContainsString('Active Agents', $output);
        $this->assertStringContainsString('coder', $output);
        $this->assertStringContainsString('writes code', $output);
        $this->assertStringContainsString('● active', $output);
    }

    /**
     * Both classes state their table's total width in prose. Pin the two
     * numbers so the sentence cannot drift away from the budgets it
     * describes — that drift is the whole reason these budgets are a map and
     * the cap is derived from it.
     */
    public function testTheDeclaredBudgetsSumToTheWidthsTheDocBlocksClaim(): void
    {
        $this->assertSame(76, TranscriptTable::maxCells($this->columnsOf(AgentsCommand::class)));
        $this->assertSame(88, TranscriptTable::maxCells($this->columnsOf(McpAuthCommand::class)));
    }

    // -------------------------------------------------------------------------
    // mcp auth list
    // -------------------------------------------------------------------------

    public function testMcpAuthListAlignsMultiByteServerUrlsOnTheCellGrid(): void
    {
        $output = $this->runMcpAuthList([
            'https://plain.example.com' => [time() + 9999, ['read', 'write']],
            'https://ünïcode.example.com' => [time() + 9999, ['read']],
        ]);

        $rows = $this->boxRows($output);

        $this->assertCount(6, $rows, 'top + header + separator + 2 rows + bottom');
        $this->assertSame(
            [Width::string($rows[0])],
            array_values(array_unique(array_map(
                static fn (string $r): int => Width::string($r),
                $rows,
            ))),
            "rows differ in width:\n" . implode("\n", $rows),
        );
    }

    /**
     * The status labels used to be wrapped in `**…**`. The transcript's
     * Markdown pass eats those four bytes and re-emits the text bolded, so
     * the cell arrives four cells narrower than the padding the table wrote
     * around it — the closing border of that one row lands out of line while
     * every other row is fine.
     */
    public function testMcpAuthStatusCellsCarryNoMarkdownEmphasis(): void
    {
        $output = $this->runMcpAuthList([
            'https://active.example.com' => [time() + 9999, ['read']],
            'https://expired.example.com' => [time() - 10, []],
            'https://soon.example.com' => [time() + 60, []],
        ]);

        $this->assertStringNotContainsString('**●', $output);
        $this->assertStringNotContainsString('**○', $output);
        $this->assertStringContainsString('● active', $output);
        $this->assertStringContainsString('○ expired', $output);
        $this->assertStringContainsString('● expiring soon', $output);
    }

    /**
     * `Expires` is budgeted at exactly the width of `Y-m-d H:i` so the cap's
     * proportional shrink cannot reach it. It was measured clipping
     * `2026-08-22 03:00` to `2026-08-22 03` before the budgets were derived
     * from the columns.
     */
    public function testMcpAuthNeverClipsTheExpiryTimestamp(): void
    {
        $at = mktime(3, 46, 0, 8, 22, 2026);
        self::assertIsInt($at);

        $output = $this->runMcpAuthList([
            'https://' . str_repeat('long.', 60) . 'example.com' => [$at, ['a', 'b', 'c', 'd', 'e', 'f', 'g']],
        ]);

        $this->assertStringContainsString(date('Y-m-d H:i', $at), $output);
    }

    /**
     * Expiry and scopes used to be a second indented line printed ONLY when
     * `expiresAt !== null`, so a server holding credentials with no expiry
     * never showed its scopes at all.
     */
    public function testMcpAuthShowsScopesForAServerWithNoExpiry(): void
    {
        $output = $this->runMcpAuthList([
            'https://noexpiry.example.com' => [null, ['read', 'write']],
        ]);

        $this->assertStringContainsString('read, write', $output);
    }

    public function testMcpAuthListBoundsARowNoMatterHowLongTheServerUrlIs(): void
    {
        $output = $this->runMcpAuthList([
            'https://' . str_repeat('long.', 100) . 'example.com' => [time() + 5, ['a', 'b', 'c', 'd', 'e', 'f', 'g', 'h']],
        ]);

        $cap = TranscriptTable::maxCells($this->columnsOf(McpAuthCommand::class));
        $rows = $this->boxRows($output);
        $this->assertNotSame([], $rows);

        foreach ($rows as $row) {
            // See the /agents twin: the cap is the worst case. `Status` is
            // budgeted for `○ no credentials` (16), and this fixture's widest
            // label is `● expiring soon` (15), so the table fits one cell
            // under.
            $this->assertLessThanOrEqual($cap, Width::string($row));
            $this->assertSame(Width::string($rows[0]), Width::string($row));
        }
    }

    public function testMcpAuthListEmitsNoEscapeBytes(): void
    {
        $output = $this->runMcpAuthList([
            "https://\033[31mevil.example.com" => [time() + 9999, ["\033[1mread"]],
        ]);

        $this->assertStringNotContainsString("\033", $output);
    }

    public function testMcpAuthListStillReportsAnEmptyStore(): void
    {
        $output = $this->runMcpAuthList([]);

        $this->assertStringContainsString('No MCP servers registered', $output);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /** @return list<string> the box-drawing lines only */
    private function boxRows(string $output): array
    {
        $rows = [];
        foreach (explode("\n", $output) as $line) {
            if (preg_match('/^[│╭├╰]/u', $line) === 1) {
                $rows[] = $line;
            }
        }

        return $rows;
    }

    /**
     * @param class-string $class
     * @return array<string, int>
     */
    private function columnsOf(string $class): array
    {
        $constant = (new \ReflectionClass($class))->getReflectionConstant('COLUMNS');
        self::assertNotFalse($constant, $class . ' must declare a COLUMNS budget map');

        /** @var array<string, int> $value */
        $value = $constant->getValue();

        return $value;
    }

    private function agent(string $name, string $description): Agent
    {
        return new Agent(
            name: $name,
            description: $description,
            prompt: '',
            model: 'm',
            provider: 'p',
            tools: [],
            skillNames: [],
            hooks: [],
            isActive: true,
        );
    }

    /** @param list<Agent> $agents */
    private function runAgents(array $agents, int $cols = self::WIDE_TERMINAL): string
    {
        $reflection = new \ReflectionClass(AgentManager::class);
        $manager = $reflection->newInstanceWithoutConstructor();
        $indexed = [];
        foreach ($agents as $agent) {
            $indexed[$agent->name] = $agent;
        }
        $property = $reflection->getProperty('agents');
        $property->setAccessible(true);
        $property->setValue($manager, $indexed);

        ob_start();
        (new AgentsCommand($manager))->execute($this->chatAt($cols), []);

        return (string) ob_get_clean();
    }

    // -------------------------------------------------------------------------
    // The fit to the live pane — B2
    // -------------------------------------------------------------------------

    /**
     * THE DEFECT THIS CLOSES. `McpAuthCommand`'s budgets sum to 88 cells, and
     * an 80-column terminal's transcript pane is **74** — `Renderer::render()`
     * wraps `renderHistory()` at `max(20, cols() - SHELL_CHROME_COLS)`, and
     * SHELL_CHROME_COLS is 6. A row wider than that is HARD-wrapped mid-row by
     * the Markdown pass, which shreds the bordered box into fragments, so the
     * box had to FIT rather than merely be smaller than the unbounded rows it
     * replaced.
     *
     * The bound is asserted as `paneWidthFor(80)` rather than as a literal,
     * and that is the whole point of this test rather than a style choice:
     * TWO drafts of the source doc-blocks stated this pane as 76 and as 80.
     * A test carrying its own copy of the arithmetic could have agreed with
     * either of them.
     *
     * An earlier revision defended the 88 as "a measured trade" forced by the
     * pane width being unknowable from a command. `Chat::cols()` has always
     * carried it and `execute()` has always been handed the `Chat`.
     */
    public function testMcpAuthListFitsAnEightyColumnPane(): void
    {
        $pane = $this->paneWidthFor(80);
        $this->assertSame(74, $pane, 'an 80-column terminal gives the transcript pane 74 cells, not 76 or 80');

        $output = $this->runMcpAuthList(
            ['https://a-genuinely-long-mcp-server-url.example.com/v1/sse' => [time() + 9999, ['read', 'write']]],
            80,
        );

        foreach ($this->boxRows($output) as $row) {
            $this->assertLessThanOrEqual(
                $pane,
                Width::string($row),
                'a row wider than the pane is hard-wrapped mid-row, which shreds the box: ' . $row,
            );
        }
    }

    /**
     * The same guarantee for `/agents`. Its 76 does NOT fit an 80-column
     * terminal either — the pane is 74 — so this asserts both widths.
     */
    public function testAgentsListFitsItsPaneAtEightyAndAtSixtyColumns(): void
    {
        foreach ([80, 60] as $cols) {
            $pane = $this->paneWidthFor($cols);
            $output = $this->runAgents(
                [$this->agent('a-rather-long-agent-name-here', str_repeat('description ', 12))],
                $cols,
            );

            $rows = $this->boxRows($output);
            $this->assertNotSame([], $rows, "no box rendered at {$cols} columns");
            foreach ($rows as $row) {
                $this->assertLessThanOrEqual(
                    $pane,
                    Width::string($row),
                    "over-wide row at {$cols} columns (pane is {$pane}): " . $row,
                );
            }
        }
    }

    /** The pane a table gets on a $cols-wide terminal, from the production path. */
    private function paneWidthFor(int $cols): int
    {
        return TranscriptTable::paneWidth((new Chat([]))->withSize($cols, 40));
    }

    /**
     * The fit must not clip a FIXED-FORMAT column into a wrong value. A
     * purely proportional shrink across all four columns takes `Expires` below
     * 16 — measured at 13 for both a 74- and a 60-cell pane — which renders
     * `2026-08-22 03:00` as `2026-08-22 0…`, a wrong date rather than a short one, and exactly the
     * mangling `Table::width()`'s own cap was rejected for.
     * `TranscriptTable::fit()` floors that column instead, so the loss lands
     * on `Server` and `Scopes`. Asserted through the RENDERED bytes, so it
     * covers the command wiring the floor as well as `fit()` honouring it.
     */
    public function testTheExpiryTimestampSurvivesAnEightyColumnPane(): void
    {
        $expiry = mktime(3, 0, 0, 8, 22, 2026);
        self::assertIsInt($expiry);

        $output = $this->runMcpAuthList(
            ['https://a-genuinely-long-mcp-server-url.example.com/v1/sse' => [$expiry, ['read', 'write']]],
            80,
        );

        $this->assertStringContainsString(date('Y-m-d H:i', $expiry), $output);
    }

    /**
     * The rows stay a rectangle at every width. A fit that returned budgets
     * the header row and the body rows disagreed about would produce a box
     * whose right border stepped in and out, which is the original alignment
     * defect by a new route.
     */
    public function testEveryRowStaysTheSameWidthAtEveryPaneWidth(): void
    {
        foreach ([120, 80, 60, 50, 40] as $cols) {
            $rows = $this->boxRows($this->runMcpAuthList(
                [
                    'https://ünïcode-and-long.example.com/v1/sse' => [time() + 9999, ['read', 'write']],
                    'https://s.io' => [null, []],
                ],
                $cols,
            ));

            $this->assertNotSame([], $rows, "no box rendered at {$cols} columns");
            $widths = array_unique(array_map(static fn (string $r): int => Width::string($r), $rows));
            $this->assertCount(1, $widths, "ragged box at {$cols} columns: " . implode(',', $widths));

            // And the one width is inside the pane, except below the floors
            // where the box is deliberately over-wide (see fit()).
            $pane = $this->paneWidthFor($cols);
            if ($pane >= 60) {
                $this->assertLessThanOrEqual($pane, reset($widths), "over-wide box at {$cols} columns");
            }
        }
    }

    /**
     * Below the floors the box is deliberately WIDER than the pane rather than
     * clipping its own headers into nonsense — the same call
     * {@see \SugarCraft\Crush\Chat::handleHelpCommand()} makes with its `max(20, …)`
     * floor, and at that width every other box in the shell is over-wide too.
     * Asserted so the behaviour is a decision on record rather than a surprise.
     */
    public function testAVeryNarrowPaneKeepsTheHeadersRatherThanTheWidth(): void
    {
        $output = $this->runMcpAuthList(['https://s.io' => [time() + 9999, ['read']]], 30);

        foreach (['Server', 'Status', 'Expires', 'Scopes'] as $header) {
            $this->assertStringContainsString($header, $output, 'a header was clipped instead of overflowing');
        }
    }

    /**
     * Wide enough that neither command's budgets are scaled, so the tests
     * about ALIGNMENT are not also silently testing the fit.
     *
     * Pinned rather than left to the ambient terminal, and that is not
     * tidiness: `Chat::cols()` falls back to
     * {@see \SugarCraft\Crush\Tui\Renderer::getTerminalSize()}, which
     * MEASURED at 200 on the machine this was written on and at 80 under a
     * default CI runner — so every assertion below about a 76- or 88-cell
     * natural width would have been testing a different code path depending on
     * who ran it. The pane-fitting tests pass their width explicitly instead.
     */
    private const WIDE_TERMINAL = 200;

    /** A `Chat` reporting exactly $cols columns, the way a WindowSizeMsg would. */
    private function chatAt(int $cols): Chat
    {
        return (new Chat([]))->withSize($cols, 40);
    }

    /** @param array<string, array{0: ?int, 1: list<string>}> $servers */
    private function runMcpAuthList(array $servers, int $cols = self::WIDE_TERMINAL): string
    {
        $path = tempnam(sys_get_temp_dir(), 'crush-mcp-auth-') . '.json';
        $data = [];
        foreach ($servers as $url => [$expiresAt, $scopes]) {
            $data[$url] = [
                'clientId' => 'c',
                'clientSecret' => 's',
                'registrationAccessToken' => 'r',
                'accessToken' => 'a',
                'refreshToken' => 'rf',
                'expiresAt' => $expiresAt,
                'scopes' => $scopes,
            ];
        }
        file_put_contents($path, (string) json_encode($data));

        try {
            $store = new McpAuthStore(new OAuthClientRegistration(null, $path));

            ob_start();
            (new McpAuthCommand($store))->execute($this->chatAt($cols), ['list']);

            return (string) ob_get_clean();
        } finally {
            @unlink($path);
        }
    }
}
