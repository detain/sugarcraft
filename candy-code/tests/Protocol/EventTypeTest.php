<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Protocol;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Host\SessionEvent;
use SugarCraft\Code\Protocol\EventEnvelope;
use SugarCraft\Code\Protocol\EventType;
use SugarCraft\Code\Protocol\Schema\EventSchemas;

/**
 * The event catalogue a client is promised (`server.hello` `features.events`)
 * agrees with what the host actually produces and logs: every type the host
 * names is catalogued, and the durable column is the host's own.
 */
final class EventTypeTest extends TestCase
{
    public function testEveryHostEventTypeIsCataloguedWithTheHostsDurability(): void
    {
        $constants = (new \ReflectionClass(SessionEvent::class))->getConstants();
        $types = \array_filter($constants, static fn (mixed $value, string $name): bool => \is_string($value)
            && \str_contains($value, '.') && !\str_starts_with($name, 'STOP_'), \ARRAY_FILTER_USE_BOTH);

        self::assertNotEmpty($types);
        foreach ($types as $name => $type) {
            self::assertTrue(EventType::isKnown($type), $name . ' is missing from the catalogue');
            self::assertSame(SessionEvent::typeIsDurable($type), EventType::isDurable($type), $type . ' durability disagrees with the host');
            self::assertSame(EventType::SCOPE_SESSION, EventType::scope($type));
        }
        foreach (EventType::all() as $type) {
            self::assertNotSame('', EventType::description($type));
            if (EventType::scope($type) === EventType::SCOPE_SERVER) {
                self::assertFalse(EventType::isDurable($type), $type . ': there is no server log to replay it from');
            }
        }
    }

    public function testAnEnvelopeHasOneShapeLiveOrReplayed(): void
    {
        $live = EventEnvelope::fromSessionEvent(
            SessionEvent::new(SessionEvent::TURN_STARTED, ['messageId' => 'm1'], 's1', 't1', 1000)->withSeq(7),
        );
        $replayed = EventEnvelope::fromLogRow('s1', ['seq' => 7, 'ts' => 1000, 'type' => 'turn.started', 'payload' => ['turnId' => 't1', 'messageId' => 'm1']]);

        self::assertSame($live->toArray(), $replayed->toArray());
        self::assertSame(['sessionId' => 's1', 'seq' => 7, 'type' => 'turn.started', 'ts' => 1000, 'turnId' => 't1', 'durable' => true, 'data' => ['messageId' => 'm1']], $live->toArray());
        self::assertTrue($live->isNumbered());
    }

    /**
     * C-CTX-2.d: the live-only pair must stay out of the session log — a
     * replay of a stale ghost suggestion or a redundant re-read cue would be
     * worse than silence — while still being schema-typed on the wire.
     */
    public function testTheLiveOnlyPairIsCataloguedEphemeralWithADataSchema(): void
    {
        foreach ([SessionEvent::PROMPT_SUGGESTED, SessionEvent::CONTEXT_CHANGED] as $type) {
            self::assertTrue(EventType::isKnown($type));
            self::assertFalse(EventType::isDurable($type), $type . ' must never enter the session log');
            self::assertFalse(\in_array($type, SessionEvent::DURABLE_TYPES, true), $type . ' joined the host durable column');
            self::assertSame(EventType::SCOPE_SESSION, EventType::scope($type));
            self::assertNotNull(EventSchemas::data($type), $type . ' has no data schema');
        }
    }

    public function testAnEphemeralOrServerEventCarriesNoSeq(): void
    {
        $delta = EventEnvelope::fromSessionEvent(SessionEvent::new(SessionEvent::ASSISTANT_DELTA, ['text' => 'x'], 's1', 't1'));
        $tick = EventEnvelope::server(EventType::SERVER_TICK, ['now' => 1]);

        self::assertArrayNotHasKey('seq', $delta->toArray());
        self::assertFalse($delta->isNumbered());
        self::assertNull($tick->toArray()['sessionId']);
        self::assertSame('event', $tick->notification()['method']);
    }
}
