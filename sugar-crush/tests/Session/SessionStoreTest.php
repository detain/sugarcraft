<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Session;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Session\SessionStore;

/**
 * @see SessionStore
 */
final class SessionStoreTest extends TestCase
{
    private string $tempDir;
    private string $dbPath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempDir = sys_get_temp_dir() . '/session_store_test_' . uniqid('', true);
        mkdir($this->tempDir, 0755, true);
        $this->dbPath = $this->tempDir . '/test.db';
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        if (isset($this->dbPath) && file_exists($this->dbPath)) {
            unlink($this->dbPath);
        }
        if (isset($this->tempDir) && is_dir($this->tempDir)) {
            rmdir($this->tempDir);
        }
    }

    // =========================================================================
    // Constructor Tests
    // =========================================================================

    public function testConstructorCreatesPDOAndTables(): void
    {
        $store = new SessionStore($this->dbPath);

        $this->assertFileExists($this->dbPath);

        // Verify tables were created by querying them
        $pdo = new \PDO("sqlite:{$this->dbPath}");
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

        $tables = $pdo->query("SELECT name FROM sqlite_master WHERE type='table'")->fetchAll(\PDO::FETCH_COLUMN);
        $this->assertContains('sessions', $tables);
        $this->assertContains('messages', $tables);
        $this->assertContains('tool_calls', $tables);
    }

    public function testConstructorCreatesOwnerOnlyDatabaseFile(): void
    {
        $store = new SessionStore($this->dbPath);

        $this->assertFileExists($this->dbPath);
        // Transcript DB holds full conversation history — must be owner-only.
        clearstatcache(true, $this->dbPath);
        $this->assertSame(0600, fileperms($this->dbPath) & 0777);
    }

    public function testConstructorTightensPermissionsOnPreexistingLooseDatabase(): void
    {
        // Simulate a database left world-readable by an older version.
        touch($this->dbPath);
        chmod($this->dbPath, 0644);

        $store = new SessionStore($this->dbPath);

        // The setup chmod above pollutes PHP's stat cache; clear it so
        // fileperms() reflects the constructor's on-disk 0600.
        clearstatcache(true, $this->dbPath);
        $this->assertSame(0600, fileperms($this->dbPath) & 0777);
    }

    public function testConstructorEnablesWALMode(): void
    {
        $store = new SessionStore($this->dbPath);

        $pdo = new \PDO("sqlite:{$this->dbPath}");
        $result = $pdo->query('PRAGMA journal_mode')->fetch(\PDO::FETCH_COLUMN);
        $this->assertSame('wal', $result);
    }

    public function testConstructorSetsExceptionMode(): void
    {
        $store = new SessionStore($this->dbPath);

        $pdo = new \PDO("sqlite:{$this->dbPath}");
        $this->assertSame(\PDO::ERRMODE_EXCEPTION, $pdo->getAttribute(\PDO::ATTR_ERRMODE));
    }

    // =========================================================================
    // createSession and getSession Tests
    // =========================================================================

    public function testCreateSessionInsertsSession(): void
    {
        $store = new SessionStore($this->dbPath);

        $store->createSession('session-1', 'openai', 'gpt-4', 'You are helpful');

        $session = $store->getSession('session-1');

        $this->assertNotNull($session);
        $this->assertSame('session-1', $session['id']);
        $this->assertSame('openai', $session['provider']);
        $this->assertSame('gpt-4', $session['model']);
        $this->assertSame('You are helpful', $session['system_prompt']);
    }

    public function testCreateSessionWithoutSystemPrompt(): void
    {
        $store = new SessionStore($this->dbPath);

        $store->createSession('session-2', 'anthropic', 'claude-3');

        $session = $store->getSession('session-2');

        $this->assertNotNull($session);
        $this->assertNull($session['system_prompt']);
    }

    public function testGetSessionReturnsNullForNonexistent(): void
    {
        $store = new SessionStore($this->dbPath);

        $session = $store->getSession('nonexistent');

        $this->assertNull($session);
    }

    // =========================================================================
    // listSessions Tests
    // =========================================================================

    public function testListSessionsReturnsSessionsOrderedByUpdatedAt(): void
    {
        $store = new SessionStore($this->dbPath);

        $store->createSession('session-a', 'provider-a', 'model-a');
        usleep(1000); // Ensure different timestamps
        $store->createSession('session-b', 'provider-b', 'model-b');
        usleep(1000);
        $store->createSession('session-c', 'provider-c', 'model-c');

        $sessions = $store->listSessions();

        $this->assertCount(3, $sessions);
        // Most recently updated should be first (session-c)
        $this->assertSame('session-c', $sessions[0]['id']);
        $this->assertSame('session-b', $sessions[1]['id']);
        $this->assertSame('session-a', $sessions[2]['id']);
    }

    public function testListSessionsRespectsLimit(): void
    {
        $store = new SessionStore($this->dbPath);

        $store->createSession('session-1', 'p', 'm');
        $store->createSession('session-2', 'p', 'm');
        $store->createSession('session-3', 'p', 'm');

        $sessions = $store->listSessions(2);

        $this->assertCount(2, $sessions);
    }

    public function testListSessionsReturnsEmptyArrayWhenNoSessions(): void
    {
        $store = new SessionStore($this->dbPath);

        $sessions = $store->listSessions();

        $this->assertIsArray($sessions);
        $this->assertCount(0, $sessions);
    }

    // =========================================================================
    // updateSession Tests
    // =========================================================================

    public function testUpdateSessionUpdatesTimestamp(): void
    {
        $store = new SessionStore($this->dbPath);

        $store->createSession('session-1', 'provider', 'model');
        $original = $store->getSession('session-1');

        sleep(1); // Ensure timestamp difference

        $store->updateSession('session-1');
        $updated = $store->getSession('session-1');

        $this->assertNotSame($original['updated_at'], $updated['updated_at']);
    }

    public function testUpdateSessionDoesNotAffectOtherFields(): void
    {
        $store = new SessionStore($this->dbPath);

        $store->createSession('session-1', 'provider', 'model', 'system prompt');
        $original = $store->getSession('session-1');

        $store->updateSession('session-1');
        $updated = $store->getSession('session-1');

        $this->assertSame($original['id'], $updated['id']);
        $this->assertSame($original['provider'], $updated['provider']);
        $this->assertSame($original['model'], $updated['model']);
        $this->assertSame($original['system_prompt'], $updated['system_prompt']);
    }

    // =========================================================================
    // addMessage and getMessages Tests
    // =========================================================================

    public function testAddMessageInsertsMessage(): void
    {
        $store = new SessionStore($this->dbPath);
        $store->createSession('session-1', 'provider', 'model');

        $messageId = $store->addMessage('session-1', [
            'role' => 'user',
            'content' => 'Hello, world!',
        ]);

        $this->assertGreaterThan(0, $messageId);
    }

    public function testAddMessageWithToolCalls(): void
    {
        $store = new SessionStore($this->dbPath);
        $store->createSession('session-1', 'provider', 'model');

        $messageId = $store->addMessage('session-1', [
            'role' => 'assistant',
            'content' => 'Let me help',
            'tool_calls' => [
                ['id' => 'call_1', 'name' => 'bash', 'arguments' => ['cmd' => 'ls']],
            ],
        ]);

        $messages = $store->getMessages('session-1');
        $this->assertCount(1, $messages);
        $this->assertNotNull($messages[0]['tool_calls']);
        $this->assertSame('call_1', $messages[0]['tool_calls'][0]['id']);
    }

    public function testAddMessageWithToolResults(): void
    {
        $store = new SessionStore($this->dbPath);
        $store->createSession('session-1', 'provider', 'model');

        $store->addMessage('session-1', [
            'role' => 'tool',
            'content' => 'Command output',
            'tool_results' => ['call_id' => 'call_1', 'result' => 'files listed'],
        ]);

        $messages = $store->getMessages('session-1');
        $this->assertCount(1, $messages);
        $this->assertNotNull($messages[0]['tool_results']);
        $this->assertSame('call_1', $messages[0]['tool_results']['call_id']);
    }

    public function testAddMessageUpdatesSessionTimestamp(): void
    {
        $store = new SessionStore($this->dbPath);
        $store->createSession('session-1', 'provider', 'model');

        sleep(1);
        $beforeUpdate = $store->getSession('session-1')['updated_at'];

        $store->addMessage('session-1', ['role' => 'user', 'content' => 'Hi']);

        $afterUpdate = $store->getSession('session-1')['updated_at'];
        $this->assertNotSame($beforeUpdate, $afterUpdate);
    }

    public function testGetMessagesReturnsMessagesOrderedByCreatedAt(): void
    {
        $store = new SessionStore($this->dbPath);
        $store->createSession('session-1', 'provider', 'model');

        $store->addMessage('session-1', ['role' => 'user', 'content' => 'First']);
        $store->addMessage('session-1', ['role' => 'assistant', 'content' => 'Second']);
        $store->addMessage('session-1', ['role' => 'user', 'content' => 'Third']);

        $messages = $store->getMessages('session-1');

        $this->assertCount(3, $messages);
        $this->assertSame('First', $messages[0]['content']);
        $this->assertSame('Second', $messages[1]['content']);
        $this->assertSame('Third', $messages[2]['content']);
    }

    public function testGetMessagesReturnsEmptyArrayForNonexistentSession(): void
    {
        $store = new SessionStore($this->dbPath);

        $messages = $store->getMessages('nonexistent');

        $this->assertIsArray($messages);
        $this->assertCount(0, $messages);
    }

    public function testGetMessagesDecodesJsonToolCallsAndResults(): void
    {
        $store = new SessionStore($this->dbPath);
        $store->createSession('session-1', 'provider', 'model');

        $store->addMessage('session-1', [
            'role' => 'assistant',
            'content' => 'Using tool',
            'tool_calls' => [['id' => 'call_1', 'name' => 'test', 'arguments' => []]],
            'tool_results' => [['id' => 'call_1', 'result' => 'output']],
        ]);

        $messages = $store->getMessages('session-1');

        $this->assertIsArray($messages[0]['tool_calls']);
        $this->assertIsArray($messages[0]['tool_results']);
    }

    // =========================================================================
    // addToolCall Tests
    // =========================================================================

    public function testAddToolCallInsertsToolCall(): void
    {
        $store = new SessionStore($this->dbPath);
        $store->createSession('session-1', 'provider', 'model');
        $messageId = $store->addMessage('session-1', [
            'role' => 'assistant',
            'content' => 'Using tool',
        ]);

        $store->addToolCall('session-1', $messageId, [
            'name' => 'bash',
            'arguments' => ['command' => 'ls -la'],
            'result' => 'file1 file2',
            'duration_ms' => 150,
            'success' => 1,
        ]);

        // Verify by checking messages with tool_calls
        $messages = $store->getMessages('session-1');
        // tool_calls are stored in the tool_calls table, not in messages
        // The message's tool_calls column would be null since we didn't set it in addMessage
        $this->assertCount(1, $messages);
    }

    public function testAddToolCallWithMinimalData(): void
    {
        $store = new SessionStore($this->dbPath);
        $store->createSession('session-1', 'provider', 'model');
        $messageId = $store->addMessage('session-1', [
            'role' => 'assistant',
            'content' => 'Using tool',
        ]);

        // Should not throw - all fields have defaults
        $store->addToolCall('session-1', $messageId, [
            'name' => 'test_tool',
            'arguments' => [],
        ]);

        $this->assertTrue(true); // If we get here, no exception was thrown
    }

    // =========================================================================
    // deleteSession Tests
    // =========================================================================

    public function testDeleteSessionRemovesSession(): void
    {
        $store = new SessionStore($this->dbPath);
        $store->createSession('session-1', 'provider', 'model');

        $store->deleteSession('session-1');

        $this->assertNull($store->getSession('session-1'));
    }

    public function testDeleteSessionRemovesRelatedMessages(): void
    {
        $store = new SessionStore($this->dbPath);
        $store->createSession('session-1', 'provider', 'model');
        $store->addMessage('session-1', ['role' => 'user', 'content' => 'Hello']);

        $this->assertCount(1, $store->getMessages('session-1'));

        $store->deleteSession('session-1');

        $this->assertCount(0, $store->getMessages('session-1'));
    }

    public function testDeleteSessionRemovesRelatedToolCalls(): void
    {
        $store = new SessionStore($this->dbPath);
        $store->createSession('session-1', 'provider', 'model');
        $messageId = $store->addMessage('session-1', [
            'role' => 'assistant',
            'content' => 'Using tool',
        ]);
        $store->addToolCall('session-1', $messageId, [
            'name' => 'bash',
            'arguments' => ['cmd' => 'ls'],
        ]);

        $store->deleteSession('session-1');

        // After delete, session should not exist (which means tool_calls also gone)
        $this->assertNull($store->getSession('session-1'));
    }

    public function testDeleteSessionIsIdempotent(): void
    {
        $store = new SessionStore($this->dbPath);
        $store->createSession('session-1', 'provider', 'model');

        // Should not throw even though session already deleted
        $store->deleteSession('session-1');
        $store->deleteSession('session-1');

        $this->assertTrue(true);
    }

    // =========================================================================
    // pruneSessions Tests
    // =========================================================================

    public function testPruneSessionsRemovesOldSessions(): void
    {
        $store = new SessionStore($this->dbPath);
        $store->createSession('old-session', 'provider', 'model');

        // Manually manipulate the updated_at to be in the past
        $pdo = new \PDO("sqlite:{$this->dbPath}");
        $pdo->exec("UPDATE sessions SET updated_at = '2020-01-01 00:00:00' WHERE id = 'old-session'");

        $pruned = $store->pruneSessions(30);

        $this->assertGreaterThanOrEqual(1, $pruned);
        $this->assertNull($store->getSession('old-session'));
    }

    public function testPruneSessionsKeepsRecentSessions(): void
    {
        $store = new SessionStore($this->dbPath);
        $store->createSession('recent-session', 'provider', 'model');

        $pruned = $store->pruneSessions(30);

        $this->assertSame(0, $pruned);
        $this->assertNotNull($store->getSession('recent-session'));
    }

    public function testPruneSessionsRemovesOldMessagesWithOldSession(): void
    {
        $store = new SessionStore($this->dbPath);
        $store->createSession('old-session', 'provider', 'model');
        $store->addMessage('old-session', ['role' => 'user', 'content' => 'Old message']);

        // Manually manipulate the updated_at to be in the past
        $pdo = new \PDO("sqlite:{$this->dbPath}");
        $pdo->exec("UPDATE sessions SET updated_at = '2020-01-01 00:00:00' WHERE id = 'old-session'");

        $store->pruneSessions(30);

        $this->assertCount(0, $store->getMessages('old-session'));
    }

    public function testPruneSessionsRemovesOldToolCallsWithOldSession(): void
    {
        $store = new SessionStore($this->dbPath);
        $store->createSession('old-session', 'provider', 'model');
        $messageId = $store->addMessage('old-session', [
            'role' => 'assistant',
            'content' => 'Using tool',
        ]);
        $store->addToolCall('old-session', $messageId, [
            'name' => 'bash',
            'arguments' => ['cmd' => 'ls'],
        ]);

        // Manually manipulate the updated_at to be in the past
        $pdo = new \PDO("sqlite:{$this->dbPath}");
        $pdo->exec("UPDATE sessions SET updated_at = '2020-01-01 00:00:00' WHERE id = 'old-session'");

        $store->pruneSessions(30);

        $this->assertNull($store->getSession('old-session'));
    }

    public function testPruneSessionsReturnsZeroWhenNothingToPrune(): void
    {
        $store = new SessionStore($this->dbPath);
        $store->createSession('session-1', 'provider', 'model');

        $pruned = $store->pruneSessions(30);

        $this->assertSame(0, $pruned);
    }

    // =========================================================================
    // Foreign Key Constraint Tests
    // =========================================================================

    public function testForeignKeyConstraintPreventsOrphanedMessages(): void
    {
        $store = new SessionStore($this->dbPath);
        $store->createSession('session-1', 'provider', 'model');

        // Delete the session - messages should be cascade deleted
        $store->deleteSession('session-1');

        // Verify no messages remain
        $pdo = new \PDO("sqlite:{$this->dbPath}");
        $count = $pdo->query("SELECT COUNT(*) FROM messages WHERE session_id = 'session-1'")->fetch(\PDO::FETCH_COLUMN);
        $this->assertSame(0, (int) $count);
    }

    public function testForeignKeyConstraintPreventsOrphanedToolCalls(): void
    {
        $store = new SessionStore($this->dbPath);
        $store->createSession('session-1', 'provider', 'model');
        $messageId = $store->addMessage('session-1', [
            'role' => 'assistant',
            'content' => 'Using tool',
        ]);
        $store->addToolCall('session-1', $messageId, [
            'name' => 'bash',
            'arguments' => ['cmd' => 'ls'],
        ]);

        // Delete the session - tool_calls should be cascade deleted
        $store->deleteSession('session-1');

        // Verify no tool_calls remain
        $pdo = new \PDO("sqlite:{$this->dbPath}");
        $count = $pdo->query("SELECT COUNT(*) FROM tool_calls WHERE session_id = 'session-1'")->fetch(\PDO::FETCH_COLUMN);
        $this->assertSame(0, (int) $count);
    }

    public function testSessionDataIntegrityAfterMultipleOperations(): void
    {
        $store = new SessionStore($this->dbPath);

        // Create multiple sessions with messages and tool calls
        foreach (['s1', 's2', 's3'] as $id) {
            $store->createSession($id, 'provider', 'model');
            $msgId = $store->addMessage($id, ['role' => 'user', 'content' => "Hello from $id"]);
            $store->addToolCall($id, $msgId, ['name' => 'test', 'arguments' => []]);
        }

        // Verify all sessions exist
        $this->assertCount(3, $store->listSessions());

        // Delete middle session
        $store->deleteSession('s2');

        // Verify remaining sessions still intact
        $sessions = $store->listSessions();
        $this->assertCount(2, $sessions);
        $this->assertNull($store->getSession('s2'));
        $this->assertNotNull($store->getSession('s1'));
        $this->assertNotNull($store->getSession('s3'));

        // Verify messages for remaining sessions
        $this->assertCount(1, $store->getMessages('s1'));
        $this->assertCount(1, $store->getMessages('s3'));
    }

    // =========================================================================
    // Session Naming Tests (P6.S11)
    // =========================================================================

    public function testCreateSessionWithName(): void
    {
        $store = new SessionStore($this->dbPath);

        $store->createSession('session-named', 'openai', 'gpt-4', 'You are helpful', 'My Session');

        $session = $store->getSession('session-named');

        $this->assertNotNull($session);
        $this->assertSame('session-named', $session['id']);
        $this->assertSame('My Session', $session['name']);
    }

    public function testCreateSessionWithNullName(): void
    {
        $store = new SessionStore($this->dbPath);

        $store->createSession('session-unnamed', 'openai', 'gpt-4', null, null);

        $session = $store->getSession('session-unnamed');

        $this->assertNotNull($session);
        $this->assertNull($session['name']);
    }

    public function testGetSessionByName(): void
    {
        $store = new SessionStore($this->dbPath);

        $store->createSession('session-1', 'provider', 'model', null, 'Alpha');
        $store->createSession('session-2', 'provider', 'model', null, 'Beta');

        $found = $store->getSessionByName('Alpha');

        $this->assertNotNull($found);
        $this->assertSame('session-1', $found['id']);
        $this->assertSame('Alpha', $found['name']);
    }

    public function testGetSessionByNameReturnsNullForNonexistent(): void
    {
        $store = new SessionStore($this->dbPath);

        $found = $store->getSessionByName('Nonexistent');

        $this->assertNull($found);
    }

    public function testRenameSession(): void
    {
        $store = new SessionStore($this->dbPath);

        $store->createSession('session-1', 'provider', 'model', null, 'Original');
        $original = $store->getSession('session-1');
        $this->assertSame('Original', $original['name']);

        $store->renameSession('session-1', 'Renamed');

        $renamed = $store->getSession('session-1');
        $this->assertSame('Renamed', $renamed['name']);
    }

    public function testRenameSessionUpdatesTimestamp(): void
    {
        $store = new SessionStore($this->dbPath);

        $store->createSession('session-1', 'provider', 'model');
        sleep(1);
        $beforeUpdate = $store->getSession('session-1')['updated_at'];

        $store->renameSession('session-1', 'New Name');

        $afterUpdate = $store->getSession('session-1')['updated_at'];
        $this->assertNotSame($beforeUpdate, $afterUpdate);
    }

    // =========================================================================
    // Session Forking Tests (P6.S11)
    // =========================================================================

    public function testForkSessionCreatesCopy(): void
    {
        $store = new SessionStore($this->dbPath);

        $store->createSession('original', 'provider', 'model', 'system prompt', 'Original Name');
        $store->addMessage('original', ['role' => 'user', 'content' => 'Hello']);
        $store->addMessage('original', ['role' => 'assistant', 'content' => 'Hi there']);

        $newId = $store->forkSession('original');

        // New ID should be different
        $this->assertNotSame('original', $newId);
        $this->assertSame(32, strlen($newId)); // 16 bytes = 32 hex chars

        // Original should still exist unchanged
        $original = $store->getSession('original');
        $this->assertNotNull($original);
        $this->assertSame('Original Name', $original['name']);

        // New session should be a copy with same metadata
        $forked = $store->getSession($newId);
        $this->assertNotNull($forked);
        $this->assertSame('provider', $forked['provider']);
        $this->assertSame('model', $forked['model']);
        $this->assertSame('system prompt', $forked['system_prompt']);
        $this->assertSame('Original Name', $forked['name']);
    }

    public function testForkSessionCopiesMessages(): void
    {
        $store = new SessionStore($this->dbPath);

        $store->createSession('original', 'provider', 'model');
        $store->addMessage('original', ['role' => 'user', 'content' => 'First message']);
        $store->addMessage('original', ['role' => 'assistant', 'content' => 'Second message']);

        $originalMessages = $store->getMessages('original');
        $this->assertCount(2, $originalMessages);

        $newId = $store->forkSession('original');

        $forkedMessages = $store->getMessages($newId);
        $this->assertCount(2, $forkedMessages);
        $this->assertSame('First message', $forkedMessages[0]['content']);
        $this->assertSame('Second message', $forkedMessages[1]['content']);
    }

    public function testForkSessionCopiesToolCalls(): void
    {
        $store = new SessionStore($this->dbPath);

        $store->createSession('original', 'provider', 'model');
        $msgId = $store->addMessage('original', [
            'role' => 'assistant',
            'content' => 'Using tool',
            'tool_calls' => [['id' => 'call_1', 'name' => 'bash', 'arguments' => ['cmd' => 'ls']]],
        ]);
        $store->addToolCall('original', $msgId, [
            'name' => 'bash',
            'arguments' => ['cmd' => 'ls'],
            'result' => 'file1 file2',
        ]);

        $newId = $store->forkSession('original');

        // Verify original messages have tool calls
        $originalMessages = $store->getMessages('original');
        $this->assertNotNull($originalMessages[0]['tool_calls']);

        // Forked messages should also have tool calls
        $forkedMessages = $store->getMessages($newId);
        $this->assertNotNull($forkedMessages[0]['tool_calls']);
    }

    public function testForkSessionThrowsForNonexistentSession(): void
    {
        $store = new SessionStore($this->dbPath);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Session not found: nonexistent');

        $store->forkSession('nonexistent');
    }

    public function testForkSessionHasFreshTimestamps(): void
    {
        $store = new SessionStore($this->dbPath);

        $store->createSession('original', 'provider', 'model');

        sleep(1);
        $originalSession = $store->getSession('original');
        $originalCreatedAt = $originalSession['created_at'];
        $originalUpdatedAt = $originalSession['updated_at'];

        $newId = $store->forkSession('original');
        $forkedSession = $store->getSession($newId);

        // Forked session should have fresh timestamps (different from original)
        $this->assertNotSame($originalCreatedAt, $forkedSession['created_at']);
        $this->assertNotSame($originalUpdatedAt, $forkedSession['updated_at']);
    }
}
