<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Agents;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Agents\AgentType;
use SugarCraft\Crush\Agents\Mailbox;
use SugarCraft\Crush\Agents\TaskList;
use SugarCraft\Crush\Agents\Teammate;
use SugarCraft\Crush\Agents\Team;
use SugarCraft\Crush\Agents\WorktreeConfig;
use SugarCraft\Crush\Agents\WorktreeManager;

/**
 * Tests for Team - aggregate root for lead + teammates coordination.
 *
 * HOME is redirected to a sandbox for the whole class, same convention as
 * EngineBackendParallelConfigTest: a Team persists its task list and mailbox
 * under ~/.sugar-crush/teams/{id}/ the moment it is constructed, and every
 * test below constructs one.
 */
final class TeamTest extends TestCase
{
    /** @var list<string> temp dirs created by createRealWorktreeManager(), cleaned up in tearDown() */
    private array $tmpDirsToClean = [];

    /** The throwaway root holding this test's sandbox HOME. */
    private string $sandboxDir;

    /** The developer's actual home, kept so tearDown() can check it is untouched. */
    private string $realHome;

    private string $originalHome;

    private ?string $originalServerHome = null;

    /** @var list<string> */
    private array $realHomeFootprint = [];

    /**
     * Per-process attribution token (E281): every Team fixture id is built
     * through {@see teamId()} so it starts with this value, and the footprint
     * guard only counts entries that start with it.
     */
    private string $processToken = '';

    protected function setUp(): void
    {
        parent::setUp();

        $this->sandboxDir = sys_get_temp_dir() . '/sc_team_' . bin2hex(random_bytes(6));
        mkdir($this->sandboxDir . '/home', 0o700, true);

        // BEFORE the snapshot: the very first realHomeFootprint() call already
        // filters by this token.
        $this->processToken = uniqid((string) getmypid(), true);

        $this->originalHome = getenv('HOME') ?: '';
        $this->originalServerHome = isset($_SERVER['HOME']) ? (string) $_SERVER['HOME'] : null;
        $this->realHome = $this->originalServerHome ?? $this->originalHome;
        $this->realHomeFootprint = $this->realHomeFootprint();

        // BOTH have to move: Team::basePath() reads $_SERVER['HOME'] while
        // Bootstrap reads getenv('HOME'), so redirecting one leaves the other
        // pointing at the real home.
        putenv('HOME=' . $this->sandboxDir . '/home');
        $_SERVER['HOME'] = $this->sandboxDir . '/home';
    }

    protected function tearDown(): void
    {
        if ($this->originalServerHome === null) {
            unset($_SERVER['HOME']);
        } else {
            $_SERVER['HOME'] = $this->originalServerHome;
        }
        $this->originalHome === '' ? putenv('HOME') : putenv('HOME=' . $this->originalHome);

        foreach ($this->tmpDirsToClean as $dir) {
            if (is_dir($dir)) {
                $this->removeDirectory($dir);
            }
        }
        $this->removeDirectory($this->sandboxDir);

        $this->assertSame(
            $this->realHomeFootprint,
            $this->realHomeFootprint(),
            'a Team test wrote into the real ~/.sugar-crush instead of its sandbox HOME',
        );

        parent::tearDown();
    }

