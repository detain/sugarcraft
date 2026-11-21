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
 *
 * The contract is asynchronous (owner decision 2026-11-19, option a). Every method returns
 * a promise. It resolves to the value the call answers with, or to nothing for the void
 * calls, and it rejects with the same error the host raises for the same refusal (the
 * wire's RpcError kinds), so a caller handles a refusal in its rejection handler. A method
 * whose answer is not a value (the subscriptions) resolves to null once it is registered.
 */
interface HostServices
{
    /**
     * Mirrors the wire call server.hello (inventory row 1).
     *
     * @return PromiseInterface<array>
     */
    public function hello(): PromiseInterface;

    /**
     * Mirrors the wire call session.subscribe (row 2).
     *
     * @return PromiseInterface<array>
     */
    public function subscribeSession(string $sessionId, ?int $afterSeq, ?\Closure $cursor): PromiseInterface;

    /**
     * Mirrors the wire call session.unsubscribe (row 3).
     *
     * @return PromiseInterface<null>
     */
    public function unsubscribeSession(string $sessionId): PromiseInterface;

    /**
     * Mirrors the wire call session.send (row 4).
     *
     * @return PromiseInterface<array>
     */
    public function sendTurn(
        string $sessionId,
        string $text,
        string $delivery,
        ?string $idempotencyKey,
        array $images,
        array $documents,
    ): PromiseInterface;

    /**
     * Mirrors the wire call session.create (row 5). The resolved result carries the new session id.
     *
     * @return PromiseInterface<array>
     */
    public function createSession(?string $name, ?string $permissionMode): PromiseInterface;

    /**
     * Mirrors the wire call permission.respond (row 6).
     *
     * @return PromiseInterface<array>
     */
    public function respondPermission(string $sessionId, string $askId, string $reply, ?string $note): PromiseInterface;

    /**
     * Mirrors the wire call session.cancel (row 7).
     *
     * @return PromiseInterface<array>
     */
    public function cancelSession(string $sessionId, string $mode): PromiseInterface;

    /**
     * Mirrors the wire call session.get (row 8).
     *
     * @return PromiseInterface<array>
     */
    public function sessionSnapshot(string $sessionId, int $limit): PromiseInterface;

    /**
     * Mirrors the wire call command.exec (row 9).
     *
     * @return PromiseInterface<array>
     */
    public function execCommand(string $sessionId, string $name, string $args): PromiseInterface;

    /**
     * Mirrors the wire call permission.pending (row 10).
     *
     * @return PromiseInterface<array>
     */
    public function pendingPermissions(): PromiseInterface;

    /**
     * Opens the host connection (row 13). Resolves once the host is ready to serve calls.
     *
     * @return PromiseInterface<null>
     */
    public function connect(): PromiseInterface;

    /**
     * Closes the host connection (row 13).
     *
     * @return PromiseInterface<null>
     */
    public function disconnect(): PromiseInterface;

    /**
     * Whether the host connection is currently open (row 14).
     *
     * @return PromiseInterface<bool>
     */
    public function isConnected(): PromiseInterface;

    /**
     * The session ids this plugin currently follows (row 15).
     *
     * @return PromiseInterface<list<string>>
     */
    public function followedSessions(): PromiseInterface;

    /**
     * Delivers each session event to the listener, in per-session seq order (row 11).
     *
     * The listener receives the event envelope as the wire carries it in a `event` notification's
     * params (`sessionId`, `seq`, `type`, `ts`, `turnId`, `durable`, `data`), as an array.
     *
     * No event is dropped silently. A host that cannot keep up with a listener delivers an
     * overflow notice in its place, carrying `dropped` and `action: 'resubscribe'` as the wire
     * protocol does, and the listener re-follows the session to recover the gap.
     *
     * @return PromiseInterface<null>
     */
    public function subscribeEvents(\Closure $listener): PromiseInterface;

    /**
     * Delivers each follow result to the listener after a re-follow (row 12).
     *
     * @return PromiseInterface<null>
     */
    public function subscribeFollowResults(\Closure $listener): PromiseInterface;
}
