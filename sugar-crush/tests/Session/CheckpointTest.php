<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Session;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Session\EnhancedSessionStore;

/**
 * @see EnhancedSessionStore checkpoint methods
 */
final class CheckpointTest extends TestCase
{
    private string $tempDir;
    private string $dbPath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempDir = sys_get_temp_dir() . '/checkpoint_test_' . uniqid('', true);
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
    // saveCheckpoint Tests
    // =========================================================================

    public function testSaveCheckpointReturnsIndex(): void
    {
        $store = new EnhancedSessionStore($this->dbPath);
        $store->createSession('session-1', 'provider', 'model');

        $chatState = [
            'messages' => [['role' => 'user', 'content' => 'Hello']],
            'inputBuf' => '',
            'agentContext' => ['currentSessionId' => 'session-1'],
        ];

        $index = $store->saveCheckpoint('session-1', $chatState);

        $this->assertSame(0, $index);
    }

    public function testSaveCheckpointIncrementsIndex(): void
    {
        $store = new EnhancedSessionStore($this->dbPath);
        $store->createSession('session-2', 'provider', 'model');

        $chatState = ['messages' => [], 'inputBuf' => '', 'agentContext' => []];

        $idx1 = $store->saveCheckpoint('session-2', $chatState);
        $idx2 = $store->saveCheckpoint('session-2', $chatState);
        $idx3 = $store->saveCheckpoint('session-2', $chatState);

        $this->assertSame(0, $idx1);
        $this->assertSame(1, $idx2);
        $this->assertSame(2, $idx3);
    }

    // =========================================================================
    // getCheckpoint Tests
    // =========================================================================

    public function testGetCheckpointReturnsState(): void
    {
        $store = new EnhancedSessionStore($this->dbPath);
        $store->createSession('session-3', 'provider', 'model');

        $originalState = [
            'messages' => [['role' => 'user', 'content' => 'Test']],
            'inputBuf' => 'test input',
            'agentContext' => ['currentSessionId' => 'session-3'],
        ];

        $index = $store->saveCheckpoint('session-3', $originalState);
        $retrieved = $store->getCheckpoint('session-3', $index);

        $this->assertNotNull($retrieved);
        $this->assertSame($originalState, $retrieved);
    }

    public function testGetCheckpointReturnsNullForNonexistent(): void
    {
        $store = new EnhancedSessionStore($this->dbPath);
        $store->createSession('session-4', 'provider', 'model');

        $result = $store->getCheckpoint('session-4', 999);

        $this->assertNull($result);
    }

    public function testGetCheckpointReturnsNullForNonexistentSession(): void
    {
        $store = new EnhancedSessionStore($this->dbPath);

        $result = $store->getCheckpoint('nonexistent-session', 0);

        $this->assertNull($result);
    }

    // =========================================================================
    // listCheckpoints Tests
    // =========================================================================

    public function testListCheckpointsReturnsEmptyWhenNone(): void
    {
        $store = new EnhancedSessionStore($this->dbPath);
        $store->createSession('session-5', 'provider', 'model');

        $checkpoints = $store->listCheckpoints('session-5');

        $this->assertIsArray($checkpoints);
        $this->assertCount(0, $checkpoints);
    }

    public function testListCheckpointsReturnsAllCheckpoints(): void
    {
        $store = new EnhancedSessionStore($this->dbPath);
        $store->createSession('session-6', 'provider', 'model');

        $state = ['messages' => [], 'inputBuf' => '', 'agentContext' => []];
        $store->saveCheckpoint('session-6', $state);
        $store->saveCheckpoint('session-6', $state);
        $store->saveCheckpoint('session-6', $state);

        $checkpoints = $store->listCheckpoints('session-6');

        $this->assertCount(3, $checkpoints);
        // Should be ordered by index DESC (most recent first)
        $this->assertSame(2, $checkpoints[0]['index']);
        $this->assertSame(1, $checkpoints[1]['index']);
        $this->assertSame(0, $checkpoints[2]['index']);
    }

    public function testListCheckpointsRespectsLimit(): void
    {
        $store = new EnhancedSessionStore($this->dbPath);
        $store->createSession('session-7', 'provider', 'model');

        $state = ['messages' => [], 'inputBuf' => '', 'agentContext' => []];
        for ($i = 0; $i < 10; $i++) {
            $store->saveCheckpoint('session-7', $state);
        }

        $checkpoints = $store->listCheckpoints('session-7', 5);

        $this->assertCount(5, $checkpoints);
        $this->assertSame(9, $checkpoints[0]['index']);
        $this->assertSame(5, $checkpoints[4]['index']);
    }