    /**
     * Everything under the real ~/.sugar-crush THIS PROCESS's Teams could
     * create: the config dir's own entries, so conjuring the directory itself
     * is caught, plus the names directly under teams/ that carry this
     * process's token (E281).
     *
     * WHY THE TEAMS LEVEL IS ATTRIBUTED AND NOT JUST COUNTED: the home is
     * shared across concurrent lanes, and siblings leak. MEASURED at the time
     * of this fix, the real tree carried thousands of leftover team dirs from
     * non-pid-prefixed fixtures elsewhere (the found origin is
     * `tests/Integration/MultiAgentRefactorTest.php` writing
     * `'throwing-' . uniqid('', true)` — a file outside this one's ownership).
     * An unfiltered before/after diff let any sibling's leak between the two
     * snapshots redden THIS file for a bug it does not have. Attribution
     * requires the name: every Team id here now flows through
     * {@see teamId()}, and only tokens matching this process are compared.
     *
     * The `teams/` directory itself is excluded at the config-dir level: its
     * existence is structural — a sibling creating it concurrently is the
     * exact false positive this item removes — while anything THIS process
     * writes under it is caught by token at the leaf.
     *
     * Deliberately shallow: the residue is one new entry per Team, so a
     * recursive walk buys nothing and costs a full tree scan twice per test.
     *
     * @return list<string>
     */
    private function realHomeFootprint(): array
    {
        $configDir = $this->realHome . '/.sugar-crush';

        $entries = array_values(array_filter(
            self::entriesOf($configDir),
            static fn(string $path): bool => basename($path) !== 'teams',
        ));

        foreach (self::entriesOf($configDir . '/teams') as $teamEntry) {
            if ($this->isOwnTeamsEntry($teamEntry)) {
                $entries[] = $teamEntry;
            }
        }

        return $entries;
    }

    /**
     * True when a `teams/<name>` entry is attributed to this process — the
     * classification decision of {@see realHomeFootprint()}, extracted so the
     * fixture table below can pin both polarities (E322 rule: a scan is not
     * tested until a known offender is proven to be caught by it — and its
     * mirror, until a known foreign name is proven NOT to be).
     */
    private function isOwnTeamsEntry(string $path): bool
    {
        return str_starts_with(basename($path), $this->processToken);
    }

    /**
     * A fixture Team id, attributed to this process. Idempotent: passing an
     * already-tokenised id (e.g. a `$team->id` handed back to a teammate
     * factory) returns it untouched, so both call shapes stay consistent.
     */
    private function teamId(string $name): string
    {
        return str_starts_with($name, $this->processToken)
            ? $name
            : $this->processToken . '-' . $name;
    }

    public function testTheFootprintAttributesOnlyThisProcessesTeamEntries(): void
    {
        $teamsDir = $this->realHome . '/.sugar-crush/teams';

        $cases = [
            // This process's own fixtures, as teamId() builds them.
            $teamsDir . '/' . $this->teamId('team-alpha')            => true,
            // The residue shapes this guard used to false-alarm on.
            $teamsDir . '/throwing-68d1f2a3b4c5d6e7.89012345'        => false,
            $teamsDir . '/team-alpha'                                => false,
            $teamsDir . '/team-tasklist-' . uniqid((string) (getmypid() + 1000), true) => false,
            // (Traversal-shaped ids never reach disk — Team throws first — so
            // the leaf-basename rule below only ever sees single-segment names.)
        ];

        foreach ($cases as $path => $expected) {
            $this->assertSame(
                $expected,
                $this->isOwnTeamsEntry($path),
                'attribution of ' . basename($path) . ' went the wrong way',
            );
        }
    }

    /**
     * @return list<string>
     */
    private static function entriesOf(string $dir): array
    {
        if (!is_dir($dir)) {
            return [];
        }

        $entries = array_values(array_diff(scandir($dir) ?: [], ['.', '..']));
        sort($entries);

        return array_map(static fn(string $entry): string => $dir . '/' . $entry, $entries);
    }

    // -------------------------------------------------------------------------
    // Construction and property access
    // -------------------------------------------------------------------------

    public function testConstructionWithAllFields(): void
    {
        $createdAt = new \DateTimeImmutable('2026-01-15T10:00:00Z');
        $team = new Team(
            id: $id = $this->teamId('team-alpha'),
            name: 'Alpha Squad',
            leadAgentId: 'lead-001',
            createdAt: $createdAt,
        );

        $this->assertSame($id, $team->id);
        $this->assertSame('Alpha Squad', $team->name);
        $this->assertSame('lead-001', $team->leadAgentId);
        $this->assertSame($createdAt, $team->createdAt);
    }

    public function testConstructionWithMinimalFields(): void
    {
        $createdAt = new \DateTimeImmutable();
        $team = new Team(
            id: $id = $this->teamId('team-beta'),
            name: 'Beta Team',
            leadAgentId: 'lead-002',
            createdAt: $createdAt,
        );

        $this->assertSame($id, $team->id);
        $this->assertSame('Beta Team', $team->name);
        $this->assertSame('lead-002', $team->leadAgentId);
        $this->assertSame($createdAt, $team->createdAt);
    }

