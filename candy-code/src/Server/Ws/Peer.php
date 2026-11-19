<?php

declare(strict_types=1);

namespace SugarCraft\Code\Server\Ws;

/**
 * Who is on the other end of one protocol client: the identity a call is
 * judged by (its principal, its credential bucket, its viewer pins), and
 * nothing about how bytes reach it.
 *
 * A WebSocket {@see Connection} is one peer. The in-process host
 * ({@see \SugarCraft\Code\Host\InProcessHostServices}) is another, and it has
 * no socket, so the protocol layer never assumes a Connection behind a Client.
 */
interface Peer
{
    /** A stable id for this peer, unique among the peers one server holds. */
    public function id(): string;

    /** How the peer authenticated (`cookie`, `ticket`, `bearer`, `subprotocol`, `viewer`, `in-process`). */
    public function principal(): string;

    /** The cookie session behind the peer's credential; null when none. */
    public function cookieSession(): ?string;

    /** The viewer grant's public id on the peer's credential; null for every other door. */
    public function tokenId(): ?string;

    /**
     * The sessions a pinned viewer grant covers; null when unpinned or not a viewer.
     *
     * @return list<string>|null
     */
    public function viewerSessions(): ?array;
}
