<?php

declare(strict_types=1);

namespace CandyCode\Bundles\Discord\Tests\Support;

use CandyCode\Bundles\Discord\Support\SessionMap;
use PHPUnit\Framework\TestCase;

/**
 * The bind-cursor contract (cc-review-1111 M-7).
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
        $this->path = \sys_get_temp_dir() . '/discord-session-map-' . \uniqid('', true) . '.sqlite';
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

    // ── inbound claim machine (043, discord-25) ────────────────────────

    public function testAFreshClaimAnswersOnceAndTheLiveDuplicateStaysSuppressed(): void
    {
        $map = $this->map();
        self::assertTrue($map->claimInbound('discord', 'msg-1'));
        self::assertFalse($map->claimInbound('discord', 'msg-1'), 'a replay of a still-pending claim suppresses (as before)');
        self::assertSame('pending', $map->inboundState('discord', 'msg-1'));
    }

    public function testAConfirmedClaimSurvivesReopenAndNeverReprocesses(): void
    {
        $map = $this->map();
        self::assertTrue($map->claimInbound('discord', 'msg-2'));
        $map->confirmInbound('discord', 'msg-2');
        self::assertSame('confirmed', $map->inboundState('discord', 'msg-2'));

        $reopened = $this->map();
        self::assertSame('confirmed', $reopened->inboundState('discord', 'msg-2'), 'the boot sweep only orphans pending rows');
        self::assertFalse($reopened->claimInbound('discord', 'msg-2'));
    }

    public function testAPendingClaimFromADeadProcessIsStaleAndRedeliveryReprocessesIt(): void
    {
        // The crash window: claimed, never confirmed, then the process dies.
        $this->map()->claimInbound('discord', 'msg-3');

        $reopened = $this->map();
        self::assertSame('stale', $reopened->inboundState('discord', 'msg-3'), 'boot marks surviving claims orphaned');
        self::assertTrue($reopened->claimInbound('discord', 'msg-3'), 'the redelivery re-processes instead of vanishing');
        self::assertSame('pending', $reopened->inboundState('discord', 'msg-3'));
    }

    public function testAPendingRowPastTheHorizonReprocessesAndThePoisonArmStaysBounded(): void
    {
        $now = \time();
        $map = $this->map();
        self::assertTrue($map->claimInbound('discord', 'poison-1', $now));
        // Still inside the horizon: looks like a live dispatch, suppress.
        self::assertFalse($map->claimInbound('discord', 'poison-1', $now + SessionMap::CLAIM_GRACE_SECONDS - 10));
        // Past the horizon: no live dispatch takes that long — re-process.
        self::assertTrue($map->claimInbound('discord', 'poison-1', $now + SessionMap::CLAIM_GRACE_SECONDS + 1));
        // Re-claim refreshes the horizon, so a re-crashing id replays at
        // most once per horizon, and pruneSeen ages the row out.
        self::assertFalse($map->claimInbound('discord', 'poison-1', $now + SessionMap::CLAIM_GRACE_SECONDS + 30));
    }

    public function testALegacySeenTableWithoutTheStateColumnMigratesOnOpen(): void
    {
        $legacy = new \PDO('sqlite:' . $this->path);
        $legacy->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $legacy->exec('CREATE TABLE seen_inbound (platform TEXT NOT NULL, inbound_id TEXT NOT NULL, seen_at INTEGER NOT NULL, PRIMARY KEY (platform, inbound_id))');
        $legacy->exec("INSERT INTO seen_inbound (platform, inbound_id, seen_at) VALUES ('discord', 'old-1', 100)");
        $legacy = null;

        $map = $this->map();
        self::assertNotNull($map->inboundState('discord', 'old-1'), 'the ALTER added the state column');
        self::assertTrue($map->claimInbound('discord', 'old-1'), 'a legacy row reads as an orphan (stale) and re-claims');
    }

    /**
     * The M-7 keystone: re-binding a key onto a fresh session resets
     * its cursor, so the fresh session's low seqs stay deliverable.
     */
    public function testRebindingAKeyToAFreshSessionResetsTheStaleCursor(): void
    {
        $map = $this->map();
        $map->bind('channel:42', 'session-A', 'message:1');
        $map->noteSeqForSession('session-A', 500);
        self::assertSame(500, $map->cursor('channel:42'));

        // /new re-points the same key at a brand-new session (seq space restarts).
        $map->bind('channel:42', 'session-B');
        self::assertSame(0, $map->cursor('channel:42'));
        self::assertSame(0, $map->cursorForSession('session-B'));
        self::assertSame('session-B', $map->sessionIdFor('channel:42'));
    }

    /**
     * The polarity twin: a same-session re-bind must NOT resurrect a
     * replay — the monotonic observation record survives.
     */
    public function testRebindingTheSameSessionKeepsTheCursorUntouched(): void
    {
        $map = $this->map();
        $map->bind('channel:42', 'session-A', 'message:1');
        $map->noteSeq('channel:42', 500);

        $map->bind('channel:42', 'session-A');
        self::assertSame(500, $map->cursor('channel:42'));
        self::assertSame(500, $map->cursorForSession('session-A'));
    }

    /**
     * Migrating ONE key must not disturb the cursors the old session
     * earned through its other bindings (greatest-wins stays intact).
     */
    public function testMigratingOneKeyLeavesTheOldSessionsOtherBindingsAlone(): void
    {
        $map = $this->map();
        $map->bind('channel:42', 'session-A');
        $map->bind('channel:99', 'session-A');
        $map->noteSeqForSession('session-A', 500);

        $map->bind('channel:42', 'session-B');
        self::assertSame(500, $map->cursorForSession('session-A'));
        self::assertSame(500, $map->cursor('channel:99'));
        self::assertSame(0, $map->cursor('channel:42'));
        self::assertSame('session-B', $map->sessionIdFor('channel:42'));
        self::assertSame('session-A', $map->sessionIdFor('channel:99'));
    }

    /** A never-before-seen key inserts at zero — the /first-contact shape. */
    public function testAFreshKeyStartsAtZero(): void
    {
        $map = $this->map();
        $map->bind('channel:7', 'session-A');
        self::assertSame(0, $map->cursor('channel:7'));
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
        $map->bind('channel:42', 'session-A', 'message:1');
        $map->noteSeqForSession('session-A', 500);

        // Re-bind onto a fresh session WITH a different peer argument:
        // the session and cursor legs move...
        $map->bind('channel:42', 'session-B', 'message:2');
        self::assertSame('session-B', $map->sessionIdFor('channel:42'));
        self::assertSame(0, $map->cursor('channel:42'));

        // ...but the stored peer_id is the original — never rewritten,
        // not even by a null-peer re-bind (the bridges' re-bind shape).
        $db = new \PDO('sqlite:' . $this->path);
        $peer = $db->query("SELECT peer_id FROM bindings WHERE key = 'channel:42'")->fetch(\PDO::FETCH_ASSOC);
        self::assertSame('message:1', $peer['peer_id']);

        $map->bind('channel:42', 'session-C');
        self::assertSame('session-C', $map->sessionIdFor('channel:42'));
        $peer = $db->query("SELECT peer_id FROM bindings WHERE key = 'channel:42'")->fetch(\PDO::FETCH_ASSOC);
        self::assertSame('message:1', $peer['peer_id']);
    }
}
