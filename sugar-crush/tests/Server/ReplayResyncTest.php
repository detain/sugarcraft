<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Server;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Host\EventLog;
use SugarCraft\Crush\Host\SessionEvent;
use SugarCraft\Crush\Tests\Server\Support\ProtocolFixture;

/**
 * Roadmap O-3b, Appendix O §6.8: `session.subscribe` with a cursor replays
 * exactly the durable events after it — in pages, gap-free, in order — then
 * goes live without doubling anything logged meanwhile; without a cursor, or
 * with one the log can no longer serve, it answers with a snapshot instead.
 */
final class ReplayResyncTest extends TestCase
{
    private ProtocolFixture $fixture;

    protected function setUp(): void
    {
        $this->fixture = ProtocolFixture::new();
    }

    protected function tearDown(): void
    {
        $this->fixture->tearDown();
    }

    public function testACursorReplaysEveryLaterEventAcrossPagesThenGoesLive(): void
    {
        $client = $this->fixture->client();
        $sessionId = $this->fixture->session($client);
        $this->logEvents($sessionId, 450);
        $latest = (int) $this->fixture->hub->get($sessionId)?->events()?->latestSeq($sessionId);

        $answer = $client->call('session.subscribe', ['sessionId' => $sessionId, 'afterSeq' => 10]);
        self::assertSame(11, $answer['fromSeq']);
        self::assertSame($latest, $answer['throughSeq']);
        self::assertArrayNotHasKey('reset', $answer);

        // Logged while the replay pages: heard live, after the replay, once.
        $this->logEvents($sessionId, 3);
        $this->fixture->run(0.1);

        $seqs = \array_column($client->events(), 'seq');
        self::assertSame(\range(11, $latest + 3), $seqs, 'no gap, no duplicate, in order');
        self::assertGreaterThan(EventLog::PAGE_SIZE, $latest - 10, 'the replay needed more than one page');
    }

    public function testReplayedAndLiveEventsHaveOneShape(): void
    {
        $client = $this->fixture->client();
        $sessionId = $this->fixture->session($client);
        $this->logEvents($sessionId, 1);
        $client->call('session.subscribe', ['sessionId' => $sessionId, 'afterSeq' => 0]);
        $this->fixture->run();
        $this->logEvents($sessionId, 1);
        $this->fixture->run();

        [$replayed, $live] = $client->events(SessionEvent::TURN_QUEUED);
        self::assertSame(\array_keys($replayed), \array_keys($live));
        self::assertSame($sessionId, $replayed['sessionId']);
        self::assertTrue($replayed['durable']);
    }

    public function testNoCursorAnswersWithASnapshot(): void
    {
        $client = $this->fixture->client();
        $sessionId = $this->fixture->session($client);
        $this->logEvents($sessionId, 5);
        $client->clear();

        $answer = $client->call('session.subscribe', ['sessionId' => $sessionId]);
        $this->fixture->run();

        self::assertTrue($answer['reset']);
        self::assertSame($sessionId, $answer['snapshot']['sessionId']);
        self::assertSame('idle', $answer['snapshot']['status']);
        self::assertSame($answer['throughSeq'], $answer['snapshot']['lastSeq']);
        self::assertSame([], $client->events(), 'nothing older than the snapshot is replayed');

        $this->logEvents($sessionId, 1);
        self::assertSame([$answer['throughSeq'] + 1], \array_column($client->events(), 'seq'));
    }

    public function testACursorAheadOfTheLogResyncsFromASnapshot(): void
    {
        $client = $this->fixture->client();
        $sessionId = $this->fixture->session($client);
        $this->logEvents($sessionId, 2);

        self::assertTrue($client->call('session.subscribe', ['sessionId' => $sessionId, 'afterSeq' => 99])['reset']);
    }

    public function testACursorRetentionHasOvertakenResyncsFromASnapshot(): void
    {
        $this->fixture->tearDown();
        $this->fixture = ProtocolFixture::new(null, 5);
        $client = $this->fixture->client();
        $sessionId = $this->fixture->session($client);
        $this->logEvents($sessionId, 20);

        $lost = $client->call('session.subscribe', ['sessionId' => $sessionId, 'afterSeq' => 3]);
        $kept = $client->call('session.subscribe', ['sessionId' => $sessionId, 'afterSeq' => 17]);

        self::assertTrue($lost['reset']);
        self::assertSame(18, $kept['fromSeq']);
    }

    public function testUnsubscribeStopsTheStream(): void
    {
        $client = $this->fixture->client();
        $sessionId = $this->fixture->session($client);
        $client->call('session.subscribe', ['sessionId' => $sessionId]);
        $this->fixture->run();
        $client->clear();

        self::assertTrue($client->call('session.unsubscribe', ['sessionId' => $sessionId])['unsubscribed']);
        $this->logEvents($sessionId, 2);

        self::assertSame([], $client->events());
    }

    public function testASessionOpenElsewhereIsRefusedAsLocked(): void
    {
        $client = $this->fixture->client();
        $this->fixture->store->createSession('held', 'p', 'm');
        $lock = $this->fixture->store->lockSession('held');
        self::assertNotNull($lock);

        // flock() belongs to the open file description, so a second
        // acquisition in this same process is refused exactly as another
        // sugarcrush's would be.
        $answer = $client->request('session.subscribe', ['sessionId' => 'held']);
        $lock->release();

        self::assertSame('session_locked', $answer['error']['data']['kind'] ?? null);
        self::assertSame(-32009, $answer['error']['code']);
        self::assertSame('session_not_found', $client->request('session.subscribe', ['sessionId' => 'never'])['error']['data']['kind']);
    }

    /** Log $count durable events into $sessionId through its host. */
    private function logEvents(string $sessionId, int $count): void
    {
        $host = $this->fixture->hub->get($sessionId);
        self::assertNotNull($host);
        for ($i = 0; $i < $count; $i++) {
            $host->announce(SessionEvent::new(SessionEvent::TURN_QUEUED, ['queueId' => 'q' . $i, 'text' => 'x', 'position' => 1], $sessionId));
        }
    }
}
