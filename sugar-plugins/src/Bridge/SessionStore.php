<?php

declare(strict_types=1);

namespace SugarCraft\Plugins\Bridge;

/**
 * The durable side of the router: conversation bindings, per-session event
 * cursors, the last-known reply address for each session, and the inbound
 * claim ledger that drops platform redeliveries. Implementations persist;
 * the router only asks.
 */
interface SessionStore
{
    /** Claim a platform inbound id for dispatch; false when it was already claimed. */
    public function claimInbound(string $platform, string $inboundId): bool;

    /** Mark a claimed inbound as dispatched, so a later redelivery stays suppressed. */
    public function confirmInbound(string $platform, string $inboundId): void;

    /** Release a conversation's binding. */
    public function forget(string $key): void;

    /** The session bound to a conversation key, or null. */
    public function sessionIdFor(string $key): ?string;

    /** Bind a conversation key to a session. */
    public function bind(string $key, string $sessionId, ?string $peerId = null): void;

    /**
     * Every live binding as rows carrying at least "sessionId" and "key".
     *
     * @return array<int,array<string,mixed>>
     */
    public function allBindings(): array;

    /** The highest durable sequence applied for a session. */
    public function cursorForSession(string $sessionId): int;

    /** Record an applied durable sequence for a session. */
    public function noteSeqForSession(string $sessionId, int $seq): void;

    /** Remember the conversation a session last answered, with its reply address. */
    public function rememberConversation(string $sessionId, string $key, array $ref): void;

    /**
     * The conversation a session last answered, as {key, ref}, or null.
     *
     * @return array{key:string, ref:array<string,mixed>}|null
     */
    public function lastConversation(string $sessionId): ?array;
}
