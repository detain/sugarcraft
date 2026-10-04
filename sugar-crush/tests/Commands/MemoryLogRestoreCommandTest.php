<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Commands;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Crush\Backend\EchoBackend;
use SugarCraft\Crush\Chat;
use SugarCraft\Crush\Commands\MemoryHistoryCommand;
use SugarCraft\Crush\Memory\MemoryHistory;
use SugarCraft\Crush\Memory\MemoryStore;
use SugarCraft\Crush\Message;
use SugarCraft\Crush\Tests\Support\HomeSandboxTrait;

/**
 * Roadmap 5.4-2 through the chat: every `/memory` change is a commit of the
 * home memory directory, `/memory log` lists them and `/memory restore`
 * puts memory back as it stood at one.
 */
final class MemoryLogRestoreCommandTest extends TestCase
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

        $this->sandbox = sys_get_temp_dir() . '/crush-memlog-' . bin2hex(random_bytes(6));
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

    public function testEveryChangeIsACommitAndTheLogListsThem(): void
    {
        $this->reply('/memory add first note --scope user');
        $this->reply('/memory add second note --scope user');

        $log = $this->reply('/memory log');

        $this->assertStringContainsString('**Memory history** (3, newest first)', $log);
        $this->assertStringContainsString(MemoryHistory::START_SUBJECT, $log);
        $this->assertSame(2, substr_count($log, 'memory: /memory add'));
        $this->assertDirectoryExists($this->sandbox . '/store/.git');
    }

    public function testAChangeNoCommandMadeIsCommittedUnderItsOwnSubject(): void
    {
        $this->reply('/memory add first note --scope user');
        // The Memory tool, auto-memory or a hand edit: written to the store
        // directly, outside any /memory command.
        $this->store->add('written by the tool', 'user');
        $this->reply('/memory add second note --scope user');

        $subjects = array_map(
            static fn($r): string => $r->subject,
            (array) MemoryHistory::forStore($this->store)?->log(),
        );

        $this->assertSame(
            ['memory: /memory add', MemoryHistoryCommand::OUTSIDE_SUBJECT, 'memory: /memory add', MemoryHistory::START_SUBJECT],
            $subjects,
            'the command is never credited with the change it did not make',
        );
    }

    public function testRestorePutsMemoryBackAndCanBeUndone(): void
    {
        $this->reply('/memory add keep me --scope user');
        $first = (string) MemoryHistory::forStore($this->store)?->log()[0]->shortSha;
        $this->reply('/memory add drop me --scope user');

        $reply = $this->reply("/memory restore {$first}");

        $this->assertStringContainsString("Memory restored to `{$first}`", $reply);
        $contents = array_map(static fn($e): string => $e->content(), $this->store->list('user'));
        $this->assertContains('keep me', $contents);
        $this->assertNotContains('drop me', $contents);

        // The note it removed is still in the history, one restore away.
        $log = (array) MemoryHistory::forStore($this->store)?->log();
        $this->assertStringStartsWith('memory: restore to ', $log[0]->subject);
        $this->reply('/memory restore ' . $log[1]->shortSha);
        $contents = array_map(static fn($e): string => $e->content(), $this->store->list('user'));
        $this->assertContains('drop me', $contents);
    }

    public function testRestoreRefusesWhatIsNotACommitId(): void
    {
        $this->reply('/memory add a note --scope user');

        $this->assertStringContainsString('Usage: /memory restore <commit>', $this->reply('/memory restore'));
        $this->assertStringContainsString('Cannot restore', $this->reply('/memory restore HEAD'));
        $this->assertStringContainsString('Cannot restore', $this->reply('/memory restore deadbeef'));
        $this->assertStringContainsString('Usage: /memory log [count]', $this->reply('/memory log lots'));
    }

    public function testARepositoryStoreIsNeverVersioned(): void
    {
        $repoStore = MemoryStore::forRepository($this->sandbox . '/store');

        $reply = $this->reply('/memory log', $repoStore);

        $this->assertStringContainsString('home memory directory', $reply);
        $this->assertDirectoryDoesNotExist($this->sandbox . '/store/.git');
    }

    public function testTheHelpNamesBothSubCommands(): void
    {
        $help = $this->reply('/memory');

        $this->assertStringContainsString('/memory log [count]', $help);
        $this->assertStringContainsString('/memory restore <commit>', $help);
    }

    private function reply(string $draft, ?MemoryStore $store = null): string
    {
        $chat = (new Chat(
            history: [Message::user('hello'), Message::assistant('hi')],
            inputBuf: $draft,
            backend: new EchoBackend(),
            memoryStore: $store ?? $this->store,
            projectRoot: $this->projectRoot,
        ))->withSize(100, 30);

        [$next] = $chat->update(new KeyMsg(KeyType::Enter));
        $this->assertInstanceOf(Chat::class, $next);
        $this->assertCount(4, $next->history, 'a memory command appends exactly the command and its reply');

        return $next->history[3]->content;
    }
}
