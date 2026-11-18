<?php

declare(strict_types=1);

namespace SugarCraft\Plugins\Channel;

/**
 * A channel that dispatches inbound platform events through a durable ledger.
 *
 * The host persists each event before it is dispatched and hands the stored
 * payload to dispatchInboundEvent(). On boot it replays every row still pending
 * when the previous process died between persist and dispatch. Returning true
 * acks the row. Returning false or throwing records a failed attempt, and the
 * host dead-letters the row after its own attempt and age limits.
 *
 * Delivery is at-least-once. The ledger never hands over the same key twice,
 * but a replay after a crash delivers the same event again by design. A channel
 * must therefore make dispatchInboundEvent() idempotent for its own platform
 * message ids.
 */
interface DispatchesInboundEvents extends ChannelInterface
{
    /**
     * Deliver one stored inbound payload. True acks it; false or a throw records
     * a failed attempt against the dead-letter count.
     *
     * @param array<string, mixed> $payload the redacted, size-capped body the ledger stored
     */
    public function dispatchInboundEvent(array $payload): bool;
}
