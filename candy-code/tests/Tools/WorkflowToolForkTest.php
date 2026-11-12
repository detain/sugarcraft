<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Tools;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Agents\AgentResult;
use SugarCraft\Code\Agents\AgentStatus;
use SugarCraft\Code\Backend\EngineBackend;
use SugarCraft\Code\Permissions\PermissionAction;
use SugarCraft\Code\Permissions\PermissionGate;
use SugarCraft\Code\Permissions\PermissionMode;
use SugarCraft\Code\Permissions\PermissionRule;
use SugarCraft\Code\Providers\CompleteRequest;
use SugarCraft\Code\Providers\CompleteResponse;
use SugarCraft\Code\Providers\EmbeddingsRequest;
use SugarCraft\Code\Providers\EmbeddingsResponse;
use SugarCraft\Code\Providers\ProviderInterface;
use SugarCraft\Code\Tests\Support\ScriptedProvider;
use SugarCraft\Code\Tools\BuiltIn\WorkflowTool;
use SugarCraft\Code\Tools\Tool;
use SugarCraft\Code\Tools\ToolResult;
use SugarCraft\Code\Workflows\StageResult;
use SugarCraft\Code\Workflows\WorkflowStatus;

/**
 * P2-1: a `parallel: true` stage's agents whose tools cannot ask permission
 * run forked, concurrently; any agent that might ask stays in the turn's
 * process. Every answer names the pid that produced it, which is the
 * observable a fork changes.
 */
final class WorkflowToolForkTest extends TestCase
{
    /** @var list<string> */
    private array $roots = [];

    protected function setUp(): void
    {
        if (!\function_exists('pcntl_fork')) {
            $this->markTestSkipped('needs pcntl_fork()');
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->roots as $root) {
            @rmdir($root);
        }
    }

    public function testReadOnlyAgentsRunForkedAndConcurrently(): void
    {
        $beats = 0;
        $result = $this->tool(['Read', 'Grep'], heartbeat: static function () use (&$beats): void {
            $beats++;
        })->execute(['plan' => self::plan(3, '[Read, Grep]')]);

        $this->assertFalse($result->isError(), $result->content());
        $row = WorkflowToolTest::document($result->content())['stages'][0];
        $this->assertSame('parallel: forked (3 ask-free agents)', $row['dispatch']);

        $pids = self::pids($row['output']);
        $this->assertCount(3, $pids, $row['output']);
        $this->assertCount(3, array_unique($pids), 'each agent ran in its own child');
        $this->assertNotContains((int) getmypid(), $pids, 'no agent ran in the turn process');
        $this->assertGreaterThan(0, $beats, 'the parent beats the turn heartbeat while the children work');
    }

    public function testAnAgentThatMayAskRunsInlineAndTheReportSaysWhy(): void
    {
        $result = $this->tool(['Read', 'Bash'])->execute(['plan' => self::plan(2, null)]);

        $this->assertFalse($result->isError(), $result->content());
        $row = WorkflowToolTest::document($result->content())['stages'][0];
        $this->assertSame('parallel: inline (agent "a0" may ask: Bash; 1 more inline)', $row['dispatch']);
        $this->assertSame([(int) getmypid(), (int) getmypid()], self::pids($row['output']));
    }

    /**
     * The test reads the tools the agent's REQUEST carries — what is really
     * on its wire. Every parallel agent rides its own `tools:` grant (C4), so
     * one stage can be mixed: the Bash agent stays in the turn's process
     * while its Read-only sibling forks — in either declaration order, which
     * proves the judgement is per agent, not the first task's.
     */
    public function testEachAgentIsJudgedOnTheToolsItsRequestCarries(): void
    {
        $plan = "stages:\n  - name: sweep\n    parallel: true\n    agents:\n"
            . "      - {type: x, name: shell, prompt: one, tools: [Bash]}\n"
            . "      - {type: x, name: reader, prompt: two, tools: [Read]}\n";
        $reversed = "stages:\n  - name: sweep\n    parallel: true\n    agents:\n"
            . "      - {type: x, name: reader, prompt: two, tools: [Read]}\n"
            . "      - {type: x, name: shell, prompt: one, tools: [Bash]}\n";

        foreach (['shell first' => $plan, 'reader first' => $reversed] as $label => $yaml) {
            $result = $this->tool(['Read', 'Bash'])->execute(['plan' => $yaml]);

            $this->assertFalse($result->isError(), $result->content());
            $row = WorkflowToolTest::document($result->content())['stages'][0];
            $this->assertSame('parallel: 1 forked, 1 inline (agent "shell" may ask: Bash)', $row['dispatch'], $label);
            $pids = self::pids($row['output']);
            $this->assertCount(2, $pids, $label);
            $this->assertSame(1, count(array_filter($pids, static fn (int $pid): bool => $pid === (int) getmypid())), $label . ': exactly the shell agent ran in the turn process');
            $this->assertCount(2, array_unique($pids), $label . ': the reader ran in a child of its own');
        }
    }

