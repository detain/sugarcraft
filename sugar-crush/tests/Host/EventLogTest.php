<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Host;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Host\EventLog;
use SugarCraft\Crush\Session\EnhancedSessionStore;

/**
 * Roadmap O-2b: {@see EventLog}, the durable per-session event log a host
 * writes before it broadcasts (Appendix O §4.8 / §6.4 / §6.8). The contract a
 * reconnecting client relies on: seqs are monotonic per session, never reused,
 * continue across a restart, page in order, and a cursor that retention left
 * behind is told to resync rather than handed a gap.
 */
final class EventLogTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/crush-event-log-' . bin2hex(random_bytes(6));
        mkdir($this->dir, 0700, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $path) {
            if (is_dir($path)) {
                foreach (glob($path . '/*') ?: [] as $file) {
                    @unlink($file);
                }
                @rmdir($path);
                continue;
            }
            @unlink($path);
        }
        @rmdir($this->dir);
    }

    private function store(): EnhancedSessionStore
    {
        return new EnhancedSessionStore($this->dir . '/session.db');
    }

    public function testSeqsAreMonotonicPerSessionAndIndependentAcrossSessions(): void
    {
        $log = EventLog::new($this->store());

        self::assertSame(1, $log->append('a', 'message.created', ['messageId' => 'm_a_1']));
        self::assertSame(2, $log->append('a', 'turn.started'));
        self::assertSame(1, $log->append('b', 'message.created'), 'each session counts from 1');
        self::assertSame(3, $log->append('a', 'turn.completed', ['stopReason' => 'end_turn']));

        self::assertSame(3, $log->latestSeq('a'));
        self::assertSame(1, $log->latestSeq('b'));
        self::assertSame(0, $log->latestSeq('never'));
        self::assertNull($log->oldestSeq('never'));
    }

    public function testSinceReplaysInOrderAfterTheCursorAndPages(): void
    {
        $log = EventLog::new($this->store());
        foreach (range(1, 5) as $n) {
            $log->append('s', 'notice', ['text' => "n{$n}"], 1_790_000_000_000 + $n);
        }

        $page = $log->since('s', 2, 2);

        self::assertSame([3, 4], array_column($page, 'seq'));
        self::assertSame(['n3', 'n4'], array_map(static fn (array $e): string => $e['payload']['text'], $page));
        self::assertSame(1_790_000_000_003, $page[0]['ts']);
        self::assertSame('notice', $page[0]['type']);
        self::assertSame([1, 2, 3, 4, 5], array_column($log->since('s'), 'seq'));
        self::assertSame([], $log->since('s', 5));
    }

    public function testSeqsContinueAcrossAReopenedStore(): void
    {
        EventLog::new($this->store())->append('s', 'a');
        EventLog::new($this->store())->append('s', 'b');

        $reopened = EventLog::new($this->store());

        self::assertSame(3, $reopened->append('s', 'c'), 'a restarted process continues from MAX(seq)');
        self::assertSame(['a', 'b', 'c'], array_column($reopened->since('s'), 'type'));
    }

    public function testRetentionDropsTheOldestAndNeverReusesASeq(): void
    {
        $log = EventLog::new($this->store(), 3);
        foreach (range(1, 5) as $n) {
            $log->append('s', 'e' . $n);
        }

        self::assertSame([3, 4, 5], array_column($log->since('s'), 'seq'));
        self::assertSame(3, $log->oldestSeq('s'));
        self::assertSame(5, $log->latestSeq('s'));
        self::assertSame(6, $log->append('s', 'e6'), 'MAX survives the trim, so nothing is renumbered');
        self::assertSame(3, $log->retain());
    }

    public function testReplayabilityFollowsRetentionAndRejectsAForeignCursor(): void
    {
        $log = EventLog::new($this->store(), 3);
        self::assertTrue($log->isReplayable('s', 0), 'an empty log replays from the start');
        foreach (range(1, 5) as $n) {
            $log->append('s', 'e' . $n);
        }

        self::assertFalse($log->isReplayable('s', 0), 'events 1-2 were dropped');
        self::assertFalse($log->isReplayable('s', 1));
        self::assertTrue($log->isReplayable('s', 2), 'everything after 2 is still here');
        self::assertTrue($log->isReplayable('s', 5), 'up to date');
        self::assertFalse($log->isReplayable('s', 6), 'a cursor ahead of the log is from another database');
        self::assertFalse($log->isReplayable('s', -1));
    }

    public function testRetainBelowOneKeepsEverything(): void
    {
        $log = EventLog::new($this->store(), 0);
        foreach (range(1, 4) as $n) {
            $log->append('s', 'e');
        }

        self::assertSame(0, $log->retain());
        self::assertSame(1, $log->oldestSeq('s'));
        self::assertSame(2, $log->withRetain(2)->retain());
        self::assertSame(EventLog::DEFAULT_RETAIN, EventLog::new($this->store())->retain());
    }

    public function testAnEmptyTypeIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        EventLog::new($this->store())->append('s', '  ');
    }

    public function testPayloadsRoundTripAndInvalidUtf8IsSubstituted(): void
    {
        $store = $this->store();
        $log = EventLog::new($store);
        $log->append('s', 'tool.finished', ['content' => "caf\xe9", 'nested' => ['a' => [1, 2]], 'path' => '/x/y']);
        $log->append('s', 'session.status');

        $events = $log->since('s');

        self::assertSame("caf\u{FFFD}", $events[0]['payload']['content']);
        self::assertSame(['a' => [1, 2]], $events[0]['payload']['nested']);
        self::assertSame([], $events[1]['payload'], 'an empty data object round-trips as empty');
        self::assertSame($store, $log->store());
    }
}
