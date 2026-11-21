<?php

declare(strict_types=1);

namespace SugarCraft\Code\Server\Workspace;

/**
 * Something the {@see Gateway} fans a workspace host's events out to: a client
 * {@see ConnectionTarget} on the wire, or an in-process event sink
 * ({@see \SugarCraft\Code\Host\InProcessEventSink}). The gateway keeps every
 * subscriber, root client and durable cursor keyed by {@see id()}, so the two
 * kinds share one bookkeeping table (PL-5c-A design, section 3.1).
 */
interface RelayTarget
{
    /** The key of this target in the gateway's tables. Never collides with another target's. */
    public function id(): string;

    /** Whether the target is still connected. */
    public function isOpen(): bool;

    /** Whether the target takes an event of $sessionId (null for a server-scope event). Asked before any cursor moves. */
    public function accepts(?string $sessionId): bool;

    /** The sessions a viewer grant is pinned to, or null for an owner (no pin). */
    public function viewerSessions(): ?array;

    /**
     * One relayed event. $message is the whole JSON-RPC notification (null when it
     * could not be decoded); $text is its encoded form. $ephemeral marks a live-only
     * event that may be shed under overflow, coalesced by $coalesceKey when given.
     */
    public function deliver(?array $message, string $text, ?string $coalesceKey, bool $ephemeral, ?int $seq, ?string $sessionId): void;

    /** The gateway no longer forwards $sessionId to this target (its host was lost or retired). */
    public function unfollowed(string $sessionId): void;
}
