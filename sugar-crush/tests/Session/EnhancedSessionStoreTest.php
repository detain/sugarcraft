<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Session;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Session\EnhancedSessionStore;
use SugarCraft\Crush\Session\SessionMeta;

/**
 * @see EnhancedSessionStore
 */
final class EnhancedSessionStoreTest extends TestCase
{
    private string $tempDir;
    private string $dbPath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempDir = sys_get_temp_dir() . '/enhanced_session_store_test_' . uniqid('', true);
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

    public function testConstructorCreatesEnhancedSchema(): void
    {
        $store = new EnhancedSessionStore($this->dbPath);

        $pdo = new \PDO("sqlite:{$this->dbPath}");
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

        $tables = $pdo->query("SELECT name FROM sqlite_master WHERE type='table'")->fetchAll(\PDO::FETCH_COLUMN);
        $this->assertContains('sessions', $tables);
        $this->assertContains('messages', $tables);
        $this->assertContains('tool_calls', $tables);
        $this->assertContains('session_meta', $tables);
    }

    public function testConstructorCreatesIndexOnLastActivity(): void
    {
        $store = new EnhancedSessionStore($this->dbPath);

        $pdo = new \PDO("sqlite:{$this->dbPath}");
        $indexes = $pdo->query("SELECT name FROM sqlite_master WHERE type='index' AND name LIKE '%session_meta%'")->fetchAll(\PDO::FETCH_COLUMN);
        $this->assertContains('idx_session_meta_last_activity', $indexes);
    }

    public function testConstructorSetsOwnerOnlyPermissions(): void
    {
        $store = new EnhancedSessionStore($this->dbPath);

        $this->assertFileExists($this->dbPath);
        clearstatcache(true, $this->dbPath);
        $this->assertSame(0600, fileperms($this->dbPath) & 0777);
    }

    // =========================================================================
    // createSession and getSessionMeta Tests
    // =========================================================================

    public function testGetSessionMetaReturnsNullForNonexistent(): void
    {
        $store = new EnhancedSessionStore($this->dbPath);
        $store->createSession('session-nonexistent', 'provider', 'model');

        $meta = $store->getSessionMeta('nonexistent');

        $this->assertNull($meta);
    }

    public function testGetSessionMetaReturnsNullWhenSessionHasNoMeta(): void
    {
        $store = new EnhancedSessionStore($this->dbPath);
        $store->createSession('session-no-meta', 'provider', 'model');

        $meta = $store->getSessionMeta('session-no-meta');

        $this->assertNull($meta);
    }

    public function testSaveAndGetSessionMetaRoundTrip(): void
    {
        $store = new EnhancedSessionStore($this->dbPath);
        $store->createSession('session-round-trip', 'provider', 'model');

        $lastActivity = new \DateTimeImmutable('2026-08-06 15:00:00');
        $tasks = ['task-a', 'task-b'];
        $modifiedFiles = ['src/Chat.php'];
        $agentStates = ['teammate-1' => ['status' => 'running', 'turns' => 7]];

        $meta = new SessionMeta(
            sessionId: 'session-round-trip',
            summary: 'Working on implementation',
            tasks: $tasks,
            modifiedFiles: $modifiedFiles,
            agentStates: $agentStates,
            lastActivity: $lastActivity,
        );

        $store->saveSessionMeta($meta);

        $saved = $store->getSessionMeta('session-round-trip');

        $this->assertNotNull($saved);
        $this->assertSame('session-round-trip', $saved->sessionId);
        $this->assertSame('Working on implementation', $saved->summary);
        $this->assertSame($tasks, $saved->tasks);
        $this->assertSame($modifiedFiles, $saved->modifiedFiles);
        $this->assertSame($agentStates, $saved->agentStates);
        $this->assertEquals($lastActivity, $saved->lastActivity);
    }

    public function testSaveSessionMetaUpdatesExisting(): void
    {
        $store = new EnhancedSessionStore($this->dbPath);
        $store->createSession('session-update', 'provider', 'model');

        $originalMeta = SessionMeta::new(
            sessionId: 'session-update',
            summary: 'Original summary',
            tasks: ['original-task'],
        );
        $store->saveSessionMeta($originalMeta);

        $updatedMeta = $originalMeta->withSummary('Updated summary')
            ->withTasks(['updated-task', 'new-task']);
        $store->saveSessionMeta($updatedMeta);

        $saved = $store->getSessionMeta('session-update');

        $this->assertNotNull($saved);
        $this->assertSame('Updated summary', $saved->summary);
        $this->assertSame(['updated-task', 'new-task'], $saved->tasks);
    }

    // =========================================================================
    // listSessionsWithMeta Tests
    // =========================================================================

    public function testListSessionsWithMetaReturnsEmptyWhenNoSessions(): void
    {
        $store = new EnhancedSessionStore($this->dbPath);

        $sessions = $store->listSessionsWithMeta();

        $this->assertIsArray($sessions);
        $this->assertCount(0, $sessions);
    }

