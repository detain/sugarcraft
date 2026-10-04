<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Host;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Host\TurnTicket;

/**
 * Roadmap O-2g / Appendix O §6.6: a ticket is the ADMISSION of a submission
 * — started, queued, steered, pending or refused — separate from the durable
 * story of the turn it starts.
 */
final class TurnTicketTest extends TestCase
{
    public function testEachAdmissionCarriesItsOwnFields(): void
    {
        self::assertSame(
            ['admitted' => 'started', 'turnId' => 't_1', 'messageId' => 'm_1'],
            TurnTicket::started('t_1', 'm_1')->toArray(),
        );
        self::assertSame(['admitted' => 'queued', 'position' => 2], TurnTicket::queued(2)->toArray());
        self::assertSame(
            ['admitted' => 'steered', 'turnId' => 't_1', 'steerId' => 'st_1', 'position' => 1],
            TurnTicket::steered('t_1', 'st_1', 1)->toArray(),
        );
        self::assertSame(['admitted' => 'pending'], TurnTicket::pending()->toArray());
        self::assertSame(['admitted' => 'refused', 'reason' => 'no'], TurnTicket::refused('no')->toArray());
    }

    public function testOnlyARefusalIsNotAdmittedAndTheKeyIsEchoed(): void
    {
        self::assertFalse(TurnTicket::refused('no')->isAdmitted());
        self::assertTrue(TurnTicket::queued(1)->isAdmitted());
        self::assertTrue(TurnTicket::pending()->isAdmitted());
        self::assertSame('k', TurnTicket::queued(1)->withIdempotencyKey('k')->toArray()['idempotencyKey']);
    }

    public function testAnUnknownAdmissionIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        TurnTicket::new('maybe');
    }
}
