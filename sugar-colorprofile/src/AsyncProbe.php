<?php

declare(strict_types=1);

namespace SugarCraft\ColorProfile;

use React\ChildProcess\Process;
use React\EventLoop\Loop;
use React\EventLoop\LoopInterface;
use React\Promise\Deferred;
use React\Promise\PromiseInterface;
use SugarCraft\ColorProfile\Probe\InfocmpBinary;
use SugarCraft\ColorProfile\Probe\TerminfoParser;

/**
 * Asynchronous terminal color profile probe using ReactPHP ChildProcess.
 *
 * Runs `infocmp` in a subprocess to detect TrueColor (Tc/RGB) support so the
 * blocking call never lands on the embedding application's event loop thread.
 *
 * Async settle paths NEVER delegate to the synchronous probe: every resolve
 * is composed from data already in hand — the environment-anchored detection
 * chain (a pure env read, no shell) plus whatever the child's stdout managed
 * to deliver. The single exception is when no event loop can be reached at
 * all: there the async surface degrades to its synchronous sibling, because
 * nothing can be blocked in a context that has no loop.
 *
 * A chain-only fallback resolves WITHOUT spawning when:
 * - Environment suppression (NO_COLOR / CLICOLOR=0 / TERM=dumb) is in force —
 *   the async leg must not outrun the env gate the sync path honours
 * - {@see self::MAX_CONCURRENT_PROBES} children are already in flight
 * - The infocmp binary is not present
 * - TERM is unset or empty — the probe does not fabricate a terminal type
 *   the caller never declared
 *
 * Every spawned child is bounded: a wall-clock ceiling timer escalates
 * TERM → KILL and the exit/error handlers cancel BOTH rungs, so a settled
 * caller can never be followed by a stray SIGKILL. Slots whose caller
 * abandoned the loop are reclaimed (child killed) by the next call — the
 * static slot table can therefore never wedge permanently.
 *
 * Probe results are ADVISORY ONLY: the terminfo snapshot answers "what could
 * this terminal type advertise", not "what will the renderer emit" — callers
 * that must honour user suppression should read DetectionChain themselves.
 *
 * The returned promise carries no cancellation handler — cancelling it
 * does not fall back, it only detaches the caller from the result.
 *
 * @see Probe for the synchronous baseline detection (chain + terminfo leg)
 */
final class AsyncProbe
{
    /**
     * Hard ceiling on simultaneously spawned infocmp children.
     *
     * The old code spawned one child per call with no bound — a request loop
     * probing on every connection could fork unboundedly. At the cap, calls
     * degrade to the chain answer instead of queueing (the async leg is an
     * optimisation, never a correctness requirement). Public because the
     * README documents it as the fixed ceiling it is.
     */
    public const MAX_CONCURRENT_PROBES = 4;

    /**
     * Wall-clock budget for one infocmp child. infocmp reads a local terminfo
     * file; anything still running after this is wedged and gets escalated
     * TERM → KILL while the caller receives the chain answer.
     */
    private const PROBE_TIMEOUT_SECONDS = 5.0;

    /** Grace between the TERM and the KILL rung of the escalation ladder. */
    private const KILL_GRACE_SECONDS = 1.0;

    /** Keep-a-prefix bound for the child's stderr drain (see MT8 listener). */
    private const STDERR_CAP_BYTES = 8192;

    /**
     * In-flight children keyed by an opaque token, each with the child handle
     * and its absolute deadline. Token (not counter) accounting is what makes
     * both release paths — normal settle and stale reclaim — idempotent.
     *
     * @var array<int, array{process: Process, deadline: float}>
     */
    private static array $inFlight = [];

    /** Monotonic token generator for the slot table. */
    private static int $nextToken = 1;

