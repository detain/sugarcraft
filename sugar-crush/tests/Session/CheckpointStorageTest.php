<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Session;

use PDO;
use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Session\EnhancedSessionStore;

/**
 * crush_code.md Phase 0 item 11 — checkpoints must stop rewriting the whole
 * conversation on every turn.
 *
 * These assert on the bytes that reach SQLite, not on code shape: the finding
 * was an O(N²) write-volume one, so the regression guard has to be a
 * measurement.
 *
 * @see EnhancedSessionStore::saveCheckpoint()
 */
final class CheckpointStorageTest extends TestCase
{
    private string $tempDir;
    private string $dbPath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempDir = sys_get_temp_dir() . '/crush_cp_storage_' . bin2hex(random_bytes(6));
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

    /**
     * The headline: total stored checkpoint bytes must grow roughly linearly
     * in turn count, not quadratically. Doubling the session length may not
     * more than triple the bytes — a full-snapshot-per-turn store quadruples
     * them, so the bound separates the two without being brittle about the
     * exact encoding.
     */
    public function testCheckpointBytesGrowLinearlyNotQuadraticallyWithTurnCount(): void
    {
        $short = $this->storedBytesForSession(20);
        $long = $this->storedBytesForSession(40);

        $this->assertGreaterThan(0, $short);
        $this->assertLessThan(
            $short * 3,
            $long,
            "Doubling the turn count grew stored checkpoint bytes from {$short} to {$long}; "
            . 'that is the O(N²) full-snapshot-per-turn behaviour item 11 removed.',
        );
    }

    /**
     * The mechanism behind that bound: a message body is stored once, however
     * many checkpoints go on to reference it.
     */
    public function testRepeatedHistoryIsStoredOncePerDistinctMessage(): void
    {
        $store = new EnhancedSessionStore($this->dbPath);
        $store->createSession('s', 'p', 'm');

        $history = [];
        for ($turn = 1; $turn <= 10; $turn++) {
            $history[] = ['role' => 'user', 'content' => "question {$turn}"];
            $history[] = ['role' => 'assistant', 'content' => "answer {$turn}"];
            $store->saveCheckpoint('s', ['messages' => $history, 'inputBuf' => '']);
        }

        $pdo = new PDO('sqlite:' . $this->dbPath);
        $blobs = (int) $pdo->query('SELECT COUNT(*) FROM checkpoint_blobs')->fetchColumn();

        $this->assertSame(20, $blobs, 'Ten turns of two distinct messages each must intern exactly 20 bodies.');
    }

    /**
     * A checkpoint still round-trips to exactly the state it was saved from,
     * key order included — /rewind rebuilds Message objects straight off this
     * array.
     */
    public function testCheckpointRoundTripsTheExactStateItWasSavedFrom(): void
    {
        $store = new EnhancedSessionStore($this->dbPath);
        $store->createSession('s', 'p', 'm');

        $state = [
            'messages' => [
                ['role' => 'user', 'content' => 'first'],
                ['role' => 'assistant', 'content' => 'second', 'pendingToolCallId' => null],
            ],
            'inputBuf' => 'half typed',
            'inFlight' => false,
            'agentContext' => ['currentSessionId' => 's'],
        ];

        $index = $store->saveCheckpoint('s', $state);

        $this->assertSame($state, $store->getCheckpoint('s', $index));
    }

    /**
     * A message REPLACED in place (a tool-running placeholder becoming its
     * real result) must not corrupt the checkpoints that referenced the old
     * body — the reason content addressing was chosen over a prefix delta.
     */
    public function testReplacingAMessageInPlaceLeavesEarlierCheckpointsIntact(): void
    {
        $store = new EnhancedSessionStore($this->dbPath);
        $store->createSession('s', 'p', 'm');

        $running = [
            ['role' => 'user', 'content' => 'run the tests'],
            ['role' => 'assistant', 'content' => 'bash: phpunit', 'pendingToolCallId' => 'call-1'],
        ];
        $resolved = [
            ['role' => 'user', 'content' => 'run the tests'],
            ['role' => 'assistant', 'content' => 'OK (4999 tests)', 'pendingToolCallId' => null],
        ];

        $first = $store->saveCheckpoint('s', ['messages' => $running]);
        $second = $store->saveCheckpoint('s', ['messages' => $resolved]);

        $this->assertSame($running, $store->getCheckpoint('s', $first)['messages']);
        $this->assertSame($resolved, $store->getCheckpoint('s', $second)['messages']);
    }

