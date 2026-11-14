<?php

declare(strict_types=1);

namespace SugarCraft\Git\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Git\Git;
use SugarCraft\Git\Tests\Concerns\GitSpawn;
use SugarCraft\Git\Tests\Concerns\RecursiveDirCleanup;

/**
 * Real-git coverage for the hooks probe behind the candy-git-144 collector
 * announce: git's own hook layout decides the answer. A fixture can only
 * prove the App side of the wiring - this proves the probe itself: sample
 * files git ships with are inert by default, a chmod'ed hook is live and
 * will run on the next commit verb whether anyone meant it or not.
 */
final class LocalHooksIntegrationTest extends TestCase
{
    use GitSpawn;
    use RecursiveDirCleanup;

    private string $cwd;
    private Git $git;

    protected function setUp(): void
    {
        if (self::gitSpawn(['--version'])['exit'] !== 0) {
            $this->markTestSkipped('git not spawnable');
        }
        $this->cwd = sys_get_temp_dir() . '/hooks_' . uniqid();
        mkdir($this->cwd, 0o777, true);
        $this->gitMust(['init', '-q', '-b', 'main', $this->cwd]);
        $this->git = new Git($this->cwd);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->cwd);
    }

    public function test_sample_hooks_are_not_live(): void
    {
        // A fresh git init ships *.sample files only - never executable,
        // never run. The probe must stay quiet or every repo on earth
        // would announce.
        $this->assertFalse($this->git->localHooksPresent());
    }

    public function test_an_executable_pre_commit_is_live(): void
    {
        $hook = $this->cwd . '/.git/hooks/pre-commit';
        file_put_contents($hook, "#!/bin/sh\nexit 0\n");
        chmod($hook, 0o755);

        $this->assertTrue($this->git->localHooksPresent(), 'a chmod-ed hook is exactly what the collector warns about');
    }
}
