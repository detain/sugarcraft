<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Protocol;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Host\SessionEvent;
use SugarCraft\Code\Protocol\EventType;
use SugarCraft\Code\Protocol\SessionFeed;
use SugarCraft\Code\Tests\Server\Support\ProtocolFixture;

/**
 * S-O18: the pre-live ring is capped in bytes as well as count, so a few
 * hundred fat frames cannot pin memory behind a stalled catch-up.
 */
final class SessionFeedPreliveByteCapTest extends TestCase
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

    public function testFatFramesTripTheByteCeilingFarBelowTheCountCap(): void
    {
        $client = $this->fixture->client();
        $sessionId = $this->fixture->session($client);
        $client->call('session.subscribe', ['sessionId' => $sessionId]);
        $host = $this->fixture->hub->get($sessionId);
        self::assertNotNull($host);

        $fat = \str_repeat('x', 200_000);
        $sent = 0;
        for ($i = 0; $i < 20; ++$i) {
            $host->announce($this->progress($sessionId, $fat, 1_700_000_000_000 + $i));
            ++$sent;
        }

        $overflow = $client->events(EventType::SERVER_OVERFLOW);
        self::assertCount(1, $overflow, 'byte ceiling trips exactly once, long before 2048 frames');
        self::assertSame('resubscribe', $overflow[0]['data']['action']);
        self::assertLessThan(10, $overflow[0]['data']['dropped']);
        self::assertGreaterThan(0, $overflow[0]['data']['dropped']);
        self::assertSame(1_048_576, SessionFeed::MAX_PRELIVE_BYTES);
    }

    public function testSmallFramesUnderTheByteCeilingStillFlush(): void
    {
        $client = $this->fixture->client();
        $sessionId = $this->fixture->session($client);
        $client->call('session.subscribe', ['sessionId' => $sessionId]);
        $host = $this->fixture->hub->get($sessionId);
        self::assertNotNull($host);

        for ($i = 0; $i < 3; ++$i) {
            $host->announce($this->progress($sessionId, 'ok', 1_700_000_000_000 + $i));
        }
        $this->fixture->run();

        self::assertCount(0, $client->events(EventType::SERVER_OVERFLOW));
        self::assertCount(3, $client->events('tool.progress'));
    }

    private function progress(string $sessionId, string $text, int $ts): SessionEvent
    {
        return SessionEvent::new(SessionEvent::TOOL_PROGRESS, ['name' => 'Read', 'callId' => $text], $sessionId, null, $ts);
    }
}
