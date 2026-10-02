<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\MCP;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\MCP\GitArgument;
use SugarCraft\Crush\MCP\GitCommandHandlers;
use SugarCraft\Crush\MCP\GitMcpServer;
use SugarCraft\Crush\MCP\GitOperationResult;

/**
 * Audit GIT-1: model-supplied git tool arguments must never reach git's
 * option parser, and the per-call `path` must stay inside the configured
 * repository root.
 *
 * Before the fix `gitShow(ref: '--output=<file>')` wrote <file>,
 * `gitAdd(['-A'])` staged every untracked file, and `path` could aim
 * `gitReset(mode: hard)` at any repository on disk. Every injection case
 * here asserts on SIDE EFFECTS — no file written, HEAD, branches, index and
 * worktrees unchanged — not only on the returned failure, because a failure
 * returned after git already acted is exactly the bug.
 *
 * Layout: `<tmp>/work/root` is the configured repository (with an untracked
 * file and a nested repository `nested/`), `<tmp>/work/sibling` a sibling
 * worktree location, `<tmp>/other` an unrelated repository outside the
 * worktree bound, and `<tmp>/work/root/escape` a symlink to it.
 */
final class GitCommandHandlersArgumentSafetyTest extends TestCase
{
    private string $tempDir;
    private string $root;
    private string $other;
    private string $previousCwd;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempDir = sys_get_temp_dir() . '/git_argument_safety_' . uniqid('', true);
        $this->root = $this->tempDir . '/work/root';
        $this->other = $this->tempDir . '/other';

        foreach ([$this->root, $this->other] as $repo) {
            mkdir($repo, 0777, true);
            $this->initRepo($repo);
        }

        $this->initRepo($this->root . '/nested');
        // An untracked file: `git add -A` would stage it, so the index is a
        // witness for gitAdd's injection cases.
        file_put_contents($this->root . '/untracked.txt', "untracked\n");
        symlink($this->other, $this->root . '/escape');

