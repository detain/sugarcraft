<?php

declare(strict_types=1);

namespace SugarCraft\Plugins\Host;

use React\Promise\PromiseInterface;

/**
 * The calls a channel plugin makes into the host it runs inside.
 *
 * Each method maps to one row of the Phase 5a inventory in plan_plugin.md: rows 1 to 10
 * are the wire calls a bundle makes today, and rows 11 to 16 are the lifecycle and event
 * hooks around them. An in-process host implements this directly, so a plugin no longer
 * dials its own host over a loopback WebSocket. The wire protocol stays for external clients.
 */
interface HostServices
{
    /** Mirrors the wire call server.hello (inventory row 1). */
    public function hello(): array;

    /** Mirrors the wire call session.subscribe (row 2). */
    public function subscribeSession(string $sessionId, ?int $afterSeq, ?\Closure $cursor): array;

    /** Mirrors the wire call session.unsubscribe (row 3). */
    public function unsubscribeSession(string $sessionId): void;

    /** Mirrors the wire call session.send (row 4). */
    public function sendTurn(
        string $sessionId,
        string $text,
        string $delivery,
        ?string $idempotencyKey,
        array $images,
        array $documents,
    ): array;

    /** Mirrors the wire call session.create (row 5). The result carries the new session id. */
    public function createSession(?string $name, ?string $permissionMode): array;

    /** Mirrors the wire call permission.respond (row 6). */
    public function respondPermission(string $sessionId, string $askId, string $reply, ?string $note): array;

    /** Mirrors the wire call session.cancel (row 7). */
    public function cancelSession(string $sessionId, string $mode): array;

    /** Mirrors the wire call session.get (row 8). */
    public function sessionSnapshot(string $sessionId, int $limit): array;

    /** Mirrors the wire call command.exec (row 9). */
    public function execCommand(string $sessionId, string $name, string $args): array;

    /** Mirrors the wire call permission.pending (row 10). */
    public function pendingPermissions(): array;

    /** Opens the host connection; the promise resolves once the host is ready to serve calls (row 13). */
    public function connect(): PromiseInterface;

    /** Closes the host connection (row 13). */
    public function disconnect(): void;

    /** Whether the host connection is currently open (row 14). */
    public function isConnected(): bool;

    /**
     * The session ids this plugin currently follows (row 15).
     *
     * @return list<string>
     */
    public function followedSessions(): array;

    /**
     * Delivers each session event to the listener, in per-session seq order (row 11).
     *
     * No event is dropped silently. A host that cannot keep up with a listener delivers an
     * overflow notice in its place, carrying `dropped` and `action: 'resubscribe'` as the wire
     * protocol does, and the listener re-follows the session to recover the gap.
     */
    public function subscribeEvents(\Closure $listener): void;

    /** Delivers each follow result to the listener after a re-follow (row 12). */
    public function subscribeFollowResults(\Closure $listener): void;
}
