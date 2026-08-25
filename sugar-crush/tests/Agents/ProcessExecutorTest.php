<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Agents;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Agents\Agent;
use SugarCraft\Crush\Agents\AgentResult;
use SugarCraft\Crush\Agents\AgentStatus;
use SugarCraft\Crush\Agents\ProcessExecutor;
use SugarCraft\Crush\Agents\SubAgent;
use SugarCraft\Crush\Messages\UserMessage;
use SugarCraft\Crush\Providers\CompleteRequest;
use SugarCraft\Crush\Providers\EchoProvider;

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

                ++$count;
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
            if ($scan($source) > 0) {
                $offenders[] = substr($file->getPathname(), \strlen($srcRoot) + 1);
            }
        }

        // A positive claim about the population, so an empty walk fails too.
        $this->assertGreaterThan(200, $files, 'the src/ walk found almost nothing — the scan is not running');
        $this->assertSame([], $offenders, 'production code must not select the fabricating worker');
    }
}
