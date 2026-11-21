<?php

declare(strict_types=1);

namespace SugarCraft\Plugins\Channel;

/**
 * A channel that hears the host session's own event stream, in-process, with
 * message text and deltas included.
 *
 * The envelope is a JSON-serializable array with `type`, `sessionId`, `ts` and
 * `data` always present, and `seq` only on durable events the host has numbered.
 * The host enforces the payload law before delivery: an envelope that fails a
 * JSON round-trip is refused at the door, never handed over and never silently
 * truncated.
 *
 * Delivery is live-only and best-effort. A throw from onEvent() is logged and
 * swallowed by the host, so a faulting consumer cannot break the feed. A channel
 * disposed at shutdown receives nothing further.
 */
interface SubscribesToSessionEvents extends ChannelInterface
{
    /**
     * @param array<string, mixed> $envelope JSON-serializable session event envelope
     */
    public function onEvent(array $envelope): void;
}
