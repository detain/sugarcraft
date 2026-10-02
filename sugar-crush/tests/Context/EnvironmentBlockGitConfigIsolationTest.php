<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Context;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Context\EnvironmentBlock;

/**
 * The env block's git reads are a reading of the repository, not of the
 * user's git config, and they never write the index (audit 15d-12).
 *
 * Each test points GIT_CONFIG_GLOBAL at a file of its own and sets
 * GIT_CONFIG_NOSYSTEM, so the host's config can neither cause nor mask the
 * behaviour under test; both are restored in tearDown().
 */
final class EnvironmentBlockGitConfigIsolationTest extends TestCase
{
    private const ENV_NAMES = ['GIT_CONFIG_GLOBAL', 'GIT_CONFIG_NOSYSTEM'];

    private string $base;

    private string $repo;

    private string $globalConfig;

    /** @var array<string, string|false> */
    private array $savedEnv = [];

    protected function setUp(): void
    {
        $this->base = sys_get_temp_dir() . '/env_git_isolation_' . uniqid((string) getmypid(), true);
        $this->repo = $this->base . '/repo';
        $this->globalConfig = $this->base . '/gitconfig';
        mkdir($this->repo, 0777, true);
        file_put_contents($this->globalConfig, '');

        foreach (self::ENV_NAMES as $name) {
            $this->savedEnv[$name] = getenv($name);
        }
        putenv('GIT_CONFIG_GLOBAL=' . $this->globalConfig);
        putenv('GIT_CONFIG_NOSYSTEM=1');

        if ($this->git(['--version']) !== 0) {
            $this->markTestSkipped('git is unavailable in this environment');
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->savedEnv as $name => $value) {
            putenv($value === false ? $name : $name . '=' . $value);
        }
        self::removeTree($this->base);
    }

    public function testUserColourAndExternalDiffConfigNeverReachThePrompt(): void
    {
        $marker = $this->base . '/external-diff-ran';
        $tool = $this->base . '/extdiff.sh';
        file_put_contents($tool, "#!/bin/sh\necho EXTERNAL-DIFF-TOOL \"\$@\"\ntouch " . escapeshellarg($marker) . "\n");
        chmod($tool, 0755);
        // `color.diff` as well as `color.ui`: the per-command slot outranks
        // `color.ui`, so a fix that only pinned `color.ui` must still red here.
        file_put_contents($this->globalConfig, "[color]\n\tui = always\n\tdiff = always\n"
            . "[diff]\n\texternal = {$tool}\n[core]\n\tquotepath = true\n");

        $this->initRepo();
        file_put_contents($this->repo . '/tracked.txt', "one\ntwo\nthree\n");
        $this->commitAll('initial import');
        file_put_contents($this->repo . '/staged.txt', "staged line\n");
        $this->assertSame(0, $this->git(['add', 'staged.txt']));
        file_put_contents($this->repo . '/tracked.txt', "one\nTWO\nthree\n");
        file_put_contents($this->repo . "/caf\u{e9}.txt", "untracked\n");

        // The hazard is live in this fixture: plain git colours and runs the tool.
        $this->assertSame(0, $this->git(['log', '--oneline', '-1'], $log));
        $this->assertStringContainsString("\x1b", implode("\n", $log), 'git did not colour its log, so the absence below proves nothing');
        $this->assertSame(0, $this->git(['diff'], $diff));
        $this->assertStringContainsString('EXTERNAL-DIFF-TOOL', implode("\n", $diff), 'git did not run diff.external, so the absence below proves nothing');
        $this->assertFileExists($marker);
        unlink($marker);

        $out = EnvironmentBlock::capture($this->repo, 'model')->withWriteSinceLastRender(true)->render();

        $this->assertStringNotContainsString("\x1b", $out);
        $this->assertStringNotContainsString('EXTERNAL-DIFF-TOOL', $out);
        $this->assertFileDoesNotExist($marker, 'the user\'s external diff tool ran during render()');
        $this->assertMatchesRegularExpression('/\nRecent commits:\n[0-9a-f]{7,} initial import\n/', $out);
        $this->assertStringContainsString(
            "Staged changes (git diff --cached, index vs HEAD):\n 1 file changed, 1 insertion(+)\n",
            $out,
        );
        $this->assertStringContainsString("+++ b/staged.txt\n@@ -0,0 +1 @@\n+staged line", $out);
        $this->assertStringContainsString(
            "Unstaged changes (git diff, working tree vs index):\n 1 file changed, 1 insertion(+), 1 deletion(-)\n",
            $out,
        );
        $this->assertStringContainsString("--- a/tracked.txt\n+++ b/tracked.txt\n@@ -1,3 +1,3 @@\n one\n-two\n+TWO\n three", $out);
        // core.quotepath is pinned off, so a non-ASCII path is shown as itself.
        $this->assertStringContainsString("\n?? caf\u{e9}.txt\n", $out);
    }