    /**
     * The recovery guarantee, stated as a test: a store that did not write
     * the checkpoints must still see EVERY turn as its own rewind point — no
     * turns are collapsed or thrown away, which is exactly what a "checkpoint
     * every K turns" throttle would have cost.
     *
     * Named for what it does: `unset($writer)` is a CLEAN close, so this is a
     * reopen, not an interruption. Durability across a real SIGKILL is a
     * property of WAL mode rather than of this class, and faking one from
     * inside the test process would only assert on PHP's destructor order.
     */
    public function testASecondStoreOverTheSameFileSeesEveryTurnAsItsOwnRewindPoint(): void
    {
        $writer = new EnhancedSessionStore($this->dbPath);
        $writer->createSession('s', 'p', 'm');

        $history = [];
        for ($turn = 1; $turn <= 6; $turn++) {
            $history[] = ['role' => 'user', 'content' => "turn {$turn}"];
            $writer->saveCheckpoint('s', ['messages' => $history, 'inputBuf' => '']);
        }
        // The writing store closes and a fresh one opens the same file.
        unset($writer);

        $resumed = new EnhancedSessionStore($this->dbPath);

        $this->assertCount(6, $resumed->listCheckpoints('s'));
        for ($turn = 1; $turn <= 6; $turn++) {
            $state = $resumed->getCheckpoint('s', $turn - 1);
            $this->assertNotNull($state, "Checkpoint for turn {$turn} did not survive the reopen.");
            $this->assertCount($turn, $state['messages']);
            $this->assertSame("turn {$turn}", $state['messages'][$turn - 1]['content']);
        }
    }

    /**
     * A resumed process must also keep appending to the same interned bodies
     * rather than starting a second copy of the history — the cold-cache path
     * through internMessages().
     */
    public function testResumedProcessReusesTheBodiesTheDeadProcessAlreadyStored(): void
    {
        $writer = new EnhancedSessionStore($this->dbPath);
        $writer->createSession('s', 'p', 'm');
        $history = [
            ['role' => 'user', 'content' => 'one'],
            ['role' => 'assistant', 'content' => 'two'],
        ];
        $writer->saveCheckpoint('s', ['messages' => $history]);
        unset($writer);

        $resumed = new EnhancedSessionStore($this->dbPath);
        $history[] = ['role' => 'user', 'content' => 'three'];
        $index = $resumed->saveCheckpoint('s', ['messages' => $history]);

        $pdo = new PDO('sqlite:' . $this->dbPath);
        $blobs = (int) $pdo->query('SELECT COUNT(*) FROM checkpoint_blobs')->fetchColumn();

        $this->assertSame(3, $blobs, 'The resumed process re-stored bodies it should have found on disk.');
        $this->assertSame($history, $resumed->getCheckpoint('s', $index)['messages']);
    }

    /**
     * Databases written before item 11 hold whole-state JSON in `state_data`
     * with no envelope and no blob table. Those rows must keep reading back
     * unchanged, and the schema must migrate around them.
     */
    public function testCheckpointsWrittenByTheOldInlineFormatStillReadBack(): void
    {
        $store = new EnhancedSessionStore($this->dbPath);
        $store->createSession('s', 'p', 'm');

        $legacy = [
            'messages' => [['role' => 'user', 'content' => 'written by the old format']],
            'inputBuf' => '',
            'agentContext' => ['currentSessionId' => 's'],
        ];

        // Write the row exactly as the pre-item-11 saveCheckpoint() did.
        $pdo = new PDO('sqlite:' . $this->dbPath);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->prepare('INSERT INTO checkpoints (session_id, "index", state_data) VALUES (?, ?, ?)')
            ->execute(['s', 0, json_encode($legacy)]);
        unset($pdo);

        $reopened = new EnhancedSessionStore($this->dbPath);

        $this->assertSame($legacy, $reopened->getCheckpoint('s', 0));

        // And a new checkpoint alongside it uses the new format without
        // disturbing the old row.
        $reopened->saveCheckpoint('s', ['messages' => [['role' => 'user', 'content' => 'new']]]);
        $this->assertSame($legacy, $reopened->getCheckpoint('s', 0));
        $this->assertSame('new', $reopened->getCheckpoint('s', 1)['messages'][0]['content']);
    }

