<?php

declare(strict_types=1);

namespace SugarCraft\Git\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Git\Git;
use SugarCraft\Git\Tests\Concerns\GitSpawn;
use SugarCraft\Git\Tests\Concerns\RecursiveDirCleanup;

/**
 * Real-git legs for the destructive-operation UNDO lane (candy-git-077).
 *
 * restoreBranch(), reset() and the stash verbs were fixture recordings
 * only - and a fixture can never catch the exact bug the restore body
 * exists to avoid: recreating a deleted branch with checkout-style
 * semantics would move HEAD and pass every in-memory pin. This suite
 * drives the real binary and asserts on the repository afterwards, so
 * the "creates the ref WITHOUT moving HEAD" guarantee has a witness.
 */
final class HistoryUndoIntegrationTest extends TestCase
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
        $this->cwd = sys_get_temp_dir() . '/undolane_' . uniqid();
        mkdir($this->cwd, 0o777, true);
        $this->gitMust(['init', '-q', '-b', 'main', $this->cwd]);
        $this->gitMust(['-C', $this->cwd, 'config', 'user.email', 'test@test.com']);
        $this->gitMust(['-C', $this->cwd, 'config', 'user.name', 'Test']);
        $this->git = new Git($this->cwd);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->cwd);
    }

    /** Commit a one-line change and return its sha. */
    private function commitFile(string $content, string $file = 'f.txt'): string
    {
        file_put_contents($this->cwd . '/' . $file, $content);
        $this->gitMust(['-C', $this->cwd, 'add', '--', $file]);
        $this->gitMust(['-C', $this->cwd, 'commit', '-q', '-m', $content]);

        return trim($this->gitStdout(['-C', $this->cwd, 'rev-parse', 'HEAD']));
    }

    public function test_restore_branch_recreates_the_ref_without_moving_head(): void
    {
        $this->commitFile('base');
        $this->gitMust(['-C', $this->cwd, 'checkout', '-q', '-b', 'feature']);
        $featureSha = $this->commitFile('side');
        $this->gitMust(['-C', $this->cwd, 'checkout', '-q', 'main']);
        // Merge first: git branch -d refuses not-yet-merged branches (the
        // app's D key lives with the same refusal), so the deleted branch
        // here is one the driver would actually let go.
        $this->gitMust(['-C', $this->cwd, 'merge', '-q', 'feature']);
        $headBefore = trim($this->gitStdout(['-C', $this->cwd, 'rev-parse', 'HEAD']));

        $this->git->deleteBranch('feature');
        $gone = self::gitSpawn(['-C', $this->cwd, 'show-ref', '--verify', 'refs/heads/feature']);
        $this->assertNotSame(0, $gone['exit'], 'the delete must actually have landed');

        $this->git->restoreBranch('feature', $featureSha);

        $this->assertSame($featureSha, trim($this->gitStdout(['-C', $this->cwd, 'rev-parse', 'feature'])));
        $this->assertSame('refs/heads/main', trim($this->gitStdout(['-C', $this->cwd, 'symbolic-ref', 'HEAD'])), 'restoring a branch may create the ref but must never check it out');
        $this->assertSame($headBefore, trim($this->gitStdout(['-C', $this->cwd, 'rev-parse', 'HEAD'])), 'HEAD stays at its own commit');
        $this->assertSame('', $this->gitStdout(['-C', $this->cwd, 'status', '--porcelain']), 'the work tree is untouched by a restore');
    }

    public function test_soft_reset_steps_back_over_the_last_commit_keeping_its_content_staged(): void
    {
        $first = $this->commitFile('base');
        $this->commitFile('uncommitted-will-be-reset');

        $this->git->reset();

        $this->assertSame($first, trim($this->gitStdout(['-C', $this->cwd, 'rev-parse', 'HEAD'])), 'reset --soft moves the branch boundary back one commit');
        // The point of --soft in an undo lane: the typed bytes survive in the
        // index, never lost to a mixed/hard reset.
        $this->assertSame('M  f.txt', $this->gitStdout(['-C', $this->cwd, 'status', '--porcelain']), 'the reset-back content sits staged in the index (X=M, Y=space)');
    }

    public function test_stash_apply_round_trip_leaves_the_entry_standing(): void
    {
        $this->commitFile('base');
        file_put_contents($this->cwd . '/f.txt', "dirty\n");

        $this->git->stashPush('note');
        $this->assertSame('base', file_get_contents($this->cwd . '/f.txt'), 'the push swept the change into the stash');
        $list = $this->git->stashList();
        $this->assertCount(1, $list);
        $this->assertStringContainsString('note', $list[0]->message);

        $this->git->stashApply('stash@{0}');
        $this->assertSame("dirty\n", file_get_contents($this->cwd . '/f.txt'), 'apply restores the sweep');
        $this->assertCount(1, $this->git->stashList(), 'apply must never consume the entry - drop is the consumer');

        $this->git->stashDrop('stash@{0}');
        $this->assertSame([], $this->git->stashList(), 'drop is the destructive end of the round trip');
    }

    public function test_stash_list_on_an_empty_repo_is_the_empty_shape(): void
    {
        // No commits yet: git stash list answers with nothing, and the
        // parser must hand back [] rather than laundering git's silence
        // into an error row.
        $this->assertSame([], $this->git->stashList());
    }
}
