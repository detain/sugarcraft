<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Sessions;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Agents\Agent;
use SugarCraft\Crush\Agents\AgentResult;
use SugarCraft\Crush\Agents\AgentStatus;
use SugarCraft\Crush\Sessions\BackgroundSession;
use SugarCraft\Crush\Sessions\BackgroundSessionStatus;

final class BackgroundSessionTest extends TestCase
{
    public function testConstruction(): void
    {
        $agent = new Agent(
    name: 'test-agent',
    description: 'Test agent',
    prompt: '',
    model: 'test-model',
    provider: 'test',
    tools: [],
    skillNames: [],
    hooks: [],
    isActive: true
);
        $session = new BackgroundSession(
            id: 'session-1',
            name: 'Test Session',
            agent: $agent,
            task: 'do work',
            workingDirectory: '/tmp',
        );
        $this->assertSame('session-1', $session->id);
        $this->assertSame('Test Session', $session->name);
        $this->assertSame($agent, $session->agent);
        $this->assertSame('do work', $session->task);
        $this->assertSame('/tmp', $session->workingDirectory);
        $this->assertSame([], $session->tags);
        $this->assertSame(BackgroundSessionStatus::Pending, $session->status);
        $this->assertSame('', $session->output);
    }

    public function testConstructionWithTags(): void
    {
        $agent = new Agent(
    name: 'test-agent',
    description: 'Test agent',
    prompt: '',
    model: 'test-model',
    provider: 'test',
    tools: [],
    skillNames: [],
    hooks: [],
    isActive: true
);
        $session = new BackgroundSession(
            id: 's1', name: 'S1', agent: $agent,
            task: 't', workingDirectory: '/tmp',
            tags: ['research', 'urgent'],
        );
        $this->assertSame(['research', 'urgent'], $session->tags);
    }

    public function testWithStatus(): void
    {
        $agent = new Agent(
    name: 'test-agent',
    description: 'Test agent',
    prompt: '',
    model: 'test-model',
    provider: 'test',
    tools: [],
    skillNames: [],
    hooks: [],
    isActive: true
);
        $session = new BackgroundSession(
            id: 's1', name: 'S1', agent: $agent,
            task: 't', workingDirectory: '/tmp',
        );
        $next = $session->withStatus(BackgroundSessionStatus::Running);
        $this->assertSame(BackgroundSessionStatus::Pending, $session->status);
        $this->assertSame(BackgroundSessionStatus::Running, $next->status);
    }

    public function testRecordHeartbeat(): void
    {
        $agent = new Agent(
    name: 'test-agent',
    description: 'Test agent',
    prompt: '',
    model: 'test-model',
    provider: 'test',
    tools: [],
    skillNames: [],
    hooks: [],
    isActive: true
);
        $session = new BackgroundSession(
            id: 's1', name: 'S1', agent: $agent,
            task: 't', workingDirectory: '/tmp',
        );
        $before = $session->secondsSinceLastHeartbeat();
        $session->recordHeartbeat();
        $after = $session->secondsSinceLastHeartbeat();
        $this->assertLessThanOrEqual(1, $after);
    }

    public function testIsStalledWhenHeartbeatOverdue(): void
    {
        $agent = new Agent(
    name: 'test-agent',
    description: 'Test agent',
    prompt: '',
    model: 'test-model',
    provider: 'test',
    tools: [],
    skillNames: [],
    hooks: [],
    isActive: true
);
        $session = new BackgroundSession(
            id: 's1', name: 'S1', agent: $agent,
            task: 't', workingDirectory: '/tmp',
        );
        // Default heartbeat timeout from BackgroundSupervisor is 15 seconds
        $this->assertFalse($session->isStalled(15));
        // Simulate time passing by directly setting lastHeartbeat (via reflection)
        $refl = new \ReflectionClass($session);
        $prop = $refl->getProperty('lastHeartbeat');
        $prop->setAccessible(true);
        $prop->setValue($session, time() - 20);
        $this->assertTrue($session->isStalled(15));
    }

    public function testIsRunning(): void
    {
        $agent = new Agent(
    name: 'test-agent',
    description: 'Test agent',
    prompt: '',
    model: 'test-model',
    provider: 'test',
    tools: [],
    skillNames: [],
    hooks: [],
    isActive: true
);
        $session = new BackgroundSession(
            id: 's1', name: 'S1', agent: $agent,
            task: 't', workingDirectory: '/tmp',
        );
        $this->assertFalse($session->isRunning());
        $running = $session->withStatus(BackgroundSessionStatus::Running);
        $this->assertTrue($running->isRunning());
        $streaming = $session->withStatus(BackgroundSessionStatus::Streaming);
        $this->assertTrue($streaming->isRunning());
    }

