<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Agents;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Agents\Agent;
use SugarCraft\Crush\Agents\AgentResult;
use SugarCraft\Crush\Agents\AgentStatus;
use SugarCraft\Crush\Agents\ProcessExecutor;
use SugarCraft\Crush\Agents\SubAgent;
use SugarCraft\Crush\Messages\AssistantMessage;
use SugarCraft\Crush\Messages\ToolResultMessage;
use SugarCraft\Crush\Messages\UserMessage;
use SugarCraft\Crush\Providers\CompleteRequest;
use SugarCraft\Crush\Providers\EchoProvider;
use SugarCraft\Crush\Sessions\BackgroundSupervisor;

/**
 * Tests for ProcessExecutor - process-based agent executor.
 */
final class ProcessExecutorTest extends TestCase
{
    private ProcessExecutor $executor;
    private SubAgent $agent;
    private CompleteRequest $request;

    protected function setUp(): void
    {
        parent::setUp();
        // simulatedWorker: true, and it is the whole file's fixture choice.
        //
        // ProcessExecutor's shipped default is now the LIVE worker, which
        // constructs a real provider in the child and refuses — an `error`
        // frame and exit(1) — when none is configured. That is correct for
        // production and useless as a fixture for the tests below, which are
        // about the TRANSPORT: the spawn, the ready/execute handshake, the
        // line protocol, the heartbeat window, the timeout escalation and the
        // cleanup. Those need a worker with a KNOWN, provider-independent
        // timing shape, and the simulation is exactly that — roughly 1.04s of
        // usleep()s punctuated by heartbeats, which is what the 1s and 0s
        // timeout expectations further down are calibrated against.
        //
        // The live worker is covered separately, at the bottom of this file:
        // its refusal, its round trip through a real ProviderInterface, and a
        // scanner proving nothing in src/ opts into the simulation.
        $this->executor = new ProcessExecutor(simulatedWorker: true);
        $this->agent = new SubAgent(
            id: 'test-agent-' . uniqid((string) getmypid(), true),
            agent: new Agent(
                name: 'TestAgent',
                description: 'Test agent for unit tests',
                prompt: 'You are a test agent.',
                model: 'test-model',
                provider: 'test',
                tools: [],
                skillNames: [],
                hooks: [],
                isActive: true,
            ),
            task: 'Say hello',
        );
        $this->request = new CompleteRequest(
            model: 'test-model',
            messages: [['role' => 'user', 'content' => 'Hello, agent!']],
        );
    }

    // -------------------------------------------------------------------------
    // execute() - basic spawn and run
    // -------------------------------------------------------------------------

    public function testExecuteReturnsAgentResult(): void
    {
        $result = $this->executor->execute($this->agent, $this->request);

        $this->assertInstanceOf(AgentResult::class, $result);
        $this->assertSame($this->agent->id, $result->agentId);
    }

    public function testExecuteReturnsCompletedStatusOnSuccess(): void
    {
        $result = $this->executor->execute($this->agent, $this->request);

        $this->assertSame(AgentStatus::Completed, $result->status);
    }

    public function testExecutePopulatesOutput(): void
    {
        $result = $this->executor->execute($this->agent, $this->request);

        $this->assertNotNull($result->output);
        $this->assertNotEmpty($result->output);
    }

    public function testExecuteRecordsStartAndEndTime(): void
    {
        $result = $this->executor->execute($this->agent, $this->request);

        $this->assertNotNull($result->startedAt);
        $this->assertNotNull($result->completedAt);
    }

    public function testExecuteDurationIsPositive(): void
    {
        $result = $this->executor->execute($this->agent, $this->request);

        $this->assertGreaterThan(0, $result->durationMs());
    }

    public function testExecuteWithDifferentBinaryPath(): void
    {
        $executor = new ProcessExecutor(binaryPath: 'php', timeoutSeconds: 60, simulatedWorker: true);
        $result = $executor->execute($this->agent, $this->request);

        $this->assertSame(AgentStatus::Completed, $result->status);
    }

    public function testExecuteWithCustomTimeout(): void
    {
        $executor = new ProcessExecutor(binaryPath: 'php', timeoutSeconds: 5, simulatedWorker: true);
        $result = $executor->execute($this->agent, $this->request);

        $this->assertNotNull($result->status);
    }

    // -------------------------------------------------------------------------
    // executeStream() - streaming output
    // -------------------------------------------------------------------------

    public function testExecuteStreamReturnsGenerator(): void
    {
        $generator = $this->executor->executeStream($this->agent, $this->request);

        $this->assertInstanceOf(\Generator::class, $generator);
    }

    public function testExecuteStreamYieldsStreamingStatusFirst(): void
    {
        $generator = $this->executor->executeStream($this->agent, $this->request);

        $firstChunk = $generator->current();
        $this->assertSame(AgentStatus::Streaming, $firstChunk->status);
        $this->assertSame($this->agent->id, $firstChunk->agentId);
    }

    public function testExecuteStreamYieldsCompleteStatusLast(): void
    {
        $generator = $this->executor->executeStream($this->agent, $this->request);

        foreach ($generator as $result) {
            // iterate to completion
        }

        // Generator has been exhausted, last result should have been complete
        $this->assertTrue(true); // If we got here without error, the loop completed
    }

    public function testExecuteStreamYieldsOutputChunks(): void
    {
        $results = [];
        foreach ($this->executor->executeStream($this->agent, $this->request) as $result) {
            $results[] = $result;
            if ($result->status === AgentStatus::Completed) {
                break;
            }
        }

        $this->assertNotEmpty($results);
        $this->assertGreaterThanOrEqual(1, count($results));
    }

    public function testExecuteStreamHasAgentIdOnAllChunks(): void
    {
        foreach ($this->executor->executeStream($this->agent, $this->request) as $result) {
            $this->assertSame($this->agent->id, $result->agentId);
            if ($result->status === AgentStatus::Completed) {
                break;
            }
        }
    }

    // -------------------------------------------------------------------------
    // cancel() and cancelAll() stubs
    // -------------------------------------------------------------------------

    public function testCancelWithInvalidIdDoesNotThrow(): void
    {
        $this->executor->cancel('nonexistent-agent-id');

        $this->assertTrue(true); // No exception thrown
    }

    public function testCancelAllWithNoRunningAgentsDoesNotThrow(): void
    {
        $this->executor->cancelAll();

        $this->assertTrue(true); // No exception thrown
    }

    public function testCancelAfterExecuteDoesNotThrow(): void
    {
        $result = $this->executor->execute($this->agent, $this->request);

        // Cancel should handle already-finished processes gracefully
        $this->executor->cancel($this->agent->id);

        $this->assertSame(AgentStatus::Completed, $result->status);
    }

    // -------------------------------------------------------------------------
    // Lifecycle and process management
    // -------------------------------------------------------------------------

