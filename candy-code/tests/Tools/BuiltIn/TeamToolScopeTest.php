<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Tools\BuiltIn;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Agents\TeamManager;
use SugarCraft\Code\Tests\Support\HomeSandboxTrait;
use SugarCraft\Code\Tools\BuiltIn\TeamTool;
use SugarCraft\Code\Tools\ToolResult;

/**
 * M-3 (cc-review-1111): the team registry is per-USER, so without a scope
 * stamp one workspace's agent could name another workspace's team id (ids are
 * model-chosen and visible through `list`) and read its task titles, mail it,
 * or claim and settle its work — including the createWorktree side effect on
 * claim. These pins prove the tool gate refuses every cross-workspace action
 * loudly, leaks nothing, and never mutates the victim's board; the same
 * workspace still drives its own team end to end (non-vacuity).
 */
final class TeamToolScopeTest extends TestCase
{
    use HomeSandboxTrait;

    private string $tempDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tempDir = sys_get_temp_dir() . '/sc_team_scope_' . bin2hex(random_bytes(6));
        mkdir($this->tempDir . '/repo', 0700, true);
        $this->useHomeSandbox($this->tempDir . '/home');
    }

    protected function tearDown(): void
    {
        $this->restoreHomeSandbox();
        $this->removeDirectory($this->tempDir);

        parent::tearDown();
    }

    public function testAClaimOnAnotherWorkspacesTeamIsRefusedAndNeverLands(): void
    {
        $victim = $this->scopeTool('ws-a');
        $this->ok($victim->execute(['action' => 'create', 'team' => 'alpha']));
        $this->ok($victim->execute(['action' => 'add', 'team' => 'alpha', 'title' => 'Write the parser', 'prompt' => 'Parse the grammar in docs/grammar.md.']));

        $intruder = $this->scopeTool('ws-b');
        $refusal = $this->err($intruder->execute(['action' => 'claim', 'team' => 'alpha', 'teammate' => 'mallory']));
        self::assertStringContainsString('belongs to another workspace', $refusal);

        // The victim's board is untouched: the task is still up for grabs.
        self::assertStringContainsString('[pending]', $this->ok($victim->execute(['action' => 'list', 'team' => 'alpha'])));
        self::assertStringContainsString('Claimed t1', $this->ok($victim->execute(['action' => 'claim', 'team' => 'alpha', 'teammate' => 'alice'])));
    }

    public function testListingAnotherWorkspacesTeamRefusesAndLeaksNoTaskTitles(): void
    {
        $victim = $this->scopeTool('ws-a');
        $this->ok($victim->execute(['action' => 'create', 'team' => 'alpha']));
        $this->ok($victim->execute(['action' => 'add', 'team' => 'alpha', 'title' => 'Write the parser', 'prompt' => 'Parse the grammar in docs/grammar.md.']));

        $intruder = $this->scopeTool('ws-b');
        $refusal = $this->err($intruder->execute(['action' => 'list', 'team' => 'alpha']));
        self::assertStringContainsString('belongs to another workspace', $refusal);
        self::assertStringNotContainsString('Write the parser', $refusal);
    }

    public function testMessagingAnotherWorkspacesTeamIsRefusedAndTheLeadInboxStaysEmpty(): void
    {
        $victim = $this->scopeTool('ws-a');
        $this->ok($victim->execute(['action' => 'create', 'team' => 'alpha']));

        $intruder = $this->scopeTool('ws-b');
        $refusal = $this->err($intruder->execute(['action' => 'message', 'team' => 'alpha', 'teammate' => 'mallory', 'to' => 'lead', 'text' => 'poisoned result']));
        self::assertStringContainsString('belongs to another workspace', $refusal);

        // n13 team-stamp fuse (lane-report pre-authorized): scope invariant
        // unchanged — the inbox is provably STILL EMPTY after the refused
        // cross-workspace message; only the presentation string now carries
        // its team name.
        self::assertSame('No unread messages in team "alpha".', $this->ok($victim->execute(['action' => 'inbox', 'team' => 'alpha', 'teammate' => 'lead'])));
    }

    public function testCompletingAnotherWorkspacesTaskIsRefusedAndTheClaimSurvives(): void
    {
        $victim = $this->scopeTool('ws-a');
        $this->ok($victim->execute(['action' => 'create', 'team' => 'alpha']));
        $this->ok($victim->execute(['action' => 'add', 'team' => 'alpha', 'title' => 'Write the parser', 'prompt' => 'Parse the grammar in docs/grammar.md.']));
        $this->ok($victim->execute(['action' => 'claim', 'team' => 'alpha', 'teammate' => 'alice', 'task' => 't1']));

        $intruder = $this->scopeTool('ws-b');
        $refusal = $this->err($intruder->execute(['action' => 'complete', 'team' => 'alpha', 'teammate' => 'alice', 'task' => 't1', 'result' => 'fabricated']));
        self::assertStringContainsString('belongs to another workspace', $refusal);

        // alice's claim is still the live state of the victim's board.
        self::assertStringContainsString('in_progress', $this->ok($victim->execute(['action' => 'list', 'team' => 'alpha'])));
    }

    public function testReadingAnotherWorkspacesInboxIsRefused(): void
    {
        $victim = $this->scopeTool('ws-a');
        $this->ok($victim->execute(['action' => 'create', 'team' => 'alpha']));

        $intruder = $this->scopeTool('ws-b');
        $refusal = $this->err($intruder->execute(['action' => 'inbox', 'team' => 'alpha', 'teammate' => 'lead']));
        self::assertStringContainsString('belongs to another workspace', $refusal);
    }

    public function testAPreScopingTeamIsRefusedLoudlyWithTheRemedy(): void
    {
        // A team written straight through the manager API (a registry from
        // before scoping existed) carries no stamp; the tool must refuse to
        // drive it rather than treat "no stamp" as "matches anything".
        $this->manager()->createTeam('old', 'old', 'lead');

        $tool = $this->scopeTool('ws-a');
        $refusal = $this->err($tool->execute(['action' => 'list', 'team' => 'old']));
        self::assertStringContainsString('predates workspace scoping', $refusal);
        self::assertStringContainsString('create', $refusal);
    }

    public function testTheTeamlessListHidesForeignTeamsAndCountsThem(): void
    {
        $tool = $this->scopeTool('ws-a');
        $this->ok($tool->execute(['action' => 'create', 'team' => 'alpha']));

        $manager = $this->manager();
        $manager->createTeam('legacy', 'legacy', 'lead');
        $manager->createTeam('other', 'other', 'lead', null, 'ws-b');

        $listed = $this->ok($tool->execute(['action' => 'list']));
        self::assertStringContainsString('- alpha', $listed);
        // The count suffix legitimately spells 'other workspaces'; only a
        // listed ROW would name the id itself.
        self::assertStringNotContainsString('- other', $listed);
        self::assertStringNotContainsString('- legacy', $listed);
        self::assertStringContainsString('(+2 teams scoped to other workspaces, not listed)', $listed);
    }

    public function testTheSameWorkspaceDrivesItsOwnTeamEndToEnd(): void
    {
        $tool = $this->scopeTool('ws-c');
        $this->ok($tool->execute(['action' => 'create', 'team' => 'beta']));
        self::assertStringContainsString('Added t1', $this->ok($tool->execute(['action' => 'add', 'team' => 'beta', 'title' => 'Write the parser', 'prompt' => 'Parse the grammar in docs/grammar.md.'])));
        self::assertStringContainsString('Claimed t1', $this->ok($tool->execute(['action' => 'claim', 'team' => 'beta', 'teammate' => 'alice'])));
        $this->ok($tool->execute(['action' => 'complete', 'team' => 'beta', 'teammate' => 'alice', 'task' => 't1', 'result' => 'done']));

        self::assertStringContainsString('[completed]', $this->ok($tool->execute(['action' => 'list', 'team' => 'beta'])));
    }

    public function testTheScopeIsCarriedOnTheToolAndDefaultsToTheWorkspaceFingerprint(): void
    {
        self::assertSame('ws-a', $this->scopeTool('ws-a')->scope());

        // Production path: a tool built without an explicit scope still lands
        // on a stable fingerprint (HOME + cwd), never an empty string that
        // would collide with the legacy "unscoped" stamp.
        $defaulted = TeamTool::new(fn (): TeamManager => $this->manager(), 4242, static fn (int $pid, ?int $started): bool => true);
        self::assertSame(64, strlen($defaulted->scope()));
    }

    /**
     * A tool pinned to an explicit workspace scope, sharing this test's
     * registry directory — the shape of two launches in two projects.
     */
    private function scopeTool(string $scope): TeamTool
    {
        return TeamTool::new(
            fn (): TeamManager => $this->manager(),
            4242,
            static fn (int $pid, ?int $started): bool => true,
            $scope,
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