    public function testTheSummaryCountsAMixedStage(): void
    {
        $stage = new StageResult('sweep', WorkflowStatus::Completed, agents: [
            new AgentResult(agentId: 'a', status: AgentStatus::Completed),
            new AgentResult(agentId: 'b', status: AgentStatus::Completed),
            new AgentResult(agentId: 'c', status: AgentStatus::Completed),
        ]);
        $log = [
            'a' => ['agent' => 'reader', 'forked' => true, 'why' => null],
            'b' => ['agent' => 'shell', 'forked' => false, 'why' => 'may ask: Bash'],
            'c' => ['agent' => 'other', 'forked' => true, 'why' => null],
        ];

        $this->assertSame('parallel: 2 forked, 1 inline (agent "shell" may ask: Bash)', WorkflowTool::dispatchSummary($stage, $log));
        $this->assertNull(WorkflowTool::dispatchSummary($stage, []), 'no logged dispatch, no claim');
        $this->assertSame(
            'parallel: inline (agent "shell": pcntl_fork() failed)',
            WorkflowTool::dispatchSummary($stage, ['b' => ['agent' => 'shell', 'forked' => false, 'why' => 'pcntl_fork() failed']]),
        );
    }

    public function testAnAskRuleOnAReadToolKeepsTheAgentsInline(): void
    {
        $gate = new PermissionGate(PermissionMode::Default, [new PermissionRule('Read(*.env)', PermissionAction::Ask)]);
        $result = $this->tool(['Read'], gate: $gate)->execute(['plan' => self::plan(2, '[Read]')]);

        $row = WorkflowToolTest::document($result->content())['stages'][0];
        $this->assertStringStartsWith('parallel: inline (agent "a0" may ask: an Ask rule "Read(*.env)" covers some Read calls', $row['dispatch']);
        $this->assertSame([(int) getmypid(), (int) getmypid()], self::pids($row['output']));
    }

    /**
     * B3: the test is the gate's own "could this ask?" — a write tool the
     * session already allows outright cannot ask, so its agents fork too.
     */
    public function testAWriteToolTheSessionAllowsOutrightForks(): void
    {
        foreach ([
            'allow rule' => new PermissionGate(PermissionMode::Default, [new PermissionRule('Bash', PermissionAction::Allow)]),
            'bypass' => new PermissionGate(PermissionMode::BypassPermissions),
        ] as $label => $gate) {
            $result = $this->tool(['Read', 'Bash'], gate: $gate)->execute(['plan' => self::plan(2, '[Read, Bash]')]);

            $this->assertFalse($result->isError(), $result->content());
            $row = WorkflowToolTest::document($result->content())['stages'][0];
            $this->assertSame('parallel: forked (2 ask-free agents)', $row['dispatch'], $label);
            $this->assertNotContains((int) getmypid(), self::pids($row['output']), $label);
        }
    }

