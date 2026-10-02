<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Agents;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Agents\AgentManager;
use SugarCraft\Crush\Agents\AgentStatus;
use SugarCraft\Crush\Agents\AgentWorkerPool;
use SugarCraft\Crush\Agents\EngineExecutor;
use SugarCraft\Crush\Agents\SubAgent;
use SugarCraft\Crush\Agents\SuspendedDelegations;
use SugarCraft\Crush\Backend\EngineBackend;
use SugarCraft\Crush\Providers\CompleteRequest;
use SugarCraft\Crush\Providers\CompleteResponse;
use SugarCraft\Crush\Providers\CustomProvider;
use SugarCraft\Crush\Providers\ProviderInterface;
use SugarCraft\Crush\Providers\ProviderResponseException;
use SugarCraft\Crush\Providers\TransientFailure;
use SugarCraft\Crush\Skills\SkillRegistry;
use SugarCraft\Crush\Tests\Support\RosterAgent;
use SugarCraft\Crush\Tests\Support\ScriptedProvider;
use SugarCraft\Crush\Tools\BuiltIn\TaskTool;
use SugarCraft\Crush\Tools\ToolCall;
use SugarCraft\Crush\Workflows\Tasks;
use SugarCraft\Crush\Workflows\WorkflowBuilder;
use SugarCraft\Crush\Workflows\WorkflowEngine;
use SugarCraft\Crush\Workflows\WorkflowRegistry;

