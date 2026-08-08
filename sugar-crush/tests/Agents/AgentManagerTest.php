<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Agents;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Agents\Agent;
use SugarCraft\Crush\Agents\AgentManager;
use SugarCraft\Crush\Agents\SubAgent;
use SugarCraft\Crush\Agents\Team;
use SugarCraft\Crush\Agents\TeamConfig;
use SugarCraft\Crush\Agents\TeamManager;
use SugarCraft\Crush\Permissions\PermissionDecision;
use SugarCraft\Crush\Permissions\PermissionGate;
use SugarCraft\Crush\Permissions\PermissionMode;
use SugarCraft\Crush\Providers\CompleteRequest;
use SugarCraft\Crush\Providers\CompleteResponse;
use SugarCraft\Crush\Providers\ProviderInterface;
use SugarCraft\Crush\Skills\Skill;
use SugarCraft\Crush\ToolCall;
use SugarCraft\Crush\Skills\SkillRegistry;

/**
 * Tests for AgentManager - manages agents and sub-agents.
 */
final class AgentManagerTest extends TestCase
{
    private ProviderInterface $provider;
    private SkillRegistry $skillRegistry;
    private AgentManager $agentManager;

    protected function setUp(): void
    {
        $this->provider = $this->createMock(ProviderInterface::class);
        $this->skillRegistry = new SkillRegistry();
        $this->agentManager = new AgentManager($this->provider, $this->skillRegistry);
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

    public function testCreateSubAgentMidSessionModeChangeThrowsLogicException(): void
    {
        $agent = $this->createAgent(name: 'mode-test-agent', prompt: 'Mode test agent');
        $this->agentManager->register($agent);

        // First sub-agent with Default mode seals the session
        $this->agentManager->createSubAgent('mode-test-agent', 'Task 1', PermissionMode::Default);

        // Attempting to create a second sub-agent with a different mode throws
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Permission mode cannot be changed mid-session');

        $this->agentManager->createSubAgent('mode-test-agent', 'Task 2', PermissionMode::BypassPermissions);
    }

    // -------------------------------------------------------------------------
    // getSubAgent()
    // -------------------------------------------------------------------------

    public function testGetSubAgentSuccess(): void
    {
        $agent = $this->createAgent(name: 'get-agent', prompt: 'Get agent');
        $this->agentManager->register($agent);

        $created = $this->agentManager->createSubAgent('get-agent', 'Get test');

        $retrieved = $this->agentManager->getSubAgent($created->id);

        $this->assertSame($created, $retrieved);
    }

    public function testGetSubAgentNotFoundReturnsNull(): void
    {
        $retrieved = $this->agentManager->getSubAgent('nonexistent-id');
        $this->assertNull($retrieved);
    }

    // -------------------------------------------------------------------------
    // executeSubAgent()
    // -------------------------------------------------------------------------

    public function testExecuteSubAgentNotFoundThrowsException(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('SubAgent not found: nonexistent-id');

        // Consume the generator to trigger the exception
        foreach ($this->agentManager->executeSubAgent('nonexistent-id') as $_) {
            // No-op
        }
    }

    public function testExecuteSubAgentSuccessNonStreaming(): void
    {
        $agent = $this->createAgent(name: 'exec-agent', prompt: 'Exec prompt');
        $this->agentManager->register($agent);

        $subAgent = $this->agentManager->createSubAgent('exec-agent', 'Execute test');

        $this->provider->method('supportsStreaming')->willReturn(false);
        $this->provider->method('complete')
            ->willReturn(new CompleteResponse(content: 'Execution result'));

        $results = [];
        foreach ($this->agentManager->executeSubAgent($subAgent->id) as $result) {
            $results[] = $result;
        }

        // Non-streaming mode does not yield intermediate results
        $this->assertCount(0, $results);
        $this->assertSame(SubAgent::STATUS_COMPLETE, $subAgent->status);
        $this->assertSame('Execution result', $subAgent->output);
        $this->assertInstanceOf(\DateTimeImmutable::class, $subAgent->completedAt);
    }

    public function testExecuteSubAgentSuccessStreaming(): void
    {
        $agent = $this->createAgent(name: 'stream-agent', prompt: 'Stream prompt');
        $this->agentManager->register($agent);

        $subAgent = $this->agentManager->createSubAgent('stream-agent', 'Stream test');

        $this->provider->method('supportsStreaming')->willReturn(true);
        $this->provider->method('completeStream')
            ->willReturn($this->createStreamingResponse(['First ', 'second ', 'third']));

        $results = [];
        foreach ($this->agentManager->executeSubAgent($subAgent->id) as $result) {
            $results[] = $result;
        }

        $this->assertCount(3, $results);
        $this->assertSame(SubAgent::STATUS_COMPLETE, $subAgent->status);
        $this->assertSame('First second third', $subAgent->output);
    }

    public function testExecuteSubAgentHandlesException(): void
    {
        $agent = $this->createAgent(name: 'error-agent', prompt: 'Error prompt');
        $this->agentManager->register($agent);

        $subAgent = $this->agentManager->createSubAgent('error-agent', 'Error test');

        $this->provider->method('supportsStreaming')->willReturn(false);
        $this->provider->method('complete')
            ->willThrowException(new \RuntimeException('Provider error'));

        try {
            foreach ($this->agentManager->executeSubAgent($subAgent->id) as $_) {
                // No-op
            }
            $this->fail('Expected exception was not thrown');
        } catch (\RuntimeException $e) {
            $this->assertSame('Provider error', $e->getMessage());
            $this->assertSame(SubAgent::STATUS_FAILED, $subAgent->status);
            $this->assertSame('Provider error', $subAgent->error);
        }
    }

    public function testExecuteSubAgentPermissionGateDenySetsFailedStatus(): void
    {
        // Custom gate factory that returns a gate which denies Bash tool calls
        $denyGate = new PermissionGate(PermissionMode::DontAsk);

        $customAgentManager = new AgentManager(
            provider: $this->provider,
            skillRegistry: $this->skillRegistry,
            workerPool: null,
            permissionGateFactory: fn() => $denyGate,
        );

        $agent = $this->createAgent(name: 'deny-agent', prompt: 'Deny agent');
        $customAgentManager->register($agent);

        $subAgent = $customAgentManager->createSubAgent('deny-agent', 'Execute with deny');

        $this->provider->method('supportsStreaming')->willReturn(false);
        $this->provider->method('complete')
            ->willReturn(new CompleteResponse(
                content: 'Result',
                toolCalls: [
                    new ToolCall(name: 'Bash', arguments: ['command' => 'ls']),
                ],
            ));

        try {
            foreach ($customAgentManager->executeSubAgent($subAgent->id) as $_) {
                // No-op
            }
            $this->fail('Expected RuntimeException was not thrown');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('denied by permission gate', $e->getMessage());
            $this->assertSame(SubAgent::STATUS_FAILED, $subAgent->status);
            $this->assertStringContainsString('denied by permission gate', $subAgent->error);
        }
    }

    public function testExecuteSubAgentPermissionGateAskThrowsRuntimeException(): void
    {
        // Custom gate factory that returns a gate which asks (not denies) for Bash tool calls
        // Default mode: Bash is not read-only, so it returns Ask
        $askGate = new PermissionGate(PermissionMode::Default);

        $customAgentManager = new AgentManager(
            provider: $this->provider,
            skillRegistry: $this->skillRegistry,
            workerPool: null,
            permissionGateFactory: fn() => $askGate,
        );

        $agent = $this->createAgent(name: 'ask-agent', prompt: 'Ask agent');
        $customAgentManager->register($agent);

        $subAgent = $customAgentManager->createSubAgent('ask-agent', 'Execute with ask');

        $this->provider->method('supportsStreaming')->willReturn(false);
        $this->provider->method('complete')
            ->willReturn(new CompleteResponse(
                content: 'Result',
                toolCalls: [
                    new ToolCall(name: 'Bash', arguments: ['command' => 'ls']),
                ],
            ));

        try {
            foreach ($customAgentManager->executeSubAgent($subAgent->id) as $_) {
                // No-op
            }
            $this->fail('Expected RuntimeException was not thrown');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('requires user input', $e->getMessage());
            $this->assertSame(SubAgent::STATUS_FAILED, $subAgent->status);
            $this->assertStringContainsString('requires user input', $subAgent->error);
        }
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

        $this->assertNotNull($this->agentManager->getSubAgent($id));

        $this->agentManager->removeSubAgent($id);

        $this->assertNull($this->agentManager->getSubAgent($id));
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
            messages: [new \SugarCraft\Crush\Messages\UserMessage('test')],
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
        $blockingExecutor = $this->createMock(\SugarCraft\Crush\Agents\ExecutorInterface::class);
        $blockingExecutor->method('execute')
            ->willReturnCallback(function (\SugarCraft\Crush\Agents\SubAgent $agent) {
                return new \SugarCraft\Crush\Agents\AgentResult(
                    agentId: $agent->id,
                    status: \SugarCraft\Crush\Agents\AgentStatus::Completed,
                    output: 'Output from ' . $agent->agent->name,
                );
            });
        $blockingExecutor->method('executeStream')
            ->willReturnCallback(function (\SugarCraft\Crush\Agents\SubAgent $agent) {
                yield new \SugarCraft\Crush\Agents\AgentResult(
                    agentId: $agent->id,
                    status: \SugarCraft\Crush\Agents\AgentStatus::Completed,
                    output: 'Output from ' . $agent->agent->name,
                );
            });
        $blockingExecutor->method('cancel')->willReturnCallback(function (string $id) {});
        $blockingExecutor->method('cancelAll')->willReturnCallback(function () {});

        $workerPool = new \SugarCraft\Crush\Agents\AgentWorkerPool(
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
            messages: [new \SugarCraft\Crush\Messages\UserMessage('Execute all tasks')],
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
                \SugarCraft\Crush\Agents\AgentStatus::Completed,
                \SugarCraft\Crush\Agents\AgentStatus::Failed,
                \SugarCraft\Crush\Agents\AgentStatus::TimedOut,
                \SugarCraft\Crush\Agents\AgentStatus::Stopped,
            ]);
        }
    }

    public function testExecuteAllDelegatesToWorkerPool(): void
    {
        $blockingExecutor = $this->createMock(\SugarCraft\Crush\Agents\ExecutorInterface::class);
        $blockingExecutor->method('execute')
            ->willReturnCallback(function (\SugarCraft\Crush\Agents\SubAgent $agent) {
                return new \SugarCraft\Crush\Agents\AgentResult(
                    agentId: $agent->id,
                    status: \SugarCraft\Crush\Agents\AgentStatus::Completed,
                    output: 'Task: ' . $agent->task . ' Agent: ' . $agent->agent->name,
                );
            });
        $blockingExecutor->method('executeStream')
            ->willReturnCallback(function (\SugarCraft\Crush\Agents\SubAgent $agent) {
                yield new \SugarCraft\Crush\Agents\AgentResult(
                    agentId: $agent->id,
                    status: \SugarCraft\Crush\Agents\AgentStatus::Completed,
                    output: 'Task: ' . $agent->task . ' Agent: ' . $agent->agent->name,
                );
            });
        $blockingExecutor->method('cancel')->willReturnCallback(function (string $id) {});
        $blockingExecutor->method('cancelAll')->willReturnCallback(function () {});

        $workerPool = new \SugarCraft\Crush\Agents\AgentWorkerPool(
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
            messages: [new \SugarCraft\Crush\Messages\UserMessage('Ignored')],
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
        $customExecutor = $this->createMock(\SugarCraft\Crush\Agents\ExecutorInterface::class);
        $customExecutor->method('execute')
            ->willReturnCallback(function (\SugarCraft\Crush\Agents\SubAgent $agent) {
                return new \SugarCraft\Crush\Agents\AgentResult(
                    agentId: $agent->id,
                    status: \SugarCraft\Crush\Agents\AgentStatus::Completed,
                    output: 'Result',
                );
            });
        $customExecutor->method('executeStream')
            ->willReturnCallback(function (\SugarCraft\Crush\Agents\SubAgent $agent) {
                yield new \SugarCraft\Crush\Agents\AgentResult(
                    agentId: $agent->id,
                    status: \SugarCraft\Crush\Agents\AgentStatus::Completed,
                    output: 'Result',
                );
            });
        $customExecutor->method('cancel')->willReturnCallback(function (string $id) {});
        $customExecutor->method('cancelAll')->willReturnCallback(function () {});

        $workerPool = new \SugarCraft\Crush\Agents\AgentWorkerPool(
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
            messages: [new \SugarCraft\Crush\Messages\UserMessage('Track test')],
        );

        $results = [];
        foreach ($agentManager->executeAll([$subAgent], $request) as $result) {
            $results[] = $result;
        }

        $this->assertCount(1, $results);

        // Sub-agent must be registered so it can be looked up
        $tracked = $agentManager->getSubAgent('track-sub');
        $this->assertSame($subAgent, $tracked);
    }

    public function testExecuteAllUsesProvidedWorkerPool(): void
    {
        $customExecutor = $this->createMock(\SugarCraft\Crush\Agents\ExecutorInterface::class);
        $customExecutor->method('execute')
            ->willReturnCallback(function (\SugarCraft\Crush\Agents\SubAgent $agent) {
                return new \SugarCraft\Crush\Agents\AgentResult(
                    agentId: $agent->id,
                    status: \SugarCraft\Crush\Agents\AgentStatus::Completed,
                    output: 'Custom pool result',
                );
            });
        $customExecutor->method('executeStream')
            ->willReturnCallback(function (\SugarCraft\Crush\Agents\SubAgent $agent) {
                yield new \SugarCraft\Crush\Agents\AgentResult(
                    agentId: $agent->id,
                    status: \SugarCraft\Crush\Agents\AgentStatus::Completed,
                    output: 'Custom pool result',
                );
            });
        $customExecutor->method('cancel')->willReturnCallback(function (string $id) {});
        $customExecutor->method('cancelAll')->willReturnCallback(function () {});

        $customPool = new \SugarCraft\Crush\Agents\AgentWorkerPool(
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
            messages: [new \SugarCraft\Crush\Messages\UserMessage('Ignored')],
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
        $teamManager = new TeamManager(sys_get_temp_dir() . '/agentmgr_test_' . uniqid());

        $this->agentManager->setTeamManager($teamManager);

        $this->assertSame($teamManager, $this->agentManager->getTeamManager());
    }

    public function testCreateTeamDelegates(): void
    {
        $teamManager = new TeamManager(sys_get_temp_dir() . '/agentmgr_test_' . uniqid());
        $this->agentManager->setTeamManager($teamManager);

        $team = $this->agentManager->createTeam('test-team', 'Test Team', 'lead-agent-1');

        $this->assertInstanceOf(Team::class, $team);
        $this->assertSame('test-team', $team->id);
        $this->assertSame('Test Team', $team->name);
        $this->assertSame('lead-agent-1', $team->leadAgentId);

        // Verify it was actually registered in TeamManager
        $this->assertSame($team, $teamManager->getTeam('test-team'));
    }

    public function testGetTeamDelegates(): void
    {
        $teamManager = new TeamManager(sys_get_temp_dir() . '/agentmgr_test_' . uniqid());
        $this->agentManager->setTeamManager($teamManager);

        // Create a team directly via TeamManager
        $created = $teamManager->createTeam('existing-team', 'Existing Team', 'lead-agent-2');

        // AgentManager should return it via getTeam
        $retrieved = $this->agentManager->getTeam('existing-team');

        $this->assertSame($created, $retrieved);
    }

    public function testGetTeamThrowsWhenNoTeamManager(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('TeamManager has not been set on AgentManager');

        $this->agentManager->getTeam('any-team');
    }

    public function testHasTeamDelegates(): void
    {
        $teamManager = new TeamManager(sys_get_temp_dir() . '/agentmgr_test_' . uniqid());
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
        $teamManager = new TeamManager(sys_get_temp_dir() . '/agentmgr_test_' . uniqid());
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
        $teamManager = new TeamManager(sys_get_temp_dir() . '/agentmgr_test_' . uniqid());
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
        $teamManager = new TeamManager(sys_get_temp_dir() . '/agentmgr_test_' . uniqid());
        $this->agentManager->setTeamManager($teamManager);

        // Initially empty
        $this->assertSame([], $this->agentManager->getTeams());

        // Create some teams
        $team1 = $this->agentManager->createTeam('team-1', 'Team One', 'lead-1');
        $team2 = $this->agentManager->createTeam('team-2', 'Team Two', 'lead-2');

        $teams = $this->agentManager->getTeams();

        $this->assertCount(2, $teams);
        $this->assertSame($team1, $teams[0]);
        $this->assertSame($team2, $teams[1]);
    }

    public function testGetTeamsThrowsWhenNoTeamManager(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('TeamManager has not been set on AgentManager');

        $this->agentManager->getTeams();
    }

    public function testTeamCountDelegates(): void
    {
        $teamManager = new TeamManager(sys_get_temp_dir() . '/agentmgr_test_' . uniqid());
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
    // Helper methods
    // -------------------------------------------------------------------------

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