    public function testListSessionsWithMetaReturnsAllSessions(): void
    {
        $store = new EnhancedSessionStore($this->dbPath);

        $store->createSession('session-a', 'provider', 'model');
        $store->createSession('session-b', 'provider', 'model');
        $store->createSession('session-c', 'provider', 'model');

        $sessions = $store->listSessionsWithMeta();

        $this->assertCount(3, $sessions);
    }

    public function testListSessionsWithMetaIncludesSummaryWhenAvailable(): void
    {
        $store = new EnhancedSessionStore($this->dbPath);
        $store->createSession('session-with-meta', 'provider', 'model');

        $meta = SessionMeta::new(
            sessionId: 'session-with-meta',
            summary: 'Test summary',
            tasks: ['task-1'],
        );
        $store->saveSessionMeta($meta);

        $sessions = $store->listSessionsWithMeta();

        $this->assertCount(1, $sessions);
        $this->assertSame('Test summary', $sessions[0]['summary']);
        $this->assertSame(['task-1'], $sessions[0]['tasks']);
    }

    public function testListSessionsWithMetaReturnsEmptyArraysWhenNoMeta(): void
    {
        $store = new EnhancedSessionStore($this->dbPath);
        $store->createSession('session-no-meta', 'provider', 'model');

        $sessions = $store->listSessionsWithMeta();

        $this->assertCount(1, $sessions);
        $this->assertNull($sessions[0]['summary']);
        $this->assertSame([], $sessions[0]['tasks']);
        $this->assertSame([], $sessions[0]['modified_files']);
        $this->assertSame([], $sessions[0]['agent_states']);
    }

    public function testListSessionsWithMetaRespectsLimit(): void
    {
        $store = new EnhancedSessionStore($this->dbPath);

        for ($i = 1; $i <= 5; $i++) {
            $store->createSession("session-$i", 'provider', 'model');
            // Small delay to ensure different timestamps
            usleep(1000);
        }

        $sessions = $store->listSessionsWithMeta(3);

        $this->assertCount(3, $sessions);
    }

    public function testListSessionsWithMetaOrderedByLastActivity(): void
    {
        $store = new EnhancedSessionStore($this->dbPath);

        // Create sessions without meta (will use updated_at)
        $store->createSession('session-old', 'provider', 'model');
        usleep(1000);
        $store->createSession('session-new', 'provider', 'model');

        // Add meta with explicit last_activity ordering
        $oldMeta = SessionMeta::new(
            sessionId: 'session-old',
            summary: 'Old session',
            lastActivity: new \DateTimeImmutable('2026-08-01 10:00:00'),
        );
        $store->saveSessionMeta($oldMeta);

        $newMeta = SessionMeta::new(
            sessionId: 'session-new',
            summary: 'New session',
            lastActivity: new \DateTimeImmutable('2026-08-06 10:00:00'),
        );
        $store->saveSessionMeta($newMeta);

        $sessions = $store->listSessionsWithMeta();

        // session-new should be first due to more recent last_activity
        $this->assertSame('session-new', $sessions[0]['id']);
        $this->assertSame('session-old', $sessions[1]['id']);
    }

    // =========================================================================
    // Foreign Key Constraint Tests
    // =========================================================================

    public function testForeignKeyCascadeDeletesSessionMeta(): void
    {
        $store = new EnhancedSessionStore($this->dbPath);
        $store->createSession('session-cascade', 'provider', 'model');

        $meta = SessionMeta::new(sessionId: 'session-cascade', summary: 'To be deleted');
        $store->saveSessionMeta($meta);

        $this->assertNotNull($store->getSessionMeta('session-cascade'));

        $store->deleteSession('session-cascade');

        $this->assertNull($store->getSessionMeta('session-cascade'));
    }

    // =========================================================================
    // Inherited SessionStore Functionality Tests
    // =========================================================================

    public function testCreateAndGetSessionStillWorks(): void
    {
        $store = new EnhancedSessionStore($this->dbPath);
        $store->createSession('session-basic', 'openai', 'gpt-4', 'You are helpful');

        $session = $store->getSession('session-basic');

        $this->assertNotNull($session);
        $this->assertSame('session-basic', $session['id']);
        $this->assertSame('openai', $session['provider']);
        $this->assertSame('gpt-4', $session['model']);
        $this->assertSame('You are helpful', $session['system_prompt']);
    }

    public function testAddMessageUpdatesSessionTimestamp(): void
    {
        $store = new EnhancedSessionStore($this->dbPath);
        $store->createSession('session-msg', 'provider', 'model');

        sleep(1);
        $before = $store->getSession('session-msg')['updated_at'];

        $store->addMessage('session-msg', ['role' => 'user', 'content' => 'Hello']);

        $after = $store->getSession('session-msg')['updated_at'];
        $this->assertNotSame($before, $after);
    }

    public function testListSessionsStillWorks(): void
    {
        $store = new EnhancedSessionStore($this->dbPath);
        $store->createSession('list-1', 'p', 'm');
        $store->createSession('list-2', 'p', 'm');

        $sessions = $store->listSessions();

        $this->assertCount(2, $sessions);
    }
}
