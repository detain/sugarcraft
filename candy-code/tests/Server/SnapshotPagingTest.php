<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Server;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Host\SessionSnapshot;
use SugarCraft\Code\Message;
use SugarCraft\Code\Tests\Server\Support\ProtocolFixture;

/**
 * ISS-058: a long session's whole-transcript snapshot outgrew
 * {@see \SugarCraft\Code\Protocol\Dispatcher::MAX_SERVER_FRAME_BYTES} and the
 * answer came back `too_large`. `session.get` and every reset snapshot now
 * page — `limit`/`beforeIndex` (and `snapshotLimit` on `session.subscribe`)
 * cap the newest rows, and the answer says where its window sits so a client
 * can walk backwards without a gap or an overlap. Unparameterized answers
 * stay unpaged and byte-identical to the pre-paging shape.
 */
final class SnapshotPagingTest extends TestCase
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

    public function testAPagedWindowCarriesTheNewestRowsWithTheirCoordinates(): void
    {
        $client = $this->fixture->client();
        $sessionId = $this->fixture->session($client);
        $full = $this->seed($client, $sessionId, 3);

        $page = $client->call('session.get', ['sessionId' => $sessionId, 'limit' => 5]);

        self::assertSame(\array_slice($full, 1), $page['messages'], 'the page is the newest five rows, oldest-first inside the page');
        self::assertSame(6, $page['totalMessages']);
        self::assertSame(1, $page['oldestIndex']);
        self::assertTrue($page['hasMoreOlder']);
    }

    public function testWalkingBackwardsRevealsNoGapAndNoOverlap(): void
    {
        $client = $this->fixture->client();
        $sessionId = $this->fixture->session($client);
        $full = $this->seed($client, $sessionId, 3);

        $newest = $client->call('session.get', ['sessionId' => $sessionId, 'limit' => 2]);
        $middle = $client->call('session.get', ['sessionId' => $sessionId, 'limit' => 2, 'beforeIndex' => $newest['oldestIndex']]);
        $oldest = $client->call('session.get', ['sessionId' => $sessionId, 'limit' => 2, 'beforeIndex' => $middle['oldestIndex']]);

        self::assertSame($full, [...$oldest['messages'], ...$middle['messages'], ...$newest['messages']], 'the walk covers every row exactly once');
        self::assertSame(4, $newest['oldestIndex']);
        self::assertTrue($newest['hasMoreOlder']);
        self::assertSame(2, $middle['oldestIndex']);
        self::assertTrue($middle['hasMoreOlder']);
        self::assertSame(0, $oldest['oldestIndex']);
        self::assertFalse($oldest['hasMoreOlder'], 'the walk stops at the first row, never below it');
    }

    public function testAWindowBeyondEitherEdgeClampsToTheTranscript(): void
    {
        $client = $this->fixture->client();
        $sessionId = $this->fixture->session($client);
        $full = $this->seed($client, $sessionId, 2);

        $wide = $client->call('session.get', ['sessionId' => $sessionId, 'limit' => SessionSnapshot::MAX_PAGE_ROWS, 'beforeIndex' => 99]);

        self::assertSame($full, $wide['messages']);
        self::assertSame(0, $wide['oldestIndex']);
        self::assertFalse($wide['hasMoreOlder']);
    }

    public function testAnUnparameterizedGetStillCarriesTheWholeTranscript(): void
    {
        $client = $this->fixture->client();
        $sessionId = $this->fixture->session($client);
        $full = $this->seed($client, $sessionId, 2);

        $answer = $client->call('session.get', ['sessionId' => $sessionId]);

        self::assertSame($full, $answer['messages']);
        foreach (['totalMessages', 'oldestIndex', 'hasMoreOlder'] as $meta) {
            self::assertArrayNotHasKey($meta, $answer, 'an unpaged answer keeps the pre-paging shape');
        }
    }

    public function testASubscriptionCanPageItsResetSnapshot(): void
    {
        $client = $this->fixture->client();
        $sessionId = $this->fixture->session($client);
        $full = $this->seed($client, $sessionId, 3);

        $answer = $client->call('session.subscribe', ['sessionId' => $sessionId, 'snapshotLimit' => 5]);
        $this->fixture->run();

        self::assertTrue($answer['reset']);
        self::assertSame(\array_slice($full, 1), $answer['snapshot']['messages']);
        self::assertSame(6, $answer['snapshot']['totalMessages']);
        self::assertSame(1, $answer['snapshot']['oldestIndex']);
        self::assertTrue($answer['snapshot']['hasMoreOlder']);
        self::assertSame($answer['throughSeq'], $answer['snapshot']['lastSeq'], 'the page still anchors on the same sequence as the whole');
    }

    public function testASubscriptionWithoutALimitKeepsTheWholeResetSnapshot(): void
    {
        $client = $this->fixture->client();
        $sessionId = $this->fixture->session($client);
        $full = $this->seed($client, $sessionId, 2);

        $answer = $client->call('session.subscribe', ['sessionId' => $sessionId]);
        $this->fixture->run();

        self::assertSame($full, $answer['snapshot']['messages']);
        self::assertArrayNotHasKey('hasMoreOlder', $answer['snapshot']);
    }

    public function testRefusedPagingCoordinatesCostACallNoFrame(): void
    {
        $client = $this->fixture->client();
        $sessionId = $this->fixture->session($client);
        $this->seed($client, $sessionId, 1);

        foreach ([
            ['sessionId' => $sessionId, 'limit' => 0],
            ['sessionId' => $sessionId, 'limit' => SessionSnapshot::MAX_PAGE_ROWS + 1],
            ['sessionId' => $sessionId, 'beforeIndex' => -1],
        ] as $params) {
            $answer = $client->request('session.get', $params);
            self::assertSame('invalid_params', $answer['error']['data']['kind'] ?? null, 'out-of-range paging is refused by the schema, not clamped silently');
        }

        self::assertSame('invalid_params', $client->request('session.subscribe', ['sessionId' => $sessionId, 'snapshotLimit' => 0])['error']['data']['kind'] ?? null);
    }

    /**
     * Run $turns whole turns (user + assistant rows each) and return the full
     * transcript rows the unparameterized answer carries.
     *
     * @return list<array<string, mixed>>
     */
    private function seed($client, string $sessionId, int $turns): array
    {
        for ($i = 0; $i < $turns; $i++) {
            $client->call('session.send', ['sessionId' => $sessionId, 'text' => 'prompt ' . $i]);
            $this->fixture->backend->settle(Message::assistant('reply ' . $i));
            $this->fixture->run();
        }

        return (array) $client->call('session.get', ['sessionId' => $sessionId])['messages'];
    }
}
