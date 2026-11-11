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
