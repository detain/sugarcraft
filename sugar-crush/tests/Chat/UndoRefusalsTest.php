<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Chat;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Chat;
use SugarCraft\Crush\Message;
use SugarCraft\Crush\Role;
use SugarCraft\Crush\Tests\Support\CheckpointedRepoTrait;
use SugarCraft\Crush\Workspace\AutoCommitter;

/**
 * Step 3.G: in a session that has auto-committed, `/undo` reverts the last
 * commit Aider's way — `git checkout HEAD~1 -- <files>` + `git reset --soft
 * HEAD~1`, and the model is told — and refuses, changing nothing, in Aider's
 * five cases: the commit is not this session's, it is a merge, a file it
 * changed is dirty now, a file it changed did not exist before it, or it is
 * already pushed.
 *
 * @see Chat::handleUndoCommand()
 * @see AutoCommitter::undo()
 */
final class UndoRefusalsTest extends TestCase
{
    use CheckpointedRepoTrait;

    protected function setUp(): void
    {
        $this->setUpCheckpointedRepo();
    }

    protected function tearDown(): void
    {
        $this->tearDownCheckpointedRepo();
    }

    public function testUndoRevertsTheSessionsLastAutoCommitAndTellsTheModel(): void
    {
        $sha = $this->autoCommit('file.txt', "model\n", 'fix: change the file');

        $undone = $this->send('/undo', [Message::user('change it'), Message::assistant('done')]);

        self::assertSame("base\n", $this->file('file.txt'));
        self::assertSame('base', $this->gitAt('log', '-1', '--format=%s'));
        self::assertSame('', $this->gitAt('status', '--porcelain'), 'the index went back with the files');
        self::assertStringContainsString('Reverted ' . substr($sha, 0, 7) . ' (fix: change the file)', $this->uiReply($undone));
        $note = $undone->history[\count($undone->history) - 1];
        self::assertSame(Role::System, $note->role);
        self::assertFalse($note->uiOnly, 'the model is told, so it does not simply redo the change');
        self::assertStringContainsString('was reverted', $note->content);
        self::assertSame(['change it', 'done', $note->content], self::conversation($undone), 'the conversation stays, as in Aider');
        self::assertFalse(AutoCommitter::new($this->repo)->withSessionId('undo-session')->hasCommits(), 'the record went with the commit');
    }

    public function testRefusedWhenTheLastCommitIsNotThisSessions(): void
    {
        $this->autoCommit('file.txt', "model\n", 'fix: mine');
        file_put_contents($this->repo . '/file.txt', "by hand\n");
        $this->gitAt('commit', '-q', '-am', 'the user committed');

        $this->assertRefused('the last commit was not made by sugar-crush in this session', 'the user committed');
        self::assertStringContainsString('/rewind --both', $this->uiReply($this->send('/undo')));
    }

    public function testRefusedForAMergeCommit(): void
    {
        $this->gitAt('checkout', '-q', '-b', 'side');
        file_put_contents($this->repo . '/side.txt', "side\n");
        $this->gitAt('add', 'side.txt');
        $this->gitAt('commit', '-q', '-m', 'side');
        $this->gitAt('checkout', '-q', '-');
        file_put_contents($this->repo . '/main.txt', "main\n");
        $this->gitAt('add', 'main.txt');
        $this->gitAt('commit', '-q', '-m', 'main');
        $this->gitAt('merge', '-q', '--no-ff', '-m', 'merge side', 'side');
        $this->recordAsOurs($this->gitAt('rev-parse', 'HEAD'), 'merge side');

        $this->assertRefused('is a merge commit', 'merge side');
    }

    public function testRefusedWhenAFileItChangedIsDirtyNow(): void
    {
        $this->autoCommit('file.txt', "model\n", 'fix: model');
        file_put_contents($this->repo . '/file.txt', "model\nand the user typed more\n");

        $this->assertRefused('these files have uncommitted changes now: file.txt', 'fix: model');
        self::assertSame("model\nand the user typed more\n", $this->file('file.txt'), 'the user\'s work is untouched');
    }

