<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Server;

use PHPUnit\Framework\TestCase;
use React\EventLoop\Loop;
use SugarCraft\Crush\Protocol\JsonRpc;
use SugarCraft\Crush\Server\Ws\Outbox;
use SugarCraft\Crush\Server\Ws\ProtocolPendingHandler;
use SugarCraft\Crush\Tests\Server\Support\WireClient;

/**
 * Roadmap O-3b, Appendix O §6.9: a client that cannot keep up costs its own
 * {@see Outbox} memory and nothing else. A congested socket is handed nothing
 * more until it drains; past the soft watermark queued deltas of one part are
 * merged; past the hard watermark ephemeral events are shed for one overflow
 * notice, durable ones never are, and a queue that stays over the mark closes
 * the socket with 1013.
 */
final class BackpressureTest extends TestCase
{
    public function testACongestedSocketIsHandedNothingUntilItDrains(): void
    {
        $client = WireClient::open(new ProtocolPendingHandler());
        $outbox = Outbox::for($client->connection, Loop::get());
        $client->toClient->pause();

        $outbox->push('{"n":1}');
        self::assertTrue($client->connection->isCongested(), 'the write found the buffer full');
        $outbox->push('{"n":2}');
        $outbox->push('{"n":3}');

        self::assertSame(2, $outbox->count(), 'the rest waits in the outbox');
        $client->toClient->resume();

        self::assertSame(0, $outbox->count());
        self::assertSame([1, 2, 3], \array_column($client->received(), 'n'), 'in order, nothing lost');
    }

    public function testPastTheSoftMarkDeltasOfOnePartAreMerged(): void
    {
        $client = WireClient::open(new ProtocolPendingHandler());
        $outbox = Outbox::for($client->connection, Loop::get(), null, 64, 100_000);
        $client->toClient->pause();
        $outbox->push(\str_repeat('x', 80));

        $outbox->pushEphemeral(self::delta('He', 0), 'p1');
        $outbox->pushEphemeral(self::delta('llo', 2), 'p1');
        $outbox->pushEphemeral(self::delta(' there', 5), 'p1');
        $outbox->pushEphemeral(self::delta('!', 0), 'p2');

        self::assertTrue($outbox->isAboveSoft());
        self::assertSame(2, $outbox->count(), 'three deltas of p1 became one, beside p2\'s');
        $client->toClient->resume();

        $deltas = \array_values(\array_filter($client->received(), static fn (array $m): bool => ($m['method'] ?? null) === 'event'));
        self::assertSame('Hello there', $deltas[0]['params']['data']['text']);
        self::assertSame(0, $deltas[0]['params']['data']['offset'], 'the first offset is kept');
        self::assertSame('!', $deltas[1]['params']['data']['text']);
    }

    public function testPastTheHardMarkEphemeralsAreShedForOneNoticeAndDurablesKept(): void
    {
        $client = WireClient::open(new ProtocolPendingHandler());
        $outbox = Outbox::for($client->connection, Loop::get(), static fn (int $dropped): string => '{"overflow":' . $dropped . '}', 10, 400, 60.0);
        $client->toClient->pause();
        $outbox->push('{"durable":0}');

        for ($i = 1; $i <= 20; $i++) {
            $outbox->pushEphemeral(self::delta(\str_repeat('y', 20), $i));
        }
        $outbox->push('{"durable":1}');

        self::assertTrue($outbox->isOverflowing());
        self::assertGreaterThan(0, $outbox->dropped());
        $client->toClient->resume();

        $received = $client->received();
        self::assertSame([0, 1], \array_column($received, 'durable'), 'durable messages are never shed');
        self::assertCount(1, \array_column($received, 'overflow'), 'one notice says how many were dropped');
        self::assertFalse($outbox->isOverflowing(), 'a drained queue leaves overflow');
        $this->runLoop(0.01);
        self::assertTrue($client->connection->isOpen());
    }

    public function testAQueueThatStaysOverTheHardMarkClosesTheSocketWith1013(): void
    {
        $client = WireClient::open(new ProtocolPendingHandler());
        $outbox = Outbox::for($client->connection, Loop::get(), null, 10, 100, 0.02);
        $client->toClient->pause();
        $outbox->push(\str_repeat('z', 50));
        $outbox->push(\str_repeat('z', 150));
        self::assertTrue($outbox->isOverflowing());

        $this->runLoop(0.1);

        self::assertFalse($client->connection->isOpen());
        self::assertSame(0, $outbox->count(), 'nothing is held for a closed socket');
        $client->toClient->resume();
        self::assertSame([Outbox::CLOSE_TRY_AGAIN_LATER], $client->closeCodes());
    }

    public function testWatermarksMustBeOrdered(): void
    {
        $client = WireClient::open(new ProtocolPendingHandler());

        $this->expectException(\InvalidArgumentException::class);
        Outbox::for($client->connection, Loop::get(), null, 100, 10);
    }

    /** @return array<string, mixed> */
    private static function delta(string $text, int $offset): array
    {
        return JsonRpc::notification('event', ['sessionId' => 's', 'type' => 'assistant.delta', 'durable' => false, 'data' => ['text' => $text, 'offset' => $offset]]);
    }

    private function runLoop(float $seconds): void
    {
        $timer = Loop::addTimer($seconds, static fn () => Loop::stop());
        Loop::run();
        Loop::cancelTimer($timer);
    }
}
