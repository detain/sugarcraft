<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Workflows;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Agents\Agent;
use SugarCraft\Code\Agents\AgentManager;
use SugarCraft\Code\Agents\AgentWorkerPool;
use SugarCraft\Code\Agents\EngineExecutor;
use SugarCraft\Code\Agents\StructuredOutputTool;
use SugarCraft\Code\Agents\SubAgent;
use SugarCraft\Code\Backend\EngineBackend;
use SugarCraft\Code\Providers\CompleteRequest;
use SugarCraft\Code\Providers\CompleteResponse;
use SugarCraft\Code\Skills\SkillRegistry;
use SugarCraft\Code\Tests\Support\RosterAgent;
use SugarCraft\Code\Tests\Support\ScriptedProvider;
use SugarCraft\Code\ToolCall as GrantCall;
use SugarCraft\Code\Tools\Tool;
use SugarCraft\Code\Tools\ToolCall;
use SugarCraft\Code\Tools\ToolResult;
use SugarCraft\Code\Workflows\Tasks;
use SugarCraft\Code\Workflows\WorkflowEngine;
use SugarCraft\Code\Workflows\WorkflowRegistry;
use SugarCraft\Code\Workflows\WorkflowStatus;

/**
 * C6 (wf-impl): `tools: []` means NO tools — on the real executor, for every
 * stage shape — while an absent `tools:` keeps the session's tools. Before
 * this an empty list collapsed into "absent" and granted the session's full,
 * unpoliced set: a "toolless" judge could Read and run Bash.
 */
final class WorkflowToollessTaskTest extends TestCase
{
    /** @var array<string, int> tool name => times it ran */
    private array $calls = [];

    public function testAnEmptyToolListCannotCallReadOrBash(): void
    {
        $provider = new ScriptedProvider([
            new CompleteResponse(content: '', toolCalls: [new ToolCall('c1', 'Read', ['file_path' => 'x']), new ToolCall('c2', 'Bash', ['command' => 'ls'])]),
            new CompleteResponse(content: 'judged'),
        ]);

        $result = $this->runYaml($provider, "stages:\n  - name: judge\n    prompt: judge it\n    tools: []\n");

        self::assertSame(WorkflowStatus::Completed, $result->status, (string) $result->firstFailure()?->error);
        self::assertSame([], $this->calls, 'neither Read nor Bash ran');
        self::assertNull($provider->requests[0]->tools, 'no tool block went on the wire');
    }

    public function testAnAbsentToolsKeyKeepsTheSessionsTools(): void
    {
        $provider = new ScriptedProvider([
            new CompleteResponse(content: '', toolCalls: [new ToolCall('c1', 'Read', ['file_path' => 'x']), new ToolCall('c2', 'Bash', ['command' => 'ls'])]),
            new CompleteResponse(content: 'judged'),
        ]);

        $result = $this->runYaml($provider, "stages:\n  - name: judge\n    prompt: judge it\n");

        self::assertSame(WorkflowStatus::Completed, $result->status, (string) $result->firstFailure()?->error);
        self::assertSame(['Read' => 1, 'Bash' => 1], $this->calls);
        self::assertSame(['Read', 'Bash'], self::wireNames($provider->requests[0]));
    }

    public function testASchemadToollessAgentStillAnswersThroughStructuredOutput(): void
    {
        $provider = new ScriptedProvider([
            new CompleteResponse(content: '', toolCalls: [new ToolCall('c1', StructuredOutputTool::NAME, ['verdict' => 'pass'])]),
        ]);

        $result = $this->runYaml(
            $provider,
            "stages:\n  - name: judge\n    prompt: judge it\n    tools: []\n"
            . "    outputSchema: {type: object, properties: {verdict: {type: string}}, required: [verdict]}\n",
        );

        self::assertSame(WorkflowStatus::Completed, $result->status, (string) $result->firstFailure()?->error);
        self::assertSame([StructuredOutputTool::NAME], self::wireNames($provider->requests[0]), 'the answer channel and nothing else');
        self::assertSame('{"verdict":"pass"}', $result->stageResults[0]->output);
    }

    public function testEachParallelAgentKeepsItsOwnReading(): void
    {
        $provider = new ScriptedProvider([
            static fn (CompleteRequest $r): CompleteResponse => new CompleteResponse(content: 'a: ' . implode(',', self::wireNames($r))),
        ]);

        $result = $this->runYaml(
            $provider,
            "stages:\n  - name: fan\n    parallel: true\n    agents:\n"
            . "      - {name: none, prompt: a, tools: []}\n"
            . "      - {name: reader, prompt: b, tools: [Read]}\n"
            . "      - {name: silent, prompt: c}\n",
        );

        self::assertSame(WorkflowStatus::Completed, $result->status, (string) $result->firstFailure()?->error);
        $byAgent = [];
        foreach ($provider->requests as $request) {
            $byAgent[] = self::wireNames($request);
        }
        self::assertEqualsCanonicalizing([[], ['Read'], ['Read', 'Bash']], $byAgent);
    }

