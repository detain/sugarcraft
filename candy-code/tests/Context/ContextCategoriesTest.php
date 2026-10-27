<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Context;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Agents\AgentManager;
use SugarCraft\Code\App\App;
use SugarCraft\Code\Context\CompactorConfig;
use SugarCraft\Code\Context\ContextBreakdown;
use SugarCraft\Code\Context\ContextCategories;
use SugarCraft\Code\Context\ContextCategory;
use SugarCraft\Code\Context\EnvironmentBlock;
use SugarCraft\Code\Hooks\HookManager;
use SugarCraft\Code\Hooks\HookRegistry;
use SugarCraft\Code\Lang;
use SugarCraft\Code\MCP\McpClient;
use SugarCraft\Code\MCP\McpTool;
use SugarCraft\Code\MCP\McpToolShape;
use SugarCraft\Code\Message;
use SugarCraft\Code\Providers\ProviderInterface;
use SugarCraft\Code\Runtime;
use SugarCraft\Code\Skills\Skill;
use SugarCraft\Code\Skills\SkillRegistry;
use SugarCraft\Code\Tests\Prompt\PromptFixture;
use SugarCraft\Code\Tests\Support\HomeSandboxTrait;
use SugarCraft\Code\Tests\Support\RosterAgent;
use SugarCraft\Code\Tools\BuiltIn\Read;
use SugarCraft\Code\Tools\BuiltIn\TaskTool;
use SugarCraft\Code\Tools\McpToolBridge;
use SugarCraft\Code\Tools\Tool;
use SugarCraft\Code\Tools\ToolResult;
use SugarCraft\Code\Util\TokenEstimate;

/**
 * Roadmap P-CTX.a: the Claude Code-style `By category` attribution.
 *
 * Every law the block lives by is pinned here: each prompt layer bills to
 * exactly one category (unknown labels fall to System prompt), the content
 * categories sum to the breakdown's own totalTokens() on fully measured
 * fixtures, the bar apportions its cells exactly, the `<available-agents>`
 * roster is carved out of the tool-guidance section into Custom agents
 * without losing a token, and the autocompact buffer reads the real
 * CompactorConfig — a measured 0 when compaction is off, null (not
 * measured) when the surface never saw a config, and never-negative free
 * space over budget.
 */
final class ContextCategoriesTest extends TestCase
{
    use HomeSandboxTrait;

    private ?PromptFixture $fixture = null;

    private string $sandbox = '';

    protected function setUp(): void
    {
        $this->sandbox = sys_get_temp_dir() . '/crush-ctxcats-' . bin2hex(random_bytes(6));
        $this->useHomeSandbox($this->sandbox . '/home');
    }

    protected function tearDown(): void
    {
        $this->fixture?->destroy();
        $this->restoreHomeSandbox();
        if (is_dir($this->sandbox)) {
            exec('rm -rf ' . escapeshellarg($this->sandbox) . ' 2>&1');
        }
    }

