<?php

declare(strict_types=1);

namespace SugarCraft\Git\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Git\Git;
use SugarCraft\Git\Tests\Concerns\RecursiveDirCleanup;

/**
 * Real-git coverage for the commit-signing probe behind candy-git-054's
 * collector warning: what `git config` actually resolves decides whether
 * the hint fires. Only the real binary proves the --bool normalisation
 * (yes/on/1 all mean true), the unset-key exit code, and that SSH-format
 * signing — which rides an already-unlocked agent socket — is excluded.
 */
final class GpgSigningProbeIntegrationTest extends TestCase
{
    use RecursiveDirCleanup;

    private string $cwd;
    private Git $git;

    protected function setUp(): void
    {
        if (!exec('git --version 2>/dev/null')) {
            $this->markTestSkipped('git not available');
        }
        $this->cwd = sys_get_temp_dir() . '/gpgprobe_' . uniqid();
        mkdir($this->cwd, 0o777, true);
        exec('git init -q ' . escapeshellarg($this->cwd) . ' 2>/dev/null');
        $this->git = new Git($this->cwd);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->cwd);
    }

    private function setConfig(string $key, string $value): void
    {
        exec('git -C ' . escapeshellarg($this->cwd) . ' config ' . escapeshellarg($key) . ' ' . escapeshellarg($value) . ' 2>/dev/null');
    }

    public function test_unset_signing_config_means_no_pinentry(): void
    {
        $this->assertFalse($this->git->commitGpgSigns());
    }

    public function test_signing_off_stays_quiet(): void
    {
        $this->setConfig('commit.gpgsign', 'false');
        $this->assertFalse($this->git->commitGpgSigns());
    }

    public function test_signing_on_warns(): void
    {
        $this->setConfig('commit.gpgsign', 'true');
        $this->assertTrue($this->git->commitGpgSigns());
    }

    public function test_bool_spellings_normalise_through_the_git_side(): void
    {
        // --bool means git itself answers yes/on/1 — the probe must agree
        // with git's own normalisation, not with a string equality check.
        foreach (['yes', 'on', '1'] as $spelling) {
            $this->setConfig('commit.gpgsign', $spelling);
            $this->assertTrue($this->git->commitGpgSigns(), "git treats '{$spelling}' as true");
        }
    }

    public function test_ssh_format_signing_never_touches_pinentry(): void
    {
        $this->setConfig('commit.gpgsign', 'true');
        $this->setConfig('gpg.format', 'ssh');
        $this->assertFalse($this->git->commitGpgSigns());

        $this->setConfig('gpg.format', 'openpgp');
        $this->assertTrue($this->git->commitGpgSigns(), 'only ssh is exempt');
    }
}
