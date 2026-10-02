<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\MCP;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\MCP\GitCommandHandlers;
use SugarCraft\Crush\MCP\McpClient;
use SugarCraft\Crush\Support\ProcessContainment;

/**
 * How the git MCP handlers RUN git (audit GIT-2): both pipes drained
 * concurrently, a wall-clock bound that kills the whole process group, and
 * the inherited environment rather than a two-key PATH/HOME block.
 *
 * Every fixture is a real repository with a real `pre-commit` hook, because
 * the defect only exists between two processes: a hook that fills the stderr
 * pipe while the parent waits on stdout.
 *
 * @see GitCommandHandlers
 */
final class GitCommandHandlersProcessTest extends TestCase
{
    /** Variables this suite putenv()s; restored to their prior values in tearDown(). */
    private const TOUCHED_ENV = [
        'GIT_CONFIG_GLOBAL',
        'GIT_CONFIG_NOSYSTEM',
        'GIT_DIR',
        'SSH_AUTH_SOCK',
        'SUGARCRUSH_GIT2_PROBE',
    ];

    private string $tempDir;
    private string $repoPath;

    /** @var array<string, string|false> */
    private array $savedEnv = [];

    protected function setUp(): void
    {
        parent::setUp();

        foreach (self::TOUCHED_ENV as $name) {
            $this->savedEnv[$name] = getenv($name);
        }
        // Hermetic: a developer's global `commit.gpgsign=true` or hooksPath
        // must not decide whether these commits succeed. Inherited by the
        // handler's child now that the environment is passed through.
        putenv('GIT_CONFIG_GLOBAL=/dev/null');
        putenv('GIT_CONFIG_NOSYSTEM=1');
        putenv('GIT_DIR');

        $this->tempDir = sys_get_temp_dir() . '/git_handlers_process_test_' . uniqid('', true);
        $this->repoPath = $this->tempDir . '/repo';
        mkdir($this->repoPath, 0777, true);

        $git = '/usr/bin/git -C ' . escapeshellarg($this->repoPath);
        exec('/usr/bin/git init --quiet ' . escapeshellarg($this->repoPath) . ' 2>&1');
        exec($git . ' config user.email "test@example.com" 2>&1');
        exec($git . ' config user.name "Test User" 2>&1');
        exec($git . ' config core.hooksPath .git/hooks 2>&1');

        file_put_contents($this->repoPath . '/staged.txt', "content\n");
        exec($git . ' add staged.txt 2>&1');
    }

    protected function tearDown(): void
    {
        foreach ($this->savedEnv as $name => $value) {
            putenv($value === false ? $name : "{$name}={$value}");
        }

        if (is_dir($this->tempDir)) {
            $files = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($this->tempDir, \RecursiveDirectoryIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST,
            );
            foreach ($files as $file) {
                is_dir($file->getPathname()) && !is_link($file->getPathname())
                    ? rmdir($file->getPathname())
                    : unlink($file->getPathname());
            }
            rmdir($this->tempDir);
        }

        parent::tearDown();
    }

    private function installPreCommitHook(string $body): void
    {
        $hook = $this->repoPath . '/.git/hooks/pre-commit';
        file_put_contents($hook, "#!/bin/sh\n" . $body . "\n");
        chmod($hook, 0755);
    }

    /** HEAD's subject, or '' while the repository has no commit yet. */
    private function headSubject(): string
    {
        $lines = [];
        $exit = 0;
        exec('/usr/bin/git -C ' . escapeshellarg($this->repoPath) . ' log -1 --format=%s 2>&1', $lines, $exit);

        return $exit === 0 ? trim(implode("\n", $lines)) : '';
    }

    /**
     * THE REPRO. Before GIT-2 the parent read stdout to EOF first; the hook
     * blocked writing its 300 KB once the 64 KiB stderr pipe filled, git never
     * exited, and gitCommit() never returned. The generous handler timeout is
     * only a backstop — the assertion is that the commit completes on its own.
     */
    public function testChattyPreCommitHookDoesNotDeadlockTheCommit(): void
    {
        $this->installPreCommitHook("head -c 300000 /dev/zero | tr '\\0' 'e' >&2\nexit 0");
        $handlers = new GitCommandHandlers($this->repoPath, timeoutSeconds: 30.0);

        $started = microtime(true);
        $result = $handlers->gitCommit('chatty hook commit');
        $elapsed = microtime(true) - $started;

        $this->assertTrue($result->isSuccess(), 'commit failed: ' . substr((string) $result->error, -300));
        $this->assertLessThan(15.0, $elapsed, 'a 300 KB stderr hook must not stall the commit');
        $this->assertSame('chatty hook commit', $this->headSubject());
    }