    /**
     * A real prompt build — instructions, memory note, repo map, skill
     * body and listing, env, base — leaves NO layer unattributed, and the
     * content rows sum to the very figure the total line prints.
     */
    public function testEveryLayerOfARealPromptBillsToACategory(): void
    {
        $this->fixture = (new PromptFixture())
            ->write('CLAUDE.md', "# Conventions\n\nUse tabs.\n")
            ->writeJson('composer.json', ['name' => 'acme/panel', 'autoload' => ['psr-4' => ['Acme\\Panel\\' => 'src/']]])
            ->write('src/Panel.php', "<?php\n\ndeclare(strict_types=1);\n");
        $store = $this->fixture->memoryStore();
        $store->add('the panel keeps its figures from one measurement', 'project');
        $this->fixture->addSkill($this->skill('ctxcat-enabled', 'BODY TEXT spliced into the prompt every turn.'));
        $this->fixture->addListedSkill($this->skill('ctxcat-listed', 'Advertised in the listing only.'));

        $app = $this->fixture->app();
        $rows = $this->panelRuntime($app)->promptSectionSizes($app);
        $labels = array_column($rows, 'label');
        self::assertContains('project-instructions', $labels, 'the fixture really spliced the CLAUDE.md layer');
        self::assertContains('project-memory', $labels);
        self::assertContains('repo-map', $labels);
        self::assertContains('skills', $labels);
        self::assertContains('available-skills', $labels);

        $b = ContextBreakdown::measure([Message::user('go')], $rows, [], 100_000, 200);
        $cats = ContextCategories::from($b);

        self::assertGreaterThan(0, $cats->tokensOf(ContextCategory::SystemPrompt));
        self::assertGreaterThan(0, $cats->tokensOf(ContextCategory::MemoryFiles));
        self::assertGreaterThan(0, $cats->tokensOf(ContextCategory::ProjectMemory));
        self::assertGreaterThan(0, $cats->tokensOf(ContextCategory::RepositoryMap));
        self::assertGreaterThan(0, $cats->tokensOf(ContextCategory::Skills));
        self::assertSame(200, $cats->tokensOf(ContextCategory::Messages));
        self::assertSame(0, $cats->tokensOf(ContextCategory::McpTools), 'an empty measured list is a measured zero');
        self::assertSame(0, $cats->tokensOf(ContextCategory::CustomAgents));
        self::assertNull($cats->tokensOf(ContextCategory::AutocompactBuffer), 'no config surfaced — not measured, not zero');

        $content = array_sum(array_map(
            static fn (array $row): int => $row['tokens'] ?? 0,
            array_filter($cats->rows, static fn (array $row): bool => !in_array($row['category'], [ContextCategory::AutocompactBuffer, ContextCategory::FreeSpace], true)),
        ));
        self::assertSame($b->totalTokens(), $cats->contentTokens());
        self::assertSame($content, $cats->contentTokens(), 'contentTokens() is the rows, summed');
        self::assertSame(100_000 - $b->totalTokens(), $cats->tokensOf(ContextCategory::FreeSpace));
    }

    /** An unrecognised layer label must land in System prompt, never vanish. */
    public function testAnUnknownSectionLabelFallsToSystemPrompt(): void
    {
        $rows = [
            ['label' => 'base', 'stability' => 'static', 'sections' => 1, 'bytes' => 400, 'tokens' => 100],
            ['label' => 'some-future-fence', 'stability' => 'per-session', 'sections' => 1, 'bytes' => 2800, 'tokens' => 700],
        ];
        $cats = ContextCategories::from(ContextBreakdown::measure([], $rows, [], 10_000, 0));

        self::assertSame(800, $cats->tokensOf(ContextCategory::SystemPrompt));
        self::assertSame(0, $cats->tokensOf(ContextCategory::MemoryFiles), 'layers were measured — the empty bucket is a real zero');
        foreach ([ContextCategory::ProjectMemory, ContextCategory::RepositoryMap, ContextCategory::Skills] as $none) {
            self::assertSame(0, $cats->tokensOf($none), $none->value . ' measured nothing');
        }
    }

