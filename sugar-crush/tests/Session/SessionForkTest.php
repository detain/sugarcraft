<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Session;

use PDO;
use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Message;
use SugarCraft\Crush\Session\EnhancedSessionStore;
use SugarCraft\Crush\Session\SessionMeta;
use SugarCraft\Crush\Session\SessionStore;

/**
 * `/branch` and `/fork` must hand the new session the conversation it was
 * forked from (audit SES-2), resolve names deterministically, and not make the
 * first save under the new id re-intern the whole history (audit 15b-21).
 *
 * @see EnhancedSessionStore::forkSession()
 * @see SessionStore::forkSession()
 */
final class SessionForkTest extends TestCase
{
    private string $tempDir;
    private string $dbPath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempDir = sys_get_temp_dir() . '/crush_fork_' . bin2hex(random_bytes(6));
        mkdir($this->tempDir, 0700, true);
        $this->dbPath = $this->tempDir . '/test.db';
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        foreach (glob($this->tempDir . '/*') ?: [] as $file) {
            @unlink($file);
        }
        if (is_dir($this->tempDir)) {
            @rmdir($this->tempDir);
        }
    }

    public function testForkCarriesTheParentsTranscript(): void
    {
        $store = new EnhancedSessionStore($this->dbPath);
        $store->createSession('parent', 'p', 'm');
        $store->saveTranscript('parent', [Message::user('hello'), Message::assistant('hi')]);

        $fork = $store->forkSession('parent');

        $this->assertNotNull($store->loadTranscript($fork), 'the fork used to start with no transcript at all');
        $this->assertSame($store->loadTranscript('parent'), $store->loadTranscript($fork));
    }

    public function testForkCarriesCheckpointsSoRewindOnABranchWorks(): void
    {
        $store = new EnhancedSessionStore($this->dbPath);
        $store->createSession('parent', 'p', 'm');
        $history = [];
        for ($turn = 0; $turn < 3; $turn++) {
            $history[] = Message::user("turn {$turn}");
            $store->saveCheckpoint('parent', ['messages' => $history, 'inputBuf' => "draft {$turn}"]);
        }

        $fork = $store->forkSession('parent');

        $parent = $store->listCheckpoints('parent');
        $forked = $store->listCheckpoints($fork);
        $this->assertCount(3, $forked);
        $this->assertSame(array_column($parent, 'index'), array_column($forked, 'index'));
        $this->assertSame(array_column($parent, 'created_at'), array_column($forked, 'created_at'));
        $this->assertSame(array_column($parent, 'state_data'), array_column($forked, 'state_data'));

        $restored = $store->restoreCheckpoint($fork, $forked[1]['index']);
        $this->assertNotNull($restored, '/rewind on a branch said "No checkpoints available"');
        $this->assertSame('draft 1', $restored['inputBuf']);
        $this->assertCount(3, $store->listCheckpoints('parent'), 'rewinding the branch must not touch the parent');
    }

    /**
     * Blobs are per session, so the fork needs its own rows. Pointing the
     * copied envelopes at the parent's rows would read fine until the parent
     * was deleted or its GC ran — then the fork would silently lose them.
     */
    public function testForkOwnsItsBlobsAndSurvivesTheParentsDeletion(): void
    {
        $store = new EnhancedSessionStore($this->dbPath);
        $store->createSession('parent', 'p', 'm');
        $history = [Message::user('one'), Message::assistant('two'), Message::user('three')];
        $store->saveCheckpoint('parent', ['messages' => $history]);
        $store->saveTranscript('parent', $history);
        $expected = $store->loadTranscript('parent');

        $fork = $store->forkSession('parent');

        $pdo = new PDO('sqlite:' . $this->dbPath);
        $stateRows = $pdo->prepare('
            SELECT state_data FROM checkpoints WHERE session_id = ?
            UNION ALL SELECT state_data FROM session_transcripts WHERE session_id = ?
        ');
        $stateRows->execute([$fork, $fork]);
        $owner = $pdo->prepare('SELECT session_id FROM checkpoint_blobs WHERE id = ?');
        foreach ($stateRows->fetchAll(PDO::FETCH_COLUMN) as $stateData) {
            foreach (json_decode((string) $stateData, true)['__cpm'] as $id) {
                $owner->execute([$id]);
                $this->assertSame($fork, $owner->fetchColumn(), "fork envelope names blob {$id}, which is not the fork's");
            }
        }

        $store->deleteSession('parent');

        $this->assertSame($expected, $store->loadTranscript($fork));
        $this->assertNotNull($store->getCheckpoint($fork, 0));
    }

    public function testForkCopiesSessionMeta(): void
    {
        $store = new EnhancedSessionStore($this->dbPath);
        $store->createSession('parent', 'p', 'm');
        $store->saveSessionMeta(new SessionMeta(
            sessionId: 'parent',
            summary: 'fixing the login bug',
            tasks: ['write test'],
            modifiedFiles: ['src/Login.php'],
            agentStates: [],
            lastActivity: new \DateTimeImmutable('2026-01-01 00:00:00', new \DateTimeZone('UTC')),
        ));

        $fork = $store->forkSession('parent');

        $meta = $store->getSessionMeta($fork);
        $this->assertNotNull($meta);
        $this->assertSame('fixing the login bug', $meta->summary);
        $this->assertSame(['write test'], $meta->tasks);
        $this->assertSame(['src/Login.php'], $meta->modifiedFiles);
        $this->assertGreaterThan(
            (new \DateTimeImmutable('2026-01-02 00:00:00', new \DateTimeZone('UTC')))->getTimestamp(),
            $meta->lastActivity->getTimestamp(),
            'the fork was active just now, not when the parent last was',
        );
    }

    public function testForkOfANamedSessionGetsADistinctBranchName(): void
    {
        $store = new EnhancedSessionStore($this->dbPath);
        $store->createSession('parent', 'p', 'm', null, 'my-work');

        $first = $store->forkSession('parent');
        $second = $store->forkSession('parent');
        $ofBranch = $store->forkSession($first);

        $this->assertSame('my-work (branch)', $store->getSession($first)['name']);
        $this->assertSame('my-work (branch 2)', $store->getSession($second)['name']);
        $this->assertSame('my-work (branch 3)', $store->getSession($ofBranch)['name']);
        $this->assertSame('parent', $store->getSessionByName('my-work')['id']);
        $this->assertSame($first, $store->getSessionByName('my-work (branch)')['id']);
    }

    public function testForkOfAnUnnamedSessionStaysUnnamed(): void
    {
        $store = new EnhancedSessionStore($this->dbPath);
        $store->createSession('parent', 'p', 'm');

        $this->assertNull($store->getSession($store->forkSession('parent'))['name']);
    }

    /**
     * Databases written before forks were renamed already hold duplicate
     * names. The row a user typing the name means is the one last used; the
     * unordered query returned the first row it met, which was the parent.
     */
    public function testGetSessionByNameResolvesLegacyDuplicatesToTheMostRecentlyUsed(): void
    {
        $store = new SessionStore($this->dbPath);
        $insert = $store->getPdo()->prepare(
            'INSERT INTO sessions (id, provider, model, name, updated_at) VALUES (?, ?, ?, ?, ?)',
        );
        $insert->execute(['parent', 'p', 'm', 'my-work', '2026-01-01 00:00:00']);
        $insert->execute(['branch', 'p', 'm', 'my-work', '2026-03-01 00:00:00']);

        $this->assertSame('branch', $store->getSessionByName('my-work')['id']);

        $store->getPdo()->exec("UPDATE sessions SET updated_at = '2026-06-01 00:00:00' WHERE id = 'parent'");
        $this->assertSame('parent', $store->getSessionByName('my-work')['id']);
    }

    /**
     * Audit 15b-21: the first save under a `/branch` id used to insert every
     * message again, one autocommitted INSERT each — a multi-second freeze
     * inside update() at 800 messages. With the blobs copied by the fork, that
     * save writes nothing new.
     */
    public function testFirstSaveUnderAForkReinternsNothing(): void
    {
        $store = new EnhancedSessionStore($this->dbPath);
        $store->createSession('parent', 'p', 'm');
        $history = [];
        for ($i = 0; $i < 50; $i++) {
            $history[] = Message::user("message {$i}");
        }
        $store->saveTranscript('parent', $history);

        $fork = $store->forkSession('parent');
        $pdo = new PDO('sqlite:' . $this->dbPath);
        $blobsBefore = (int) $pdo->query('SELECT COUNT(*) FROM checkpoint_blobs')->fetchColumn();

        $store->saveTranscript($fork, $history);

        $this->assertSame(100, $blobsBefore, 'fixture: the fork holds its own copy of the 50 blobs');
        $this->assertSame($blobsBefore, (int) $pdo->query('SELECT COUNT(*) FROM checkpoint_blobs')->fetchColumn());
        $this->assertSame($store->loadTranscript('parent'), $store->loadTranscript($fork));
    }

    /** A fork that fails part-way leaves no half-copied session behind. */
    public function testAFailedForkLeavesNothingBehind(): void
    {
        $store = new EnhancedSessionStore($this->dbPath);
        $store->createSession('parent', 'p', 'm');
        $store->saveTranscript('parent', [Message::user('hello')]);
        $store->saveSessionMeta(SessionMeta::new('parent', 's'));

        $pdo = new PDO('sqlite:' . $this->dbPath);
        $pdo->exec("CREATE TRIGGER fail_meta BEFORE INSERT ON session_meta BEGIN SELECT RAISE(ABORT, 'injected'); END");

        try {
            $store->forkSession('parent');
            $this->fail('the injected failure did not surface');
        } catch (\PDOException $e) {
            $this->assertStringContainsString('injected', $e->getMessage());
        }

        foreach (['sessions' => 1, 'session_transcripts' => 1, 'checkpoint_blobs' => 1] as $table => $rows) {
            $this->assertSame($rows, (int) $pdo->query("SELECT COUNT(*) FROM {$table}")->fetchColumn(), $table);
        }
    }

    /**
     * Audit SES-6: `created_at` has one-second resolution, and every message a
     * fork copies lands in the same second. Without a tiebreak the order of
     * tied rows is whatever the chosen plan yields: a table scan happens to
     * give rowid order, but an index the planner prefers — here one on
     * `created_at DESC`, which it walks backwards — gives the reverse.
     */
    public function testGetMessagesKeepsInsertionOrderAcrossSameSecondTimestamps(): void
    {
        $store = new SessionStore($this->dbPath);
        $store->createSession('s', 'p', 'm');
        $insert = $store->getPdo()->prepare(
            "INSERT INTO messages (session_id, role, content, created_at) VALUES ('s', 'user', ?, '2026-01-01 00:00:00')",
        );
        foreach (['first', 'second', 'third'] as $content) {
            $insert->execute([$content]);
        }
        $store->getPdo()->exec('CREATE INDEX idx_test_messages_created ON messages(session_id, created_at DESC)');

        $this->assertSame(['first', 'second', 'third'], array_column($store->getMessages('s'), 'content'));
    }
}
