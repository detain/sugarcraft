<?php

declare(strict_types=1);

namespace SugarCraft\Code\Server\Workspace;

use SugarCraft\Code\Protocol\Dispatcher;
use SugarCraft\Code\Server\Ws\Connection;

/**
 * A wire client as a {@see RelayTarget}: the frames go through the connection's
 * {@see \SugarCraft\Code\Server\Ws\Outbox}, a durable event as an ordinary frame and a
 * live-only one through `pushEphemeral`, exactly as the gateway sent them before the
 * relay targets were an interface.
 */
final class ConnectionTarget implements RelayTarget
{
    public function __construct(
        private readonly Connection $connection,
        private readonly Dispatcher $primary,
    ) {
    }

    public function id(): string
    {
        return $this->connection->id();
    }

    public function isOpen(): bool
    {
        return $this->connection->isOpen();
    }

    /** A connection takes every event; its viewer pin is checked by the gateway. */
    public function accepts(?string $sessionId): bool
    {
        return true;
    }

    public function viewerSessions(): ?array
    {
        return $this->connection->viewerSessions();
    }

    public function deliver(?array $message, string $text, ?string $coalesceKey, bool $ephemeral, ?int $seq, ?string $sessionId): void
    {
        $outbox = $this->primary->outboxFor($this->connection);
        if (!$ephemeral || $message === null) {
            // Unregistered means closed: nothing queues for a gone peer.
            $outbox?->push($text);

            return;
        }
        $outbox?->pushEphemeral($message, $coalesceKey);
    }

    /** A wire client is told by the overflow notice it is sent, not by the gateway's bookkeeping. */
    public function unfollowed(string $sessionId): void
    {
    }
}
