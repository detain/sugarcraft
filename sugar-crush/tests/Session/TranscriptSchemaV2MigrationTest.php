<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Session;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Chat;
use SugarCraft\Crush\Message;
use SugarCraft\Crush\Session\EnhancedSessionStore;
use SugarCraft\Crush\ToolCall;
use SugarCraft\Crush\ToolResult;

/**
 * Roadmap 1.B-1: transcript schema version 2. Every saved row has an identity
 * - `m_<session>_<ref>` and a ref that is never reused - and a version-1
 * transcript (or the checkpoint a pre-transcript session resumes from) is
 * migrated on load, deterministically, so identities are stable from the
 * first resume on.
 */
final class TranscriptSchemaV2MigrationTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/crush_transcript_v2_' . bin2hex(random_bytes(6));
        mkdir($this->dir, 0700, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->dir);
    }

    private function store(): EnhancedSessionStore
    {
        return new EnhancedSessionStore($this->dir . '/session.db');
    }

    private function rawPdo(): \PDO
    {
        return new \PDO('sqlite:' . $this->dir . '/session.db');
    }

    /**
     * The transcript state as stored, envelope unwrapped.
     *
     * @return array<string, mixed>
     */
    private function storedState(string $sessionId): array
    {
        $stmt = $this->rawPdo()->prepare('SELECT state_data FROM session_transcripts WHERE session_id = ?');
        $stmt->execute([$sessionId]);
        $decoded = json_decode((string) $stmt->fetchColumn(), true);
        $this->assertIsArray($decoded);

        return \is_array($decoded['__cps'] ?? null) ? $decoded['__cps'] : $decoded;
    }

    /**
     * @param list<array<string, mixed>>|null $rows
     * @return list<array{0: mixed, 1: mixed}>
     */
    private static function identities(?array $rows): array
    {
        return array_map(static fn(array $r): array => [$r['id'] ?? null, $r['ref'] ?? null], $rows ?? []);
    }

    public function testASaveWritesVersionTwoWithItsHighWaterMark(): void
    {
        $store = $this->store();
        $store->createSession('s', 'p', 'm');

        $store->saveTranscript('s', [Message::user('a'), Message::assistant('b')]);

        $state = $this->storedState('s');
        $this->assertSame(EnhancedSessionStore::TRANSCRIPT_SCHEMA_VERSION, $state['v']);
        $this->assertSame(2, EnhancedSessionStore::TRANSCRIPT_SCHEMA_VERSION);
        $this->assertSame(3, $state['nextRef']);
        $this->assertSame([['m_s_1', 1], ['m_s_2', 2]], self::identities($store->loadTranscript('s')));
    }

    public function testTheSameRowKeepsItsIdentityAcrossSavesAndARefIsNeverReused(): void
    {
        $store = $this->store();
        $store->createSession('s', 'p', 'm');
        [$a, $b, $c, $d] = [Message::user('a'), Message::assistant('b'), Message::user('c'), Message::user('d')];

        $store->saveTranscript('s', [$a, $b, $c]);
        $store->saveTranscript('s', [$a, $b]);
        $store->saveTranscript('s', [$a, $b, $d]);

        $this->assertSame(
            [['m_s_1', 1], ['m_s_2', 2], ['m_s_4', 4]],
            self::identities($store->loadTranscript('s')),
            'c took ref 3 and dropping it does not give 3 to d',
        );
    }

    public function testTheMarkSurvivesAReopenedStore(): void
    {
        $first = $this->store();
        $first->createSession('s', 'p', 'm');
        $first->saveTranscript('s', [Message::user('a'), Message::user('b'), Message::user('c')]);
        $first->saveTranscript('s', [Message::user('only')]);

        $second = $this->store();
        $second->saveTranscript('s', [Message::user('fresh')]);

        $this->assertSame([['m_s_5', 5]], self::identities($second->loadTranscript('s')), 'refs 1-4 are spent on disk, not only in memory');
    }

    public function testARowThatCarriesItsIdentityKeepsIt(): void
    {
        $store = $this->store();
        $store->createSession('s', 'p', 'm');

        $store->saveTranscript('s', [
            Message::user('early'),
            Message::user('carried')->withIdentity('custom', 7),
            ['role' => 'assistant', 'content' => 'raw row'],
        ]);

        $this->assertSame(
            [['m_s_8', 8], ['custom', 7], ['m_s_9', 9]],
            self::identities($store->loadTranscript('s')),
            'the held ref 7 is observed before any is allocated, so even the row before it starts at 8; a raw array row is given one too',
        );
    }

    public function testAnIdentityLessRowIsStoredWithItsOwnBytesSoCheckpointBlobsAreShared(): void
    {
        $store = $this->store();
        $store->createSession('s', 'p', 'm');
        $history = [Message::user('one'), Message::user('two')];
        $store->saveCheckpoint('s', ['messages' => $history, 'inputBuf' => '']);
        $pdo = $this->rawPdo();
        $blobs = (int) $pdo->query('SELECT COUNT(*) FROM checkpoint_blobs')->fetchColumn();

        $store->saveTranscript('s', $history);

        $this->assertSame($blobs, (int) $pdo->query('SELECT COUNT(*) FROM checkpoint_blobs')->fetchColumn());
        $this->assertSame([['m_s_1', 1], ['m_s_2', 2]], $this->storedState('s')['identities']);
    }

    public function testAVersionOneTranscriptIsMigratedInOrderAndDeterministically(): void
    {
        $store = $this->store();
        $store->createSession('legacy', 'p', 'm');
        $toolRow = Message::assistant("a.txt\nb.txt")
            ->withToolResults([ToolResult::ok('Bash', "a.txt\nb.txt", 'call_1')])
            ->jsonSerialize();
        $pdo = $this->rawPdo();
        // The pre-1.B-1 shape: no `v`, no `nextRef`, rows without identity.
        $pdo->prepare('INSERT INTO session_transcripts (session_id, state_data, updated_at) VALUES (?, ?, ?)')
            ->execute(['legacy', json_encode(['messages' => [
                Message::user('list files')->jsonSerialize(),
                $toolRow,
                Message::assistant('Two files.')->jsonSerialize(),
            ]]), gmdate('Y-m-d H:i:s')]);

        $rows = $store->loadTranscript('legacy');

        $expected = [['m_legacy_1', 1], ['m_legacy_2', 2], ['m_legacy_3', 3]];
        $this->assertSame($expected, self::identities($rows));
        $this->assertSame($expected, self::identities($this->store()->loadTranscript('legacy')), 'a second read migrates identically');
        $this->assertArrayNotHasKey('stepId', $rows[1], 'a legacy tool row has no recorded step to rebuild a pair from');
        $revived = Chat::reviveTranscriptMessage($rows[1]);
        $this->assertFalse($revived->uiOnly, 'and it still reaches the model, as the prose row it always was');
        $this->assertSame(['m_legacy_2', 2], [$revived->id, $revived->ref]);
    }

    public function testTheFirstSaveAfterAResumePersistsTheMigratedIdentities(): void
    {
        $store = $this->store();
        $store->createSession('legacy', 'p', 'm');
        $this->rawPdo()->prepare('INSERT INTO session_transcripts (session_id, state_data, updated_at) VALUES (?, ?, ?)')
            ->execute(['legacy', json_encode(['messages' => [
                Message::user('one')->jsonSerialize(),
                Message::assistant('two')->jsonSerialize(),
            ]]), gmdate('Y-m-d H:i:s')]);

        $history = Chat::loadTranscript($store, 'legacy');
        $store->saveTranscript('legacy', [...$history, Message::user('three')]);

        $state = $this->storedState('legacy');
        $this->assertSame(2, $state['v']);
        $this->assertSame(4, $state['nextRef']);
        $this->assertSame(
            [['m_legacy_1', 1], ['m_legacy_2', 2], ['m_legacy_3', 3]],
            self::identities($this->store()->loadTranscript('legacy')),
        );
    }

    public function testTheCheckpointStandInIsMigratedToo(): void
    {
        $store = $this->store();
        $store->createSession('old', 'p', 'm');
        $store->saveCheckpoint('old', ['messages' => [Message::user('old turn'), Message::assistant('old reply')], 'inputBuf' => '']);

        $this->assertSame([['m_old_1', 1], ['m_old_2', 2]], self::identities($store->loadTranscript('old')));
    }

    public function testAForkKeepsItsParentsIdentitiesAndContinuesItsMark(): void
    {
        $store = $this->store();
        $store->createSession('parent', 'p', 'm');
        $history = [Message::user('a'), Message::assistant('b')];
        $store->saveTranscript('parent', $history);

        $fork = $store->forkSession('parent');
        $store->saveTranscript($fork, [...$history, Message::user('in the branch')]);

        $this->assertSame(
            [['m_parent_1', 1], ['m_parent_2', 2], ['m_' . $fork . '_3', 3]],
            self::identities($store->loadTranscript($fork)),
        );
    }

    public function testResumedMessagesCarryTheirIdentityIntoChat(): void
    {
        $store = $this->store();
        $store->createSession('s', 'p', 'm');
        $store->saveTranscript('s', [
            Message::user('go'),
            Message::assistant('ran')->withToolCalls([new ToolCall('Bash', ['command' => 'ls'], 'c1')]),
        ]);

        $history = Chat::loadTranscript($store, 's');

        $this->assertSame([['m_s_1', 1], ['m_s_2', 2]], array_map(static fn(Message $m): array => [$m->id, $m->ref], $history));
    }
}