    public function testDefaultMaxTeammatesIsFive(): void
    {
        $team = new Team(
            id: $this->teamId('team-default-cap'),
            name: 'Default Cap Team',
            leadAgentId: 'lead-default-cap',
            createdAt: new \DateTimeImmutable(),
        );

        $this->assertSame(5, $team->maxTeammates);
    }

    // -------------------------------------------------------------------------
    // getTeammates()
    // -------------------------------------------------------------------------

    public function testGetTeammatesInitiallyEmpty(): void
    {
        $team = new Team(
            id: $this->teamId('team-empty'),
            name: 'Empty Team',
            leadAgentId: 'lead-empty',
            createdAt: new \DateTimeImmutable(),
        );

        $this->assertSame([], $team->getTeammates());
    }

    public function testGetTeammatesReturnsAllAddedTeammates(): void
    {
        $team = $this->createTeam('team-multi');

        $teammate1 = $this->createTeammate('tm-1', 'team-multi', 'Alice', AgentType::Coder);
        $teammate2 = $this->createTeammate('tm-2', 'team-multi', 'Bob', AgentType::Reviewer);

        $team->addTeammate($teammate1);
        $team->addTeammate($teammate2);

        $teammates = $team->getTeammates();
        $this->assertCount(2, $teammates);
        $this->assertSame($teammate1, $teammates[0]);
        $this->assertSame($teammate2, $teammates[1]);
    }

    // -------------------------------------------------------------------------
    // addTeammate()
    // -------------------------------------------------------------------------

    public function testAddTeammateSucceedsForMatchingTeamId(): void
    {
        $team = $this->createTeam('team-add');
        $teammate = $this->createTeammate('tm-add-1', 'team-add', 'Carol', AgentType::Tester);

        $team->addTeammate($teammate);

        $this->assertCount(1, $team->getTeammates());
        $this->assertSame($teammate, $team->getTeammates()[0]);
    }

    public function testAddTeammateThrowsForMismatchedTeamId(): void
    {
        $team = $this->createTeam('team-a');
        $teammate = $this->createTeammate('tm-wrong', 'team-b', 'Dan', AgentType::Coder);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Teammate tm-wrong does not belong to team ' . $this->teamId('team-a'));

        $team->addTeammate($teammate);
    }

    public function testAddTeammateOverwritesExistingWithSameId(): void
    {
        $team = $this->createTeam('team-overwrite');

        // Two different objects with the same identity
        $original = $this->createTeammate('tm-same', 'team-overwrite', 'Original', AgentType::Coder);
        $replacement = new Teammate(
            id: 'tm-same',
            teamId: $this->teamId('team-overwrite'),
            name: 'Replacement',
            type: AgentType::Reviewer,
            model: 'claude-sonnet-4-6',
            tools: ['Read', 'Edit', 'Bash'],
        );

        $team->addTeammate($original);
        $this->assertCount(1, $team->getTeammates());
        $this->assertSame('Original', $team->getTeammates()[0]->name);

        $team->addTeammate($replacement);
        $this->assertCount(1, $team->getTeammates());
        $this->assertSame('Replacement', $team->getTeammates()[0]->name);
    }

    // -------------------------------------------------------------------------
    // addTeammate() — maxTeammates cap (R6)
    // -------------------------------------------------------------------------

    public function testAddTeammateThrowsWhenAtMaxCapacity(): void
    {
        $team = $this->createTeam('team-capped', maxTeammates: 2);

        $team->addTeammate($this->createTeammate('tm-cap-1', 'team-capped', 'One', AgentType::Coder));
        $team->addTeammate($this->createTeammate('tm-cap-2', 'team-capped', 'Two', AgentType::Reviewer));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('team-capped');

        // Third teammate must be rejected — this is the original bug: without
        // enforcement, this call previously always succeeded regardless of maxTeammates.
        $team->addTeammate($this->createTeammate('tm-cap-3', 'team-capped', 'Three', AgentType::Tester));
    }