    public function testFanOutAndVerifierPanelsHonourAnEmptyList(): void
    {
        $provider = new ScriptedProvider([
            static fn (CompleteRequest $r): CompleteResponse => new CompleteResponse(content: 'PASS ' . implode(',', self::wireNames($r))),
        ]);

        $result = $this->runYaml(
            $provider,
            "stages:\n"
            . "  - name: wave\n    parallel: true\n    from: {context: files}\n    prompt: 'fix {{item}}'\n    tools: []\n"
            . "  - name: check\n    type: verification\n    task: {prompt: do it, tools: [Read]}\n"
            . "    verifier: {prompt: 'judge {{prevResult}} {{lens}}', tools: [], votes: 2, lenses: [a, b]}\n",
            ['files' => ['A.php', 'B.php']],
        );

        self::assertSame(WorkflowStatus::Completed, $result->status, (string) $result->firstFailure()?->error);
        $names = array_map(self::wireNames(...), $provider->requests);
        self::assertSame([[], [], ['Read'], [], []], $names, 'two toolless wave agents, the task on [Read], two toolless votes');
    }

    public function testAnExplicitEmptyListOverridesAPresetsTools(): void
    {
        $provider = new ScriptedProvider([new CompleteResponse(content: 'ok')]);

        $result = $this->runYaml(
            $provider,
            "stages:\n  - name: judge\n    prompt: judge it\n    preset: reader\n    tools: []\n",
            roster: [RosterAgent::named('reader', ['Read', 'Bash'])],
        );

        self::assertSame(WorkflowStatus::Completed, $result->status, (string) $result->firstFailure()?->error);
        self::assertSame([], self::wireNames($provider->requests[0]));

        $silent = new ScriptedProvider([new CompleteResponse(content: 'ok')]);
        $this->runYaml($silent, "stages:\n  - name: judge\n    prompt: judge it\n    preset: reader\n", roster: [RosterAgent::named('reader', ['Read'])]);
        self::assertSame(['Read'], self::wireNames($silent->requests[0]), 'silence still takes the preset\'s tools');
    }

    public function testThePhpBuilderSpellsBothReadings(): void
    {
        self::assertTrue(Tasks::agent('a')->prompt('p')->tools([])->build()->declaresNoTools());
        self::assertFalse(Tasks::agent('a')->prompt('p')->build()->declaresNoTools());
        self::assertFalse(Tasks::agent('a')->prompt('p')->tools(['Read'])->build()->declaresNoTools());
    }

    public function testTheGrantHookRefusesEveryCallButTheAnswerChannel(): void
    {
        $manager = new AgentManager(new ScriptedProvider([]), new SkillRegistry());
        $toolless = new SubAgent(id: 's', agent: RosterAgent::named('judge'), task: 't', toolless: true);
        $silent = new SubAgent(id: 's2', agent: RosterAgent::named('judge'), task: 't');

        self::assertStringContainsString('declares tools: [] (no tools)', (string) $manager->grantRefusalFor(new GrantCall('Read', []), $toolless));
        self::assertNull($manager->grantRefusalFor(new GrantCall(StructuredOutputTool::NAME, []), $toolless));
        self::assertNull($manager->grantRefusalFor(new GrantCall('Read', []), $silent));
    }

    /**
     * @param array<string, mixed> $context
     * @param list<Agent>|null $roster
     */
    private function runYaml(ScriptedProvider $provider, string $yaml, array $context = [], ?array $roster = null): \SugarCraft\Code\Workflows\WorkflowResult
    {
        $this->calls = [];
        $tools = [$this->tool('Read'), $this->tool('Bash')];
        $backend = EngineBackend::new($provider, 'm')->withTools($tools);
        $registry = new WorkflowRegistry(sys_get_temp_dir() . '/wf-toolless-unused');
        $engine = new WorkflowEngine(
            $registry,
            new AgentWorkerPool(executor: new EngineExecutor($backend)),
            toolRegistry: $tools,
        );
        if ($roster !== null) {
            $engine->setAgentRoster($roster);
        }

        return $engine->runWorkflow($registry->fromYamlString($yaml), $context);
    }

    /** @return list<string> */
    private static function wireNames(CompleteRequest $request): array
    {
        return array_map(static fn (Tool $t): string => $t->name(), $request->tools ?? []);
    }

    private function tool(string $name): Tool
    {
        $calls = &$this->calls;

        return new class ($name, $calls) implements Tool {
            /** @param array<string, int> $calls */
            public function __construct(private readonly string $name, private array &$calls)
            {
            }

            public function name(): string
            {
                return $this->name;
            }

            public function description(): string
            {
                return $this->name;
            }

            public function inputSchema(): array
            {
                return ['type' => 'object', 'properties' => []];
            }

            public function execute(array $args): ToolResult
            {
                $this->calls[$this->name] = ($this->calls[$this->name] ?? 0) + 1;

                return new ToolResult(toolCallId: '', content: 'ran');
            }
        };
    }
}
