<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Agents;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Agents\Agent;
use SugarCraft\Code\Agents\AgentManager;
use SugarCraft\Code\Agents\AgentResult;
use SugarCraft\Code\Agents\AgentStatus;
use SugarCraft\Code\Agents\AgentWorkerPool;
use SugarCraft\Code\Agents\ExecutorInterface;
use SugarCraft\Code\Agents\SubAgent;
use SugarCraft\Code\Agents\SuspendedDelegations;
use SugarCraft\Code\Agents\Team;
use SugarCraft\Code\Agents\TeamConfig;
use SugarCraft\Code\Agents\TeamManager;
use SugarCraft\Code\Backend\EngineBackend;
use SugarCraft\Code\Events\SubAgentActivity;
use SugarCraft\Code\Hooks\BuiltIn\SubAgentGrantHook;
use SugarCraft\Code\Hooks\HookContext;
use SugarCraft\Code\MCP\McpClient;
use SugarCraft\Code\MCP\McpTool;
use SugarCraft\Code\Messages\Message as TypedMessage;
use SugarCraft\Code\Permissions\PermissionDecision;
use SugarCraft\Code\Permissions\PermissionGate;
use SugarCraft\Code\Permissions\PermissionMode;
use SugarCraft\Code\Providers\CompleteRequest;
use SugarCraft\Code\Providers\CompleteResponse;
use SugarCraft\Code\Providers\EmbeddingsRequest;
use SugarCraft\Code\Providers\EmbeddingsResponse;
use SugarCraft\Code\Providers\ProviderInterface;
use SugarCraft\Code\Skills\Skill;
use SugarCraft\Code\ToolCall;
use SugarCraft\Code\Tools\BuiltIn\TaskTool;
use SugarCraft\Code\Tools\McpToolBridge;
use SugarCraft\Code\Tools\Tool;
use SugarCraft\Code\Tools\ToolCall as WireToolCall;
use SugarCraft\Code\Tools\ToolResult;
use SugarCraft\Code\Skills\SkillRegistry;
use SugarCraft\Code\Tests\Support\HomeSandboxTrait;
use SugarCraft\Code\Tests\Support\ScriptedProvider;

/**
 * Tests for AgentManager - manages agents and sub-agents.
 *
 * HOME is redirected to a sandbox for the whole class through
 * {@see HomeSandboxTrait}: the team tests below delegate to a real
 * TeamManager, and the Teams it builds persist under ~/.candy-code/teams/{id}/
 * no matter where the manager's own registry lives.
 *
 * WHAT THIS USED TO DO, AND WHY IT STOPPED. setUp() snapshotted a shallow
 * listing of the DEVELOPER'S real ~/.candy-code and tearDown() asserted it was
 * unchanged. That made every test here a hostage to every other process on the
 * machine: a live candy-code writing its session lock, another worktree's suite
 * sweeping a stale team dir. One recorded red had entries DISAPPEARING,
 * which nothing in this file can even cause. The leak it guarded against
 * is now asserted the other way round, on ground this test owns:
 * {@see testATeamBuiltThroughTheManagerPersistsIntoTheSandboxHome()} proves the
 * Team's files land in the sandbox HOME, which a redirect that stopped reaching
 * the writer would fail - without reading the real home at all.
 *
 * Every test that RUNS a sub-agent drives it through {@see TaskTool}'s engine
 * path (CC1C-c2a), the one every production registration binds; the
 * manager's own sequential runner is retired.
 */
final class AgentManagerTest extends TestCase
{
    use HomeSandboxTrait;

    private ProviderInterface $provider;
    private SkillRegistry $skillRegistry;
    private AgentManager $agentManager;

    /** The throwaway root holding this test's sandbox HOME and registry dirs. */
    private string $sandboxDir;

    /** @var array<string, Tool> one {@see recordingTool()} per name, for the whole test */
    private array $recordingTools = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->provider = $this->createMock(ProviderInterface::class);
        $this->skillRegistry = new SkillRegistry();
        $this->agentManager = new AgentManager($this->provider, $this->skillRegistry);

        $this->sandboxDir = sys_get_temp_dir() . '/sc_agent_manager_' . bin2hex(random_bytes(6));
        mkdir($this->sandboxDir, 0o700, true);

