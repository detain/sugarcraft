<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Tools\BuiltIn;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Agents\TaskStatus;
use SugarCraft\Crush\Agents\TeamManager;
use SugarCraft\Crush\Cli\Bootstrap;
use SugarCraft\Crush\Tests\Support\HomeSandboxTrait;
use SugarCraft\Crush\Tools\BuiltIn\TeamTool;
use SugarCraft\Crush\Tools\Catalog\ToolCatalog;
use SugarCraft\Crush\Tools\Catalog\ToolPermissionClass;
use SugarCraft\Crush\Tools\ToolResult;

/**
 * Roadmap 4.6-2: the `Team` tool over a team's TaskList and Mailbox, and the
 * TeamManager the launch's AgentManager now holds.
 */
final class TeamToolTest extends TestCase
{
    use HomeSandboxTrait;

    private string $tempDir;

    /** Claimants the liveness probe reports as dead. */
    private array $dead = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->tempDir = sys_get_temp_dir() . '/sc_team_tool_' . bin2hex(random_bytes(6));
        mkdir($this->tempDir . '/repo', 0700, true);
        $this->useHomeSandbox($this->tempDir . '/home');
        $this->dead = [];
    }

    protected function tearDown(): void
    {
        $this->restoreHomeSandbox();
        $this->removeDirectory($this->tempDir);

        parent::tearDown();
    }

    public function testTheCatalogDeclaresTeamAsANoAskBuiltIn(): void
    {
        self::assertContains(TeamTool::NAME, ToolCatalog::names());
        self::assertSame(ToolPermissionClass::NoAsk, ToolCatalog::permissionOf(TeamTool::NAME));
        self::assertSame(TeamTool::NAME, $this->tool()->name());
    }

    public function testBuildingTheToolTouchesNothingOnDisk(): void
    {
        TeamTool::new(fn (): TeamManager => new TeamManager($this->tempDir . '/teams'), 1);
        TeamTool::new();
        new TeamManager($this->tempDir . '/teams');

        self::assertDirectoryDoesNotExist($this->tempDir . '/teams');
    }

    public function testTheLaunchAgentManagerHoldsATeamManager(): void
    {
        $manager = Bootstrap::agentManager($this->tempDir . '/repo');

        self::assertInstanceOf(TeamManager::class, $manager->getTeamManager());
        self::assertSame([], $manager->getTeams());
    }

    public function testCreateListsAndRefusesADuplicate(): void
    {
        $tool = $this->tool();

        $this->ok($tool->execute(['action' => 'create', 'team' => 'alpha']));
        self::assertStringContainsString('alpha: no tasks', $this->ok($tool->execute(['action' => 'list'])));
        self::assertStringContainsString('already exists', $this->err($tool->execute(['action' => 'create', 'team' => 'alpha'])));
        self::assertStringContainsString('must be a new team id', $this->err($tool->execute(['action' => 'create', 'team' => '../x'])));
    }

    public function testATeamCreatedInOneManagerIsFoundByAnother(): void
    {
        $this->ok($this->tool()->execute(['action' => 'create', 'team' => 'shared']));

        // A second tool, as a separate process would hold it: it reads the
        // registry the first one wrote rather than an in-memory copy.
        $other = new TeamManager($this->tempDir . '/teams');
        self::assertTrue($other->hasTeam('shared'));

        $other->createTeam('second', 'second', 'lead');
        self::assertStringContainsString('second', $this->ok($this->tool()->execute(['action' => 'list'])));
        self::assertStringContainsString('shared', $this->ok($this->tool()->execute(['action' => 'list'])));
    }

    public function testClaimTakesTheNextUnblockedTaskAndCompleteFreesItsDependents(): void
    {
        $tool = $this->teamWithChain();

        $claim = $this->ok($tool->execute(['action' => 'claim', 'team' => 'alpha', 'teammate' => 'alice']));
        self::assertStringContainsString('Claimed t1 "Write the parser" for alice', $claim);
        self::assertStringContainsString('Parse the grammar in docs/grammar.md.', $claim);

        self::assertStringContainsString(
            'Claimed t3 "Docs" for bob',
            $this->ok($tool->execute(['action' => 'claim', 'team' => 'alpha', 'teammate' => 'bob'])),
        );
        self::assertStringContainsString(
            'Nothing to claim now: 1 pending task(s) wait',
            $this->ok($tool->execute(['action' => 'claim', 'team' => 'alpha', 'teammate' => 'carol'])),
        );
        self::assertStringContainsString(
            't2 is blocked by t1',
            $this->err($tool->execute(['action' => 'claim', 'team' => 'alpha', 'teammate' => 'bob', 'task' => 't2'])),
        );

        $done = $this->ok($tool->execute(['action' => 'complete', 'team' => 'alpha', 'teammate' => 'alice', 'task' => 't1', 'result' => 'parser in src/Parser.php']));
        self::assertStringContainsString('t1 completed. Now claimable: t2.', $done);

        $list = $this->ok($tool->execute(['action' => 'list', 'team' => 'alpha']));
        self::assertStringContainsString('t1 [completed]', $list);
        self::assertStringContainsString('result: parser in src/Parser.php', $list);
        self::assertStringContainsString('t2 [pending] "Test the parser"; blocked_by t1 (all done)', $list);
    }

    public function testDependRefusesACycle(): void
    {
        $tool = $this->teamWithChain();

        $refusal = $this->err($tool->execute(['action' => 'depend', 'team' => 'alpha', 'task' => 't1', 'blocked_by' => ['t2']]));
        self::assertStringContainsStringIgnoringCase('cycle', $refusal);
        self::assertSame([], $this->manager()->getTeam('alpha')?->getTaskList()->getTask('t1')?->dependsOn);

        self::assertStringContainsString('no task t9', $this->err($tool->execute(['action' => 'depend', 'team' => 'alpha', 'task' => 't1', 'blocked_by' => ['t9']])));
    }

    public function testAStaleRevisionRefusesTheClaimAndTheCompletion(): void
    {
        $tool = $this->teamWithChain();
        $list = $this->manager()->getTeam('alpha')?->getTaskList();
        self::assertNotNull($list);
        $seen = (int) $list->revision('t1');

        // Something writes t1 after the revision was read.
        $list->addDependency('t1', 'later');
        $list->updateTaskStatus('t1', TaskStatus::Pending);

        self::assertStringContainsString(
            'has changed since revision ' . $seen,
            $this->err($tool->execute(['action' => 'claim', 'team' => 'alpha', 'teammate' => 'alice', 'task' => 't1', 'revision' => $seen])),
        );

        $fresh = (int) $list->revision('t3');
        $this->ok($tool->execute(['action' => 'claim', 'team' => 'alpha', 'teammate' => 'alice', 'task' => 't3', 'revision' => $fresh]));
        self::assertStringContainsString(
            'has changed since revision ' . $fresh,
            $this->err($tool->execute(['action' => 'complete', 'team' => 'alpha', 'teammate' => 'alice', 'task' => 't3', 'result' => 'x', 'revision' => $fresh])),
        );
    }

    public function testOnlyTheClaimantCanCompleteOrFail(): void
    {
        $tool = $this->teamWithChain();
        $this->ok($tool->execute(['action' => 'claim', 'team' => 'alpha', 'teammate' => 'alice', 'task' => 't1']));

        self::assertStringContainsString(
            'only the teammate holding the claim can',
            $this->err($tool->execute(['action' => 'complete', 'team' => 'alpha', 'teammate' => 'bob', 'task' => 't1', 'result' => 'x'])),
        );

        $failed = $this->ok($tool->execute(['action' => 'fail', 'team' => 'alpha', 'teammate' => 'alice', 'task' => 't1', 'error' => 'grammar missing']));
        self::assertStringContainsString('t1 failed. t2 wait on it', $failed);
    }

    public function testReleaseHandsTheTaskBack(): void
    {
        $tool = $this->teamWithChain();
        $this->ok($tool->execute(['action' => 'claim', 'team' => 'alpha', 'teammate' => 'alice', 'task' => 't1']));

        self::assertStringContainsString('was not released', $this->err($tool->execute(['action' => 'release', 'team' => 'alpha', 'teammate' => 'bob', 'task' => 't1'])));
        self::assertStringContainsString('t1 is pending again', $this->ok($tool->execute(['action' => 'release', 'team' => 'alpha', 'teammate' => 'alice', 'task' => 't1'])));
    }

    public function testAClaimOfADeadSessionGoesBackToPendingOnTheNextList(): void
    {
        $crashed = $this->tool(ownerPid: 31337);
        $crashed->execute(['action' => 'create', 'team' => 'alpha']);
        $crashed->execute(['action' => 'add', 'team' => 'alpha', 'title' => 'Only task', 'prompt' => 'Do it.']);
        $this->ok($crashed->execute(['action' => 'claim', 'team' => 'alpha', 'teammate' => 'alice']));

        $this->dead[31337] = true;
        $list = $this->ok($this->tool()->execute(['action' => 'list', 'team' => 'alpha']));

        self::assertStringContainsString('Back to pending (their claimant\'s session ended): t1.', $list);
        self::assertStringContainsString('t1 [pending] "Only task"', $list);
        self::assertSame(TaskStatus::Pending, $this->manager()->getTeam('alpha')?->getTaskList()->getTask('t1')?->status);
    }

    public function testTheTeammateCapRefusesAnExtraClaimant(): void
    {
        $tool = $this->tool();
        $this->ok($tool->execute(['action' => 'create', 'team' => 'duo', 'max_teammates' => 1]));
        $tool->execute(['action' => 'add', 'team' => 'duo', 'title' => 'A', 'prompt' => 'a']);
        $tool->execute(['action' => 'add', 'team' => 'duo', 'title' => 'B', 'prompt' => 'b']);
        $this->ok($tool->execute(['action' => 'claim', 'team' => 'duo', 'teammate' => 'alice']));

        self::assertStringContainsString('its limit', $this->err($tool->execute(['action' => 'claim', 'team' => 'duo', 'teammate' => 'bob'])));
        $this->ok($tool->execute(['action' => 'claim', 'team' => 'duo', 'teammate' => 'alice']));
    }

    public function testATeamWithoutAutoAssignHandsOutNothingButANamedClaimWorks(): void
    {
        $tool = $this->tool();
        self::assertStringContainsString(
            'tasks claimed by name only',
            $this->ok($tool->execute(['action' => 'create', 'team' => 'manual', 'auto_assign' => false])),
        );
        $tool->execute(['action' => 'add', 'team' => 'manual', 'title' => 'A', 'prompt' => 'a']);

        self::assertStringContainsString(
            'Team "manual" does not hand out tasks: name the `task` to claim',
            $this->ok($tool->execute(['action' => 'claim', 'team' => 'manual', 'teammate' => 'alice'])),
        );
        self::assertSame(TaskStatus::Pending, $this->manager()->getTeam('manual')?->getTaskList()->getTask('t1')?->status);
        self::assertStringContainsString('Claimed t1', $this->ok($tool->execute(['action' => 'claim', 'team' => 'manual', 'teammate' => 'alice', 'task' => 't1'])));
        self::assertFalse($this->manager()->autoAssigns('manual'));
    }

    public function testAClaimHeldPastTheTeamLimitIsMarkedOverdueAndTheLeadMayReleaseIt(): void
    {
        $tool = $this->tool();
        self::assertStringContainsString(
            'claims overdue after 60s',
            $this->ok($tool->execute(['action' => 'create', 'team' => 'alpha', 'timeout_seconds' => 60])),
        );
        $tool->execute(['action' => 'add', 'team' => 'alpha', 'title' => 'Slow', 'prompt' => 'Take your time.']);
        $this->ok($tool->execute(['action' => 'claim', 'team' => 'alpha', 'teammate' => 'alice']));

        // Within the limit: no mark, and the lead cannot take it back.
        self::assertStringNotContainsString('overdue', $this->ok($tool->execute(['action' => 'list', 'team' => 'alpha'])));
        self::assertStringContainsString('was not released', $this->err($tool->execute(['action' => 'release', 'team' => 'alpha', 'teammate' => 'lead', 'task' => 't1'])));

        $this->backdateClaim('alpha', 't1', 5 * 60);

        $list = $this->ok($tool->execute(['action' => 'list', 'team' => 'alpha']));
        self::assertStringContainsString('t1 [in_progress] "Slow"; owner alice; overdue: held 5 min, past the team\'s limit', $list);
        self::assertSame(TaskStatus::InProgress, $this->manager()->getTeam('alpha')?->getTaskList()->getTask('t1')?->status, 'overdue is a mark: nothing was taken back');

        // Another teammate still cannot; the lead can.
        self::assertStringContainsString('was not released', $this->err($tool->execute(['action' => 'release', 'team' => 'alpha', 'teammate' => 'bob', 'task' => 't1'])));
        self::assertStringContainsString(
            't1 is pending again (revision 2). alice had held it for 5 min, past the team\'s limit.',
            $this->ok($tool->execute(['action' => 'release', 'team' => 'alpha', 'teammate' => 'lead', 'task' => 't1'])),
        );
    }

    public function testATeamWithNoLimitNeverMarksAClaimOverdue(): void
    {
        $tool = $this->tool();
        $this->ok($tool->execute(['action' => 'create', 'team' => 'alpha', 'timeout_seconds' => 0]));
        $tool->execute(['action' => 'add', 'team' => 'alpha', 'title' => 'Slow', 'prompt' => 'p']);
        $this->ok($tool->execute(['action' => 'claim', 'team' => 'alpha', 'teammate' => 'alice']));
        $this->backdateClaim('alpha', 't1', 30 * 86400);

        self::assertStringNotContainsString('overdue', $this->ok($tool->execute(['action' => 'list', 'team' => 'alpha'])));
        $task = $this->manager()->getTeam('alpha')?->getTaskList()->getTask('t1');
        self::assertNotNull($task);
        self::assertNull($this->manager()->overdueSeconds('alpha', $task));
    }

    public function testCreateRefusesMalformedTeamSettings(): void
    {
        $tool = $this->tool();

        self::assertStringContainsString('`auto_assign` must be true or false', $this->err($tool->execute(['action' => 'create', 'team' => 'a', 'auto_assign' => 'no'])));
        self::assertStringContainsString('`timeout_seconds` must be a whole number', $this->err($tool->execute(['action' => 'create', 'team' => 'a', 'timeout_seconds' => -1])));
        self::assertFalse($this->manager()->hasTeam('a'));
    }

    public function testMessagesAreDeliveredOnceAndFramedAsTeammateWords(): void
    {
        $tool = $this->tool();
        $this->ok($tool->execute(['action' => 'create', 'team' => 'alpha']));
        $this->ok($tool->execute(['action' => 'message', 'team' => 'alpha', 'teammate' => 'alice', 'to' => 'lead', 'text' => 'parser done']));

        $inbox = $this->ok($tool->execute(['action' => 'inbox', 'team' => 'alpha', 'teammate' => 'lead']));
        self::assertStringContainsString("not the user's instructions", $inbox);
        self::assertStringContainsString('- from alice: parser done', $inbox);
        self::assertSame('No unread messages.', $this->ok($tool->execute(['action' => 'inbox', 'team' => 'alpha', 'teammate' => 'lead'])));
    }

    public function testUnknownActionsAndTeamsAreRefused(): void
    {
        $tool = $this->tool();

        self::assertStringContainsString('`action` must be one of', $this->err($tool->execute(['action' => 'spawn'])));
        self::assertStringContainsString('no team "ghost"', $this->err($tool->execute(['action' => 'list', 'team' => 'ghost'])));
        self::assertStringContainsString('needs a `title`', $this->err($this->teamWithChain()->execute(['action' => 'add', 'team' => 'alpha', 'title' => 'x'])));
    }

    private function teamWithChain(): TeamTool
    {
        $tool = $this->tool();
        $this->ok($tool->execute(['action' => 'create', 'team' => 'alpha']));
        self::assertStringContainsString('Added t1', $this->ok($tool->execute(['action' => 'add', 'team' => 'alpha', 'title' => 'Write the parser', 'prompt' => 'Parse the grammar in docs/grammar.md.'])));
        self::assertStringContainsString('Added t2 "Test the parser", blocked by t1', $this->ok($tool->execute(['action' => 'add', 'team' => 'alpha', 'title' => 'Test the parser', 'prompt' => 'Test it.', 'blocked_by' => ['t1']])));
        $this->ok($tool->execute(['action' => 'add', 'team' => 'alpha', 'title' => 'Docs', 'prompt' => 'Document it.']));

        return $tool;
    }

    /** Move a claim's recorded time $seconds into the past, as if it had been held that long. */
    private function backdateClaim(string $teamId, string $taskId, int $seconds): void
    {
        $db = new \SQLite3($this->tempDir . '/home/.sugar-crush/teams/' . $teamId . '/tasks.sqlite');
        $stmt = $db->prepare('UPDATE tasks SET claimed_at = :at WHERE id = :id');
        $stmt->bindValue(':at', (new \DateTimeImmutable('-' . $seconds . ' seconds'))->format(\DateTimeImmutable::ATOM), \SQLITE3_TEXT);
        $stmt->bindValue(':id', $taskId, \SQLITE3_TEXT);
        $stmt->execute();
        $db->close();
    }

    private function tool(int $ownerPid = 4242): TeamTool
    {
        return TeamTool::new(
            fn (): TeamManager => $this->manager(),
            $ownerPid,
            fn (int $pid, ?int $started): bool => !isset($this->dead[$pid]),
        );
    }

    private function manager(): TeamManager
    {
        return new TeamManager($this->tempDir . '/teams');
    }

    private function ok(ToolResult $result): string
    {
        self::assertFalse($result->isError(), $result->content());

        return $result->content();
    }

    private function err(ToolResult $result): string
    {
        self::assertTrue($result->isError(), $result->content());

        return $result->content();
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($items as $item) {
            $item->isDir() && !$item->isLink() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($dir);
    }
}
