<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Cli;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Agents\WorktreeManager;
use SugarCraft\Code\Cli\ArgvParser;
use SugarCraft\Code\Cli\MainWorktree;

/**
 * C-AGT-6.c: `--worktree [name]` seats the WHOLE main session in a git
 * worktree and honours the release law on the way out — a tree that holds
 * nothing is gone at exit, a tree that holds work stays and announces itself.
 *
 * Everything runs against a throwaway repository under the system temp dir,
 * with CANDY_CODE_WORKTREES_DIR pointed INTO that dir so no case can reach
 * the real checkout's base even by env accident. The shutdown-hook half
 * needs a process boundary (that is what a shutdown function is); the child
 * harnesses the same way the bin tests do, and the parent-side `release()`
 * is asserted directly for the matrix, because it is the very function the
 * hook calls.
 */
final class MainWorktreeTest extends TestCase
{
    private string $root;
    private string $repo;
    private string|false $savedWorktreesDir;

    protected function setUp(): void
    {
        parent::setUp();

        if (!self::gitAvailable()) {
            $this->markTestSkipped('git is not available on PATH');
        }

        $this->root = sys_get_temp_dir() . '/cc-mainworktree-' . getmypid() . '-' . bin2hex(random_bytes(5));
        mkdir($this->root . '/home', 0o700, true);
        $this->repo = $this->root . '/repo';
        mkdir($this->repo, 0o700, true);

        self::execGit($this->repo, 'init', '-q', '-b', 'main', '.');
        self::writeAndCommit($this->repo, 'file.txt', "one\n", 'initial');

        $this->savedWorktreesDir = getenv('CANDY_CODE_WORKTREES_DIR');
        putenv('CANDY_CODE_WORKTREES_DIR=' . $this->root . '/wt-base');

        MainWorktree::resetForTesting();
    }

    protected function tearDown(): void
    {
        MainWorktree::resetForTesting();

        if ($this->savedWorktreesDir === false) {
            putenv('CANDY_CODE_WORKTREES_DIR');
        } else {
            putenv('CANDY_CODE_WORKTREES_DIR=' . $this->savedWorktreesDir);
        }

        exec('rm -rf ' . escapeshellarg($this->root) . ' 2>&1');

        parent::tearDown();
    }

    public function testEnterCreatesTheNamedWorktreeAndAnswersItsPath(): void
    {
        $path = MainWorktree::enter($this->repo, 'fix-bug');

        $this::assertDirectoryExists($path);
        $this->assertSame('main-fix-bug', basename($path));
        $this->assertStringStartsWith($this->root . '/wt-base/', $path, 'the env base is honoured');

        // The branch is the namespaced one, and it is a real branch whose tip
        // sits at the repository HEAD it was cut from.
        $verify = self::runGit($this->repo, 'rev-parse', '--verify', 'candy-code/fix-bug');
        $this->assertSame(0, $verify['code'], 'branch candy-code/fix-bug exists: ' . $verify['out']);
        $this->assertSame(
            trim(self::runGit($this->repo, 'rev-parse', 'main')['out']),
            trim($verify['out']),
            'the branch starts at HEAD',
        );

        $this->assertNull(MainWorktree::release());
    }

    public function testAnUntouchedWorktreeIsReleasedSilently(): void
    {
        $path = MainWorktree::enter($this->repo, 'gone');

        $this->assertNull(MainWorktree::release(), 'nothing to announce when the tree is dropped');
        self::assertDirectoryDoesNotExist($path);
        $this->assertArrayNotHasKey('main-gone', self::registry($this->repo));
        $this->assertNotSame(0, self::runGit($this->repo, 'rev-parse', '--verify', 'candy-code/gone')['code'], 'the branch is gone with it');
    }

    public function testACommitInsideTheWorktreeKeepsItAndAnnouncesIt(): void
    {
        $path = MainWorktree::enter($this->repo, 'stays');
        self::writeAndCommit($path, 'work.txt', "made\n", 'a commit of its own');

        $announce = MainWorktree::release();

        $this::assertIsString($announce);
        $this->assertStringContainsString($path, $announce);
        $this->assertStringContainsString('candy-code/stays', $announce);
        $this->assertStringContainsString('--worktree stays', $announce, 'the announce says how to get back to the tree');
        self::assertDirectoryExists($path);
        $this->assertSame(0, self::runGit($this->repo, 'rev-parse', '--verify', 'candy-code/stays')['code']);

        // Released-once law: a second call cannot re-drop or re-announce.
        $this->assertNull(MainWorktree::release());
    }

    public function testAnUncommittedEditKeepsTheWorktreeToo(): void
    {
        $path = MainWorktree::enter($this->repo, 'dirty');
        file_put_contents($path . '/file.txt', "edited in the session\n");

        $announce = MainWorktree::release();

        $this::assertIsString($announce);
        $this->assertStringContainsString($path, $announce);
        self::assertDirectoryExists($path);
    }

