<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Tools;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Backend\EngineBackend;
use SugarCraft\Crush\Context\TurnContextBlock;
use SugarCraft\Crush\Messages\Message as TypedMessage;
use SugarCraft\Crush\Permissions\PermissionGate;
use SugarCraft\Crush\Permissions\PermissionMode;
use SugarCraft\Crush\Providers\CompleteRequest;
use SugarCraft\Crush\Providers\CompleteResponse;
use SugarCraft\Crush\Runtime;
use SugarCraft\Crush\Tests\Support\ScriptedProvider;
use SugarCraft\Crush\Tools\BuiltIn\TaskTool;
use SugarCraft\Crush\Tools\BuiltIn\WorkflowTool;
use SugarCraft\Crush\Tools\Catalog\ToolCatalog;
use SugarCraft\Crush\Tools\Catalog\ToolPermissionClass;
use SugarCraft\Crush\Tools\DelegatesToEngine;
use SugarCraft\Crush\Tools\ExemptFromParallelDeadline;
use SugarCraft\Crush\Tools\ParallelSafe;
use SugarCraft\Crush\Tools\Tool;
use SugarCraft\Crush\Tools\ToolCall;
use SugarCraft\Crush\Tools\ToolResult;

/**
 * Roadmap 4.10-2: the model runs a YAML plan of its own through
 * {@see \SugarCraft\Crush\Workflows\WorkflowEngine}. Every run here is real —
 * the tool's own per-call engine and pool, its stage agents on the bound
 * engine's tool loop — and inline, so the scripted provider's requests are
 * visible in this process.
 */
final class WorkflowToolTest extends TestCase
{
    private const TWO_STAGES = <<<'YAML'
        name: survey-then-fix
        stages:
          - name: survey
            agent: explorer
            prompt: List the callers of {{symbol}}.
            tools: [Read]
          - name: fix
            prompt: "Update every caller: {{survey.output}}"
            tools: [Read, Edit]
        YAML;

    /** @var list<string> */
    private array $roots = [];

    protected function tearDown(): void
    {
        foreach ($this->roots as $root) {
            @rmdir($root);
        }
    }

    public function testThePlansStagesRunInOrderOnTheBoundEngineAndEachOutputIsReported(): void
    {
        $read = self::probe('Read');
        $provider = new ScriptedProvider([
            new CompleteResponse(content: '', toolCalls: [new ToolCall('c1', 'Read', ['path' => 'src/A.php'])]),
            new CompleteResponse(content: 'A.php and B.php call it'),
            new CompleteResponse(content: 'both callers updated'),
        ]);

        $result = $this->tool($provider, [$read, self::probe('Edit')])->execute([
            'id' => 'call_wf',
            'plan' => self::TWO_STAGES,
            'context' => ['symbol' => 'Foo::bar'],
        ]);

        $this->assertFalse($result->isError(), $result->content());
        $this->assertSame('call_wf', $result->toolCallId());
        $this->assertSame([['path' => 'src/A.php']], $read->calls, 'the stage agent ran its own tool loop');
        $this->assertStringStartsWith("Workflow 'survey-then-fix' completed: 2 of 2 stages succeeded", $result->content());
        $this->assertStringContainsString("### Stage 1: survey (completed)\nA.php and B.php call it", $result->content());
        $this->assertStringContainsString("### Stage 2: fix (completed)\nboth callers updated", $result->content());

        $this->assertSame('List the callers of Foo::bar.', self::lastUserTurn($provider->requests[0]), 'context interpolated');
        $this->assertSame('Update every caller: A.php and B.php call it', self::lastUserTurn($provider->requests[2]), 'stage output chained');
        $this->assertSame(['Read', 'Edit'], self::toolNames($provider->requests[2]), 'the stage got exactly its declared tools');
    }