    public function testRenderNeverRewritesTheIndexEvenWithAStatDirtyFile(): void
    {
        $this->dirtyRepoWithAStatDirtyFile();
        $index = $this->repo . '/.git/index';
        $before = $this->indexFingerprint();

        $out = EnvironmentBlock::capture($this->repo, 'model')->withWriteSinceLastRender(true)->render();

        $this->assertSame($before, $this->indexFingerprint(), 'render() rewrote .git/index');
        $this->assertFileDoesNotExist($index . '.lock');
        $this->assertRealFieldsWithoutTheStatDirtyFile($out);

        // AFTER, so it cannot disturb the render: porcelain `git diff` does
        // rewrite this index, which is what makes the equality above a claim.
        $this->assertSame(0, $this->git(['diff', '--stat']));
        $this->assertNotSame($before, $this->indexFingerprint(), 'porcelain git diff left this index alone, so the fixture is not stat-dirty and the test proves nothing');
    }

    public function testRenderSucceedsWhileAnotherGitHoldsTheIndexLock(): void
    {
        $this->dirtyRepoWithAStatDirtyFile();
        $lock = $this->repo . '/.git/index.lock';
        file_put_contents($lock, '');
        $before = $this->indexFingerprint();

        $out = EnvironmentBlock::capture($this->repo, 'model')->withWriteSinceLastRender(true)->render();

        $this->assertFileExists($lock, 'render() removed a lock it did not own');
        $this->assertSame('', file_get_contents($lock));
        $this->assertSame($before, $this->indexFingerprint());
        $this->assertRealFieldsWithoutTheStatDirtyFile($out);
    }

    public function testAnUnbornHeadStillShowsTheStagedDiffAgainstTheEmptyTree(): void
    {
        $this->initRepo();
        file_put_contents($this->repo . '/first.txt', "first\n");
        $this->assertSame(0, $this->git(['add', 'first.txt']));

        $out = EnvironmentBlock::capture($this->repo, 'model')->withWriteSinceLastRender(true)->render();

        $this->assertStringContainsString(
            "Staged changes (git diff --cached, index vs HEAD):\n 1 file changed, 1 insertion(+)\n",
            $out,
        );
        $this->assertStringContainsString("new file mode 100644\n", $out);
        $this->assertStringContainsString("+++ b/first.txt\n@@ -0,0 +1 @@\n+first", $out);
        $this->assertStringContainsString('Unstaged changes (git diff, working tree vs index): (none)', $out);
    }

    /**
     * A committed repo with one real unstaged edit (`a.txt`), one staged file
     * (`s.txt`) and one STAT-DIRTY file (`b.txt`: mtime moved, content not).
     */
    private function dirtyRepoWithAStatDirtyFile(): void
    {
        $this->initRepo();
        file_put_contents($this->repo . '/a.txt', "alpha\n");
        file_put_contents($this->repo . '/b.txt', "bravo\n");
        $this->commitAll('initial import');
        file_put_contents($this->repo . '/s.txt', "staged\n");
        $this->assertSame(0, $this->git(['add', 's.txt']));
        file_put_contents($this->repo . '/a.txt', "alpha edited\n");
        $this->assertTrue(touch($this->repo . '/b.txt', time() + 3600));
        clearstatcache();
    }

    private function assertRealFieldsWithoutTheStatDirtyFile(string $out): void
    {
        $this->assertStringNotContainsString('unavailable (', $out);
        $this->assertStringContainsString("Status:\n M a.txt\nA  s.txt\n", $out);
        $this->assertStringContainsString("+++ b/s.txt\n@@ -0,0 +1 @@\n+staged", $out);
        $this->assertStringContainsString(
            "Unstaged changes (git diff, working tree vs index):\n 1 file changed, 1 insertion(+), 1 deletion(-)\n",
            $out,
        );
        $this->assertStringContainsString("-alpha\n+alpha edited", $out);
        $this->assertStringNotContainsString('b.txt', $out, 'a stat-dirty file with unchanged content was reported as changed');
    }

    /** @return array{0: string|false, 1: int|false, 2: int|false} */
    private function indexFingerprint(): array
    {
        clearstatcache();
        $index = $this->repo . '/.git/index';

        return [md5_file($index), fileinode($index), filemtime($index)];
    }

    private function initRepo(): void
    {
        foreach ([
            ['init', '-q'],
            ['config', 'user.email', 'isolation@example.invalid'],
            ['config', 'user.name', 'Isolation Fixture'],
            ['config', 'commit.gpgsign', 'false'],
        ] as $argv) {
            $this->assertSame(0, $this->git($argv, $output), 'git ' . implode(' ', $argv) . ': ' . implode("\n", $output));
        }
    }

    private function commitAll(string $message): void
    {
        $this->assertSame(0, $this->git(['add', '-A']));
        $this->assertSame(0, $this->git(['commit', '-q', '-m', $message], $output), implode("\n", $output));
    }

    /**
     * @param list<string> $argv
     * @param list<string> $output
     */
    private function git(array $argv, ?array &$output = null): int
    {
        $command = 'git';
        if ($argv !== ['--version']) {
            $command .= ' -C ' . escapeshellarg($this->repo);
        }
        foreach ($argv as $arg) {
            $command .= ' ' . escapeshellarg($arg);
        }
        $output = [];
        exec($command . ' 2>&1', $output, $exitCode);

        return $exitCode;
    }

    private static function removeTree(string $dir): void
    {
        if (!is_dir($dir) || is_link($dir)) {
            @unlink($dir);

            return;
        }
        foreach (array_diff((array) scandir($dir), ['.', '..']) as $entry) {
            self::removeTree($dir . '/' . $entry);
        }
        @rmdir($dir);
    }
}
