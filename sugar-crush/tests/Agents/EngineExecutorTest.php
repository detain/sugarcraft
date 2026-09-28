<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Agents;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Agents\AgentStatus;
use SugarCraft\Crush\Agents\AgentWorkerPool;
use SugarCraft\Crush\Agents\EngineExecutor;
use SugarCraft\Crush\Agents\SubAgent;
use SugarCraft\Crush\Backend\EngineBackend;
use SugarCraft\Crush\Messages\Message as TypedMessage;
use SugarCraft\Crush\Providers\CompleteRequest;
use SugarCraft\Crush\Providers\CompleteResponse;
use SugarCraft\Crush\Tests\Support\RosterAgent;
use SugarCraft\Crush\Tests\Support\ScriptedProvider;
use SugarCraft\Crush\Tools\BuiltIn\TaskTool;
use SugarCraft\Crush\Tools\Tool;
use SugarCraft\Crush\Tools\ToolCall;
use SugarCraft\Crush\Tools\ToolResult;
use SugarCraft\Crush\Workflows\Tasks;
use SugarCraft\Crush\Workflows\WorkflowBuilder;
use SugarCraft\Crush\Workflows\WorkflowEngine;
use SugarCraft\Crush\Workflows\WorkflowRegistry;

/**
 * {@see EngineExecutor}: a pooled agent — a `/workflow` stage — is a whole
 * agentic run through the session engine, not one completion. Every scripted
 * agent here CALLS A TOOL FIRST, which is exactly the shape the one-call
 * worker could never finish: it advertised the tool, executed nothing, and
 * the stage ended with empty output.
 */
final class EngineExecutorTest extends TestCase
{
    public function testAStageAgentRunsAToolLoopUntilItAnswers(): void
    {
        $probe = self::probe();
        $provider = new ScriptedProvider([
            new CompleteResponse(content: '', toolCalls: [new ToolCall('call_1', 'probe', [])]),
            new CompleteResponse(content: 'stage report'),
        ]);
        $executor = new EngineExecutor(EngineBackend::new($provider, 'm')->withTools([$probe]));

        $result = $executor->execute(self::subAgent(), self::request(tools: [$probe], systemPrompt: 'You are a stage.'));

        $this->assertSame(AgentStatus::Completed, $result->status);
        $this->assertSame('stage report', $result->output);
        $this->assertSame(1, $probe->calls, 'the tool the stage asked for was executed');
        $this->assertSame(
            [['system', 'You are a stage.'], ['user', 'check the lib']],
            self::turns($provider->requests[0]),
        );
        $this->assertSame('tool', self::turns($provider->requests[1])[3][0] ?? null, 'the result was fed back');
    }

    public function testWithoutAStageGrantItInheritsTheEngineToolsButNeverTask(): void
    {
        $probe = self::probe();
        $provider = new ScriptedProvider([new CompleteResponse(content: 'fine')]);
        $engine = EngineBackend::new($provider, 'm')->withTools([$probe, new TaskTool()]);

        (new EngineExecutor($engine))->execute(self::subAgent(), self::request());

        $this->assertSame(['probe'], array_map(static fn (Tool $t): string => $t->name(), $provider->requests[0]->tools ?? []));
    }

    public function testAnEmptyFinalAnswerIsAFailureNamingTheStepCap(): void
    {
        $provider = new ScriptedProvider([
            new CompleteResponse(content: '', toolCalls: [new ToolCall('call_1', 'probe', [])]),
        ]);
        $executor = new EngineExecutor(EngineBackend::new($provider, 'm')->withTools([self::probe()]));

        $result = $executor->execute(self::subAgent(maxTurns: 2), self::request());

        $this->assertSame(AgentStatus::Failed, $result->status);
        $this->assertStringContainsString('without a final report (step cap 2)', $result->error?->getMessage() ?? '');
    }

    public function testAProviderFailureIsAFailedResultNotAThrow(): void
    {
        $executor = new EngineExecutor(EngineBackend::new(new ScriptedProvider([new \RuntimeException('upstream 503')]), 'm'));

        $result = $executor->execute(self::subAgent(), self::request());

        $this->assertSame(AgentStatus::Failed, $result->status);
        $this->assertSame('upstream 503', $result->error?->getMessage());
    }

    public function testAnUnboundExecutorRefusesAndBindingIsLate(): void
    {
        $executor = new EngineExecutor();
        $this->assertNull($executor->engine());
        $this->assertSame(AgentStatus::Failed, $executor->execute(self::subAgent(), self::request())->status);

        $engine = EngineBackend::new(new ScriptedProvider([new CompleteResponse(content: 'bound now')]), 'm');
        $executor->bind($engine);

        $this->assertSame($engine, $executor->engine());
        $stream = iterator_to_array($executor->executeStream(self::subAgent(), self::request()), false);
        $this->assertCount(1, $stream);
        $this->assertSame('bound now', $stream[0]->output);
    }

