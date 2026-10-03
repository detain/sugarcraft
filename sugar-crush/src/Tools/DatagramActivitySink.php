<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tools;

use SugarCraft\Crush\Events\SubAgentActivity;

/**
 * The child's half of a per-member activity relay: each beat is ONE datagram
 * on a unix `SOCK_DGRAM` socket — the beat's v2 wire array
 * ({@see SubAgentActivity::toArray()}, the same shape the engine's own
 * `subagent` frame carries), `serialize()`d and decoded on the other side
 * with `allowed_classes => false` because the bytes crossed a process
 * boundary.
 *
 * WHY DATAGRAMS. A datagram is delivered whole or not at all, so the reader
 * needs no length prefix and no reassembly buffer, a child killed mid-write
 * leaves nothing half-written on the wire, and a beat the reader cannot parse
 * costs that one beat instead of everything after it. It is also what lets
 * more than one writer (a nested delegation) share a sink without
 * interleaving bytes.
 *
 * NEVER BLOCKS THE RUN. The socket is non-blocking: when the reader has
 * stopped draining and the queue is full, or a beat is larger than
 * {@see MAX_DATAGRAM_BYTES}, the beat is dropped and the run carries on. The
 * forking parent covers a lost terminal beat itself — it synthesises
 * `finished` for any run whose process exited without one reaching it
 * ({@see \SugarCraft\Crush\Support\SubAgentActivityRelay::unfinished()}).
 */
final class DatagramActivitySink implements ActivitySink
{
    /**
     * Ceiling on one beat. The reader receives into a buffer of exactly this
     * size, so a larger datagram would arrive truncated and fail to decode;
     * refusing it here keeps the failure on the side that can see it.
     */
    public const MAX_DATAGRAM_BYTES = 64 * 1024;

    /**
     * @param resource $socket the child's end of a SOCK_DGRAM pair
     */
    private function __construct(private mixed $socket)
    {
    }

    /**
     * @param resource $socket the child's end of a SOCK_DGRAM pair
     */
    public static function over(mixed $socket): self
    {
        if (is_resource($socket)) {
            stream_set_blocking($socket, false);
        }

        return new self($socket);
    }

    public function emit(SubAgentActivity $activity): void
    {
        if (!is_resource($this->socket)) {
            return;
        }

        $body = serialize($activity->toArray());
        if (strlen($body) > self::MAX_DATAGRAM_BYTES) {
            return;
        }

        @stream_socket_sendto($this->socket, $body);
    }
}
