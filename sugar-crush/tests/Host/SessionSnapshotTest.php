<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Host;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Host\SessionSnapshot;
use SugarCraft\Crush\Message;

/**
 * Roadmap O-2g / Appendix O §4.3 and §6.8: a snapshot is what a client takes
 * before following a session's events from its `lastSeq` on.
 */
final class SessionSnapshotTest extends TestCase
{
    public function testTheWireShapeCarriesRowsStatusQueueAndCursor(): void
    {
        $snapshot = SessionSnapshot::new('s', [Message::user('hi')], ['next'], true, 't_1', 0.25, 7);

        self::assertSame('busy', $snapshot->status());
        $wire = $snapshot->toArray();
        self::assertSame('s', $wire['sessionId']);
        self::assertSame('busy', $wire['status']);
        self::assertSame('t_1', $wire['turnId']);
        self::assertSame('hi', $wire['messages'][0]['content']);
        self::assertSame('user', $wire['messages'][0]['role']);
        self::assertSame(['next'], $wire['queued']);
        self::assertSame(0.25, $wire['spentUsd']);
        self::assertSame(7, $wire['lastSeq']);
    }

    public function testAnIdleSnapshotOmitsTheTurn(): void
    {
        $wire = SessionSnapshot::new('s')->toArray();

        self::assertSame('idle', $wire['status']);
        self::assertArrayNotHasKey('turnId', $wire);
        self::assertSame([], $wire['messages']);
    }
}