    public function testListCheckpointsDefaultLimit100(): void
    {
        $store = new EnhancedSessionStore($this->dbPath);
        $store->createSession('session-8', 'provider', 'model');

        $state = ['messages' => [], 'inputBuf' => '', 'agentContext' => []];
        for ($i = 0; $i < 150; $i++) {
            $store->saveCheckpoint('session-8', $state);
        }

        // Should only return 100 (the enforced limit)
        $checkpoints = $store->listCheckpoints('session-8');

        $this->assertCount(100, $checkpoints);
        $this->assertSame(149, $checkpoints[0]['index']);
        $this->assertSame(50, $checkpoints[99]['index']);
    }

    // =========================================================================
    // restoreCheckpoint Tests
    // =========================================================================

    public function testRestoreCheckpointReturnsState(): void
    {
        $store = new EnhancedSessionStore($this->dbPath);
        $store->createSession('session-9', 'provider', 'model');

        $originalState = [
            'messages' => [['role' => 'user', 'content' => 'Restore me']],
            'inputBuf' => 'restored input',
            'agentContext' => ['currentSessionId' => 'session-9'],
        ];

        $index = $store->saveCheckpoint('session-9', $originalState);

        // Add a few more checkpoints after the one we want to restore
        $laterState = ['messages' => [['role' => 'user', 'content' => 'Later']]];
        $store->saveCheckpoint('session-9', $laterState);
        $store->saveCheckpoint('session-9', $laterState);

        $restored = $store->restoreCheckpoint('session-9', $index);

        $this->assertNotNull($restored);
        $this->assertSame($originalState, $restored);
    }

    public function testRestoreCheckpointRemovesNewerCheckpoints(): void
    {
        $store = new EnhancedSessionStore($this->dbPath);
        $store->createSession('session-10', 'provider', 'model');

        $state0 = ['messages' => [['role' => 'user', 'content' => 'Checkpoint 0']]];
        $state1 = ['messages' => [['role' => 'user', 'content' => 'Checkpoint 1']]];
        $state2 = ['messages' => [['role' => 'user', 'content' => 'Checkpoint 2']]];

        $store->saveCheckpoint('session-10', $state0);
        $store->saveCheckpoint('session-10', $state1);
        $index2 = $store->saveCheckpoint('session-10', $state2);

        // Restore to index 1 - this should remove checkpoints 1 and 2 (they're now invalid)
        $store->restoreCheckpoint('session-10', 1);

        $checkpoints = $store->listCheckpoints('session-10');
        // Only checkpoint 0 remains since we restored to 1 (indices >= 1 are deleted)
        $this->assertCount(1, $checkpoints);
        $this->assertSame(0, $checkpoints[0]['index']);

        // Verify checkpoint 1 and 2 data is gone
        $this->assertNull($store->getCheckpoint('session-10', 1));
        $this->assertNull($store->getCheckpoint('session-10', 2));
    }

    public function testRestoreCheckpointReturnsNullForNotFound(): void
    {
        $store = new EnhancedSessionStore($this->dbPath);
        $store->createSession('session-11', 'provider', 'model');

        $result = $store->restoreCheckpoint('session-11', 999);

        $this->assertNull($result);
    }

    public function testRestoreCheckpointReturnsNullForNonexistentSession(): void
    {
        $store = new EnhancedSessionStore($this->dbPath);

        $result = $store->restoreCheckpoint('nonexistent-session', 0);

        $this->assertNull($result);
    }

    // =========================================================================
    // 100 Checkpoint Limit Tests
    // =========================================================================

    public function testCheckpointsEnforce100Limit(): void
    {
        $store = new EnhancedSessionStore($this->dbPath);
        $store->createSession('session-12', 'provider', 'model');

        $state = ['messages' => [['role' => 'user', 'content' => 'x']], 'inputBuf' => '', 'agentContext' => []];

        // Save 105 checkpoints
        for ($i = 0; $i < 105; $i++) {
            $store->saveCheckpoint('session-12', $state);
        }

        $checkpoints = $store->listCheckpoints('session-12');

        $this->assertCount(100, $checkpoints);
        // Oldest checkpoints (0-4) should have been pruned
        $this->assertSame(104, $checkpoints[0]['index']);
        $this->assertSame(5, $checkpoints[99]['index']);
    }
}