    public function testOutsideAGitTreeTheFlagIsRefused(): void
    {
        $notRepo = $this->root . '/plain-dir';
        mkdir($notRepo, 0o700, true);

        $thrown = null;
        try {
            MainWorktree::enter($notRepo, 'x');
        } catch (\RuntimeException $caught) {
            // Capture only: an assertion inside this catch could be swallowed.
            $thrown = $caught;
        }
        $this->assertInstanceOf(
            \RuntimeException::class,
            $thrown,
            'enter() answered for a directory that is not a git work tree',
        );
        $this->assertStringContainsString('git work tree', $thrown->getMessage());
        $this->assertStringContainsString($notRepo, $thrown->getMessage(), 'the refusal names what is not a repository');

        // A launch launched at a repo subdirectory resolves the TOPLEVEL —
        // the same root the other WorktreeManager builders use.
        mkdir($this->repo . '/sub', 0o700, true);
        $path = MainWorktree::enter($this->repo . '/sub', 'sub-root');
        $this->assertStringStartsWith($this->root . '/wt-base/main-sub-root', $path);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function badNames(): array
    {
        return [
            'empty'         => [''],
            'traversal'     => ['a..b'],
            'path-separator'=> ['a/b'],
            'backslash'     => ['a\\b'],
            'dotted-start'  => ['.hidden'],
            'dash-start'    => ['-o'],
            'space'         => ['two words'],
        ];
    }

    /**
     * A name is a directory component AND a branch component; the union of
     * what both allow is smaller than what createWorktree would accept for an
     * agent id, and it is enforced HERE so the user's typo gets the flag's
     * own message, not a git-flavoured one from the manager.
     *
     * @dataProvider badNames
     */
    public function testNamesThePathAndRefRulesRefuseAreRejected(string $name): void
    {
        $thrown = null;
        try {
            MainWorktree::enter($this->repo, $name);
        } catch (\RuntimeException $caught) {
            // Capture only: an assertion inside this catch could be swallowed.
            $thrown = $caught;
        }
        $this->assertInstanceOf(\RuntimeException::class, $thrown, "enter() accepted the name '{$name}'");
        $this->assertStringContainsString('--worktree', $thrown->getMessage());
        $this->assertStringContainsString('begin with a letter or digit', $thrown->getMessage());
    }

    public function testABareFlagAutoNamesTheTree(): void
    {
        $path = MainWorktree::enter($this->repo, null);

        $this::assertDirectoryExists($path);
        $name = substr(basename($path), strlen('main-'));
        $this->assertMatchesRegularExpression('/^\d{6}-\d{6}-[0-9a-f]{6}$/', $name, 'the auto name is sortable and collision-resistant');
        self::assertSame(0, self::runGit($this->repo, 'rev-parse', '--verify', 'candy-code/' . $name)['code'], 'the branch carries the auto name');
        $this->assertNull(MainWorktree::release());
    }

    public function testASecondLaunchWithTheSameNameReusesTheTreeWithoutArmingRelease(): void
    {
        $path = MainWorktree::enter($this->repo, 'shared');
        file_put_contents($path . '/session.txt', "first launch was here\n");
        $this->assertIsString(MainWorktree::release(), 'the uncommitted edit keeps the tree and says so');
        // The first release ran (tree kept by the uncommitted edit above); a
        // fresh "launch" reusing the name must find the SAME tree with the
        // file in it, and must not decide the reuse's exit drops it.
        MainWorktree::resetForTesting();

        $again = MainWorktree::enter($this->repo, 'shared');

        $this->assertSame($path, $again);
        $this->assertSame("first launch was here\n", file_get_contents($again . '/session.txt'));
        $this->assertNull(MainWorktree::release(), 'the reuse arms no release law of its own');
        self::assertDirectoryExists($again);
    }

    public function testTheRootSwapKeepsTheWorktreeFieldsAndTheRestOfTheLaunch(): void
    {
        $args = ArgvParser::parse(['candy-code', '--worktree', 'fix-bug', '-p', 'x']);
        $swapped = $args->withRoot('/somewhere/wt/main-fix-bug', $args->positionals);

        $this->assertSame('/somewhere/wt/main-fix-bug', $swapped->root);
        $this->assertTrue($swapped->worktreeRequested, 'bin arms the release hook off these same fields');
        $this->assertSame('fix-bug', $swapped->worktreeName);
        $this->assertTrue($swapped->promptRequested);
        $this->assertSame('x', $swapped->prompt);
    }

    /**
     * THE HOOK, ACROSS A REAL PROCESS EXIT (two directions). A clean `exit(0)`
     * runs the shutdown hook: untouched → tree gone and stderr silent; edited
     * → tree kept AND the announce lands on stderr with the \r\n padding the
     * class doc-block states for the raw-mode case.
     */
    public function testTheShutdownHookReleasesOrAnnouncesOnRealProcessExit(): void
    {
        $script = $this->root . '/child.php';
        $autoload = var_export(dirname(__DIR__, 2) . '/vendor/autoload.php', true);
        file_put_contents($script, <<<PHP
            <?php
            require {$autoload};
            \$p = SugarCraft\\Code\\Cli\\MainWorktree::enter(\$argv[1], \$argv[2]);
            if (\$argv[3] !== '') {
                file_put_contents(\$p . '/' . \$argv[3], "touched\\n");
            }
            fwrite(STDOUT, \$p);

            PHP);

        $clean = $this->runChild($script, [$this->repo, 'hook-clean', '']);
        $this->assertStringNotContainsString('left in place', $clean['err'], 'a dropped tree says nothing');
        self::assertDirectoryDoesNotExist($this->root . '/wt-base/main-hook-clean');

        $dirty = $this->runChild($script, [$this->repo, 'hook-dirty', 'note.txt']);
        $this->assertStringContainsString('left in place', $dirty['err'], 'a kept tree announces itself: ' . $dirty['err']);
        $this->assertStringContainsString('main-hook-dirty', $dirty['err']);
        $this->assertStringContainsString("\r\n", $dirty['err'], 'the announce is padded for raw mode');
        self::assertDirectoryExists($this->root . '/wt-base/main-hook-dirty');
    }

    /**
     * THE PID GUARD IS STRUCTURAL (rule 15's "what cannot be observed here is
     * pinned where it can"). AgentWorkerPool fork children inherit shutdown
     * closures and exit() through them on every completed stage; without the
     * getmypid() check inside the hook each child's exit would fire the
     * PARENT's release — the live tree deleted while the session still runs.
     * The check is one line; a mutation that removes it reddens this pin.
     */
    public function testTheShutdownHookCarriesTheForkPidGuard(): void
    {
        $src = (string) file_get_contents(__DIR__ . '/../../src/Cli/MainWorktree.php');

        $this->assertMatchesRegularExpression(
            '/register_shutdown_function\(.*?getmypid\(\)\s*!==\s*\$installPid/s',
            $src,
            'the release hook must check the installing pid before acting',
        );
        $this->assertMatchesRegularExpression(
            '/\$entered === null \|\| self::\$released/',
            $src,
            'the release step must not run twice in the parent (normal completion AND a fatal both reach shutdown)',
        );
    }

    // -----------------------------------------------------------------------

    /**
     * @param array<int, string> $argv
     *
     * @return array{code: int, out: string, err: string}
     */
    private function runChild(string $script, array $argv): array
    {
        $proc = proc_open(
            [PHP_BINARY, $script, ...$argv],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $this->root,
            ['HOME' => $this->root . '/home', 'CANDY_CODE_WORKTREES_DIR' => $this->root . '/wt-base', 'PATH' => getenv('PATH') ?: '/usr/bin:/bin'],
        );
        $this::assertIsResource($proc);
        $out = (string) stream_get_contents($pipes[1]);
        $err = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($proc);

        return ['code' => $code, 'out' => $out, 'err' => $err];
    }

    /**
     * @param list<string> $args
     *
     * @return array{code: int, out: string}
     */
    private static function runGit(string $cwd, string ...$args): array
    {
        $proc = proc_open(
            ['git', '-c', 'user.name=MainWorktreeTest', '-c', 'user.email=test@example.invalid', '-C', $cwd, ...$args],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $cwd,
            ['PATH' => getenv('PATH') ?: '/usr/bin:/bin', 'HOME' => getenv('HOME') ?: '/tmp'],
        );
        $out = (string) stream_get_contents($pipes[1]) . (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return ['code' => proc_close($proc), 'out' => $out];
    }

    /** @param list<string> $args */
    private static function execGit(string $cwd, string ...$args): string
    {
        $r = self::runGit($cwd, ...$args);
        if ($r['code'] !== 0) {
            throw new \RuntimeException('git ' . implode(' ', $args) . " failed: " . $r['out']);
        }

        return trim($r['out']);
    }

    private static function writeAndCommit(string $cwd, string $file, string $body, string $message): void
    {
        file_put_contents($cwd . '/' . $file, $body);
        self::execGit($cwd, 'add', '--', $file);
        self::execGit($cwd, 'commit', '-q', '-m', $message);
    }

    /** @return array<string, mixed> */
    private static function registry(string $repoRoot): array
    {
        return WorktreeManager::new($repoRoot)->listWorktrees();
    }

    private static function gitAvailable(): bool
    {
        static $ok = null;
        if ($ok === null) {
            $ok = self::runGit(sys_get_temp_dir(), '--version')['code'] === 0;
        }

        return $ok;
    }
}
