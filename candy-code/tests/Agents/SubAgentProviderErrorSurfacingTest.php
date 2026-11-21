<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Agents;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Agents\AgentManager;
use SugarCraft\Code\Agents\AgentStatus;
use SugarCraft\Code\Agents\AgentWorkerPool;
use SugarCraft\Code\Agents\EngineExecutor;
use SugarCraft\Code\Agents\SubAgent;
use SugarCraft\Code\Agents\SuspendedDelegations;
use SugarCraft\Code\Backend\EngineBackend;
use SugarCraft\Code\Providers\CompleteRequest;
use SugarCraft\Code\Providers\CompleteResponse;
use SugarCraft\Code\Providers\CustomProvider;
use SugarCraft\Code\Providers\ProviderInterface;
use SugarCraft\Code\Providers\ProviderResponseException;
use SugarCraft\Code\Providers\TransientFailure;
use SugarCraft\Code\Skills\SkillRegistry;
use SugarCraft\Code\Tests\Support\RosterAgent;
use SugarCraft\Code\Tests\Support\ScriptedProvider;
use SugarCraft\Code\Tools\BuiltIn\TaskTool;
use SugarCraft\Code\Tools\Tool;
use SugarCraft\Code\Tools\ToolCall;
use SugarCraft\Code\Tools\ToolResult;
use SugarCraft\Code\Workflows\Tasks;
use SugarCraft\Code\Workflows\WorkflowBuilder;
use SugarCraft\Code\Workflows\WorkflowEngine;
use SugarCraft\Code\Workflows\WorkflowRegistry;

/**
 * Audit 15e AG-4, the sub-agent twin of 15a A1: a provider that reports a
 * failed call as an `isError` response (CustomProvider - and so the
 * `anthropic` type - and VertexProvider) must FAIL a sub-agent with the
 * provider's own text, on every road a sub-agent runs. AG-4 was filed
 * against the old direct-call sub-agent entry point, which had its own
 * provider seam and settled the sub-agent COMPLETE with empty output; every
 * sub-agent run here is now a Task delegation (CC1C-c2b) or a workflow
 * stage. The Task tool's engine path and the workflow {@see EngineExecutor}
 * run through {@see \SugarCraft\Code\Runtime}, which A1 fixed; these pin
 * that the thrown {@see ProviderResponseException} reaches the Task result,
 * the sub-agent row and the workflow stage as a failure.
 *
 * Transient error responses are still retried - the throw sits after each
 * retry loop, never inside it.
 */
final class SubAgentProviderErrorSurfacingTest extends TestCase
{
    private const UNAUTHORIZED_BODY = '{"error":{"message":"Incorrect API key provided"}}';

    private const PROVIDER_TEXT = 'Incorrect API key provided';

    /**
     * Five queued 401s, so an unwanted retry shows up as a drained queue
     * rather than as a MockHandler "queue is empty" error that could itself
     * be mistaken for the surfaced failure.
     */
    private static function unauthorizedProvider(bool $streaming, ?MockHandler &$mock = null): CustomProvider
    {
        $mock = new MockHandler(array_fill(
            0,
            5,
            new Response(401, ['Content-Type' => 'application/json'], self::UNAUTHORIZED_BODY),
        ));

        return new CustomProvider(
            'custom',
            'http://provider.invalid',
            'm',
            null,
            new Client(['handler' => HandlerStack::create($mock), 'base_uri' => 'http://provider.invalid/']),
            $streaming,
            true,
        );
    }

    /** @return iterable<string, array{bool}> */
    public static function streamingModes(): iterable
    {
        yield 'stream=true' => [true];
        yield 'stream=false' => [false];
    }

