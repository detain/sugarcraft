<?php

declare(strict_types=1);

namespace SugarCraft\Git\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Git\Git;
use SugarCraft\Git\Tests\Concerns\RecursiveDirCleanup;

/**
 * Real-`git` coverage for the stash lifecycle candy-git-014 opened up:
 * create (with and without untracked files, with and without --keep-index),
 * list round-trips of the typed message, and pop — an apply followed by a
 * drop, where a conflicting apply must leave the entry in place.
 *
 * Unit doubles record argv; they cannot prove that `-m` decorates the entry
 * or that `--keep-index` keeps the staged bytes — these legs run the real
 * plumbing (the same reason the remote trio and merge sequencer have their
 * own integration files).
 */
final class StashLifecycleIntegrationTest extends TestCase
{
    use RecursiveDirCleanup;

    private string $cwd;
    private Git $git;

    protected function setUp(): void
    {
        if (!exec('git --version 2>/dev/null')) {
            $this->markTestSkipped('git not available');
        }
        $this->cwd = sys_get_temp_dir() . '/gitstash_' . uniqid();
        mkdir($this->cwd, 0o777, true);
        exec('git init -q ' . escapeshellarg($this->cwd) . ' 2>/dev/null');
        exec('git -C ' . escapeshellarg($this->cwd) . " config user.email 'test@test.com' 2>/dev/null");
        exec('git -C ' . escapeshellarg($this->cwd) . " config user.name 'Test' 2>/dev/null");
        file_put_contents($this->cwd . '/f.txt', "seed\n");
        exec('git -C ' . escapeshellarg($this->cwd) . ' add -A 2>/dev/null');
        exec('git -C ' . escapeshellarg($this->cwd) . ' commit -qm seed 2>/dev/null');

        $this->git = new Git($this->cwd);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->cwd);
    }

    /** Raw git stdout, one line trimmed — shell_exec so the append-on-PHP-
     * exec() out-array footgun can never skew these assertions. */
    private function raw(string ...$args): string
    {
        return rtrim((string) shell_exec(
            'git -C ' . escapeshellarg($this->cwd) . ' ' . implode(' ', array_map('escapeshellarg', $args)) . ' 2>/dev/null'
        ), "\n");
    }

    public function testPushListAndPopRoundTrip(): void
    {
        file_put_contents($this->cwd . '/f.txt', "changed bytes\n");
        $this->git->stashPush('my stash');

        // The working tree is clean again and the message survives the round
        // trip through git's own list decoration.
        self::assertSame('', trim($this->raw('status', '--porcelain')));
        $list = $this->git->stashList();
        self::assertCount(1, $list);
        self::assertSame('my stash', $list[0]->message);

        $this->git->stashPop('stash@{0}');
        self::assertSame("changed bytes\n", file_get_contents($this->cwd . '/f.txt'));
        self::assertSame([], $this->git->stashList());
    }

    public function testUntrackedFilesNeedTheExplicitFlag(): void
    {
        file_put_contents($this->cwd . '/f.txt', "tracked change\n");
        file_put_contents($this->cwd . '/u.txt', "brand new\n");

        $this->git->stashPush('without untracked', false, false);
        self::assertFileExists($this->cwd . '/u.txt', 'a plain push must not touch untracked files');

        $this->git->stashPush('with untracked', false, true);
        self::assertFileDoesNotExist($this->cwd . '/u.txt');

        $list = $this->git->stashList();
        self::assertCount(2, $list);
        $this->git->stashPop('stash@{0}');
        self::assertFileExists($this->cwd . '/u.txt', 'pop brings the stashed untracked file back');
        self::assertSame("brand new\n", file_get_contents($this->cwd . '/u.txt'));
    }

    public function testKeepIndexLeavesTheStagedBytesInPlace(): void
    {
        file_put_contents($this->cwd . '/f.txt', "staged work\n");
        exec('git -C ' . escapeshellarg($this->cwd) . ' add f.txt 2>/dev/null');

        $this->git->stashPush('staged kept', true, false);

        self::assertCount(1, $this->git->stashList());
        // The staged change survived the stash — that is the whole --keep-index
        // contract lazygit's flow leans on.
        self::assertSame('f.txt', trim($this->raw('diff', '--cached', '--name-only')));
        self::assertSame("staged work\n", file_get_contents($this->cwd . '/f.txt'));
    }

    public function testConflictedPopKeepsTheEntryBecauseApplyPrecedesDrop(): void
    {
        file_put_contents($this->cwd . '/f.txt', "stashed side\n");
        $this->git->stashPush('conflict bait');

        // Conflicting HEAD move: apply merges against the committed content
        // and conflicts. An UNCOMMITTED divergence would be refused outright
        // ("would be overwritten by merge") — same throw-before-drop contract,
        // different git wording, so the committed shape is what we exercise.
        file_put_contents($this->cwd . '/f.txt', "local side\n");
        $this->raw('add', 'f.txt');
        $this->raw('commit', '-m', 'local');
        try {
            $this->git->stashPop('stash@{0}');
            self::fail('a conflicting pop must throw');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('conflict', strtolower($e->getMessage()));
        }

        self::assertCount(1, $this->git->stashList(), 'the failed apply must not have consumed the drop');
    }

    public function testPopOfAMissingRefThrows(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->git->stashPop('stash@{3}');
    }
}
