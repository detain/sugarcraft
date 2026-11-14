<?php

declare(strict_types=1);

namespace SugarCraft\Git\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Git\Git;
use SugarCraft\Git\Tests\Concerns\RecursiveDirCleanup;

/**
 * Real-`git` coverage for multi-line message authoring (candy-git-013).
 *
 * The collector gained newlines (Tab chord + paste) precisely so subject +
 * body round-trip through git byte-for-byte; a fake driver could never prove
 * that `-m` survives argv, cleanup modes, and the %B read back — so this
 * suite drives the real binary.
 */
final class CommitMessageIntegrationTest extends TestCase
{
    use RecursiveDirCleanup;

    private string $cwd;
    private Git $git;

    protected function setUp(): void
    {
        if (!exec('git --version 2>/dev/null')) {
            $this->markTestSkipped('git not available');
        }
        $this->cwd = sys_get_temp_dir() . '/gitmsg_' . uniqid();
        mkdir($this->cwd, 0o777, true);
        exec('git init -q ' . escapeshellarg($this->cwd) . ' 2>/dev/null');
        exec('git -C ' . escapeshellarg($this->cwd) . " config user.email 'test@test.com' 2>/dev/null");
        exec('git -C ' . escapeshellarg($this->cwd) . " config user.name 'Test' 2>/dev/null");
        file_put_contents($this->cwd . '/f.txt', "one\n");
        exec('git -C ' . escapeshellarg($this->cwd) . ' add -A 2>/dev/null');
        $this->git = new Git($this->cwd);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->cwd);
    }

    /**
     * shell_exec, never exec() with a shared out-array: exec() APPENDS to a
     * pre-filled array instead of resetting it (caught in the remote-trio
     * suite), and these reads run back to back in one test body.
     */
    private function raw(string $args): string
    {
        return rtrim((string) shell_exec('git -C ' . escapeshellarg($this->cwd) . ' ' . $args . ' 2>/dev/null'), "\n");
    }

    public function testSubjectAndBodyRoundTripThroughTheDriver(): void
    {
        $message = "feat: the subject\n\nwhy this exists\n- bullet one\n- bullet two";
        $this->git->commit($message);

        $this->assertSame($message, $this->git->lastCommitMessage());
        // Independent of the driver's own reader, the stored bytes carry the body.
        $this->assertStringContainsString('- bullet two', $this->raw('log -1 --pretty=%B'));
    }

    public function testAmendWithRewritesTheMessageAndKeepsTheTree(): void
    {
        $this->git->commit('original subject');
        $treeBefore = $this->raw('rev-parse HEAD^{tree}');
        $countBefore = $this->raw('rev-list --count HEAD');

        $this->git->amendWith("fixed subject\n\nnow with a body");

        $this->assertSame("fixed subject\n\nnow with a body", $this->git->lastCommitMessage());
        $this->assertSame($treeBefore, $this->raw('rev-parse HEAD^{tree}'), 'amend must rewrite only the message');
        $this->assertSame($countBefore, $this->raw('rev-list --count HEAD'), 'no extra commit boundary');
    }

    public function testPlainAmendStillKeepsTheMessageForTheUndoLane(): void
    {
        // The old blind keypress remains reachable for the history redo arm.
        $this->git->commit('keep me');
        file_put_contents($this->cwd . '/g.txt', "staged later\n");
        exec('git -C ' . escapeshellarg($this->cwd) . ' add g.txt 2>/dev/null');
        $this->git->amend();

        $this->assertSame('keep me', $this->git->lastCommitMessage());
    }

    public function testLastCommitMessageRefusesHonestlyOnAnEmptyRepo(): void
    {
        $empty = sys_get_temp_dir() . '/gitempty_' . uniqid();
        mkdir($empty, 0o777, true);
        exec('git init -q ' . escapeshellarg($empty) . ' 2>/dev/null');
        try {
            $this->expectException(\RuntimeException::class);
            (new Git($empty))->lastCommitMessage();
        } finally {
            $this->removeDir($empty);
        }
    }
}