/**
 * Audit 15e AG-4, the sub-agent twin of 15a A1: a provider that reports a
 * failed call as an `isError` response (CustomProvider - and so the
 * `anthropic` type - and VertexProvider) must FAIL a sub-agent with the
 * provider's own text, on every road a sub-agent runs:
 *
 *   - {@see AgentManager::executeSubAgent()} has its own provider seam and
 *     used to settle the sub-agent COMPLETE with empty output, the error
 *     chunk read only to decide whether to retry. Fixed there.
 *   - The Task tool's engine path and the workflow {@see EngineExecutor}
 *     run through {@see \SugarCraft\Crush\Runtime}, which A1 fixed; these
 *     pin that the thrown {@see ProviderResponseException} reaches the Task
 *     result, the sub-agent row and the workflow stage as a failure.
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
     * @return array{0: AgentManager, 1: SubAgent}
     */
    private static function subAgentOn(ProviderInterface $provider): array
    {
        $manager = new AgentManager($provider, new SkillRegistry());
        $manager->register(RosterAgent::named('worker'));

        return [$manager, $manager->createSubAgent('worker', 'do the thing')];
    }

    // -------------------------------------------------------------------------
    // AgentManager::executeSubAgent() - the seam AG-4 was filed against.
    // -------------------------------------------------------------------------

    #[DataProvider('streamingModes')]
    public function testASubAgentOnACustomProvider401FailsWithTheProviderMessage(bool $streaming): void
    {
        $mock = null;
        [$manager, $subAgent] = self::subAgentOn(self::unauthorizedProvider($streaming, $mock));

        try {
            iterator_to_array($manager->executeSubAgent($subAgent->id));
            $this->fail('a 401 must fail the sub-agent; it settled ' . $subAgent->status . ' with output ' . var_export($subAgent->output, true));
        } catch (ProviderResponseException $e) {
            $this->assertStringContainsString(self::PROVIDER_TEXT, $e->getMessage());
            $this->assertFalse(TransientFailure::isTransient($e), 'an outer retry loop must not re-run it');
        }

        $this->assertSame(SubAgent::STATUS_FAILED, $subAgent->status);
        $this->assertStringContainsString(self::PROVIDER_TEXT, (string) $subAgent->error);
        $this->assertNotNull($subAgent->completedAt, 'a failed sub-agent stops its elapsed clock');
        $this->assertSame(4, $mock->count(), 'a 401 is permanent: exactly one request, no retry');
    }

    /**
     * Partial text, then a non-transient error chunk: the partial text must
     * not stand as the answer, and the failed response's tool calls must not
     * reach the grant check - a call outside the (empty) grant would
     * otherwise replace the provider's message with a grant refusal.
     */
    public function testAStreamedErrorChunkAfterPartialOutputFailsTheSubAgentBeforeItsToolCallsAreJudged(): void
    {
        $provider = new ScriptedProvider([[
            new CompleteResponse(content: 'half ', toolCalls: [new ToolCall('call_1', 'Write', ['file_path' => 'x'])]),
            new CompleteResponse(content: '', isError: true, errorMessage: 'model not found', errorTransient: false),
        ]], streams: true);
        [$manager, $subAgent] = self::subAgentOn($provider);

        try {
            iterator_to_array($manager->executeSubAgent($subAgent->id));
            $this->fail('a non-transient error chunk must fail the sub-agent');
        } catch (ProviderResponseException $e) {
            $this->assertSame('model not found', $e->getMessage());
        }

        $this->assertSame(SubAgent::STATUS_FAILED, $subAgent->status);
        $this->assertSame('model not found', $subAgent->error);
        $this->assertCount(1, $provider->requests);
    }

    #[DataProvider('streamingModes')]
    public function testATransientErrorResponseIsStillRetriedAndTheRetryIsTheAnswer(bool $streaming): void
    {
        $provider = new ScriptedProvider([
            new CompleteResponse(content: '', isError: true, errorMessage: 'overloaded', errorTransient: true),
            new CompleteResponse(content: 'recovered'),
        ], streams: $streaming);
        [$manager, $subAgent] = self::subAgentOn($provider);

        iterator_to_array($manager->executeSubAgent($subAgent->id));

        $this->assertCount(2, $provider->requests, 'one failed attempt, one retry');
        $this->assertSame(SubAgent::STATUS_COMPLETE, $subAgent->status);
        $this->assertSame('recovered', $subAgent->output);
        $this->assertNull($subAgent->error);
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
        [$manager, $subAgent] = self::subAgentOn($provider);

        try {
            iterator_to_array($manager->executeSubAgent($subAgent->id));
            $this->fail('an exhausted error-response sequence must fail the sub-agent');
        } catch (ProviderResponseException $e) {
            $this->assertSame('overloaded #' . TransientFailure::MAX_ATTEMPTS, $e->getMessage());
        }

        $this->assertCount(TransientFailure::MAX_ATTEMPTS, $provider->requests);
        $this->assertSame(SubAgent::STATUS_FAILED, $subAgent->status);
        $this->assertSame('overloaded #' . TransientFailure::MAX_ATTEMPTS, $subAgent->error);
    }

    public function testAnErrorResponseWithoutAMessageFailsWithTheFallbackText(): void
    {
        [$manager, $subAgent] = self::subAgentOn(new ScriptedProvider([new CompleteResponse(content: '', isError: true)]));

        try {
            iterator_to_array($manager->executeSubAgent($subAgent->id));
            $this->fail('an error response without text must still fail the sub-agent');
        } catch (ProviderResponseException) {
            // expected
        }

        $this->assertSame(SubAgent::STATUS_FAILED, $subAgent->status);
        $this->assertSame(ProviderResponseException::FALLBACK_MESSAGE, $subAgent->error);
    }

    // -------------------------------------------------------------------------
    // The engine roads: Task's engine path and the workflow EngineExecutor.
    // -------------------------------------------------------------------------

    #[DataProvider('streamingModes')]
    public function testTheTaskEnginePathFailsTheRowAndTellsTheParentTheProviderMessage(bool $streaming): void
    {
        $storeDir = sys_get_temp_dir() . '/sc_ag4_task_' . bin2hex(random_bytes(6));
        $manager = new AgentManager(new ScriptedProvider([]), new SkillRegistry());
        $manager->register(RosterAgent::named('coder'));
        $engine = EngineBackend::new(self::unauthorizedProvider($streaming), 'm')->withoutHooks();

        try {
            $result = (new TaskTool($manager, suspended: new SuspendedDelegations($storeDir)))
                ->withEngine($engine)
                ->execute([
                    'description' => 'Audit candy-core',
                    'prompt' => 'Audit candy-core and report the findings',
                    'agent' => 'coder',
                ]);
        } finally {
            foreach (glob($storeDir . '/*') ?: [] as $file) {
                @unlink($file);
            }
            @rmdir($storeDir);
        }

        $this->assertTrue($result->isError(), 'the parent turn must see a failure, not an empty report');
        $this->assertStringContainsString(self::PROVIDER_TEXT, $result->content());

        $rows = $manager->subAgentsOf('coder');
        $this->assertCount(1, $rows);
        $this->assertSame(SubAgent::STATUS_FAILED, $rows[0]->status, 'the dashboard row settles failed');
        $this->assertStringContainsString(self::PROVIDER_TEXT, (string) $rows[0]->error);
    }

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