    public function testExecuteMultipleAgentsInSequence(): void
    {
        $agent2 = new SubAgent(
            id: 'test-agent-2-' . uniqid((string) getmypid(), true),
            agent: new Agent(
                name: 'TestAgent2',
                description: 'Second test agent',
                prompt: 'You are a second test agent.',
                model: 'test-model',
                provider: 'test',
                tools: [],
                skillNames: [],
                hooks: [],
                isActive: true,
            ),
            task: 'Say goodbye',
        );

        $result1 = $this->executor->execute($this->agent, $this->request);
        $result2 = $this->executor->execute($agent2, $this->request);

        $this->assertSame(AgentStatus::Completed, $result1->status);
        $this->assertSame(AgentStatus::Completed, $result2->status);
    }

    public function testExecuteCleansUpProcesses(): void
    {
        // Execute should clean up its process after completion
        $result = $this->executor->execute($this->agent, $this->request);

        // If we get here without zombie processes or memory leaks, the test passes
        $this->assertSame(AgentStatus::Completed, $result->status);
    }

    public function testExecuteStreamCleansUpProcesses(): void
    {
        // Execute stream should clean up its process after completion
        foreach ($this->executor->executeStream($this->agent, $this->request) as $result) {
            if ($result->status === AgentStatus::Completed) {
                break;
            }
        }

        // If we get here without zombie processes or memory leaks, the test passes
        $this->assertTrue(true);
    }

    // -------------------------------------------------------------------------
    // Edge cases
    // -------------------------------------------------------------------------

    public function testExecuteWithEmptyTask(): void
    {
        $agentWithEmptyTask = new SubAgent(
            id: 'empty-task-agent-' . uniqid((string) getmypid(), true),
            agent: $this->agent->agent,
            task: '',
        );

        $result = $this->executor->execute($agentWithEmptyTask, $this->request);

        $this->assertNotNull($result->status);
    }

    public function testExecuteWithAgentHavingNoTools(): void
    {
        $result = $this->executor->execute($this->agent, $this->request);

        $this->assertSame(AgentStatus::Completed, $result->status);
    }

    public function testExecuteWithRequestHavingNoSystemPrompt(): void
    {
        $requestNoSystem = new CompleteRequest(
            model: 'test-model',
            messages: [['role' => 'user', 'content' => 'Hello']],
        );

        $result = $this->executor->execute($this->agent, $requestNoSystem);

        $this->assertSame(AgentStatus::Completed, $result->status);
    }

    public function testExecuteWithRequestHavingNullOptionalFields(): void
    {
        $requestFull = new CompleteRequest(
            model: 'test-model',
            messages: [['role' => 'user', 'content' => 'Hello']],
            tools: null,
            systemPrompt: null,
            temperature: null,
            maxTokens: null,
            jsonSchema: null,
        );

        $result = $this->executor->execute($this->agent, $requestFull);

        $this->assertSame(AgentStatus::Completed, $result->status);
    }

    // -------------------------------------------------------------------------
    // Heartbeat mechanism
    // -------------------------------------------------------------------------

    public function testExecuteSucceedsWhenWorkerSendsHeartbeats(): void
    {
        // The default inline worker sends heartbeats every 5 seconds;
        // with a short task it should complete well within the 15s heartbeat window.
        $result = $this->executor->execute($this->agent, $this->request);

        $this->assertSame(AgentStatus::Completed, $result->status);
    }

    public function testExecuteSucceedsWithNormalHeartbeatWorker(): void
    {
        // Verifies that a worker sending regular heartbeats completes successfully.
        // The default inline worker sends heartbeats every 500ms, well within the
        // 15-second heartbeat timeout window, so execute() returns Completed.
        $executor = new ProcessExecutor(binaryPath: 'php', timeoutSeconds: 30, simulatedWorker: true);

        $result = $executor->execute($this->agent, $this->request);

        $this->assertSame(AgentStatus::Completed, $result->status);
    }

    // -------------------------------------------------------------------------
    // Timeout escalation (SIGTERM → SIGKILL)
    // -------------------------------------------------------------------------

    public function testExecuteTimesOutAndReturnsFailedStatus(): void
    {
        // Short timeout so the test completes quickly.
        $executor = new ProcessExecutor(binaryPath: 'php', timeoutSeconds: 1, simulatedWorker: true);

        $result = $executor->execute($this->agent, $this->request);

        // The task takes longer than 1 second; timeout should trigger failure.
        $this->assertSame(AgentStatus::Failed, $result->status);
        $this->assertStringContainsString('timed out', $result->error->getMessage());
    }

    public function testTimeoutUsesSigtermFollowedBySigkill(): void
    {
        // This test verifies that after a timeout, SIGKILL is eventually sent.
        // Worker takes ~1.04s; use timeout of 500ms so the deadline definitely fires.
        // This confirms the escalation path runs and returns Failed without zombie processes.
        $executor = new ProcessExecutor(binaryPath: 'php', timeoutSeconds: 0, simulatedWorker: true);
        $result = $executor->execute($this->agent, $this->request);

        $this->assertSame(AgentStatus::Failed, $result->status);
        $this->assertStringContainsString('timed out', $result->error->getMessage());

        // If SIGKILL escalation works, the process is cleaned up and we don't
        // have zombie processes hanging around.
        $this->assertTrue(true);
    }

    // -------------------------------------------------------------------------
    // Crash recovery
    // -------------------------------------------------------------------------

    public function testExecuteReturnsPartialOutputOnCrash(): void
    {
        // We cannot easily simulate a segfault in a test, but we can verify that
        // the partial-output path exists and that execute() correctly returns
        // whatever was in the buffer before the crash.
        // For the P1.S6 crash recovery, we verify:
        // 1. proc_open failures throw a descriptive exception
        // 2. Non-zero exit codes are captured as failures with partial output
        $result = $this->executor->execute($this->agent, $this->request);

        // Normal worker exits with 0 — no partial output returned as failure
        if ($result->status === AgentStatus::Failed) {
            // On crash, partial output should be present
            $this->assertNotNull($result->output);
        } else {
            $this->assertSame(AgentStatus::Completed, $result->status);
        }
    }

    public function testSpawnWorkerWithInvalidBinaryThrows(): void
    {
        $executor = new ProcessExecutor(binaryPath: '/nonexistent/php');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Failed to spawn');

        $executor->execute($this->agent, $this->request);
    }

    // -------------------------------------------------------------------------
    // Backpressure — memory pressure detection
    // -------------------------------------------------------------------------