    public function testAddTeammateStaysAtCapacityAfterRejectedAdd(): void
    {
        $team = $this->createTeam('team-capped-count', maxTeammates: 1);
        $team->addTeammate($this->createTeammate('tm-only', 'team-capped-count', 'Only', AgentType::Coder));

        // THE `fail()` IS OUTSIDE THE `try`, AND THAT IS THE WHOLE POINT (E510).
        // It used to sit on the line after the call, under a
        // `catch (\RuntimeException) { // expected }` — and MEASURED on PHP
        // 8.3.6, PHPUnit 10.5.64, `AssertionFailedError` (what `fail()` throws)
        // extends `PHPUnit\Framework\Exception` extends `\RuntimeException`.
        // So the catch swallowed the refusal-to-throw it was written to report,
        // into an EMPTY body, and this test's own diagnostic vanished. The
        // assertions below happened to red anyway; that is luck, not coverage.
        $refused = false;
        try {
            $team->addTeammate($this->createTeammate('tm-extra', 'team-capped-count', 'Extra', AgentType::Coder));
        } catch (\RuntimeException) {
            $refused = true;
        }
        $this->assertTrue($refused, 'Expected addTeammate() to throw once at capacity.');

        $this->assertCount(1, $team->getTeammates());
        $this->assertSame('tm-only', $team->getTeammates()[0]->id);
    }

    public function testAddTeammateAllowsReplacementWhileAtCapacity(): void
    {
        $team = $this->createTeam('team-capped-replace', maxTeammates: 1);
        $team->addTeammate($this->createTeammate('tm-slot', 'team-capped-replace', 'First', AgentType::Coder));

        $replacement = new Teammate(
            id: 'tm-slot',
            teamId: $this->teamId('team-capped-replace'),
            name: 'Second',
            type: AgentType::Reviewer,
            model: 'claude-sonnet-4-6',
            tools: ['Read'],
        );

        // Re-adding the SAME id does not grow the team, so it must still be allowed.
        $team->addTeammate($replacement);

        $this->assertCount(1, $team->getTeammates());
        $this->assertSame('Second', $team->getTeammates()[0]->name);
    }

    public function testAddTeammateUpToDefaultCapacitySucceeds(): void
    {
        $team = $this->createTeam('team-default-fill');

        for ($i = 1; $i <= 5; $i++) {
            $team->addTeammate($this->createTeammate("tm-{$i}", 'team-default-fill', "Member {$i}", AgentType::Coder));
        }

        $this->assertCount(5, $team->getTeammates());

        $this->expectException(\RuntimeException::class);
        $team->addTeammate($this->createTeammate('tm-6', 'team-default-fill', 'Member 6', AgentType::Coder));
    }

    // -------------------------------------------------------------------------
    // removeTeammate()
    // -------------------------------------------------------------------------

    public function testRemoveTeammateSucceeds(): void
    {
        $team = $this->createTeam('team-remove');
        $teammate = $this->createTeammate('tm-remove', 'team-remove', 'Eve', AgentType::Devops);

        $team->addTeammate($teammate);
        $this->assertCount(1, $team->getTeammates());

        $team->removeTeammate('tm-remove');
        $this->assertCount(0, $team->getTeammates());
    }

    public function testRemoveTeammateIsIdempotent(): void
    {
        $team = $this->createTeam('team-remove-idempotent');
        $teammate = $this->createTeammate('tm-gone', 'team-remove-idempotent', 'Frank', AgentType::Architect);

        $team->addTeammate($teammate);
        $team->removeTeammate('tm-gone');
        $team->removeTeammate('tm-gone'); // second call must not throw

        $this->assertCount(0, $team->getTeammates());
    }

    public function testRemoveTeammateFromEmptyTeamIsIdempotent(): void
    {
        $team = $this->createTeam('team-remove-empty');

        // Must not throw
        $team->removeTeammate('nonexistent');

        $this->assertCount(0, $team->getTeammates());
    }