    /**
     * Delegate one `worker` run through {@see TaskTool} on an engine over
     * $provider and hand back the Task result and the row it settled.
     *
     * @param list<Tool> $tools the session's tool set
     * @return array{0: ToolResult, 1: SubAgent}
     */
    private static function delegatedWorkerOn(ProviderInterface $provider, array $tools = []): array
    {
        $manager = new AgentManager(new ScriptedProvider([]), new SkillRegistry());
        $manager->register(RosterAgent::named('worker'));
        $storeDir = sys_get_temp_dir() . '/sc_ag4_task_' . bin2hex(random_bytes(6));

        try {
            $result = (new TaskTool($manager, suspended: new SuspendedDelegations($storeDir)))
                ->withEngine(EngineBackend::new($provider, 'm')->withoutHooks()->withTools($tools))
                ->execute([
                    'description' => 'Audit sugar-core',
                    'prompt' => 'Audit sugar-core and report the findings',
                    'agent' => 'worker',
                    'id' => 'task-call',
                ]);
        } finally {
            foreach (glob($storeDir . '/*') ?: [] as $file) {
                @unlink($file);
            }
            @rmdir($storeDir);
        }

        $rows = $manager->subAgentsOf('worker');
        self::assertCount(1, $rows, 'the Task call ran exactly one delegation');

        return [$result, $rows[0]];
    }

    // -------------------------------------------------------------------------
    // The Task tool's engine path.
    // -------------------------------------------------------------------------

    /**
     * The seam AG-4 was filed against, on the Task path: a 401 fails the
     * delegation with the provider's text on the parent's Task result and
     * on the dashboard row, and is never retried.
     */
    #[DataProvider('streamingModes')]
    public function testTheTaskEnginePathFailsTheRowAndTellsTheParentTheProviderMessage(bool $streaming): void
    {
        $mock = null;
        [$result, $row] = self::delegatedWorkerOn(self::unauthorizedProvider($streaming, $mock));

        $this->assertTrue($result->isError(), 'the parent turn must see a failure, not an empty report');
        $this->assertStringContainsString(self::PROVIDER_TEXT, $result->content());

        $this->assertSame(SubAgent::STATUS_FAILED, $row->status, 'the dashboard row settles failed');
        $this->assertStringContainsString(self::PROVIDER_TEXT, (string) $row->error);
        $this->assertNotNull($row->completedAt, 'a failed sub-agent stops its elapsed clock');
        $this->assertSame(4, $mock->count(), 'a 401 is permanent: exactly one request, no retry');
    }

    /**
     * Partial text, then a non-transient error chunk: the partial text must
     * not stand as the answer, and the failed response's tool calls must
     * never run - the run fails with the provider's message.
     */
    public function testAStreamedErrorChunkAfterPartialOutputFailsTheSubAgentBeforeItsToolCallsRun(): void
    {
        $provider = new ScriptedProvider([[
            new CompleteResponse(content: 'half ', toolCalls: [new ToolCall('call_1', 'Write', ['file_path' => 'x'])]),
            new CompleteResponse(content: '', isError: true, errorMessage: 'model not found', errorTransient: false),
        ]], streams: true);
        $write = new class implements Tool {
            public int $runs = 0;

            public function name(): string
            {
                return 'Write';
            }

            public function description(): string
            {
                return 'counts Write runs';
            }

            public function inputSchema(): array
            {
                return ['type' => 'object'];
            }

            public function execute(array $args): ToolResult
            {
                $this->runs++;

                return new ToolResult(toolCallId: (string) ($args['id'] ?? ''), content: 'written');
            }
        };
        [$result, $row] = self::delegatedWorkerOn($provider, [$write]);

        $this->assertTrue($result->isError(), 'a non-transient error chunk must fail the sub-agent');
        $this->assertStringContainsString('model not found', $result->content());
        $this->assertSame(SubAgent::STATUS_FAILED, $row->status);
        $this->assertSame('sub-agent "worker" failed: model not found', $row->error);
        $this->assertCount(1, $provider->requests);
        $this->assertSame(0, $write->runs, 'the failed response\'s tool call never ran');
    }

    #[DataProvider('streamingModes')]
    public function testATransientErrorResponseIsStillRetriedAndTheRetryIsTheAnswer(bool $streaming): void
    {
        $provider = new ScriptedProvider([
            new CompleteResponse(content: '', isError: true, errorMessage: 'overloaded', errorTransient: true),
            new CompleteResponse(content: 'recovered'),
        ], streams: $streaming);
        [$result, $row] = self::delegatedWorkerOn($provider);

        $this->assertFalse($result->isError(), $result->content());
        $this->assertCount(2, $provider->requests, 'one failed attempt, one retry');
        $this->assertSame(SubAgent::STATUS_COMPLETE, $row->status);
        $this->assertSame('recovered', $row->output);
        $this->assertNull($row->error);
    }

