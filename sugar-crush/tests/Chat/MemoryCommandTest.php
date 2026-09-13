<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Chat;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Crush\Backend\EchoBackend;
use SugarCraft\Crush\Chat;
use SugarCraft\Crush\Context\ProjectMemoryWriter;
use SugarCraft\Crush\Message;
use SugarCraft\Crush\Memory\MemoryEntry;
use SugarCraft\Crush\Memory\MemoryStore;
use SugarCraft\Crush\Role;

final class MemoryCommandTest extends TestCase
{
    private string $tempDir;
    private MemoryStore $memoryStore;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/memory_test_' . uniqid((string) getmypid(), true);
        mkdir($this->tempDir, 0755, true);
        $this->memoryStore = new MemoryStore($this->tempDir);
    }

    protected function tearDown(): void
    {
        // Recursive: the old cleanup only unlinked *.md, so anything else the
        // store wrote (index subdirectories, non-.md sidecars) left the
        // directory non-empty and every rmdir() raised "Directory not empty"
        // -- 10 of the suite's warnings came from this one line.
        self::removeTree($this->tempDir);
    }

    /** Delete a directory and everything under it, quietly and completely. */
    private static function removeTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $entries = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($entries as $entry) {
            /** @var \SplFileInfo $entry */
            if ($entry->isDir()) {
                @rmdir($entry->getPathname());
            } else {
                @unlink($entry->getPathname());
            }
        }

        @rmdir($dir);
    }

    public function testMemoryListShowsMemories(): void
    {
        // Add some memories
        $this->memoryStore->add('Test memory content', 'user');

        $chat = new Chat(
            history: [],
            inputBuf: '/memory list',
            backend: new EchoBackend(),
            memoryStore: $this->memoryStore,
        );

        [$next, ] = $chat->update(new KeyMsg(KeyType::Enter, ''));

        $this->assertFalse($next->inFlight);
        $lastMsg = $next->history[count($next->history) - 1];
        $this->assertSame(Role::Assistant, $lastMsg->role);
        $this->assertStringContainsString('Test memory content', $lastMsg->content);
    }

    public function testMemoryListWithEmptyStore(): void
    {
        $chat = new Chat(
            history: [],
            inputBuf: '/memory list',
            backend: new EchoBackend(),
            memoryStore: $this->memoryStore,
        );

        [$next, ] = $chat->update(new KeyMsg(KeyType::Enter, ''));

        $this->assertFalse($next->inFlight);
        $lastMsg = $next->history[count($next->history) - 1];
        $this->assertSame(Role::Assistant, $lastMsg->role);
        $this->assertStringContainsString('No memories found', $lastMsg->content);
    }

    public function testMemoryAddCreatesEntry(): void
    {
        $chat = new Chat(
            history: [],
            inputBuf: '/memory add This is a new memory',
            backend: new EchoBackend(),
            memoryStore: $this->memoryStore,
        );

        [$next, ] = $chat->update(new KeyMsg(KeyType::Enter, ''));

        $this->assertFalse($next->inFlight);
        $lastMsg = $next->history[count($next->history) - 1];
        $this->assertSame(Role::Assistant, $lastMsg->role);
        $this->assertStringContainsString('Memory created', $lastMsg->content);
        $this->assertStringContainsString('`', $lastMsg->content); // Contains backticks for ID
    }

    public function testMemoryAddWithScope(): void
    {
        $chat = new Chat(
            history: [],
            // Explicit sandbox root: a project-scope ADD now lands in the
            // ROOT's repo-local corner (E25 piece 2), so a Chat left without a
            // root would write notes into whatever tree runs the suite.
            projectRoot: $this->tempDir,
            inputBuf: '/memory add --scope project This is a project memory',
            backend: new EchoBackend(),
            memoryStore: $this->memoryStore,
        );

        [$next, ] = $chat->update(new KeyMsg(KeyType::Enter, ''));

        $this->assertFalse($next->inFlight);
        $lastMsg = $next->history[count($next->history) - 1];
        $this->assertSame(Role::Assistant, $lastMsg->role);
        $this->assertStringContainsString('Memory created', $lastMsg->content);
    }

    public function testMemorySearchFindsEntries(): void
    {
        $this->memoryStore->add('PHP programming tips', 'user');

        $chat = new Chat(
            history: [],
            inputBuf: '/memory search PHP',
            backend: new EchoBackend(),
            memoryStore: $this->memoryStore,
        );

        [$next, ] = $chat->update(new KeyMsg(KeyType::Enter, ''));

        $this->assertFalse($next->inFlight);
        $lastMsg = $next->history[count($next->history) - 1];
        $this->assertSame(Role::Assistant, $lastMsg->role);
        $this->assertStringContainsString('PHP programming tips', $lastMsg->content);
    }

    public function testMemorySearchNoResults(): void
    {
        $this->memoryStore->add('Some content', 'user');

        $chat = new Chat(
            history: [],
            inputBuf: '/memory search nonexistent',
            backend: new EchoBackend(),
            memoryStore: $this->memoryStore,
        );

        [$next, ] = $chat->update(new KeyMsg(KeyType::Enter, ''));

        $this->assertFalse($next->inFlight);
        $lastMsg = $next->history[count($next->history) - 1];
        $this->assertSame(Role::Assistant, $lastMsg->role);
        $this->assertStringContainsString('No memories found matching', $lastMsg->content);
    }

    public function testMemoryEditUpdatesEntry(): void
    {
        $id = $this->memoryStore->add('Original content', 'user');

        $chat = new Chat(
            history: [],
            inputBuf: "/memory edit {$id} Updated content",
            backend: new EchoBackend(),
            memoryStore: $this->memoryStore,
        );

        [$next, ] = $chat->update(new KeyMsg(KeyType::Enter, ''));

        $this->assertFalse($next->inFlight);
        $lastMsg = $next->history[count($next->history) - 1];
        $this->assertSame(Role::Assistant, $lastMsg->role);
        $this->assertStringContainsString('updated', $lastMsg->content);

        // Verify the entry was actually updated
        $entry = $this->memoryStore->get($id);
        $this->assertNotNull($entry);
        $this->assertSame('Updated content', $entry->content());
    }

    public function testMemoryEditNotFound(): void
    {
        $chat = new Chat(
            history: [],
            inputBuf: '/memory edit 00000000000000000000000000000000 New content',
            backend: new EchoBackend(),
            memoryStore: $this->memoryStore,
        );

        [$next, ] = $chat->update(new KeyMsg(KeyType::Enter, ''));

        $this->assertFalse($next->inFlight);
        $lastMsg = $next->history[count($next->history) - 1];
        $this->assertSame(Role::Assistant, $lastMsg->role);
        $this->assertStringContainsString('not found', $lastMsg->content);
    }

    public function testMemoryDeleteRemovesEntry(): void
    {
        $id = $this->memoryStore->add('Content to delete', 'user');

        $chat = new Chat(
            history: [],
            inputBuf: "/memory delete {$id}",
            backend: new EchoBackend(),
            memoryStore: $this->memoryStore,
        );

        [$next, ] = $chat->update(new KeyMsg(KeyType::Enter, ''));

        $this->assertFalse($next->inFlight);
        $lastMsg = $next->history[count($next->history) - 1];
        $this->assertSame(Role::Assistant, $lastMsg->role);
        $this->assertStringContainsString('deleted', $lastMsg->content);

        // Verify the entry was actually deleted
        $entry = $this->memoryStore->get($id);
        $this->assertNull($entry);
    }

    public function testMemoryDeleteNotFound(): void
    {
        $chat = new Chat(
            history: [],
            inputBuf: '/memory delete 00000000000000000000000000000000',
            backend: new EchoBackend(),
            memoryStore: $this->memoryStore,
        );

        [$next, ] = $chat->update(new KeyMsg(KeyType::Enter, ''));

        $this->assertFalse($next->inFlight);
        $lastMsg = $next->history[count($next->history) - 1];
        $this->assertSame(Role::Assistant, $lastMsg->role);
        $this->assertStringContainsString('not found', $lastMsg->content);
    }

    // ---- cross-store resolution (lane jb, r75): per-id ops follow capture()'s fold law ----

    /** Repo-local memory dir of the sandboxed project root, as the writer lays it out. */
    private function repoMemoryPath(): string
    {
        return $this->tempDir . '/' . ProjectMemoryWriter::RELATIVE_DIRECTORY . '/project';
    }

    /** The single entry id living in a store directory ('' when there is none). */
    private function soleEntryIdIn(string $scopeDir): string
    {
        $files = glob($scopeDir . '/*.md') ?: [];
        $files = array_values(array_filter($files, static fn (string $f): bool => !str_ends_with($f, 'MEMORY.md')));
        $this->assertCount(1, $files, 'expected exactly one note in ' . $scopeDir);

        return basename($files[0], '.md');
    }

    public function testProjectNoteAddedByCommandIsDeletableFromRepoStore(): void
    {
        [$created, ] = (new Chat(
            history: [],
            projectRoot: $this->tempDir,
            inputBuf: '/memory add --scope project note the repo will hold',
            backend: new EchoBackend(),
            memoryStore: $this->memoryStore,
        ))->update(new KeyMsg(KeyType::Enter, ''));

        $id = $this->soleEntryIdIn($this->repoMemoryPath());
        $this->assertStringContainsString($id, $created->history[count($created->history) - 1]->content);

        [$next, ] = (new Chat(
            history: [],
            projectRoot: $this->tempDir,
            inputBuf: "/memory delete {$id}",
            backend: new EchoBackend(),
            memoryStore: $this->memoryStore,
        ))->update(new KeyMsg(KeyType::Enter, ''));

        $this->assertStringContainsString('deleted', $next->history[count($next->history) - 1]->content);
        $this->assertSame([], array_filter(glob($this->repoMemoryPath() . '/*.md') ?: [], static fn (string $f): bool => !str_ends_with($f, 'MEMORY.md')));
        // The home store never held this note and must not have gained one.
        $this->assertSame([], $this->memoryStore->list('project'));
    }

    public function testSharedIdResolvesRepoFirstSoTheShownEntryDiesAndTheTwinSurvives(): void
    {
        // capture() folds the REPO copy when both stores hold one id (E25p2's
        // documented law). Ops must claim the same entry — otherwise deleting
        // the note the prompt shows silently kills its invisible home twin and
        // the shown note survives its own deletion.
        $writer = ProjectMemoryWriter::createForRoot($this->tempDir);
        $this->assertNotNull($writer);
        $id = $writer->write('Repo note - the one the prompt shows');
        $this->memoryStore->update($id, MemoryEntry::new(
            type: 'pattern',
            content: 'Home twin - invisible behind the repo copy',
            scope: 'project',
            tags: [],
            id: $id,
        ));

        [$next, ] = (new Chat(
            history: [],
            projectRoot: $this->tempDir,
            inputBuf: "/memory delete {$id}",
            backend: new EchoBackend(),
            memoryStore: $this->memoryStore,
        ))->update(new KeyMsg(KeyType::Enter, ''));

        $this->assertStringContainsString('deleted', $next->history[count($next->history) - 1]->content);
        $this->assertNull($writer->store()->get($id), 'the repo copy the fold SHOWS must be the copy delete removes');
        $twin = $this->memoryStore->get($id);
        $this->assertNotNull($twin, 'the hidden home twin must survive the repo-first delete');
        $this->assertSame('Home twin - invisible behind the repo copy', $twin->content());
    }

    public function testHomeOnlyIdStillDeletesFromHomeWithRepoStorePresent(): void
    {
        $writer = ProjectMemoryWriter::createForRoot($this->tempDir);
        $this->assertNotNull($writer);
        $occupant = $writer->write('repo occupant');

        $id = $this->memoryStore->add('Home only', 'user');

        [$next, ] = (new Chat(
            history: [],
            projectRoot: $this->tempDir,
            inputBuf: "/memory delete {$id}",
            backend: new EchoBackend(),
            memoryStore: $this->memoryStore,
        ))->update(new KeyMsg(KeyType::Enter, ''));

        $this->assertStringContainsString('deleted', $next->history[count($next->history) - 1]->content);
        $this->assertNull($this->memoryStore->get($id));
        $this->assertNotNull($writer->store()->get($occupant), 'the repo store must be untouched by a home-only delete');
    }

    public function testEditResolvesRepoStore(): void
    {
        $writer = ProjectMemoryWriter::createForRoot($this->tempDir);
        $this->assertNotNull($writer);
        $id = $writer->write('original repo body');

        [$next, ] = (new Chat(
            history: [],
            projectRoot: $this->tempDir,
            inputBuf: "/memory edit {$id} edited repo body",
            backend: new EchoBackend(),
            memoryStore: $this->memoryStore,
        ))->update(new KeyMsg(KeyType::Enter, ''));

        $this->assertStringContainsString('updated', $next->history[count($next->history) - 1]->content);
        $entry = $writer->store()->get($id);
        $this->assertNotNull($entry);
        $this->assertSame('edited repo body', $entry->content());
        $this->assertNull($this->memoryStore->get($id), 'edit must not mint a home copy');
    }

    public function testEditNotFoundWithBothStoresResolvable(): void
    {
        $this->assertNotNull(ProjectMemoryWriter::createForRoot($this->tempDir));

        [$next, ] = (new Chat(
            history: [],
            projectRoot: $this->tempDir,
            inputBuf: '/memory edit 00000000000000000000000000000000 New content',
            backend: new EchoBackend(),
            memoryStore: $this->memoryStore,
        ))->update(new KeyMsg(KeyType::Enter, ''));

        $this->assertStringContainsString('not found', $next->history[count($next->history) - 1]->content);
    }

    public function testMemoryClearRequiresConfirm(): void
    {
        $this->memoryStore->add('Memory 1', 'project');

        $chat = new Chat(
            history: [],
            inputBuf: '/memory clear --scope project',
            backend: new EchoBackend(),
            memoryStore: $this->memoryStore,
        );

        [$next, ] = $chat->update(new KeyMsg(KeyType::Enter, ''));

        $this->assertFalse($next->inFlight);
        $lastMsg = $next->history[count($next->history) - 1];
        $this->assertSame(Role::Assistant, $lastMsg->role);
        $this->assertStringContainsString('--confirm', $lastMsg->content);

        // Verify nothing was deleted
        $entries = $this->memoryStore->list('project');
        $this->assertCount(1, $entries);
    }

    public function testMemoryClearWithConfirm(): void
    {
        $this->memoryStore->add('Memory to clear 1', 'project');
        $this->memoryStore->add('Memory to clear 2', 'project');

        $chat = new Chat(
            history: [],
            inputBuf: '/memory clear --scope project --confirm',
            backend: new EchoBackend(),
            memoryStore: $this->memoryStore,
        );

        [$next, ] = $chat->update(new KeyMsg(KeyType::Enter, ''));

        $this->assertFalse($next->inFlight);
        $lastMsg = $next->history[count($next->history) - 1];
        $this->assertSame(Role::Assistant, $lastMsg->role);
        $this->assertStringContainsString('cleared', $lastMsg->content);

        // Verify all entries were deleted
        $entries = $this->memoryStore->list('project');
        $this->assertCount(0, $entries);
    }

    public function testMemoryHelpShowsAllCommands(): void
    {
        $chat = new Chat(
            history: [],
            inputBuf: '/memory',
            backend: new EchoBackend(),
            memoryStore: $this->memoryStore,
        );

        [$next, ] = $chat->update(new KeyMsg(KeyType::Enter, ''));

        $this->assertFalse($next->inFlight);
        $lastMsg = $next->history[count($next->history) - 1];
        $this->assertSame(Role::Assistant, $lastMsg->role);
        $this->assertStringContainsString('/memory list', $lastMsg->content);
        $this->assertStringContainsString('/memory add', $lastMsg->content);
        $this->assertStringContainsString('/memory search', $lastMsg->content);
        $this->assertStringContainsString('/memory edit', $lastMsg->content);
        $this->assertStringContainsString('/memory delete', $lastMsg->content);
        $this->assertStringContainsString('/memory clear', $lastMsg->content);
    }

    public function testMemoryNotConfigured(): void
    {
        $chat = new Chat(
            history: [],
            inputBuf: '/memory list',
            backend: new EchoBackend(),
            memoryStore: null,
        );

        [$next, ] = $chat->update(new KeyMsg(KeyType::Enter, ''));

        $this->assertFalse($next->inFlight);
        $lastMsg = $next->history[count($next->history) - 1];
        $this->assertSame(Role::Assistant, $lastMsg->role);
        $this->assertStringContainsString('not configured', $lastMsg->content);
    }

    public function testMemoryUnknownCommand(): void
    {
        $chat = new Chat(
            history: [],
            inputBuf: '/memory unknown',
            backend: new EchoBackend(),
            memoryStore: $this->memoryStore,
        );

        [$next, ] = $chat->update(new KeyMsg(KeyType::Enter, ''));

        $this->assertFalse($next->inFlight);
        $lastMsg = $next->history[count($next->history) - 1];
        $this->assertSame(Role::Assistant, $lastMsg->role);
        $this->assertStringContainsString('Unknown command', $lastMsg->content);
    }

    public function testMemoryInputBufClearedAfterCommand(): void
    {
        $chat = new Chat(
            history: [],
            inputBuf: '/memory list',
            backend: new EchoBackend(),
            memoryStore: $this->memoryStore,
        );

        [$next, ] = $chat->update(new KeyMsg(KeyType::Enter, ''));

        $this->assertSame('', $next->inputBuf);
    }

    public function testMemoryListWithScopeFilter(): void
    {
        $this->memoryStore->add('User memory', 'user');
        $this->memoryStore->add('Project memory', 'project');

        $chat = new Chat(
            history: [],
            inputBuf: '/memory list --scope project',
            backend: new EchoBackend(),
            memoryStore: $this->memoryStore,
        );

        [$next, ] = $chat->update(new KeyMsg(KeyType::Enter, ''));

        $this->assertFalse($next->inFlight);
        $lastMsg = $next->history[count($next->history) - 1];
        $this->assertSame(Role::Assistant, $lastMsg->role);
        $this->assertStringContainsString('Project memory', $lastMsg->content);
        $this->assertStringNotContainsString('User memory', $lastMsg->content);
    }

    public function testWithMemoryStoreFluent(): void
    {
        $chat = new Chat(
            history: [],
            inputBuf: '',
            backend: new EchoBackend(),
        );

        $chatWithMemory = $chat->withMemoryStore($this->memoryStore);

        $this->assertNotSame($chat, $chatWithMemory);
        $this->assertFalse($chatWithMemory->inFlight);
        $this->assertNotNull($chatWithMemory->memoryStore());
    }
}