    public function testTheOfflineEchoFallbackIsRefusedNotPassedOffAsWork(): void
    {
        $executor = new EngineExecutor(EngineBackend::new(new \SugarCraft\Crush\Providers\EchoProvider(), 'echo'));

        $result = $executor->execute(self::subAgent(), self::request());

        $this->assertSame(AgentStatus::Failed, $result->status);
        $this->assertStringContainsString('No provider configured', $result->error?->getMessage() ?? '');
    }

    /**
     * End to end through the REAL forking pool: a sequential stage and a
     * two-agent parallel stage, each agent calling a tool before answering,
     * each run in the child the pool forks — the path a TUI `/workflow run`
     * takes, where an inline run would freeze the screen.
     */
    public function testAWorkflowsStagesRunTheToolLoopInForkedChildren(): void
    {
        if (!function_exists('pcntl_fork')) {
            $this->markTestSkipped('forked stages need ext-pcntl');
        }

        $provider = new ScriptedProvider([
            new CompleteResponse(content: '', toolCalls: [new ToolCall('call_1', 'probe', [])]),
            new CompleteResponse(content: 'agent report'),
        ]);
        $executor = new EngineExecutor();
        $registry = new WorkflowRegistry();
        $workflows = new WorkflowEngine($registry, new AgentWorkerPool(maxConcurrent: 2, forkedExecutor: $executor));
        $workflows->bindEngineBackend(EngineBackend::new($provider, 'm')->withTools([self::probe()]));
        $registry->register(
            (new WorkflowBuilder())
                ->name('tool-loop')
                ->description('each agent calls a tool before it answers')
                ->stage('first', Tasks::agent('coder')->prompt('look first'))
                ->parallel('fan', [Tasks::agent('a')->prompt('look a'), Tasks::agent('b')->prompt('look b')])
                ->build(),
        );

        $result = $workflows->run('tool-loop');

        $this->assertTrue($result->isSuccess(), (string) ($result->stageResults[0]->error ?? ''));
        $this->assertSame('agent report', $result->stageResults[0]->output);
        $this->assertSame([], $provider->requests, 'every provider call happened in a forked child, none in this process');
    }

    public function testExecuteOneForksWhenAForkedExecutorIsConfigured(): void
    {
        if (!function_exists('pcntl_fork')) {
            $this->markTestSkipped('forking needs ext-pcntl');
        }

        $pidReporter = new EngineExecutor(EngineBackend::new(new ScriptedProvider([
            static fn (): CompleteResponse => new CompleteResponse(content: (string) getmypid()),
        ]), 'm'));
        $pool = new AgentWorkerPool(forkedExecutor: $pidReporter);

        $result = $pool->executeOne(self::subAgent(), self::request());

        $this->assertSame(AgentStatus::Completed, $result->status);
        $this->assertNotSame((string) getmypid(), $result->output, 'the agent ran in a forked child');
    }

    private static function subAgent(?int $maxTurns = null): SubAgent
    {
        return new SubAgent(id: 'stage-' . bin2hex(random_bytes(4)), agent: RosterAgent::named('coder', maxTurns: $maxTurns), task: 'check the lib');
    }

    /**
     * @param ?list<Tool> $tools
     */
    private static function request(?array $tools = null, ?string $systemPrompt = null): CompleteRequest
    {
        return new CompleteRequest(
            model: 'm',
            messages: [['role' => 'user', 'content' => 'check the lib']],
            tools: $tools,
            systemPrompt: $systemPrompt,
        );
    }

    /**
     * @return list<array{0: string, 1: string}>
     */
    private static function turns(CompleteRequest $request): array
    {
        return array_map(static fn (TypedMessage $m): array => [$m->role(), $m->content()], $request->messages);
    }

    /**
     * @return Tool&object{calls: int}
     */
    private static function probe(): Tool
    {
        return new class () implements Tool {
            public int $calls = 0;

            public function name(): string
            {
                return 'probe';
            }

            public function description(): string
            {
                return 'counts its calls';
            }

            public function inputSchema(): array
            {
                return ['type' => 'object', 'properties' => []];
            }

            public function execute(array $args): ToolResult
            {
                $this->calls++;

                return new ToolResult(toolCallId: (string) ($args['id'] ?? ''), content: 'probed');
            }
        };
    }
}
