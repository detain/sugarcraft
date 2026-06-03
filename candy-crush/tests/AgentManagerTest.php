<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Agents\Agent;
use SugarCraft\Crush\Agents\AgentManager;
use SugarCraft\Crush\Agents\SubAgent;
use SugarCraft\Crush\Providers\CompleteRequest;
use SugarCraft\Crush\Providers\CompleteResponse;
use SugarCraft\Crush\Providers\ProviderInterface;
use SugarCraft\Crush\Skills\Skill;
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
