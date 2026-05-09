<?php

declare(strict_types=1);

namespace SugarCraft\Vcr\Tests\Msg;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Core\Msg\WindowSizeMsg;
use SugarCraft\Vcr\Cassette;
use SugarCraft\Vcr\CassetteHeader;
use SugarCraft\Vcr\Event;
use SugarCraft\Vcr\EventKind;
use SugarCraft\Vcr\Format\JsonlFormat;
use SugarCraft\Vcr\Msg\Registry;

/**
 * Verifies the Msg serializer envelope round-trips cleanly through the
 * cassette format: encode → JSONL → decode → assert Msg equality.
 *
 * The PR2 Recorder records raw input bytes; PR3 introduces this Msg
 * envelope as an alternative `input` payload. Both forms can coexist
 * in the cassette format; the Player (PR4) decides which to consume.
 */
final class SerializerCassetteIntegrationTest extends TestCase
{
    public function testKeyMsgEnvelopeRoundTripsThroughJsonl(): void
    {
        $registry = Registry::default();
        $original = new KeyMsg(KeyType::Char, 'q', alt: false, ctrl: true);

        $envelope = $registry->encode($original);
        $this->assertNotNull($envelope);

        $cassette = new Cassette(
            new CassetteHeader(1, '2026-05-08T12:00:00Z', 80, 24, 'sugarcraft/candy-vcr@dev'),
            [new Event(t: 0.1, kind: EventKind::Input, payload: ['msg' => $envelope])],
        );

        $format = new JsonlFormat();
        $loaded = $format->decode($format->encode($cassette));

        $this->assertSame(1, $loaded->eventCount());
        $msg = $registry->decode($loaded->events[0]->payload['msg']);
        $this->assertInstanceOf(KeyMsg::class, $msg);
        $this->assertSame('q', $msg->rune);
        $this->assertTrue($msg->ctrl);
    }

    public function testMixedInputEventsRoundTrip(): void
    {
        $registry = Registry::default();

        $cassette = new Cassette(
            new CassetteHeader(1, '2026-05-08T12:00:00Z', 80, 24, 'sugarcraft/candy-vcr@dev'),
            [
                // PR2-style raw bytes
                new Event(t: 0.1, kind: EventKind::Input, payload: ['b' => 'q']),
                // PR3-style Msg envelope
                new Event(t: 0.2, kind: EventKind::Input, payload: [
                    'msg' => $registry->encode(new WindowSizeMsg(80, 24)),
                ]),
            ],
        );

        $format = new JsonlFormat();
        $loaded = $format->decode($format->encode($cassette));

        $this->assertSame('q', $loaded->events[0]->payload['b']);
        $msg = $registry->decode($loaded->events[1]->payload['msg']);
        $this->assertInstanceOf(WindowSizeMsg::class, $msg);
        $this->assertSame(80, $msg->cols);
        $this->assertSame(24, $msg->rows);
    }

    public function testUserJsonableMsgEnvelopeRoundTrips(): void
    {
        $registry = Registry::default();
        $original = new UserJsonableMsg(name: 'tap', count: 42);

        $envelope = $registry->encode($original);
        $this->assertNotNull($envelope);

        $cassette = new Cassette(
            new CassetteHeader(1, '2026-05-08T12:00:00Z', 80, 24, 'sugarcraft/candy-vcr@dev'),
            [new Event(t: 0.0, kind: EventKind::Input, payload: ['msg' => $envelope])],
        );

        $format = new JsonlFormat();
        $loaded = $format->decode($format->encode($cassette));
        $msg = $registry->decode($loaded->events[0]->payload['msg']);

        $this->assertInstanceOf(UserJsonableMsg::class, $msg);
        $this->assertSame('tap', $msg->name);
        $this->assertSame(42, $msg->count);
    }
}