    /**
     * The bar's law: segments sum to EXACTLY the cell budget by largest
     * remainder, an all-zero block paints nothing, and an unmeasured row
     * (null tokens) claims no space — the measured rows split everything.
     */
    public function testTheBarApportionsItsCellsExactly(): void
    {
        $rows = [
            ['label' => 'base', 'stability' => 'static', 'sections' => 1, 'bytes' => 1200, 'tokens' => 300],
            ['label' => 'repo-map', 'stability' => 'per-session', 'sections' => 1, 'bytes' => 1200, 'tokens' => 300],
            ['label' => 'project-memory', 'stability' => 'per-session', 'sections' => 1, 'bytes' => 400, 'tokens' => 100],
        ];
        $b = ContextBreakdown::measure([Message::user(str_repeat('word ', 80))], $rows, [new Read()], 10_000, 90);
        $bars = ContextCategories::from($b)->bar(24);

        self::assertCount(11, $bars, 'one segment per category');
        $cells = array_sum(array_map(static fn (string $s): int => substr_count($s, '█'), $bars));
        self::assertSame(24, $cells, 'largest remainder sums exact — no invented or lost cell');
        foreach ($bars as $bar) {
            self::assertMatchesRegularExpression('/^█*$/u', $bar);
        }

        // Nothing measured at all: the empty bar, never a fake first block.
        $none = ContextCategories::from(new ContextBreakdown(0, null, null, null, 0, 0, 0, [], null, null));
        self::assertSame(array_fill(0, 11, ''), $none->bar(24));

        // Only Messages and free space carry weight — the split is even.
        $only = ContextCategories::from(ContextBreakdown::measure([Message::user('hi')], null, null, 200, 100));
        $segs = $only->bar(10);
        self::assertSame(str_repeat('█', 5), $segs[8], 'Messages is the ninth row (index 8), 100 tokens of 200');
        self::assertSame(str_repeat('█', 5), $segs[10], 'free space measured 100 too — it shares the bar');
    }

    /**
     * The roster rides the tool-guidance section but bills to Custom
     * agents: the System prompt row gives it up and the two still sum to
     * the sections' measured total.
     */
    public function testTheAgentsRosterMovesOutOfTheSystemPromptRow(): void
    {
        $task = new TaskTool($this->rosterManager());
        $guidance = $task->promptGuidance();
        self::assertStringContainsString(TaskTool::AGENTS_OPEN, $guidance, 'fixture discipline: the roster fence must open');
        $rosterShare = TokenEstimate::ofText(substr($guidance, strpos($guidance, TaskTool::AGENTS_OPEN)));

        $rows = [
            ['label' => 'base', 'stability' => 'static', 'sections' => 1, 'bytes' => 4000, 'tokens' => 1000],
            ['label' => 'tool-guidance', 'stability' => 'static', 'sections' => 2, 'bytes' => 2000, 'tokens' => 500],
        ];
        $b = ContextBreakdown::measure([], $rows, [new Read(), $task], 20_000, 0);

        self::assertSame($rosterShare, $b->agentsTokens, 'measure() cuts the roster at the fence, not at a guess');
        $cats = ContextCategories::from($b);
        self::assertSame(500 - $rosterShare + 1000, $cats->tokensOf(ContextCategory::SystemPrompt));
        self::assertSame($rosterShare, $cats->tokensOf(ContextCategory::CustomAgents));
        self::assertSame(1500, ($cats->tokensOf(ContextCategory::SystemPrompt) ?? 0) + ($cats->tokensOf(ContextCategory::CustomAgents) ?? 0), 'the carve loses no token');
        self::assertGreaterThanOrEqual($rosterShare, 500, 'the substring is priced no higher than its superstring');
    }