    public function testAStageAgentNeverGetsTaskOrWorkflowEvenWhenItDeclaresNoTools(): void
    {
        $provider = new ScriptedProvider([new CompleteResponse(content: 'done')]);
        $tools = [self::probe('Read'), new TaskTool(), new WorkflowTool()];

        $result = $this->tool($provider, $tools)->execute(['plan' => "stages:\n  - name: only\n    prompt: do it\n"]);

        $this->assertFalse($result->isError(), $result->content());
        $this->assertSame(['Read'], self::toolNames($provider->requests[0]), 'a plan cannot recurse or fan out further');
        $this->assertStringStartsWith("Workflow 'plan' completed", $result->content(), 'name defaults to "plan"');
    }

    public function testAnArgumentScopedStageGrantHoldsEveryCallTheStageAgentMakes(): void
    {
        $bash = self::probe('Bash');
        $provider = new ScriptedProvider([
            new CompleteResponse(content: '', toolCalls: [
                new ToolCall('c1', 'Bash', ['command' => 'git status']),
                new ToolCall('c2', 'Bash', ['command' => 'git log && rm x']),
            ]),
            new CompleteResponse(content: 'reviewed'),
        ]);

        $this->tool($provider, [$bash])->execute(['plan' => "stages:\n  - name: review\n    prompt: review\n    tools: [\"Bash(git *)\"]\n"]);

        $this->assertSame([['command' => 'git status']], $bash->calls);
        $turns = self::toolTurns($provider->requests[1]);
        $this->assertStringContainsString('outside the tool grant agent "coder" declares [Bash(git *)]', $turns[1]);
    }

    public function testAFailedStageStopsTheRunAndTheResultIsAnErrorNamingWhatDidNotRun(): void
    {
        $provider = new ScriptedProvider([new \RuntimeException('upstream 503')]);

        $result = $this->tool($provider, [self::probe('Read'), self::probe('Edit')])->execute(['plan' => self::TWO_STAGES]);

        $this->assertTrue($result->isError());
        $this->assertStringStartsWith("Workflow 'survey-then-fix' failed: 0 of 2 stages succeeded", $result->content());
        $this->assertStringContainsString("### Stage 1: survey (failed)\nError: upstream 503", $result->content());
        $this->assertStringContainsString('1 later stage did not run.', $result->content());
        $this->assertCount(1, $provider->requests, 'the second stage was never dispatched');
    }

    public function testAPlanThatIsNotValidYamlIsRefusedWithTheParsersReasonAndNothingRuns(): void
    {
        $provider = new ScriptedProvider([]);

        $result = $this->tool($provider, [])->execute(['plan' => "stages: [\n  - name: x"]);

        $this->assertTrue($result->isError());
        $this->assertStringContainsString('Error: the plan was not run. Workflow file (Workflow tool plan) is not valid YAML:', $result->content());
        $this->assertSame([], $provider->requests);
    }

    public function testAPlanTheLoaderRefusesIsReportedAsTheLoaderSaysIt(): void
    {
        $result = $this->tool(new ScriptedProvider([]), [])->execute([
            'plan' => "stages:\n  - name: a\n    prompt: x\n  - name: a\n    prompt: y\n",
        ]);

        $this->assertTrue($result->isError());
        $this->assertStringContainsString('has two stages with the same name', $result->content());
    }

    public function testADeclaredToolTheSessionDoesNotHaveFailsTheStageBeforeItRuns(): void
    {
        $provider = new ScriptedProvider([]);

        $result = $this->tool($provider, [self::probe('Read')])->execute(['plan' => "stages:\n  - name: a\n    prompt: x\n    tools: [Write]\n"]);

        $this->assertTrue($result->isError());
        $this->assertStringContainsString('declares tool "Write", which this session\'s tool registry does not contain', $result->content());
        $this->assertSame([], $provider->requests);
    }

