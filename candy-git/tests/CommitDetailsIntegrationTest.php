<?php

declare(strict_types=1);

namespace SugarCraft\Git\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Git\Git;
use SugarCraft\Git\Tests\Concerns\RecursiveDirCleanup;

/**
 * Real-`git` coverage for the commit-details surface (candy-git-015).
 *
 * The log pane used to fetch author/ago and render neither, and offered no
 * action on a commit at all. These legs prove the driver methods behind the
 * new details overlay against real git: `commitDetails()` is what the
 * read-only overlay feeds on, `revert()` stacks the inverse commit, and
 * `resetHard()` moves HEAD where the armed UI key sends it.
 */
final class CommitDetailsIntegrationTest extends TestCase
{
    use RecursiveDirCleanup;

    private string $cwd;
    private Git $git;

    protected function setUp(): void
    {
        if (!exec('git --version 2>/dev/null')) {
            $this->markTestSkipped('git not available');
        }
        $this->cwd = sys_get_temp_dir() . '/gitdetails_' . uniqid();
        mkdir($this->cwd, 0o777, true);
        exec('git init -q ' . escapeshellarg($this->cwd) . ' 2>/dev/null');
        exec('git -C ' . escapeshellarg($this->cwd) . " config user.email 'test@test.com' 2>/dev/null");
        exec('git -C ' . escapeshellarg($this->cwd) . " config user.name 'Test' 2>/dev/null");

        file_put_contents($this->cwd . '/f.txt', "one\n");
        exec('git -C ' . escapeshellarg($this->cwd) . ' add -A 2>/dev/null');
        exec('git -C ' . escapeshellarg($this->cwd) . ' commit -qm "first commit" 2>/dev/null');

        // Second commit touches a DISJOINT file: reverting it is the clean
        // case the overlay offers, and a seeded conflict would test git's
        // 3-way machinery instead of our driver lane.
        file_put_contents($this->cwd . '/g.txt', "two\n");
        exec('git -C ' . escapeshellarg($this->cwd) . ' add -A 2>/dev/null');
        exec('git -C ' . escapeshellarg($this->cwd) . ' commit -qm "second commit" 2>/dev/null');

        $this->git = new Git($this->cwd);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->cwd);
    }

    private function raw(string ...$args): string
    {
        // shell_exec, NOT exec(): exec() APPENDS to a pre-filled array
        // argument instead of resetting it (the CG-06 integration lesson).
        $cmd = 'git -C ' . escapeshellarg($this->cwd);
        foreach ($args as $a) {
            $cmd .= ' ' . escapeshellarg($a);
        }

        return rtrim((string) shell_exec($cmd . ' 2>/dev/null'), "\n");
    }

    public function testCommitDetailsRendersTheFullDisplayWithThePatch(): void
    {
        $sha = $this->raw('rev-parse', 'HEAD');
        $lines = $this->git->commitDetails($sha);

        $starts = array_values(array_filter($lines, static fn (string $l): bool => str_starts_with($l, 'commit ')));
        $this->assertNotEmpty($starts, 'the display opens with the commit header');
        $this->assertStringContainsString($sha, $starts[0]);
        $authors = array_filter($lines, static fn (string $l): bool => str_starts_with($l, 'Author:'));
        $this->assertNotEmpty($authors, '--format=full carries the author line the overlay shows');
        $messages = array_filter($lines, static fn (string $l): bool => trim($l) === 'second commit');
        $this->assertNotEmpty($messages);
        $hunk = array_filter($lines, static fn (string $l): bool => str_starts_with($l, '@@'));
        $this->assertNotEmpty($hunk, 'the patch body rides along — the overlay hunks are real');
        $plus = array_filter($lines, static fn (string $l): bool => $l === '+two');
        $this->assertNotEmpty($plus);
    }

    public function testCommitDetailsResolvesTheAbbreviatedShaTheUiShows(): void
    {
        // The log pane carries 7-char shas; the overlay passes what it sees.
        $full = $this->raw('rev-parse', 'HEAD');
        $short = substr($full, 0, 7);
        $lines = $this->git->commitDetails($short);
        $this->assertNotEmpty(array_filter($lines, static fn (string $l): bool => str_starts_with($l, 'commit ')));
    }

    public function testRevertStacksTheInverseCommitAndUndoesTheBytes(): void
    {
        $second = $this->raw('rev-parse', 'HEAD');
        $before = (int) $this->raw('rev-list', '--count', 'HEAD');

        $this->git->revert($second);

        $after = (int) $this->raw('rev-list', '--count', 'HEAD');
        $this->assertSame($before + 1, $after, 'a revert is a new commit, never a rewrite');
        $this->assertStringStartsWith('Revert "second commit"', $this->raw('log', '-1', '--pretty=%s'));
        $this->assertFalse(file_exists($this->cwd . '/g.txt'), 'the inverse undid the commit content');
        $this->assertSame("one\n", file_get_contents($this->cwd . '/f.txt'), 'and nothing else');
        $this->assertSame("one\n", file_get_contents($this->cwd . '/f.txt'));
    }

    public function testResetHardMovesHeadOntoTheChosenCommit(): void
    {
        $target = $this->raw('rev-parse', 'HEAD~1');
        $this->git->resetHard($target);

        $this->assertFalse(file_exists($this->cwd . '/g.txt'), 'the later commit is gone from the worktree too - the shape the UI arms twice');
        $this->assertSame("one\n", file_get_contents($this->cwd . '/f.txt'));    }

    public function testDetailsOfAnUnknownRefThrowsThroughTheDriverFunnel(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->git->commitDetails('deadbeefdeadbeefdeadbeefdeadbeefdeadbeef');
    }
}