    /**
     * A failing hook's explanation is at the END of its output, so the error
     * carries the stderr TAIL — bounded, and saying how much was dropped.
     */
    public function testFailingHookErrorCarriesTheBoundedStderrTail(): void
    {
        $this->installPreCommitHook(
            "head -c 300000 /dev/zero | tr '\\0' 'e' >&2\necho 'HOOK-REFUSED-THE-COMMIT' >&2\nexit 1",
        );
        $handlers = new GitCommandHandlers($this->repoPath, timeoutSeconds: 30.0);

        $result = $handlers->gitCommit('refused commit');

        $this->assertTrue($result->isFailure());
        $error = (string) $result->error;
        $this->assertStringEndsWith('HOOK-REFUSED-THE-COMMIT', $error);
        $this->assertStringContainsString('earlier bytes of stderr omitted', $error);
        $this->assertLessThanOrEqual(GitCommandHandlers::STDERR_TAIL_BYTES + 100, strlen($error));
        $this->assertSame('', $this->headSubject(), 'the refused commit must not exist');
    }

    /**
     * A hook that outlives the bound: the call fails promptly saying it timed
     * out, and the hook's BACKGROUNDED child dies too — it is in git's process
     * group, which is what the timeout kills. Killing only git would orphan it
     * (and it would hold the pipes open).
     */
    public function testTimeoutFailsPromptlyAndKillsTheHookProcessGroup(): void
    {
        $pidFile = $this->tempDir . '/grandchild.pid';
        $this->installPreCommitHook(
            "echo 'hook started' >&2\nsleep 30 &\necho \$! > " . escapeshellarg($pidFile) . "\nsleep 30",
        );
        $handlers = new GitCommandHandlers($this->repoPath, timeoutSeconds: 1.0);

        $started = microtime(true);
        $result = $handlers->gitCommit('slow hook commit');
        $elapsed = microtime(true) - $started;

        $this->assertTrue($result->isFailure());
        $this->assertStringContainsString('timed out after 1s', (string) $result->error);
        $this->assertStringContainsString('hook started', (string) $result->error, 'stderr tail rides the timeout error');
        $this->assertLessThan(8.0, $elapsed, 'a one-second bound plus the TERM/KILL ladder must stay bounded');

        if (ProcessContainment::detachedSpawnBinary() === '' || !function_exists('posix_kill')) {
            $this->markTestIncomplete('no usable setsid(1)/posix here: git does not lead its own group to kill');
        }

        $this->assertFileExists($pidFile);
        $grandchild = (int) trim((string) file_get_contents($pidFile));
        $this->assertGreaterThan(0, $grandchild);
        $this->assertTrue(
            $this->awaitGone($grandchild, 3.0),
            "the hook's backgrounded child (pid {$grandchild}) survived the timeout",
        );
    }

    /**
     * Audit R18: the bound is configurable from `.mcp.json`. A `git` entry's
     * `timeout` reaches the handlers McpClient builds, so a hook that sleeps
     * well past it is killed at the configured second — before the fix the
     * key was ignored, the 300 s default applied, and this commit waited the
     * hook out and succeeded.
     */
    public function testMcpJsonTimeoutBoundsTheGitCallMcpClientBuilds(): void
    {
        $this->installPreCommitHook("echo 'slow hook started' >&2\nsleep 20");
        $configPath = $this->tempDir . '/.mcp.json';
        file_put_contents($configPath, (string) json_encode([
            'mcpServers' => [
                'repo' => ['type' => 'git', 'path' => $this->repoPath, 'timeout' => 1],
            ],
        ]));
        $client = new McpClient($configPath, unrestricted: true);
        $client->startServers();

        $started = microtime(true);
        $result = $client->callTool('repo', 'gitCommit', ['message' => 'bounded by .mcp.json']);
        $elapsed = microtime(true) - $started;

        $this->assertFalse($result['success'], 'the hook outlived the configured bound, so the commit must fail');
        $this->assertStringContainsString('timed out after 1s', (string) $result['error']);
        $this->assertLessThan(8.0, $elapsed, 'the configured one-second bound, not the 300 s default');
        $this->assertSame('', $this->headSubject(), 'the timed-out commit must not exist');
    }