    public function testIsActive(): void
    {
        $agent = new Agent(
    name: 'test-agent',
    description: 'Test agent',
    prompt: '',
    model: 'test-model',
    provider: 'test',
    tools: [],
    skillNames: [],
    hooks: [],
    isActive: true
);
        $session = new BackgroundSession(
            id: 's1', name: 'S1', agent: $agent,
            task: 't', workingDirectory: '/tmp',
        );
        $this->assertTrue($session->isActive());
        $completed = $session->withStatus(BackgroundSessionStatus::Completed);
        $this->assertFalse($completed->isActive());
    }

    public function testIsComplete(): void
    {
        $agent = new Agent(
    name: 'test-agent',
    description: 'Test agent',
    prompt: '',
    model: 'test-model',
    provider: 'test',
    tools: [],
    skillNames: [],
    hooks: [],
    isActive: true
);
        $session = new BackgroundSession(
            id: 's1', name: 'S1', agent: $agent,
            task: 't', workingDirectory: '/tmp',
        );
        $this->assertFalse($session->isComplete());
        $done = $session->withStatus(BackgroundSessionStatus::Completed);
        $this->assertTrue($done->isComplete());
    }

    public function testIsFailure(): void
    {
        $agent = new Agent(
    name: 'test-agent',
    description: 'Test agent',
    prompt: '',
    model: 'test-model',
    provider: 'test',
    tools: [],
    skillNames: [],
    hooks: [],
    isActive: true
);
        $session = new BackgroundSession(
            id: 's1', name: 'S1', agent: $agent,
            task: 't', workingDirectory: '/tmp',
        );
        $this->assertFalse($session->isFailure());
        $failed = $session->withStatus(BackgroundSessionStatus::Failed);
        $this->assertTrue($failed->isFailure());
        $withError = new BackgroundSession(
            id: 's1', name: 'S1', agent: $agent,
            task: 't', workingDirectory: '/tmp',
        );
        $withError->error = 'something went wrong';
        $this->assertTrue($withError->isFailure());
    }

    public function testElapsedDisplay(): void
    {
        $agent = new Agent(
    name: 'test-agent',
    description: 'Test agent',
    prompt: '',
    model: 'test-model',
    provider: 'test',
    tools: [],
    skillNames: [],
    hooks: [],
    isActive: true
);
        $session = new BackgroundSession(
            id: 's1', name: 'S1', agent: $agent,
            task: 't', workingDirectory: '/tmp',
            createdAt: new \DateTimeImmutable('-65 seconds'),
        );
        $display = $session->elapsedDisplay();
        $this->assertStringContainsString('m', $display);
    }

    public function testUsageDisplayEmpty(): void
    {
        $agent = new Agent(
    name: 'test-agent',
    description: 'Test agent',
    prompt: '',
    model: 'test-model',
    provider: 'test',
    tools: [],
    skillNames: [],
    hooks: [],
    isActive: true
);
        $session = new BackgroundSession(
            id: 's1', name: 'S1', agent: $agent,
            task: 't', workingDirectory: '/tmp',
        );
        $this->assertSame('', $session->usageDisplay());
    }

    public function testUsageDisplayWithTokens(): void
    {
        $agent = new Agent(
    name: 'test-agent',
    description: 'Test agent',
    prompt: '',
    model: 'test-model',
    provider: 'test',
    tools: [],
    skillNames: [],
    hooks: [],
    isActive: true
);
        $session = new BackgroundSession(
            id: 's1', name: 'S1', agent: $agent,
            task: 't', workingDirectory: '/tmp',
        );
        $session->tokensUsed = 1234;
        $this->assertSame('1,234 tokens', $session->usageDisplay());
    }

    public function testToAgentResult(): void
    {
        $agent = new Agent(
    name: 'test-agent',
    description: 'Test agent',
    prompt: '',
    model: 'test-model',
    provider: 'test',
    tools: [],
    skillNames: [],
    hooks: [],
    isActive: true
);
        $session = new BackgroundSession(
            id: 's1', name: 'S1', agent: $agent,
            task: 't', workingDirectory: '/tmp',
        );
        $done = $session->withStatus(BackgroundSessionStatus::Completed);
        $done->output = 'test output';
        $result = $done->toAgentResult();
        $this->assertSame('s1', $result->agentId);
        $this->assertSame(AgentStatus::Completed, $result->status);
        $this->assertSame('test output', $result->output);
    }

    public function testToArray(): void
    {
        $agent = new Agent(
    name: 'test-agent',
    description: 'Test agent',
    prompt: '',
    model: 'test-model',
    provider: 'test',
    tools: [],
    skillNames: [],
    hooks: [],
    isActive: true
);
        $session = new BackgroundSession(
            id: 's1', name: 'S1', agent: $agent,
            task: 't', workingDirectory: '/tmp',
        );
        $arr = $session->toArray();
        $this->assertSame('s1', $arr['id']);
        $this->assertSame('S1', $arr['name']);
        $this->assertSame('pending', $arr['status']);
    }
}