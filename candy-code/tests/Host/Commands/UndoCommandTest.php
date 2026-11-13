<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Host\Commands;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Host\Commands\CommandContext;
use SugarCraft\Code\Host\Commands\UndoCommand;
use SugarCraft\Code\Lang;
use SugarCraft\Code\Message;
use SugarCraft\Code\Tests\Support\CheckpointedRepoTrait;
use SugarCraft\Code\Workspace\AutoCommitter;

final class UndoCommandTest extends TestCase
{
    use CheckpointedRepoTrait;

    protected function setUp(): void
    {
        $this->setUpCheckpointedRepo();
        $this->gitAt('config', 'user.name', 'Ada User');
        $this->gitAt('config', 'user.email', 'ada@example.invalid');
    }

    protected function tearDown(): void
    {
        $this->tearDownCheckpointedRepo();
    }

    private function context(array $history = []): CommandContext
    {
        return CommandContext::new(history: $history, sessionStore: $this->store, sessionId: 'undo-session', root: $this->repo);
    }

    private function committer(): AutoCommitter
    {
        return AutoCommitter::new($this->repo)->withSessionId('undo-session');
    }

    public function testTheTypedTextIsNormalisedToUndo(): void
    {
        $result = (new UndoCommand())->run($this->context(), '/undo please');

        self::assertSame('/undo', $result->rows[0]->content);
    }

    public function testNoStoreIsAnErrorReply(): void
    {
        $result = (new UndoCommand())->run(CommandContext::new(), '/undo');

        self::assertSame(Lang::t('host.checkpoint.no_store'), $result->rows[1]->content);
    }

    public function testNoCheckpointsMeansNothingToUndo(): void
    {
        $result = (new UndoCommand())->run($this->context(), '/undo');

        self::assertSame(Lang::t('host.checkpoint.none'), $result->rows[1]->content);
    }

    public function testWithoutAnAutoCommitItRewindsTheConversationToTheLastCheckpoint(): void
    {
        $this->store->saveCheckpoint('undo-session', ['messages' => [], 'messagesPrecedePrompt' => true, 'inputBuf' => 'hello']);

        $result = (new UndoCommand())->run($this->context([Message::user('hello'), Message::assistant('hi')]), '/undo');

        self::assertFalse($result->isRefused());
        self::assertNotNull($result->effect(\SugarCraft\Code\Host\Commands\CommandEffectKind::RestoreCheckpoint));
    }

    public function testASessionAutoCommitIsRevertedInsteadOfTheConversation(): void
    {
        file_put_contents($this->repo . '/file.txt', "edited by the model\n");
        $outcome = $this->committer()->commit(['file.txt'], 'feat: model edit');
        self::assertTrue($outcome['ok'], $outcome['reason']);

        $result = (new UndoCommand())->run($this->context(), '/undo');

        self::assertStringStartsWith('Reverted ', $result->rows[1]->content);
        self::assertSame("base\n", $this->file('file.txt'));
    }

    public function testUndoAutoCommitReportsTheRevertAndLeavesTheModelAnInstruction(): void
    {
        file_put_contents($this->repo . '/file.txt', "edited\n");
        $this->committer()->commit(['file.txt'], 'feat: model edit');

        $result = UndoCommand::undoAutoCommit($this->committer());

        self::assertSame('/undo', $result->rows[0]->content);
        self::assertStringContainsString('feat: model edit', $result->rows[1]->content);
        self::assertStringContainsString('file.txt', $result->rows[1]->content);
        self::assertCount(3, $result->rows);
        self::assertFalse($result->rows[2]->uiOnly, 'the model is told the change was reverted');
        self::assertStringContainsString('The user ran /undo', $result->rows[2]->content);
    }

    public function testUndoAutoCommitRefusesWhenHeadIsNotOurs(): void
    {
        $result = UndoCommand::undoAutoCommit($this->committer());

        self::assertStringStartsWith('Nothing was undone: ', $result->rows[1]->content);
        self::assertStringContainsString(Lang::t('host.undo.rewind_hint'), $result->rows[1]->content);
    }
}
