<?php

declare(strict_types=1);

namespace SugarCraft\Code\Host;

use SugarCraft\Code\Server\Ws\Peer;

/**
 * The identity of the in-process host (PL-5c): a peer with no socket and no
 * credential. It holds the owner's scopes, because the host it runs inside is
 * the owner's own process, and it is never a viewer, so no session pin applies.
 */
final class InProcessPeer implements Peer
{
    private function __construct(private readonly string $id)
    {
    }

    /** A peer with an id no other peer of this server shares. */
    public static function new(): self
    {
        return new self('in-process-' . \bin2hex(\random_bytes(6)));
    }

    public function id(): string
    {
        return $this->id;
    }

    public function principal(): string
    {
        return 'in-process';
    }

    public function cookieSession(): ?string
    {
        return null;
    }

    public function tokenId(): ?string
    {
        return null;
    }

    public function viewerSessions(): ?array
    {
        return null;
    }
}