        // Run from INSIDE the fixture. Before the fix a relative `path` was
        // resolved against the process CWD, so running this file against the
        // unfixed handlers from the package directory aimed `gitReset(mode:
        // hard, path: '..')` at the checkout this suite lives in — measured:
        // it reset the developer's working tree and created a branch there.
        // From here, every escape the unfixed code allows lands on a fixture.
        $this->previousCwd = (string) getcwd();
        chdir($this->root);
    }

    protected function tearDown(): void
    {
        chdir($this->previousCwd);
        parent::tearDown();
        if (!is_dir($this->tempDir)) {
            return;
        }
        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->tempDir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($files as $file) {
            $pathname = $file->getPathname();
            if (is_link($pathname) || !is_dir($pathname)) {
                unlink($pathname);
            } else {
                rmdir($pathname);
            }
        }
        rmdir($this->tempDir);
    }

    // =========================================================================
    // Option injection
    // =========================================================================

    /**
     * Every handler argument that lands in a git argv, crossed with the
     * three payload shapes the audit names. `{out}` becomes a scratch file
     * path that must never be created.
     *
     * @return iterable<string, array{0: string, 1: string}>
     */
    public static function injectionCases(): iterable
    {
        $handlers = [
            'gitShow', 'gitConfigGet', 'gitRevert', 'gitReset', 'gitResetHard',
            'gitBranchCreate', 'gitBranchCreateCheckout', 'gitBranchDelete', 'gitBranchDeleteForce',
            'gitBranchCheckout', 'gitBranchCheckoutCreate',
            'gitWorktreeAddPath', 'gitWorktreeAddBranch', 'gitWorktreeRemove',
            'gitFlowFeature', 'gitFlowRelease', 'gitFlowHotfix',
            'gitLfsTrack', 'gitLfsUntrack', 'gitAdd', 'gitBlame',
        ];
        $payloads = ['output' => '--output={out}', 'force' => '--force', 'all' => '-A'];

        foreach ($handlers as $handler) {
            foreach ($payloads as $label => $payload) {
                yield "{$handler} {$label}" => [$handler, $payload];
            }
        }
    }

    #[DataProvider('injectionCases')]
    public function testOptionShapedArgumentIsNeverParsedAsAnOption(string $handler, string $payload): void
    {
        $out = $this->tempDir . '/pwned.txt';
        $value = str_replace('{out}', $out, $payload);
        $before = $this->repoState($this->root);

        $result = $this->invoke(new GitCommandHandlers($this->root), $handler, $value);

        $this->assertFalse($result->isSuccess(), "{$handler}('{$value}') must fail");
        $this->assertFileDoesNotExist($out, "{$handler}('{$value}') must not write a file");
        $this->assertSame($before, $this->repoState($this->root), "{$handler}('{$value}') must not change the repository");
    }

    /**
     * Where git expects a ref, name or key the refusal happens BEFORE git
     * runs, and says why.
     */
    public function testRefusalNamesTheLeadingDash(): void
    {
        $handlers = new GitCommandHandlers($this->root);

        $result = $handlers->gitShow('--output=' . $this->tempDir . '/x');

        $this->assertFalse($result->isSuccess());
        $this->assertStringContainsString("must not start with '-'", (string) $result->error);
        $this->assertSame('git_show', $result->operation);
        $this->assertSame('git_history', $result->group);
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function invalidBranchNames(): iterable
    {
        foreach (['a..b', 'a b', 'x.lock', '.hidden', 'a/', '/a', 'a//b', '@', 'a@{1}', 'a~1', 'a^', 'a:b', 'a?', 'a*', 'a[', 'a\\b', 'trailing.', "a\tb"] as $name) {
            yield $name => [$name];
        }
    }

    #[DataProvider('invalidBranchNames')]
    public function testInvalidBranchNameIsRefusedWithoutTouchingTheRepository(string $name): void
    {
        $before = $this->repoState($this->root);

        $result = (new GitCommandHandlers($this->root))->gitBranchCreate($name);

        $this->assertFalse($result->isSuccess());
        $this->assertSame($before, $this->repoState($this->root));
        $this->assertNull(GitArgument::optionError($name, 'x'), 'covered by the branch-name rules, not the dash rule');
        $this->assertNotNull(GitArgument::branchNameError($name));
    }

    /**
     * The guard must not cost the tools their ordinary use.
     */
    public function testOrdinaryArgumentsStillWork(): void
    {
        $handlers = new GitCommandHandlers($this->root);

        $this->assertTrue($handlers->gitShow('HEAD')->isSuccess());
        $this->assertSame('Test User', trim((string) $handlers->gitConfigGet('user.name')->output));
        $this->assertTrue($handlers->gitBranchCreate('feature/ok-1')->isSuccess());
        $this->assertTrue($handlers->gitBranchCheckout('feature/ok-1')->isSuccess());
        $this->assertTrue($handlers->gitBranchCheckout('topic/two', createBranch: true)->isSuccess());
        $this->assertTrue($handlers->gitBranchDelete('feature/ok-1')->isSuccess());
        $this->assertTrue($handlers->gitAdd(['untracked.txt'])->isSuccess());
        $this->assertStringContainsString('A  untracked.txt', $this->git($this->root, 'status', '--porcelain'));

        file_put_contents($this->root . '/tracked.txt', "changed\n");
        $this->assertTrue($handlers->gitBranchCheckout('tracked.txt')->isSuccess(), 'checkout of a FILE still works');
        $this->assertSame("tracked\n", file_get_contents($this->root . '/tracked.txt'));

        $this->assertTrue($handlers->gitReset('HEAD', 'mixed')->isSuccess());
        $this->assertTrue($handlers->gitRevert('HEAD', noCommit: true)->isSuccess());
        $this->assertStringContainsString('D  tracked.txt', $this->git($this->root, 'status', '--porcelain'));
    }

    // =========================================================================
    // Path containment
    // =========================================================================

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function escapingPaths(): iterable
    {
        yield 'filesystem root' => ['/'];
        yield 'relative sibling repository' => ['../../other'];
        yield 'parent of the root' => ['..'];
        yield 'symlink out of the root' => ['escape'];
        yield 'missing directory' => ['does-not-exist'];
        yield 'absolute other repository' => ['{other}'];
    }

    #[DataProvider('escapingPaths')]
    public function testPathOutsideTheRootIsRefused(string $path): void
    {
        $path = str_replace('{other}', $this->other, $path);
        $otherBefore = $this->repoState($this->other);
        $handlers = new GitCommandHandlers($this->root);

        $status = $handlers->gitStatus($path);
        $reset = $handlers->gitReset('HEAD', 'hard', $path);
        $branch = $handlers->gitBranchCreate('planted', path: $path);

        foreach ([$status, $reset, $branch] as $result) {
            $this->assertFalse($result->isSuccess());
            $this->assertStringContainsString('inside the repository root', (string) $result->error);
        }
        $this->assertSame($otherBefore, $this->repoState($this->other), 'the other repository must be untouched');
    }

    public function testPathInsideTheRootStillWorks(): void
    {
        $handlers = new GitCommandHandlers($this->root);

        $relative = $handlers->gitLog(path: 'nested');
        $absolute = $handlers->gitLog(path: $this->root . '/nested');
        $self = $handlers->gitStatus('.');

        $this->assertTrue($relative->isSuccess(), (string) $relative->error);
        $this->assertSame('initial', $relative->output[0]['message'] ?? null);
        $this->assertTrue($absolute->isSuccess(), (string) $absolute->error);
        $this->assertTrue($self->isSuccess(), (string) $self->error);
        $this->assertStringContainsString('untracked.txt', (string) $self->output);
    }

    public function testRootDefaultsToTheProcessCwdWhenNoneIsConfigured(): void
    {
        // setUp() made the fixture root the process CWD.
        $handlers = new GitCommandHandlers();

        $this->assertTrue($handlers->gitStatus('nested')->isSuccess());
        $this->assertFalse($handlers->gitStatus($this->other)->isSuccess());
        $this->assertFalse($handlers->gitStatus('..')->isSuccess());
    }

    // =========================================================================
    // Worktree location
    // =========================================================================

    public function testWorktreeBesideTheRootIsAllowed(): void
    {
        $result = (new GitCommandHandlers($this->root))->gitWorktreeAdd('../sibling', 'wt-branch');

        $this->assertTrue($result->isSuccess(), (string) $result->error);
        $this->assertDirectoryExists($this->tempDir . '/work/sibling');
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function escapingWorktreePaths(): iterable
    {
        yield 'outside the root parent' => ['{tmp}/other-wt'];
        yield 'relative climb' => ['../../far-wt'];
        yield 'through a symlink' => ['escape/wt'];
        yield 'parent does not exist' => ['../missing/wt'];
        yield 'dot-dot name' => ['../..'];
    }

    #[DataProvider('escapingWorktreePaths')]
    public function testWorktreeOutsideTheBoundIsRefused(string $worktreePath): void
    {
        $worktreePath = str_replace('{tmp}', $this->tempDir, $worktreePath);
        $before = $this->repoState($this->root);

        $result = (new GitCommandHandlers($this->root))->gitWorktreeAdd($worktreePath);

        $this->assertFalse($result->isSuccess());
        $this->assertSame($before, $this->repoState($this->root));
        $this->assertDirectoryDoesNotExist($this->tempDir . '/other-wt');
        $this->assertDirectoryDoesNotExist($this->tempDir . '/far-wt');
        $this->assertDirectoryDoesNotExist($this->other . '/wt');
    }

    // =========================================================================
    // MCP dispatch
    // =========================================================================

    public function testServerDispatchCarriesTheSameGuards(): void
    {
        $server = new GitMcpServer(new GitCommandHandlers($this->root));
        $out = $this->tempDir . '/via-server.txt';

        $show = $server->callTool('gitShow', ['ref' => '--output=' . $out]);
        $escape = $server->callTool('gitStatus', ['path' => '/']);

        $this->assertFalse($show['success']);
        $this->assertFileDoesNotExist($out);
        $this->assertFalse($escape['success']);
        $this->assertTrue($server->callTool('gitStatus', ['path' => 'nested'])['success']);
    }

    public function testServerRefusesArgumentsTheHandlerDoesNotDeclare(): void
    {
        $server = new GitMcpServer(new GitCommandHandlers($this->root));
        $out = $this->tempDir . '/positional.txt';

        $unknown = $server->callTool('gitStatus', ['cwd' => '/']);
        $positional = $server->callTool('gitShow', ['--output=' . $out]);

        $this->assertFalse($unknown['success']);
        $this->assertStringContainsString("Unknown argument 'cwd'", $unknown['error']);
        $this->assertFalse($positional['success']);
        $this->assertStringContainsString("Unknown argument '0'", $positional['error']);
        $this->assertFileDoesNotExist($out);
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    private function invoke(GitCommandHandlers $h, string $handler, string $value): GitOperationResult
    {
        return match ($handler) {
            'gitShow' => $h->gitShow($value),
            'gitConfigGet' => $h->gitConfigGet($value),
            'gitRevert' => $h->gitRevert($value),
            'gitReset' => $h->gitReset($value),
            'gitResetHard' => $h->gitReset($value, 'hard'),
            'gitBranchCreate' => $h->gitBranchCreate($value),
            'gitBranchCreateCheckout' => $h->gitBranchCreate($value, checkout: true),
            'gitBranchDelete' => $h->gitBranchDelete($value),
            'gitBranchDeleteForce' => $h->gitBranchDelete($value, force: true),
            'gitBranchCheckout' => $h->gitBranchCheckout($value),
            'gitBranchCheckoutCreate' => $h->gitBranchCheckout($value, createBranch: true),
            'gitWorktreeAddPath' => $h->gitWorktreeAdd($value),
            'gitWorktreeAddBranch' => $h->gitWorktreeAdd('../wt-from-branch', $value),
            'gitWorktreeRemove' => $h->gitWorktreeRemove($value),
            'gitFlowFeature' => $h->gitFlowFeature('start', $value),
            'gitFlowRelease' => $h->gitFlowRelease('start', $value),
            'gitFlowHotfix' => $h->gitFlowHotfix('start', $value),
            'gitLfsTrack' => $h->gitLfsTrack($value),
            'gitLfsUntrack' => $h->gitLfsUntrack($value),
            'gitAdd' => $h->gitAdd([$value]),
            'gitBlame' => $h->gitBlame($value),
        };
    }

    /**
     * Everything an injected option could have changed: HEAD, the branch
     * set, the index and working tree, and the registered worktrees.
     *
     * @return array<string, string>
     */
    private function repoState(string $repo): array
    {
        return [
            'head' => $this->git($repo, 'rev-parse', 'HEAD'),
            'branches' => $this->git($repo, 'branch', '--list', '--format=%(refname)'),
            'status' => $this->git($repo, 'status', '--porcelain'),
            'worktrees' => $this->git($repo, 'worktree', 'list', '--porcelain'),
        ];
    }

    private function initRepo(string $repo): void
    {
        if (!is_dir($repo)) {
            mkdir($repo, 0777, true);
        }
        $this->git($repo, 'init', '--quiet');
        $this->git($repo, 'config', 'user.email', 'test@example.com');
        $this->git($repo, 'config', 'user.name', 'Test User');
        file_put_contents($repo . '/tracked.txt', "tracked\n");
        $this->git($repo, 'add', 'tracked.txt');
        $this->git($repo, 'commit', '--quiet', '-m', 'initial');
    }

    private function git(string $repo, string ...$args): string
    {
        $command = '/usr/bin/git -C ' . escapeshellarg($repo);
        foreach ($args as $arg) {
            $command .= ' ' . escapeshellarg($arg);
        }
        exec($command . ' 2>&1', $output);

        return trim(implode("\n", $output));
    }
}
