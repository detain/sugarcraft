<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Chat;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Chat;
use SugarCraft\Crush\Message;
use SugarCraft\Crush\Tests\Support\CheckpointedRepoTrait;
use SugarCraft\Crush\Workspace\CheckpointDiff;
use SugarCraft\Crush\Workspace\WorkspaceCheckpointer;

/**
 * Item 3.A-2: `/undo` takes back the last turn — conversation and files —
 * `/redo` walks forward again until the next prompt, and `/diff [n]` shows
 * what changed in the files since a checkpoint.
 *
 * @see Chat::handleUndoCommand()
 * @see Chat::handleRedoCommand()
 * @see Chat::handleDiffCommand()
 */
final class UndoRedoDiffCommandsTest extends TestCase
{
    use CheckpointedRepoTrait;

    private const FIRST = [['role' => 'user', 'content' => 'first'], ['role' => 'assistant', 'content' => 'one']];

    protected function setUp(): void
    {
        $this->setUpCheckpointedRepo();
    }

    protected function tearDown(): void
    {
        $this->tearDownCheckpointedRepo();
    }

    public function testUndoTakesBackTheTurnAndRedoBringsItBackFilesIncluded(): void
    {
        $chat = $this->twoTurns();

        $undone = $this->send('/undo', $chat);
        self::assertSame(['first', 'one'], self::conversation($undone));
        self::assertSame('second', $undone->inputBuf, 'the prompt goes back in the box');
        self::assertSame("turn one\n", $this->file('file.txt'));
        self::assertSame("created\n", $this->file('new.txt'));
        self::assertStringContainsString('Restored the files', self::reply($undone));

        $twice = $this->send('/undo', $undone);
        self::assertSame([], self::conversation($twice));
        self::assertSame('first', $twice->inputBuf);
        self::assertSame("base\n", $this->file('file.txt'));
        self::assertNull($this->file('new.txt'), 'a file the turn created is gone again');

        $redone = $this->send('/redo', $twice);
        self::assertSame(['first', 'one'], self::conversation($redone));
        self::assertSame('second', $redone->inputBuf);
        self::assertSame("turn one\n", $this->file('file.txt'));
        self::assertSame("created\n", $this->file('new.txt'));
        self::assertStringContainsString('to checkpoint 1', self::reply($redone));

        $back = $this->send('/redo', $redone);
        self::assertSame(['first', 'one', 'second', 'two'], self::conversation($back));
        self::assertSame('', $back->inputBuf);
        self::assertSame("turn two\n", $this->file('file.txt'), 'the last redo returns the files the first undo left');
        self::assertStringContainsString('back where you were', self::reply($back));

        $none = $this->send('/redo', $back);
        self::assertStringStartsWith('Nothing to redo', self::reply($none));
        self::assertSame(
            [WorkspaceCheckpointer::refFor('undo-session', 0), WorkspaceCheckpointer::refFor('undo-session', 1)],
            $this->refs(),
            'the end of the stack unpins the state it was keeping',
        );
    }

    public function testTheNextPromptDiscardsTheRedoStack(): void
    {
        $undone = $this->send('/undo', $this->twoTurns());

        // Enter on the restored draft is a new turn: its checkpoint save
        // ends the stack, as in every editor.
        [$sent] = $this->chatAt('a different second prompt', $undone->history)->update(
            new \SugarCraft\Core\Msg\KeyMsg(\SugarCraft\Core\KeyType::Enter, ''),
        );
        self::assertTrue($sent->inFlight);

        self::assertSame([], $this->store->redoStack('undo-session'));
        self::assertStringStartsWith('Nothing to redo', self::reply($this->send('/redo', $undone)));
    }

    public function testRedoLeavesFilesAloneThatNoLongerMatchWhereTheConversationIs(): void
    {
        $undone = $this->send('/undo', $this->twoTurns());
        file_put_contents($this->repo . '/file.txt', "edited by hand after the undo\n");

        $redone = $this->send('/redo', $undone);

        self::assertSame(['first', 'one', 'second', 'two'], self::conversation($redone), 'the conversation still moves');
        self::assertSame("edited by hand after the undo\n", $this->file('file.txt'), 'work the redo knows nothing about is kept');
        self::assertStringContainsString('The files were left as they are', self::reply($redone));
    }

    public function testUndoOfACheckpointWithoutASnapshotRewindsTheConversationAndSaysWhy(): void
    {
        $this->store->saveCheckpoint('undo-session', ['messages' => [], 'messagesPrecedePrompt' => true, 'inputBuf' => 'hello']);
        file_put_contents($this->repo . '/file.txt', "edited\n");

        $undone = $this->send('/undo', [Message::user('hello'), Message::assistant('hi')]);

        self::assertSame([], self::conversation($undone));
        self::assertSame('hello', $undone->inputBuf);
        self::assertSame("edited\n", $this->file('file.txt'));
        self::assertStringContainsString('The files were left as they are: no snapshot was taken', self::reply($undone));
    }