    /**
     * Rewinding discards state, so the bodies only that state referenced are
     * collected. Bodies still named by a surviving checkpoint stay.
     */
    public function testRewindingCollectsOnlyTheBodiesNoSurvivingCheckpointNeeds(): void
    {
        $store = new EnhancedSessionStore($this->dbPath);
        $store->createSession('s', 'p', 'm');

        $kept = [['role' => 'user', 'content' => 'keep me']];
        $discarded = [...$kept, ['role' => 'assistant', 'content' => 'throw me away']];

        $store->saveCheckpoint('s', ['messages' => $kept]);
        $store->saveCheckpoint('s', ['messages' => $discarded]);

        $store->restoreCheckpoint('s', 1);

        $pdo = new PDO('sqlite:' . $this->dbPath);
        $payloads = $pdo->query('SELECT payload FROM checkpoint_blobs')->fetchAll(PDO::FETCH_COLUMN);

        $this->assertCount(1, $payloads);
        $this->assertStringContainsString('keep me', (string) $payloads[0]);
        $this->assertSame($kept, $store->getCheckpoint('s', 0)['messages']);
    }

    /**
     * The two-terminal repro. `Bootstrap::seedSession()` resumes
     * `listSessions(1)[0]` — the globally most recent row — so two sugar-crush
     * terminals ALWAYS land on the same session. A `/rewind` in one collects
     * blobs the other still has interned; that other process then wrote
     * envelopes naming deleted ids, and every checkpoint it wrote for the rest
     * of its life read back as "Checkpoint N not found".
     *
     * Before the fix this was 5/5 unreadable, and the bad rows stayed
     * unreadable even after a restart.
     */
    public function testARewindInAnotherProcessDoesNotKillThisOnesCheckpoints(): void
    {
        $terminalOne = new EnhancedSessionStore($this->dbPath);
        $terminalOne->createSession('s', 'p', 'm');

        $history = [];
        for ($turn = 1; $turn <= 5; $turn++) {
            $history[] = ['role' => 'user', 'content' => "turn {$turn}"];
            $terminalOne->saveCheckpoint('s', ['messages' => $history, 'inputBuf' => '']);
        }

        // A second terminal — separate process, separate connection, same
        // session — rewinds, which garbage-collects the blobs behind
        // checkpoints 2..4.
        $terminalTwo = new EnhancedSessionStore($this->dbPath);
        $this->assertNotNull($terminalTwo->restoreCheckpoint('s', 2));

        // Terminal one keeps working, unaware.
        $index = null;
        for ($turn = 6; $turn <= 10; $turn++) {
            $history[] = ['role' => 'user', 'content' => "turn {$turn}"];
            $index = $terminalOne->saveCheckpoint('s', ['messages' => $history, 'inputBuf' => '']);

            $state = $terminalOne->getCheckpoint('s', $index);
            $this->assertNotNull(
                $state,
                "Checkpoint {$index} written after another process rewound is unreadable; /rewind is dead in this process.",
            );
            $this->assertSame($history, $state['messages']);
        }

        // And the rows are readable from a third store too, not just from the
        // instance whose cache happened to be warm.
        $reopened = new EnhancedSessionStore($this->dbPath);
        $this->assertSame($history, $reopened->getCheckpoint('s', (int) $index)['messages']);
    }

