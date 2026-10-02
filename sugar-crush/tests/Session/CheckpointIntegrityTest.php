<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Session;

use PDO;
use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Message;
use SugarCraft\Crush\Session\EnhancedSessionStore;
use SugarCraft\Crush\Session\SessionMeta;

/**
 * Checkpoint and transcript writes: one transaction per save (audit 15b-21),
 * unique checkpoint indexes even with two writers (audit SES-3a), blob GC on
 * prune (audit SES-4), and UTC timestamps throughout (audit SES-5).
 *
 * @see EnhancedSessionStore::saveCheckpoint()
 * @see EnhancedSessionStore::saveTranscript()
 */
final class CheckpointIntegrityTest extends TestCase
{
    private string $tempDir;
    private string $dbPath;
    private string $previousTimezone;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempDir = sys_get_temp_dir() . '/crush_cp_integrity_' . bin2hex(random_bytes(6));
        mkdir($this->tempDir, 0700, true);
        $this->dbPath = $this->tempDir . '/test.db';
        $this->previousTimezone = date_default_timezone_get();
    }

    protected function tearDown(): void
    {
        date_default_timezone_set($this->previousTimezone);
        parent::tearDown();
        foreach (glob($this->tempDir . '/*') ?: [] as $file) {
            @unlink($file);
        }
        if (is_dir($this->tempDir)) {
            @rmdir($this->tempDir);
        }
    }

    // -- SES-3(a) ----------------------------------------------------------

    public function testCheckpointIndexIsUniquePerSession(): void
    {
        $store = new EnhancedSessionStore($this->dbPath);
        $store->createSession('s', 'p', 'm');
        $store->saveCheckpoint('s', ['inputBuf' => 'x']);

        $pdo = new PDO('sqlite:' . $this->dbPath);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $this->expectException(\PDOException::class);
        $this->expectExceptionMessageMatches('/UNIQUE/');
        $pdo->exec("INSERT INTO checkpoints (session_id, \"index\", state_data) VALUES ('s', 0, '{}')");
    }

    /**
     * An existing database can already hold duplicate indexes from the race.
     * Opening it must repair them without losing a snapshot, keep their order,
     * and do nothing at all on the next open.
     */
    public function testOpeningALegacyDatabaseRenumbersDuplicateIndexesLosslessly(): void
    {
        (new EnhancedSessionStore($this->dbPath))->createSession('s', 'p', 'm');
        $pdo = new PDO('sqlite:' . $this->dbPath);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        // Back to the shipped schema: a plain index, no uniqueness.
        $pdo->exec('DROP INDEX idx_checkpoints_session_index_unique');
        $pdo->exec('CREATE INDEX idx_checkpoints_session_index ON checkpoints(session_id, "index" DESC)');
        $insert = $pdo->prepare("INSERT INTO checkpoints (session_id, \"index\", state_data) VALUES ('s', ?, ?)");
        foreach ([[3, 'a'], [4, 'b'], [4, 'c'], [5, 'd'], [9, 'e']] as [$index, $draft]) {
            $insert->execute([$index, json_encode(['inputBuf' => $draft])]);
        }

        $store = new EnhancedSessionStore($this->dbPath);

        $rows = $pdo->query('SELECT "index", state_data FROM checkpoints ORDER BY "index"')->fetchAll(PDO::FETCH_ASSOC);
        $this->assertSame([3, 4, 5, 6, 9], array_map('intval', array_column($rows, 'index')));
        $this->assertSame(
            ['a', 'b', 'c', 'd', 'e'],
            array_map(static fn (array $r): string => json_decode($r['state_data'], true)['inputBuf'], $rows),
        );
        $indexes = $pdo->query("SELECT name FROM sqlite_master WHERE type = 'index' AND tbl_name = 'checkpoints'")
            ->fetchAll(PDO::FETCH_COLUMN);
        $this->assertContains('idx_checkpoints_session_index_unique', $indexes);
        $this->assertNotContains('idx_checkpoints_session_index', $indexes, 'the redundant non-unique index is dropped');
        $this->assertSame(10, $store->saveCheckpoint('s', ['inputBuf' => 'f']));

        new EnhancedSessionStore($this->dbPath);
        $this->assertSame(6, (int) $pdo->query('SELECT COUNT(*) FROM checkpoints')->fetchColumn());
    }

    /**
     * Two processes checkpointing one session at once — `--continue` in two
     * terminals. Index allocation was `MAX()+1` then `INSERT` in separate
     * autocommits, so both could take the same index. Each child opens its
     * own connection after the fork (a SQLite handle must never cross one) and
     * leaves by SIGKILL so neither PHPUnit's shutdown nor an inherited
     * destructor runs in it.
     */
    public function testTwoWritersNeverShareACheckpointIndex(): void
    {
        if (!\function_exists('pcntl_fork') || !\function_exists('posix_kill')) {
            $this->markTestSkipped('needs pcntl + posix to run two real writers');
        }

        (new EnhancedSessionStore($this->dbPath))->createSession('s', 'p', 'm');
        gc_collect_cycles();
        $go = $this->tempDir . '/go';
        $perChild = 40;

        $pids = [];
        for ($child = 0; $child < 2; $child++) {
            $pid = pcntl_fork();
            if ($pid === -1) {
                $this->fail('pcntl_fork failed');
            }
            if ($pid === 0) {
                $error = '';
                try {
                    $store = new EnhancedSessionStore($this->dbPath);
                    $deadline = microtime(true) + 10;
                    while (!is_file($go) && microtime(true) < $deadline) {
                        usleep(1000);
                    }
                    for ($i = 0; $i < $perChild; $i++) {
                        $store->saveCheckpoint('s', ['messages' => [Message::user("child {$child} turn {$i}")]]);
                    }
                } catch (\Throwable $e) {
                    $error = $e::class . ': ' . $e->getMessage();
                }
                file_put_contents($this->tempDir . "/child{$child}.done", $error);
                posix_kill(getmypid(), \SIGKILL);
            }
            $pids[] = $pid;
        }

        touch($go);
        $deadline = microtime(true) + 60;
        foreach ($pids as $pid) {
            while (pcntl_waitpid($pid, $status, \WNOHANG) === 0) {
                if (microtime(true) > $deadline) {
                    posix_kill($pid, \SIGKILL);
                    pcntl_waitpid($pid, $status);
                    $this->fail("writer {$pid} did not finish");
                }
                usleep(5000);
            }
        }

        for ($child = 0; $child < 2; $child++) {
            $this->assertSame('', @file_get_contents($this->tempDir . "/child{$child}.done"), "writer {$child} failed");
        }
        $pdo = new PDO('sqlite:' . $this->dbPath);
        $indexes = $pdo->query("SELECT \"index\" FROM checkpoints WHERE session_id = 's'")->fetchAll(PDO::FETCH_COLUMN);
        $this->assertCount(2 * $perChild, $indexes);
        $this->assertCount(2 * $perChild, array_unique($indexes), 'two checkpoints share an index');
    }

    // -- 15b-21: one transaction per save ------------------------------------

    /**
     * Every blob INSERT used to autocommit, so a save that failed part-way
     * left the blobs before the failure committed. Atomic now — which is the
     * same property as "one commit", the thing that took 800 messages from
     * 800 fsyncs to one.
     */
    public function testATranscriptSaveIsOneAtomicTransaction(): void
    {
        $store = new EnhancedSessionStore($this->dbPath);
        $store->createSession('s', 'p', 'm');
        $pdo = new PDO('sqlite:' . $this->dbPath);
        $pdo->exec("CREATE TRIGGER poison BEFORE INSERT ON checkpoint_blobs WHEN NEW.payload LIKE '%poison%'
            BEGIN SELECT RAISE(ABORT, 'injected'); END");

        $history = [];
        for ($i = 0; $i < 50; $i++) {
            $history[] = Message::user($i === 30 ? 'poison' : "message {$i}");
        }

        try {
            $store->saveTranscript('s', $history);
            $this->fail('the injected failure did not surface');
        } catch (\PDOException $e) {
            $this->assertStringContainsString('injected', $e->getMessage());
        }

        $this->assertSame(0, (int) $pdo->query('SELECT COUNT(*) FROM checkpoint_blobs')->fetchColumn());
        $this->assertSame(0, (int) $pdo->query('SELECT COUNT(*) FROM session_transcripts')->fetchColumn());
    }

    /**
     * A checkpoint whose row insert fails after its blobs were interned rolls
     * the blobs back too — and the in-memory intern cache with them, or the
     * next checkpoint of the same messages would name blob ids that no longer
     * exist and read back as "not found".
     */
    public function testAFailedCheckpointRollsBackItsBlobsAndTheInternCache(): void
    {
        $store = new EnhancedSessionStore($this->dbPath);
        $store->createSession('s', 'p', 'm');
        $pdo = new PDO('sqlite:' . $this->dbPath);
        $pdo->exec("CREATE TRIGGER fail_cp BEFORE INSERT ON checkpoints BEGIN SELECT RAISE(ABORT, 'injected'); END");
        $history = [Message::user('one'), Message::assistant('two')];

        try {
            $store->saveCheckpoint('s', ['messages' => $history]);
            $this->fail('the injected failure did not surface');
        } catch (\PDOException) {
        }
        $this->assertSame(0, (int) $pdo->query('SELECT COUNT(*) FROM checkpoint_blobs')->fetchColumn());

        $pdo->exec('DROP TRIGGER fail_cp');
        $index = $store->saveCheckpoint('s', ['messages' => $history]);

        $restored = $store->getCheckpoint('s', $index);
        $this->assertNotNull($restored, 'the second checkpoint named rolled-back blob ids');
        $this->assertSame(['one', 'two'], array_column($restored['messages'], 'content'));
    }

    // -- SES-4 ---------------------------------------------------------------

    /**
     * The audit's repro: compaction replaces history every 30 turns, so the
     * pre-compaction bodies end up referenced only by checkpoints the
     * retention limit prunes. Those blobs were collected only by /rewind.
     */
    public function testPruningCheckpointsCollectsTheBlobsOnlyTheyReferenced(): void
    {
        $store = new EnhancedSessionStore($this->dbPath);
        $store->createSession('s', 'p', 'm');
        $history = [];
        for ($i = 0; $i < 150; $i++) {
            $history[] = Message::user("turn {$i} " . str_repeat('x', 200));
            if ($i % 30 === 29) {
                $history = [Message::system("summary after {$i}")];
            }
            $store->saveCheckpoint('s', ['messages' => $history]);
            $store->saveTranscript('s', $history);
        }

        $pdo = new PDO('sqlite:' . $this->dbPath);
        $live = [];
        $states = $pdo->query('SELECT state_data FROM checkpoints UNION ALL SELECT state_data FROM session_transcripts');
        foreach ($states->fetchAll(PDO::FETCH_COLUMN) as $stateData) {
            foreach (json_decode((string) $stateData, true)['__cpm'] as $id) {
                $live[$id] = true;
            }
        }

        $this->assertSame(100, (int) $pdo->query('SELECT COUNT(*) FROM checkpoints')->fetchColumn());
        $this->assertSame(count($live), (int) $pdo->query('SELECT COUNT(*) FROM checkpoint_blobs')->fetchColumn());
        // The GC runs on the turn path, so the intern cache has to stay right
        // through it: everything still referenced still reads back.
        foreach ($store->listCheckpoints('s') as $checkpoint) {
            $this->assertNotNull($checkpoint['state_data'], "checkpoint {$checkpoint['index']} lost a blob");
        }
        $this->assertSame(
            array_map(static fn (Message $m): string => $m->content, $history),
            array_column($store->loadTranscript('s'), 'content'),
        );
    }

    // -- SES-5 ---------------------------------------------------------------

    public function testCheckpointCreatedAtIsUtc(): void
    {
        date_default_timezone_set('Asia/Tokyo');
        $store = new EnhancedSessionStore($this->dbPath);
        $store->createSession('s', 'p', 'm');

        $store->saveCheckpoint('s', ['inputBuf' => 'x']);

        $createdAt = new \DateTimeImmutable($store->listCheckpoints('s')[0]['created_at'], new \DateTimeZone('UTC'));
        $this->assertEqualsWithDelta(time(), $createdAt->getTimestamp(), 60, 'checkpoint time was written in local (+09:00) time');
    }

    public function testSessionMetaLastActivityIsStoredAsUtcAndReadBackAsTheSameInstant(): void
    {
        date_default_timezone_set('Asia/Tokyo');
        $store = new EnhancedSessionStore($this->dbPath);
        $store->createSession('s', 'p', 'm');
        $at = new \DateTimeImmutable('2026-05-01 12:00:00', new \DateTimeZone('+05:00'));

        $store->saveSessionMeta(SessionMeta::new('s', lastActivity: $at));

        $pdo = new PDO('sqlite:' . $this->dbPath);
        $this->assertSame('2026-05-01 07:00:00', $pdo->query('SELECT last_activity FROM session_meta')->fetchColumn());
        $this->assertSame($at->getTimestamp(), $store->getSessionMeta('s')->lastActivity->getTimestamp());
    }

    /**
     * `listSessionsWithMeta()` orders by `COALESCE(last_activity, updated_at)`.
     * With last_activity in local time and updated_at in UTC, a session used
     * an hour AGO sorted above one used just now east of UTC.
     */
    public function testListSessionsWithMetaOrdersMetaAndPlainRowsOnOneClock(): void
    {
        date_default_timezone_set('Asia/Tokyo');
        $store = new EnhancedSessionStore($this->dbPath);
        $store->createSession('with-meta', 'p', 'm');
        $store->saveSessionMeta(SessionMeta::new('with-meta', lastActivity: new \DateTimeImmutable('-1 hour')));
        $store->createSession('plain', 'p', 'm');

        $this->assertSame(['plain', 'with-meta'], array_column($store->listSessionsWithMeta(), 'id'));
    }
}