    public function testRefusedWhenAFileItChangedDidNotExistBefore(): void
    {
        $this->autoCommit('created.txt', "new\n", 'feat: add a file');

        $this->assertRefused('created.txt did not exist before', 'feat: add a file');
        self::assertSame("new\n", $this->file('created.txt'));
    }

    public function testRefusedWhenTheCommitIsAlreadyPushed(): void
    {
        $sha = $this->autoCommit('file.txt', "model\n", 'fix: model');
        $this->gitAt('update-ref', 'refs/remotes/origin/main', $sha);

        $this->assertRefused('is already on origin/main', 'fix: model');
    }

    public function testUndoingTheUsersSnapshotPutsTheirChangesBackUncommitted(): void
    {
        file_put_contents($this->repo . '/file.txt', "the user's work\n");
        $this->turnCheckpoint([], 'prompt');
        $workspace = $this->store->listCheckpoints('undo-session', 1)[0]['state_data'][\SugarCraft\Crush\Session\EnhancedSessionStore::CHECKPOINT_WORKSPACE_KEY];
        file_put_contents($this->repo . '/file.txt', "the user's work\nthe model's line\n");
        $outcome = AutoCommitter::new($this->repo)->withSessionId('undo-session')->commitEdit($this->repo . '/file.txt', static fn (): string => 'chore: add a line', $workspace);
        self::assertNotNull($outcome);
        self::assertNotNull($outcome['snapshot']);

        $this->send('/undo');
        self::assertSame("the user's work\n", $this->file('file.txt'), 'the model\'s commit is reverted first');
        self::assertSame(AutoCommitter::SNAPSHOT_SUBJECT, $this->gitAt('log', '-1', '--format=%s'));

        $again = $this->send('/undo');
        self::assertStringContainsString('back to uncommitted, as they were', $this->uiReply($again));
        self::assertSame('base', $this->gitAt('log', '-1', '--format=%s'));
        self::assertSame("the user's work\n", $this->file('file.txt'));
        self::assertSame(' M file.txt', $this->gitAt('status', '--porcelain'), 'their change is back where it was: in the file, unstaged');
    }

    public function testASessionThatNeverAutoCommittedGetsTheCheckpointUndo(): void
    {
        self::assertSame('No checkpoints available to rewind to.', $this->uiReply($this->send('/undo')));
    }

    private function assertRefused(string $why, string $headSubject): void
    {
        $head = $this->gitAt('rev-parse', 'HEAD');
        $refused = $this->send('/undo', [Message::user('hi')]);

        self::assertStringStartsWith('Nothing was undone: ', $this->uiReply($refused));
        self::assertStringContainsString($why, $this->uiReply($refused));
        self::assertSame($head, $this->gitAt('rev-parse', 'HEAD'), 'a refusal moves nothing');
        self::assertSame($headSubject, $this->gitAt('log', '-1', '--format=%s'));
        self::assertSame(['hi'], self::conversation($refused), 'and tells the model nothing');
    }

    private function autoCommit(string $file, string $contents, string $subject): string
    {
        file_put_contents($this->repo . '/' . $file, $contents);
        $outcome = AutoCommitter::new($this->repo)->withSessionId('undo-session')->commit([$file], $subject);
        self::assertTrue($outcome['ok'], $outcome['reason']);

        return (string) $outcome['sha'];
    }

    /** Record $sha in the session's auto-commit list the way a commit does. */
    private function recordAsOurs(string $sha, string $subject): void
    {
        $file = $this->repo . '/.git/' . AutoCommitter::RECORD_PATH;
        @mkdir(\dirname($file), 0o700, true);
        file_put_contents($file, json_encode(['sha' => $sha, 'session' => 'undo-session', 'kind' => 'edit', 'subject' => $subject, 'at' => time()]) . "\n", FILE_APPEND);
    }

    /** The command's own reply: the last UI-only row. */
    private function uiReply(Chat $chat): string
    {
        foreach (array_reverse($chat->history) as $message) {
            if ($message->uiOnly) {
                return $message->content;
            }
        }

        return '';
    }
}
