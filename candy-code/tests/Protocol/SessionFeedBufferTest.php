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
 *
 * CWPD-9 lives here too: the fan-out faults {@see SessionFeed::hear()}
 * swallows to keep the session's listener are now disclosed to the server's
 * log sink — a silently broken feed is worse than a logged one.
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

    /**
     * CWPD-9: a subscriber whose fan-out throws must not take the feed's
     * listener with it — and the fault must not vanish silently either. The
     * poison rides after the real client (fan-out walks array order), and a
     * pre-seeded deferred queue gives the catch's reset real work a later
     * announce cannot fake.
     */
    public function testAFanOutFaultIsDisclosedToTheLogAndCostsNobodyTheirListener(): void
    {
        $faults = [];
        $this->fixture->tearDown();
        $this->fixture = ProtocolFixture::new(log: static function (string $line) use (&$faults): void {
            $faults[] = $line;
        });

        $client = $this->fixture->client();
        $sessionId = $this->fixture->session($client);
        $client->call('session.subscribe', ['sessionId' => $sessionId]);
        $feed = $this->fixture->context->feed($sessionId);
        self::assertNotNull($feed);
        $this->fixture->run();

        $feedClass = new ReflectionClass(SessionFeed::class);
        $subscribers = $feedClass->getProperty('subscribers');
        $subscribers->setAccessible(true);
        $deferred = $feedClass->getProperty('deferred');
        $deferred->setAccessible(true);

        $live = $subscribers->getValue($feed);
        $live['poison'] = ['client' => new \stdClass(), 'mode' => 'full', 'live' => true, 'buffer' => [], 'lastSent' => -1, 'stale' => false];
        $subscribers->setValue($feed, $live);
        $deferred->setValue($feed, [$this->progress($sessionId, 'seeded', 1_699_999_999_999)]);

        $host = $this->fixture->hub->get($sessionId);
        self::assertNotNull($host);
        $host->announce($this->progress($sessionId, 'boom', 1_700_000_002_000));

        $told = \array_values(\array_filter($faults, static fn (string $line): bool => \str_contains($line, 'fan-out dropped')));
        self::assertCount(1, $told, 'the swallowed fault is logged exactly once');
        self::assertStringContainsString("session $sessionId fan-out dropped", $told[0]);
        self::assertStringContainsString('Call to undefined method stdClass::', $told[0], 'the line names the fault behind the silence');
        self::assertSame([], $deferred->getValue($feed), 'the catch still clears the deferred queue');

        // The listener survived: a later event still reaches the real client,
        // and the poison earns a second line — not a detached feed.
        $host->announce($this->progress($sessionId, 'after', 1_700_000_003_000));
        self::assertSame(
            ['boom', 'after'],
            \array_map(static fn (array $e): string => (string) $e['data']['callId'], $client->events('tool.progress')),
            'the healthy subscriber hears past the fault, in order',
        );
        self::assertCount(2, \array_filter($faults, static fn (string $line): bool => \str_contains($line, 'fan-out dropped')));
        self::assertSame(2, $feed->subscriberCount(), 'the fault detached nobody');
    }

    private function progress(string $sessionId, string $callId, int $tsMs): SessionEvent
    {
        return SessionEvent::new(SessionEvent::TOOL_PROGRESS, ['name' => 'Read', 'callId' => $callId], $sessionId, null, $tsMs);
    }
}
