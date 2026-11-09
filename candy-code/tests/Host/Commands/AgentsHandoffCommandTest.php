<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Host\Commands;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Agents\WorktreeConfig;
use SugarCraft\Code\Agents\WorktreeIsolationMode;
use SugarCraft\Code\Agents\WorktreeManager;
use SugarCraft\Code\Host\Commands\AgentStatusCommands;
use SugarCraft\Code\Host\Commands\AgentsHostCommand;
use SugarCraft\Code\Host\Commands\CommandContext;
use SugarCraft\Code\Host\Commands\CommandResult;
use SugarCraft\Code\Lang;
use SugarCraft\Code\Workspace\GitRunner;

use function bin2hex;
use function count;
use function escapeshellarg;
use function file_put_contents;
use function getenv;
use function implode;
use function is_dir;
use function mkdir;
use function posix_getuid;
use function putenv;
use function random_bytes;
use function shell_exec;
use function trim;

/**
 * RS-AGENT-A5: the `agents handoff <id> [--apply]` verb at its real seat —
 * the hosted command, routed BEFORE the manager gate, so the hand-off report
 * answers from disk facts (the worktree registry and git plumbing) even in a
 * window with no roster and no provider configured.
 *
 * The capability cards a2a shipped taught the posture the parser keeps:
 * strict arguments, the shared `host.agents.usage` line for every misuse
 * shape, zero new strings for the twelve locales to chase. `--apply` is the
 * only write in the family, and it is a bounded one — a NEW `handoff/*` ref
 * via git plumbing, never master, never HEAD, never the agent worktree — so
 * a read-only window must refuse it while still answering the read-only
 * question. The end-to-end legs build a real repository through the same
 * WorktreeConfig default (registry inside the fixture root) that the
 * production `WorktreeManager::new($repo)` resolves, so what is asserted is
 * the routing and the printed report together, not a mock of them.
 */
#[CoversClass(AgentsHostCommand::class)]
#[CoversClass(AgentStatusCommands::class)]
final class AgentsHandoffCommandTest extends TestCase
{
    private const ENV_DIR_KEYS = ['CANDY_CODE_WORKTREES_DIR', 'SUGARCRUSH_WORKTREES_DIR', 'SUGAR_CRUSH_WORKTREES_DIR'];

    /** @var array<string,string|false> */
    private array $savedEnv = [];

    private string $sandbox = '';

    private string $project = '';

    protected function setUp(): void
    {
        parent::setUp();

        foreach (self::ENV_DIR_KEYS as $key) {
            $this->savedEnv[$key] = getenv($key);
            putenv($key);
        }

        $this->sandbox = '/tmp/wf-a5-cmd-' . posix_getuid() . '-' . bin2hex(random_bytes(4));
        $this->project = $this->sandbox . '/project';
        if (!mkdir($this->project, 0700, true)) {
            self::fail('could not create the hand-off command sandbox');
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->savedEnv as $key => $value) {
            if ($value === false) {
                putenv($key);
                continue;
            }
            putenv($key . '=' . $value);
        }

        $this->rmTree($this->sandbox);
        parent::tearDown();
    }

    /** @return array<string,array{0:string}> */
    public static function argumentMisuse(): array
    {
        return [
            'no id' => ['/agents handoff'],
            'unknown flag' => ['/agents handoff a1 --nope'],
            'doubled apply' => ['/agents handoff a1 --apply --apply'],
            'second positional' => ['/agents handoff a1 a2'],
        ];
    }

    #[DataProvider('argumentMisuse')]
    public function testEveryMisuseShapePrintsTheSharedUsageLine(string $text): void
    {
        $result = $this->driveHandoff($text);

        $rows = self::contentsOf($result);
        self::assertCount(2, $rows, 'the echo and the usage row, nothing else');
        self::assertSame($text, $rows[0]);
        self::assertSame(Lang::t('host.agents.usage'), self::lastContent($result));
    }

    public function testAnUnknownIdPrintsTheOnRecordSentenceFromAnEmptyProject(): void
    {
        // No .candy-code/worktrees here: branchOf answers null and the report
        // says so in one row — the sentence proves it reached the formatter.
        $body = self::lastContent($this->driveHandoff('/agents handoff ghost-e137'));

        self::assertStringContainsString('No worktree is on record for "ghost-e137"', $body);
    }

