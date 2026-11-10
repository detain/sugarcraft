<?php

declare(strict_types=1);

namespace SugarCraft\Code\Channels;

/**
 * The GAP-3 door: a channel that hears the served session's own event
 * stream, in-process, WITH message text and deltas (spec §10.3 "event
 * subscribers" row — the text-egress door §6.4 governs).
 *
 * The envelope is exactly {@see \SugarCraft\Code\Host\SessionEvent::toArray()}
 * with a guaranteed non-null `sessionId` key: the same shape the wire
 * frames, never a protocol addition (P1 adds ZERO protocol methods or
 * events — `tools/gen-protocol-schema.php --check` stays green UNTOUCHED
 * through this seam). The seam enforces the JSON-serializable payload law
 * before delivery: an envelope that fails a json round-trip is refused at
 * the door, never handed over and never silently truncated.
 *
 * Delivery is live-only and best-effort: a throw from onEvent() is logged
 * and swallowed (the CWPD-9 feed law — the listener must survive a
 * faulting consumer), and a channel disposed at serve shutdown receives
 * nothing further.
 */
interface SubscribesToSessionEvents extends ChannelInterface
{
    /**
     * @param array<string, mixed> $envelope JSON-serializable session event
     *        envelope; `type`, `sessionId`, `ts` and `data` are always
     *        present, `seq` only on durable events the log has numbered.
     */
    public function onEvent(array $envelope): void;
}
