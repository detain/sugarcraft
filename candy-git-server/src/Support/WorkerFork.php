<?php

declare(strict_types=1);

namespace SugarCraft\GitServer\Support;

/**
 * Fork-per-request worker pool — owner ruling 8b (G-5/G-1 wave).
 *
 * The HTTP listener's scaffold era ran EVERY client on one loop
 * synchronously (finding 006's disclosure, S39): a long-poll push holding
 * a git child open delayed every other connection behind it. This pool is
 * the commissioned redesign: each accepted connection is OWNED by a forked
 * worker that runs to completion and exits, so one wedged request can
 * never stall its neighbours, and the parent's job shrinks to accept,
 * capacity, and reap.
 *
 * The shape deliberately mirrors the two park designs the child-lifetime
 * roster already carries (candy-top AsyncCommand, this lib's
 * BoundedCommand::run): bounded, WNOHANG-polled, and honest that cleanup
 * is the parent's contract, not the kernel's guarantee.
 *
 * Two per-worker bounds make the pool self-healing:
 *  - a DEATH PIPE (socketpair): the child's copy of the parent's write
 *    end observes EOF the moment the parent dies by ANY means — including
 *    SIGKILL, where POSIX-ignored TERM handlers and inherited loops cannot
 *    help. An orphaned worker therefore exits at its next select tick,
 *    releasing the client socket and every inherited descriptor.
 *  - pcntl_alarm ceiling: an absolute wall-clock bound on one request; a
 *    worker stuck inside a blocking library call wakes on SIGALRM and
 *    exits. (stream_select returns early on signals, so the alarm bites
 *    even mid-poll.)
 *
 * Kill-tree boundary: terminateAll() signals THIS pool's direct children
 * only; a worker that itself spawned helpers (a git child through
 * StdioPipe) is responsible for reaping them on its own teardown paths —
 * the same contract the parent process never had.
 *
 * Windows has no fork; supported() answers false and the caller must stay
 * on the synchronous listener (the documented degraded mode, NOT a silent
 * single-threaded surprise).
 */
final class WorkerFork
{
    /** Seats (live workers) the pool hands out before it refuses. */
    public const DEFAULT_MAX_WORKERS = 64;

    /**
     * Absolute wall for ONE request inside a worker. Matches the
     * listener's write ceiling (WRITE_ABSOLUTE_SECONDS): a worker that
     * cannot finish serving in that window is broken, and the alarm ends
     * it rather than letting it inherit the connection forever.
     */
    public const DEFAULT_CEILING_SECONDS = 300.0;

    /** Grace between SIGTERM and SIGKILL in terminateAll(). */
    public const TERM_GRACE_SECONDS = 1.0;

    private const POLL_SECONDS = 0.01;

    /** @var array<int, array{pipe: resource, started: float}> pid => per-worker state (parent side) */
    private array $workers = [];

    private int $peak = 0;

    private int $total = 0;

    public function __construct(
        private readonly int $maxWorkers = self::DEFAULT_MAX_WORKERS,
        private readonly float $ceilingSeconds = self::DEFAULT_CEILING_SECONDS,
    ) {
    }

    public static function supported(): bool
    {
        return \PHP_OS_FAMILY !== 'Windows'
            && \function_exists('pcntl_fork')
            && \function_exists('pcntl_signal')
            && \function_exists('pcntl_alarm')
            && \function_exists('pcntl_waitpid')
            && \function_exists('stream_socket_pair');
    }

    /**
     * Spawn a worker owning $client. $foreign are the parent's other live
     * descriptors (listener socket, other clients) which the child closes
     * IMMEDIATELY so a wedged-or-alive worker can never hold a port or
     * another client's stream open. $handler is a blocking pump: called
     * repeatedly in the child, returns false when the connection is done.
     *
     * @param callable(resource):bool $handler
     * @param list<resource> $foreign
     */
    public function fork(callable $handler, $client, array $foreign = []): bool
    {
        $this->reap();
        if (\count($this->workers) >= $this->maxWorkers) {
            return false;
        }

        $pair = \stream_socket_pair(\STREAM_PF_UNIX, \STREAM_SOCK_STREAM, \STREAM_IPPROTO_IP);
        if ($pair === false) {
            return false;
        }

        $pid = \pcntl_fork();
        if ($pid === -1) {
            @\fclose($pair[0]);
            @\fclose($pair[1]);

            return false;
        }

        if ($pid === 0) {
            // The child's copy of the PARENT-side write end must go before
            // anything else: held anywhere, the death pipe can never reach
            // EOF, and the worker is deaf to its parent's death.
            @\fclose($pair[1]);

            // The child ends HERE and never returns into parent code.
            $this->runWorker($pair[0], $client, $foreign, $handler);
            exit(0); // unreachable: runWorker exits
        }

        // Parent side: keep the death-pipe's write end open for exactly as
        // long as the worker is registered; close drops it to EOF.
        @\fclose($pair[0]);
        $this->workers[$pid] = ['pipe' => $pair[1], 'started' => \microtime(true)];
        $this->total++;
        $this->peak = \max($this->peak, \count($this->workers));

        return true;
    }