    public function testAReadOnlyWindowRefusesTheApplyButStillAnswersTheQuestion(): void
    {
        $refused = (new AgentsHostCommand())->run(
            CommandContext::new(root: $this->project, readOnly: true),
            '/agents handoff a1 --apply',
        );
        self::assertTrue($refused->isRefused(), 'apply writes a ref and the read-only door holds');
        self::assertSame(
            Lang::t('host.command.read_only', ['name' => '/agents handoff --apply']),
            (string) $refused->error,
        );

        // The same window still reports: the question is read-only.
        $report = (new AgentsHostCommand())->run(
            CommandContext::new(root: $this->project, readOnly: true),
            '/agents handoff a1',
        );
        self::assertFalse($report->isRefused());
        self::assertStringContainsString('No worktree is on record', self::lastContent($report));
    }

    public function testTheVerbRunsAheadOfTheManagerGateWithNoAgentManager(): void
    {
        // Every other verb behind the gate fails not_configured; handoff
        // answers from disk. No manager is constructed in this context.
        $body = self::lastContent($this->driveHandoff('/agents handoff a1'));

        self::assertStringContainsString('No worktree is on record for "a1"', $body);
        self::assertStringNotContainsString('not configured', $body);
    }

    public function testARealWorktreeReportsThroughTheCommandEndToEnd(): void
    {
        $this->makeRepoWithAgentWorktree('a1');

        $body = self::lastContent($this->driveHandoff('/agents handoff a1'));

        self::assertStringContainsString('Hand-off report for "a1":', $body);
        self::assertStringContainsString('branch "agent-a1" · 1 commit(s) ahead, 0 behind', $body);
        self::assertStringContainsString('could fast-forward', $body);
        self::assertStringContainsString('merging into the main tree now: clean', $body);
        self::assertStringContainsString('agent.txt', $body);
    }

    public function testApplyThroughTheCommandWritesTheRefAndPrintsTheOutcome(): void
    {
        $repo = $this->makeRepoWithAgentWorktree('a1');

        $body = self::lastContent($this->driveHandoff('/agents handoff a1 --apply'));

        self::assertStringContainsString('APPLIED: merged into new branch "handoff/a1-', $body);
        self::assertStringContainsString('master, HEAD and the agent worktree were not touched', $body);
        $refs = trim($this->runGitAt($repo, 'for-each-ref', '--format=%(refname:short)', 'refs/heads/handoff'));
        self::assertStringStartsWith('handoff/a1-', $refs);
    }

    /* ------------------------------------------------------------------ */

    private function driveHandoff(string $text): CommandResult
    {
        return (new AgentsHostCommand())->run(
            CommandContext::new(root: $this->project),
            $text,
        );
    }

    /** Builds a real repo as the project root with one ahead-committed agent worktree. */
    private function makeRepoWithAgentWorktree(string $agentId): string
    {
        $repo = $this->project;
        shell_exec('git init -q -b main ' . escapeshellarg($repo) . ' 2>&1');
        if (!is_dir($repo . '/.git')) {
            self::fail('git init failed for the command fixture');
        }
        $this->runGitAt($repo, 'config', 'user.email', 'fixture@example.invalid');
        $this->runGitAt($repo, 'config', 'user.name', 'Fixture');
        file_put_contents($repo . '/work.txt', "base line\nagent tail\n");
        $this->runGitAt($repo, 'add', '-A');
        $this->runGitAt($repo, 'commit', '-qm', 'seed commit');

        $manager = new WorktreeManager(
            new WorktreeConfig(isolationMode: WorktreeIsolationMode::Worktree),
            $repo,
        );
        $worktree = $manager->createWorktree($agentId, 'agent-' . $agentId);
        file_put_contents($worktree . '/agent.txt', "agent work product\n");
        $this->runGitAt($worktree, 'add', '-A');
        $this->runGitAt($worktree, 'commit', '-qm', 'agent work');

        return $repo;
    }

    private function runGitAt(string $cwd, string ...$args): string
    {
        $result = GitRunner::new($cwd)->run(...$args);
        self::assertTrue($result['ok'], 'git ' . implode(' ', $args) . ' failed: ' . (string) $result['stderr']);

        return trim((string) $result['stdout']);
    }

    /** @return list<string> */
    private static function contentsOf(CommandResult $result): array
    {
        $lines = [];
        foreach ($result->rows as $row) {
            $lines[] = (string) $row->content;
        }

        return $lines;
    }

    private static function lastContent(CommandResult $result): string
    {
        $lines = self::contentsOf($result);

        return (string) $lines[count($lines) - 1];
    }

    private function rmTree(string $path): void
    {
        // The caps-test shape: one shell rm -rf over the sandbox, not a PHP
        // recursive walker a sibling test could drift against.
        shell_exec('rm -rf ' . escapeshellarg($path) . ' 2>&1');
    }
}
