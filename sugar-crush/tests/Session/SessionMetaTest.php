<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Session;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Session\SessionMeta;

/**
 * @see SessionMeta
 */
final class SessionMetaTest extends TestCase
{
    // =========================================================================
    // Constructor Tests
    // =========================================================================

    public function testConstructorSetsAllProperties(): void
    {
        $lastActivity = new \DateTimeImmutable('2026-08-06 12:00:00');
        $tasks = ['task-1', 'task-2'];
        $modifiedFiles = ['src/Chat.php', 'src/Agents/AgentManager.php'];
        $agentStates = ['agent-1' => ['status' => 'running', 'turns' => 5]];

        $meta = new SessionMeta(
            sessionId: 'session-abc',
            summary: 'Working on agent implementation',
            tasks: $tasks,
            modifiedFiles: $modifiedFiles,
            agentStates: $agentStates,
            lastActivity: $lastActivity,
        );

        $this->assertSame('session-abc', $meta->sessionId);
        $this->assertSame('Working on agent implementation', $meta->summary);
        $this->assertSame($tasks, $meta->tasks);
        $this->assertSame($modifiedFiles, $meta->modifiedFiles);
        $this->assertSame($agentStates, $meta->agentStates);
        $this->assertSame($lastActivity, $meta->lastActivity);
    }

    // =========================================================================
    // ::new() Factory Tests
    // =========================================================================

    public function testNewFactoryWithDefaults(): void
    {
        $meta = SessionMeta::new('session-123');

        $this->assertSame('session-123', $meta->sessionId);
        $this->assertSame('', $meta->summary);
        $this->assertSame([], $meta->tasks);
        $this->assertSame([], $meta->modifiedFiles);
        $this->assertSame([], $meta->agentStates);
        $this->assertInstanceOf(\DateTimeImmutable::class, $meta->lastActivity);
    }

    public function testNewFactoryWithAllParameters(): void
    {
        $lastActivity = new \DateTimeImmutable('2026-08-06 14:30:00');
        $tasks = ['implement-agent', 'write-tests'];
        $modifiedFiles = ['src/Agents/AgentWorkerPool.php'];
        $agentStates = ['coder' => ['status' => 'idle']];

        $meta = SessionMeta::new(
            sessionId: 'session-xyz',
            summary: 'Phase 1 agent pool implementation',
            tasks: $tasks,
            modifiedFiles: $modifiedFiles,
            agentStates: $agentStates,
            lastActivity: $lastActivity,
        );

        $this->assertSame('session-xyz', $meta->sessionId);
        $this->assertSame('Phase 1 agent pool implementation', $meta->summary);
        $this->assertSame($tasks, $meta->tasks);
        $this->assertSame($modifiedFiles, $meta->modifiedFiles);
        $this->assertSame($agentStates, $meta->agentStates);
        $this->assertSame($lastActivity, $meta->lastActivity);
    }

    public function testNewFactoryLastActivityDefaultsToNow(): void
    {
        $before = new \DateTimeImmutable();
        $meta = SessionMeta::new('session-default');
        $after = new \DateTimeImmutable();

        $this->assertGreaterThanOrEqual($before, $meta->lastActivity);
        $this->assertLessThanOrEqual($after, $meta->lastActivity);
    }

    // =========================================================================
    // withSummary() Tests
    // =========================================================================

    public function testWithSummaryReturnsNewInstance(): void
    {
        $original = SessionMeta::new('session-1', 'Original summary');
        $new = $original->withSummary('Updated summary');

        $this->assertNotSame($original, $new);
        $this->assertSame('Original summary', $original->summary);
        $this->assertSame('Updated summary', $new->summary);
    }

    public function testWithSummaryPreservesOtherFields(): void
    {
        $tasks = ['task-a', 'task-b'];
        $modifiedFiles = ['file-1.php', 'file-2.php'];
        $agentStates = ['agent-x' => ['turns' => 10]];
        $lastActivity = new \DateTimeImmutable('2026-08-06 10:00:00');

        $original = new SessionMeta(
            sessionId: 'session-preserve',
            summary: 'Original',
            tasks: $tasks,
            modifiedFiles: $modifiedFiles,
            agentStates: $agentStates,
            lastActivity: $lastActivity,
        );

        $new = $original->withSummary('New Summary');

        $this->assertSame('session-preserve', $new->sessionId);
        $this->assertSame('New Summary', $new->summary);
        $this->assertSame($tasks, $new->tasks);
        $this->assertSame($modifiedFiles, $new->modifiedFiles);
        $this->assertSame($agentStates, $new->agentStates);
        $this->assertSame($lastActivity, $new->lastActivity);
    }

    // =========================================================================
    // withTasks() Tests
    // =========================================================================

