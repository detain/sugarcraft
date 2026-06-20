<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Agents;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Agents\Agent;
use SugarCraft\Crush\Agents\SubAgent;

/**
 * Tests for SubAgent value object - represents a sub-agent task instance.
 */
final class SubAgentTest extends TestCase
{
    // -------------------------------------------------------------------------
    // Status constants
    // -------------------------------------------------------------------------

    public function testStatusConstants(): void
    {
        $this->assertSame('pending', SubAgent::STATUS_PENDING);
        $this->assertSame('running', SubAgent::STATUS_RUNNING);
        $this->assertSame('streaming', SubAgent::STATUS_STREAMING);
        $this->assertSame('complete', SubAgent::STATUS_COMPLETE);
        $this->assertSame('stopped', SubAgent::STATUS_STOPPED);
        $this->assertSame('failed', SubAgent::STATUS_FAILED);
    }

    // -------------------------------------------------------------------------
    // Constructor & initial state
    // -------------------------------------------------------------------------

    public function testConstructorSetsInitialState(): void
    {
        $agent = $this->createAgent();
        $createdAt = new \DateTimeImmutable('2024-01-15T10:00:00Z');

        $subAgent = new SubAgent(
            id: 'test_id_123',
            agent: $agent,
            task: 'Test task description',
            createdAt: $createdAt,
        );

        $this->assertSame('test_id_123', $subAgent->id);
        $this->assertSame($agent, $subAgent->agent);
        $this->assertSame('Test task description', $subAgent->task);
        $this->assertSame($createdAt, $subAgent->createdAt);
        $this->assertSame(SubAgent::STATUS_PENDING, $subAgent->status);
        $this->assertSame('', $subAgent->output);
        $this->assertNull($subAgent->completedAt);
        $this->assertNull($subAgent->error);
    }

    public function testConstructorWithDefaultCreatedAt(): void
    {
        $agent = $this->createAgent();
        $before = new \DateTimeImmutable();

        $subAgent = new SubAgent(
            id: 'id_456',
            agent: $agent,
            task: 'Task with default timestamp',
        );

        $after = new \DateTimeImmutable();

        $this->assertSame('id_456', $subAgent->id);
        $this->assertSame($agent, $subAgent->agent);
        $this->assertInstanceOf(\DateTimeImmutable::class, $subAgent->createdAt);
        $this->assertGreaterThanOrEqual($before, $subAgent->createdAt);
        $this->assertLessThanOrEqual($after, $subAgent->createdAt);
    }

    // -------------------------------------------------------------------------
    // isRunning()
    // -------------------------------------------------------------------------

    public function testIsRunningWhenPending(): void
    {
        $subAgent = $this->createSubAgentWithStatus(SubAgent::STATUS_PENDING);
        $this->assertFalse($subAgent->isRunning());
    }

    public function testIsRunningWhenRunning(): void
    {
        $subAgent = $this->createSubAgentWithStatus(SubAgent::STATUS_RUNNING);
        $this->assertTrue($subAgent->isRunning());
    }

    public function testIsRunningWhenStreaming(): void
    {
        $subAgent = $this->createSubAgentWithStatus(SubAgent::STATUS_STREAMING);
        $this->assertTrue($subAgent->isRunning());
    }

    public function testIsRunningWhenComplete(): void
    {
        $subAgent = $this->createSubAgentWithStatus(SubAgent::STATUS_COMPLETE);
        $this->assertFalse($subAgent->isRunning());
    }

    // -------------------------------------------------------------------------
    // isComplete()
    // -------------------------------------------------------------------------

    public function testIsCompleteWhenPending(): void
    {
        $subAgent = $this->createSubAgentWithStatus(SubAgent::STATUS_PENDING);
        $this->assertFalse($subAgent->isComplete());
    }

    public function testIsCompleteWhenRunning(): void
    {
        $subAgent = $this->createSubAgentWithStatus(SubAgent::STATUS_RUNNING);
        $this->assertFalse($subAgent->isComplete());
    }

    public function testIsCompleteWhenComplete(): void
    {
        $subAgent = $this->createSubAgentWithStatus(SubAgent::STATUS_COMPLETE);
        $this->assertTrue($subAgent->isComplete());
    }

    public function testIsCompleteWhenStopped(): void
    {
        $subAgent = $this->createSubAgentWithStatus(SubAgent::STATUS_STOPPED);
        $this->assertFalse($subAgent->isComplete());
    }

    // -------------------------------------------------------------------------
    // isStopped()
    // -------------------------------------------------------------------------

    public function testIsStoppedWhenPending(): void
    {
        $subAgent = $this->createSubAgentWithStatus(SubAgent::STATUS_PENDING);
        $this->assertFalse($subAgent->isStopped());
    }

