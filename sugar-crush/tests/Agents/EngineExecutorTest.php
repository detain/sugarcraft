<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Agents;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Agents\AgentManager;
use SugarCraft\Crush\Agents\AgentStatus;
use SugarCraft\Crush\Agents\AgentWorkerPool;
use SugarCraft\Crush\Agents\EngineExecutor;
use SugarCraft\Crush\Agents\SubAgent;
use SugarCraft\Crush\Backend\EngineBackend;
use SugarCraft\Crush\Messages\Message as TypedMessage;
use SugarCraft\Crush\Providers\CompleteRequest;
use SugarCraft\Crush\Providers\CompleteResponse;
use SugarCraft\Crush\Skills\SkillRegistry;
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
        $this->assertSame('bound now', $executor->execute(self::subAgent(), self::request())->output);
    }

    public function testTheOfflineEchoFallbackIsRefusedNotPassedOffAsWork(): void
    {
        $executor = new EngineExecutor(EngineBackend::new(new \SugarCraft\Crush\Providers\EchoProvider(), 'echo'));

        $result = $executor->execute(self::subAgent(), self::request());

        $this->assertSame(AgentStatus::Failed, $result->status);
        $this->assertStringContainsString('No provider configured', $result->error?->getMessage() ?? '');
    }

    public function testTheStreamIsAnActivityLogFollowedByTheFinalAnswer(): void
    {
        $provider = new ScriptedProvider([
            new CompleteResponse(content: 'looking first', toolCalls: [new ToolCall('call_1', 'probe', ['path' => "src/\nA.php"])]),
            new CompleteResponse(content: 'the answer'),
        ]);
        $executor = new EngineExecutor(EngineBackend::new($provider, 'm')->withRoot($this->emptyRoot())->withTools([self::probe()]));

        $results = iterator_to_array($executor->executeStream(self::subAgent(), self::request()), false);
        $final = array_pop($results);
        $streamed = implode('', array_map(static fn ($r): string => (string) $r->output, $results));

        $this->assertSame(AgentStatus::Completed, $final->status);
        $this->assertSame('the answer', $final->output, 'the terminal result is the answer alone');
        foreach ($results as $partial) {
            $this->assertSame(AgentStatus::Streaming, $partial->status);
        }
        // describeToolCall() JSON-encodes values, so the newline arrives as
        // the two characters `\n` and the tool line stays one line.
        $this->assertSame("looking first\n▸ probe(path: \"src/\\nA.php\")\nthe answer", $streamed);
    }

    public function testTokenDeltasAreCoalescedRatherThanAppendedOneByOne(): void
    {
        // A streaming provider's many tiny chunks: every delta inside one flush
        // window must leave as ONE streamed result, not fifty file appends.
        $chunky = new ScriptedProvider(
            [array_fill(0, 50, new CompleteResponse(content: 't'))],
            streams: true,
        );
        $results = iterator_to_array(
            (new EngineExecutor(EngineBackend::new($chunky, 'm')->withRoot($this->emptyRoot())))->executeStream(self::subAgent(), self::request()),
            false,
        );

        $this->assertCount(2, $results, 'one coalesced partial, then the terminal result');
        $this->assertSame(str_repeat('t', 50), $results[0]->output);
    }

    /**
     * THE PANE'S VIEW, through the real forking pool: the parent's SubAgent
     * carries the running agent's partial output WHILE the child is still
     * working, not only once it has finished. The pool is driven inside a
     * Fiber so its idle() suspends on every poll — exactly how the TUI's
     * workflow Fiber drives it — and the sample is taken between polls.
     */
    public function testAStageAgentsPartialOutputReachesTheParentBeforeItFinishes(): void
    {
        if (!function_exists('pcntl_fork')) {
            $this->markTestSkipped('forking needs ext-pcntl');
        }

        $provider = new ScriptedProvider([
            new CompleteResponse(content: 'surveying the lib', toolCalls: [new ToolCall('call_1', 'probe', [])]),
            static function (): CompleteResponse {
                usleep(800_000);

                return new CompleteResponse(content: 'finished report');
            },
        ]);
        $pool = new AgentWorkerPool(forkedExecutor: new EngineExecutor(EngineBackend::new($provider, 'm')->withTools([self::probe()])));
        $subAgent = self::subAgent();

        $results = [];
        $fiber = new \Fiber(static function () use ($pool, $subAgent, &$results): void {
            foreach ($pool->executeAll([$subAgent], self::request()) as $result) {
                $results[] = $result;
            }
        });

        $liveSamples = [];
        $fiber->start();
        $deadline = microtime(true) + 20.0;
        while (!$fiber->isTerminated() && microtime(true) < $deadline) {
            if ($results === [] && $subAgent->output !== '') {
                $liveSamples[] = $subAgent->output;
            }
            usleep(20_000);
            $fiber->resume();
        }

        $this->assertTrue($fiber->isTerminated(), 'the run settled');
        $this->assertNotSame([], $liveSamples, 'partial output was visible before the agent finished');
        $this->assertStringContainsString('surveying the lib', $liveSamples[0]);
        // The final step's prose streams too, so the LAST sample may hold it;
        // what matters is a sample taken inside the 800ms tool-then-think window.
        $midRun = array_filter(
            $liveSamples,
            static fn (string $s): bool => str_contains($s, '▸ probe()') && !str_contains($s, 'finished report'),
        );
        $this->assertNotSame([], $midRun, 'the pane saw the tool call while the agent was still thinking');
        $this->assertSame('finished report', $results[0]->output ?? null);
    }

    /**
     * A SEQUENTIAL stage is visible to the pane too: it now dispatches through
     * the workflow's AgentManager, whose liveOutputs() is what the pane reads,
     * so its partial output shows there while the stage is still running.
     */
    public function testASequentialStagesLiveOutputReachesTheManagerThePaneReads(): void
    {
        if (!function_exists('pcntl_fork')) {
            $this->markTestSkipped('forking needs ext-pcntl');
        }

        $provider = new ScriptedProvider([
            new CompleteResponse(content: 'reading the lib', toolCalls: [new ToolCall('call_1', 'probe', [])]),
            static function (): CompleteResponse {
                usleep(800_000);

                return new CompleteResponse(content: 'sequential report');
            },
        ]);
        $manager = new AgentManager($provider, new SkillRegistry());
        $registry = new WorkflowRegistry();
        $workflows = new WorkflowEngine($registry, new AgentWorkerPool(forkedExecutor: new EngineExecutor()));
        $workflows->setAgentManager($manager);
        $workflows->bindEngineBackend(EngineBackend::new($provider, 'm')->withTools([self::probe()]));
        $registry->register(
            (new WorkflowBuilder())
                ->name('live-seq')
                ->description('one sequential stage that pauses mid-run')
                ->stage('only', Tasks::agent('coder')->prompt('look'))
                ->build(),
        );

        $result = null;
        $fiber = new \Fiber(static function () use ($workflows, &$result): void {
            $result = $workflows->run('live-seq');
        });

        $live = [];
        $fiber->start();
        $deadline = microtime(true) + 20.0;
        while (!$fiber->isTerminated() && microtime(true) < $deadline) {
            $live[] = $manager->liveOutputs()['coder'] ?? '';
            usleep(20_000);
            $fiber->resume();
        }

        $this->assertTrue($result?->isSuccess() ?? false);
        $this->assertSame('sequential report', $result->stageResults[0]->output);
        $midRun = array_filter($live, static fn (string $o): bool => str_contains($o, '▸ probe()') && !str_contains($o, 'sequential report'));
        $this->assertNotSame([], $midRun, 'the sequential stage painted live, not only at the end');
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

    /**
     * The 2026-10-02 step-budget decision: an agent that declares no
     * `maxTurns` gets 200 steps, the same figure as TaskTool's sub-agents.
     * Sixty tool steps then an answer is an ordinary stage, and under the old
     * cap of 50 it ended with no report at all. Each step's arguments differ,
     * because sixty IDENTICAL calls are a loop: the repeat-call guard
     * (`ToolCallLoopGuard`) ends that turn at the eighth.
     */
    public function testAnUndeclaredStepCapIsTwoHundredSteps(): void
    {
        $this->assertSame(200, EngineExecutor::DEFAULT_MAX_TURNS);

        $script = [];
        for ($step = 1; $step <= 60; $step++) {
            $script[] = new CompleteResponse(content: '', toolCalls: [new ToolCall('call_' . $step, 'probe', ['step' => $step])]);
        }
        $script[] = new CompleteResponse(content: 'sixty steps later');
        $probe = self::probe();
        $executor = new EngineExecutor(EngineBackend::new(new ScriptedProvider($script), 'm')->withTools([$probe]));

        $result = $executor->execute(self::subAgent(), self::request());

        $this->assertSame(AgentStatus::Completed, $result->status, (string) $result->error?->getMessage());
        $this->assertSame('sixty steps later', $result->output);
        $this->assertSame(60, $probe->calls);
    }

    /**
     * Audit WF-1-rem: where the pool cannot fork (no pcntl, a failed fork) it
     * runs this executor INLINE and cannot interrupt it, so the agent's own
     * timeout must be enforced here. Each tool step takes 0.6 s against a
     * 1 s timeout: the run stops at the first progress event past the
     * deadline instead of working through all ten steps.
     */
    public function testARunPastTheAgentsOwnTimeoutStopsAtTheNextEventAndSettlesTimedOut(): void
    {
        $script = [];
        for ($step = 1; $step <= 10; $step++) {
            $script[] = new CompleteResponse(content: '', toolCalls: [new ToolCall('call_' . $step, 'probe', [])]);
        }
        $script[] = new CompleteResponse(content: 'should never get here');
        $probe = self::probe(sleepMicroseconds: 600_000);
        $executor = new EngineExecutor(EngineBackend::new(new ScriptedProvider($script), 'm')->withTools([$probe]));
        $agent = new SubAgent(id: 'slow-stage', agent: RosterAgent::named('coder'), task: 'loop', timeout: 1);

        $began = microtime(true);
        $result = $executor->execute($agent, self::request());
        $elapsed = microtime(true) - $began;

        $this->assertSame(AgentStatus::TimedOut, $result->status);
        $this->assertStringContainsString('ran past its 1 s timeout', (string) $result->error?->getMessage());
        $this->assertLessThan(3.0, $elapsed, 'the inline run ignored the agent\'s timeout');
        $this->assertLessThan(10, $probe->calls, 'every step ran: nothing enforced the timeout');
    }

    public function testANonPositiveTimeoutPlacesNoBoundOnTheRun(): void
    {
        $provider = new ScriptedProvider([
            new CompleteResponse(content: '', toolCalls: [new ToolCall('call_1', 'probe', [])]),
            new CompleteResponse(content: 'unbounded report'),
        ]);
        $executor = new EngineExecutor(EngineBackend::new($provider, 'm')->withTools([self::probe()]));
        $agent = new SubAgent(id: 'unbounded-stage', agent: RosterAgent::named('coder'), task: 'look', timeout: 0);

        $result = $executor->execute($agent, self::request());

        $this->assertSame(AgentStatus::Completed, $result->status, 'timeout 0 must mean "no bound", not "already expired"');
    }

    /** @var list<string> */
    private array $emptyRoots = [];

    protected function tearDown(): void
    {
        foreach ($this->emptyRoots as $dir) {
            @rmdir($dir);
        }
        $this->emptyRoots = [];
        parent::tearDown();
    }

    /**
     * An empty, non-git project root for the two tests that time the stream.
     * Their assertions depend on the first token arriving inside one
     * STREAM_FLUSH_SECONDS window, and a turn rooted at the cwd first shells
     * out to `git status` for the <env> block: in a large checkout with a
     * dirty tree that alone was measured at 0.43 s, past the 0.25 s window.
     */
    private function emptyRoot(): string
    {
        $dir = sys_get_temp_dir() . '/crush-engine-executor-' . bin2hex(random_bytes(6));
        mkdir($dir, 0o700);
        $this->emptyRoots[] = $dir;

        return $dir;
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
    private static function probe(int $sleepMicroseconds = 0): Tool
    {
        return new class ($sleepMicroseconds) implements Tool {
            public int $calls = 0;

            public function __construct(private readonly int $sleepMicroseconds)
            {
            }

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
                if ($this->sleepMicroseconds > 0) {
                    usleep($this->sleepMicroseconds);
                }

                return new ToolResult(toolCallId: (string) ($args['id'] ?? ''), content: 'probed');
            }
        };
    }
}
