<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Support;

/**
 * Runs a test with a working directory that is NOT inside a git work tree.
 *
 * The engine renders a per-step turn-context row from the cwd's live git
 * state. Run from a checkout (and especially a busy monorepo one) that row
 * rides into the first user message and changes between steps, so tests that
 * assert the exact message shapes a sub-agent was handed pass or fail on what
 * the working tree happens to look like. They are about delivery, not about
 * the host's git state, so they pin the one input they do not own.
 */
trait RunsOutsideAnyRepoTrait
{
    private string $outsideRepoPreviousCwd = '';

    private string $outsideRepoDir = '';

    private function enterDirectoryOutsideAnyRepo(): void
    {
        $this->outsideRepoPreviousCwd = (string) \getcwd();
        $this->outsideRepoDir = \sys_get_temp_dir() . '/sc_norepo_' . \bin2hex(\random_bytes(6));
        \mkdir($this->outsideRepoDir, 0700, true);
        \chdir($this->outsideRepoDir);
    }

    private function leaveDirectoryOutsideAnyRepo(): void
    {
        if ($this->outsideRepoPreviousCwd !== '') {
            \chdir($this->outsideRepoPreviousCwd);
        }
        if ($this->outsideRepoDir !== '') {
            @\rmdir($this->outsideRepoDir);
        }
    }
}