    public function testRemoveTeammateOnlyRemovesSpecifiedId(): void
    {
        $team = $this->createTeam('team-remove-selective');

        $alice = $this->createTeammate('tm-alice', 'team-remove-selective', 'Alice', AgentType::Coder);
        $bob = $this->createTeammate('tm-bob', 'team-remove-selective', 'Bob', AgentType::Reviewer);

        $team->addTeammate($alice);
        $team->addTeammate($bob);

        $team->removeTeammate('tm-alice');

        $this->assertCount(1, $team->getTeammates());
        $this->assertSame('tm-bob', $team->getTeammates()[0]->id);
    }

    // -------------------------------------------------------------------------
    // getTaskList()
    // -------------------------------------------------------------------------

    public function testGetTaskListReturnsTaskListInstance(): void
    {
        $team = new Team(
            id: $this->teamId('team-tasklist'),
            name: 'Task List Test',
            leadAgentId: 'lead-tasklist',
            createdAt: new \DateTimeImmutable(),
        );

        $this->assertInstanceOf(TaskList::class, $team->getTaskList());
    }

    public function testGetTaskListReturnsSameInstance(): void
    {
        $team = new Team(
            id: $this->teamId('team-tasklist-same'),
            name: 'Task List Same Test',
            leadAgentId: 'lead-tasklist-same',
            createdAt: new \DateTimeImmutable(),
        );

        $first = $team->getTaskList();
        $second = $team->getTaskList();

        $this->assertSame($first, $second);
    }

    // -------------------------------------------------------------------------
    // getMailbox()
    // -------------------------------------------------------------------------

    public function testGetMailboxReturnsMailboxInstance(): void
    {
        $team = new Team(
            id: $this->teamId('team-mailbox'),
            name: 'Mailbox Test',
            leadAgentId: 'lead-mailbox',
            createdAt: new \DateTimeImmutable(),
        );

        $this->assertInstanceOf(Mailbox::class, $team->getMailbox());
    }

    public function testGetMailboxReturnsSameInstance(): void
    {
        $team = new Team(
            id: $this->teamId('team-mailbox-same'),
            name: 'Mailbox Same Test',
            leadAgentId: 'lead-mailbox-same',
            createdAt: new \DateTimeImmutable(),
        );

        $first = $team->getMailbox();
        $second = $team->getMailbox();

        $this->assertSame($first, $second);
    }

    // -------------------------------------------------------------------------
    // Path traversal guard
    // -------------------------------------------------------------------------

    public function testTeamIdWithPathTraversalThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('path traversal');