    /**
     * The child sees the inherited environment — set via putenv() at call
     * time, so it is not a snapshot — and git's no-prompt switch.
     */
    public function testHookSeesTheInheritedEnvironment(): void
    {
        putenv('SUGARCRUSH_GIT2_PROBE=probe-value');
        putenv('SSH_AUTH_SOCK=/tmp/sugarcrush-git2-agent.sock');
        $envFile = $this->tempDir . '/hook.env';
        $this->installPreCommitHook(
            'printf \'%s|%s|%s\' "$SUGARCRUSH_GIT2_PROBE" "$SSH_AUTH_SOCK" "$GIT_TERMINAL_PROMPT" > '
            . escapeshellarg($envFile),
        );
        $handlers = new GitCommandHandlers($this->repoPath);

        $result = $handlers->gitCommit('env commit');

        $this->assertTrue($result->isSuccess(), 'commit failed: ' . (string) $result->error);
        $this->assertSame(
            'probe-value|/tmp/sugarcrush-git2-agent.sock|0',
            (string) file_get_contents($envFile),
        );
    }

    /**
     * Inheriting the environment must not inherit WHICH REPOSITORY: a
     * `GIT_DIR` exported by a parent (sugar-crush launched from a git hook)
     * would otherwise aim every tool past the configured root.
     */
    public function testRepositoryLocatingVariablesAreNotInherited(): void
    {
        putenv('GIT_DIR=' . $this->tempDir . '/elsewhere/.git');
        $handlers = new GitCommandHandlers(cwd: $this->repoPath);

        $result = $handlers->gitStatus();

        $this->assertTrue($result->isSuccess(), 'status failed: ' . (string) $result->error);
        $this->assertStringContainsString('staged.txt', (string) $result->output);
    }

    public function testTimeoutMustBePositive(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new GitCommandHandlers($this->repoPath, timeoutSeconds: 0.0);
    }

    public function testConfiguredTimeoutReadsAPositiveNumberOfSeconds(): void
    {
        $this->assertSame(42.0, GitCommandHandlers::configuredTimeout(42));
        $this->assertSame(1.5, GitCommandHandlers::configuredTimeout(1.5));
        $this->assertSame(120.0, GitCommandHandlers::configuredTimeout('120'), 'a numeric string reads like startTimeout does');
        $this->assertSame(42.0, (new GitCommandHandlers($this->repoPath, GitCommandHandlers::configuredTimeout(42)))->timeoutSeconds());
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function unusableTimeouts(): iterable
    {
        yield 'absent' => [null];
        yield 'zero' => [0];
        yield 'negative' => [-5];
        yield 'not a number' => ['10m'];
        yield 'boolean' => [true];
        yield 'list' => [[30]];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('unusableTimeouts')]
    public function testConfiguredTimeoutKeepsTheDefaultForAnUnusableValue(mixed $value): void
    {
        $this->assertSame(
            GitCommandHandlers::DEFAULT_TIMEOUT_SECONDS,
            GitCommandHandlers::configuredTimeout($value),
            'the config can move the bound but never switch it off',
        );
    }

    /**
     * Whether $pid is gone (or a zombie awaiting its reparented reap) within
     * $seconds. A zombie still answers signal 0, so /proc's state decides on
     * Linux.
     */
    private function awaitGone(int $pid, float $seconds): bool
    {
        $deadline = microtime(true) + $seconds;
        do {
            if (!@posix_kill($pid, 0)) {
                return true;
            }
            $stat = @file_get_contents("/proc/{$pid}/stat");
            if (is_string($stat) && preg_match('/\) ([A-Za-z]) /', $stat, $m) === 1 && $m[1] === 'Z') {
                return true;
            }
            usleep(20_000);
        } while (microtime(true) < $deadline);

        return false;
    }
}