        // The trait moves BOTH spellings: Team::basePath() and
        // TeamManager::expandPath() resolve through HomeDirectory, while
        // anything still reading $_SERVER['HOME'] directly would otherwise
        // keep the real one.
        $this->useHomeSandbox($this->sandboxDir . '/home');
    }

    protected function tearDown(): void
    {
        $this->restoreHomeSandbox();
        $this->removeDirectory($this->sandboxDir);

        parent::tearDown();
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        foreach (array_diff(scandir($dir) ?: [], ['.', '..']) as $entry) {
            $path = $dir . '/' . $entry;
            is_dir($path) ? $this->removeDirectory($path) : @unlink($path);
        }

        @rmdir($dir);
    }

    /**
     * A registry dir inside this test's sandbox, so it goes away with it.
     *
     * These used to be minted straight into sys_get_temp_dir() and never
     * removed: 8,487 abandoned agentmgr_test_* registries had piled up in one
     * developer's /tmp.
     */
    private function teamRegistryDir(): string
    {
        return $this->sandboxDir . '/registry_' . bin2hex(random_bytes(6));
    }

    // -------------------------------------------------------------------------
    // register() and get()
    // -------------------------------------------------------------------------

    public function testRegisterAndGetAgent(): void
    {
        $agent = $this->createAgent(name: 'test-agent', prompt: 'You are a test.');

        $this->agentManager->register($agent);

        $retrieved = $this->agentManager->get('test-agent');
        $this->assertSame($agent, $retrieved);
    }

    public function testGetUnknownAgentReturnsNull(): void
    {
        $retrieved = $this->agentManager->get('nonexistent');
        $this->assertNull($retrieved);
    }

    // -------------------------------------------------------------------------
    // all()
    // -------------------------------------------------------------------------

    public function testAllReturnsAllAgents(): void
    {
        $agent1 = $this->createAgent(name: 'agent-1', prompt: 'Agent 1');
        $agent2 = $this->createAgent(name: 'agent-2', prompt: 'Agent 2');

        $this->agentManager->register($agent1);
        $this->agentManager->register($agent2);

        $all = $this->agentManager->all();

        $this->assertCount(2, $all);
        $this->assertSame($agent1, $all[0]);
        $this->assertSame($agent2, $all[1]);
    }

    public function testAllReturnsEmptyArrayWhenNoAgents(): void
    {
        $all = $this->agentManager->all();
        $this->assertSame([], $all);
    }

    // -------------------------------------------------------------------------
    // active()
    // -------------------------------------------------------------------------

    public function testActiveReturnsOnlyActiveAgents(): void
    {
        $activeAgent = $this->createAgent(name: 'active', prompt: 'Active agent', isActive: true);
        $inactiveAgent = $this->createAgent(name: 'inactive', prompt: 'Inactive agent', isActive: false);

        $this->agentManager->register($activeAgent);
        $this->agentManager->register($inactiveAgent);

        $active = $this->agentManager->active();

        $this->assertCount(1, $active);
        $this->assertSame($activeAgent, $active[0]);
    }

    // -------------------------------------------------------------------------
    // createSubAgent()
    // -------------------------------------------------------------------------

    public function testCreateSubAgentSuccess(): void
    {
        $agent = $this->createAgent(name: 'code-agent', prompt: 'You write code.');
        $this->agentManager->register($agent);

        $subAgent = $this->agentManager->createSubAgent('code-agent', 'Write a function');

        $this->assertInstanceOf(SubAgent::class, $subAgent);
        $this->assertSame($agent, $subAgent->agent);
        $this->assertSame('Write a function', $subAgent->task);
        $this->assertSame(SubAgent::STATUS_PENDING, $subAgent->status);
        $this->assertStringStartsWith('subagent_', $subAgent->id);
    }

    public function testCreateSubAgentUnknownAgentThrowsException(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Unknown agent: unknown-agent');

        $this->agentManager->createSubAgent('unknown-agent', 'Some task');
    }

    public function testCreateSubAgentMultipleTimes(): void
    {
        $agent = $this->createAgent(name: 'multi-agent', prompt: 'Multi agent');
        $this->agentManager->register($agent);

        $subAgent1 = $this->agentManager->createSubAgent('multi-agent', 'Task 1');
        $subAgent2 = $this->agentManager->createSubAgent('multi-agent', 'Task 2');

        $this->assertNotSame($subAgent1->id, $subAgent2->id);
        $this->assertNotSame($subAgent1, $subAgent2);
    }

    public function testCreateSubAgentWithBypassPermissionsCreatesPermissionGate(): void
    {
        $agent = $this->createAgent(name: 'bypass-agent', prompt: 'Bypass agent');
        $this->agentManager->register($agent);

        $subAgent = $this->agentManager->createSubAgent(
            'bypass-agent',
            'Task requiring bypass',
            PermissionMode::BypassPermissions,
        );

        $this->assertNotNull($subAgent->permissionGate);
        $this->assertSame(PermissionMode::BypassPermissions, $subAgent->permissionGate->mode());
    }

    /**
     * Roadmap 4.1-2: the session-wide seal is gone. It locked the FIRST
     * sub-agent's mode for the session and threw on any second preset that
     * declared another — so a `plan` reviewer and an `accept-edits` coder
     * could not both be delegated to. Each sub-agent now gets its own mode.
     */
    public function testSubAgentsWithDifferentModesCoexist(): void
    {
        $agent = $this->createAgent(name: 'mode-test-agent', prompt: 'Mode test agent');
        $this->agentManager->register($agent);

        $modes = [];
        foreach ([PermissionMode::Plan, PermissionMode::Default, PermissionMode::AcceptEdits, PermissionMode::DontAsk] as $mode) {
            $modes[] = $this->agentManager->createSubAgent('mode-test-agent', 'Task', $mode)->permissionGate?->mode();
        }

        $this->assertSame([PermissionMode::Plan, PermissionMode::Default, PermissionMode::AcceptEdits, PermissionMode::DontAsk], $modes);
    }

    /**
     * What the seal was for — no delegation escalating the session — now holds
     * per sub-agent: with the session's mode as the ceiling, the gate is built
     * for the stricter of the two, so a wider preset mode is held to the
     * session's and a stricter one stands. The one amendment is bypass
     * (CL-1 FIX 2, b46b1c8d6): a bypass SESSION inherits DOWN, whatever the
     * preset asks — the narrow-only doctrine exists to stop a delegate
     * ESCAPING the session's limits, and bypass sets none to escape.
     *
     * @return iterable<string, array{PermissionMode, PermissionMode, PermissionMode}>
     */
    public static function narrowings(): iterable
    {
        yield 'bypass under default' => [PermissionMode::BypassPermissions, PermissionMode::Default, PermissionMode::Default];
        yield 'auto under accept-edits' => [PermissionMode::Auto, PermissionMode::AcceptEdits, PermissionMode::AcceptEdits];
        yield 'plan under default' => [PermissionMode::Plan, PermissionMode::Default, PermissionMode::Plan];
        yield 'dont-ask under plan' => [PermissionMode::DontAsk, PermissionMode::Plan, PermissionMode::DontAsk];
        yield 'default under bypass' => [PermissionMode::Default, PermissionMode::BypassPermissions, PermissionMode::BypassPermissions];
        yield 'accept-edits under dont-ask' => [PermissionMode::AcceptEdits, PermissionMode::DontAsk, PermissionMode::DontAsk];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('narrowings')]
    public function testASubAgentsModeIsTheStricterOfItsOwnAndTheSessions(PermissionMode $preset, PermissionMode $session, PermissionMode $expected): void
    {
        $agent = $this->createAgent(name: 'narrow-agent', prompt: 'Narrow agent');
        $this->agentManager->register($agent);

        $subAgent = $this->agentManager->createSubAgent('narrow-agent', 'Task', $preset, $session);

        $this->assertSame($expected, $subAgent->permissionGate?->mode());
    }

    // -------------------------------------------------------------------------
    // getSubAgent()
    // -------------------------------------------------------------------------

    public function testGetSubAgentSuccess(): void
    {
        $agent = $this->createAgent(name: 'get-agent', prompt: 'Get agent');
        $this->agentManager->register($agent);

        $created = $this->agentManager->createSubAgent('get-agent', 'Get test');

        $retrieved = $this->agentManager->subAgent($created->id);

        $this->assertSame($created, $retrieved);
    }

    public function testGetSubAgentNotFoundReturnsNull(): void
    {
        $retrieved = $this->agentManager->subAgent('nonexistent-id');
        $this->assertNull($retrieved);
    }

    // -------------------------------------------------------------------------
    // Live telemetry: subAgentsOf() / elapsedSeconds() / tokensUsed() / costUsd()
    // §5 E6 -- the render site previously hardcoded 0, 0, 0.0
    // because no per-agent telemetry accessor existed at all.
    // -------------------------------------------------------------------------

    public function testTelemetryAccessorsAreZeroForUnknownAgent(): void
    {
        $this->assertSame([], $this->agentManager->subAgentsOf('nobody'));
        $this->assertSame(0, $this->agentManager->elapsedSeconds('nobody'));
        $this->assertSame(0, $this->agentManager->tokensUsed('nobody'));
        $this->assertSame(0.0, $this->agentManager->costUsd('nobody'));
    }

    public function testSubAgentsOfFiltersByOwningAgent(): void
    {
        $this->agentManager->register($this->createAgent(name: 'alpha'));
        $this->agentManager->register($this->createAgent(name: 'beta'));

        $first = $this->agentManager->createSubAgent('alpha', 'task one');
        $second = $this->agentManager->createSubAgent('alpha', 'task two');
        $this->agentManager->createSubAgent('beta', 'other task');

        $owned = $this->agentManager->subAgentsOf('alpha');

        $this->assertCount(2, $owned);
        $this->assertSame([$first, $second], $owned);
    }

    public function testElapsedSecondsIsZeroWhileSubAgentIsStillPending(): void
    {
        $this->agentManager->register($this->createAgent(name: 'idle-agent'));
        $this->agentManager->createSubAgent('idle-agent', 'never started');

        $this->assertSame(0, $this->agentManager->elapsedSeconds('idle-agent'));
    }

    public function testElapsedSecondsSpansEarliestStartToLatestCompletion(): void
    {
        $this->agentManager->register($this->createAgent(name: 'span-agent'));

        $first = $this->agentManager->createSubAgent('span-agent', 'task one');
        $first->startedAt = new \DateTimeImmutable('2024-01-15T10:00:00Z');
        $first->completedAt = new \DateTimeImmutable('2024-01-15T10:00:30Z');

        $second = $this->agentManager->createSubAgent('span-agent', 'task two');
        $second->startedAt = new \DateTimeImmutable('2024-01-15T10:00:10Z');
        $second->completedAt = new \DateTimeImmutable('2024-01-15T10:01:15Z');

        // Span, not sum: the two ran concurrently for 30s+65s of individual
        // elapsed time but the agent was only busy for 75 wall-clock seconds.
        $this->assertSame(75, $this->agentManager->elapsedSeconds('span-agent'));
    }

    public function testElapsedSecondsKeepsCountingWhileASubAgentIsStillRunning(): void
    {
        $this->agentManager->register($this->createAgent(name: 'running-agent'));

        $subAgent = $this->agentManager->createSubAgent('running-agent', 'long task');
        $subAgent->status = SubAgent::STATUS_STREAMING;
        $subAgent->startedAt = (new \DateTimeImmutable())->modify('-90 seconds');

        $this->assertGreaterThanOrEqual(90, $this->agentManager->elapsedSeconds('running-agent'));
    }

    public function testTokensAndCostSumAcrossSubAgents(): void
    {
        $this->agentManager->register($this->createAgent(name: 'sum-agent'));

        $first = $this->agentManager->createSubAgent('sum-agent', 'task one');
        $first->tokensUsed = 120;
        $first->costUsd = 0.25;

        $second = $this->agentManager->createSubAgent('sum-agent', 'task two');
        $second->tokensUsed = 30;
        $second->costUsd = 0.05;

        $this->assertSame(150, $this->agentManager->tokensUsed('sum-agent'));
        $this->assertEqualsWithDelta(0.30, $this->agentManager->costUsd('sum-agent'), 0.0001);
    }

    public function testATaskRunStampsStartedAtAndAccumulatesStreamingUsage(): void
    {
        $this->agentManager->register($this->createAgent(name: 'telemetry-agent'));
        // The row is minted by the run itself, so there is nothing stamped
        // before it starts.
        $this->assertSame([], $this->agentManager->runs());

        $manager = $this->agentManager;
        $seenMidFlight = [];
        $startedBeforeTheProvider = null;
        $provider = $this->streamingProviderFrom(static function () use ($manager, &$seenMidFlight, &$startedBeforeTheProvider): \Generator {
            $startedBeforeTheProvider = $manager->runs()[0]->startedAt;
            yield new CompleteResponse(content: 'a', tokensUsed: 10, costUsd: 0.01);
            $seenMidFlight[] = $manager->runs()[0]->tokensUsed;
            yield new CompleteResponse(content: 'b', tokensUsed: 5, costUsd: 0.02);
            $seenMidFlight[] = $manager->runs()[0]->tokensUsed;
        });

        [$result, $subAgent] = $this->delegateOnTask($manager, 'telemetry-agent', $provider);

        $this->assertFalse($result->isError(), $result->content());
        // Would be [0, 0] / 0 / 0.0 against the pre-E6 code, which never
        // touched usage at all.
        $this->assertSame([10, 15], $seenMidFlight);
        $this->assertInstanceOf(\DateTimeImmutable::class, $startedBeforeTheProvider, 'startedAt is stamped as the run starts, before the provider answers');
        $this->assertInstanceOf(\DateTimeImmutable::class, $subAgent->startedAt);
        $this->assertSame(15, $this->agentManager->tokensUsed('telemetry-agent'));
        $this->assertEqualsWithDelta(0.03, $this->agentManager->costUsd('telemetry-agent'), 0.0001);
    }

    public function testATaskRunRecordsUsageOnTheNonStreamingPath(): void
    {
        $this->agentManager->register($this->createAgent(name: 'blocking-agent'));

        [$result, $subAgent] = $this->delegateOnTask(
            $this->agentManager,
            'blocking-agent',
            new ScriptedProvider([new CompleteResponse(content: 'done', tokensUsed: 42, costUsd: 0.5)]),
        );

        $this->assertFalse($result->isError(), $result->content());
        $this->assertSame(42, $subAgent->tokensUsed);
        $this->assertSame(42, $this->agentManager->tokensUsed('blocking-agent'));
        $this->assertEqualsWithDelta(0.5, $this->agentManager->costUsd('blocking-agent'), 0.0001);
        $this->assertInstanceOf(\DateTimeImmutable::class, $subAgent->startedAt);
    }

    public function testExecuteAllMirrorsPoolTelemetryOntoTheRegisteredSubAgents(): void
    {
        // executeAll() via AgentWorkerPool is the path Chat/WorkflowEngine take,
        // so telemetry has to survive it -- not just the Task tool's own run.
        $executor = new class implements ExecutorInterface {
            public function execute(SubAgent $agent, CompleteRequest $request): AgentResult
            {
                return new AgentResult(
                    agentId: $agent->id,
                    status: AgentStatus::Completed,
                    output: 'pool output',
                    tokensUsed: 200,
                    costUsd: 0.75,
                    startedAt: new \DateTimeImmutable('2024-01-15T10:00:00Z'),
                    completedAt: new \DateTimeImmutable('2024-01-15T10:00:40Z'),
                );
            }

            public function executeStream(SubAgent $agent, CompleteRequest $request): \Generator
            {
                yield $this->execute($agent, $request);
            }

            public function cancel(string $agentId): void {}

            public function cancelAll(): void {}
        };

        $manager = new AgentManager(
            $this->provider,
            $this->skillRegistry,
            new AgentWorkerPool(2, $executor),
        );
        $manager->register($this->createAgent(name: 'pool-agent'));
        $subAgent = $manager->createSubAgent('pool-agent', 'pooled task');

        $results = iterator_to_array($manager->executeAll(
            [$subAgent],
            new CompleteRequest(model: 'test-model', messages: []),
        ));

        $this->assertCount(1, $results);
        $this->assertSame(200, $manager->tokensUsed('pool-agent'));
        $this->assertEqualsWithDelta(0.75, $manager->costUsd('pool-agent'), 0.0001);
        $this->assertSame(40, $manager->elapsedSeconds('pool-agent'));
    }

    /**
     * E28: a transient failure mid-stream used to re-run evaluateToolCalls()
     * on the retry, because the evaluation lived INSIDE the retried region.
     * The user-visible price measured at the entry: one Write plus a 503
     * showed TWO approval prompts for the same tool call. The hoist moved
     * collection into the attempt and evaluation after the accepted
     * attempt — so exactly one prompt, on a stream that still really
     * retried (both half-assertions below; either one alone is satisfiable
     * by a provider that never retried at all).
     *
     * On the Task path the same law holds in Runtime: a sequence's tool calls
     * are collected per attempt and dispatched after the accepted one only.
     */
    public function testAMidStreamRetryApprovesEachToolCallExactlyOnce(): void
    {
        $prompts = 0;
        $manager = new AgentManager(provider: $this->provider, skillRegistry: $this->skillRegistry);
        $manager->register(new Agent(
            name: 'retry-gate-agent',
            description: 'retry-gate-agent description',
            prompt: 'Test prompt',
            model: 'claude-sonnet-4-6',
            provider: 'anthropic',
            tools: ['Write'],
            skillNames: [],
            hooks: [],
            isActive: true,
        ));

        $call = new WireToolCall('call-1', 'Write', ['path' => 'x.txt']);
        // The rolled-back attempt streams the tool call and then fails
        // transiently. It streams no TEXT with it: Runtime continues a reply
        // that already reached the screen instead of re-running it, and does
        // not continue one that carried a tool call at all, so an attempt
        // with both would fail the run rather than retry it.
        $provider = new ScriptedProvider([
            [
                new CompleteResponse(content: '', toolCalls: [$call]),
                new CompleteResponse(content: '', isError: true, errorTransient: true),
            ],
            [
                new CompleteResponse(content: 'partial', toolCalls: [$call]),
                new CompleteResponse(content: ' done'),
            ],
            [new CompleteResponse(content: 'reported')],
        ], streams: true);

        [$result, $subAgent] = $this->delegateOnTask(
            $manager,
            'retry-gate-agent',
            $provider,
            engineTools: [$this->recordingTool('Write')],
            sessionGate: new PermissionGate(PermissionMode::Default),
            approver: static function () use (&$prompts): bool {
                $prompts++;

                return true;
            },
        );

        $this->assertFalse($result->isError(), $result->content());
        $this->assertCount(3, $provider->requests, 'the transient error must still have driven exactly one retry, then the step after the tool call');
        $this->assertEquals($provider->requests[0]->messages, $provider->requests[1]->messages, 'the retry re-sends the same step, it does not continue a dropped reply');
        $this->assertSame(1, $prompts, 'a rolled-back attempt must not ask the user about a discarded tool call');
        $this->assertCount(1, $this->recordingTool('Write')->calls, 'the accepted attempt\'s call ran once');
        $this->assertSame(['partial done'], $this->delegatedAssistantTurns($provider->requests[2]), 'the accepted attempt\'s text, once');
        $this->assertSame(SubAgent::STATUS_COMPLETE, $subAgent->status);
    }

    /**
     * E654: executeAll() forwarded the CALLER's shared systemPrompt to every
     * batch member, so a member whose declaration carried its own prompt ran
     * under a sibling's words — the sibling-governance defect the tool-grant
     * resolver already ends one field over. A member that declares NOTHING
     * still gets the shared prompt byte-identical (the left assertion is the
     * control the right one is measured against), and a granted-but-missing
     * skill refuses the WHOLE batch before the pool dispatches anything.
     */
    public function testExecuteAllSpeaksEachMemberOwnSystemPrompt(): void
    {
        $captured = [];
        $capture = function (SubAgent $agent, CompleteRequest $request) use (&$captured): AgentResult {
            $captured[$agent->id] = $request->systemPrompt;

            return new AgentResult(
                agentId: $agent->id,
                status: AgentStatus::Completed,
                output: 'ok',
                tokensUsed: 1,
                costUsd: 0.0,
            );
        };
        $executor = new class($capture) implements ExecutorInterface {
            /** @param \Closure(SubAgent, CompleteRequest): AgentResult $onExecute */
            public function __construct(private \Closure $onExecute) {}

            public function execute(SubAgent $agent, CompleteRequest $request): AgentResult
            {
                return ($this->onExecute)($agent, $request);
            }

            public function executeStream(SubAgent $agent, CompleteRequest $request): \Generator
            {
                yield $this->execute($agent, $request);
            }

            public function cancel(string $agentId): void {}

            public function cancelAll(): void {}
        };

        $manager = new AgentManager(
            $this->provider,
            $this->skillRegistry,
            new AgentWorkerPool(2, $executor),
        );
        $agentA = $this->createAgent(name: 'own-prompt-agent', prompt: 'A-OWN');
        $manager->register($agentA);
        $manager->register($this->createAgent(name: 'silent-prompt-agent', prompt: ''));
        $declaring = $manager->createSubAgent('own-prompt-agent', 'task a');
        $silent = $manager->createSubAgent('silent-prompt-agent', 'task b');

        iterator_to_array($manager->executeAll(
            [$declaring, $silent],
            new CompleteRequest(model: 'test-model', messages: [], systemPrompt: 'SHARED'),
        ));

        // The RAW declared prompt, not systemPrompt()'s composed string: the
        // pool path never carried the session <env> block, and E654 splices
        // the member's own declaration onto the shared prompt. (The roster
        // census in testEveryProductionCallSiteOfTheAgentAssemblerIsDerived
        // AndAccountedFor is what keeps that choice honest — a second
        // systemPrompt() call site here would move it.)
        $this->assertSame(
            "SHARED\n\nA-OWN",
            $captured[$declaring->id],
            'a declaring agent keeps the session prompt AND speaks its own',
        );
        $this->assertSame(
            'SHARED',
            $captured[$silent->id],
            'a silent member forwards the shared prompt verbatim',
        );
    }

    /**
     * E654, the fail-closed half: an unresolvable granted skill stops the
     * whole batch BEFORE dispatch — no member runs with a prompt advertising
     * a body it will never receive (the E643 severity choice at batch scale).
     */
    public function testExecuteAllRefusesTheWholeBatchOnAMissingGrantedSkill(): void
    {
        $dispatched = 0;
        $counter = function (SubAgent $agent) use (&$dispatched): void {
            $dispatched++;
        };
        $executor = new class($counter) implements ExecutorInterface {
            /** @param \Closure(SubAgent): void $onDispatch */
            public function __construct(private \Closure $onDispatch) {}

            public function execute(SubAgent $agent, CompleteRequest $request): AgentResult
            {
                ($this->onDispatch)($agent);

                return new AgentResult(
                    agentId: $agent->id,
                    status: AgentStatus::Completed,
                    output: 'ok',
                    tokensUsed: 1,
                    costUsd: 0.0,
                );
            }

            public function executeStream(SubAgent $agent, CompleteRequest $request): \Generator
            {
                yield $this->execute($agent, $request);
            }

            public function cancel(string $agentId): void {}

            public function cancelAll(): void {}
        };

        $manager = new AgentManager(
            $this->provider,
            $this->skillRegistry,
            new AgentWorkerPool(2, $executor),
        );
        $manager->register($this->createAgent(name: 'clean-member', prompt: 'fine'));
        $manager->register(new Agent(
            name: 'bad-skill-agent',
            description: 'bad-skill-agent description',
            prompt: 'I advertise a skill I do not have',
            model: 'claude-sonnet-4-6',
            provider: 'anthropic',
            tools: [],
            skillNames: ['skill-that-does-not-exist-' . uniqid('', true)],
            hooks: [],
            isActive: true,
        ));
        $clean = $manager->createSubAgent('clean-member', 'task c');
        $bad = $manager->createSubAgent('bad-skill-agent', 'task d');

        // Capture-only catch (SwallowingCatchCensusTest): an assertion failure
        // here would be a RuntimeException too, so nothing is asserted inside
        // the handler — the message check lands after it, beside the others.
        $refusal = null;
        try {
            iterator_to_array($manager->executeAll(
                [$clean, $bad],
                new CompleteRequest(model: 'test-model', messages: [], systemPrompt: 'SHARED'),
            ));
        } catch (\RuntimeException $caught) {
            $refusal = $caught->getMessage();
        }

        $this->assertNotNull($refusal, 'a batch member with an unresolvable granted skill must not run');
        $this->assertStringContainsString('skill-that-does-not-exist', $refusal);

        $this->assertSame(0, $dispatched, 'the refusal lands before the pool dispatches ANY member');
        $this->assertSame(
            \SugarCraft\Code\Agents\SubAgent::STATUS_STOPPED,
            $clean->status,
            'the never-dispatched sibling is settled (stop-shape) with the reason, not left pending-forever',
        );
    }

    public function testStoppingASubAgentFreezesElapsedSeconds(): void
    {
        $this->agentManager->register($this->createAgent(name: 'stop-agent'));
        $subAgent = $this->agentManager->createSubAgent('stop-agent', 'long task');
        $subAgent->status = SubAgent::STATUS_RUNNING;
        $subAgent->startedAt = (new \DateTimeImmutable())->modify('-120 seconds');

        $this->agentManager->stopSubAgent($subAgent->id);

        $frozen = $subAgent->elapsedSeconds();
        $this->assertNotNull($subAgent->completedAt);
        $this->assertGreaterThanOrEqual(120, $frozen);
        // Without the completedAt stamp this would keep climbing forever.
        $this->assertSame($frozen, $subAgent->elapsedSeconds());
        $this->assertSame($frozen, $this->agentManager->elapsedSeconds('stop-agent'));
    }

    public function testFailedSubAgentFreezesElapsedSeconds(): void
    {
        $this->agentManager->register($this->createAgent(name: 'fail-agent'));

        [$result, $subAgent] = $this->delegateOnTask(
            $this->agentManager,
            'fail-agent',
            new ScriptedProvider([new \RuntimeException('provider down')]),
        );

        $this->assertTrue($result->isError(), 'the Task result must carry the provider failure');
        $this->assertStringContainsString('sub-agent "fail-agent" failed: provider down', $result->content());

        $this->assertSame(SubAgent::STATUS_FAILED, $subAgent->status);
        $this->assertSame('sub-agent "fail-agent" failed: provider down', $subAgent->error);
        $this->assertNotNull($subAgent->completedAt);

        $frozen = $this->agentManager->elapsedSeconds('fail-agent');
        $this->assertSame($frozen, $this->agentManager->elapsedSeconds('fail-agent'));
    }

    public function testATaskRunSucceedsNonStreaming(): void
    {
        $agent = $this->createAgent(name: 'exec-agent', prompt: 'Exec prompt');
        $this->agentManager->register($agent);

        $manager = $this->agentManager;
        $results = [];
        [$result, $subAgent] = $this->delegateOnTask(
            $manager,
            'exec-agent',
            new ScriptedProvider([new CompleteResponse(content: 'Execution result')]),
            emitter: static function (SubAgentActivity $activity) use ($manager, &$results): void {
                $output = $manager->runs()[0]->output;
                if ($activity->op === SubAgentActivity::OP_PROGRESS && $output !== '') {
                    $results[] = $output;
                }
            },
        );

        // Non-streaming mode carries no per-chunk intermediates: the reply
        // reaches the row once, whole, where a stream lands chunk by chunk.
        $this->assertFalse($result->isError(), $result->content());
        $this->assertSame(['writing: Execution result'], $results);
        $this->assertSame(SubAgent::STATUS_COMPLETE, $subAgent->status);
        $this->assertSame('Execution result', $subAgent->output);
        $this->assertInstanceOf(\DateTimeImmutable::class, $subAgent->completedAt);
    }

    public function testATaskRunSucceedsStreaming(): void
    {
        $agent = $this->createAgent(name: 'stream-agent', prompt: 'Stream prompt');
        $this->agentManager->register($agent);

        $manager = $this->agentManager;
        $results = [];
        [$result, $subAgent] = $this->delegateOnTask(
            $manager,
            'stream-agent',
            new ScriptedProvider([iterator_to_array($this->createStreamingResponse(['First ', 'second ', 'third']), false)], streams: true),
            emitter: static function (SubAgentActivity $activity) use ($manager, &$results): void {
                $output = $manager->runs()[0]->output;
                if ($activity->op === SubAgentActivity::OP_PROGRESS && $output !== '') {
                    $results[] = $output;
                }
            },
        );

        $this->assertFalse($result->isError(), $result->content());
        $this->assertCount(3, $results);
        $this->assertSame(['writing: First', 'writing: First second', 'writing: First second third'], $results, 'one intermediate output per streamed chunk');
        $this->assertSame(SubAgent::STATUS_COMPLETE, $subAgent->status);
        $this->assertSame('First second third', $subAgent->output);
    }

    public function testATaskRunHandlesException(): void
    {
        $agent = $this->createAgent(name: 'error-agent', prompt: 'Error prompt');
        $this->agentManager->register($agent);

        [$result, $subAgent] = $this->delegateOnTask(
            $this->agentManager,
            'error-agent',
            new ScriptedProvider([new \RuntimeException('Provider error')]),
        );

        $this->assertTrue($result->isError(), 'Expected the provider error on the Task result');
        $this->assertStringContainsString('Provider error', $result->content());
        $this->assertSame(SubAgent::STATUS_FAILED, $subAgent->status);
        $this->assertSame('sub-agent "error-agent" failed: Provider error', $subAgent->error);
    }

    public function testATaskRunsPermissionGateDenyRefusesTheCall(): void
    {
        // A gate factory whose gate denies Bash: the run's own DontAsk gate,
        // stricter than the session's, judges every call beside it.
        $denyGate = new PermissionGate(PermissionMode::DontAsk);

        $customAgentManager = new AgentManager(
            provider: $this->provider,
            skillRegistry: $this->skillRegistry,
            workerPool: null,
            permissionGateFactory: fn() => $denyGate,
        );
        $customAgentManager->register($this->createAgent(name: 'deny-agent', prompt: 'Deny agent'));

        [$subAgent, $turns] = $this->delegateEmittingCalls(
            $customAgentManager,
            'deny-agent',
            [new WireToolCall('c1', 'Bash', ['command' => 'make build'])],
        );

        // The refusal is the denied call's result, and the run goes on past
        // it: a refused call is not a failed run on the Task path.
        $this->assertSame(['Hook denied: Permission mode \'dont-ask\' of agent "deny-agent" does not allow Bash.'], $turns);
        $this->assertSame([], $this->recordingTool('Bash')->calls, 'the denied call never ran');
        $this->assertSame(SubAgent::STATUS_COMPLETE, $subAgent->status);
        $this->assertNull($subAgent->error);
    }

    public function testATaskRunsPermissionGateAskWithNoApproverRefusesTheCall(): void
    {
        // Default mode: `make build` is not a read-only command, so the
        // session gate asks, and nothing is attached to answer.
        $customAgentManager = new AgentManager(
            provider: $this->provider,
            skillRegistry: $this->skillRegistry,
            workerPool: null,
        );
        $customAgentManager->register($this->createAgent(name: 'ask-agent', prompt: 'Ask agent'));

        [$subAgent, $turns] = $this->delegateEmittingCalls(
            $customAgentManager,
            'ask-agent',
            [new WireToolCall('c1', 'Bash', ['command' => 'make build'])],
            sessionMode: PermissionMode::Default,
        );

        $this->assertCount(1, $turns);
        $this->assertStringContainsString('no approver is attached', $turns[0]);
        $this->assertSame([], $this->recordingTool('Bash')->calls, 'an unanswered ask never runs the call');
        $this->assertSame(SubAgent::STATUS_COMPLETE, $subAgent->status);
    }

    /**
     * Phase 1 item 2's third part. An ASK used to be an
     * unconditional hard failure, which made PermissionMode::Auto's whole
     * 3-strike escalation unreachable for sub-agents: escalating from Deny to
     * Ask produced the same dead sub-agent, only with a worse message.
     */
    public function testATaskRunsPermissionGateAskIsRoutedToTheApprover(): void
    {
        $asked = [];

        $customAgentManager = new AgentManager(
            provider: $this->provider,
            skillRegistry: $this->skillRegistry,
            workerPool: null,
        );
        $customAgentManager->register($this->createAgent(name: 'ask-agent', prompt: 'Ask agent'));

        [$subAgent] = $this->delegateEmittingCalls(
            $customAgentManager,
            'ask-agent',
            [new WireToolCall('c1', 'Bash', ['command' => 'make build'])],
            sessionMode: PermissionMode::Default,
            approver: static function (WireToolCall $call) use (&$asked, $customAgentManager): bool {
                $asked[] = [$call->name(), $customAgentManager->runs()[0]->id];

                return true;
            },
        );

        $this->assertSame(SubAgent::STATUS_COMPLETE, $subAgent->status);
        $this->assertNull($subAgent->error);
        $this->assertSame([['Bash', $subAgent->id]], $asked);
        $this->assertSame([['command' => 'make build']], $this->recordingTool('Bash')->calls, 'the approved call ran');
    }

    public function testATaskRunsPermissionGateAskIsRefusedWhenTheApproverRefuses(): void
    {
        $customAgentManager = new AgentManager(
            provider: $this->provider,
            skillRegistry: $this->skillRegistry,
            workerPool: null,
        );
        $customAgentManager->register($this->createAgent(name: 'ask-agent', prompt: 'Ask agent'));

        [$subAgent, $turns] = $this->delegateEmittingCalls(
            $customAgentManager,
            'ask-agent',
            [new WireToolCall('c1', 'Bash', ['command' => 'make build'])],
            sessionMode: PermissionMode::Default,
            approver: static fn(): bool => false,
        );

        $this->assertSame(['Permission denied: Allow Bash to run? (permission mode: default)'], $turns, 'refused at the permission prompt');
        $this->assertSame([], $this->recordingTool('Bash')->calls);
        $this->assertSame(SubAgent::STATUS_COMPLETE, $subAgent->status);
    }

    /**
     * Same rule {@see \SugarCraft\Code\Runtime::settleAsk()} enforces: only a
     * literal `true` grants. Every PermissionReply case is a truthy object, so
     * a cast would turn a Reject into permission.
     */
    public function testATaskRunsPermissionGateAskRequiresLiteralTrue(): void
    {
        $customAgentManager = new AgentManager(
            provider: $this->provider,
            skillRegistry: $this->skillRegistry,
            workerPool: null,
        );
        $customAgentManager->register($this->createAgent(name: 'ask-agent', prompt: 'Ask agent'));

        [, $turns] = $this->delegateEmittingCalls(
            $customAgentManager,
            'ask-agent',
            [new WireToolCall('c1', 'Bash', ['command' => 'make build'])],
            sessionMode: PermissionMode::Default,
            approver: static fn(): mixed => 'yes, go ahead',
        );

        $this->assertSame(['Permission denied: Allow Bash to run? (permission mode: default)'], $turns, 'a truthy non-true answer is refused at the permission prompt');
        $this->assertSame([], $this->recordingTool('Bash')->calls);
    }

    /**
     * The escalation this unblocks end-to-end: Auto denies three of a kind and
     * then hands the fourth to the human instead of killing the sub-agent.
     */
    public function testAutoModeCircuitBreakerEscalationReachesTheApprover(): void
    {
        $escalations = 0;
        $gate = new PermissionGate(
            PermissionMode::Auto,
            [],
            new \SugarCraft\Code\Permissions\SafetyClassifier(),
        );

        $customAgentManager = new AgentManager(
            provider: $this->provider,
            skillRegistry: $this->skillRegistry,
            workerPool: null,
        );
        $customAgentManager->register($this->createAgent(name: 'auto-agent', prompt: 'Auto agent'));

        // Three of the SAME dangerous category: the first two are denied
        // outright, so the third strike is the one the run delivers.
        // Not a security finding, which would ask at once (roadmap 5.11-2).
        $dangerous = new ToolCall(name: 'Bash', arguments: ['command' => 'git push --force origin main']);

        // Strikes 1 and 2 are plain denials.
        $this->assertSame(PermissionDecision::Deny, $gate->evaluate($dangerous));
        $this->assertSame(PermissionDecision::Deny, $gate->evaluate($dangerous));

        // Strike 3 escalates to Ask, which the approver now settles.
        [$subAgent] = $this->delegateEmittingCalls(
            $customAgentManager,
            'auto-agent',
            [new WireToolCall('c1', 'Bash', ['command' => 'git push --force origin main'])],
            sessionGate: $gate,
            approver: static function () use (&$escalations): bool {
                $escalations++;

                return true;
            },
        );

        $this->assertSame(1, $escalations);
        $this->assertSame(SubAgent::STATUS_COMPLETE, $subAgent->status);
        $this->assertCount(1, $this->recordingTool('Bash')->calls, 'the approved third strike ran');
    }

    // -------------------------------------------------------------------------
    // stopSubAgent()
    // -------------------------------------------------------------------------

    public function testStopSubAgentSuccess(): void
    {
        $agent = $this->createAgent(name: 'stop-agent', prompt: 'Stop prompt');
        $this->agentManager->register($agent);

        $subAgent = $this->agentManager->createSubAgent('stop-agent', 'Stop test');

        $this->agentManager->stopSubAgent($subAgent->id);

        $this->assertSame(SubAgent::STATUS_STOPPED, $subAgent->status);
    }

    public function testStopSubAgentNotFoundDoesNothing(): void
    {
        // Early return - should not throw, just do nothing
        $this->agentManager->stopSubAgent('nonexistent-id');
        $this->assertTrue(true); // If we get here, early return worked
    }

    // -------------------------------------------------------------------------
    // removeSubAgent()
    // -------------------------------------------------------------------------

    public function testRemoveSubAgentSuccess(): void
    {
        $agent = $this->createAgent(name: 'remove-agent', prompt: 'Remove prompt');
        $this->agentManager->register($agent);

        $subAgent = $this->agentManager->createSubAgent('remove-agent', 'Remove test');
        $id = $subAgent->id;

        $this->assertNotNull($this->agentManager->subAgent($id));

        $this->agentManager->removeSubAgent($id);

        $this->assertNull($this->agentManager->subAgent($id));
    }

    public function testRemoveSubAgentNotFoundDoesNothing(): void
    {
        // Should not throw, just do nothing
        $this->agentManager->removeSubAgent('nonexistent-id');
        $this->assertTrue(true);
    }

    // -------------------------------------------------------------------------
    // executeAll()
    // -------------------------------------------------------------------------

    public function testExecuteAllWithEmptyArray(): void
    {
        $agents = [];
        $request = new CompleteRequest(
            model: 'test-model',
            messages: [new \SugarCraft\Code\Messages\UserMessage('test')],
        );

        $results = [];
        foreach ($this->agentManager->executeAll($agents, $request) as $result) {
            $results[] = $result;
        }

        $this->assertSame([], $results);
    }

    public function testExecuteAllWithMultipleAgents(): void
    {
        // Create a custom executor that returns predictable results
        $blockingExecutor = $this->createMock(\SugarCraft\Code\Agents\ExecutorInterface::class);
        $blockingExecutor->method('execute')
            ->willReturnCallback(function (\SugarCraft\Code\Agents\SubAgent $agent) {
                return new \SugarCraft\Code\Agents\AgentResult(
                    agentId: $agent->id,
                    status: \SugarCraft\Code\Agents\AgentStatus::Completed,
                    output: 'Output from ' . $agent->agent->name,
                );
            });
        $blockingExecutor->method('executeStream')
            ->willReturnCallback(function (\SugarCraft\Code\Agents\SubAgent $agent) {
                yield new \SugarCraft\Code\Agents\AgentResult(
                    agentId: $agent->id,
                    status: \SugarCraft\Code\Agents\AgentStatus::Completed,
                    output: 'Output from ' . $agent->agent->name,
                );
            });
        $blockingExecutor->method('cancel')->willReturnCallback(function (string $id) {});
        $blockingExecutor->method('cancelAll')->willReturnCallback(function () {});

        $workerPool = new \SugarCraft\Code\Agents\AgentWorkerPool(
            maxConcurrent: 5,
            executor: $blockingExecutor,
        );

        $agentManager = new AgentManager(
            provider: $this->provider,
            skillRegistry: $this->skillRegistry,
            workerPool: $workerPool,
        );

        $agent1 = $this->createAgent(name: 'parallel-agent-1', prompt: 'Agent 1');
        $agent2 = $this->createAgent(name: 'parallel-agent-2', prompt: 'Agent 2');
        $agent3 = $this->createAgent(name: 'parallel-agent-3', prompt: 'Agent 3');

        $subAgent1 = new SubAgent(id: 'sub1', agent: $agent1, task: 'Task 1');
        $subAgent2 = new SubAgent(id: 'sub2', agent: $agent2, task: 'Task 2');
        $subAgent3 = new SubAgent(id: 'sub3', agent: $agent3, task: 'Task 3');

        $request = new CompleteRequest(
            model: 'test-model',
            messages: [new \SugarCraft\Code\Messages\UserMessage('Execute all tasks')],
        );

        $results = [];
        foreach ($agentManager->executeAll([$subAgent1, $subAgent2, $subAgent3], $request) as $result) {
            $results[$result->agentId] = $result;
        }

        $this->assertCount(3, $results);
        foreach ($results as $result) {
            $this->assertNotEmpty($result->agentId);
            $this->assertIsString($result->agentId);
            $this->assertContains($result->status, [
                \SugarCraft\Code\Agents\AgentStatus::Completed,
                \SugarCraft\Code\Agents\AgentStatus::Failed,
                \SugarCraft\Code\Agents\AgentStatus::TimedOut,
                \SugarCraft\Code\Agents\AgentStatus::Stopped,
            ]);
        }
    }

    public function testExecuteAllDelegatesToWorkerPool(): void
    {
        $blockingExecutor = $this->createMock(\SugarCraft\Code\Agents\ExecutorInterface::class);
        $blockingExecutor->method('execute')
            ->willReturnCallback(function (\SugarCraft\Code\Agents\SubAgent $agent) {
                return new \SugarCraft\Code\Agents\AgentResult(
                    agentId: $agent->id,
                    status: \SugarCraft\Code\Agents\AgentStatus::Completed,
                    output: 'Task: ' . $agent->task . ' Agent: ' . $agent->agent->name,
                );
            });
        $blockingExecutor->method('executeStream')
            ->willReturnCallback(function (\SugarCraft\Code\Agents\SubAgent $agent) {
                yield new \SugarCraft\Code\Agents\AgentResult(
                    agentId: $agent->id,
                    status: \SugarCraft\Code\Agents\AgentStatus::Completed,
                    output: 'Task: ' . $agent->task . ' Agent: ' . $agent->agent->name,
                );
            });
        $blockingExecutor->method('cancel')->willReturnCallback(function (string $id) {});
        $blockingExecutor->method('cancelAll')->willReturnCallback(function () {});

        $workerPool = new \SugarCraft\Code\Agents\AgentWorkerPool(
            maxConcurrent: 5,
            executor: $blockingExecutor,
        );

        $agentManager = new AgentManager(
            provider: $this->provider,
            skillRegistry: $this->skillRegistry,
            workerPool: $workerPool,
        );

        $agent = $this->createAgent(name: 'exec-agent', prompt: 'Execute prompt');
        $subAgent = new SubAgent(id: 'pooled-sub', agent: $agent, task: 'Shared task message');

        $request = new CompleteRequest(
            model: 'test-model',
            messages: [new \SugarCraft\Code\Messages\UserMessage('Ignored')],
        );

        $results = [];
        foreach ($agentManager->executeAll([$subAgent], $request) as $result) {
            $results[] = $result;
        }

        $this->assertCount(1, $results);
        $this->assertStringContainsString('Shared task message', $results[0]->output ?? '');
        $this->assertStringContainsString('exec-agent', $results[0]->output ?? '');
    }

    public function testExecuteAllRegistersSubAgentsForTracking(): void
    {
        $customExecutor = $this->createMock(\SugarCraft\Code\Agents\ExecutorInterface::class);
        $customExecutor->method('execute')
            ->willReturnCallback(function (\SugarCraft\Code\Agents\SubAgent $agent) {
                return new \SugarCraft\Code\Agents\AgentResult(
                    agentId: $agent->id,
                    status: \SugarCraft\Code\Agents\AgentStatus::Completed,
                    output: 'Result',
                );
            });
        $customExecutor->method('executeStream')
            ->willReturnCallback(function (\SugarCraft\Code\Agents\SubAgent $agent) {
                yield new \SugarCraft\Code\Agents\AgentResult(
                    agentId: $agent->id,
                    status: \SugarCraft\Code\Agents\AgentStatus::Completed,
                    output: 'Result',
                );
            });
        $customExecutor->method('cancel')->willReturnCallback(function (string $id) {});
        $customExecutor->method('cancelAll')->willReturnCallback(function () {});

        $workerPool = new \SugarCraft\Code\Agents\AgentWorkerPool(
            maxConcurrent: 5,
            executor: $customExecutor,
        );

        $agentManager = new AgentManager(
            provider: $this->provider,
            skillRegistry: $this->skillRegistry,
            workerPool: $workerPool,
        );

        $agent = $this->createAgent(name: 'track-agent', prompt: 'Track prompt');
        $subAgent = new SubAgent(id: 'track-sub', agent: $agent, task: 'Track task');

        $request = new CompleteRequest(
            model: 'test-model',
            messages: [new \SugarCraft\Code\Messages\UserMessage('Track test')],
        );

        $results = [];
        foreach ($agentManager->executeAll([$subAgent], $request) as $result) {
            $results[] = $result;
        }

        $this->assertCount(1, $results);

        // Sub-agent must be registered so it can be looked up
        $tracked = $agentManager->subAgent('track-sub');
        $this->assertSame($subAgent, $tracked);
    }

    public function testExecuteAllUsesProvidedWorkerPool(): void
    {
        $customExecutor = $this->createMock(\SugarCraft\Code\Agents\ExecutorInterface::class);
        $customExecutor->method('execute')
            ->willReturnCallback(function (\SugarCraft\Code\Agents\SubAgent $agent) {
                return new \SugarCraft\Code\Agents\AgentResult(
                    agentId: $agent->id,
                    status: \SugarCraft\Code\Agents\AgentStatus::Completed,
                    output: 'Custom pool result',
                );
            });
        $customExecutor->method('executeStream')
            ->willReturnCallback(function (\SugarCraft\Code\Agents\SubAgent $agent) {
                yield new \SugarCraft\Code\Agents\AgentResult(
                    agentId: $agent->id,
                    status: \SugarCraft\Code\Agents\AgentStatus::Completed,
                    output: 'Custom pool result',
                );
            });
        $customExecutor->method('cancel')->willReturnCallback(function (string $id) {});
        $customExecutor->method('cancelAll')->willReturnCallback(function () {});

        $customPool = new \SugarCraft\Code\Agents\AgentWorkerPool(
            maxConcurrent: 2,
            executor: $customExecutor,
        );

        $agentManager = new AgentManager(
            provider: $this->provider,
            skillRegistry: $this->skillRegistry,
            workerPool: $customPool,
        );

        $agent = $this->createAgent(name: 'pooled-agent', prompt: 'Pooled agent prompt');
        $subAgent = new SubAgent(id: 'custom-pool-sub', agent: $agent, task: 'Pooled task');

        $request = new CompleteRequest(
            model: 'test-model',
            messages: [new \SugarCraft\Code\Messages\UserMessage('Ignored')],
        );

        $results = [];
        foreach ($agentManager->executeAll([$subAgent], $request) as $result) {
            $results[] = $result;
        }

        $this->assertCount(1, $results);
        $this->assertSame('Custom pool result', $results[0]->output);
    }

    // -------------------------------------------------------------------------
    // Team management
    // -------------------------------------------------------------------------

    public function testSetAndGetTeamManager(): void
    {
        $teamManager = new TeamManager($this->teamRegistryDir());

        $this->agentManager->setTeamManager($teamManager);

        $this->assertSame($teamManager, $this->agentManager->teamManager());
    }

    public function testCreateTeamDelegates(): void
    {
        $teamManager = new TeamManager($this->teamRegistryDir());
        $this->agentManager->setTeamManager($teamManager);

        $team = $this->agentManager->createTeam('test-team', 'Test Team', 'lead-agent-1');

        $this->assertInstanceOf(Team::class, $team);
        $this->assertSame('test-team', $team->id);
        $this->assertSame('Test Team', $team->name);
        $this->assertSame('lead-agent-1', $team->leadAgentId);

        // Verify it was actually registered in TeamManager
        $this->assertSame($team, $teamManager->team('test-team'));
    }

    /**
     * The sandbox is asserted where it is OWNED. A Team persists under
     * whatever "~" resolves to when it is constructed, so finding its task
     * list inside the sandbox HOME proves the redirect reached the
     * writer - and a redirect that stopped applying (a reader that fell back
     * to the passwd entry, a cached home, one spelling left behind) leaves the
     * sandbox empty and reds here, naming why, instead of littering the
     * developer's real ~/.candy-code/teams/ in silence.
     */
    public function testATeamBuiltThroughTheManagerPersistsIntoTheSandboxHome(): void
    {
        $this->agentManager->setTeamManager(new TeamManager($this->teamRegistryDir()));
        $teamId = 'sandboxed-' . bin2hex(random_bytes(6));

        $this->agentManager->createTeam($teamId, 'Sandboxed Team', 'lead-agent-1');

        $teamDir = $this->sandboxDir . '/home/.candy-code/teams/' . $teamId;
        $this->assertFileExists(
            $teamDir . '/tasks.sqlite',
            'the Team did not persist into the sandbox HOME - "~" resolved somewhere this test '
                . 'does not own, which on a developer machine is their real ~/.candy-code',
        );
    }

    public function testGetTeamDelegates(): void
    {
        $teamManager = new TeamManager($this->teamRegistryDir());
        $this->agentManager->setTeamManager($teamManager);

        // Create a team directly via TeamManager
        $created = $teamManager->createTeam('existing-team', 'Existing Team', 'lead-agent-2');

        // AgentManager should return it via getTeam
        $retrieved = $this->agentManager->team('existing-team');

        $this->assertSame($created, $retrieved);
    }

    public function testGetTeamThrowsWhenNoTeamManager(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('TeamManager has not been set on AgentManager');

        $this->agentManager->team('any-team');
    }

    public function testHasTeamDelegates(): void
    {
        $teamManager = new TeamManager($this->teamRegistryDir());
        $this->agentManager->setTeamManager($teamManager);

        // Create a team directly via TeamManager
        $teamManager->createTeam('known-team', 'Known Team', 'lead-agent-3');

        $this->assertTrue($this->agentManager->hasTeam('known-team'));
        $this->assertFalse($this->agentManager->hasTeam('unknown-team'));
    }

    public function testHasTeamThrowsWhenNoTeamManager(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('TeamManager has not been set on AgentManager');

        $this->agentManager->hasTeam('any-team');
    }

    public function testRemoveTeamDelegates(): void
    {
        $teamManager = new TeamManager($this->teamRegistryDir());
        $this->agentManager->setTeamManager($teamManager);

        // Create a team via AgentManager
        $created = $this->agentManager->createTeam('removable-team', 'Removable Team', 'lead-agent-4');
        $this->assertTrue($this->agentManager->hasTeam('removable-team'));

        // Remove via AgentManager
        $removed = $this->agentManager->removeTeam('removable-team');

        $this->assertSame($created, $removed);
        $this->assertFalse($this->agentManager->hasTeam('removable-team'));
        $this->assertFalse($teamManager->hasTeam('removable-team'));
    }

    public function testRemoveTeamThrowsWhenNoTeamManager(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('TeamManager has not been set on AgentManager');

        $this->agentManager->removeTeam('any-team');
    }

    public function testCreateTeamThrowsWhenNoTeamManager(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('TeamManager has not been set on AgentManager');

        $this->agentManager->createTeam('new-team', 'New Team', 'lead-agent-5');
    }

    public function testCreateTeamConflictDelegates(): void
    {
        $teamManager = new TeamManager($this->teamRegistryDir());
        $this->agentManager->setTeamManager($teamManager);

        // Create first team succeeds
        $this->agentManager->createTeam('duplicate-team', 'First Team', 'lead-agent-6');

        // Create same teamId again should throw TeamManager's exception
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Team "duplicate-team" already exists.');

        $this->agentManager->createTeam('duplicate-team', 'Second Team', 'lead-agent-7');
    }

    public function testGetTeamsDelegates(): void
    {
        $teamManager = new TeamManager($this->teamRegistryDir());
        $this->agentManager->setTeamManager($teamManager);

        // Initially empty
        $this->assertSame([], $this->agentManager->teams());

        // Create some teams
        $team1 = $this->agentManager->createTeam('team-1', 'Team One', 'lead-1');
        $team2 = $this->agentManager->createTeam('team-2', 'Team Two', 'lead-2');

        $teams = $this->agentManager->teams();

        $this->assertCount(2, $teams);
        $this->assertSame($team1, $teams[0]);
        $this->assertSame($team2, $teams[1]);
    }

    public function testGetTeamsThrowsWhenNoTeamManager(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('TeamManager has not been set on AgentManager');

        $this->agentManager->teams();
    }

    public function testTeamCountDelegates(): void
    {
        $teamManager = new TeamManager($this->teamRegistryDir());
        $this->agentManager->setTeamManager($teamManager);

        // Initially zero
        $this->assertSame(0, $this->agentManager->teamCount());

        // Add teams
        $this->agentManager->createTeam('count-team-1', 'Count Team 1', 'lead-count-1');
        $this->assertSame(1, $this->agentManager->teamCount());

        $this->agentManager->createTeam('count-team-2', 'Count Team 2', 'lead-count-2');
        $this->assertSame(2, $this->agentManager->teamCount());

        // Remove a team
        $this->agentManager->removeTeam('count-team-1');
        $this->assertSame(1, $this->agentManager->teamCount());
    }

    public function testTeamCountThrowsWhenNoTeamManager(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('TeamManager has not been set on AgentManager');

        $this->agentManager->teamCount();
    }

    // -------------------------------------------------------------------------
    // isWorking() and active()'s derived liveness
    // -------------------------------------------------------------------------

    public function testIsWorkingIsFalseForAnAgentWithNoSubAgents(): void
    {
        $this->agentManager->register($this->createAgent(name: 'idle'));

        $this->assertFalse($this->agentManager->isWorking('idle'));
        $this->assertFalse($this->agentManager->isWorking('never-registered'));
    }

    public function testIsWorkingCountsAPendingSubAgent(): void
    {
        // A sub-agent queued behind the pool's concurrency limit is work the
        // user asked for and is waiting on - reporting it idle would make a
        // saturated pool look like an empty one.
        $this->agentManager->register($this->createAgent(name: 'queued'));
        $subAgent = $this->agentManager->createSubAgent('queued', 'task');

        $this->assertSame(SubAgent::STATUS_PENDING, $subAgent->status);
        $this->assertTrue($this->agentManager->isWorking('queued'));
    }

    public function testIsWorkingGoesFalseOnceEverySubAgentIsTerminal(): void
    {
        $this->agentManager->register($this->createAgent(name: 'winding-down'));
        $first = $this->agentManager->createSubAgent('winding-down', 'one');
        $second = $this->agentManager->createSubAgent('winding-down', 'two');

        $first->status = SubAgent::STATUS_COMPLETE;
        $this->assertTrue($this->agentManager->isWorking('winding-down'), 'the second is still live');

        $second->status = SubAgent::STATUS_FAILED;
        $this->assertFalse($this->agentManager->isWorking('winding-down'));
    }

    public function testActivePromotesAnIdleRegistrationThatHasLiveWork(): void
    {
        // The property Bootstrap's roster depends on: agents are registered
        // idle so a launch paints no agent strip, and delegation is what makes
        // one appear.
        $this->agentManager->register($this->createAgent(name: 'delegate', isActive: false));
        $this->assertSame([], $this->agentManager->active());

        $this->agentManager->createSubAgent('delegate', 'task');

        $active = $this->agentManager->active();
        $this->assertCount(1, $active);
        $this->assertSame('delegate', $active[0]->name);
        $this->assertTrue($active[0]->isActive, 'the renderers turn isActive into the literal word "working"');
    }

    public function testActiveDemotesADerivedAgentOnceItsWorkIsDone(): void
    {
        $this->agentManager->register($this->createAgent(name: 'delegate', isActive: false));
        $subAgent = $this->agentManager->createSubAgent('delegate', 'task');

        $this->agentManager->stopSubAgent($subAgent->id);

        $this->assertSame([], $this->agentManager->active());
    }

    public function testActiveDoesNotMutateTheRegistration(): void
    {
        // The registration is the configured agent and outlives any sub-agent;
        // the derived case hands back a copy.
        $registered = $this->createAgent(name: 'delegate', isActive: false);
        $this->agentManager->register($registered);
        $this->agentManager->createSubAgent('delegate', 'task');

        $this->agentManager->active();

        $this->assertFalse($this->agentManager->get('delegate')?->isActive);
        $this->assertFalse($registered->isActive);
    }

    // -------------------------------------------------------------------------
    // liveOutput() / liveOutputs() - the live output buffer seam
    // -------------------------------------------------------------------------

    public function testLiveOutputIsEmptyForAnAgentWithNoSubAgents(): void
    {
        $this->agentManager->register($this->createAgent(name: 'quiet'));

        $this->assertSame('', $this->agentManager->liveOutput('quiet'));
        $this->assertSame('', $this->agentManager->liveOutput('never-registered'));
    }

    public function testLiveOutputJoinsSubAgentBuffersInCreationOrder(): void
    {
        $this->agentManager->register($this->createAgent(name: 'talker'));
        $first = $this->agentManager->createSubAgent('talker', 'one');
        $second = $this->agentManager->createSubAgent('talker', 'two');

        $first->output = 'from the first';
        $second->output = 'from the second';

        $this->assertSame("from the first\nfrom the second", $this->agentManager->liveOutput('talker'));
    }

    public function testLiveOutputGrowsWithTheBufferRatherThanWaitingForCompletion(): void
    {
        $this->agentManager->register($this->createAgent(name: 'streamer'));
        $subAgent = $this->agentManager->createSubAgent('streamer', 'task');
        $subAgent->status = SubAgent::STATUS_STREAMING;

        $subAgent->output .= 'chunk one ';
        $this->assertSame('chunk one ', $this->agentManager->liveOutput('streamer'));

        $subAgent->output .= 'chunk two';
        $this->assertSame('chunk one chunk two', $this->agentManager->liveOutput('streamer'));
    }

    public function testLiveOutputsOmitsSilentAgents(): void
    {
        // The split-pane compositor lays several agents out side by side;
        // including the silent ones would give it a row of empty tiles.
        $this->agentManager->register($this->createAgent(name: 'loud'));
        $this->agentManager->register($this->createAgent(name: 'silent'));
        $loud = $this->agentManager->createSubAgent('loud', 'task');
        $this->agentManager->createSubAgent('silent', 'task');
        $loud->output = 'saying something';

        $this->assertSame(['loud' => 'saying something'], $this->agentManager->liveOutputs());
    }

    public function testLiveOutputsDropsAnAgentWhoseSubAgentWasRemoved(): void
    {
        // This used to hold because liveOutputs() iterated the REGISTERED map
        // and asked liveOutput() for each name; it holds now because the
        // sub-agent it derives from is gone from the map it reads. Same
        // outcome, and the reason matters — the registration is untouched
        // either way.
        $this->agentManager->register($this->createAgent(name: 'gone'));
        $subAgent = $this->agentManager->createSubAgent('gone', 'task');
        $subAgent->output = 'orphaned text';

        $this->agentManager->removeSubAgent($subAgent->id);

        $this->assertNotNull($this->agentManager->get('gone'), 'the registration survives');
        $this->assertSame([], $this->agentManager->liveOutputs());
    }

    /**
     * The defect that made the split-pane compositor unreachable in
     * production: `liveOutputs()` iterated the REGISTERED map, and the one
     * production producer of live agent output does not register.
     *
     * {@see \SugarCraft\Code\Workflows\WorkflowEngine::executeParallelStage()}
     * builds ad-hoc `Agent`s named `$task->name ?? $task->agentType`
     * and hands the `SubAgent`s to
     * {@see AgentManager::executeAll()}, whose first loop files them under
     * `$subAgents` and nowhere else (`AgentManager::executeAll()`'s registration loop) — reproduced here
     * by that exact insertion. Neither shipped workflow names a parallel task
     * after a roster agent (`examples/workflows/lint-then-fix.yaml` names
     * `style-fixer`/`correctness-fixer`), so the registered map was the one
     * place the answer could never be. Against the old implementation the last
     * assertion returns `[]`.
     */
    public function testLiveOutputsSeesAWorkflowSpawnedAgentThatWasNeverRegistered(): void
    {
        $this->agentManager->register($this->createAgent(name: 'reviewer', isActive: false));

        $subAgent = $this->fileSubAgent(
            new SubAgent(
                id: 'fix-1-abc',
                agent: $this->createAgent(name: 'style-fixer'),
                task: 'fix the style',
            ),
        );
        $subAgent->status = SubAgent::STATUS_STREAMING;
        $subAgent->output = 'rewriting Foo.php';

        $this->assertNull($this->agentManager->get('style-fixer'), 'never registered, by construction');
        $this->assertTrue($this->agentManager->isWorking('style-fixer'));
        $this->assertSame(['style-fixer' => 'rewriting Foo.php'], $this->agentManager->liveOutputs());
    }

    /**
     * The other half: it has to go away again.
     *
     * Nothing clears {@see SubAgent::$output} — {@see AgentManager::executeAll()}
     * settles the pool's FINAL text onto it — so a `liveOutputs()` that filtered
     * only on `output !== ''` reported a finished agent for the rest of the
     * session, and a pane keyed off it would never have come down. The filter
     * is the same `!isComplete() && !isStopped()` predicate {@see
     * AgentManager::isWorking()} applies, so this method and `active()` cannot
     * disagree about who is working.
     */
    public function testLiveOutputsDropsAnAgentOnceItsSubAgentReachesATerminalState(): void
    {
        $this->agentManager->register($this->createAgent(name: 'talker'));
        $subAgent = $this->agentManager->createSubAgent('talker', 'task');
        $subAgent->status = SubAgent::STATUS_STREAMING;
        $subAgent->output = 'still going';

        $this->assertSame(['talker' => 'still going'], $this->agentManager->liveOutputs());

        foreach ([SubAgent::STATUS_COMPLETE, SubAgent::STATUS_STOPPED, SubAgent::STATUS_FAILED] as $terminal) {
            $subAgent->status = $terminal;
            $this->assertFalse($this->agentManager->isWorking('talker'), "isWorking after {$terminal}");
            $this->assertSame([], $this->agentManager->liveOutputs(), "liveOutputs after {$terminal}");
            // The RAW buffer accessor keeps its value: the dashboard reads it
            // for an agent it has already decided is worth a row.
            $this->assertSame('still going', $this->agentManager->liveOutput('talker'));
        }
    }

    /**
     * Two sub-agents under one workflow name join newest-last, and a finished
     * one contributes nothing — a mid-flight stage must not have a retry's
     * dead first attempt pasted above its live text.
     */
    public function testLiveOutputsJoinsOnlyTheNonTerminalSubAgentsOfOneName(): void
    {
        $dead = $this->fileSubAgent(new SubAgent(
            id: 'fix-1',
            agent: $this->createAgent(name: 'style-fixer'),
            task: 't',
        ));
        $dead->status = SubAgent::STATUS_FAILED;
        $dead->output = 'first attempt';

        $live = $this->fileSubAgent(new SubAgent(
            id: 'fix-2',
            agent: $this->createAgent(name: 'style-fixer'),
            task: 't',
        ));
        $live->status = SubAgent::STATUS_RUNNING;
        $live->output = 'second attempt';

        $third = $this->fileSubAgent(new SubAgent(
            id: 'fix-3',
            agent: $this->createAgent(name: 'style-fixer'),
            task: 't',
        ));
        $third->status = SubAgent::STATUS_STREAMING;
        $third->output = 'third attempt';

        $this->assertSame(
            ['style-fixer' => "second attempt\nthird attempt"],
            $this->agentManager->liveOutputs(),
        );
    }

    /**
     * File a SubAgent under the manager's sub-agent map exactly as
     * {@see AgentManager::executeAll()}'s first loop does
     * (`AgentManager::executeAll()`'s registration loop), without a pool or a provider — the workflow
     * path's shape, minus its I/O.
     */
    private function fileSubAgent(SubAgent $subAgent): SubAgent
    {
        $property = new \ReflectionProperty(AgentManager::class, 'subAgents');
        $map = $property->getValue($this->agentManager);
        $map[$subAgent->id] = $subAgent;
        $property->setValue($this->agentManager, $map);

        return $subAgent;
    }

    // -------------------------------------------------------------------------
    // executeAll() settles pool results back onto the SubAgent
    // -------------------------------------------------------------------------

    public function testExecuteAllSettlesTheSubAgentsStatusAndOutputFromThePoolResult(): void
    {
        // Without this, a pool-executed sub-agent stayed `pending` with an
        // empty buffer forever: isWorking() (and through it active(), the
        // status strip and the dashboard) reported a finished agent as still
        // working for the rest of the session.
        $manager = new AgentManager(
            $this->provider,
            $this->skillRegistry,
            new AgentWorkerPool(2, $this->completingExecutor('settled output', AgentStatus::Completed)),
        );
        $manager->register($this->createAgent(name: 'pool-agent', isActive: false));
        $subAgent = $manager->createSubAgent('pool-agent', 'pooled task');

        iterator_to_array($manager->executeAll(
            [$subAgent],
            new CompleteRequest(model: 'test-model', messages: []),
        ));

        $this->assertSame(SubAgent::STATUS_COMPLETE, $subAgent->status);
        $this->assertSame('settled output', $subAgent->output);
        $this->assertSame('settled output', $manager->liveOutput('pool-agent'));
        $this->assertFalse($manager->isWorking('pool-agent'));
        $this->assertSame([], $manager->active());
    }

    public function testExecuteAllFoldsATimeoutOntoTheFailedStatus(): void
    {
        // SubAgent has no `timed_out`; from the caller's side a timeout is a
        // failure that happens to have a cause.
        $manager = new AgentManager(
            $this->provider,
            $this->skillRegistry,
            new AgentWorkerPool(2, $this->completingExecutor(null, AgentStatus::TimedOut)),
        );
        $manager->register($this->createAgent(name: 'slow-agent'));
        $subAgent = $manager->createSubAgent('slow-agent', 'slow task');

        iterator_to_array($manager->executeAll(
            [$subAgent],
            new CompleteRequest(model: 'test-model', messages: []),
        ));

        $this->assertSame(SubAgent::STATUS_FAILED, $subAgent->status);
        // A null output must not blank a buffer the sub-agent already had.
        $this->assertSame('', $subAgent->output);
    }

    // -------------------------------------------------------------------------
    // Cancelled / abandoned sub-agents must reach a terminal status
    // -------------------------------------------------------------------------

    /**
     * `stopOnFirstFailure` cancels the rest of the queue, and the pool yields
     * NO result for a sub-agent it never dispatched — so those sub-agents were
     * left at `pending` forever. Because pending counts as working,
     * `isWorking()` (and through it `active()`, the transcript strip and
     * {@see \SugarCraft\Code\Tui\Components\AgentDashboardPane}) then painted
     * `[working]` for the rest of the session with nothing running.
     *
     * Fails if the fix is reverted: sub-agents two and three stay
     * `SubAgent::STATUS_PENDING`, so the status assertions fail and
     * `assertFalse($manager->isWorking(...))` fails with the agent still
     * reported as working.
     */
    public function testCancelledSubAgentsSettleInsteadOfBeingLeftPendingForever(): void
    {
        $manager = new AgentManager($this->provider, $this->skillRegistry);
        $manager->register($this->createAgent(name: 'coder', isActive: false));

        // maxConcurrent=1 so exactly one runs, fails, and empties the queue.
        $pool = (new AgentWorkerPool(1, $this->completingExecutor(null, AgentStatus::Failed)))
            ->withStopOnFirstFailure(true);

        $subAgents = [
            $manager->createSubAgent('coder', 'one'),
            $manager->createSubAgent('coder', 'two'),
            $manager->createSubAgent('coder', 'three'),
        ];

        $results = iterator_to_array($manager->executeAll(
            $subAgents,
            new CompleteRequest(model: 'test-model', messages: []),
            $pool,
        ));

        $this->assertCount(1, $results, 'stopOnFirstFailure must cancel the rest of the queue');
        $this->assertSame(SubAgent::STATUS_FAILED, $subAgents[0]->status);
        $this->assertSame(SubAgent::STATUS_STOPPED, $subAgents[1]->status);
        $this->assertSame(SubAgent::STATUS_STOPPED, $subAgents[2]->status);

        // The property the renderers actually read.
        $this->assertFalse($manager->isWorking('coder'));
        $this->assertSame([], $manager->active());

        // A terminal status without a completedAt would leave elapsedSeconds()
        // counting against wall-clock forever, which is the same bug wearing a
        // different hat.
        $this->assertNotNull($subAgents[1]->completedAt);
        $this->assertNotNull($subAgents[2]->completedAt);
    }

    /**
     * A caller that walks away mid-iteration leaves the same wreckage as a
     * cancellation, so the settling is a `finally` rather than a tail: the
     * generator is destroyed on `break`, and everything still pending has to
     * stop claiming to be working.
     */
    public function testAbandoningTheGeneratorMidIterationStillSettlesTheRemainder(): void
    {
        $manager = new AgentManager($this->provider, $this->skillRegistry);
        $manager->register($this->createAgent(name: 'coder', isActive: false));

        $pool = new AgentWorkerPool(1, $this->completingExecutor('done', AgentStatus::Completed));

        $subAgents = [
            $manager->createSubAgent('coder', 'one'),
            $manager->createSubAgent('coder', 'two'),
            $manager->createSubAgent('coder', 'three'),
        ];

        foreach ($manager->executeAll($subAgents, new CompleteRequest(model: 'test-model', messages: []), $pool) as $result) {
            unset($result);
            break;
        }

        $this->assertSame(SubAgent::STATUS_COMPLETE, $subAgents[0]->status);
        $this->assertFalse($manager->isWorking('coder'));
    }

    /**
     * The settling must not overwrite an outcome the pool DID report — a
     * completed sub-agent stays complete, with its output intact.
     */
    public function testSettlingLeavesResultsThePoolAlreadyReportedAlone(): void
    {
        $manager = new AgentManager($this->provider, $this->skillRegistry);
        $manager->register($this->createAgent(name: 'coder', isActive: false));

        $pool = new AgentWorkerPool(2, $this->completingExecutor('the answer', AgentStatus::Completed));

        $subAgents = [
            $manager->createSubAgent('coder', 'one'),
            $manager->createSubAgent('coder', 'two'),
        ];

        iterator_to_array($manager->executeAll(
            $subAgents,
            new CompleteRequest(model: 'test-model', messages: []),
            $pool,
        ));

        foreach ($subAgents as $subAgent) {
            $this->assertSame(SubAgent::STATUS_COMPLETE, $subAgent->status);
            $this->assertSame('the answer', $subAgent->output);
            $this->assertNull($subAgent->error);
        }
    }

    // -------------------------------------------------------------------------
    // Helper methods
    // -------------------------------------------------------------------------

    /** An executor that returns one fixed result, for the pool-settling tests. */
    private function completingExecutor(?string $output, AgentStatus $status): ExecutorInterface
    {
        return new class ($output, $status) implements ExecutorInterface {
            public function __construct(
                private readonly ?string $output,
                private readonly AgentStatus $status,
            ) {}

            public function execute(SubAgent $agent, CompleteRequest $request): AgentResult
            {
                return new AgentResult(
                    agentId: $agent->id,
                    status: $this->status,
                    output: $this->output,
                    startedAt: new \DateTimeImmutable('2024-01-15T10:00:00Z'),
                    completedAt: new \DateTimeImmutable('2024-01-15T10:00:10Z'),
                );
            }

            public function executeStream(SubAgent $agent, CompleteRequest $request): \Generator
            {
                yield $this->execute($agent, $request);
            }

            public function cancel(string $agentId): void {}

            public function cancelAll(): void {}
        };
    }


    // -------------------------------------------------------------------------
    // The sub-agent TOOL GRANT (C7).
    //
    // AgentDefinition::$defaultTools reached Agent::$tools faithfully and then
    // died: the manager's old sequential runner built its CompleteRequest with
    // no `tools` argument, CompleteRequest defaults that to null, and every
    // provider that reads the field gates on `$request->tools !== null`. On
    // THAT method a preset reached the model with NO tools while its system
    // prompt described the roster it thought it had. The runner is gone
    // (CC1C-c2a moved these tests onto TaskTool's engine path, which resolves
    // the same grant through AgentManager::grantedToolsFor()); the request
    // observed below is the one that path sends.
    //
    // SCOPED TO THAT PATH ON PURPOSE, because the sentence above is not
    // true of every path and an earlier version of this header implied it was.
    // The live parallel path is executeAll() -> AgentWorkerPool, and
    // WorkflowEngine builds its request with `tools: $firstTask->tools`, where
    // WorkflowTask::$tools defaults to `[]` and NOT to null — a distinction
    // this file spends a whole test on, since `[]` and `null` are
    // distinguishable to four of the six providers. That path is a separate,
    // still-open finding; nothing below claims to cover it.
    //
    // These pin the behaviour, not the structure — the RESTRICTION as much as
    // the grant, because a test that only proves tools arrive would pass an
    // implementation that ships the whole registry.
    // -------------------------------------------------------------------------

    /** @return list<\SugarCraft\Code\Tools\Tool> */
    private function fakeRegistry(string ...$names): array
    {
        return array_map(
            static fn(string $name): \SugarCraft\Code\Tools\Tool => new class ($name) implements \SugarCraft\Code\Tools\Tool {
                public function __construct(private readonly string $name) {}

                public function name(): string
                {
                    return $this->name;
                }

                public function description(): string
                {
                    return "fake {$this->name}";
                }

                public function inputSchema(): array
                {
                    return ['type' => 'object'];
                }

                public function execute(array $args): \SugarCraft\Code\Tools\ToolResult
                {
                    return new \SugarCraft\Code\Tools\ToolResult('id', 'ok');
                }
            },
            $names,
        );
    }

    /**
     * Run one sub-agent to completion and hand back the request the provider
     * actually received.
     *
     * The captured request is the ONLY honest observation point for this item:
     * every assertion about "the sub-agent got tools" that stops short of the
     * provider is an assertion about a field being copied.
     *
     * @param list<string> $grant
     * @param ?list<\SugarCraft\Code\Tools\Tool> $registry
     * @param ?list<\SugarCraft\Code\Tools\Tool> $universe The UNFILTERED
     *        ceiling $registry was narrowed from — supply it whenever the
     *        fixture models a session with `allowedTools`/`disabledTools`
     *        (E639 option 1); omit it to keep the old all-or-nothing refusal.
     * @param list<mixed> $mcpServers the preset's `mcpServers` allowlist to
     *        carry onto the Agent — E696-α narrows MCP bridges by it at
     *        resolution; omit (empty) for the allow-all default every
     *        built-in preset ships.
     * @param list<\SugarCraft\Code\Tools\Tool> $engineTools the session tool
     *        set the run's engine offers; empty (the default) offers none, so
     *        a roster that resolves to no narrowing reaches the wire as null
     */
    private function captureSubAgentRequest(array $grant, ?array $registry, ?array $universe = null, array $mcpServers = [], array $engineTools = []): CompleteRequest
    {
        $manager = new AgentManager(
            provider: $this->provider,
            skillRegistry: $this->skillRegistry,
            toolRegistry: $registry,
            toolUniverse: $universe,
        );
        $manager->register(new Agent(
            name: 'granted',
            description: 'granted description',
            prompt: 'Test prompt',
            model: 'claude-sonnet-4-6',
            provider: 'anthropic',
            tools: $grant,
            skillNames: [],
            hooks: [],
            isActive: true,
            mcpServers: $mcpServers,
        ));

        $provider = new ScriptedProvider([new CompleteResponse(content: 'done')]);
        [$result] = $this->delegateOnTask($manager, 'granted', $provider, engineTools: $engineTools);

        $this->assertFalse($result->isError(), $result->content());
        $this->assertCount(1, $provider->requests, 'the provider was never called');

        return $provider->requests[0];
    }

    /** @param ?list<\SugarCraft\Code\Tools\Tool> $tools */
    private static function toolNames(?array $tools): ?array
    {
        return $tools === null
            ? null
            : array_map(static fn(\SugarCraft\Code\Tools\Tool $t): string => $t->name(), $tools);
    }

    /**
     * A real {@see McpToolBridge} over a declared tool, WITHOUT touching a
     * transport: the bridge constructor starts nothing (a non-existent config
     * path is fine — the same hermetic recipe McpToolBridgeTest ships), and
     * roster resolution reads only `name()` and `descriptor()->serverName`,
     * never the client. `serverName` is the RAW `.mcp.json` key; the wire name
     * sanitises it (`my_files` → `mcp__my_5Ffiles__ping`), which is exactly
     * the pair E696-α's raw-vs-raw comparison turns on.
     */
    private function mcpBridge(string $server, string $tool = 'ping'): McpToolBridge
    {
        return new McpToolBridge(
            new McpClient('/nonexistent/.mcp.json', unrestricted: true),
            new McpTool(
                name: $tool,
                description: 'Fixture bridge tool.',
                inputSchema: ['type' => 'object'],
                serverName: $server,
            ),
        );
    }

    // -------------------------------------------------------------------------
    // E696-α: per-preset `mcpServers` narrowing at grant-resolution.
    // -------------------------------------------------------------------------

    /**
     * THE FEATURE: a preset naming servers restricts its agent's roster to
     * those servers' bridges. `Bash` rides through untouched — the gate is
     * `instanceof McpToolBridge`, so non-MCP grants cannot be affected by an
     * MCP field.
     */
    public function testAPresetNamingOneServerKeepsOnlyThatServersBridge(): void
    {
        $request = $this->captureSubAgentRequest(
            ['Bash', 'mcp__*'],
            array_merge(
                $this->fakeRegistry('Bash'),
                [$this->mcpBridge('alpha'), $this->mcpBridge('beta')],
            ),
            mcpServers: ['alpha'],
        );

        $this->assertSame(
            ['Bash', 'mcp__alpha__ping'],
            self::toolNames($request->tools),
            'the roster must carry the named server\'s bridge and nothing from the unnamed one',
        );
    }

    /**
     * ZERO BLAST RADIUS, both spellings: the empty list (what every built-in
     * preset ships and what an absent `mcpServers:` key parses to) and the
     * `*` entry advertise every bridge — byte-identical rosters. A regression
     * that read `[]` as "allow nothing" would silently MCP-gut every existing
     * sub-agent, so this is the pin that protects the whole fleet.
     */
    public function testAnEmptyAllowListAdvertisesEveryBridgeExactlyLikeTheGlob(): void
    {
        $registry = array_merge(
            $this->fakeRegistry('Bash'),
            [$this->mcpBridge('alpha'), $this->mcpBridge('beta')],
        );

        $empty = $this->captureSubAgentRequest(['Bash', 'mcp__*'], $registry, mcpServers: []);
        $glob = $this->captureSubAgentRequest(['Bash', 'mcp__*'], $registry, mcpServers: ['*']);

        $expected = ['Bash', 'mcp__alpha__ping', 'mcp__beta__ping'];
        $this->assertSame($expected, self::toolNames($empty->tools));
        $this->assertSame($expected, self::toolNames($glob->tools));
    }

    /**
     * THE E42 CLASS, pinned on both legs: the allowlist speaks RAW config keys
     * and is compared against `descriptor()->serverName`, never the sanitised
     * wire spelling. A server named `my_files` rides the wire as
     * `mcp__my_5Ffiles__ping` — the raw entry keeps it; a preset author who
     * pasted the sanitised half matches nothing and the bridge is dropped
     * (roster empties to null, the same shape an all-denied grant produces).
     */
    public function testTheAllowListMatchesRawServerKeysAndNotTheSanitisedWireSpelling(): void
    {
        $keep = $this->captureSubAgentRequest(
            ['mcp__*'],
            [$this->mcpBridge('my_files')],
            mcpServers: ['my_files'],
        );
        $this->assertSame(
            ['mcp__my_5Ffiles__ping'],
            self::toolNames($keep->tools),
            'a raw entry must keep the bridge whose WIRE name sanitises the underscore — comparing wire-against-raw would drop it',
        );

        $drop = $this->captureSubAgentRequest(
            ['mcp__*'],
            [$this->mcpBridge('my_files')],
            mcpServers: ['my_5Ffiles'],
        );
        $this->assertNull(
            $drop->tools,
            'the sanitised spelling is not a config key; the router law drops it and an emptied roster reaches the provider as null',
        );
    }

    /**
     * ISS-030: the allowlist never needed the `tools:` declaration to be
     * present to mean something. Before the fix, silence about `tools` took
     * the early null and the bridge filter below never ran, so a preset with
     * `mcpServers: [alpha]` and no `tools:` got EVERY bridge from the engine.
     * Now the silent-tools path still narrows MCP by the declared servers.
     */
    public function testMcpServersWithoutToolsStillNarrowsTheBridges(): void
    {
        $request = $this->captureSubAgentRequest(
            [],
            array_merge(
                $this->fakeRegistry('Bash'),
                [$this->mcpBridge('alpha'), $this->mcpBridge('beta')],
            ),
            mcpServers: ['alpha'],
        );

        $this->assertSame(
            ['Bash', 'mcp__alpha__ping'],
            self::toolNames($request->tools),
            'a tools-silent preset with a server allowlist must not hand the run the unnamed server',
        );
    }

    /**
     * ZERO BLAST RADIUS on the silent-tools side too: no `tools:` AND no
     * `mcpServers:` is still silence — null, "the agent says nothing about
     * tools", the run keeps the engine's set.
     */
    public function testSilenceAboutBothListsIsStillSilence(): void
    {
        $request = $this->captureSubAgentRequest(
            [],
            array_merge($this->fakeRegistry('Bash'), [$this->mcpBridge('alpha')]),
            mcpServers: [],
        );

        $this->assertNull($request->tools);
    }

    /**
     * A registry whose only bridges the allowlist refuses resolves to the
     * EMPTY roster, not to null: null would re-admit every bridge through
     * TaskTool's `$granted ?? $engine->tools()` fallback, which is the exact
     * leak this item is about.
     *
     * Observed where it bites: the engine offers the refused bridge, and the
     * run's request carries no tools at all — the empty roster reaches the
     * wire as no tool block (Runtime sends an empty list as null), where a
     * null roster would have carried the engine's bridge.
     */
    public function testAnAllowlistRefusingEveryOfferedBridgeEmptiesTheRosterRatherThanNullingIt(): void
    {
        $offered = [$this->mcpBridge('beta')];
        $request = $this->captureSubAgentRequest(
            [],
            $offered,
            mcpServers: ['alpha'],
            engineTools: $offered,
        );

        $this->assertNull($request->tools, 'the refused bridge the engine offers must not reach the run');
    }

    /**
     * ISS-030, the per-call half: {@see AgentManager::grantRefusalFor()}
     * consults the server allowlist for bridge calls even when the agent
     * declared no `tools:` — the verdict the SubAgentGrantHook asks is not
     * gated on the grant list being non-empty any more than the roster is.
     */
    public function testThePerCallVerdictRefusesABridgeOutsideTheServerAllowlist(): void
    {
        $manager = new AgentManager(
            provider: $this->provider,
            skillRegistry: $this->skillRegistry,
            toolRegistry: [$this->mcpBridge('alpha'), $this->mcpBridge('beta')],
        );
        $manager->register(new Agent(
            name: 'docsonly',
            description: 'docsonly',
            prompt: 'p',
            model: 'm',
            provider: 'anthropic',
            tools: [],
            skillNames: [],
            hooks: [],
            isActive: false,
            mcpServers: ['alpha'],
        ));
        $sub = $manager->createSubAgent('docsonly', 'x');

        $this->assertNull($manager->grantRefusalFor(new \SugarCraft\Code\ToolCall('mcp__alpha__ping', []), $sub));

        $refused = $manager->grantRefusalFor(new \SugarCraft\Code\ToolCall('mcp__beta__ping', []), $sub);
        $this->assertNotNull($refused, 'a tools-silent preset still bounds its named servers per call');
        $this->assertStringContainsString('MCP server allowlist', (string) $refused);
    }

    /**
     * FAIL LOUD, AND ONLY WHERE IT BITES: a non-string allowlist entry that a
     * bridge actually meets raises instead of quietly matching nothing — the
     * same argument `namePatterns()` gives for malformed grant declarations.
     * The lazy half is the parity statement: with no bridges in the registry
     * the malformed list never reaches the predicate and costs nothing, which
     * is why this throw cannot fire for agents that never touch MCP.
     */
    public function testANonStringAllowListEntryFailsLoudWhereABridgeIsNarrowedAndStaysSilentWhereNoneIs(): void
    {
        $refusal = $this->subAgentGrantRefusal(['mcp__*'], [$this->mcpBridge('alpha')], mcpServers: [42]);
        $this->assertStringContainsString('non-empty string', $refusal, 'a malformed entry that a bridge meets must not read as a rule that matches nothing');

        $request = $this->captureSubAgentRequest(
            ['Bash'],
            $this->fakeRegistry('Bash'),
            mcpServers: [42],
        );
        $this->assertSame(['Bash'], self::toolNames($request->tools));
    }

    /**
     * THE CALL-TIME LAYER, re-pinned for MCP names: a preset that declares its
     * allowed servers in BOTH lists (tools and mcpServers) gets list-time
     * narrowing AND a refusal on a hallucinated call to the dropped server.
     * Since ISS-030 the refusal names the SERVER allowlist — the MCP check now
     * runs ahead of the grant scan and states the tighter reason for a bridge
     * call (the same call that used to fall to "outside the tool grant"); the
     * verdict is identical, and the roster still never advertises the tool.
     */
    public function testACallToANarrowedAwayBridgeOutsideTheDeclarationIsRefusedByTheGrant(): void
    {
        $manager = new AgentManager(
            provider: $this->provider,
            skillRegistry: $this->skillRegistry,
            toolRegistry: array_merge(
                $this->fakeRegistry('Bash'),
                [$this->mcpBridge('alpha'), $this->mcpBridge('beta')],
            ),
        );
        $manager->register(new Agent(
            name: 'scoped',
            description: 'scoped description',
            prompt: 'Test prompt',
            model: 'claude-sonnet-4-6',
            provider: 'anthropic',
            tools: ['mcp__alpha__ping'],
            skillNames: [],
            hooks: [],
            isActive: true,
            mcpServers: ['alpha'],
        ));

        $provider = new ScriptedProvider([
            new CompleteResponse(content: 'Result', toolCalls: [new WireToolCall('c1', 'mcp__beta__ping', [])]),
            new CompleteResponse(content: 'reported'),
        ]);
        [$result, $subAgent] = $this->delegateOnTask($manager, 'scoped', $provider);

        $this->assertFalse($result->isError(), $result->content());
        $this->assertSame(['mcp__alpha__ping'], self::toolNames($provider->requests[0]->tools), 'the roster never advertises the dropped server');
        $this->assertSame(
            ['Tool not found: mcp__beta__ping'],
            $this->delegatedToolTurns($provider->requests[1]),
            'a call to the dropped server outside the declaration must still be refused',
        );

        // The grant verdict the Task path installs beside the roster states the
        // tighter reason for the same call.
        $verdict = (new SubAgentGrantHook($manager, $subAgent))
            ->execute(new HookContext('s', 'mcp__beta__ping', [], '', '', 'm', 'p', ''));
        $this->assertFalse($verdict->permitsExecution());
        $this->assertStringContainsString('is refused by the MCP server allowlist', $verdict->message);
        $this->assertSame(SubAgent::STATUS_COMPLETE, $subAgent->status, 'a refused call is not a failed run on the Task path');
    }

    /**
     * THE HEADLINE. The grant reaches the provider as a non-null array of Tool
     * objects — not strings, which is what `tools: $agent->tools` would have
     * sent and what would have fatalled on `->name()` inside the provider.
     */
    public function testDeclaredToolsReachTheProviderAsResolvedToolObjects(): void
    {
        $request = $this->captureSubAgentRequest(
            ['Read', 'Grep'],
            $this->fakeRegistry('Bash', 'Read', 'Edit', 'Grep', 'Write'),
        );

        $this->assertNotNull($request->tools, 'a declared grant must not reach the provider as null');
        $this->assertSame(['Read', 'Grep'], self::toolNames($request->tools));

        foreach ($request->tools as $tool) {
            $this->assertInstanceOf(\SugarCraft\Code\Tools\Tool::class, $tool);
        }
    }

    /**
     * THE RESTRICTION, which is the half a "tools arrive" test cannot see: an
     * implementation that shipped the whole registry would pass the test above
     * and fail this one.
     */
    public function testAGrantOfReadDoesNotYieldEdit(): void
    {
        $request = $this->captureSubAgentRequest(
            ['Read'],
            $this->fakeRegistry('Bash', 'Read', 'Edit', 'Grep', 'Write'),
        );

        $this->assertSame(['Read'], self::toolNames($request->tools));
        $this->assertNotContains('Edit', self::toolNames($request->tools));
        $this->assertNotContains('Bash', self::toolNames($request->tools));
        $this->assertNotContains('Write', self::toolNames($request->tools));
    }

    /**
     * `Bash(git *)`'s NAME half selects the tool. The argument half cannot ride
     * on a tool schema, so the roster carries the whole `Bash` — which is why
     * the per-call enforcement below is not optional.
     */
    public function testAnArgumentScopedGrantResolvesOnItsNameHalf(): void
    {
        $request = $this->captureSubAgentRequest(
            ['Read', 'Grep', 'Bash(git *)'],
            $this->fakeRegistry('Bash', 'Read', 'Edit', 'Grep'),
        );

        $this->assertSame(['Bash', 'Read', 'Grep'], self::toolNames($request->tools));
    }

    /**
     * Registry order, not declaration order, and deduped: `Bootstrap::tools()`
     * documents its array as a wire order the model has learned, and two agents
     * with the same tools must not receive them in two orders.
     */
    public function testResolvedRosterKeepsRegistryOrderAndDeduplicates(): void
    {
        $request = $this->captureSubAgentRequest(
            ['Grep', 'Bash', 'Bash(git *)', 'Read'],
            $this->fakeRegistry('Bash', 'Read', 'Edit', 'Grep'),
        );

        $this->assertSame(['Bash', 'Read', 'Grep'], self::toolNames($request->tools));
    }

    /**
     * An `fnmatch()` name pattern is admitted, because the dialect is
     * PermissionRule's and `mcp__git__*` has to mean there what it means in
     * `disabledTools`.
     */
    public function testAWildcardGrantResolvesEveryMatchingTool(): void
    {
        $request = $this->captureSubAgentRequest(
            ['mcp__git__*'],
            $this->fakeRegistry('Bash', 'mcp__git__status', 'mcp__git__push', 'mcp__jira__issue'),
        );

        $this->assertSame(['mcp__git__status', 'mcp__git__push'], self::toolNames($request->tools));
    }

    /**
     * NO REGISTRY IS NOT AN EMPTY REGISTRY, and this pins the pre-existing
     * behaviour deliberately kept reachable: a caller that supplies none —
     * every test double in this file — gets `tools: null` exactly as before,
     * rather than a refusal it cannot act on. `Bootstrap` supplies BOTH sets
     * now (E639), so what still reaches here with null is a caller that has
     * genuinely no tool set to speak of.
     */
    public function testWithNoRegistryTheRequestKeepsItsPreExistingNullTools(): void
    {
        $request = $this->captureSubAgentRequest(['Read', 'Grep'], null);

        $this->assertNull($request->tools);
    }

    /**
     * An agent that declares nothing says nothing, and `tools: []` on the wire
     * is a real (empty) tool block to an OpenAI-shaped provider rather than
     * absence. Same `?:` reading Runtime already applies to App::$tools.
     */
    public function testAnAgentThatDeclaresNoToolsStillSendsNull(): void
    {
        $request = $this->captureSubAgentRequest([], $this->fakeRegistry('Bash', 'Read'));

        $this->assertNull($request->tools);
    }

    /**
     * FAILS LOUD, NEVER OPEN. Dropping an unresolvable name is this very bug
     * wearing a different hat: a typo'd `Reed` would hand the sub-agent a
     * smaller roster than its prompt describes, silently.
     */
    public function testAnUnresolvableGrantIsRefusedRatherThanDropped(): void
    {
        $refusal = $this->subAgentGrantRefusal(['Read', 'Reed'], $this->fakeRegistry('Bash', 'Read'));

        $this->assertStringContainsString('"Reed"', $refusal, 'an unresolvable grant must not be silently dropped');
        $this->assertStringContainsString('match no tool this session offers', $refusal);
        $this->assertStringNotContainsString('"Read"', $refusal);
    }

    /**
     * An EMPTY registry is a statement — a registry exists and offers nothing —
     * so any declaration at all is unresolvable. The distinction from `null` is
     * the whole reason the parameter is nullable.
     */
    public function testAnEmptyRegistryRefusesEveryDeclaration(): void
    {
        $refusal = $this->subAgentGrantRefusal(['Read'], []);

        $this->assertStringContainsString('the registry is empty', $refusal);
    }

    /**
     * A guard must go red on what it cannot parse, not silently skip it.
     */
    public function testAMalformedDeclarationIsRefused(): void
    {
        $refusal = $this->subAgentGrantRefusal(['Bash(git *'], $this->fakeRegistry('Bash'));

        $this->assertStringContainsString('unterminated', $refusal);
    }

    /** @see testAMalformedDeclarationIsRefused — the same rule, one type down. */
    public function testANonStringDeclarationIsRefused(): void
    {
        $refusal = $this->subAgentGrantRefusal([42], $this->fakeRegistry('Bash'));

        $this->assertStringContainsString('declares a tools entry of type int', $refusal);
    }

    /** A registry holding something that is not a Tool is refused, not skipped. */
    public function testANonToolRegistryEntryIsRefused(): void
    {
        $refusal = $this->subAgentGrantRefusal(['Read'], ['Read']);

        $this->assertStringContainsString('is a string, not a', $refusal);
    }

    // -------------------------------------------------------------------------
    // Per-call enforcement of the grant's ARGUMENT half.
    //
    // ON THE TASK PATH (CC1C-c2a). Every per-call test from here down runs the
    // sub-agent through TaskTool's engine loop. The verdict is
    // AgentManager::grantRefusalFor(), which SubAgentGrantHook asks ahead of
    // the session gate; the history below names the manager's retired
    // sequential runner (evaluateToolCalls(), refuseCallOutsideGrant()), whose
    // checks that verdict carries. A refused call comes back to the model as
    // that call's error result and the run goes on past it — the deliberate
    // difference the CC1C-c1 inventory records — so each test asserts the
    // refusal text the model received, that the refused tool never ran, and,
    // where it pinned one, the row's settled status.
    // -------------------------------------------------------------------------

    /**
     * The `Bash(git *)` decision, in the direction that makes it worth having:
     * the roster hands the sub-agent the WHOLE `Bash` tool, and the call is
     * still refused because the agent only ever asked for git.
     */
    public function testACallOutsideTheGrantsArgumentHalfIsRefused(): void
    {
        $manager = $this->emittingCallsManager('reviewer', ['Read', 'Grep', 'Bash(git *)'], registry: ['Bash', 'Read', 'Edit', 'Grep']);

        [$subAgent, $turns] = $this->delegateEmittingCalls(
            $manager,
            'reviewer',
            [new WireToolCall('c1', 'Bash', ['command' => 'rm -rf /'])],
            sessionMode: PermissionMode::BypassPermissions,
        );

        // NOT "the gate would have allowed this". MEASURED on PHP 8.3.6:
        // PermissionGate(BypassPermissions) answers Deny for
        // Bash(command: 'rm -rf /'), so what this proves is ORDER — the grant
        // is settled first, and its message is the one that survives. An
        // earlier version of this line said BypassPermissions "settles the
        // GATE, never the grant", which reads as a claim the gate would have
        // let the call through; it would not.
        $this->assertCount(1, $turns);
        $this->assertStringContainsString('is outside the tool grant', $turns[0], 'the grant is checked before the gate, so its refusal is the one reported');
        $this->assertStringContainsString('Bash(git *)', $turns[0]);
        $this->assertSame([], $this->recordingTool('Bash')->calls, 'the refused call never ran');
        $this->assertSame(SubAgent::STATUS_COMPLETE, $subAgent->status, 'a refused call is not a failed run on the Task path');
    }

    /** The same grant admits the call it was written for. */
    public function testACallInsideTheGrantsArgumentHalfIsAllowedThrough(): void
    {
        $manager = $this->emittingCallsManager('reviewer', ['Read', 'Grep', 'Bash(git *)'], registry: ['Bash', 'Read', 'Edit', 'Grep']);

        [$subAgent, $turns] = $this->delegateEmittingCalls(
            $manager,
            'reviewer',
            [new WireToolCall('c1', 'Bash', ['command' => 'git status'])],
            sessionMode: PermissionMode::BypassPermissions,
        );

        $this->assertSame(['Bash ok'], $turns);
        $this->assertSame([['command' => 'git status']], $this->recordingTool('Bash')->calls);
        $this->assertSame(SubAgent::STATUS_COMPLETE, $subAgent->status);
        $this->assertNull($subAgent->error);
    }

    /**
     * `Allow` is an INTERSECTION over `[;&|\r\n]+` segments, so a chained
     * escape cannot ride in on a first segment that matches.
     */
    public function testAChainedEscapeCannotRideInOnAMatchingFirstSegment(): void
    {
        $manager = $this->emittingCallsManager('reviewer', ['Bash(git *)'], registry: ['Bash', 'Read', 'Edit', 'Grep']);

        [, $turns] = $this->delegateEmittingCalls(
            $manager,
            'reviewer',
            [new WireToolCall('c1', 'Bash', ['command' => 'git log && rm -rf /'])],
            sessionMode: PermissionMode::BypassPermissions,
        );

        $this->assertCount(1, $turns);
        $this->assertStringContainsString('is outside the tool grant', $turns[0]);
        $this->assertSame([], $this->recordingTool('Bash')->calls);
    }

    /**
     * A tool the agent never named at all is refused on the NAME half, and this
     * one runs with NO gate attached — the precondition evaluateToolCalls() used
     * to return early on. An agent's declaration is its own statement about
     * itself; it does not become unenforceable because the caller owns no UI.
     *
     * WHAT "NO GATE" MEANS ON THE TASK PATH. `createSubAgent()` always
     * attaches a gate to the row, but TaskTool consults it only when its mode
     * is STRICTER than the session's, and here the engine carries no session
     * gate and the agent declares no mode — so no gate judges the call, and
     * the grant hook is all that stands in front of the tool. With no registry
     * the run inherits the engine's whole set, so the undeclared Edit is on
     * its roster and only the grant can refuse it.
     *
     * THE PREMISE IS PROVED BY BEHAVIOR, NOT READ OFF THE ROW. The second call
     * is a GRANTED `Bash` that writes: any gate judging it would put it to the
     * prompt, and with no approver that prompt refuses it. It runs, so no gate
     * judged the run's calls — which reading the row's gate mode cannot show,
     * because the row always carries one.
     */
    public function testAToolTheAgentNeverDeclaredIsRefusedEvenWithNoGate(): void
    {
        $manager = $this->emittingCallsManager('reviewer', ['Read', 'Bash']);

        [$subAgent, $turns] = $this->delegateEmittingCalls(
            $manager,
            'reviewer',
            [
                new WireToolCall('c1', 'Edit', ['file_path' => '/etc/passwd']),
                new WireToolCall('c2', 'Bash', ['command' => 'touch /tmp/gateless-probe']),
            ],
            engineTools: ['Read'],
        );

        $this->assertCount(2, $turns);
        $this->assertStringContainsString('Tool call "Edit"', $turns[0]);
        $this->assertStringContainsString('is outside the tool grant', $turns[0], 'the grant must be enforced with no gate attached');
        $this->assertSame([], $this->recordingTool('Edit')->calls);
        $this->assertSame(
            'Bash ok',
            $turns[1],
            'the premise of this test: a granted write ran unprompted with no approver, so no gate judged the run\'s calls',
        );
        $this->assertSame([['command' => 'touch /tmp/gateless-probe']], $this->recordingTool('Bash')->calls);
        $this->assertSame(SubAgent::STATUS_COMPLETE, $subAgent->status);
    }

    /**
     * An agent that declares nothing is NOT policed. Reading silence as "forbid
     * everything" would refuse every call for every Agent built without the
     * field — which is most of them, this file's own helper included.
     */
    public function testAnAgentWithNoDeclarationIsNotPolicedByTheGrant(): void
    {
        $manager = $this->emittingCallsManager('reviewer', [], registry: ['Bash', 'Read', 'Edit', 'Grep']);

        [$subAgent, $turns] = $this->delegateEmittingCalls(
            $manager,
            'reviewer',
            [new WireToolCall('c1', 'Edit', ['file_path' => '/etc/passwd'])],
            sessionMode: PermissionMode::BypassPermissions,
        );

        $this->assertSame(['Edit ok'], $turns);
        $this->assertSame([['file_path' => '/etc/passwd']], $this->recordingTool('Edit')->calls);
        $this->assertSame(SubAgent::STATUS_COMPLETE, $subAgent->status);
    }


    // -------------------------------------------------------------------------
    // The DENYLIST half. Agent::$disallowedTools reaches this class from
    // Agent::fromPreset() and from opencode's `permission:` block, and until
    // resolveGrantedTools() existed it was consumed by NOTHING in src/. A
    // resolver that read only $tools would hand a preset the very tool its own
    // denylist refuses -- a widening committed inside the fix for a lie.
    // -------------------------------------------------------------------------

    /**
     * @param list<string> $grant
     * @param list<string> $deny
     * @param ?list<\SugarCraft\Code\Tools\Tool> $registry
     * @param list<\SugarCraft\Code\Tools\Tool> $engineTools the session tool
     *        set the run's engine offers; empty (the default) offers none
     */
    private function captureSubAgentRequestWithDenylist(array $grant, array $deny, ?array $registry, array $engineTools = []): CompleteRequest
    {
        $manager = new AgentManager(
            provider: $this->provider,
            skillRegistry: $this->skillRegistry,
            toolRegistry: $registry,
        );
        $manager->register(new Agent(
            name: 'denied',
            description: 'denied description',
            prompt: 'Test prompt',
            model: 'claude-sonnet-4-6',
            provider: 'anthropic',
            tools: $grant,
            skillNames: [],
            hooks: [],
            isActive: true,
            disallowedTools: $deny,
        ));

        $provider = new ScriptedProvider([new CompleteResponse(content: 'done')]);
        [$result] = $this->delegateOnTask($manager, 'denied', $provider, engineTools: $engineTools);

        $this->assertFalse($result->isError(), $result->content());
        $this->assertCount(1, $provider->requests, 'the provider was never called');

        return $provider->requests[0];
    }

    // -------------------------------------------------------------------------
    // Roadmap 4.6-2: `Team` rides along with every declared grant.
    // -------------------------------------------------------------------------

    public function testEveryDeclaredGrantCarriesTeamWhenTheSessionOffersIt(): void
    {
        // The built-in reviewer's own declaration, which names no Team.
        $grant = \SugarCraft\Code\Agents\AgentDefinition::reviewer()->defaultTools;
        self::assertNotContains('Team', $grant);

        $request = $this->captureSubAgentRequest($grant, $this->fakeRegistry('Bash', 'Read', 'Team', 'Grep', 'Edit', 'Skill'));

        $this->assertSame(['Bash', 'Read', 'Team', 'Grep', 'Skill'], self::toolNames($request->tools), 'registry order, Team included, Edit still narrowed out');
    }

    public function testASessionWithoutTeamAddsNothingAndRefusesNothing(): void
    {
        $request = $this->captureSubAgentRequest(['Read'], $this->fakeRegistry('Read', 'Bash'));

        $this->assertSame(['Read'], self::toolNames($request->tools));
    }

    public function testADenylistKeepsAnAgentOffTheBoard(): void
    {
        $request = $this->captureSubAgentRequestWithDenylist(['Read'], ['Team'], $this->fakeRegistry('Read', 'Team'));

        $this->assertSame(['Read'], self::toolNames($request->tools));
    }

    public function testATeamCallIsAdmittedPerCallUnlessDenied(): void
    {
        $manager = new AgentManager($this->provider, $this->skillRegistry);
        $manager->register(\SugarCraft\Code\Agents\Agent::fromDefinition(\SugarCraft\Code\Agents\AgentDefinition::architect(), 'anthropic', 'm'));
        $architect = $manager->createSubAgent('architect', 'design it');

        $this->assertNull($manager->grantRefusalFor(new \SugarCraft\Code\ToolCall('Team', ['action' => 'list']), $architect));
        $this->assertNotNull($manager->grantRefusalFor(new \SugarCraft\Code\ToolCall('Bash', ['command' => 'ls']), $architect), 'every other name is still held to the grant');

        $denying = new AgentManager($this->provider, $this->skillRegistry);
        $denying->register(new Agent(
            name: 'solo',
            description: 'solo',
            prompt: 'p',
            model: 'm',
            provider: 'anthropic',
            tools: ['Read'],
            skillNames: [],
            hooks: [],
            isActive: true,
            disallowedTools: ['Team'],
        ));
        $this->assertNotNull($denying->grantRefusalFor(new \SugarCraft\Code\ToolCall('Team', ['action' => 'list']), $denying->createSubAgent('solo', 'x')));
    }

    public function testTheDenylistShrinksTheResolvedRoster(): void
    {
        $request = $this->captureSubAgentRequestWithDenylist(
            ['Read', 'Grep', 'Bash'],
            ['Bash'],
            $this->fakeRegistry('Bash', 'Read', 'Edit', 'Grep'),
        );

        $this->assertSame(['Read', 'Grep'], self::toolNames($request->tools));
    }

    /**
     * AN ARGUMENT-SCOPED DENIAL DOES NOT STRIP THE TOOL FROM THE ROSTER, and
     * this is the shipped-then-caught bug rather than a nicety: a roster entry
     * is a DECLARATION, and `PermissionGate::refuses()` already states that an
     * argument-sensitive rule never settles one in either direction. Applying
     * it here made `disallowedTools: ['Bash(git push*)']` remove the whole
     * `Bash` tool, so a reviewer granted `Bash(git *)` could no longer run `git
     * status` — the denial defeated the grant it was written to narrow.
     */
    public function testAnArgumentScopedDenialDoesNotStripTheToolFromTheRoster(): void
    {
        $request = $this->captureSubAgentRequestWithDenylist(
            ['Bash(git *)', 'Read'],
            ['Bash(git push*)'],
            $this->fakeRegistry('Bash', 'Read', 'Edit'),
        );

        $this->assertSame(
            ['Bash', 'Read'],
            self::toolNames($request->tools),
            'the tool must survive; the denial bites at call time',
        );
    }

    /**
     * The other polarity of the same rule, so the test above is not simply
     * "denials do nothing": a NAME-ONLY denial of the same tool still strips it.
     */
    public function testANameOnlyDenialOfTheSameToolStillStripsIt(): void
    {
        $request = $this->captureSubAgentRequestWithDenylist(
            ['Bash(git *)', 'Read'],
            ['Bash'],
            $this->fakeRegistry('Bash', 'Read', 'Edit'),
        );

        $this->assertSame(['Read'], self::toolNames($request->tools));
    }

    /** The dialect is the same one, so `mcp__git__*` means here what it means in disabledTools. */
    public function testTheDenylistGlobsToolNames(): void
    {
        $request = $this->captureSubAgentRequestWithDenylist(
            ['mcp__*'],
            ['mcp__git__*'],
            $this->fakeRegistry('mcp__git__push', 'mcp__git__status', 'mcp__jira__issue'),
        );

        $this->assertSame(['mcp__jira__issue'], self::toolNames($request->tools));
    }

    /**
     * A declaration whose every match is denied RESOLVED — policy then removed
     * it — so it must not be reported as matching no tool. That message would
     * send the reader hunting for a typo in a preset that is merely
     * self-contradictory.
     */
    public function testAFullyDeniedGrantIsNotReportedAsUnresolvable(): void
    {
        $request = $this->captureSubAgentRequestWithDenylist(
            ['Bash'],
            ['Bash'],
            $this->fakeRegistry('Bash', 'Read'),
        );

        $this->assertNull($request->tools, 'an emptied roster is absence, not an empty tool block');
    }

    /**
     * AN EMPTIED GRANT IS NOT "NO NARROWING". The test above offers no engine
     * tools, so it cannot tell a run handed nothing from a run handed the
     * engine's whole set: both reach the wire as null. With an engine that
     * offers `Bash`, a grant its own denylist empties used to come back from
     * {@see AgentManager::grantedToolsFor()} as null, which TaskTool reads as
     * "inherit everything" — the model was shown the very tool the preset
     * denies.
     */
    public function testAFullyDeniedGrantAdvertisesNoneOfTheEnginesTools(): void
    {
        $request = $this->captureSubAgentRequestWithDenylist(
            ['Bash'],
            ['Bash'],
            $this->fakeRegistry('Bash', 'Read'),
            engineTools: $this->fakeRegistry('Bash', 'Read'),
        );

        $this->assertNull($request->tools, 'the denied grant hands the run no tools, so nothing of the engine\'s set is advertised');
    }

    /** The same leak through the server allowlist: `mcp__*` narrowed to no server. */
    public function testAnMcpGrantNarrowedToNoServerAdvertisesNoneOfTheEnginesTools(): void
    {
        $request = $this->captureSubAgentRequest(
            ['mcp__*'],
            [$this->mcpBridge('alpha')],
            mcpServers: ['zeta'],
            engineTools: [...$this->fakeRegistry('Bash', 'Read'), $this->mcpBridge('alpha')],
        );

        $this->assertNull($request->tools, 'an allowlist that matches no server hands the run no tools, not the engine\'s set');
    }

    /** Both lists are validated identically, and the message says which one was read. */
    public function testAMalformedDenylistEntryIsRefusedAndNamesTheField(): void
    {
        $refusal = $this->subAgentGrantRefusal(['Read'], $this->fakeRegistry('Bash', 'Read'), deny: ['Bash(rm']);

        $this->assertStringContainsString('disallowedTools pattern "Bash(rm"', $refusal);
        $this->assertStringContainsString('unterminated', $refusal);
    }

    /**
     * At CALL time the denylist is checked with `PermissionAction::Deny`, whose
     * shell arm is a UNION over the chain's segments — so a denial fires when
     * ANY segment matches, where the grant requires EVERY segment to.
     */
    public function testTheDenylistRefusesACallTheGrantWouldHaveAdmitted(): void
    {
        $manager = $this->emittingCallsManager('careful', ['Bash(git *)'], ['Bash(git push*)'], registry: ['Bash', 'Read']);

        [$subAgent, $turns] = $this->delegateEmittingCalls(
            $manager,
            'careful',
            [new WireToolCall('c1', 'Bash', ['command' => 'git push --force'])],
            sessionMode: PermissionMode::BypassPermissions,
        );

        $this->assertCount(1, $turns);
        $this->assertStringContainsString('is refused by the denylist', $turns[0], 'the grant admits `git push --force`; only the denylist stops it');
        $this->assertSame([], $this->recordingTool('Bash')->calls);
        $this->assertSame(SubAgent::STATUS_COMPLETE, $subAgent->status, 'a refused call is not a failed run on the Task path');
    }

    /**
     * THE UNION READING, which the test above cannot see: `git push --force` is
     * ONE segment, so `Deny` and `Allow` agree on it and mutating the action
     * from Deny to Allow SURVIVED. The divergence needs a chain in which only
     * SOME segment is denied — `Deny` fires (union, the safe direction for a
     * refusal), `Allow` would not (intersection), and the grant `Bash(git *)`
     * admits every segment, so under the wrong action the call sneaks past both
     * checks.
     */
    public function testTheDenylistFiresOnAChainWhereOnlyOneSegmentIsDenied(): void
    {
        $manager = $this->emittingCallsManager('chained', ['Bash(git *)'], ['Bash(git push*)'], registry: ['Bash', 'Read']);

        // The premise, asserted rather than assumed: the GRANT admits this
        // command outright, so anything that stops it came from the denylist.
        $this->assertTrue(
            (new \SugarCraft\Code\Permissions\PermissionRule('Bash(git *)', \SugarCraft\Code\Permissions\PermissionAction::Allow))
                ->matches(new ToolCall('Bash', ['command' => 'git status && git push --force'])),
        );

        [, $turns] = $this->delegateEmittingCalls(
            $manager,
            'chained',
            [new WireToolCall('c1', 'Bash', ['command' => 'git status && git push --force'])],
            sessionMode: PermissionMode::BypassPermissions,
        );

        $this->assertCount(1, $turns);
        $this->assertStringContainsString('is refused by the denylist', $turns[0], 'a denied segment must not ride in on its neighbours');
        $this->assertSame([], $this->recordingTool('Bash')->calls);
    }

    /**
     * A denylist with NO grant is still enforced. Silence about `tools` is
     * silence; naming a tool you refuse is a statement.
     */
    public function testADenylistWithNoGrantIsStillEnforced(): void
    {
        $manager = $this->emittingCallsManager('noedit', [], ['Edit']);

        [, $turns] = $this->delegateEmittingCalls(
            $manager,
            'noedit',
            [new WireToolCall('c1', 'Edit', ['file_path' => '/etc/passwd'])],
            sessionMode: PermissionMode::BypassPermissions,
        );

        $this->assertCount(1, $turns);
        $this->assertStringContainsString('is refused by the denylist', $turns[0]);
        $this->assertSame([], $this->recordingTool('Edit')->calls);
    }

    /**
     * And the other polarity, so the test above is not simply "everything is
     * refused": a call the denylist does not name passes through an agent that
     * declares no grant.
     */
    public function testADenylistDoesNotRefuseACallItDoesNotName(): void
    {
        $manager = $this->emittingCallsManager('noedit2', [], ['Edit']);

        [$subAgent, $turns] = $this->delegateEmittingCalls(
            $manager,
            'noedit2',
            [new WireToolCall('c1', 'Read', ['file_path' => '/etc/hosts'])],
            sessionMode: PermissionMode::BypassPermissions,
        );

        $this->assertSame(['Read ok'], $turns);
        $this->assertSame([['file_path' => '/etc/hosts']], $this->recordingTool('Read')->calls);
        $this->assertSame(SubAgent::STATUS_COMPLETE, $subAgent->status);
    }

    // -------------------------------------------------------------------------
    // WHAT THE FIRST ROUND OF THIS ITEM DID NOT PIN. Every per-call test above
    // emits exactly ONE ToolCall, and a model returning two calls in one
    // response is the ordinary case. Measured at the round-60 review: adding
    // `break;` to the end of evaluateToolCalls()'s loop -- so only call #1 is
    // ever grant-checked, denylist-checked or gate-checked -- SURVIVED the
    // whole candy-code suite. So did turning the gate-null `continue` into a
    // `return`. Both were pinned below against executeSubAgent(). On the Task
    // path (CC1C-c2a) the `break` shape is pinned as before: every call is
    // its own SubAgentGrantHook verdict. evaluateToolCalls()'s gate-null
    // `continue` is NOT re-pinned here: it lives only on the dormant
    // executeSubAgent() path, which no test in this file drives and CC1C-c3
    // deletes. Its Task-path counterpart is the hook's `modeGate === null`
    // arm, which must still grant-check the call; the gateless test below
    // proves by behavior that no gate judged the run.
    // -------------------------------------------------------------------------

    /**
     * EVERY call in a response is evaluated, not just the first. The refusal
     * must name the SECOND call's tool, which is the half a `break` after the
     * first iteration silently loses.
     */
    public function testASecondToolCallInTheSameResponseIsAlsoGrantChecked(): void
    {
        $manager = $this->emittingCallsManager('multi', ['Read']);

        [$subAgent, $turns] = $this->delegateEmittingCalls(
            $manager,
            'multi',
            [
                new WireToolCall('c1', 'Read', ['file_path' => '/etc/hosts']),
                new WireToolCall('c2', 'Edit', ['file_path' => '/etc/passwd']),
            ],
            sessionMode: PermissionMode::BypassPermissions,
        );

        $this->assertCount(2, $turns, 'the loop must not stop after the first call');
        $this->assertSame('Read ok', $turns[0]);
        $this->assertStringContainsString('Tool call "Edit"', $turns[1]);
        $this->assertStringContainsString('is outside the tool grant', $turns[1]);
        $this->assertSame([], $this->recordingTool('Edit')->calls);
        $this->assertSame(SubAgent::STATUS_COMPLETE, $subAgent->status, 'a refused call is not a failed run on the Task path');
    }

    /**
     * The same loop, with NO gate judging the run — the state in which a
     * gate-null shortcut could skip the grant for every call after the first.
     * Call #1 is a GRANTED `Bash` that writes: any gate judging it would put it
     * to the prompt, and with no approver that prompt refuses it. It runs, so
     * no gate judged the run (the premise, proved by behavior), and call #2
     * must still be grant-checked.
     */
    public function testASecondToolCallIsGrantCheckedWithNoGateAttached(): void
    {
        $manager = $this->emittingCallsManager('multi', ['Read', 'Bash']);

        [$subAgent, $turns] = $this->delegateEmittingCalls(
            $manager,
            'multi',
            [
                new WireToolCall('c1', 'Bash', ['command' => 'touch /tmp/gateless-probe']),
                new WireToolCall('c2', 'Edit', ['file_path' => '/etc/passwd']),
            ],
        );

        $this->assertCount(2, $turns, 'the gateless run must evaluate the second call, not stop after the first');
        $this->assertSame(
            'Bash ok',
            $turns[0],
            'the premise of this test: a granted write ran unprompted with no approver, so no gate judged the run\'s calls',
        );
        $this->assertSame([['command' => 'touch /tmp/gateless-probe']], $this->recordingTool('Bash')->calls);
        $this->assertStringContainsString('Tool call "Edit"', $turns[1]);
        $this->assertStringContainsString('is outside the tool grant', $turns[1]);
        $this->assertSame([], $this->recordingTool('Edit')->calls);
        $this->assertSame(SubAgent::STATUS_COMPLETE, $subAgent->status);
    }

    /**
     * The DENYLIST half of the same loop: call #1 is clean, call #2 is denied.
     * A `break` loses this one too, and the denylist is the half that fails
     * DANGEROUS rather than merely narrow.
     */
    public function testASecondToolCallIsAlsoDenylistChecked(): void
    {
        $manager = $this->emittingCallsManager('multi', ['Bash(git *)'], ['Bash(git push*)']);

        [, $turns] = $this->delegateEmittingCalls(
            $manager,
            'multi',
            [
                new WireToolCall('c1', 'Bash', ['command' => 'git status']),
                new WireToolCall('c2', 'Bash', ['command' => 'git push --force']),
            ],
            sessionMode: PermissionMode::BypassPermissions,
        );

        $this->assertCount(2, $turns);
        $this->assertSame('Bash ok', $turns[0]);
        $this->assertStringContainsString('is refused by the denylist', $turns[1]);
        $this->assertSame([['command' => 'git status']], $this->recordingTool('Bash')->calls);
    }

    /**
     * THE ORDER IS THE POINT, AND THE APPROVER IS THE WITNESS.
     *
     * evaluateToolCalls() checks the grant FIRST AND UNCONDITIONALLY, and its
     * comment gives the reason: settling the agent's own question here keeps a
     * call the agent never asked for from ever reaching a blocking approval
     * prompt. Nothing pinned that. Measured at the round-60 review, skipping
     * the grant check whenever the gate answers `Ask` — an out-of-grant call
     * routed to the human approver, precisely the outcome the comment says is
     * prevented — SURVIVED.
     *
     * `PermissionMode::Default` answers `Ask` for `Edit`, and this approver
     * would say YES, so under that mutation the call is ALLOWED and this test
     * reds in two places at once: no refusal, and the approver was consulted.
     */
    public function testAnOutOfGrantCallNeverReachesTheApprover(): void
    {
        $asked = [];
        $manager = $this->emittingCallsManager('multi', ['Read']);

        // The premise, asserted rather than assumed: this gate really does
        // answer Ask for this call, so a grant check that ran second would
        // genuinely hand it to the approver.
        $this->assertSame(
            PermissionDecision::Ask,
            (new PermissionGate(PermissionMode::Default))
                ->evaluate(new ToolCall('Edit', ['file_path' => '/etc/passwd'])),
        );

        [, $turns] = $this->delegateEmittingCalls(
            $manager,
            'multi',
            [new WireToolCall('c1', 'Edit', ['file_path' => '/etc/passwd'])],
            sessionMode: PermissionMode::Default,
            approver: static function (WireToolCall $call) use (&$asked): bool {
                $asked[] = $call->name();

                return true;
            },
        );

        $this->assertCount(1, $turns);
        $this->assertStringContainsString('is outside the tool grant', $turns[0], 'the grant must settle this before the gate is consulted');
        $this->assertSame([], $asked, 'a call the agent never asked for must not reach the approver');
        $this->assertSame([], $this->recordingTool('Edit')->calls);
    }

    /**
     * The other polarity, so the test above is not simply "the approver is
     * never called": a call INSIDE the grant does reach it, and its yes is what
     * lets the call through.
     */
    public function testACallInsideTheGrantDoesReachTheApprover(): void
    {
        $asked = [];
        $manager = $this->emittingCallsManager('multi', ['Edit']);

        [$subAgent] = $this->delegateEmittingCalls(
            $manager,
            'multi',
            [new WireToolCall('c1', 'Edit', ['file_path' => '/etc/passwd'])],
            sessionMode: PermissionMode::Default,
            approver: static function (WireToolCall $call) use (&$asked): bool {
                $asked[] = $call->name();

                return true;
            },
        );

        $this->assertSame(['Edit'], $asked);
        $this->assertSame([['file_path' => '/etc/passwd']], $this->recordingTool('Edit')->calls, 'the approver\'s yes is what let the call through');
        $this->assertSame(SubAgent::STATUS_COMPLETE, $subAgent->status);
    }

    /**
     * THE APPROVED-THEN-UNCHECKED HOLE, found by mutating this fix rather than
     * by reading it.
     *
     * The three tests above pin that call #2 is evaluated, but each reaches the
     * end of the loop body by a path that `continue`s out early — a gateless
     * agent and an `Allow` decision both skip the approver. So a `break` placed
     * at the very END of the loop body SURVIVED all of them: it only cuts the
     * loop when the gate answered `Ask` AND the approver said yes, which is the
     * one path nothing exercised twice. That is a real escape, not an
     * equivalent mutant — a human who approves the first of two calls would
     * silently un-police the second.
     */
    public function testASecondCallIsStillCheckedAfterTheFirstWasApproved(): void
    {
        $asked = [];
        $manager = $this->emittingCallsManager('multi', ['Bash(git *)']);

        $this->assertSame(
            PermissionDecision::Ask,
            (new PermissionGate(PermissionMode::Default))
                ->evaluate(new ToolCall('Bash', ['command' => 'git push'])),
            'the premise: call #1 must reach the approver, not be auto-allowed',
        );

        [, $turns] = $this->delegateEmittingCalls(
            $manager,
            'multi',
            [
                // `Bash` and not `Read`: under Default the gate auto-ALLOWS a
                // read-only tool, and an Allow skips the approver — which is
                // the very path this test has to avoid, since the hole is
                // specific to a call that was ASKED about and approved.
                new WireToolCall('c1', 'Bash', ['command' => 'git push']),
                new WireToolCall('c2', 'Edit', ['file_path' => '/etc/passwd']),
            ],
            sessionMode: PermissionMode::Default,
            approver: static function (WireToolCall $call) use (&$asked): bool {
                $asked[] = $call->name();

                return true;
            },
        );

        $this->assertSame(['Bash'], $asked, 'the first call is in the grant and is approved');
        $this->assertCount(2, $turns, 'approving call #1 must not un-police call #2');
        $this->assertSame('Bash ok', $turns[0]);
        $this->assertStringContainsString('Tool call "Edit"', $turns[1]);
        $this->assertStringContainsString('is outside the tool grant', $turns[1]);
        $this->assertSame([], $this->recordingTool('Edit')->calls);
    }

    /**
     * THE PRODUCTION SHAPE OF THE MALFORMED-DENYLIST GUARD, which the roster
     * test beside it cannot reach.
     *
     * refuseCallOutsideGrant() parses BOTH lists for their side effect before
     * matching anything, so a malformed entry throws rather than degrading into
     * a rule that happens never to fire — and it degrades quietly: measured on
     * PHP 8.3.6, `new PermissionRule('Bash(rm -rf *', Deny)` constructs fine,
     * yields argumentPattern() === null and a tool-NAME pattern of
     * `Bash(rm -rf *`, which matches no tool that exists. The denial silently
     * never fires.
     *
     * WHY THE EXISTING ROSTER TEST DOES NOT COVER THIS. resolveGrantedTools()
     * validates the denylist only AFTER its `$patterns === []` early return, so
     * an agent with no grant never reaches that validation — and it is skipped
     * entirely when the manager holds no registry, which is every production
     * caller today. On that path these two calls are the ONLY validation that
     * runs at all. Measured at the round-60 review: deleting both of them from
     * refuseCallOutsideGrant() SURVIVED the whole candy-code suite.
     */
    public function testAMalformedDenylistIsRefusedAtCallTimeWithNoRegistryAndNoGrant(): void
    {
        $manager = $this->emittingCallsManager('multi', [], ['Bash(rm -rf *']);

        // The premise, measured rather than assumed: this pattern really does
        // degrade into a name pattern that matches nothing, so nothing else in
        // the pipeline would stop the call.
        $degraded = new \SugarCraft\Code\Permissions\PermissionRule(
            'Bash(rm -rf *',
            \SugarCraft\Code\Permissions\PermissionAction::Deny,
        );
        $this->assertNull($degraded->argumentPattern());
        $this->assertFalse($degraded->matches(new ToolCall('Bash', ['command' => 'rm -rf /'])));

        [, $turns] = $this->delegateEmittingCalls(
            $manager,
            'multi',
            [new WireToolCall('c1', 'Bash', ['command' => 'rm -rf /'])],
            sessionMode: PermissionMode::BypassPermissions,
        );

        $this->assertCount(1, $turns);
        $this->assertStringContainsString('disallowedTools pattern "Bash(rm -rf *"', $turns[0], 'a malformed denial must refuse, not silently never match');
        $this->assertStringContainsString('unterminated', $turns[0]);
        $this->assertSame([], $this->recordingTool('Bash')->calls);
    }

    /**
     * The GRANT list's half of the same side-effect parse, on the same
     * registry-less path — so deleting either namePatterns() call from
     * refuseCallOutsideGrant() reds, not just the denylist one.
     */
    public function testAMalformedGrantIsRefusedAtCallTimeWithNoRegistry(): void
    {
        $manager = $this->emittingCallsManager('multi', ['Read(x']);

        [, $turns] = $this->delegateEmittingCalls(
            $manager,
            'multi',
            [new WireToolCall('c1', 'Read', ['file_path' => '/etc/hosts'])],
            sessionMode: PermissionMode::BypassPermissions,
        );

        $this->assertCount(1, $turns);
        $this->assertStringContainsString('tools pattern "Read(x"', $turns[0]);
        $this->assertStringContainsString('unterminated', $turns[0]);
        $this->assertSame([], $this->recordingTool('Read')->calls);
    }

    /**
     * THE TRAP IN THE WIRING, NOW DISARMED THE WAY E639 DECIDED (option 1):
     * a policy-narrowed absence resolves through the UNFILTERED ceiling — the
     * grant survives, narrowed — while a never-existing name still refuses.
     *
     * WHAT THIS TEST USED TO PIN, recorded because the tripwire's failure
     * message ordered exactly this rewrite rather than a deletion. Before the
     * universe existed, `resolveGrantedTools()` refused EVERY declaration that
     * matched no registry entry, which was sound only against the unfiltered
     * ceiling — and the obvious thing to pass, `Bootstrap::tools()`, returns
     * the FILTERED set. Measured on PHP 8.3.6 at round 60: `disabledTools:
     * ["Bash"]` threw for five of the six built-in presets, and a
     * policy-narrowed absence produced the byte-identical refusal of a typo.
     * That indistinguishability is now the NULL-UNIVERSE polarity, asserted
     * third below, because a caller that supplies only half the truth still
     * gets the refusal that half can support.
     *
     * The semantics asserted below, not the preset count: a figure over the
     * table would rot the moment a preset changes.
     */
    public function testAPolicyNarrowedRegistryIsIndistinguishableFromATypo(): void
    {
        $narrowed = $this->fakeRegistry('Read', 'Grep');
        $ceiling = $this->fakeRegistry('Read', 'Grep', 'Bash');

        // FIRST: with both sets, the disabled tool narrows out and the grant
        // survives — no refusal, no Bash, Read still delivered.
        $request = $this->captureSubAgentRequest(['Read', 'Bash'], $narrowed, $ceiling);
        $this->assertSame(['Read'], self::toolNames($request->tools));

        // SECOND: a name the ceiling never had is still refused, and the
        // message says so rather than sending the reader to the config file.
        $refusal = $this->subAgentGrantRefusal(['Read', 'Reed'], $narrowed, $ceiling);

        $this->assertStringContainsString('"Reed"', $refusal, 'a never-existing tool must refuse even with the ceiling in hand');
        $this->assertStringContainsString('match no tool this session offers', $refusal);
        $this->assertStringContainsString('never existed rather than being disabled by policy', $refusal);

        // THIRD: WITHOUT the ceiling the old indistinguishability stands —
        // a policy narrowing and a typo produce the identical refusal, which
        // is the reason the wiring passes both sets.
        $noCeiling = $this->subAgentGrantRefusal(['Read', 'Bash'], $narrowed);
        $typo = $this->subAgentGrantRefusal(['Read', 'Reed'], $narrowed);

        $this->assertSame(
            str_replace('"Bash"', '<absent>', $noCeiling),
            str_replace('"Reed"', '<absent>', $typo),
            'without a universe, policy-narrowed and typo\'d declarations produce the identical refusal',
        );
    }

    /**
     * The whole ceiling narrowed away is STILL a supported configuration:
     * `disabledTools: ["*"]` is documented as the way to ask for a toolless
     * agent, so every preset's grant must survive as EMPTY (delivered as
     * `tools: null`) rather than crash — the six-presets-throw case of the
     * round-60 measurement, now on the survive side.
     */
    public function testAFullyNarrowedSessionYieldsAToollessGrantNotACrash(): void
    {
        $request = $this->captureSubAgentRequest(
            ['Read', 'Grep', 'Bash'],
            $this->fakeRegistry(),
            $this->fakeRegistry('Read', 'Grep', 'Bash'),
        );

        $this->assertNull($request->tools, 'a grant narrowed to nothing by policy is null, not a crash');
    }

    /**
     * The ceiling is validated like the registry: a universe holding something
     * that is not a {@see Tool} would leave every real tool "unexplained" and
     * convert narrowing into refusal — but refusing on a broken ceiling is the
     * SAFE direction, so it throws rather than silently degrading to the
     * pre-universe behaviour for just that agent.
     */
    public function testANonToolUniverseEntryIsRefused(): void
    {
        $refusal = $this->subAgentGrantRefusal(['Read'], $this->fakeRegistry('Grep'), ['Read']);

        $this->assertStringContainsString('Tool universe entry', $refusal);
    }

    // -------------------------------------------------------------------------
    // Per-agent grants on the LIVE PARALLEL PATH (E644).
    //
    // executeAll() is the path Chat and WorkflowEngine actually take, and it
    // used to forward ONE caller-built CompleteRequest through the pool: the
    // pool copied its `tools` field onto every per-agent request, so each
    // agent ran under whichever declaration the caller built the shared
    // request from — WorkflowEngine builds it from the stage's FIRST task, so
    // agents 2..n were governed by a sibling. These tests observe the requests
    // at the executor seam, the same "the provider actually received it"
    // standard the sequential tests above hold: an assertion that stops at a
    // copied field proves the copy, not the grant.
    // -------------------------------------------------------------------------

    /**
     * An executor that RECORDS the per-agent request and completes.
     *
     * The injected-executor path runs synchronously in the parent, so the
     * captured CompleteRequests (and the Tool objects inside them) survive
     * into assertions. Return type is deliberately untyped: the test needs
     * ->requests, which is not on ExecutorInterface.
     */
    private function recordingPoolExecutor()
    {
        return new class implements \SugarCraft\Code\Agents\ExecutorInterface {
            /** @var array<string, \SugarCraft\Code\Providers\CompleteRequest> */
            public array $requests = [];

            public function execute(SubAgent $agent, CompleteRequest $request): \SugarCraft\Code\Agents\AgentResult
            {
                $this->requests[$agent->id] = $request;

                return new \SugarCraft\Code\Agents\AgentResult(
                    agentId: $agent->id,
                    status: \SugarCraft\Code\Agents\AgentStatus::Completed,
                    output: 'ok',
                );
            }

            public function executeStream(SubAgent $agent, CompleteRequest $request): \Generator
            {
                yield $this->execute($agent, $request);
            }

            public function cancel(string $agentId): void {}

            public function cancelAll(): void {}
        };
    }

    private function agentDeclaring(string $name, array $tools): Agent
    {
        return new Agent(
            name: $name,
            description: "$name description",
            prompt: 'Test prompt',
            model: 'claude-sonnet-4-6',
            provider: 'anthropic',
            tools: $tools,
            skillNames: [],
            hooks: [],
            isActive: true,
        );
    }

    /**
     * THE HEADLINE. Two agents, DISJOINT grants, one shared request carrying
     * agent 1's roster — the exact shape WorkflowEngine hands this method.
     * Agent 2 declaring a tool agent 1 lacks must RECEIVE it (resolved per its
     * own declaration), and agent 1 must not receive agent 2's.
     */
    public function testEachParallelAgentIsGovernedByItsOwnGrant(): void
    {
        $registry = $this->fakeRegistry('Bash', 'Read');
        $recorder = $this->recordingPoolExecutor();
        $manager = new AgentManager(
            provider: $this->provider,
            skillRegistry: $this->skillRegistry,
            workerPool: new \SugarCraft\Code\Agents\AgentWorkerPool(executor: $recorder),
            toolRegistry: $registry,
        );

        $first = new SubAgent(id: 'sub-a', agent: $this->agentDeclaring('reader', ['Read']), task: 'read it');
        $second = new SubAgent(id: 'sub-b', agent: $this->agentDeclaring('sheller', ['Bash']), task: 'run it');

        // Agent 1's RESOLVED roster as the shared field — the exact value
        // WorkflowEngine's first-task request stands in for. Built directly;
        // the pool rebuilds messages, only the tools field is the fixture.
        $sharedRequest = new CompleteRequest(
            model: 'test-model',
            messages: [['role' => 'user', 'content' => 'ignored — the pool rebuilds this']],
            tools: array_values(array_filter(
                $registry,
                static fn(\SugarCraft\Code\Tools\Tool $t): bool => $t->name() === 'Read',
            )),
        );

        iterator_to_array($manager->executeAll([$first, $second], $sharedRequest));

        $this->assertSame(['Read'], self::toolNames($recorder->requests['sub-a']->tools ?? null));
        $this->assertSame(
            ['Bash'],
            self::toolNames($recorder->requests['sub-b']->tools ?? null),
            'agent 2 runs under its OWN declaration, not the shared request\'s',
        );
    }

    /**
     * THE RESTRICTION on the same path: a sibling's grant does not leak INTO
     * an agent that never declared the tool — the assertion the headline test
     * would fail if the implementation resolved agent 1 for everyone, or
     * merged. Silent-skip is also caught here: agent `quiet` declares nothing,
     * and declaring nothing means null tools, not the batch's union and not
     * the first agent's roster.
     */
    public function testAParallelAgentThatDeclaredNothingRunsToollessBesideAGrantedSibling(): void
    {
        $recorder = $this->recordingPoolExecutor();
        $manager = new AgentManager(
            provider: $this->provider,
            skillRegistry: $this->skillRegistry,
            workerPool: new \SugarCraft\Code\Agents\AgentWorkerPool(executor: $recorder),
            toolRegistry: $this->fakeRegistry('Bash', 'Read'),
        );

        $speaker = new SubAgent(id: 'sub-loud', agent: $this->agentDeclaring('reader', ['Read']), task: 'read it');
        $quiet = new SubAgent(id: 'sub-quiet', agent: $this->agentDeclaring('plain', []), task: 'chat');

        iterator_to_array($manager->executeAll([$speaker, $quiet], new CompleteRequest(model: 'test-model', messages: [])));

        $this->assertSame(['Read'], self::toolNames($recorder->requests['sub-loud']->tools ?? null));
        $this->assertNull(
            $recorder->requests['sub-quiet']->tools,
            'no declaration is not the shared roster — nor the sibling\'s',
        );
    }

    /**
     * FAIL BEFORE DISPATCH. A typo'd grant in batch position 2 must throw with
     * NOTHING executed — the pre-fix shape of this path forked the healthy
     * siblings first and threw (or didn't) only afterwards — and the already
     * registered members must settle STOPPED with the reason that actually
     * stopped them, not haunt isWorking() as pending-forever, and not read as
     * if the user cancelled them.
     */
    public function testAnUnresolvableParallelGrantFailsTheBatchBeforeDispatch(): void
    {
        $recorder = $this->recordingPoolExecutor();
        $manager = new AgentManager(
            provider: $this->provider,
            skillRegistry: $this->skillRegistry,
            workerPool: new \SugarCraft\Code\Agents\AgentWorkerPool(executor: $recorder),
            toolRegistry: $this->fakeRegistry('Bash', 'Read'),
        );

        $fine = new SubAgent(id: 'sub-fine', agent: $this->agentDeclaring('reader', ['Read']), task: 'read it');
        $typo = new SubAgent(id: 'sub-typo', agent: $this->agentDeclaring('broken', ['Reed']), task: 'typo');

        $caught = null;

        try {
            iterator_to_array($manager->executeAll([$fine, $typo], new CompleteRequest(model: 'test-model', messages: [])));
        } catch (\RuntimeException $e) {
            $caught = $e;
        }

        $this->assertNotNull($caught, 'an unresolvable sibling must refuse the batch');
        $this->assertStringContainsString('"Reed"', $caught->getMessage());
        $this->assertSame([], $recorder->requests, 'nothing dispatches once any member cannot resolve');
        $this->assertSame(SubAgent::STATUS_STOPPED, $fine->status);
        $this->assertStringContainsString('Not dispatched', (string) $fine->error);
        $this->assertStringContainsString('"Reed"', (string) $fine->error);
    }

    /**
     * The escape hatch survives the fix: with no registry the manager has no
     * truth to resolve against, so the caller's shared request forwards
     * VERBATIM — the pre-existing behaviour, pinned per the constructor's
     * doc-block, and NOT silently nulled agent by agent.
     */
    public function testWithNoRegistryTheParallelPathForwardsTheSharedRosterVerbatim(): void
    {
        $recorder = $this->recordingPoolExecutor();
        $manager = new AgentManager(
            provider: $this->provider,
            skillRegistry: $this->skillRegistry,
            workerPool: new \SugarCraft\Code\Agents\AgentWorkerPool(executor: $recorder),
        );

        $one = new SubAgent(id: 'sub-1', agent: $this->agentDeclaring('reader', ['Read']), task: 'a');
        $two = new SubAgent(id: 'sub-2', agent: $this->agentDeclaring('sheller', ['Bash']), task: 'b');
        $shared = $this->fakeRegistry('Bash', 'Read');

        iterator_to_array($manager->executeAll([$one, $two], new CompleteRequest(
            model: 'test-model',
            messages: [],
            tools: $shared,
        )));

        $this->assertSame(['Bash', 'Read'], self::toolNames($recorder->requests['sub-1']->tools ?? null));
        $this->assertSame(['Bash', 'Read'], self::toolNames($recorder->requests['sub-2']->tools ?? null));
    }

    // -------------------------------------------------------------------------
    // The sub-agent SKILL grant (E643).
    //
    // The identical fail-open, one field over: a granted skill that does not
    // resolve used to be silently SKIPPED while the preset prompt — pinned by
    // AgentDefinitionTest to NAME its granted skills — told the model the
    // skill was there. Fail-closed mirrors the tool-grant refusal above in
    // both shape and severity.
    // -------------------------------------------------------------------------

    /**
     * A manager whose one agent GRANTS `security-audit`, plus a holder the
     * provider callback writes the live request into (an object, because the
     * point of these tests is what reaches the provider AFTER construction —
     * a by-value return would snapshot `null`).
     *
     * @return array{AgentManager, \stdClass, string} manager, request holder, agent name
     */
    private function managerGrantingSkill(): array
    {
        $manager = new AgentManager(
            provider: $this->provider,
            skillRegistry: new SkillRegistry(),
        );
        $manager->register(new Agent(
            name: 'skillful',
            description: 'skillful description',
            prompt: 'Consult the security-audit skill you have been granted.',
            model: 'claude-sonnet-4-6',
            provider: 'anthropic',
            tools: [],
            skillNames: ['security-audit'],
            hooks: [],
            isActive: true,
        ));

        return [$manager, 'skillful'];
    }

    public function testAGrantedSkillThatDoesNotResolveFailsTheSubAgentRatherThanBeingSkipped(): void
    {
        [$manager, $name] = $this->managerGrantingSkill();
        $provider = new ScriptedProvider([new CompleteResponse(content: 'done')]);

        [$result, $subAgent] = $this->delegateOnTask($manager, $name, $provider);

        $this->assertSame(
            [],
            $provider->requests,
            'a prompt advertising an unresolvable skill must not reach the provider',
        );
        $this->assertTrue($result->isError(), 'a granted-but-missing skill must refuse, not skip');
        $this->assertStringContainsString('"security-audit"', (string) $subAgent->error);
        $this->assertStringContainsString('SkillRegistry does not resolve', (string) $subAgent->error);
        $this->assertStringContainsString((string) $subAgent->error, $result->content());
        $this->assertSame(SubAgent::STATUS_FAILED, $subAgent->status);
    }

    public function testAGrantedSkillThatResolvesIsStillAppendedToThePrompt(): void
    {
        // THE OTHER POLARITY: fail-closed must not become fail-always. A skill
        // the registry does resolve keeps the pre-fix behaviour — its body is
        // appended and the provider sees it, in the run's system turn.
        [$manager, $name] = $this->managerGrantingSkill();

        $registry = (new \ReflectionProperty(AgentManager::class, 'skillRegistry'))->getValue($manager);
        $this->assertInstanceOf(SkillRegistry::class, $registry);
        $registry->register([
            Skill::parse("---\ndescription: Audits PHP\n---\n\nAudit checklist body", 'security-audit'),
        ]);

        $provider = new ScriptedProvider([new CompleteResponse(content: 'done')]);
        [$result] = $this->delegateOnTask($manager, $name, $provider);

        $this->assertFalse($result->isError(), $result->content());
        $this->assertNotSame([], $provider->requests, 'a resolvable skill must not fail the sub-agent');
        $systemTurn = $provider->requests[0]->messages[0];
        $this->assertSame('system', $systemTurn->role());
        $this->assertStringContainsString('## Skill: security-audit', $systemTurn->content());
        $this->assertStringContainsString('Audit checklist body', $systemTurn->content());
    }

    // -------------------------------------------------------------------------
    // Load-time narrowing notices (E642 remainder).
    //
    // resolveGrantedTools() decides a narrowed grant SURVIVES; this is the
    // half that says so out loud at load, against the same two sets, so an
    // operator is not left inferring `disabledTools` from an absent tool.
    // Both polarities of E642's decision are asserted: disabled-but-real
    // WARNs (and still runs), never-existing stays SILENT here (it has no
    // safe load-time answer) and refuses at grant resolution.
    // -------------------------------------------------------------------------

    private function managerWithGrants(array $registry, ?array $universe): AgentManager
    {
        $provider = $this->createMock(ProviderInterface::class);
        $provider->method('supportsStreaming')->willReturn(false);
        $provider->method('complete')->willReturn(new CompleteResponse(content: 'ok'));

        return new AgentManager(
            provider: $provider,
            skillRegistry: new SkillRegistry(),
            toolRegistry: $registry,
            toolUniverse: $universe,
        );
    }

    public function testARosterNameTheSessionNarrowedAwayWarnsAtLoadWithoutRefusing(): void
    {
        $manager = $this->managerWithGrants(
            $this->fakeRegistry('Read', 'Grep'),
            $this->fakeRegistry('Read', 'Grep', 'Bash'),
        );
        $manager->register($this->agentDeclaring('coder', ['Read', 'Bash']));

        $warnings = $manager->narrowedGrantWarnings();
        $this->assertCount(1, $warnings, 'exactly the narrowed name is announced, once');
        $this->assertStringContainsString('"coder"', $warnings[0]);
        $this->assertStringContainsString('"Bash"', $warnings[0]);
        $this->assertStringContainsString('narrowed', $warnings[0]);
        $this->assertStringNotContainsString('"Read"', $warnings[0], 'a still-offered tool is not a narrowing');

        // WARN, NOT REFUSE — the same roster runs, narrowed to Read.
        $provider = new ScriptedProvider([new CompleteResponse(content: 'ok')]);
        [$result, $subAgent] = $this->delegateOnTask($manager, 'coder', $provider);
        $this->assertFalse($result->isError(), $result->content());
        $this->assertSame(SubAgent::STATUS_COMPLETE, $subAgent->status);
        $this->assertSame(['Read'], self::toolNames($provider->requests[0]->tools));
    }

    public function testARosterNameTheCeilingNeverHadIsSilentAtLoadAndRefusedAtGrant(): void
    {
        $manager = $this->managerWithGrants(
            $this->fakeRegistry('Read'),
            $this->fakeRegistry('Read', 'Grep', 'Bash'),
        );
        $manager->register($this->agentDeclaring('broken', ['Reed']));

        $this->assertSame(
            [],
            $manager->narrowedGrantWarnings(),
            'a typo gets no launch notice — its moment is grant resolution',
        );

        $provider = new ScriptedProvider([new CompleteResponse(content: 'ok')]);
        [$result, $subAgent] = $this->delegateOnTask($manager, 'broken', $provider);

        $this->assertTrue($result->isError(), 'E639\'s refuse path owns the never-existing polarity');
        $this->assertSame(SubAgent::STATUS_FAILED, $subAgent->status);
        $this->assertSame([], $provider->requests);
        $this->assertStringContainsString('"Reed"', (string) $subAgent->error);
    }

    /**
     * No ceiling, no claim: the collector compares two sets and holds its
     * tongue when either is missing — the same escape-hatch discipline the
     * constructor documents, applied to the reporting half.
     */
    public function testNarrowedGrantWarningsStayEmptyWithoutTheCeiling(): void
    {
        $manager = $this->managerWithGrants($this->fakeRegistry('Read'), null);
        $manager->register($this->agentDeclaring('coder', ['Read', 'Bash']));

        $this->assertSame([], $manager->narrowedGrantWarnings());
    }

    /**
     * A toolless session was already told so, ONCE, session-wide, at launch —
     * the launch-notice path that empties the registry owns that verdict. The
     * per-agent echo would multiply it by every agent times every declaration
     * and bury the notice it echoes, so an EMPTY registry suppresses here.
     * (Null registry = nothing to compare; empty registry = compared, and the
     * louder channel already spoke.)
     */
    public function testAToollessSessionIsNotEchoedAgentByAgent(): void
    {
        $manager = $this->managerWithGrants(
            [],
            $this->fakeRegistry('Read', 'Grep', 'Bash'),
        );
        $manager->register($this->agentDeclaring('coder', ['Read', 'Bash']));

        $this->assertSame(
            [],
            $manager->narrowedGrantWarnings(),
            'the session-wide toolless notice owns this case; per-agent echoes are noise',
        );
    }

    /**
     * REPORTS, DOES NOT POLICE. A malformed declaration must not turn a
     * launch notice into a startup crash — the strict refusal already
     * exists at grant resolution — so this pass skips what it cannot parse
     * instead of throwing on it.
     */
    public function testNarrowedGrantWarningsSkipMalformedDeclarationsWithoutThrowing(): void
    {
        $manager = $this->managerWithGrants(
            $this->fakeRegistry('Read'),
            $this->fakeRegistry('Read', 'Bash'),
        );
        $manager->register($this->agentDeclaring('wonky', ['Bash(git *', 'Bash']));

        $warnings = $manager->narrowedGrantWarnings();

        $this->assertCount(1, $warnings, 'the parseable narrowed name warns; the malformed one is left to the strict path');
        $this->assertStringContainsString('"Bash"', $warnings[0]);
    }

    // -------------------------------------------------------------------------
    // The Task delegation path (CC1C-c2a). Every sub-agent run above goes
    // through TaskTool's engine path, the one every production registration
    // binds, on the session manager these tests build.
    // -------------------------------------------------------------------------

    /**
     * Run one delegation of $agentName through {@see TaskTool} and hand back
     * the tool's result and the row the session manager settled for it.
     *
     * @param list<Tool> $engineTools the session's tool set, which the run
     *        inherits when its agent's grant resolves to no narrowing
     * @param (\Closure(SubAgentActivity): void)|null $emitter the run's frames;
     *        given one, the activity clock steps a second per read, so every
     *        due frame is sent
     * @return array{ToolResult, SubAgent}
     */
    private function delegateOnTask(
        AgentManager $manager,
        string $agentName,
        ProviderInterface $provider,
        array $engineTools = [],
        ?PermissionGate $sessionGate = null,
        ?\Closure $approver = null,
        ?\Closure $emitter = null,
    ): array {
        $engine = EngineBackend::new($provider, 'm')->withTools($engineTools);
        if ($sessionGate !== null) {
            $engine = $engine->withPermissionGate($sessionGate);
        }
        if ($approver !== null) {
            $engine = $engine->withPermissionApprover($approver);
        }

        $task = (new TaskTool($manager, suspended: new SuspendedDelegations($this->sandboxDir . '/suspended')))
            ->withEngine($engine);
        if ($emitter !== null) {
            $now = 0.0;
            $task = $task->withSubAgentEmitter($emitter)->withActivityClock(static function () use (&$now): float {
                return $now += 1.0;
            });
        }

        $before = count($manager->runs());
        $result = $task->execute(['description' => 'Delegated check', 'prompt' => 'do the thing', 'agent' => $agentName, 'id' => 'task-call']);
        $runs = $manager->runs();
        $this->assertCount($before + 1, $runs, 'the Task call ran exactly one delegation on the session manager');

        return [$result, $runs[$before]];
    }

    /**
     * Delegate to $agentName, whose first step answers with $calls and whose
     * second reports, and hand back the settled row and the tool turns that
     * second step carried back to the model. Every tool the session offers
     * is a {@see recordingTool()}: the ones $calls name, the registry's, and
     * $engineTools.
     *
     * @param list<WireToolCall> $calls
     * @param list<string> $engineTools further session tool names
     * @return array{SubAgent, list<string>}
     */
    private function delegateEmittingCalls(
        AgentManager $manager,
        string $agentName,
        array $calls,
        ?PermissionMode $sessionMode = null,
        ?PermissionGate $sessionGate = null,
        ?\Closure $approver = null,
        array $engineTools = [],
    ): array {
        $names = array_values(array_unique([
            ...array_keys($this->recordingTools),
            ...array_map(static fn (WireToolCall $call): string => $call->name(), $calls),
            ...$engineTools,
        ]));
        $provider = new ScriptedProvider([
            new CompleteResponse(content: 'Result', toolCalls: $calls),
            new CompleteResponse(content: 'reported'),
        ]);

        [$result, $subAgent] = $this->delegateOnTask(
            $manager,
            $agentName,
            $provider,
            engineTools: array_map(fn (string $name): Tool => $this->recordingTool($name), $names),
            sessionGate: $sessionGate ?? ($sessionMode === null ? null : new PermissionGate($sessionMode)),
            approver: $approver,
        );

        $this->assertFalse($result->isError(), $result->content());
        $this->assertCount(2, $provider->requests, 'the run carried its tool results back to the model');

        return [$subAgent, $this->delegatedToolTurns($provider->requests[1])];
    }

    /**
     * A session manager with one agent, $name, declaring $grant and $deny.
     *
     * @param list<mixed> $grant
     * @param list<string> $deny
     * @param ?list<string> $registry the session registry's tool names (each a
     *        {@see recordingTool()}), or null for no registry — where the run
     *        then inherits the engine's whole set and only the grant check
     *        stands between a call and the tool
     */
    private function emittingCallsManager(string $name, array $grant, array $deny = [], ?array $registry = null): AgentManager
    {
        $manager = new AgentManager(
            provider: $this->provider,
            skillRegistry: $this->skillRegistry,
            toolRegistry: $registry === null ? null : array_map(fn (string $tool): Tool => $this->recordingTool($tool), $registry),
        );
        $manager->register(new Agent(
            name: $name,
            description: "$name description",
            prompt: 'Test prompt',
            model: 'claude-sonnet-4-6',
            provider: 'anthropic',
            tools: $grant,
            skillNames: [],
            hooks: [],
            isActive: true,
            disallowedTools: $deny,
        ));

        return $manager;
    }

    /**
     * Refuse a delegation at grant resolution and hand back the reason: the
     * Task result is an error, the row is FAILED with that reason, and the
     * provider is never called.
     *
     * @param list<mixed> $grant
     * @param ?list<mixed> $registry
     * @param ?list<mixed> $universe
     * @param list<mixed> $mcpServers
     * @param list<string> $deny
     */
    private function subAgentGrantRefusal(array $grant, ?array $registry, ?array $universe = null, array $mcpServers = [], array $deny = []): string
    {
        $manager = new AgentManager(
            provider: $this->provider,
            skillRegistry: $this->skillRegistry,
            toolRegistry: $registry,
            toolUniverse: $universe,
        );
        $manager->register(new Agent(
            name: 'granted',
            description: 'granted description',
            prompt: 'Test prompt',
            model: 'claude-sonnet-4-6',
            provider: 'anthropic',
            tools: $grant,
            skillNames: [],
            hooks: [],
            isActive: true,
            disallowedTools: $deny,
            mcpServers: $mcpServers,
        ));

        $provider = new ScriptedProvider([new CompleteResponse(content: 'done')]);
        [$result, $subAgent] = $this->delegateOnTask($manager, 'granted', $provider);

        $this->assertTrue($result->isError(), 'a grant that does not resolve must refuse the delegation');
        $this->assertSame(SubAgent::STATUS_FAILED, $subAgent->status);
        $this->assertSame([], $provider->requests, 'a refused grant never reaches the provider');
        $this->assertNotNull($subAgent->error);
        $this->assertStringContainsString($subAgent->error, $result->content());

        return $subAgent->error;
    }

    /**
     * One tool per name for the whole test, recording each call's arguments.
     *
     * @return Tool&object{calls: list<array<string, mixed>>}
     */
    private function recordingTool(string $name): Tool
    {
        return $this->recordingTools[$name] ??= new class ($name) implements Tool {
            /** @var list<array<string, mixed>> */
            public array $calls = [];

            public function __construct(private readonly string $name)
            {
            }

            public function name(): string
            {
                return $this->name;
            }

            public function description(): string
            {
                return "records {$this->name} calls";
            }

            public function inputSchema(): array
            {
                return ['type' => 'object'];
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

    /**
     * @return list<string> the tool turns $request carried, in order
     */
    private function delegatedToolTurns(CompleteRequest $request): array
    {
        return array_values(array_map(
            static fn (TypedMessage $message): string => $message->content(),
            array_filter($request->messages, static fn (TypedMessage $message): bool => $message->role() === 'tool'),
        ));
    }

    /**
     * @return list<string> the assistant turns $request carried, in order
     */
    private function delegatedAssistantTurns(CompleteRequest $request): array
    {
        return array_values(array_map(
            static fn (TypedMessage $message): string => $message->content(),
            array_filter($request->messages, static fn (TypedMessage $message): bool => $message->role() === 'assistant'),
        ));
    }

    /**
     * A streaming provider whose stream is $stream's generator, so a test can
     * look at the row between two chunks.
     *
     * @param \Closure(CompleteRequest): \Generator<CompleteResponse> $stream
     */
    private function streamingProviderFrom(\Closure $stream): ProviderInterface
    {
        return new class ($stream) implements ProviderInterface {
            public function __construct(private \Closure $stream)
            {
            }

            public function name(): string
            {
                return 'generator';
            }

            public function supportsStreaming(): bool
            {
                return true;
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
                return 100000;
            }

            public function costPer1kTokens(string $model, string $direction): ?float
            {
                return 0.0;
            }

            public function complete(CompleteRequest $request): CompleteResponse
            {
                throw new \LogicException('this provider only streams');
            }

            public function completeStream(CompleteRequest $request): \Generator
            {
                return ($this->stream)($request);
            }

            public function embeddings(EmbeddingsRequest $request): EmbeddingsResponse
            {
                return new EmbeddingsResponse([]);
            }
        };
    }

    private function createAgent(
        string $name = 'test-agent',
        string $prompt = 'Test prompt',
        bool $isActive = true,
    ): Agent {
        return new Agent(
            name: $name,
            description: "$name description",
            prompt: $prompt,
            model: 'claude-sonnet-4-6',
            provider: 'anthropic',
            tools: [],
            skillNames: [],
            hooks: [],
            isActive: $isActive,
        );
    }

    /**
     * @param array<string> $chunks
     * @return \Generator<CompleteResponse>
     */
    private function createStreamingResponse(array $chunks): \Generator
    {
        foreach ($chunks as $chunk) {
            yield new CompleteResponse(content: $chunk);
        }
    }
}