    /** Bridges group per server; dispatchers ride the MCP row but no server. */
    public function testMcpBridgesSplitPerServerAndDispatchersStayServerless(): void
    {
        $bridge = static fn (string $server, string $name): Tool => new class ($server, $name) implements Tool {
            public function __construct(private string $server, private string $tool)
            {
            }

            public function name(): string
            {
                return McpToolBridge::wireNameFor($this->server, $this->tool);
            }

            public function description(): string
            {
                return 'Bridge double ' . $this->tool . ' of ' . $this->server;
            }

            public function inputSchema(): array
            {
                return ['type' => 'object', 'properties' => ['q' => ['type' => 'string']], 'required' => ['q']];
            }

            public function execute(array $args): ToolResult
            {
                throw new \LogicException('measured, never executed');
            }
        };

        [$list, $call] = $this->dispatcherPair();
        $tools = [new Read(), $bridge('alpha', 'search'), $bridge('alpha', 'fetch'), $bridge('beta', 'run'), $list, $call];

        $b = ContextBreakdown::measure([], null, $tools, 20_000, 0);
        self::assertSame(5, $b->mcpToolCount, 'three bridges plus the dispatch pair');
        self::assertSame(2, count($b->mcpServers));
        $byName = [];
        foreach ($b->mcpServers as $server) {
            $byName[$server['name']] = $server;
        }
        self::assertSame(2, $byName['alpha']['count']);
        self::assertSame(1, $byName['beta']['count']);

        $sum = 0;
        foreach ($tools as $tool) {
            $sum += TokenEstimate::ofToolSchema($tool->name(), $tool->description(), $tool->inputSchema());
        }
        self::assertSame($sum, $b->toolTokens, 'the whole list is priced as before');
        $serversTotal = $byName['alpha']['tokens'] + $byName['beta']['tokens'];
        self::assertLessThan($b->mcpToolTokens, $serversTotal, 'the dispatchers cost something on top of the bridges');
        self::assertSame($b->toolTokens, ($b->mcpToolTokens ?? 0) + ContextCategories::from($b)->tokensOf(ContextCategory::SystemTools), 'the tool half splits exactly');
        self::assertSame(TokenEstimate::ofToolSchema('Read', (new Read())->description(), (new Read())->inputSchema()), ContextCategories::from($b)->tokensOf(ContextCategory::SystemTools), 'the built-in row priced the Read alone, nothing MCP');

        $cats = ContextCategories::from($b);
        $labels = array_map(static fn (array $s): string => $s['label'], $cats->rows[2]['sub']);
        self::assertSame(['alpha', 'beta', 'mcp_list/mcp_call', Lang::t('cmd.context.category.mcp.instructions')], $labels);
        self::assertNotNull($cats->rows[2]['sub'][3]['note'], 'the instructions sub-row carries its not-sent note');
    }

    /** Buffer: measured 0 off, the real reservation on, null with no config. */
    public function testTheAutocompactBufferReadsTheSessionsRealConfig(): void
    {
        $window = 100_000;
        $history = [Message::user('hi')];

        $off = ContextCategories::from(ContextBreakdown::measure($history, null, null, $window, 10, compactorConfig: CompactorConfig::new()->withMode(CompactorConfig::MODE_OFF)));
        self::assertSame(0, $off->tokensOf(ContextCategory::AutocompactBuffer), 'compaction off reserves nothing — a measured zero, not an unknown');

        $llm = CompactorConfig::new();
        $expected = $window - $llm->backgroundCompactionTokenThreshold($window);
        self::assertGreaterThan(0, $expected, 'fixture discipline: the default tier must sit under the window');
        $on = ContextCategories::from(ContextBreakdown::measure($history, null, null, $window, 10, compactorConfig: $llm));
        self::assertSame($expected, $on->tokensOf(ContextCategory::AutocompactBuffer));
        self::assertSame($window - 10 - $expected, $on->tokensOf(ContextCategory::FreeSpace), 'free space nets the reservation out');

        $unknown = ContextCategories::from(ContextBreakdown::measure($history, null, null, $window, 10));
        self::assertNull($unknown->tokensOf(ContextCategory::AutocompactBuffer));
        self::assertSame($window - 10, $unknown->tokensOf(ContextCategory::FreeSpace));
    }

    /** An over-budget session shows free space as 0 — never negative. */
    public function testFreeSpaceIsNeverNegative(): void
    {
        $cats = ContextCategories::from(ContextBreakdown::measure([Message::user('hi')], null, null, 100, 500));

        self::assertSame(0, $cats->tokensOf(ContextCategory::FreeSpace));
        self::assertSame(500, $cats->tokensOf(ContextCategory::Messages));
    }