    #[DataProvider('streamingModes')]
    public function testAnExhaustedRunOfTransientErrorResponsesFailsWithTheLastMessage(bool $streaming): void
    {
        $script = [];
        for ($i = 1; $i <= TransientFailure::MAX_ATTEMPTS; $i++) {
            $script[] = new CompleteResponse(content: '', isError: true, errorMessage: 'overloaded #' . $i, errorTransient: true);
        }
        // Past the cap the scripted provider would answer this; reaching it
        // would mean the loop retried once too often.
        $script[] = new CompleteResponse(content: 'never reached');
        $provider = new ScriptedProvider($script, streams: $streaming);
        [$result, $row] = self::delegatedWorkerOn($provider);

        $this->assertTrue($result->isError(), 'an exhausted error-response sequence must fail the sub-agent');
        $this->assertStringContainsString('overloaded #' . TransientFailure::MAX_ATTEMPTS, $result->content());
        $this->assertCount(TransientFailure::MAX_ATTEMPTS, $provider->requests);
        $this->assertSame(SubAgent::STATUS_FAILED, $row->status);
        $this->assertSame('sub-agent "worker" failed: overloaded #' . TransientFailure::MAX_ATTEMPTS, $row->error);
    }

    public function testAnErrorResponseWithoutAMessageFailsWithTheFallbackText(): void
    {
        [$result, $row] = self::delegatedWorkerOn(new ScriptedProvider([new CompleteResponse(content: '', isError: true)]));

        $this->assertTrue($result->isError(), 'an error response without text must still fail the sub-agent');
        $this->assertSame(SubAgent::STATUS_FAILED, $row->status);
        $this->assertSame('sub-agent "worker" failed: ' . ProviderResponseException::FALLBACK_MESSAGE, $row->error);
    }

    // -------------------------------------------------------------------------
    // The workflow EngineExecutor.
    // -------------------------------------------------------------------------

    #[DataProvider('streamingModes')]
    public function testTheEngineExecutorReportsAFailedResultCarryingTheProviderMessage(bool $streaming): void
    {
        $executor = new EngineExecutor(EngineBackend::new(self::unauthorizedProvider($streaming), 'm')->withoutHooks());
        $agent = new SubAgent(id: 'stage-' . bin2hex(random_bytes(4)), agent: RosterAgent::named('coder'), task: 'check the lib');

        $result = $executor->execute($agent, new CompleteRequest(
            model: 'm',
            messages: [['role' => 'user', 'content' => 'check the lib']],
        ));

        $this->assertSame(AgentStatus::Failed, $result->status);
        $this->assertStringContainsString(self::PROVIDER_TEXT, $result->error?->getMessage() ?? '');
        $this->assertNull($result->output, 'no output stands in for the failed answer');
    }

    /**
     * End to end through the real forking pool, the path a TUI `/workflow run`
     * takes: the stage must fail and carry the provider's text, not succeed
     * with nothing for the next stage to work from.
     */
    public function testAWorkflowStageOnAProvider401FailsWithTheProviderMessage(): void
    {
        if (!function_exists('pcntl_fork')) {
            $this->markTestSkipped('forked stages need ext-pcntl');
        }

        $registry = new WorkflowRegistry();
        $workflows = new WorkflowEngine($registry, new AgentWorkerPool(maxConcurrent: 1, forkedExecutor: new EngineExecutor()));
        $workflows->bindEngineBackend(EngineBackend::new(self::unauthorizedProvider(true), 'm')->withoutHooks());
        $registry->register(
            (new WorkflowBuilder())
                ->name('ag4')
                ->description('one stage on a provider that rejects the key')
                ->stage('first', Tasks::agent('coder')->prompt('look first'))
                ->build(),
        );

        $result = $workflows->run('ag4');

        $this->assertFalse($result->isSuccess(), 'a stage whose provider refused must not report success');
        $this->assertStringContainsString(self::PROVIDER_TEXT, (string) ($result->stageResults[0]->error ?? ''));
    }
}