    public function testADeclarationTheSessionsModeDeniesFailsTheRunBeforeAnyStage(): void
    {
        $provider = new ScriptedProvider([]);
        $engine = EngineBackend::new($provider, 'm')
            ->withTools([self::probe('Read'), self::probe('Bash')])
            ->withPermissionGate(new PermissionGate(PermissionMode::DontAsk));

        $result = (new WorkflowTool($this->root()))->withEngine($engine)->execute([
            'plan' => "stages:\n  - name: look\n    prompt: x\n    tools: [Read]\n  - name: run\n    prompt: y\n    tools: [Bash]\n",
        ]);

        $this->assertTrue($result->isError());
        $this->assertStringContainsString('declares tool "Bash", which this session\'s permission mode (dont-ask) denies', $result->content());
        $this->assertSame([], $provider->requests, 'a refusal in stage 2 costs no stage 1 work');
    }

    public function testBadArgumentsAreRefusedWithoutRunningAnything(): void
    {
        $tool = $this->tool(new ScriptedProvider([]), []);

        $this->assertStringContainsString('needs a non-empty "plan"', $tool->execute([])->content());
        $this->assertStringContainsString('needs a non-empty "plan"', $tool->execute(['plan' => '  '])->content());
        $this->assertStringContainsString('"context" must be an object', $tool->execute(['plan' => 'stages: []', 'context' => ['a', 'b']])->content());
        $this->assertStringContainsString('"context.k" must be a string', $tool->execute(['plan' => 'stages: []', 'context' => ['k' => ['x']]])->content());
        $reserved = $tool->execute(['plan' => 'stages: []', 'context' => ['@results' => 'x']]);
        $this->assertTrue($reserved->isError());
        $this->assertStringContainsString('@results', $reserved->content());
    }

    public function testAnUnboundToolRefusesRatherThanFabricating(): void
    {
        $result = (new WorkflowTool())->execute(['plan' => "stages:\n  - name: a\n    prompt: x\n"]);

        $this->assertTrue($result->isError());
        $this->assertStringContainsString('none is bound to this call', $result->content());
    }

    public function testEveryStageAgentBeatsTheTurnsHeartbeat(): void
    {
        $provider = new ScriptedProvider([
            new CompleteResponse(content: '', toolCalls: [new ToolCall('c1', 'Read', [])]),
            new CompleteResponse(content: 'ok'),
        ]);
        $beats = 0;
        $tool = (new WorkflowTool($this->root()))->withEngine(
            EngineBackend::new($provider, 'm')->withTools([self::probe('Read')]),
            static function () use (&$beats): void {
                $beats++;
            },
        );

        $tool->execute(['plan' => "stages:\n  - name: a\n    prompt: x\n"]);

        $this->assertGreaterThan(0, $beats);
    }

    public function testALongStageOutputKeepsItsEndsAndElidesItsMiddle(): void
    {
        $long = str_repeat('é', WorkflowTool::STAGE_OUTPUT_MAX_BYTES) . 'THE-END';
        $provider = new ScriptedProvider([new CompleteResponse(content: 'START-' . $long)]);

        $content = $this->tool($provider, [])->execute(['plan' => "stages:\n  - name: a\n    prompt: x\n"])->content();

        $this->assertStringContainsString("\nSTART-", $content);
        $this->assertStringEndsWith('THE-END', $content);
        $this->assertMatchesRegularExpression('/\[… \d+ bytes elided …\]/', $content);
        $this->assertTrue(mb_check_encoding($content, 'UTF-8'), 'the cut never splits a character');
        $this->assertLessThan(WorkflowTool::STAGE_OUTPUT_MAX_BYTES + 400, strlen($content));
    }