    public function testUndoWithNothingToUndoSaysSo(): void
    {
        self::assertSame('No checkpoints available to rewind to.', self::reply($this->send('/undo', [])));
    }

    public function testDiffShowsWhatChangedSinceTheCheckpointAsAUiOnlyPatch(): void
    {
        $chat = $this->twoTurns();

        $diffed = $this->send('/diff', $chat);

        $reply = self::reply($diffed);
        self::assertStringStartsWith('1 file changed since checkpoint 1', $reply);
        self::assertStringContainsString("M file.txt", $reply);
        self::assertStringContainsString("```diff\n", $reply);
        self::assertStringContainsString("-turn one\n+turn two", $reply);
        self::assertTrue($diffed->history[\count($diffed->history) - 1]->uiOnly, 'a patch on screen is not sent to the model');
        self::assertSame(['first', 'one', 'second', 'two'], self::conversation($diffed), 'a diff changes nothing');
        self::assertSame("turn two\n", $this->file('file.txt'));

        $older = self::reply($this->send('/diff 2', $chat));
        self::assertStringStartsWith('2 files changed since checkpoint 0', $older);
        self::assertStringContainsString('A new.txt', $older);
        self::assertStringContainsString('+created', $older);
    }

    public function testDiffWhenNothingChangedAndOnBadInput(): void
    {
        $this->turnCheckpoint([], 'first');

        self::assertSame('The files match checkpoint 0: nothing has changed since.', self::reply($this->send('/diff', [])));
        foreach (['/diff 0', '/diff -1', '/diff last'] as $bad) {
            self::assertStringStartsWith('Usage: /diff [n]', self::reply($this->send($bad, [])), $bad);
        }
    }

    public function testDiffSanitisesWhatItPutsOnScreenAndFencesAroundBackticks(): void
    {
        $this->turnCheckpoint([], 'first');
        file_put_contents($this->repo . '/file.txt', "\e]52;c;evil\x07 ```` fence\n");

        $reply = self::reply($this->send('/diff', []));

        self::assertStringNotContainsString("\e", $reply);
        self::assertStringNotContainsString("\x07", $reply);
        self::assertStringContainsString("`````diff\n", $reply, 'the fence outgrows the longest backtick run inside it');
    }

    public function testDiffRunsInAReadOnlyWindowWhileUndoAndRedoAreRefused(): void
    {
        $this->turnCheckpoint([], 'first');
        file_put_contents($this->repo . '/file.txt', "changed\n");

        [$diffed] = $this->chatAt('/diff', [], true)->update(new \SugarCraft\Core\Msg\KeyMsg(\SugarCraft\Core\KeyType::Enter, ''));
        self::assertStringStartsWith('1 file changed since checkpoint 0', self::reply($diffed));

        foreach (['/undo', '/redo'] as $writer) {
            [$refused] = $this->chatAt($writer, [], true)->update(new \SugarCraft\Core\Msg\KeyMsg(\SugarCraft\Core\KeyType::Enter, ''));
            self::assertStringStartsWith('"' . $writer . '" was not sent', self::reply($refused), $writer . ' writes, so a read-only window refuses it');
        }
        self::assertSame("changed\n", $this->file('file.txt'));
    }

    public function testTheDiffIsBoundedForTheScreen(): void
    {
        $this->turnCheckpoint([], 'first');
        file_put_contents($this->repo . '/file.txt', str_repeat("line\n", CheckpointDiff::MAX_LINES * 2));

        $reply = self::reply($this->send('/diff', []));

        self::assertLessThan(CheckpointDiff::MAX_BYTES + 4096, \strlen($reply));
        self::assertMatchesRegularExpression('/\d+ more lines of the patch are not shown\.$/', $reply);
    }

    /**
     * Two turns: the first edits file.txt and creates new.txt, the second
     * edits file.txt again. Returns the window after the second.
     */
    private function twoTurns(): Chat
    {
        $this->turnCheckpoint([], 'first');
        file_put_contents($this->repo . '/file.txt', "turn one\n");
        file_put_contents($this->repo . '/new.txt', "created\n");

        $this->turnCheckpoint(self::FIRST, 'second');
        file_put_contents($this->repo . '/file.txt', "turn two\n");

        return $this->chatAt('', [
            Message::user('first'),
            Message::assistant('one'),
            Message::user('second'),
            Message::assistant('two'),
        ]);
    }

    /** @return list<string> */
    private function refs(): array
    {
        $out = $this->gitAt('for-each-ref', '--format=%(refname)', 'refs/sugar-crush/');

        return $out === '' ? [] : explode("\n", $out);
    }
}