    /**
     * The executor hands the agent's own `tools:` grant to the judgement, so a
     * scoped grant the session allows outright forks, while the same stage
     * under a broader grant (bare `Bash`) stays inline.
     */
    public function testAScopedGrantTheSessionAllowsForks(): void
    {
        $gate = new PermissionGate(PermissionMode::Default, [new PermissionRule('Bash(vendor/bin/phpunit *)', PermissionAction::Allow)]);

        $scoped = $this->tool(['Read', 'Bash'], gate: $gate)->execute(['plan' => self::plan(2, '[Read, "Bash(vendor/bin/phpunit *)"]')]);
        $this->assertFalse($scoped->isError(), $scoped->content());
        $row = WorkflowToolTest::document($scoped->content())['stages'][0];
        $this->assertSame('parallel: forked (2 ask-free agents)', $row['dispatch']);

        $bare = $this->tool(['Read', 'Bash'], gate: $gate)->execute(['plan' => self::plan(2, '[Read, Bash]')]);
        $row = WorkflowToolTest::document($bare->content())['stages'][0];
        $this->assertStringStartsWith('parallel: inline', $row['dispatch']);
    }

    public function testASequentialStageCarriesNoDispatchKey(): void
    {
        $result = $this->tool(['Read'])->execute(['plan' => "stages:\n  - name: one\n    prompt: go\n    tools: [Read]\n"]);

        $row = WorkflowToolTest::document($result->content())['stages'][0];
        $this->assertArrayNotHasKey('dispatch', $row);
        $this->assertSame([(int) getmypid()], self::pids($row['output']));
    }

    private static function plan(int $agents, ?string $tools): string
    {
        $yaml = "stages:\n  - name: sweep\n    parallel: true\n    agents:\n";
        for ($i = 0; $i < $agents; $i++) {
            $yaml .= sprintf("      - {type: x, name: a%d, prompt: p%d%s}\n", $i, $i, $tools === null ? '' : ', tools: ' . $tools);
        }

        return $yaml;
    }

    /** @return list<int> */
    private static function pids(string $output): array
    {
        preg_match_all('/pid=(\d+)/', $output, $m);

        return array_map('intval', $m[1]);
    }

    /** @param list<string> $tools */
    private function tool(array $tools, ?\Closure $heartbeat = null, ?PermissionGate $gate = null): WorkflowTool
    {
        $dir = sys_get_temp_dir() . '/crush-workflow-tool-fork-' . bin2hex(random_bytes(6));
        mkdir($dir, 0o700);
        $this->roots[] = $dir;

        $engine = EngineBackend::new(self::pidProvider(), 'm')->withTools(array_map(self::probe(...), $tools));
        if ($gate !== null) {
            $engine = $engine->withPermissionGate($gate);
        }

        return (new WorkflowTool($dir))->withEngine($engine, $heartbeat);
    }

    /**
     * Answers every call with the pid that made it, after a short pause so a
     * forked run leaves the parent idling (where it beats the heartbeat).
     */
    private static function pidProvider(): ProviderInterface
    {
        return new class (new ScriptedProvider([])) implements ProviderInterface {
            public function __construct(private ScriptedProvider $inner)
            {
            }

            public function name(): string
            {
                return 'pid-probe';
            }

            public function supportsStreaming(): bool
            {
                return false;
            }

            public function supportsFunctionCalling(): bool
            {
                return true;
            }

            public function supportsVision(): bool
            {
                return false;
            }

            public function supportsDocuments(): bool
            {
                return false;
            }

            public function supportsJsonSchema(): bool
            {
                return false;
            }

            public function contextWindow(): int
            {
                return $this->inner->contextWindow();
            }

            public function costPer1kTokens(string $model, string $direction): ?float
            {
                return null;
            }

            public function complete(CompleteRequest $request): CompleteResponse
            {
                usleep(150_000);

                return new CompleteResponse(content: 'pid=' . getmypid());
            }

            public function completeStream(CompleteRequest $request): \Generator
            {
                yield $this->complete($request);
            }

            public function embeddings(EmbeddingsRequest $request): EmbeddingsResponse
            {
                return $this->inner->embeddings($request);
            }
        };
    }

    private static function probe(string $name): Tool
    {
        return new class ($name) implements Tool {
            public function __construct(private string $name)
            {
            }

            public function name(): string
            {
                return $this->name;
            }

            public function description(): string
            {
                return 'probe';
            }

            public function inputSchema(): array
            {
                return ['type' => 'object', 'properties' => []];
            }

            public function execute(array $args): ToolResult
            {
                return new ToolResult((string) ($args['id'] ?? ''), 'ok');
            }
        };
    }
}