    /**
     * WorkflowEngine::runWorkflow() installs no R28 interrupt handlers: the
     * tool runs it inside the forked turn child, where the handler's plain
     * exit() would run shutdown over the TUI's copied object graph, and a
     * plan has no pause file to write anyway.
     */
    public function testThePlanRunsWithoutTouchingTheProcesssSignalHandlers(): void
    {
        if (!function_exists('pcntl_signal_get_handler')) {
            $this->markTestSkipped('needs ext-pcntl');
        }

        $before = pcntl_signal_get_handler(\SIGTERM);
        $during = null;
        $provider = new ScriptedProvider([
            static function () use (&$during): CompleteResponse {
                $during = pcntl_signal_get_handler(\SIGTERM);

                return new CompleteResponse(content: 'ok');
            },
        ]);

        $this->tool($provider, [])->execute(['plan' => "stages:\n  - name: a\n    prompt: x\n"]);

        $this->assertNotNull($during, 'the stage ran');
        $this->assertSame($before, $during);
    }

    public function testTheToolIsCatalogDiscoveredWriteCapableAndBindsPerCall(): void
    {
        $this->assertSame(ToolPermissionClass::Write, ToolCatalog::permissionOf(WorkflowTool::NAME));
        $this->assertContains(WorkflowTool::NAME, Runtime::WRITE_CAPABLE_TOOL_NAMES);
        $this->assertContains(WorkflowTool::class, array_map(static fn ($e): string => $e->class, ToolCatalog::built()));

        $unbound = new WorkflowTool();
        $this->assertInstanceOf(DelegatesToEngine::class, $unbound);
        $this->assertInstanceOf(ExemptFromParallelDeadline::class, $unbound);
        $this->assertNotInstanceOf(ParallelSafe::class, $unbound);

        $bound = $unbound->withEngine(EngineBackend::new(new ScriptedProvider([]), 'm'));
        $this->assertNotSame($unbound, $bound);
        $this->assertStringContainsString('none is bound', $unbound->execute(['plan' => 'stages: []'])->content(), 'binding returns a copy');
    }

    /**
     * @param list<Tool> $tools
     */
    private function tool(ScriptedProvider $provider, array $tools): WorkflowTool
    {
        return (new WorkflowTool($this->root()))->withEngine(EngineBackend::new($provider, 'm')->withTools($tools));
    }

    private function root(): string
    {
        $dir = sys_get_temp_dir() . '/crush-workflow-tool-' . bin2hex(random_bytes(6));
        mkdir($dir, 0o700);
        $this->roots[] = $dir;

        return $dir;
    }

    private static function lastUserTurn(CompleteRequest $request): string
    {
        // The `<turn-context>` row (step 1.A-1) is a user-role row of its own.
        $users = array_values(array_filter(
            $request->messages,
            static fn (TypedMessage $m): bool => $m->role() === 'user' && !TurnContextBlock::isTurnContext($m),
        ));

        return $users === [] ? '' : $users[count($users) - 1]->content();
    }

    /**
     * @return list<string>
     */
    private static function toolNames(CompleteRequest $request): array
    {
        return array_map(static fn (Tool $t): string => $t->name(), $request->tools ?? []);
    }

    /**
     * @return list<string>
     */
    private static function toolTurns(CompleteRequest $request): array
    {
        return array_values(array_map(
            static fn (TypedMessage $message): string => $message->content(),
            array_filter($request->messages, static fn (TypedMessage $message): bool => $message->role() === 'tool'),
        ));
    }

    /**
     * @return Tool&object{calls: list<array<string, mixed>>}
     */
    private static function probe(string $name): Tool
    {
        return new class ($name) implements Tool {
            /** @var list<array<string, mixed>> */
            public array $calls = [];

            public function __construct(private string $name)
            {
            }

            public function name(): string
            {
                return $this->name;
            }

            public function description(): string
            {
                return 'records its calls';
            }

            public function inputSchema(): array
            {
                return ['type' => 'object', 'properties' => []];
            }

            public function execute(array $args): ToolResult
            {
                $id = (string) ($args['id'] ?? '');
                unset($args['id']);
                $this->calls[] = $args;

                return new ToolResult(toolCallId: $id, content: $this->name . ' ok');
            }
        };
    }
}
