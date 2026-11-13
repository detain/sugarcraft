<?php

declare(strict_types=1);

namespace CandyCode\Bundles\MsTeams\Tests\Support;

use CandyCode\Bundles\MsTeams\Support\SessionMap;
use PHPUnit\Framework\TestCase;

/**
 * The bind-cursor contract (cc-review-1111 M-7) — twin of the
 * candy-discord SessionMapTest; the bundles share this class shape.
 *
 * `last_seq` is a MONOTONIC observation record: it may never move
 * backwards while a key keeps watching the SAME session (regap replay
 * would re-deliver turns). It is a record of what THAT binding saw —
 * so when bind() re-points a key at a DIFFERENT session, the old
 * session's seq space must NOT ride the rebind: the fresh session's
 * events all carry lower seqs, and a stale high cursor would have the
 * router's durable gate swallow them (the /new deafness defect).
 */
final class SessionMapTest extends TestCase
{
    private string $path;

    protected function setUp(): void
    {
        $this->path = \sys_get_temp_dir() . '/msteams-session-map-' . \uniqid('', true) . '.sqlite';
    }

    protected function tearDown(): void
    {
        foreach (['', '-wal', '-shm'] as $suffix) {
            $file = $this->path . $suffix;
            if (\is_file($file)) {
                @\unlink($file);
            }
        }
    }

    private function map(): SessionMap
    {
        return new SessionMap($this->path);
    }

    /** msteams-22: a fresh ledger is stamped with the schema it carries. */
    public function testAFreshLedgerCarriesTheSchemaVersionStamp(): void
    {
        $map = $this->map();

        $db = new \PDO('sqlite:' . $this->path);
        self::assertSame(SessionMap::SCHEMA_VERSION, (int) $db->query('PRAGMA user_version')->fetchColumn());
    }

    /** msteams-22: an unversioned legacy file migrates in place — rows ride. */
    public function testALegacyUnversionedLedgerIsMigratedWithoutLoss(): void
    {
        $map = $this->map();
        $map->bind('msteams:default:dm:peer-v', 'sess-legacy');

        // Pretend the file predates the stamp.
        $db = new \PDO('sqlite:' . $this->path);
        $db->exec('PRAGMA user_version = 0');
        $db = null;

        $reopened = $this->map();
        self::assertSame(['msteams:default:dm:peer-v'], $reopened->bindingsOfSession('sess-legacy'), 'the legacy binding must survive the stamp');
        $db = new \PDO('sqlite:' . $this->path);
        self::assertSame(SessionMap::SCHEMA_VERSION, (int) $db->query('PRAGMA user_version')->fetchColumn());
    }

    /** msteams-22: a ledger written by a NEWER bundle is refused, never half-believed. */
    public function testALedgerFromTheFutureIsRefused(): void
    {
        $this->map();
        $db = new \PDO('sqlite:' . $this->path);
        $db->exec('PRAGMA user_version = 99');
        $db = null;

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('refusing to open it');
        $this->map();
    }

    /**
     * msteams-22: the sweep deletes bindings and reply addresses idle past
     * the horizon and keeps the ones touched since — a live conversation
     * always refreshes updated_at, so age is real disuse.
     */
    public function testPruneBindingsSweepsIdleRowsAndKeepsLiveOnes(): void
    {
        $map = $this->map();
        $map->bind('msteams:default:dm:old-peer', 'sess-old');
        $map->rememberConversation('sess-old', 'msteams:default:dm:old-peer', ['conversationId' => '19:old@thread.v2', 'serviceUrl' => 'https://s.test']);

        \usleep(1_100_000);

        $map->bind('msteams:default:dm:fresh-peer', 'sess-fresh');
        $map->rememberConversation('sess-fresh', 'msteams:default:dm:fresh-peer', ['conversationId' => '19:fresh@thread.v2', 'serviceUrl' => 'https://s.test']);

        $map->pruneBindings(0); // cutoff = now: the >1 s-old rows are strictly older

        self::assertSame([], $map->bindingsOfSession('sess-old'), 'an idle binding must be swept');
        self::assertSame(['msteams:default:dm:fresh-peer'], $map->bindingsOfSession('sess-fresh'), 'a live binding must ride');
        self::assertNull($map->lastConversation('sess-old'), 'the idle reply address must be swept');
        self::assertNotNull($map->lastConversation('sess-fresh'), 'the live reply address must ride');
    }

