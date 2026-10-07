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

    /** @var \Closure(int): bool answers "is this pid still running?" */
    private readonly \Closure $ownerAlive;

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
     * @param \Closure|null $ownerAlive @internal test seam answering whether a
     *        pid is still running; null probes posix_kill(0) with the same
     *        conservative polarity as {@see ExchangeLock::processIsGone()} —
     *        anything uncertain answers "alive" so a refused claim is always
     *        a real collision, never a probe hiccup
     */
    public function __construct(?\Closure $pidProvider = null, int $firstOwnerId = 0, ?\Closure $ownerAlive = null)
    {
        $this->pidProvider = $pidProvider ?? static fn (): int => (int) getmypid();
        $this->ownerAlive = $ownerAlive ?? self::liveOwnerProbe();
        $this->ownerPid = $this->currentPid();
        $this->ownerCounter = $firstOwnerId;
    }

    /**
     * Make the CURRENT process the owner — called when a connection is opened,
     * because the process that opened it is the one whose ids should look
     * ordinary. The owner counter is not reset: an id is never reused within
     * one object's lifetime, even across a stop()/start() cycle.
     *
     * FIX #5 (fork-proven): a claim by a process OTHER than the owner used to
     * succeed unconditionally, so a forked child that re-claimed while the
     * parent still exchanged on the connection installed itself as a second
     * live owner — both then minted the same plain-counter ids and the id
     * scheme's whole premise broke. A claim is now refused (returns false,
     * ownership unchanged) while the previous owner is a DIFFERENT, still-
     * live pid; the claimer keeps minting `<pid>-<nonce>-<n>` ids and cannot
     * collide. A same-pid claim never probes and always succeeds — that is
     * the restart path (stop()/start() in the owner, and a child adopted
     * after its parent's death answers dead and takes over).
     */
    public function claim(): bool
    {
        $pid = $this->currentPid();

        if ($pid !== $this->ownerPid && ($this->ownerAlive)($this->ownerPid)) {
            return false;
        }

        $this->ownerPid = $pid;

        return true;
    }

    /**
     * The default liveness probe: a signal-0 that succeeds or fails with
     * anything but ESRCH means alive; without posix, only Linux's /proc can
     * say "absent", and every other uncertainty answers alive — the refuse-
     * only-on-certainty polarity mirrors ExchangeLock's stale-lock sweep.
     */
    private static function liveOwnerProbe(): \Closure
    {
        return static function (int $pid): bool {
            if (function_exists('posix_kill') && function_exists('posix_get_last_error')) {
                if (@posix_kill($pid, 0)) {
                    return true;
                }

                // ESRCH (3) alone means "no such process"; EPERM is a live
                // process of another user, and any other errno is not an answer.
                return posix_get_last_error() !== 3;
            }

            if (is_dir('/proc/self')) {
                return file_exists("/proc/{$pid}");
            }

            return true;
        };
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
