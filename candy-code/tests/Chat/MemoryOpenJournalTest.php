<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Chat;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\ExecRequest;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Code\App\StatusMsg;
use SugarCraft\Code\Backend\EchoBackend;
use SugarCraft\Code\Chat;
use SugarCraft\Code\Commands\EditorCommand;
use SugarCraft\Code\Commands\MemoryHistoryCommand;
use SugarCraft\Code\Memory\MemoryHistory;
use SugarCraft\Code\Memory\MemoryStore;
use SugarCraft\Code\Message;
use SugarCraft\Code\Tests\Support\HomeSandboxTrait;

/**
 * C-CTX-6.d: an edit made through `/memory open <id>` enters the memory
 * journal under its own subject. The arm answers before the `/memory`
 * router's commit-wrap ever sees `open`, so without the EditorCommand
 * hook the edit would reach the history only when some later command
 * swept it into an outside-/memory commit — never credited to the open,
 * and invisible to `/memory log` until then.
 */
final class MemoryOpenJournalTest extends TestCase
{
    use HomeSandboxTrait;

    private string $sandbox = '';
    private string $projectRoot = '';
    private MemoryStore $store;

    protected function setUp(): void
    {
        if (!MemoryHistory::available()) {
            self::markTestSkipped('git is not on PATH');
        }

        $this->sandbox = sys_get_temp_dir() . '/crush-memopen-' . bin2hex(random_bytes(6));
        $this->useHomeSandbox($this->sandbox . '/home');
        $this->projectRoot = $this->sandbox . '/project';
        mkdir($this->sandbox . '/store', 0700, true);
        mkdir($this->projectRoot, 0700, true);
        $this->store = new MemoryStore($this->sandbox . '/store');
    }

    protected function tearDown(): void
    {
        $this->restoreHomeSandbox();
        if ($this->sandbox !== '' && is_dir($this->sandbox)) {
            exec('rm -rf ' . escapeshellarg($this->sandbox));
        }
    }

    public function testAnEditMadeThroughMemoryOpenIsJournaledUnderItsOwnSubject(): void
    {
        $id = $this->store->add('Open me in the editor', 'user');
        $path = $this->store->notePath($id);
        $this->assertIsString($path);

        $request = $this->armMemoryOpen($id);
        // What the editor leaves behind: the file itself, bytes changed.
        \file_put_contents($path, \file_get_contents($path) . "\nEdited by the test editor.\n");

        $msg = ($request->onComplete)(0, '', '', null);
        $this->assertInstanceOf(StatusMsg::class, $msg);

        $log = $this->journalReply('/memory log');
        $this->assertStringContainsString("memory: /memory open {$id}", $log);
        // The edit is credited to the open, not swept under the outside
        // subject a later command would have filed it as pre-fix.
        $this->assertStringNotContainsString(MemoryHistoryCommand::OUTSIDE_SUBJECT, $log);
    }

    public function testAnUnchangedOpenRecordsNothing(): void
    {
        $id = $this->store->add('Leave me alone', 'user');
        $path = $this->store->notePath($id);
        $this->assertIsString($path);

        $request = $this->armMemoryOpen($id);
        // A clean exit with the bytes untouched — the user just looked.
        $msg = ($request->onComplete)(0, '', '', null);
        $this->assertInstanceOf(StatusMsg::class, $msg);

        $log = $this->journalReply('/memory log');
        $this->assertStringNotContainsString('memory: /memory open', $log);
    }

    public function testTheHookRunsOnlyOnACleanExitAndAChangedFile(): void
    {
        $path = $this->sandbox . '/store/note-direct.md';
        \file_put_contents($path, "one\n");
        $edits = 0;
        $hook = static function () use (&$edits): ?string {
            $edits++;
            return null;
        };

        // Clean exit, file unchanged: the hook never fires.
        $request = $this->armPlainOpen($path, $hook);
        $this->assertInstanceOf(StatusMsg::class, ($request->onComplete)(0, '', '', null));
        $this->assertSame(0, $edits);

        // Clean exit, file changed: exactly once.
        $request = $this->armPlainOpen($path, $hook);
        \file_put_contents($path, "changed\n");
        $this->assertInstanceOf(StatusMsg::class, ($request->onComplete)(0, '', '', null));
        $this->assertSame(1, $edits);

        // Non-zero exit with the file changed: the edit is the editor's
        // failure and journals nothing.
        $request = $this->armPlainOpen($path, $hook);
        \file_put_contents($path, "changed again\n");
        ($request->onComplete)(2, '', '', null);
        $this->assertSame(1, $edits);

        // The child never started: nothing to journal either.
        $request = $this->armPlainOpen($path, $hook);
        \file_put_contents($path, "changed once more\n");
        ($request->onComplete)(0, '', '', new \RuntimeException('no terminal'));
        $this->assertSame(1, $edits);
    }

    /**
     * The ExecRequest `/memory open <id>` arms onto the exec channel —
     * hash read at arm time, so mutate the note only after this returns.
     */
    private function armMemoryOpen(string $id): ExecRequest
    {
        $chat = (new Chat(
            history: [Message::user('hello'), Message::assistant('hi')],
            inputBuf: '/memory open ' . $id,
            backend: new EchoBackend(),
            memoryStore: $this->store,
            projectRoot: $this->projectRoot,
        ))->withSize(100, 30);

        [$next, $cmd] = $chat->update(new KeyMsg(KeyType::Enter));
        $this->assertInstanceOf(Chat::class, $next);
        $this->assertNotNull($cmd, 'the open arm resolves a command');
        $this->assertFalse($next->inFlight);

        $request = $cmd();
        $this->assertInstanceOf(ExecRequest::class, $request);

        return $request;
    }

    /** The transcript reply a later `/memory <sub>` answers with. */
    private function journalReply(string $draft): string
    {
        $chat = (new Chat(
            history: [Message::user('hello'), Message::assistant('hi')],
            inputBuf: $draft,
            backend: new EchoBackend(),
            memoryStore: $this->store,
            projectRoot: $this->projectRoot,
        ))->withSize(100, 30);

        [$next] = $chat->update(new KeyMsg(KeyType::Enter));
        $this->assertInstanceOf(Chat::class, $next);
        $this->assertCount(4, $next->history, 'a memory command appends exactly the command and its reply');

        return $next->history[3]->content;
    }

    /**
     * The same hook on the plain EditorCommand surface, so the gate
     * precision (exit 0 AND hash changed) is pinned without a Chat.
     */
    private function armPlainOpen(string $path, \Closure $hook): ExecRequest
    {
        $cmd = EditorCommand::new()
            ->withEnvironment(['EDITOR' => 'stub-editor'], $this->sandbox)
            ->openFile($path, $hook);

        $request = $cmd();
        $this->assertInstanceOf(ExecRequest::class, $request);

        return $request;
    }
}
