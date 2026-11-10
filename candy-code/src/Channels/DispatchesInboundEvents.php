<?php

declare(strict_types=1);

namespace SugarCraft\Code\Channels;

/**
 * A channel that dispatches inbound platform events through the durable
 * ledger — the capability seam spec §9.5 Track B hangs the core guarantee
 * on. Same shape as {@see AcceptsHttpRequests}: an optional capability
 * extending {@see ChannelInterface}, PHP-level only, never a protocol
 * method (the wire surface stays byte-frozen).
 *
 * Contract: the ledger persists the event first ({@see
 * ChannelInboundLog::record()} answering RECORDED) and hands the stored
 * payload to {@see dispatchInboundEvent()} — on boot, for every row still
 * pending when the previous process died between persist and dispatch.
 * Returning true acks the row; returning false or throwing notes the
 * failure, and the row dead-letters after the ledger's own attempt/age
 * posture. A channel must therefore make this method idempotency-safe for
 * at-least-once traffic: the ledger guarantees it never sees a DUPLICATE
 * key twice, but a REPLAY after a crash is the same event delivered again
 * by design (the platform side mirrors this with "first delivery wins for
 * one platform message id").
 *
 * @see ChannelInboundLog
 */
interface DispatchesInboundEvents extends ChannelInterface
{
    /**
     * Deliver one stored inbound payload. True acks it; false/throw notes a
     * failure attempt against the dead-letter count.
     *
     * @param array<string, mixed> $payload the redacted, size-capped body the ledger stored
     */
    public function dispatchInboundEvent(array $payload): bool;
}