    /** msteams-25: an unconfirmed redelivery INSIDE the grace window is still a duplicate. */
    public function testRedeliveryInsideTheGraceWindowStaysSuppressed(): void
    {
        $map = $this->map();
        self::assertTrue($map->claimInbound('msteams', 'm-grace-1'));
        self::assertFalse($map->claimInbound('msteams', 'm-grace-1'), 'the first dispatch may still be in flight');
    }

    /**
     * msteams-25: the crash arm — claim won, enqueue never confirmed (the
     * process died between them). Past the grace window Teams' redelivery
     * must get back in, or the message dies silently with the dead process.
     */
    public function testAnUnconfirmedClaimPastTheGraceWindowReprocesses(): void
    {
        $map = $this->map();
        self::assertTrue($map->claimInbound('msteams', 'm-crash-1'));

        $db = new \PDO('sqlite:' . $this->path);
        $db->exec('UPDATE seen_inbound SET seen_at = seen_at - ' . (SessionMap::CLAIM_GRACE_SECONDS + 60));
        $db = null;

        self::assertTrue($map->claimInbound('msteams', 'm-crash-1'), 'a dead claim must not black-hole the redelivery');
        self::assertFalse($map->claimInbound('msteams', 'm-crash-1'), 'the re-claimed row is owned again');
    }

    /** msteams-25: confirmation makes suppression permanent — grace or no grace. */
    public function testAConfirmedClaimIsSuppressedForever(): void
    {
        $map = $this->map();
        self::assertTrue($map->claimInbound('msteams', 'm-ok-1'));
        $map->confirmInbound('msteams', 'm-ok-1');

        $db = new \PDO('sqlite:' . $this->path);
        $db->exec('UPDATE seen_inbound SET seen_at = seen_at - ' . (SessionMap::CLAIM_GRACE_SECONDS + 60));
        $db = null;

        self::assertFalse($map->claimInbound('msteams', 'm-ok-1'), 'an enqueued prompt must never run twice');
    }

    /** msteams-25: the v1 ledger (no state column) migrates on open, dedupe intact. */
    public function testAVOneLedgerGainsTheStateColumnOnOpen(): void
    {
        $map = $this->map();
        self::assertTrue($map->claimInbound('msteams', 'm-v1-1'));

        // Rewind the file to the v1 shape: drop the column, clear the stamp.
        $db = new \PDO('sqlite:' . $this->path);
        $db->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $db->exec('PRAGMA busy_timeout = 5000');
        $db->exec('PRAGMA wal_checkpoint(TRUNCATE)');
        $db->exec('ALTER TABLE seen_inbound DROP COLUMN confirmed_at');
        $db->exec('PRAGMA user_version = 1');
        $db = null;
        unset($map);

        $reopened = $this->map();
        self::assertFalse($reopened->claimInbound('msteams', 'm-v1-1'), 'the migrated row must still suppress the duplicate');
        $db = new \PDO('sqlite:' . $this->path);
        self::assertSame(2, (int) $db->query('PRAGMA user_version')->fetchColumn());
    }