    public function testIsStoppedWhenRunning(): void
    {
        $subAgent = $this->createSubAgentWithStatus(SubAgent::STATUS_RUNNING);
        $this->assertFalse($subAgent->isStopped());
    }

    public function testIsStoppedWhenStopped(): void
    {
        $subAgent = $this->createSubAgentWithStatus(SubAgent::STATUS_STOPPED);
        $this->assertTrue($subAgent->isStopped());
    }

    public function testIsStoppedWhenFailed(): void
    {
        $subAgent = $this->createSubAgentWithStatus(SubAgent::STATUS_FAILED);
        $this->assertTrue($subAgent->isStopped());
    }

    public function testIsStoppedWhenComplete(): void
    {
        $subAgent = $this->createSubAgentWithStatus(SubAgent::STATUS_COMPLETE);
        $this->assertFalse($subAgent->isStopped());
    }

    // -------------------------------------------------------------------------
    // durationMs()
    // -------------------------------------------------------------------------

    public function testDurationMsWhenNotComplete(): void
    {
        $subAgent = $this->createSubAgentWithStatus(SubAgent::STATUS_PENDING);
        $this->assertNull($subAgent->durationMs());
    }

    public function testDurationMsWhenComplete(): void
    {
        $agent = $this->createAgent();
        $createdAt = new \DateTimeImmutable('2024-01-15T10:00:00Z');
        $completedAt = new \DateTimeImmutable('2024-01-15T10:00:05Z');

        $subAgent = new SubAgent(
            id: 'duration_test',
            agent: $agent,
            task: 'Duration test task',
            createdAt: $createdAt,
        );

        // Manually set status and completedAt for testing
        $subAgent->status = SubAgent::STATUS_COMPLETE;
        $subAgent->completedAt = $completedAt;

        $this->assertSame(5000, $subAgent->durationMs());
    }

    // -------------------------------------------------------------------------
    // toArray()
    // -------------------------------------------------------------------------

    public function testToArray(): void
    {
        $agent = $this->createAgent('test-agent');
        $createdAt = new \DateTimeImmutable('2024-01-15T10:00:00Z');

        $subAgent = new SubAgent(
            id: 'toarray_test',
            agent: $agent,
            task: 'Task to convert',
            createdAt: $createdAt,
        );

        $subAgent->status = SubAgent::STATUS_COMPLETE;
        $subAgent->output = 'Task completed successfully';
        $subAgent->completedAt = new \DateTimeImmutable('2024-01-15T10:00:05Z');

        $array = $subAgent->toArray();

        $this->assertIsArray($array);
        $this->assertSame('toarray_test', $array['id']);
        $this->assertSame('test-agent', $array['agent']); // From mock agent name
        $this->assertSame('Task to convert', $array['task']);
        $this->assertSame(SubAgent::STATUS_COMPLETE, $array['status']);
        $this->assertSame('Task completed successfully', $array['output']);
        $this->assertSame('2024-01-15T10:00:00+00:00', $array['created_at']);
        $this->assertSame('2024-01-15T10:00:05+00:00', $array['completed_at']);
        $this->assertNull($array['error']);
    }

    public function testToArrayWithError(): void
    {
        $agent = $this->createAgent();

        $subAgent = new SubAgent(
            id: 'error_test',
            agent: $agent,
            task: 'Task that failed',
        );

        $subAgent->status = SubAgent::STATUS_FAILED;
        $subAgent->error = 'Connection timeout';

        $array = $subAgent->toArray();

        $this->assertSame(SubAgent::STATUS_FAILED, $array['status']);
        $this->assertSame('Connection timeout', $array['error']);
    }

    public function testToArrayWithNullCompletedAt(): void
    {
        $agent = $this->createAgent();

        $subAgent = new SubAgent(
            id: 'pending_test',
            agent: $agent,
            task: 'Pending task',
        );

        $array = $subAgent->toArray();

        $this->assertNull($array['completed_at']);
    }

    // -------------------------------------------------------------------------
    // Helper methods
    // -------------------------------------------------------------------------

    private function createAgent(?string $name = null): Agent
    {
        return new Agent(
            name: $name ?? 'test-agent',
            description: 'Test agent description',
            prompt: 'You are a test agent.',
            model: 'claude-sonnet-4-6',
            provider: 'anthropic',
            tools: [],
            skillNames: [],
            hooks: [],
            isActive: true,
        );
    }

    private function createSubAgentWithStatus(string $status): SubAgent
    {
        $agent = $this->createAgent();

        $subAgent = new SubAgent(
            id: 'status_test_' . uniqid(),
            agent: $agent,
            task: 'Status test task',
        );

        $subAgent->status = $status;

        return $subAgent;
    }
}