        new Team(
            id: '../etc/passwd',
            name: 'Bad Team',
            leadAgentId: 'lead-bad',
            createdAt: new \DateTimeImmutable(),
        );
    }

    public function testTeamIdWithDoubleDotsThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('path traversal');

        new Team(
            id: 'team/../../secrets',
            name: 'Evil Team',
            leadAgentId: 'lead-evil',
            createdAt: new \DateTimeImmutable(),
        );
    }

    // -------------------------------------------------------------------------
    // claimTask()
    // -------------------------------------------------------------------------

    public function testClaimTaskReturnsFalseWhenTeammateNotFound(): void
    {
        $team = $this->createTeam('team-claim-no-tm');

        // No teammates added — claimTask must return false.
        $wm = $this->createRealWorktreeManager();

        $this->assertFalse($team->claimTask('task-1', 'nonexistent', $wm));
    }

    /**
     * E136: when the worktree steps of claimTask() die AFTER the claim
     * committed, the task used to stay `in_progress` forever — unretryable,
     * because TaskList::claimTask() only accepts Pending tasks. The rollback
     * now releases exactly the failed claim and rethrows the original
     * failure. The repro is the entry's own shape: a teammate that already
     * has a worktree, so createWorktree() refuses the second dispatch.
     */
    public function testClaimTaskRollsBackTheClaimWhenWorktreeCreationFails(): void
    {
        $team = $this->createTeam('team-claim-rollback');
        $team->addTeammate($this->createTeammate('tm-rb', $team->id, 'Riley', AgentType::Coder));
        $wm = $this->createRealWorktreeManager();

        $mk = fn(string $n): \SugarCraft\Crush\Agents\Task => new \SugarCraft\Crush\Agents\Task(
            id: $n . '-' . uniqid((string) getmypid(), true),
            teamId: $team->id,
            title: $n,
            description: '',
            prompt: 'do',
            assignedTo: null,
            status: \SugarCraft\Crush\Agents\TaskStatus::Pending,
            result: null,
            error: null,
            createdAt: new \DateTimeImmutable(),
            claimedAt: null,
            completedAt: null,
            dependsOn: [],
            isContested: false,
        );
        $first = $mk('task-rb-1');
        $second = $mk('task-rb-2');
        $team->getTaskList()->addTask($first);
        $team->getTaskList()->addTask($second);

        $this->assertTrue($team->claimTask($first->id, 'tm-rb', $wm), 'first claim must succeed');

        $failure = null;
        try {
            $team->claimTask($second->id, 'tm-rb', $wm);
        } catch (\Throwable $caught) {
            $failure = $caught;
        }

        $this->assertNotNull($failure, 'a teammate whose worktree already exists must fail, not silently double-wire');
        $reloaded = $team->getTaskList()->getTask($second->id);
        $this->assertNotNull($reloaded);
        $this->assertSame(
            \SugarCraft\Crush\Agents\TaskStatus::Pending,
            $reloaded->status,
            'E136: the failed claim is rolled back, not stranded in_progress',
        );
        $this->assertNull($reloaded->assignedTo, 'and the rollback unassigns, so any teammate may retry');

        $this->assertSame(
            \SugarCraft\Crush\Agents\TaskStatus::InProgress,
            $team->getTaskList()->getTask($first->id)?->status,
            'the rollback is scoped to the task whose worktree step failed',
        );
    }

    public function testClaimTaskReturnsFalseWhenTaskAlreadyClaimed(): void
    {
        $team = $this->createTeam('team-claim-taken');

        $teammate = $this->createTeammate('tm-1', $team->id, 'Alice', AgentType::Coder);
        $team->addTeammate($teammate);

        // Pre-add a task that is already in-progress (simulating already-claimed)
        $task = new \SugarCraft\Crush\Agents\Task(
            id: 'task-taken-' . uniqid((string) getmypid(), true),
            teamId: $team->id,
            title: 'Already Claimed',
            description: '',
            prompt: '',
            assignedTo: 'tm-other',
            status: \SugarCraft\Crush\Agents\TaskStatus::InProgress,
            result: null,
            error: null,
            createdAt: new \DateTimeImmutable(),
            claimedAt: new \DateTimeImmutable(),
            completedAt: null,
            dependsOn: [],
            isContested: false,
        );
        $team->getTaskList()->addTask($task);

        $wm = $this->createRealWorktreeManager();

        // The task is already claimed by someone else — must return false
        $this->assertFalse($team->claimTask($task->id, 'tm-1', $wm));
    }

    public function testClaimTaskReturnsTrueAndWiresWorktreePathOnSuccess(): void
    {
        $team = $this->createTeam('team-claim-ok');

        $teammate = $this->createTeammate('tm-claim', $team->id, 'Bob', AgentType::Coder);
        $team->addTeammate($teammate);

        $taskId = 'task-ok-' . uniqid((string) getmypid(), true);
        $task = new \SugarCraft\Crush\Agents\Task(
            id: $taskId,
            teamId: $team->id,
            title: 'Good Task',
            description: '',
            prompt: '',
            assignedTo: null,
            status: \SugarCraft\Crush\Agents\TaskStatus::Pending,
            result: null,
            error: null,
            createdAt: new \DateTimeImmutable(),
            claimedAt: null,
            completedAt: null,
            dependsOn: [],
            isContested: false,
        );
        $team->getTaskList()->addTask($task);

        $wm = $this->createRealWorktreeManager();

        $result = $team->claimTask($taskId, 'tm-claim', $wm);

        $this->assertTrue($result);

        // Teammate's worktreePath must be updated to what createWorktree returned
        $updatedTeammate = $team->getTeammate('tm-claim');
        $this->assertNotNull($updatedTeammate);
        $this->assertNotNull($updatedTeammate->worktreePath);
        $this->assertStringEndsWith('/tm-claim', $updatedTeammate->worktreePath);
        $this->assertTrue(is_dir($updatedTeammate->worktreePath));
    }

    public function testClaimTaskRunsWorktreeSweep(): void
    {
        // Proxy repro for "sweepIfDue() has a real caller": claimTask() must
        // invoke WorktreeManager::sweepIfDue(), whose only observable side
        // effect is writing the .last-sweep throttle marker file.
        $team = $this->createTeam('team-claim-sweep');

        $teammate = $this->createTeammate('tm-sweep', $team->id, 'Sweeper', AgentType::Coder);
        $team->addTeammate($teammate);

        $taskId = 'task-sweep-' . uniqid((string) getmypid(), true);
        $task = new \SugarCraft\Crush\Agents\Task(
            id: $taskId,
            teamId: $team->id,
            title: 'Sweep Task',
            description: '',
            prompt: '',
            assignedTo: null,
            status: \SugarCraft\Crush\Agents\TaskStatus::Pending,
            result: null,
            error: null,
            createdAt: new \DateTimeImmutable(),
            claimedAt: null,
            completedAt: null,
            dependsOn: [],
            isContested: false,
        );
        $team->getTaskList()->addTask($task);

        $wm = $this->createRealWorktreeManager();

        $reflection = new \ReflectionClass($wm);
        $expandedBasePathProp = $reflection->getProperty('expandedBasePath');
        $expandedBasePathProp->setAccessible(true);
        $marker = $expandedBasePathProp->getValue($wm) . '/.last-sweep';

        $this->assertFileDoesNotExist($marker);

        $team->claimTask($taskId, 'tm-sweep', $wm);

        $this->assertFileExists($marker, 'claimTask() must call WorktreeManager::sweepIfDue()');
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function createTeam(string $id, int $maxTeammates = 5): Team
    {
        // Every Team id in this file is attributed to the process (E281).
        $id = $this->teamId($id);

        return new Team(
            id: $id,
            name: "Team {$id}",
            leadAgentId: "lead-{$id}",
            createdAt: new \DateTimeImmutable(),
            maxTeammates: $maxTeammates,
        );
    }

    private function createTeammate(
        string $id,
        string $teamId,
        string $name,
        AgentType $type,
    ): Teammate {
        return new Teammate(
            id: $id,
            // Accepts a raw literal OR an already-tokenised $team->id; teamId()
            // is idempotent, and both call shapes appear in this file.
            teamId: $this->teamId($teamId),
            name: $name,
            type: $type,
            model: 'claude-sonnet-4-6',
            tools: ['Read', 'Edit', 'Bash'],
        );
    }

    /**
     * Build a real WorktreeManager backed by a throwaway git repo, since
     * Team::claimTask() now requires the concrete WorktreeManager type
     * (previously an untyped `object`, which a hand-rolled test double could
     * satisfy without exercising any real git/worktree behavior).
     */
    private function createRealWorktreeManager(): WorktreeManager
    {
        $tmpRoot = sys_get_temp_dir() . '/sugar-crush-team-test-' . uniqid('', true);
        mkdir($tmpRoot, 0755, true);
        $this->tmpDirsToClean[] = $tmpRoot;

        $repoRoot = $tmpRoot . '/repo.git';
        shell_exec('git init --bare ' . escapeshellarg($repoRoot) . ' 2>&1');

        $repoRoot = $tmpRoot . '/repo';
        shell_exec('git clone ' . escapeshellarg($repoRoot) . ' ' . escapeshellarg($repoRoot) . ' 2>&1');

        $config = new WorktreeConfig(basePath: $tmpRoot . '/worktrees/');

        return new WorktreeManager($config, $repoRoot);
    }

    private function removeDirectory(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        $items = array_diff(scandir($path) ?: [], ['.', '..']);
        foreach ($items as $item) {
            $itemPath = $path . '/' . $item;
            if (is_dir($itemPath)) {
                $this->removeDirectory($itemPath);
            } else {
                unlink($itemPath);
            }
        }
        rmdir($path);
    }
}
