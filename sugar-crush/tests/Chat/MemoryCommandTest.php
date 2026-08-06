<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Chat;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Crush\Backend\EchoBackend;
use SugarCraft\Crush\Chat;
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
        $this->tempDir = sys_get_temp_dir() . '/memory_test_' . uniqid();
        mkdir($this->tempDir, 0755, true);
        $this->memoryStore = new MemoryStore($this->tempDir);
    }

    protected function tearDown(): void
    {
        // Clean up temp files
        $files = glob($this->tempDir . '/*.md');
        if ($files !== false) {
            foreach ($files as $file) {
                unlink($file);
            }
        }
        $indexDir = $this->tempDir . '/indexes';
        if (is_dir($indexDir)) {
            $indexFiles = glob($indexDir . '/*.md');
            if ($indexFiles !== false) {
                foreach ($indexFiles as $file) {
                    unlink($file);
                }
            }
            rmdir($indexDir);
        }
        if (is_dir($this->tempDir)) {
            rmdir($this->tempDir);
        }
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