    /** Every case names its category row, and every row's key is translated. */
    public function testEveryCategoryHasItsLabelKey(): void
    {
        $en = require __DIR__ . '/../../lang/en.php';
        foreach (ContextCategory::cases() as $case) {
            self::assertArrayHasKey('cmd.context.category.' . $case->value, $en, $case->name . ' has no label key');
            self::assertStringStartsNotWith('cmd.', $case->label(), 'the key resolves, not the fallback');
        }
        foreach (['cmd.context.category.header', 'cmd.context.category.unmeasured', 'cmd.context.category.mcp.instructions', 'cmd.context.category.mcp.not-sent'] as $key) {
            self::assertArrayHasKey($key, $en);
        }
    }

    /** forSectionLabel() is total: nothing escapes the map without landing in System prompt. */
    public function testTheSectionMapIsTotal(): void
    {
        foreach (['base', 'maxims', 'tool-guidance', 'env', 'system-reminder', 'anything-new'] as $layer) {
            self::assertSame(ContextCategory::SystemPrompt, ContextCategory::forSectionLabel($layer), $layer);
        }
        self::assertSame(ContextCategory::MemoryFiles, ContextCategory::forSectionLabel('user-rules'));
        self::assertSame(ContextCategory::MemoryFiles, ContextCategory::forSectionLabel('project-instructions'));
        self::assertSame(ContextCategory::ProjectMemory, ContextCategory::forSectionLabel('project-memory'));
        self::assertSame(ContextCategory::RepositoryMap, ContextCategory::forSectionLabel('symbol-map'));
        self::assertSame(ContextCategory::Skills, ContextCategory::forSectionLabel('available-skills'));
    }

    private function skill(string $name, string $body): Skill
    {
        return new Skill(
            name: $name,
            description: 'Fixture skill for the category attribution test.',
            userInvocable: true,
            disableModelInvocation: false,
            allowedTools: null,
            disallowedTools: null,
            model: null,
            effort: 'low',
            context: 'thread',
            paths: [],
            content: $body,
            sourcePath: '/fixture/' . $name . '.md',
        );
    }

    /** A manager carrying the fence-rendering roster (an empty one would fall back to the sentence form). */
    private function rosterManager(): AgentManager
    {
        $manager = new AgentManager($this->createMock(ProviderInterface::class), new SkillRegistry());
        $manager->register(RosterAgent::named('ctx-attrib-worker'));

        return $manager;
    }

    /**
     * The real dispatcher pair, off the same frozen lazy-shape seam the
     * request ships them from; the dead client path can never answer, and
     * the fat descriptors are what make the shape lazy at all.
     *
     * @return array{0: Tool, 1: Tool}
     */
    private function dispatcherPair(): array
    {
        $shape = McpToolShape::derive(
            new McpClient('/nonexistent/cc-ctxcats-' . getmypid() . '/.mcp.json'),
            [
                McpTool::fromArray(['name' => 'wide-one', 'description' => 'first ' . str_repeat('d', 3000) . ' last', 'inputSchema' => ['type' => 'object', 'properties' => ['note' => ['type' => 'string']], 'required' => []]], 'ctx-cats'),
                McpTool::fromArray(['name' => 'wide-two', 'description' => 'first ' . str_repeat('e', 3000) . ' last', 'inputSchema' => ['type' => 'object', 'properties' => ['note' => ['type' => 'string']], 'required' => []]], 'ctx-cats'),
            ],
            [],
            10,
        );
        self::assertTrue($shape->lazy, 'fixture discipline: these descriptors must land dispatched');

        return [$shape->listTool(), $shape->callTool()];
    }

    /** The same controlled Runtime the panel reads layers through, over the fixture root. */
    private function panelRuntime(App $app): Runtime
    {
        return new Runtime(
            $app->provider,
            new HookManager(new HookRegistry()),
            new EnvironmentBlock($this->fixture?->root() ?? '/', $app->model, new \DateTimeImmutable('2026-02-20 08:30:00 UTC'), 'linux'),
        );
    }
}