    public function testWithTasksReturnsNewInstance(): void
    {
        $original = SessionMeta::new('session-1');
        $newTasks = ['new-task-1', 'new-task-2'];
        $new = $original->withTasks($newTasks);

        $this->assertNotSame($original, $new);
        $this->assertSame([], $original->tasks);
        $this->assertSame($newTasks, $new->tasks);
    }

    public function testWithTasksPreservesOtherFields(): void
    {
        $original = SessionMeta::new(
            sessionId: 'session-tasks',
            summary: 'Summary',
            modifiedFiles: ['file.php'],
            agentStates: ['agent' => ['status' => 'active']],
        );

        $newTasks = ['task-1', 'task-2', 'task-3'];
        $new = $original->withTasks($newTasks);

        $this->assertSame($newTasks, $new->tasks);
        $this->assertSame('session-tasks', $new->sessionId);
        $this->assertSame('Summary', $new->summary);
    }

    // =========================================================================
    // withModifiedFiles() Tests
    // =========================================================================

    public function testWithModifiedFilesReturnsNewInstance(): void
    {
        $original = SessionMeta::new('session-1');
        $newFiles = ['src/NewFile.php'];
        $new = $original->withModifiedFiles($newFiles);

        $this->assertNotSame($original, $new);
        $this->assertSame([], $original->modifiedFiles);
        $this->assertSame($newFiles, $new->modifiedFiles);
    }

    // =========================================================================
    // withAgentStates() Tests
    // =========================================================================

    public function testWithAgentStatesReturnsNewInstance(): void
    {
        $original = SessionMeta::new('session-1');
        $newStates = ['teammate-1' => ['status' => 'running', 'turns' => 15]];
        $new = $original->withAgentStates($newStates);

        $this->assertNotSame($original, $new);
        $this->assertSame([], $original->agentStates);
        $this->assertSame($newStates, $new->agentStates);
    }

    // =========================================================================
    // withLastActivity() Tests
    // =========================================================================

    public function testWithLastActivityReturnsNewInstance(): void
    {
        $original = SessionMeta::new('session-1');
        $newTime = new \DateTimeImmutable('2026-08-06 18:00:00');
        $new = $original->withLastActivity($newTime);

        $this->assertNotSame($original, $new);
        $this->assertNotSame($original->lastActivity, $new->lastActivity);
        $this->assertSame($newTime, $new->lastActivity);
    }

    // =========================================================================
    // Immutability Tests
    // =========================================================================

    public function testAllWithMethodsReturnNewInstances(): void
    {
        $meta = SessionMeta::new('session-immutable');

        $methods = [
            'withSummary' => ['new summary'],
            'withTasks' => [['task-1']],
            'withModifiedFiles' => [['file.php']],
            'withAgentStates' => [['agent' => ['status' => 'active']]],
            'withLastActivity' => [new \DateTimeImmutable()],
        ];

        foreach ($methods as $method => $args) {
            $result = $meta->$method(...$args);
            $this->assertNotSame($meta, $result, "{$method} should return a new instance");
            $this->assertInstanceOf(SessionMeta::class, $result);
        }
    }

    public function testOriginalInstanceUnchangedAfterWithCalls(): void
    {
        $meta = SessionMeta::new(
            sessionId: 'session-original',
            summary: 'original-summary',
            tasks: ['original-task'],
            modifiedFiles: ['original-file.php'],
            agentStates: ['original-agent' => ['turns' => 1]],
        );

        $meta->withSummary('new-summary');
        $meta->withTasks(['new-task']);
        $meta->withModifiedFiles(['new-file.php']);
        $meta->withAgentStates(['new-agent' => ['turns' => 2]]);
        $meta->withLastActivity(new \DateTimeImmutable());

        $this->assertSame('session-original', $meta->sessionId);
        $this->assertSame('original-summary', $meta->summary);
        $this->assertSame(['original-task'], $meta->tasks);
        $this->assertSame(['original-file.php'], $meta->modifiedFiles);
        $this->assertSame(['original-agent' => ['turns' => 1]], $meta->agentStates);
    }

    // =========================================================================
    // Edge Cases
    // =========================================================================

    public function testEmptyArraysArePreserved(): void
    {
        $meta = SessionMeta::new(
            sessionId: 'session-empty',
            tasks: [],
            modifiedFiles: [],
            agentStates: [],
        );

        $this->assertSame([], $meta->tasks);
        $this->assertSame([], $meta->modifiedFiles);
        $this->assertSame([], $meta->agentStates);
    }

