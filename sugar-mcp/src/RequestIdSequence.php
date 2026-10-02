<?php

declare(strict_types=1);

namespace SugarCraft\Mcp;

/**
 * JSON-RPC request ids that stay unique across pcntl_fork().
 *
 * WHY THIS EXISTS (audit B1 / AG-1): an embedder like sugar-crush starts its
 * MCP servers once, in the long-lived parent, and then runs every turn — and
 * every parallel sub-agent — in a forked child. A plain `$nextId++` counter is
 * COPIED into each child, so every child sends the same next id. Two children
 * calling at once both wrote `{"id":"3"}` and each accepted whichever reply
 * arrived first (the replies were swapped between agents); a child SIGKILLed
 * mid-call left its reply in the shared pipe, and the next turn — forked again
 * from the same parent counter — reused that id and was handed the dead call's
 * result. A strict id match cannot tell those apart; distinct ids make it able
 * to.
 *
 * THE SCHEME. The owner process (the pid that started the connection, see
 * {@see claim()}) keeps plain decimal counter ids `0, 1, 2, …` — the wire shape
 * every existing log and test already shows. Any other pid mints
 * `<pid>-<nonce>-<n>`: the pid separates live processes, and the random nonce
 * separates a process from an EARLIER process that had the same pid — after a
 * SIGKILLed child its pid can be reused by the next fork, and without the nonce
 * that fork would recreate exactly the id whose stale reply is still in the
 * pipe. The nonce is regenerated whenever the current pid differs from the pid
 * it was minted for, so each process gets its own on its first request.
 */
final class RequestIdSequence
{
    /** Random bytes per nonce: 48 bits, hex-encoded on the wire. */
    private const NONCE_BYTES = 6;

    private readonly \Closure $pidProvider;

    private int $ownerPid;

    private int $ownerCounter;

    private int $foreignCounter = 0;

    private string $nonce = '';

    private int $noncePid = 0;

    /**
     * @param \Closure|null $pidProvider @internal test seam returning the
     *        current pid; null reads getmypid() (which re-reads the pid after a
     *        fork — measured, it is not cached)
     * @param int $firstOwnerId the first id the owner hands out; transports
     *        whose historical first id was 1 pass 1 so their wire stays put
     */
    public function __construct(?\Closure $pidProvider = null, int $firstOwnerId = 0)
    {
        $this->pidProvider = $pidProvider ?? static fn (): int => (int) getmypid();
        $this->ownerPid = $this->currentPid();
        $this->ownerCounter = $firstOwnerId;
    }

    /**
     * Make the CURRENT process the owner — called when a connection is opened,
     * because the process that opened it is the one whose ids should look
     * ordinary. The owner counter is not reset: an id is never reused within
     * one object's lifetime, even across a stop()/start() cycle.
     */
    public function claim(): void
    {
        $this->ownerPid = $this->currentPid();
    }

    public function ownerPid(): int
    {
        return $this->ownerPid;
    }

    /** Is the current process the one that opened the connection? */
    public function isOwner(): bool
    {
        return $this->currentPid() === $this->ownerPid;
    }

    /** The current pid, as the provider reports it. */
    public function currentPid(): int
    {
        return (int) ($this->pidProvider)();
    }

    /**
     * The next id: an int for the owner, a `<pid>-<nonce>-<n>` string for any
     * other process. Transports that put ids on the wire as strings cast the
     * int; a transport that historically sent integers keeps doing so for the
     * owner. A matcher must therefore compare ids as strings.
     */
    public function next(): int|string
    {
        $pid = $this->currentPid();

        if ($pid === $this->ownerPid) {
            return $this->ownerCounter++;
        }

        if ($pid !== $this->noncePid || $this->nonce === '') {
            $this->nonce = bin2hex(random_bytes(self::NONCE_BYTES));
            $this->noncePid = $pid;
            $this->foreignCounter = 0;
        }

        return sprintf('%d-%s-%d', $pid, $this->nonce, $this->foreignCounter++);
    }
}
