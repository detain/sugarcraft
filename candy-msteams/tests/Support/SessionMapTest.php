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
}
