<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Protocol;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use SugarCraft\Code\Host\SessionEvent;
use SugarCraft\Code\Protocol\EventType;
use SugarCraft\Code\Protocol\SessionFeed;
use SugarCraft\Code\Tests\Server\Support\ProtocolFixture;

/**
 * CWPD-8: what a subscriber hoards between registering and going live used
 * to be unbounded — a client whose catch-up stalled while the session ran
 * grew its buffer forever. Past the cap the client is told once, exactly the
 * way the Gateway's outbox watermark and the dispatcher's pending-notice
 * overflow already say it (`dropped` + `action: resubscribe`), the hoard is
 * thrown away, and the subscription ends at go-live. The client's way back
 * is a resubscribe for a fresh snapshot — no gap repair is attempted.
 */
final class SessionFeedBufferTest extends TestCase
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

    public function testAStalledCatchUpEndsTheSubscriptionAndNamesTheDrop(): void
    {
        $client = $this->fixture->client();
        $sessionId = $this->fixture->session($client);
        $client->call('session.subscribe', ['sessionId' => $sessionId]);

        $feed = $this->fixture->context->feed($sessionId);
        self::assertNotNull($feed, 'fixture: subscribing attached the driver feed');

        // Catch-up gets no loop tick, so everything heard meanwhile piles into
        // the pre-live buffer — past the cap the client is told, exactly once.
        $host = $this->fixture->hub->get($sessionId);
        self::assertNotNull($host);
        $cap = 2048;
        for ($i = 0; $i < $cap + 5; ++$i) {
            $host->announce($this->progress($sessionId, 'c' . $i, 1_700_000_000_000 + $i));
        }

        $overflow = $client->events(EventType::SERVER_OVERFLOW);
        self::assertCount(1, $overflow, 'the stale notice arrives exactly once, not once per later beat');

        $cap = (new ReflectionClass(SessionFeed::class))->getConstant('MAX_PRELIVE_BUFFER');
        self::assertSame(2048, $cap, 'the buffer budget is a deliberate ceiling');
        self::assertSame(['sessionId' => $sessionId, 'dropped' => $cap + 1, 'action' => 'resubscribe'], $overflow[0]['data']);
        self::assertCount(0, $client->events('tool.progress'), 'the hoard is discarded, not delivered late');

        // Let the scheduled catch-up complete: the stale subscription ends there.
        $this->fixture->run();
        self::assertSame(0, $feed->subscriberCount(), 'a stale-marked subscriber is dropped at go-live');

        // And it stays gone: later events reach nobody on that subscription.
        $seen = \count($client->events());
        $host->announce($this->progress($sessionId, 'late', 1_700_000_001_000));
        self::assertSame($seen, \count($client->events()), 'a dropped subscription hears nothing further');

        // The way back is the documented one: resubscribe, take a snapshot.
        $again = $client->call('session.subscribe', ['sessionId' => $sessionId]);
        self::assertTrue($again['reset']);
        self::assertArrayHasKey('snapshot', $again);
        $this->fixture->run();
        self::assertSame(1, $feed->subscriberCount(), 'the resubscribed client is live again');
    }

    public function testABufferUnderTheCapFlushesNormally(): void
    {
        $client = $this->fixture->client();
        $sessionId = $this->fixture->session($client);
        $client->call('session.subscribe', ['sessionId' => $sessionId]);
        $feed = $this->fixture->context->feed($sessionId);
        self::assertNotNull($feed);
        $host = $this->fixture->hub->get($sessionId);
        self::assertNotNull($host);

        for ($i = 0; $i < 3; ++$i) {
            $host->announce($this->progress($sessionId, 'c' . $i, 1_700_000_000_000 + $i));
        }
        self::assertCount(0, $client->events(EventType::SERVER_OVERFLOW), 'fixture: under the cap says nothing');

        $this->fixture->run();

        self::assertSame(1, $feed->subscriberCount());
        self::assertCount(3, $client->events('tool.progress'), 'what a short stall hoarded still rides the flush');
    }

    private function progress(string $sessionId, string $callId, int $tsMs): SessionEvent
    {
        return SessionEvent::new(SessionEvent::TOOL_PROGRESS, ['name' => 'Read', 'callId' => $callId], $sessionId, null, $tsMs);
    }
}