    /**
     * The M-7 keystone: re-binding a key onto a fresh session resets
     * its cursor, so the fresh session's low seqs stay deliverable.
     */
    public function testRebindingAKeyToAFreshSessionResetsTheStaleCursor(): void
    {
        $map = $this->map();
        $map->bind('conversation:42', 'session-A', 'activity:1');
        $map->noteSeqForSession('session-A', 500);
        self::assertSame(500, $map->cursor('conversation:42'));

        // /new re-points the same key at a brand-new session (seq space restarts).
        $map->bind('conversation:42', 'session-B');
        self::assertSame(0, $map->cursor('conversation:42'));
        self::assertSame(0, $map->cursorForSession('session-B'));
        self::assertSame('session-B', $map->sessionIdFor('conversation:42'));
    }

    /**
     * The polarity twin: a same-session re-bind must NOT resurrect a
     * replay — the monotonic observation record survives.
     */
    public function testRebindingTheSameSessionKeepsTheCursorUntouched(): void
    {
        $map = $this->map();
        $map->bind('conversation:42', 'session-A', 'activity:1');
        $map->noteSeq('conversation:42', 500);

        $map->bind('conversation:42', 'session-A');
        self::assertSame(500, $map->cursor('conversation:42'));
        self::assertSame(500, $map->cursorForSession('session-A'));
    }

    /**
     * Migrating ONE key must not disturb the cursors the old session
     * earned through its other bindings (greatest-wins stays intact).
     */
    public function testMigratingOneKeyLeavesTheOldSessionsOtherBindingsAlone(): void
    {
        $map = $this->map();
        $map->bind('conversation:42', 'session-A');
        $map->bind('conversation:99', 'session-A');
        $map->noteSeqForSession('session-A', 500);

        $map->bind('conversation:42', 'session-B');
        self::assertSame(500, $map->cursorForSession('session-A'));
        self::assertSame(500, $map->cursor('conversation:99'));
        self::assertSame(0, $map->cursor('conversation:42'));
        self::assertSame('session-B', $map->sessionIdFor('conversation:42'));
        self::assertSame('session-A', $map->sessionIdFor('conversation:99'));
    }

    /** A never-before-seen key inserts at zero — the /first-contact shape. */
    public function testAFreshKeyStartsAtZero(): void
    {
        $map = $this->map();
        $map->bind('conversation:7', 'session-A');
        self::assertSame(0, $map->cursor('conversation:7'));
        self::assertSame(0, $map->cursorForSession('session-A'));
    }

    /**
     * Adjudicated at weld (R-FM7-r1 MINOR-1): bind() deliberately never
     * updates peer_id — the ON CONFLICT SET omits the column, and the
     * bridges re-bind with a null peer, so the first peer wins for the
     * lifetime of the key. That preserved-stale answer matches the
     * pre-M-7 behaviour byte-for-byte; this leg pins it so a future
     * touch of the SET clause cannot silently change the contract.
     */
    public function testRebindingLeavesTheStoredPeerIdUntouched(): void
    {
        $map = $this->map();
        $map->bind('conversation:42', 'session-A', 'activity:1');
        $map->noteSeqForSession('session-A', 500);

        // Re-bind onto a fresh session WITH a different peer argument:
        // the session and cursor legs move...
        $map->bind('conversation:42', 'session-B', 'activity:2');
        self::assertSame('session-B', $map->sessionIdFor('conversation:42'));
        self::assertSame(0, $map->cursor('conversation:42'));

        // ...but the stored peer_id is the original — never rewritten,
        // not even by a null-peer re-bind (the bridges' re-bind shape).
        $db = new \PDO('sqlite:' . $this->path);
        $peer = $db->query("SELECT peer_id FROM bindings WHERE key = 'conversation:42'")->fetch(\PDO::FETCH_ASSOC);
        self::assertSame('activity:1', $peer['peer_id']);

        $map->bind('conversation:42', 'session-C');
        self::assertSame('session-C', $map->sessionIdFor('conversation:42'));
        $peer = $db->query("SELECT peer_id FROM bindings WHERE key = 'conversation:42'")->fetch(\PDO::FETCH_ASSOC);
        self::assertSame('activity:1', $peer['peer_id']);
    }
}