    public function testComplexNestedArraysArePreserved(): void
    {
        $complexTasks = [
            'project-a' => [
                'subtasks' => [
                    ['id' => 1, 'title' => 'First subtask'],
                    ['id' => 2, 'title' => 'Second subtask'],
                ],
                'status' => 'in_progress',
            ],
        ];
        $complexAgentStates = [
            'agent-team' => [
                'members' => ['alice', 'bob'],
                'roles' => ['coder' => 'alice', 'reviewer' => 'bob'],
                'active' => true,
            ],
        ];

        $meta = SessionMeta::new(
            sessionId: 'session-complex',
            tasks: $complexTasks,
            agentStates: $complexAgentStates,
        );

        $this->assertSame($complexTasks, $meta->tasks);
        $this->assertSame($complexAgentStates, $meta->agentStates);
    }

    // =========================================================================
    // toArray() Tests
    // =========================================================================

    public function testToArrayReturnsAllFields(): void
    {
        $lastActivity = new \DateTimeImmutable('2026-08-06 15:30:00');
        $tasks = ['task-1', 'task-2'];
        $modifiedFiles = ['src/Chat.php'];
        $agentStates = ['agent-1' => ['status' => 'running']];

        $meta = new SessionMeta(
            sessionId: 'session-to-array',
            summary: 'Test summary',
            tasks: $tasks,
            modifiedFiles: $modifiedFiles,
            agentStates: $agentStates,
            lastActivity: $lastActivity,
        );

        $array = $meta->toArray();

        $this->assertSame('session-to-array', $array['sessionId']);
        $this->assertSame('Test summary', $array['summary']);
        $this->assertSame($tasks, $array['tasks']);
        $this->assertSame($modifiedFiles, $array['modifiedFiles']);
        $this->assertSame($agentStates, $array['agentStates']);
        $this->assertSame($lastActivity->format(\DateTimeInterface::ATOM), $array['lastActivity']);
    }

    public function testToArrayWithEmptyArrays(): void
    {
        $meta = SessionMeta::new(
            sessionId: 'session-empty-arrays',
            summary: 'Empty arrays test',
        );

        $array = $meta->toArray();

        $this->assertSame([], $array['tasks']);
        $this->assertSame([], $array['modifiedFiles']);
        $this->assertSame([], $array['agentStates']);
    }

    // =========================================================================
    // fromArray() Tests
    // =========================================================================

    public function testFromArrayReconstructsSessionMeta(): void
    {
        $data = [
            'sessionId' => 'session-reconstructed',
            'summary' => 'Reconstructed summary',
            'tasks' => ['task-a', 'task-b'],
            'modifiedFiles' => ['file-a.php', 'file-b.php'],
            'agentStates' => ['agent-x' => ['turns' => 5]],
            'lastActivity' => '2026-08-06T16:00:00+00:00',
        ];

        $meta = SessionMeta::fromArray($data);

        $this->assertSame('session-reconstructed', $meta->sessionId);
        $this->assertSame('Reconstructed summary', $meta->summary);
        $this->assertSame(['task-a', 'task-b'], $meta->tasks);
        $this->assertSame(['file-a.php', 'file-b.php'], $meta->modifiedFiles);
        $this->assertSame(['agent-x' => ['turns' => 5]], $meta->agentStates);
        $this->assertSame('2026-08-06', $meta->lastActivity->format('Y-m-d'));
    }

    public function testFromArrayWithMinimalData(): void
    {
        $data = [
            'sessionId' => 'session-minimal',
            'lastActivity' => '2026-08-06T12:00:00+00:00',
        ];

        $meta = SessionMeta::fromArray($data);

        $this->assertSame('session-minimal', $meta->sessionId);
        $this->assertSame('', $meta->summary);
        $this->assertSame([], $meta->tasks);
        $this->assertSame([], $meta->modifiedFiles);
        $this->assertSame([], $meta->agentStates);
    }

    public function testFromArrayRoundTrip(): void
    {
        $original = SessionMeta::new(
            sessionId: 'session-round-trip',
            summary: 'Round trip test',
            tasks: ['task-1', 'task-2', 'task-3'],
            modifiedFiles: ['src/A.php', 'src/B.php'],
            agentStates: ['agent-1' => ['status' => 'active', 'turns' => 10]],
            lastActivity: new \DateTimeImmutable('2026-08-06T18:30:00+00:00'),
        );

        $array = $original->toArray();
        $reconstructed = SessionMeta::fromArray($array);

        $this->assertSame($original->sessionId, $reconstructed->sessionId);
        $this->assertSame($original->summary, $reconstructed->summary);
        $this->assertSame($original->tasks, $reconstructed->tasks);
        $this->assertSame($original->modifiedFiles, $reconstructed->modifiedFiles);
        $this->assertSame($original->agentStates, $reconstructed->agentStates);
        $this->assertSame($original->lastActivity->format(\DateTimeInterface::ATOM), $reconstructed->lastActivity->format(\DateTimeInterface::ATOM));
    }
}
