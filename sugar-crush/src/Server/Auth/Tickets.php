<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Server\Auth;

/**
 * Single-use WebSocket tickets (Appendix O §8.2).
 *
 * A browser cannot set an `Authorization` header on a WebSocket, and a
 * long-lived token in a query string ends up in logs and history. So the page
 * asks `/api/ticket` — cookie-authenticated, same-origin only — for a ticket,
 * and opens `/ws?ticket=…` with it. The ticket is good once, for
 * {@see TTL_SECONDS}, and only while the cookie session it was minted for is
 * still live.
 */
final class Tickets
{
    public const TTL_SECONDS = 30;

    private function __construct(private readonly ExpiringSecrets $tickets)
    {
    }

    /** @param (\Closure(): float)|null $clock */
    public static function new(?\Closure $clock = null): self
    {
        return new self(ExpiringSecrets::new(self::TTL_SECONDS, $clock, 256));
    }

    /** A ticket bound to the cookie session $sessionId. */
    public function mint(string $sessionId): string
    {
        return $this->tickets->mint($sessionId);
    }

    /** The cookie session id $ticket was bound to, spending it; null when unknown or expired. */
    public function consume(string $ticket): ?string
    {
        return $ticket === '' ? null : $this->tickets->consume($ticket);
    }

    public function pending(): int
    {
        return $this->tickets->count();
    }
}
