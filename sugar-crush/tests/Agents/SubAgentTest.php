<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Agents;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Agents\Agent;
use SugarCraft\Crush\Agents\Isolation;
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
        $this->assertSame(300, $subAgent->timeout);
        $this->assertSame(0, $subAgent->maxRetries);
        $this->assertSame(Isolation::None, $subAgent->isolation);
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

    public function testConstructorWithCustomTimeoutMaxRetriesAndIsolation(): void
    {
        $agent = $this->createAgent();
        $createdAt = new \DateTimeImmutable('2024-01-15T10:00:00Z');

        $subAgent = new SubAgent(
            id: 'custom_fields_test',
            agent: $agent,
            task: 'Task with custom config',
            createdAt: $createdAt,
            timeout: 600,
            maxRetries: 3,
            isolation: Isolation::Worktree,
        );

        $this->assertSame('custom_fields_test', $subAgent->id);
        $this->assertSame($agent, $subAgent->agent);
        $this->assertSame('Task with custom config', $subAgent->task);
        $this->assertSame($createdAt, $subAgent->createdAt);
        $this->assertSame(600, $subAgent->timeout);
        $this->assertSame(3, $subAgent->maxRetries);
        $this->assertSame(Isolation::Worktree, $subAgent->isolation);
    }

    public function testConstructorWithTeamAndTeammateIds(): void
    {
        $agent = $this->createAgent();
        $createdAt = new \DateTimeImmutable('2024-01-15T10:00:00Z');

        $subAgent = new SubAgent(
            id: 'team_subagent_test',
            agent: $agent,
            task: 'Task with team association',
            createdAt: $createdAt,
            teamId: 'team_abc123',
            teammateId: 'teammate_xyz789',
        );

        $this->assertSame('team_abc123', $subAgent->teamId);
        $this->assertSame('teammate_xyz789', $subAgent->teammateId);
        $this->assertSame('team_subagent_test', $subAgent->id);
        $this->assertSame($agent, $subAgent->agent);
        $this->assertSame('Task with team association', $subAgent->task);
    }

    public function testConstructorWithNullTeamAndTeammateIdsByDefault(): void
    {
        $agent = $this->createAgent();

        $subAgent = new SubAgent(
            id: 'no_team_test',
            agent: $agent,
            task: 'Task without team',
        );

        $this->assertNull($subAgent->teamId);
        $this->assertNull($subAgent->teammateId);
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
        $this->assertSame(300, $array['timeout']);
        $this->assertSame(0, $array['max_retries']);
        $this->assertSame('none', $array['isolation']);
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

    public function testToArrayWithTeamAndTeammateIds(): void
    {
        $agent = $this->createAgent('team-agent');
        $createdAt = new \DateTimeImmutable('2024-01-15T10:00:00Z');

        $subAgent = new SubAgent(
            id: 'toarray_team_test',
            agent: $agent,
            task: 'Team task',
            createdAt: $createdAt,
            teamId: 'team_abc',
            teammateId: 'teammate_xyz',
        );

        $array = $subAgent->toArray();

        $this->assertIsArray($array);
        $this->assertSame('toarray_team_test', $array['id']);
        $this->assertSame('team-agent', $array['agent']);
        $this->assertSame('Team task', $array['task']);
        $this->assertSame('team_abc', $array['team_id']);
        $this->assertSame('teammate_xyz', $array['teammate_id']);
    }

    // -------------------------------------------------------------------------
    // elapsedSeconds() -- crush_feat.md §5 E6
    // -------------------------------------------------------------------------

    public function testElapsedSecondsIsZeroBeforeExecutionStarts(): void
    {
        $subAgent = new SubAgent(
            id: 'elapsed_pending',
            agent: $this->createAgent(),
            task: 'Not started yet',
        );

        $this->assertNull($subAgent->startedAt);
        $this->assertSame(0, $subAgent->elapsedSeconds());
    }

    public function testElapsedSecondsMeasuresFromStartNotCreation(): void
    {
        // createdAt is 10 minutes before startedAt: queue time must not be
        // counted as work time.
        $subAgent = new SubAgent(
            id: 'elapsed_queued',
            agent: $this->createAgent(),
            task: 'Queued a while',
            createdAt: new \DateTimeImmutable('2024-01-15T10:00:00Z'),
        );

        $subAgent->startedAt = new \DateTimeImmutable('2024-01-15T10:10:00Z');
        $subAgent->completedAt = new \DateTimeImmutable('2024-01-15T10:10:20Z');

        $this->assertSame(20, $subAgent->elapsedSeconds());
    }

    public function testElapsedSecondsCountsAgainstNowWhileRunning(): void
    {
        $subAgent = new SubAgent(
            id: 'elapsed_running',
            agent: $this->createAgent(),
            task: 'Still going',
        );

        $subAgent->status = SubAgent::STATUS_STREAMING;
        $subAgent->startedAt = (new \DateTimeImmutable())->modify('-45 seconds');

        $this->assertGreaterThanOrEqual(45, $subAgent->elapsedSeconds());
    }

    public function testElapsedSecondsFreezesOnceTerminal(): void
    {
        $subAgent = new SubAgent(
            id: 'elapsed_stopped',
            agent: $this->createAgent(),
            task: 'Killed mid-flight',
        );

        $subAgent->status = SubAgent::STATUS_STOPPED;
        $subAgent->startedAt = (new \DateTimeImmutable())->modify('-2400 seconds');
        $subAgent->completedAt = (new \DateTimeImmutable())->modify('-2390 seconds');

        // A sub-agent that died 40 minutes ago must report the 10s it actually
        // worked, not an ever-growing wall-clock figure.
        $this->assertSame(10, $subAgent->elapsedSeconds());
        $this->assertSame(10, $subAgent->elapsedSeconds());
    }

    public function testTelemetryFieldsAppearInToArray(): void
    {
        $subAgent = new SubAgent(
            id: 'elapsed_array',
            agent: $this->createAgent(),
            task: 'Telemetry',
        );

        $subAgent->startedAt = new \DateTimeImmutable('2024-01-15T10:00:00Z');
        $subAgent->completedAt = new \DateTimeImmutable('2024-01-15T10:00:05Z');
        $subAgent->tokensUsed = 77;
        $subAgent->costUsd = 1.25;

        $array = $subAgent->toArray();

        $this->assertSame('2024-01-15T10:00:00+00:00', $array['started_at']);
        $this->assertSame(5, $array['elapsed_seconds']);
        $this->assertSame(77, $array['tokens_used']);
        $this->assertSame(1.25, $array['cost_usd']);
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
            id: 'status_test_' . uniqid((string) getmypid(), true),
            agent: $agent,
            task: 'Status test task',
        );

        $subAgent->status = $status;

        return $subAgent;
    }
}