    /**
     * Same root cause, second trigger: `deleteSession()` cascade-deletes the
     * session's blobs, so the interned ids for that session id are dangling
     * even though the deletion went through THIS connection. Latent in
     * production only because session ids are `random_bytes(8)`, which is the
     * whole point — the invariant is that ANY blob deletion invalidates every
     * cache, not just the GC's own.
     */
    public function testDeletingAndRecreatingASessionIdDoesNotStrandTheCache(): void
    {
        $store = new EnhancedSessionStore($this->dbPath);
        $store->createSession('reused', 'p', 'm');
        $store->saveCheckpoint('reused', ['messages' => [['role' => 'user', 'content' => 'first life']]]);

        $store->deleteSession('reused');
        $store->createSession('reused', 'p', 'm');

        $messages = [['role' => 'user', 'content' => 'first life']];
        $index = $store->saveCheckpoint('reused', ['messages' => $messages]);

        $state = $store->getCheckpoint('reused', $index);
        $this->assertNotNull($state, 'The recreated session reused blob ids that the cascade had already deleted.');
        $this->assertSame($messages, $state['messages']);
    }

    /** Retention deletes sessions, and their blobs with them. */
    public function testPruningASessionDoesNotStrandTheCacheEither(): void
    {
        $store = new EnhancedSessionStore($this->dbPath);
        $store->createSession('doomed', 'p', 'm');
        $store->saveCheckpoint('doomed', ['messages' => [['role' => 'user', 'content' => 'x']]]);

        $pdo = new PDO('sqlite:' . $this->dbPath);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec("UPDATE sessions SET updated_at = '2020-01-01 00:00:00'");
        unset($pdo);

        $this->assertSame(1, $store->pruneSessions(30));

        $store->createSession('doomed', 'p', 'm');
        $messages = [['role' => 'user', 'content' => 'x']];
        $index = $store->saveCheckpoint('doomed', ['messages' => $messages]);

        $this->assertSame($messages, $store->getCheckpoint('doomed', $index)['messages']);
    }

    /**
     * A tool result is whatever the command wrote — `Chat::finishToolCalls()`
     * puts it into history verbatim and nothing on that path validates UTF-8.
     * `json_encode()` returns `false` on the first invalid byte and
     * `(string) false` is `''`, so such a message used to be stored as the
     * empty string, hash the empty string (collapsing every un-encodable
     * message in the session onto ONE shared blob) and decode back to `null`,
     * ending in /rewind's catch-all with "Argument #1 ($m) must be of type
     * array, null given".
     */
    public function testAMessageWithInvalidUtf8IsStoredAndRestoredWithoutAHole(): void
    {
        $store = new EnhancedSessionStore($this->dbPath);
        $store->createSession('s', 'p', 'm');

        // 64 raw bytes of an ELF header — what `bash: head -c 64 /bin/ls`
        // hands back as a tool result.
        $binary = "\x7fELF\x02\x01\x01" . random_bytes(57);
        $other = "\xff\xfe malformed too";

        $messages = [
            ['role' => 'user', 'content' => 'head -c 64 /bin/ls'],
            ['role' => 'tool', 'content' => $binary],
            ['role' => 'tool', 'content' => $other],
        ];

        $index = $store->saveCheckpoint('s', ['messages' => $messages]);
        $state = $store->getCheckpoint('s', $index);

        $this->assertNotNull($state);
        $this->assertCount(3, $state['messages']);
        foreach ($state['messages'] as $position => $message) {
            $this->assertIsArray($message, "Message {$position} came back as a null hole.");
        }
        // The undecodable bytes are substituted, not dropped, and the two
        // un-encodable messages stay DISTINCT rather than collapsing onto one
        // blob.
        $this->assertStringContainsString("\u{FFFD}", $state['messages'][1]['content']);
        $this->assertNotSame($state['messages'][1]['content'], $state['messages'][2]['content']);
        $this->assertStringContainsString('malformed too', $state['messages'][2]['content']);

        $pdo = new PDO('sqlite:' . $this->dbPath);
        $this->assertSame(3, (int) $pdo->query('SELECT COUNT(*) FROM checkpoint_blobs')->fetchColumn());
    }