    /**
     * @param resource $deathPipe  child end of the parent-death pair
     * @param resource $client     the connection this worker owns
     * @param list<resource> $foreign parent descriptors to release
     * @param callable(resource):bool $handler
     */
    private function runWorker($deathPipe, $client, array $foreign, callable $handler): void
    {
        // Signal dispositions inherited as SIG_IGN are reset to default
        // by the exec boundary — but a fork has no exec. Arm what the
        // worker needs itself: the death check does not depend on a
        // handler; SIGTERM/SIGINT exit the worker for terminateAll() and
        // for terminal Ctrl-C storms that reached it directly.
        \pcntl_async_signals(true);
        $bye = static function (int $signal): never {
            exit(0);
        };
        \pcntl_signal(\SIGTERM, $bye);
        \pcntl_signal(\SIGINT, $bye);
        \pcntl_signal(\SIGALRM, $bye);
        // Ceiling first: even if the pump below wedges inside a blocking
        // call, this alarm ends the worker within the bound.
        \pcntl_alarm((int) \max(1, \ceil($this->ceilingSeconds)));

        foreach ($foreign as $fd) {
            if (\is_resource($fd)) {
                @\fclose($fd);
            }
        }
        @\stream_set_blocking($deathPipe, false);
        @\stream_set_blocking($client, false);

        // The handler ticks FIRST-ENTRY, then on every select expiry —
        // readable or timeout. Entry covers work that must not wait for
        // client bytes (and is a harmless no-op for the HTTP pump, whose
        // first fread on an empty socket just keeps buffering); timeout
        // ticks are what carry the idle-deadline check into a silent
        // connection, which never becomes readable. false from the
        // handler — or a client that reached EOF — ends the worker.
        $running = $handler($client) && self::connectionAlive($client);
        while ($running) {
            $read = [$client, $deathPipe];
            $write = null;
            $except = null;
            $ready = @\stream_select($read, $write, $except, 1);
            if ($ready === false) {
                break; // signal-interrupted or dead fd; the alarm/EOF is the contract
            }
            foreach ($read as $stream) {
                if ($stream === $deathPipe) {
                    $probe = @\fread($deathPipe, 1);
                    if ($probe === false || ($probe === '' && @\feof($deathPipe))) {
                        // Parent gone (EOF on the death pipe): exit and
                        // release the client socket, the port would have
                        // been held otherwise.
                        exit(0);
                    }
                    continue;
                }
            }
            // Called on EVERY expiry: readable events serve traffic at the
            // earliest tick, and silent connections still face their
            // deadlines once per second.
            $running = $handler($client) && self::connectionAlive($client);
        }
        exit(0);
    }

    /** @param resource $stream */
    private static function connectionAlive($stream): bool
    {
        return \is_resource($stream) && !@\feof($stream);
    }

    /**
     * Non-blocking collect of every worker that has exited. Returns the
     * count reaped; each reap also closes that worker's death-pipe end,
     * which is what lets a live worker see EOF if the parent stops the
     * pool.
     */
    public function reap(): int
    {
        $reaped = 0;
        foreach (\array_keys($this->workers) as $pid) {
            $result = \pcntl_waitpid($pid, $status, \WNOHANG);
            if ($result === $pid) {
                @\fclose($this->workers[$pid]['pipe']);
                unset($this->workers[$pid]);
                $reaped++;
            } elseif ($result === -1) {
                // No such child (already collected elsewhere): drop the
                // bookkeeping and the pipe anyway; keeping it would wedge
                // capacity on a pid we can never wait for again.
                @\fclose($this->workers[$pid]['pipe']);
                unset($this->workers[$pid]);
            }
        }

        return $reaped;
    }

    /** Live workers right now (sweeps first). */
    public function live(): int
    {
        $this->reap();

        return \count($this->workers);
    }

    /**
     * Orderly end for every worker: TERM, bounded poll, KILL the
     * stragglers. Idempotent; safe on an empty pool.
     */
    public function terminateAll(): void
    {
        if ($this->workers === []) {
            return;
        }
        $until = \hrtime(true) + (int) (self::TERM_GRACE_SECONDS * 1e9);
        do {
            $this->reap();
            if ($this->workers === []) {
                return;
            }
            foreach (\array_keys($this->workers) as $pid) {
                @\posix_kill($pid, \SIGTERM);
            }
            \usleep((int) (self::POLL_SECONDS * 1e6));
        } while (\hrtime(true) < $until);

        $this->reap();
        foreach (\array_keys($this->workers) as $pid) {
            @\posix_kill($pid, \SIGKILL);
        }
        $killUntil = \hrtime(true) + (int) (2.0 * 1e9);
        do {
            $this->reap();
            if ($this->workers === []) {
                return;
            }
            \usleep((int) (self::POLL_SECONDS * 1e6));
        } while (\hrtime(true) < $killUntil);
        // Stragglers left after KILL+2 s are uninterruptible-sleep cases;
        // the death pipe guarantees they end the moment this process does.
        $this->reap();
    }

    /** Maximum simultaneous workers any run of this pool has held. */
    public function peakCount(): int
    {
        return $this->peak;
    }

    /** Total workers this pool has ever spawned. */
    public function totalSpawned(): int
    {
        return $this->total;
    }
}
