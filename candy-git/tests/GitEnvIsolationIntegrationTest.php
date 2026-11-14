<?php

declare(strict_types=1);

namespace SugarCraft\Git\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Git\Git;
use SugarCraft\Git\Tests\Concerns\RecursiveDirCleanup;

/**
 * The GIT_ redirect family must never reach a child (candy-git-041).
 *
 * `git -C <cwd>` does NOT outvote an inherited GIT_DIR: launched from a hook
 * or wrapper (where git itself exports those variables), the TUI would browse
 * one repository while every stage, discard and reset acted on ANOTHER.
 * This is the acceptance repro: hostile GIT_DIR, honest -C answer.
 */
final class GitEnvIsolationIntegrationTest extends TestCase
{
    use RecursiveDirCleanup;

    private string $cwd;
    private string $hostile;

    protected function setUp(): void
    {
        if (!exec('git --version 2>/dev/null')) {
            $this->markTestSkipped('git not available');
        }
        $this->cwd = sys_get_temp_dir() . '/githome_' . uniqid();
        mkdir($this->cwd, 0o777, true);
        exec('git init -q ' . escapeshellarg($this->cwd) . ' 2>/dev/null');
        exec('git -C ' . escapeshellarg($this->cwd) . " config user.email 'test@test.com' 2>/dev/null");
        exec('git -C ' . escapeshellarg($this->cwd) . " config user.name 'Test' 2>/dev/null");
        file_put_contents($this->cwd . '/mine.txt', "mine\n");
        exec('git -C ' . escapeshellarg($this->cwd) . ' add mine.txt 2>/dev/null');
        exec('git -C ' . escapeshellarg($this->cwd) . ' commit -q -m seed 2>/dev/null');
        file_put_contents($this->cwd . '/dirty.txt', "dirty\n");

        // A second repository whose only content is an untracked tell-file.
        $this->hostile = sys_get_temp_dir() . '/githostile_' . uniqid();
        mkdir($this->hostile, 0o777, true);
        exec('git init -q ' . escapeshellarg($this->hostile) . ' 2>/dev/null');
        file_put_contents($this->hostile . '/hostile.txt', "evidence\n");
    }

    protected function tearDown(): void
    {
        putenv('GIT_DIR');
        putenv('GIT_WORK_TREE');
        parent::tearDown();
    }

    public function testAHostileRedirectPairCannotRePointStatus(): void
    {
        // Hooks inherit exactly this pair from git.
        putenv('GIT_DIR=' . $this->hostile . '/.git');
        putenv('GIT_WORK_TREE=' . $this->hostile);

        $rows  = (new Git($this->cwd))->status();
        $paths = array_map(static fn (array $row): string => $row['path'] ?? '', $rows);

        $this->assertContains('dirty.txt', $paths, 'status must answer from the -C repository');
        $this->assertNotContains('hostile.txt', $paths, 'the env-redirected repository must stay invisible');
    }

    public function testAHostileGitDirAloneCannotPoisonTheIndexView(): void
    {
        // GIT_DIR without GIT_WORK_TREE is the subtler half: the worktree
        // stays here while the INDEX is answered from the hostile repository,
        // so committed files would show as untracked additions.
        putenv('GIT_DIR=' . $this->hostile . '/.git');

        $rows  = (new Git($this->cwd))->status();
        $paths = array_map(static fn (array $row): string => $row['path'] ?? '', $rows);

        $this->assertContains('dirty.txt', $paths);
        $this->assertNotContains('mine.txt', $paths, 'a tracked file must not resurface as an addition');
    }

    public function testTheRawGitCommandHonoursTheRedirectWhileCandyGitIgnoresIt(): void
    {
        // The control leg: proves the redirect really is hostile (a bare
        // `git -C` invocation answers from the OTHER repository), so the
        // positive legs test the cure, not a toothless setup.
        putenv('GIT_DIR=' . $this->hostile . '/.git');
        putenv('GIT_WORK_TREE=' . $this->hostile);

        $raw = shell_exec('git -C ' . escapeshellarg($this->cwd) . ' status --porcelain=v1 2>/dev/null');

        $this->assertStringContainsString('hostile.txt', (string) $raw, 'repro shape: -C alone loses to the redirect pair');
        $this->assertStringNotContainsString('dirty.txt', (string) $raw, 'the honest repository vanishes under the redirect');
    }
}