    /** Any OTHER encode failure is loud, not a silently empty payload. */
    public function testAnUnencodableStateThrowsRatherThanStoringAHole(): void
    {
        $store = new EnhancedSessionStore($this->dbPath);
        $store->createSession('s', 'p', 'm');

        $this->expectException(\JsonException::class);
        $store->saveCheckpoint('s', ['messages' => [['role' => 'user', 'content' => NAN]]]);
    }

    /** Deleting a session must not leave its interned bodies behind. */
    public function testDeletingASessionCascadesToItsInternedBodies(): void
    {
        $store = new EnhancedSessionStore($this->dbPath);
        $store->createSession('s', 'p', 'm');
        $store->saveCheckpoint('s', ['messages' => [['role' => 'user', 'content' => 'x']]]);

        $store->deleteSession('s');

        $pdo = new PDO('sqlite:' . $this->dbPath);
        $this->assertSame(0, (int) $pdo->query('SELECT COUNT(*) FROM checkpoint_blobs')->fetchColumn());
    }

    /**
     * The intern cache never evicted anything, so a long-lived process
     * accumulated a map per session it had ever checkpointed with nothing to
     * release them — a store is never told a session is finished. Bounded to
     * a handful of sessions; a session's own map is still proportional to the
     * messages this process checkpointed, which is the point of having it.
     */
    public function testTheInternCacheDoesNotGrowWithEverySessionEverTouched(): void
    {
        $store = new EnhancedSessionStore($this->dbPath);

        for ($session = 0; $session < 10; $session++) {
            $store->createSession("s{$session}", 'p', 'm');
            $store->saveCheckpoint("s{$session}", ['messages' => [['role' => 'user', 'content' => "hello {$session}"]]]);
        }

        $cache = (new \ReflectionProperty(EnhancedSessionStore::class, 'blobIds'))->getValue($store);
        $this->assertLessThanOrEqual(4, count($cache), 'The intern cache is holding a map for every session ever touched.');

        // Eviction only costs a re-lookup — nothing becomes unreadable.
        $messages = [['role' => 'user', 'content' => 'hello 0'], ['role' => 'user', 'content' => 'and more']];
        $index = $store->saveCheckpoint('s0', ['messages' => $messages]);
        $this->assertSame($messages, $store->getCheckpoint('s0', $index)['messages']);
    }

    /** A state carrying no message list at all is still storable. */
    public function testStateWithoutAMessageListIsStoredAndReadBackVerbatim(): void
    {
        $store = new EnhancedSessionStore($this->dbPath);
        $store->createSession('s', 'p', 'm');

        $state = ['inputBuf' => 'no history here', 'agentContext' => []];
        $index = $store->saveCheckpoint('s', $state);

        $this->assertSame($state, $store->getCheckpoint('s', $index));
    }

    /**
     * Total bytes SQLite is holding for a session's checkpoints after
     * `$turns` turns of a growing conversation.
     */
    private function storedBytesForSession(int $turns): int
    {
        $dbPath = $this->tempDir . '/bytes_' . $turns . '.db';
        $store = new EnhancedSessionStore($dbPath);
        $store->createSession('s', 'p', 'm');

        $history = [];
        for ($turn = 1; $turn <= $turns; $turn++) {
            $history[] = ['role' => 'user', 'content' => str_repeat("ask {$turn} ", 20)];
            $history[] = ['role' => 'assistant', 'content' => str_repeat("reply {$turn} ", 60)];
            $store->saveCheckpoint('s', ['messages' => $history, 'inputBuf' => '']);
        }
        unset($store);

        $pdo = new PDO('sqlite:' . $dbPath);
        $checkpointBytes = (int) $pdo->query('SELECT COALESCE(SUM(LENGTH(state_data)), 0) FROM checkpoints')->fetchColumn();
        $blobBytes = (int) $pdo->query('SELECT COALESCE(SUM(LENGTH(payload)), 0) FROM checkpoint_blobs')->fetchColumn();

        return $checkpointBytes + $blobBytes;
    }
}