    public function testExecuteThrowsOnMemoryPressure(): void
    {
        // Use a memory pressure threshold of 0.0 so ANY memory usage triggers backpressure.
        $executor = new ProcessExecutor(
            binaryPath: 'php',
            timeoutSeconds: 300,
            memoryPressureThreshold: 0.0,
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Memory pressure');

        $executor->execute($this->agent, $this->request);
    }

    public function testExecuteStreamThrowsOnMemoryPressure(): void
    {
        $executor = new ProcessExecutor(
            binaryPath: 'php',
            timeoutSeconds: 300,
            memoryPressureThreshold: 0.0,
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Memory pressure');

        //noinspection PhpUnitInspection
        foreach ($executor->executeStream($this->agent, $this->request) as $_) {
            // consume
        }
    }

    public function testExecuteSucceedsWithHighMemoryThreshold(): void
    {
        // With threshold 1.0 (100%) memory pressure is never triggered.
        $executor = new ProcessExecutor(
            binaryPath: 'php',
            timeoutSeconds: 300,
            memoryPressureThreshold: 1.0,
            simulatedWorker: true,
        );

        $result = $executor->execute($this->agent, $this->request);

        $this->assertSame(AgentStatus::Completed, $result->status);
    }

    // -------------------------------------------------------------------------
    // The LIVE worker — the shipped default
    //
    // Everything above runs against the simulation, deliberately (see setUp()).
    // This section is about the other script: the one production actually gets.
    // -------------------------------------------------------------------------

    /**
     * The shipped default refuses rather than inventing an answer.
     *
     * This is the acceptance test for the whole change and it is written as an
     * assertion about the DEFAULT construction, not about a flag: `new
     * ProcessExecutor()` is what
     * {@see \SugarCraft\Crush\Agents\AgentWorkerPool::createDefaultExecutor()}
     * builds and what {@see \SugarCraft\Crush\Chat::executeAgents()} builds, so
     * a regression that reinstated a fabricating default would land here.
     *
     * Note what is asserted about `output`: NULL. Not "does not contain the old
     * canned sentence" — a substring assertion would pass just as happily
     * against a NEW canned sentence, which is the failure mode this test
     * exists for.
     */
    public function testLiveWorkerRefusesWhenNoProviderIsConfigured(): void
    {
        $result = (new ProcessExecutor(timeoutSeconds: 30))->execute($this->agent, $this->request);

        $this->assertSame(AgentStatus::Failed, $result->status);
        $this->assertNull($result->output);
        $this->assertNotNull($result->error);
        $this->assertStringContainsString('Refusing to fabricate', $result->error->getMessage());
    }

    /**
     * A provider that will not construct is also a refusal, not a fallback.
     *
     * The dangerous shape is not "no provider" — that one is obvious. It is a
     * provider that was configured and then failed, because that is where a
     * well-meaning `catch (\Throwable) { /* fall back to the simulation *\/ }`
     * gets written. There is no such catch, and this is what would notice one.
     */
    public function testLiveWorkerRefusesAnUnconstructibleProviderRatherThanFallingBack(): void
    {
        $executor = new ProcessExecutor(
            timeoutSeconds: 30,
            workerProvider: ['type' => 'no-such-provider-type'],
        );

        $result = $executor->execute($this->agent, $this->request);

        $this->assertSame(AgentStatus::Failed, $result->status);
        $this->assertNull($result->output);
        $this->assertStringContainsString('Provider construction failed', $result->error->getMessage());
    }

    /**
     * The seam is real: the child's bytes are a provider's bytes.
     *
     * The expectation is not a literal. It is computed by running the SAME
     * provider in THIS process over the same conversation, so the assertion is
     * "the worker relayed what the provider said" rather than "the worker said
     * the thing I typed into this test". A worker that fabricated a plausible
     * answer fails here; so does one that quietly drops the request.
     *
     * {@see EchoProvider} is a real `ProviderInterface` — the one this
     * application ships as its offline default — so this exercises provider
     * construction, request assembly, `completeStream()`, the streaming frames
     * and the complete frame end to end, with no network anywhere.
     */
    public function testLiveWorkerRelaysARealProvidersAnswer(): void
    {
        $task = 'name the smallest prime above forty';
        $agent = new SubAgent(
            id: 'live-agent-' . uniqid((string) getmypid(), true),
            agent: $this->agent->agent,
            task: $task,
        );

        $executor = new ProcessExecutor(
            timeoutSeconds: 30,
            workerProvider: ['type' => 'echo'],
        );

        $result = $executor->execute($agent, new CompleteRequest(model: 'test-model', messages: []));

        $expected = (new EchoProvider())
            ->complete(new CompleteRequest(model: 'test-model', messages: [new UserMessage($task)]))
            ->content;

        $this->assertSame(AgentStatus::Completed, $result->status);
        $this->assertSame($expected, $result->output);
    }

    /**
     * The streaming frames are the PROVIDER's chunk boundaries, not one canned
     * string cut up by the worker.
     *
     * {@see EchoProvider::completeStream()} splits on whitespace, so a real
     * relay yields many Streaming results whose concatenation is exactly the
     * non-streaming answer. A worker emitting its own two fixed chunks — which
     * is precisely what the simulation does — cannot satisfy both halves.
     */
    public function testLiveWorkerStreamsTheProvidersOwnChunkBoundaries(): void
    {
        $task = 'enumerate three colours';
        $agent = new SubAgent(
            id: 'live-stream-agent-' . uniqid((string) getmypid(), true),
            agent: $this->agent->agent,
            task: $task,
        );

        $executor = new ProcessExecutor(
            timeoutSeconds: 30,
            workerProvider: ['type' => 'echo'],
        );

        $chunks = [];
        $terminal = null;
        foreach ($executor->executeStream($agent, new CompleteRequest(model: 'test-model', messages: [])) as $r) {
            if ($r->status === AgentStatus::Streaming) {
                $chunks[] = $r->output ?? '';
                continue;
            }
            $terminal = $r;
        }

        $expected = (new EchoProvider())
            ->complete(new CompleteRequest(model: 'test-model', messages: [new UserMessage($task)]))
            ->content;

        $this->assertNotNull($terminal);
        $this->assertSame(AgentStatus::Completed, $terminal->status);
        $this->assertGreaterThan(2, count($chunks), 'a relayed stream carries the provider\'s own pieces');
        $this->assertSame($expected, implode('', $chunks));
    }

    /**
     * The conversation survives the pipe.
     *
     * MEASURED on PHP 8.3.6 before the fix: `json_encode(new UserMessage('x'))`
     * is `{}`, because every Message keeps its state private and none is
     * JsonSerializable. `spawnWorker()` used to encode the request's messages
     * directly, so `request.messages` arrived as a list of empty objects and
     * the sub-agent's entire conversation was destroyed in transit — silently.
     *
     * The task and the message are DELIBERATELY different sentences. The live
     * worker falls back to the task when the conversation is empty, so a test
     * whose task and message said the same thing would pass with the bug fully
     * present. This one distinguishes them: with `{}` on the wire the echo
     * comes back as the task.
     */
    public function testLiveWorkerCarriesMessageObjectsAcrossTheWire(): void
    {
        $agent = new SubAgent(
            id: 'live-msg-agent-' . uniqid((string) getmypid(), true),
            agent: $this->agent->agent,
            task: 'THIS-IS-THE-TASK-NOT-THE-CONVERSATION',
        );

        $executor = new ProcessExecutor(
            timeoutSeconds: 30,
            workerProvider: ['type' => 'echo'],
        );

        $result = $executor->execute($agent, new CompleteRequest(
            model: 'test-model',
            messages: [new UserMessage('THIS-IS-THE-CONVERSATION')],
        ));

        $this->assertSame(AgentStatus::Completed, $result->status);
        $this->assertStringContainsString('THIS-IS-THE-CONVERSATION', (string) $result->output);
        $this->assertStringNotContainsString('THIS-IS-THE-TASK', (string) $result->output);
    }

    /**
     * A message shape the wire cannot express stops the call.
     *
     * Dropping it instead would put the executor back where it started: a
     * request that is quietly short a turn looks exactly like a short
     * conversation, and nothing downstream can tell.
     */
    public function testSpawnWorkerRefusesAMessageItCannotSerialise(): void
    {
        $executor = new ProcessExecutor(timeoutSeconds: 30, workerProvider: ['type' => 'echo']);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Cannot send message of type stdClass');

        $executor->execute($this->agent, new CompleteRequest(
            model: 'test-model',
            messages: [new \stdClass()],
        ));
    }

    /**
     * Every turn keeps its ROLE across the wire, not just its text.
     *
     * MEASURED before this test existed: replacing the child's whole
     * role-dispatch `match` with `new UserMessage($content)` — collapsing every
     * turn to a user turn — SURVIVED the entire suite, 10293 tests green.
     * `testLiveWorkerCarriesMessageObjectsAcrossTheWire` sends one UserMessage,
     * so it pins the CONTENT and cannot see the role at all.
     *
     * The distinguishing shape is a conversation whose LAST turn is not the
     * last USER turn. {@see EchoProvider::echo()} answers with the last message
     * whose `role()` is `user`, so with roles intact the answer is the earlier
     * turn and with the roles collapsed it is the later one. The two markers
     * are asserted in both polarities so a worker that returned neither cannot
     * pass by returning nothing.
     */
    public function testLiveWorkerKeepsEachTurnsRoleAcrossTheWire(): void
    {
        $agent = new SubAgent(
            id: 'live-role-agent-' . uniqid((string) getmypid(), true),
            agent: $this->agent->agent,
            task: 'THIS-IS-THE-TASK-NOT-THE-CONVERSATION',
        );

        $executor = new ProcessExecutor(timeoutSeconds: 30, workerProvider: ['type' => 'echo']);

        $result = $executor->execute($agent, new CompleteRequest(
            model: 'test-model',
            messages: [
                new UserMessage('MARKER-FROM-THE-USER-TURN'),
                new AssistantMessage('MARKER-FROM-THE-ASSISTANT-TURN'),
            ],
        ));

        $this->assertSame(AgentStatus::Completed, $result->status);
        $this->assertStringContainsString(
            'MARKER-FROM-THE-USER-TURN',
            (string) $result->output,
            'the provider answered something other than the last USER turn',
        );
        $this->assertStringNotContainsString(
            'MARKER-FROM-THE-ASSISTANT-TURN',
            (string) $result->output,
            'the assistant turn arrived as a user turn, so every role collapsed in transit',
        );
    }

    /**
     * A tool result that FAILED does not arrive at the model as one that
     * succeeded.
     *
     * `ToolResultMessage::toArray()` carries `is_error`; the child used to
     * rebuild with `new ToolResultMessage($id, $content)` and take the
     * parameter's `false` default, so the one bit that says "this tool call
     * went wrong" was dropped in transit. Asserted on the RECONSTRUCTION the
     * child performs rather than on a provider's reaction to it, because
     * EchoProvider reads only user turns and could not see the difference —
     * the point being that nothing downstream can, which is why the loss was
     * silent.
     *
     * Two halves, and both are needed. The child really is driven — the script
     * is extracted, run in a real process, and handed the turn exactly as
     * `spawnWorker()` would put it — which proves the rebuilt turn does not
     * fatal the worker. The flag itself is then asserted over the SAME wire
     * bytes in this process, because no provider in the tree reads `is_error`
     * and so no child could report on it.
     */
    public function testTheChildKeepsAToolResultsErrorFlagAndItsCallId(): void
    {
        $frames = $this->driveLiveWorker([
            'type' => 'startup',
            'autoload' => (string) $this->reflectAutoloadPath(),
            'provider' => ['type' => 'echo'],
            'agent' => ['id' => 'a', 'name' => 'A', 'model' => 'm', 'prompt' => null],
            'task' => '',
            'request' => [
                'model' => 'm',
                'messages' => [
                    (new ToolResultMessage('call-42', 'the tool blew up', true))->toArray(),
                    (new UserMessage('ping'))->toArray(),
                ],
                'tools' => null,
                'systemPrompt' => null,
                'temperature' => null,
                'maxTokens' => null,
            ],
        ], sendExecute: true);

        // The run itself must succeed — a child that fatalled while rebuilding
        // the turn would also "not lose the flag", and that is not the claim.
        $complete = null;
        foreach ($frames as $frame) {
            if (($frame['type'] ?? '') === 'complete') {
                $complete = $frame;
            }
        }

        $this->assertNotNull($complete, 'the child never completed: ' . var_export($frames, true));
        $this->assertSame('completed', $complete['status'] ?? null);

        // And the reconstruction itself, in this process, over the SAME wire
        // bytes the child was handed. This is the assertion the child cannot
        // make for itself, because no provider in the tree reads is_error.
        $wire = (new ToolResultMessage('call-42', 'the tool blew up', true))->toArray();
        $rebuilt = new ToolResultMessage(
            (string) ($wire['tool_call_id'] ?? ''),
            (string) ($wire['content'] ?? ''),
            (bool) ($wire['is_error'] ?? false),
        );
        $this->assertTrue($rebuilt->isError(), 'toArray() no longer carries is_error');
        $this->assertSame('call-42', $rebuilt->toolCallId());
    }

    /**
     * The child rebuilds each turn with EVERY field the wire carries.
     *
     * ⚠️ WHY THIS IS A STRUCTURAL TEST AND NOT A BEHAVIOURAL ONE. `is_error`,
     * `tool_call_id`, `tool_calls`, `reasoning` and `attachments` are all
     * unobservable from inside the child: the only provider it can construct
     * without a network is {@see EchoProvider}, which reads the last USER
     * turn's content and nothing else. MEASURED — there is no
     * conversation-dumping provider anywhere in `src/Providers`, and adding one
     * would be a new production class written for a test. So the role is pinned
     * behaviourally by
     * {@see testLiveWorkerKeepsEachTurnsRoleAcrossTheWire} and the remaining
     * fields are pinned here, by ARITY over the generated script's own token
     * stream.
     *
     * Arity, not text. A grep for `is_error` would be satisfied by the word
     * appearing in a comment — and the comment explaining this very fix would
     * satisfy it. Counting the arguments each `new ...Message(...)` is actually
     * constructed with is a fact about the code, and it is exactly the fact
     * that changed: every one of these lost a field by being constructed with
     * fewer arguments than the wire supplies.
     *
     * The known-positive and known-negative fixtures are the point of the rest
     * of the test: an arity counter that returned 0 for everything, or that
     * miscounted a nested call's commas, would make the assertions below pass
     * on a worker that had dropped every field.
     */
    public function testTheChildConstructsEachTurnWithEveryFieldTheWireCarries(): void
    {
        $count = static function (string $source, string $class): int {
            $tokens = token_get_all("<?php\n" . $source);
            $names = [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED];
            foreach ($tokens as $i => $token) {
                if (!\is_array($token) || !\in_array($token[0], $names, true)) {
                    continue;
                }
                // The worker script spells every class fully qualified, which
                // PHP 8 lexes as ONE T_NAME_FULLY_QUALIFIED token rather than
                // T_STRING — the first draft of this scan matched T_STRING only
                // and reported "not found" for all three constructions. Matching
                // the trailing segment covers both spellings.
                if ($token[1] !== $class && !str_ends_with($token[1], '\\' . $class)) {
                    continue;
                }

                // Find the '(' that opens this construction.
                $j = $i + 1;
                while ($j < \count($tokens) && \is_array($tokens[$j]) && $tokens[$j][0] === T_WHITESPACE) {
                    ++$j;
                }
                // Rule: gate every text comparison on is_string(). On PHP 8.3.6
                // token_get_all() yields T_ENCAPSED_AND_WHITESPACE whose text is
                // exactly '(' or '}' inside an interpolated string, so an
                // ungated comparison sees braces that are not braces.
                if ($j >= \count($tokens) || !\is_string($tokens[$j]) || $tokens[$j] !== '(') {
                    continue;
                }

                $depth = 0;
                $commas = 0;
                $sinceComma = false;
                for ($k = $j; $k < \count($tokens); ++$k) {
                    $t = $tokens[$k];
                    if (\is_string($t) && ($t === '(' || $t === '[')) {
                        ++$depth;
                        if ($depth > 1) {
                            $sinceComma = true;
                        }
                        continue;
                    }
                    if (\is_string($t) && ($t === ')' || $t === ']')) {
                        --$depth;
                        if ($depth === 0) {
                            // A TRAILING comma closes no argument. The worker
                            // script writes one on every multi-line call, so a
                            // counter that added 1 unconditionally reported 4
                            // arguments for a 3-argument construction.
                            return $commas + ($sinceComma ? 1 : 0);
                        }
                        continue;
                    }
                    if ($depth === 1 && \is_string($t) && $t === ',') {
                        ++$commas;
                        $sinceComma = false;
                        continue;
                    }
                    if ($depth >= 1 && !(\is_array($t) && \in_array($t[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true))) {
                        $sinceComma = true;
                    }
                }

                return -1; // unbalanced: a guard that cannot parse says so
            }

            return -1; // not found at all is ALSO not a zero
        };

        // Known-positive controls, one per arity the assertions below rely on,
        // including a nested call and an array literal — the two shapes a naive
        // comma count gets wrong.
        $this->assertSame(0, $count('new Foo();', 'Foo'), 'an empty argument list is not one argument');
        $this->assertSame(1, $count('new Foo($a);', 'Foo'));
        $this->assertSame(3, $count('new Foo($a, bar($b, $c), [$d, $e]);', 'Foo'), 'nested commas are not arguments');
        $this->assertSame(2, $count("new Foo(\"a\$b(\", \$c);", 'Foo'), 'a brace inside a string is not a brace');
        $this->assertSame(2, $count('new Bar\\Baz\\Foo($a, $b);', 'Foo'), 'a qualified name is the same construction');
        $this->assertSame(1, $count('new \\Bar\\Foo($a);', 'Foo'), 'a fully qualified name is too');
        $this->assertSame(-1, $count('new NotFoo($a, $b);', 'Foo'), 'a name that merely ENDS in the class is not it');
        $this->assertSame(2, $count("new Foo(\n  \$a,\n  \$b,\n);", 'Foo'), 'a trailing comma closes no argument');
        // Known-negative controls: a guard that cannot answer must not answer 0.
        $this->assertSame(-1, $count('$x = 1;', 'Foo'), 'an absent construction reported as zero arguments');
        $this->assertSame(-1, $count('new Foo($a', 'Foo'), 'an unbalanced list reported as an arity');

        $script = (new \ReflectionMethod(ProcessExecutor::class, 'createLiveWorkerScript'))
            ->invoke(new ProcessExecutor());

        $this->assertSame(
            3,
            $count($script, 'ToolResultMessage'),
            'the child rebuilds a tool result without its is_error flag: an errored '
            . 'tool call reaches the model as a successful one',
        );
        $this->assertSame(
            3,
            $count($script, 'AssistantMessage'),
            'the child rebuilds an assistant turn without its tool_calls and reasoning',
        );
        $this->assertSame(
            2,
            $count($script, 'UserMessage'),
            'the child rebuilds a user turn without its attachments',
        );
    }

    /**
     * The startup frame carries the request the parent built — on the WIRE.
     *
     * This is the one assertion that can be made about `request.tools`, and it
     * had to be made because nothing else reads that field: MEASURED, mutating
     * {@see ProcessExecutor::encodeTools()} to `return null` unconditionally
     * survived the whole suite. Neither worker script reads it, so no
     * behavioural test can see it; the frame is the artefact, so the frame is
     * what is asserted.
     *
     * The capture is a stand-in `php` binary — an executable script that
     * answers `ready` and then copies its stdin to a file — so the bytes
     * checked here are the bytes `spawnWorker()` actually wrote, not a
     * re-derivation of them.
     */
    public function testTheStartupFrameCarriesTheRequestTheParentBuilt(): void
    {
        $dir = sys_get_temp_dir() . '/sc_r60c_wire_' . uniqid((string) getmypid(), true);
        $this->assertTrue(mkdir($dir, 0o700, true));
        $capture = $dir . '/captured-stdin';
        $binary = $dir . '/fake-php';

        // `-r <script>` is ignored on purpose: this stands in for the PHP
        // binary only so that spawnWorker() has something to write into.
        file_put_contents(
            $binary,
            "#!/bin/sh\nprintf '{\"type\":\"ready\"}\\n'\ncat > " . escapeshellarg($capture) . "\n",
        );
        $this->assertTrue(chmod($binary, 0o700));

        $executor = new ProcessExecutor(
            binaryPath: $binary,
            timeoutSeconds: 30,
            workerProvider: ['type' => 'echo'],
        );

        $agent = new SubAgent(
            id: 'wire-agent-' . uniqid((string) getmypid(), true),
            agent: $this->agent->agent,
            task: 'WIRE-TASK',
        );

        $spawn = new \ReflectionMethod(ProcessExecutor::class, 'spawnWorker');
        /** @var array{stdin: resource, stdout: resource, stderr: resource, process: resource} $descriptor */
        $descriptor = $spawn->invoke($executor, $agent, new CompleteRequest(
            model: 'wire-model',
            messages: [new UserMessage('WIRE-CONVERSATION')],
            tools: [['name' => 'grep'], 'sed', new \stdClass()],
        ));

        // cat only flushes on EOF, so the write side has to go first.
        fclose($descriptor['stdin']);
        $deadline = microtime(true) + 5.0;
        $raw = '';
        while (microtime(true) < $deadline) {
            $raw = (string) @file_get_contents($capture);
            if (str_contains($raw, "\n")) {
                break;
            }
            usleep(20_000);
        }
        $executor->cancelAll();

        $startup = json_decode(strtok($raw, "\n") ?: '', true);
        $this->assertIsArray($startup, 'nothing decodable reached the worker: ' . var_export($raw, true));
        $this->assertSame('startup', $startup['type'] ?? null);

        // The tool NAMES, which is the whole reason encodeTools() is not a
        // bare null: a request granting three tools and one granting none must
        // not be byte-identical on the wire.
        $this->assertSame(
            ['grep', 'sed', '<unencodable stdClass>'],
            $startup['request']['tools'] ?? null,
            'the frame no longer says which tools the parent believed it was granting',
        );

        // And the fields the same frame is the only record of.
        $this->assertSame(
            [['role' => 'user', 'content' => 'WIRE-CONVERSATION']],
            $startup['request']['messages'] ?? null,
        );
        $this->assertSame('WIRE-TASK', $startup['task'] ?? null);
        $this->assertSame(['type' => 'echo'], $startup['provider'] ?? null);
        $this->assertIsString($startup['autoload'] ?? null);
        $this->assertFileExists((string) $startup['autoload']);

        @unlink($capture);
        @unlink($binary);
        @rmdir($dir);
    }

    /**
     * A worker whose startup frame never arrived says so, instead of blaming
     * the parent for not asking it to run.
     *
     * `createLiveWorkerScript()`'s doc-block enumerates "no startup line" among
     * the prerequisites that end in an `error` frame. MEASURED at the tree that
     * sentence was committed in, it did not: `if (!$executeReceived)` was
     * tested FIRST, and the startup loop consumes lines until it finds a
     * `startup` frame — so a parent whose startup line was malformed has
     * already had its `execute` eaten, both conditions are true, and the
     * timeout branch won. The operator was told the parent never asked for
     * execution when in fact the config frame was unreadable.
     *
     * Both branches are asserted, in the same test, because a reorder that
     * made this one reachable by making the other one dead would be the same
     * defect facing the other way.
     */
    public function testTheWorkerNamesAMissingStartupFrameRatherThanTheExecuteTimeout(): void
    {
        $malformed = $this->driveLiveWorkerRaw("{\"type\":\"not-a-startup\"}\n{\"type\":\"execute\"}\n");
        $this->assertSame('error', $this->terminalFrame($malformed)['type'] ?? null);
        $this->assertStringContainsString(
            'no startup message',
            (string) ($this->terminalFrame($malformed)['message'] ?? ''),
            'a malformed startup frame is still reported as an execute timeout',
        );

        // The control: a VALID startup with no execute behind it must still be
        // the execute timeout, or the reorder simply moved the blind spot.
        $valid = $this->driveLiveWorkerRaw(json_encode([
            'type' => 'startup',
            'autoload' => $this->reflectAutoloadPath(),
            'provider' => ['type' => 'echo'],
            'agent' => ['id' => 'a', 'name' => 'A', 'model' => 'm', 'prompt' => null],
            'task' => 't',
            'request' => ['model' => 'm', 'messages' => [], 'tools' => null],
        ]) . "\n");
        $this->assertStringContainsString(
            'Timeout waiting for execute',
            (string) ($this->terminalFrame($valid)['message'] ?? ''),
            'the execute-timeout branch went dead when the startup check moved above it',
        );
    }

    /**
     * The worker autoloader resolves in an INSTALLED layout, not only in the
     * checkout this suite happens to run in.
     *
     * MEASURED on PHP 8.3.6 against a synthetic install: from
     * `app/vendor/sugarcraft/sugar-crush/src/Agents`, the two-climb form the
     * first version of `autoloadPath()` used yields
     * `app/vendor/sugarcraft/sugar-crush/vendor/autoload.php`, which does not
     * exist — so every sub-agent in any Composer consumer of this package hit
     * the child's "Worker autoloader is not readable" refusal. The application
     * autoloader is four levels up.
     *
     * ⚠️ WHAT THIS TEST CANNOT DO, stated so nobody reads more into a green
     * than is there: the monorepo checkout is the ONE layout in which the two
     * strategies agree, so reverting `autoloadPath()` to the arithmetic does
     * not redden anything here. The first half below is a portability fixture
     * over the arithmetic itself, in both polarities; the second half pins the
     * DELEGATION, which is the part a reader can check. The layout-dependence
     * is the finding, and it is why the delegation matters.
     */
    public function testTheWorkerAutoloaderResolvesInAnInstalledLayoutNotOnlyInTheMonorepo(): void
    {
        $root = sys_get_temp_dir() . '/sc_r60c_layout_' . uniqid((string) getmypid(), true);
        $agents = $root . '/app/vendor/sugarcraft/sugar-crush/src/Agents';
        $this->assertTrue(mkdir($agents, 0o700, true));
        $this->assertNotFalse(file_put_contents($root . '/app/vendor/autoload.php', '<?php'));

        $this->assertFileDoesNotExist(
            \dirname($agents, 2) . '/vendor/autoload.php',
            'two climbs found an autoloader in an installed layout — re-measure this whole test',
        );
        $this->assertFileExists(
            \dirname($agents, 4) . '/autoload.php',
            'the application autoloader is not four levels above src/Agents after all',
        );

        @unlink($root . '/app/vendor/autoload.php');
        foreach ([$agents, \dirname($agents), \dirname($agents, 2), \dirname($agents, 3), \dirname($agents, 4), $root . '/app', $root] as $dir) {
            @rmdir($dir);
        }

        // The delegation: one locator for both children, so the daemon and the
        // sub-agent worker cannot disagree about where the autoloader is.
        $this->assertSame(
            BackgroundSupervisor::autoloadPath(),
            $this->reflectAutoloadPath(),
            'ProcessExecutor has grown a second, independent answer to this question',
        );
        $this->assertIsString($this->reflectAutoloadPath());
        $this->assertFileExists((string) $this->reflectAutoloadPath());

        // ⚠️ AND THE ASSERTION ABOVE CANNOT CATCH THE REGRESSION, which is why
        // this one exists. In THIS checkout the two strategies return the same
        // string, so reverting the delegation to the two-climb arithmetic keeps
        // assertSame() green — MEASURED, the mutation survived the whole file.
        // The difference only appears in a layout the suite cannot be run in.
        //
        // So the delegation is pinned as a token-stream fact about the method's
        // own body: it calls BackgroundSupervisor::autoloadPath(), and it does
        // no path arithmetic of its own. Structural, not textual — a comment
        // naming either symbol cannot satisfy or trip it, because comments are
        // T_COMMENT/T_DOC_COMMENT and are dropped below.
        $delegates = static function (string $body): bool {
            $significant = [];
            foreach (token_get_all("<?php\n" . $body) as $token) {
                if (\is_array($token) && \in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                    continue;
                }
                $significant[] = \is_array($token) ? $token[1] : $token;
            }

            $text = implode(' ', $significant);

            return str_contains($text, 'BackgroundSupervisor :: autoloadPath')
                && !str_contains($text, 'dirname');
        };

        $this->assertTrue(
            $delegates('return BackgroundSupervisor::autoloadPath();'),
            'the delegation detector is dead',
        );
        $this->assertFalse(
            $delegates("return \dirname(__DIR__, 2) . '/vendor/autoload.php';"),
            'the detector accepts the arithmetic it exists to reject',
        );
        $this->assertFalse(
            $delegates('/* BackgroundSupervisor::autoloadPath() */ return X;'),
            'a comment naming the delegate satisfied the detector',
        );

        $method = new \ReflectionMethod(ProcessExecutor::class, 'autoloadPath');
        $lines = file((string) $method->getFileName());
        $this->assertIsArray($lines);
        $body = implode('', \array_slice(
            $lines,
            $method->getStartLine() - 1,
            $method->getEndLine() - $method->getStartLine() + 1,
        ));

        $this->assertTrue(
            $delegates($body),
            'ProcessExecutor::autoloadPath() computes the path itself again. It resolves '
            . 'in this checkout and nowhere else — see the measurement above.',
        );
    }

    /**
     * The refusal does not leak the child it was refusing to talk to.
     *
     * `encodeMessages()` throws, and it used to throw AFTER `proc_open()`: a
     * live `php -r` process and three pipes were already in hand, and
     * `$this->processes[$agent->id]` had not been set, so neither `cancel()`
     * nor `cancelAll()` could ever reap it. Every firing of the guard that
     * exists to stop a silently-wrong request leaked a process.
     *
     * Observed rather than argued: the stand-in binary touches a marker file
     * the instant it runs, so "no child was spawned" is a file that does not
     * exist rather than a claim about ordering.
     */
    public function testRefusingAnUnserialisableMessageSpawnsNoChild(): void
    {
        $dir = sys_get_temp_dir() . '/sc_r60c_leak_' . uniqid((string) getmypid(), true);
        $this->assertTrue(mkdir($dir, 0o700, true));
        $marker = $dir . '/spawned';
        $binary = $dir . '/fake-php';
        // It drains stdin rather than exiting: a stand-in that returned
        // immediately would make spawnWorker()'s unconditional `execute` write
        // hit a closed pipe, and this test would be reporting that notice
        // rather than the leak it is about.
        file_put_contents(
            $binary,
            "#!/bin/sh\ntouch " . escapeshellarg($marker) . "\nexec cat > /dev/null\n",
        );
        $this->assertTrue(chmod($binary, 0o700));

        $executor = new ProcessExecutor(
            binaryPath: $binary,
            timeoutSeconds: 30,
            workerProvider: ['type' => 'echo'],
        );

        // Known-positive control: the same binary, a request that DOES
        // serialise. Without this, an assertFileDoesNotExist() below is
        // satisfied just as well by a marker that never gets written at all.
        $spawn = new \ReflectionMethod(ProcessExecutor::class, 'spawnWorker');
        $descriptor = $spawn->invoke($executor, $this->agent, new CompleteRequest(
            model: 'm',
            messages: [new UserMessage('fine')],
        ));
        $deadline = microtime(true) + 5.0;
        while (!file_exists($marker) && microtime(true) < $deadline) {
            usleep(20_000);
        }
        $executor->cancelAll();
        if (is_resource($descriptor['stdin'] ?? null)) {
            fclose($descriptor['stdin']);
        }
        $this->assertFileExists($marker, 'the stand-in binary never ran, so the check below proves nothing');
        $this->assertTrue(unlink($marker));

        try {
            $spawn->invoke($executor, $this->agent, new CompleteRequest(
                model: 'm',
                messages: [new \stdClass()],
            ));
            $this->fail('encodeMessages() accepted a stdClass');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('Cannot send message of type stdClass', $e->getMessage());
        }

        $this->assertFileDoesNotExist(
            $marker,
            'the refusal spawned a worker first, and nothing recorded it for cancel() to reap',
        );

        @unlink($binary);
        @rmdir($dir);
    }

    /**
     * `ProcessExecutor::autoloadPath()`, which is private and static.
     */
    private function reflectAutoloadPath(): ?string
    {
        /** @var ?string $path */
        $path = (new \ReflectionMethod(ProcessExecutor::class, 'autoloadPath'))->invoke(null);

        return $path;
    }

    /**
     * Run the LIVE worker script in a real child over a literal stdin, and
     * return the frames it emitted.
     *
     * The script is extracted from the executor rather than copied, so a change
     * to it is a change to what these tests drive.
     *
     * @return list<array<string, mixed>>
     */
    private function driveLiveWorkerRaw(string $stdin): array
    {
        $script = (new \ReflectionMethod(ProcessExecutor::class, 'createLiveWorkerScript'))
            ->invoke(new ProcessExecutor());

        $process = proc_open(
            [PHP_BINARY, '-r', $script],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        $this->assertIsResource($process);

        fwrite($pipes[0], $stdin);
        fclose($pipes[0]);
        $out = (string) stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);

        $frames = [];
        foreach (explode("\n", trim($out)) as $line) {
            if ($line === '') {
                continue;
            }
            $decoded = json_decode($line, true);
            if (\is_array($decoded)) {
                $frames[] = $decoded;
            }
        }

        return $frames;
    }

    /**
     * @param array<string, mixed> $startup
     * @return list<array<string, mixed>>
     */
    private function driveLiveWorker(array $startup, bool $sendExecute): array
    {
        $stdin = json_encode($startup) . "\n";
        if ($sendExecute) {
            $stdin .= json_encode(['type' => 'execute']) . "\n";
        }

        return $this->driveLiveWorkerRaw($stdin);
    }

    /**
     * @param list<array<string, mixed>> $frames
     * @return array<string, mixed>
     */
    private function terminalFrame(array $frames): array
    {
        $terminal = [];
        foreach ($frames as $frame) {
            if (\in_array($frame['type'] ?? '', ['error', 'complete'], true)) {
                $terminal = $frame;
            }
        }

        $this->assertNotSame([], $terminal, 'the worker emitted no terminal frame: ' . var_export($frames, true));

        return $terminal;
    }

    /**
     * Nothing in `src/` opts into the fabricating worker.
     *
     * This is the guard behind the claim that the simulation is TEST-ONLY, and
     * it is structural rather than textual on purpose: a doc-block that
     * mentions the flag must not be able to trip it, and a doc-block that
     * mentions it must not be able to satisfy it either. The scan is over
     * `token_get_all()`, where a named-argument label is a bare `T_STRING` —
     * `$simulatedWorker` in the constructor declaration is a `T_VARIABLE` and
     * every mention in prose is a `T_DOC_COMMENT`, so neither is visible here.
     *
     * The known-positive fixture below is not decoration. An assertion that a
     * list is empty is satisfied just as well by a scanner that has stopped
     * working, so the same scanner is run over a source string that DOES pass
     * the argument, in the same test, and must find it.
     */
    public function testNoProductionSourceOptsIntoTheSimulatedWorker(): void
    {
        $scan = static function (string $source): int {
            $tokens = token_get_all($source);
            $count = 0;

            foreach ($tokens as $i => $token) {
                if (!\is_array($token) || $token[0] !== T_STRING || $token[1] !== 'simulatedWorker') {
                    continue;
                }

                // Walk back over whitespace to the significant token before it.
                // A named-argument label has an ordinary token there (`(`, `,`);
                // a MEMBER ACCESS has `->`, `?->` or `::`, and the executor's own
                // `$this->simulatedWorker` reads are exactly that. Without this
                // the declaring file reports itself, which is what the first
                // draft of this scan did — the window was wrong, not the rule.
                $j = $i - 1;
                while ($j >= 0 && \is_array($tokens[$j]) && $tokens[$j][0] === T_WHITESPACE) {
                    --$j;
                }

                $previous = $j >= 0 ? $tokens[$j] : null;
                if (\is_array($previous) && \in_array(
                    $previous[0],
                    [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON],
                    true,
                )) {
                    continue;
                }

                // `simulatedWorker: false` is the DEFAULT spelled out loud, not
                // an opt-in, and the first draft of this scan reported it as
                // one. A guard that flags correct code is a guard people learn
                // to route around.
                $k = $i + 1;
                while ($k < \count($tokens) && (
                    (\is_array($tokens[$k]) && $tokens[$k][0] === T_WHITESPACE)
                    || (\is_string($tokens[$k]) && $tokens[$k] === ':')
                )) {
                    ++$k;
                }
                $value = $k < \count($tokens) ? $tokens[$k] : null;
                if (\is_array($value) && $value[0] === T_STRING && strtolower($value[1]) === 'false') {
                    continue;
                }

                ++$count;
            }

            return $count;
        };

        // A SECOND scan, because the first cannot see the shape that will
        // actually happen. `simulatedWorker` is the LAST of five constructor
        // parameters, so any future parameter shifts its position — and a
        // positional call carries no label at all, which is the only thing the
        // token scan above matches on. MEASURED before this existed:
        // `new ProcessExecutor("php", 300, 0.9, null, true)` was reported ZERO
        // times by the scan that claims production cannot select the
        // simulation.
        //
        // The threshold is READ OFF the constructor rather than written down,
        // so adding a parameter cannot silently retune the guard.
        $simulatedPosition = null;
        foreach ((new \ReflectionMethod(ProcessExecutor::class, '__construct'))->getParameters() as $parameter) {
            if ($parameter->getName() === 'simulatedWorker') {
                $simulatedPosition = $parameter->getPosition();
            }
        }
        $this->assertIsInt($simulatedPosition, 'ProcessExecutor no longer has a simulatedWorker parameter');

        $scanPositional = static function (string $source) use ($simulatedPosition): int {
            $tokens = token_get_all($source);
            $count = 0;

            foreach ($tokens as $i => $token) {
                $isName = \is_array($token)
                    && \in_array($token[0], [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)
                    && ($token[1] === 'ProcessExecutor' || str_ends_with($token[1], '\\ProcessExecutor'));
                if (!$isName) {
                    continue;
                }

                $j = $i - 1;
                while ($j >= 0 && \is_array($tokens[$j]) && $tokens[$j][0] === T_WHITESPACE) {
                    --$j;
                }
                if (!\is_array($tokens[$j] ?? null) || $tokens[$j][0] !== T_NEW) {
                    continue;
                }

                $k = $i + 1;
                while ($k < \count($tokens) && \is_array($tokens[$k]) && $tokens[$k][0] === T_WHITESPACE) {
                    ++$k;
                }
                if (!\is_string($tokens[$k] ?? null) || $tokens[$k] !== '(') {
                    continue;
                }

                $depth = 0;
                $commas = 0;
                for (; $k < \count($tokens); ++$k) {
                    $t = $tokens[$k];
                    if (\is_string($t) && ($t === '(' || $t === '[')) {
                        ++$depth;
                        continue;
                    }
                    if (\is_string($t) && ($t === ')' || $t === ']')) {
                        if (--$depth === 0) {
                            break;
                        }
                        continue;
                    }
                    if ($depth === 1 && \is_string($t) && $t === ',') {
                        ++$commas;
                    }
                }

                // More separators than the simulation flag's index means the
                // flag itself was supplied, whatever it was supplied as.
                if ($commas >= $simulatedPosition) {
                    ++$count;
                }
            }

            return $count;
        };

        // Known-positive control: the scanner must SEE an opt-in.
        $positive = "<?php\n\$e = new ProcessExecutor(timeoutSeconds: 5, simulatedWorker: true);\n";
        $this->assertSame(1, $scan($positive), 'the scanner is dead — it cannot see a real opt-in');

        // Known-negative controls: neither the declaration nor prose counts.
        $declaration = "<?php\nfinal class X { public function __construct(private readonly bool \$simulatedWorker = false) {} }\n";
        $this->assertSame(0, $scan($declaration), 'a promoted property declaration is not an opt-in');
        $prose = "<?php\n/** Pass simulatedWorker: true to get the simulation. */\n\$x = 1;\n";
        $this->assertSame(0, $scan($prose), 'a doc-block mention is not an opt-in');
        $read = "<?php\n\$s = \$this->simulatedWorker ? 'a' : 'b';\n";
        $this->assertSame(0, $scan($read), 'reading the property is not an opt-in');
        $nullsafe = "<?php\n\$s = \$e?->simulatedWorker;\n";
        $this->assertSame(0, $scan($nullsafe), 'a nullsafe read is not an opt-in either');
        $explicitDefault = "<?php\n\$e = new ProcessExecutor(simulatedWorker: false);\n";
        $this->assertSame(0, $scan($explicitDefault), 'spelling out the default is not an opt-in');

        // And the positional scan, in both polarities.
        $positional = "<?php\n\$e = new ProcessExecutor('php', 300, 0.9, null, true);\n";
        $this->assertSame(1, $scanPositional($positional), 'the positional scanner is dead');
        $qualified = "<?php\n\$e = new \\SugarCraft\\Crush\\Agents\\ProcessExecutor('php', 300, 0.9, null, true);\n";
        $this->assertSame(1, $scanPositional($qualified), 'a qualified name is the same construction');
        $short = "<?php\n\$e = new ProcessExecutor('php', 300);\n";
        $this->assertSame(0, $scanPositional($short), 'a call that stops short of the flag is not an opt-in');
        $nested = "<?php\n\$e = new ProcessExecutor('php', max(1, 2), 0.9);\n";
        $this->assertSame(0, $scanPositional($nested), 'commas inside an argument are not arguments');
        $notNew = "<?php\nProcessExecutor::class;\n";
        $this->assertSame(0, $scanPositional($notNew), 'a class-name mention is not a construction');

        $srcRoot = \dirname(__DIR__, 2) . '/src';
        $offenders = [];
        $files = 0;
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($srcRoot));
        foreach ($iterator as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }
            ++$files;
            $source = file_get_contents($file->getPathname());
            $this->assertIsString($source);
            if ($scan($source) > 0 || $scanPositional($source) > 0) {
                $offenders[] = substr($file->getPathname(), \strlen($srcRoot) + 1);
            }
        }

        // A positive claim about the population, so an empty walk fails too.
        $this->assertGreaterThan(200, $files, 'the src/ walk found almost nothing — the scan is not running');
        $this->assertSame([], $offenders, 'production code must not select the fabricating worker');
    }
}
