<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Server;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Host\SessionEvent;
use SugarCraft\Code\Tests\Server\Support\ProtocolFixture;
use SugarCraft\Code\Tests\Server\Support\WireClient;

/**
 * ISS-056: a built-in or `!bang` that ran in the host was answered as a
 * refusal (-32009) and its transcript rows emitted no event, so a driver saw
 * silence where the session had actually answered. The admission now reads
 * `handled`, and every row the command saved is announced as the
 * `message.created` a client renders — with the busy/idle window of a bang
 * marked in `session.status`.
 */
final class CommandRowsReachDriversTest extends TestCase
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

    /** @return array{WireClient, string} a subscribed client and its session id */
    private function subscribed(): array
    {
        $client = $this->fixture->client();
        $sessionId = $this->fixture->session($client);
        $client->call('session.subscribe', ['sessionId' => $sessionId]);
        $this->fixture->run();
        $client->clear();

        return [$client, $sessionId];
    }

    public function testABuiltInThatRanHereIsAdmittedAndItsRowsAreAnnounced(): void
    {
        [$client, $sessionId] = $this->subscribed();

        $sent = $client->call('session.send', ['sessionId' => $sessionId, 'text' => '/notices']);

        self::assertSame('handled', $sent['admitted'], 'a command that ran here was admitted, not refused');

        $created = $client->events(SessionEvent::MESSAGE_CREATED);
        self::assertCount(2, $created, 'the echo and the report each announce their row');
        self::assertSame('/notices', $created[0]['data']['content']);
        self::assertSame('user', $created[0]['data']['kind']);
        self::assertNotSame('', $created[1]['data']['content']);
        self::assertSame('assistant', $created[1]['data']['kind']);
        foreach ($created as $event) {
            self::assertSame($sessionId, $event['sessionId']);
            self::assertIsInt($event['data']['ref']);
            self::assertNotSame('', $event['data']['messageId']);
        }
    }

    public function testABangIsAdmittedItsWindowIsMarkedAndItsResultLands(): void
    {
        [$client, $sessionId] = $this->subscribed();

        $sent = $client->call('session.send', ['sessionId' => $sessionId, 'text' => '!echo admitted-here']);
        self::assertSame('handled', $sent['admitted']);

        $settled = false;
        for ($tick = 0; $tick < 400; $tick++) {
            $this->fixture->run(0.05);
            $rows = $client->events(SessionEvent::MESSAGE_CREATED);
            if (\count($rows) >= 2 && str_contains((string) $rows[1]['data']['content'], 'admitted-here')) {
                $settled = true;
                break;
            }
        }
        self::assertTrue($settled, 'the bang result never landed as an announced row');

        $rows = $client->events(SessionEvent::MESSAGE_CREATED);
        self::assertSame('notice', $rows[0]['data']['kind'], 'the running notice is a system row');
        self::assertSame('user', $rows[1]['data']['kind'], 'the result row is the user-role row the model reads');

        $types = \array_map(static fn (array $e): string => (string) $e['type'] . ':' . (string) ($e['data']['status'] ?? ''), $client->events());
        $busy = \array_search('session.status:busy', $types, true);
        $idle = \array_search('session.status:idle', $types, true);
        self::assertIsInt($busy, 'the bang marked the session busy without a turn to say so');
        self::assertIsInt($idle, 'and idle once the result landed');
        self::assertLessThan($idle, $busy, 'busy precedes idle');
    }
}