    /**
     * Detect the terminal color profile asynchronously.
     *
     * @return PromiseInterface<ColorProfile> Resolves with the detected color profile
     */
    public static function colorProfile(): PromiseInterface
    {
        $chain = DetectionChain::detect();

        // Env gate: suppression is terminal — the chain answer is final and
        // there is nothing left for infocmp to add. Spawn nothing.
        if (!$chain->allowsColor()) {
            return \React\Promise\resolve($chain->toColorProfile());
        }

        $loop = self::getLoop();
        if ($loop === null) {
            // No event loop exists to run a child on — the synchronous probe
            // is the full answer here, and blocking is moot without a loop.
            return \React\Promise\resolve(Probe::colorProfile());
        }

        self::reclaimStaleSlots();

        if (\count(self::$inFlight) >= self::MAX_CONCURRENT_PROBES) {
            return \React\Promise\resolve(self::settleFromChain($chain));
        }

        $infocmpPath = InfocmpBinary::path();
        if ($infocmpPath === null) {
            return \React\Promise\resolve(self::settleFromChain($chain));
        }

        $term = $chain->term();
        if ($term === '' || \strtolower($term) === 'dumb') {
            // Do not fabricate a terminal type. Unknown TERM means the
            // env-anchored chain answer is the only honest one.
            return \React\Promise\resolve(self::settleFromChain($chain));
        }

        $deferred = new Deferred();
        $command = $infocmpPath . ' -1 ' . escapeshellarg($term);

        $process = new Process($command);
        $token = self::$nextToken++;
        self::$inFlight[$token] = [
            'process' => $process,
            'deadline' => \microtime(true) + self::PROBE_TIMEOUT_SECONDS + self::KILL_GRACE_SECONDS,
        ];
        $settled = false;
        $release = static function () use ($token, &$settled): void {
            if ($settled) {
                return;
            }
            $settled = true;
            unset(self::$inFlight[$token]);
        };
        $process->start($loop);

        // react/child-process 0.6 exposes the child pipes as stream PROPERTIES
        // (created inside start()); Process itself emits only 'exit'. Listening
        // for 'stdout'/'stderr' EVENTS silently never fires — the pump below
        // reads the streams directly.
        $stdout = '';
        $process->stdout?->on('data', function ($chunk) use (&$stdout): void {
            $stdout .= $chunk;
        });

        // The child's stderr must be drained or infocmp can block writing to
        // a full pipe and die on the TERM→KILL ladder over a cosmetic warning.
        // A bounded prefix is retained; everything past the cap is discarded.
        $stderr = '';
        $process->stderr?->on('data', function ($chunk) use (&$stderr): void {
            if (\strlen($stderr) < self::STDERR_CAP_BYTES) {
                $stderr .= $chunk;
            }
        });

        // Bounded-child ladder: at the ceiling TERM the child, a grace timer
        // later KILL it, and settle the caller with the chain answer. A hung
        // infocmp can therefore never strand the promise OR the slot.
        $graceTimer = null;
        $timeoutTimer = $loop->addTimer(self::PROBE_TIMEOUT_SECONDS, static function () use ($process, $loop, $deferred, $release, $chain, &$graceTimer): void {
            $process->terminate();
            $graceTimer = $loop->addTimer(self::KILL_GRACE_SECONDS, static function () use ($process): void {
                $process->terminate(defined('SIGKILL') ? \SIGKILL : 9);
            });
            $release();
            $deferred->resolve(self::settleFromChain($chain));
        });
        $cancelLadder = static function () use ($loop, $timeoutTimer, &$graceTimer): void {
            $loop->cancelTimer($timeoutTimer);
            if ($graceTimer !== null) {
                $loop->cancelTimer($graceTimer);
            }
        };

        // react/child-process emits a NULL exit code when the child was
        // killed by a signal; the handler must accept it, or the SIGKILL rung
        // of our own ladder would raise a TypeError inside Loop::run() and
        // take the embedding application's loop down with it.
        // $stdout must be captured BY REFERENCE: the accumulator is filled by
        // the data pump after this closure is built, and a by-value copy would
        // hand the exit handler the empty string it had at registration time.
        $process->on('exit', function (?int $exitCode, ?int $signal = null) use ($deferred, &$stdout, $release, $cancelLadder, $chain): void {
            $cancelLadder();
            $release();
            if ($exitCode === 0 && TerminfoParser::hasCapName($stdout, TerminfoParser::TRUECOLOR_NAMES)) {
                $deferred->resolve(ColorProfile::TrueColor);

                return;
            }
            $deferred->resolve(self::settleFromChain($chain));
        });

        // Ensure we don't leave dangling processes
        $process->on('error', function () use ($deferred, $release, $cancelLadder, $chain): void {
            $cancelLadder();
            $release();
            $deferred->resolve(self::settleFromChain($chain));
        });

        return $deferred->promise();
    }

    /**
     * Drop the static slot table (test seam).
     *
     * @internal Suites that plant in-flight slots clear them between cases so
     *           the concurrency cap cannot leak across tests.
     */
    public static function _reset(): void
    {
        self::$inFlight = [];
        self::$nextToken = 1;
    }

    /**
     * Compose the async fallback answer from the environment chain — no shell.
     *
     * The sync probe's terminfo leg must never run from here: this is called
     * from timer/exit callbacks on the embedding application's event-loop
     * thread, and a blocking shell_exec there would stall every other watcher
     * on that loop. The chain answer can UNDER-report TrueColor (the lost
     * child's job); it can never over-report user suppression.
     */
    private static function settleFromChain(DetectionChain $chain): ColorProfile
    {
        return $chain->toColorProfile();
    }

    /**
     * Kill and forget slots whose deadline passed — these exist only when a
     * caller spawned on the loop then never ran it, so no exit/error event
     * could ever fire. Reclamation makes the cap self-healing.
     */
    private static function reclaimStaleSlots(): void
    {
        $now = \microtime(true);
        foreach (self::$inFlight as $token => $slot) {
            if ($slot['deadline'] >= $now) {
                continue;
            }
            unset(self::$inFlight[$token]);
            $slot['process']->terminate(defined('SIGKILL') ? \SIGKILL : 9);
        }
    }

    /**
     * Get the current event loop or null if not available.
     */
    private static function getLoop(): ?LoopInterface
    {
        if (!class_exists(Loop::class)) {
            return null;
        }

        try {
            return Loop::get();
        } catch (\Throwable) {
            return null;
        }
    }
}
