<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Support;

/**
 * Points HOME at a temp directory — BOTH `getenv('HOME')` and
 * `$_SERVER['HOME']` — for the duration of a test.
 *
 * BOTH, because half a sandbox is not a sandbox — and because "which spelling
 * does the code read" is not a question a test should have to keep answering.
 * Every `~`-rooted reader in `src/` now resolves through
 * {@see \SugarCraft\Crush\Support\HomeDirectory}, which prefers `getenv()`;
 * the `$_SERVER['HOME'] ?? '/root'` readers this trait was written against —
 * `Team`, `TeamManager`, `Teammate`, `WorktreeManager`, `WorkflowEngine`,
 * `WorkflowRegistry` — were migrated to it in the same change-set. Setting the
 * superglobal too costs nothing and keeps the sandbox honest for anything that
 * reads it directly (PHP populates `$_SERVER['HOME']` once, from the
 * environment `php` started with, so it does NOT follow a `putenv()`).
 *
 * A test that redirects only one of them leaves every consumer of the other
 * reading the DEVELOPER'S real `~/.claude/skills`, `~/.config/opencode/skills`
 * and `~/.sugar-crush` — which is green until the day a machine has a skill
 * named like the fixture, and is tracker #52/#53 all over again.
 *
 * Nothing in a suite may depend on what the person running it happens to have
 * installed, so the sandbox is applied whether or not the assertions currently
 * look at it.
 */
trait HomeSandboxTrait
{
    /** @var string|false the env HOME to put back, or false when it was unset */
    private string|false $homeSandboxOriginalEnv = false;

    private mixed $homeSandboxOriginalServer = null;

    private bool $homeSandboxActive = false;

    /**
     * Redirect HOME at $dir (created if absent), remembering the real values
     * on the first call so a test may move the sandbox around and still get
     * one clean restore.
     */
    protected function useHomeSandbox(string $dir): string
    {
        if (!$this->homeSandboxActive) {
            $this->homeSandboxOriginalEnv = getenv('HOME');
            $this->homeSandboxOriginalServer = $_SERVER['HOME'] ?? null;
            $this->homeSandboxActive = true;
        }

        if (!is_dir($dir)) {
            mkdir($dir, 0700, true);
        }

        putenv('HOME=' . $dir);
        $_SERVER['HOME'] = $dir;

        return $dir;
    }

    /**
     * Put the process's real HOME back. Safe to call when no sandbox was ever
     * installed, so a tearDown() need not guard it.
     */
    protected function restoreHomeSandbox(): void
    {
        if (!$this->homeSandboxActive) {
            return;
        }

        $this->homeSandboxOriginalEnv === false
            ? putenv('HOME')
            : putenv('HOME=' . $this->homeSandboxOriginalEnv);

        if ($this->homeSandboxOriginalServer === null) {
            unset($_SERVER['HOME']);
        } else {
            $_SERVER['HOME'] = $this->homeSandboxOriginalServer;
        }

        $this->homeSandboxActive = false;
    }
}
