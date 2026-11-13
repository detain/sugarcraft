<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Host\Commands;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Host\Commands\CommandContext;
use SugarCraft\Code\Host\Commands\RedoCommand;
use SugarCraft\Code\Host\Commands\UndoCommand;
use SugarCraft\Code\Lang;
use SugarCraft\Code\Message;
use SugarCraft\Code\Session\SessionStore;
use SugarCraft\Code\Tests\Support\CheckpointedRepoTrait;

final class RedoCommandTest extends TestCase
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

    private function context(array $history = []): CommandContext
    {
        return CommandContext::new(history: $history, sessionStore: $this->store, sessionId: 'undo-session', root: $this->repo);
    }

    public function testTheTypedTextIsNormalisedToRedo(): void
    {
        $result = (new RedoCommand())->run($this->context(), '/redo now');

        self::assertSame('/redo', $result->rows[0]->content);
    }

    public function testRefusalsMatchTheCheckpointFamily(): void
    {
        self::assertSame(Lang::t('host.checkpoint.no_store'), (new RedoCommand())->run(CommandContext::new(), '/redo')->rows[1]->content);
        self::assertSame(
            Lang::t('host.checkpoint.unsupported_store'),
            (new RedoCommand())->run(CommandContext::new(sessionStore: new SessionStore($this->tmp . '/db/plain.db'), sessionId: 's'), '/redo')->rows[1]->content,
        );
        self::assertSame(
            Lang::t('host.checkpoint.no_session'),
            (new RedoCommand())->run(CommandContext::new(sessionStore: $this->store), '/redo')->rows[1]->content,
        );
    }

    public function testNothingSetAsideMeansNothingToRedo(): void
    {
        $result = (new RedoCommand())->run($this->context(), '/redo');

        self::assertSame(Lang::t('host.redo.nothing'), $result->rows[1]->content);
    }

    public function testRedoStepsForwardOverWhatUndoSetAside(): void
    {
        $this->turnCheckpoint([], 'first');
        $history = [Message::user('first'), Message::assistant('one')];
        $undo = (new UndoCommand())->run($this->context($history), '/undo');
        self::assertFalse($undo->isRefused());

        $redo = (new RedoCommand())->run($this->context([]), '/redo');

        self::assertFalse($redo->isRefused());
        self::assertNotSame(Lang::t('host.redo.nothing'), $redo->rows[1]->content ?? '');
        self::assertNotNull($redo->effect(\SugarCraft\Code\Host\Commands\CommandEffectKind::RestoreCheckpoint));
    }
}
