<?php

declare(strict_types=1);

namespace SugarCraft\ColorProfile;

use React\ChildProcess\Process;
use React\EventLoop\Loop;
use React\EventLoop\LoopInterface;
use React\Promise\Deferred;
use React\Promise\PromiseInterface;
use SugarCraft\ColorProfile\Probe\InfocmpBinary;

/**
 * Asynchronous terminal color profile probe using ReactPHP ChildProcess.
 *
 * Runs `infocmp` in a subprocess to detect TrueColor (Tc/RGB) support,
 * avoiding blocking the event loop during terminal capability detection.
 *
 * Falls back to the synchronous {@see Probe::colorProfile()} if:
 * - The event loop is not available
 * - The infocmp binary is not present
 * - The child process errors before exiting
 * - Environment suppression (NO_COLOR / CLICOLOR=0 / TERM=dumb) is in force —
 *   the async leg must not outrun the env gate the sync path honours
 * - TERM is unset — previously the probe fabricated `TERM=xterm` and probed
 *   a terminal the caller never declared
 * - {@see self::MAX_CONCURRENT_PROBES} children are already in flight
 *
 * Every spawned child is bounded: a wall-clock ceiling timer escalates
 * TERM → KILL and settles the promise with the sync detection, and slots
 * whose caller abandoned the loop are reclaimed (child killed) by the next
 * call — the static slot table can therefore never wedge permanently.
 *
 * Probe results are ADVISORY ONLY: the terminfo snapshot answers "what could
 * this terminal type advertise", not "what will the renderer emit" — callers
 * that must honour user suppression should read DetectionChain themselves.
 *
 * The returned promise carries no cancellation handler — cancelling it
 * does not fall back, it only detaches the caller from the result.
 *
 * @see Probe::colorProfile() for the synchronous baseline detection
 */
final class AsyncProbe
{
    /**
     * Hard ceiling on simultaneously spawned infocmp children.
     *
     * The old code spawned one child per call with no bound — a request loop
     * probing on every connection could fork unboundedly. At the cap, calls
     * degrade to the sync detection instead of queueing (the async leg is an
     * optimisation, never a correctness requirement).
     */
    private const MAX_CONCURRENT_PROBES = 4;

    /**
     * Wall-clock budget for one infocmp child. infocmp reads a local terminfo
     * file; anything still running after this is wedged and gets escalated
     * TERM → KILL while the caller receives the sync answer.
     */
    private const PROBE_TIMEOUT_SECONDS = 5.0;

    /** Grace between the TERM and the KILL rung of the escalation ladder. */
    private const KILL_GRACE_SECONDS = 1.0;

    /**
     * In-flight children keyed by an opaque token, each with the child handle
     * and its absolute deadline. Token (not counter) accounting is what makes
     * both release paths — normal settle and stale reclaim — idempotent.
     *
     * @var array<int, array{process: Process, deadline: float}>
     */
    private static array $inFlight = [];

    /**
     * Detect the terminal color profile asynchronously.
     *
     * @return PromiseInterface<ColorProfile> Resolves with the detected color profile
     */
    public static function colorProfile(): PromiseInterface
    {
        // Env gate: suppression is terminal — hand it to the sync path,
        // which already returns the honest no-color profile, and spawn nothing.
        if (!DetectionChain::detect()->allowsColor()) {
            return \React\Promise\resolve(Probe::colorProfile());
        }

        $loop = self::getLoop();
        if ($loop === null) {
            // No event loop available — fall back to sync detection
            return \React\Promise\resolve(Probe::colorProfile());
        }

        self::reclaimStaleSlots();

        if (\count(self::$inFlight) >= self::MAX_CONCURRENT_PROBES) {
            return \React\Promise\resolve(Probe::colorProfile());
        }

        $deferred = new Deferred();

        $infocmpPath = InfocmpBinary::path();
        if ($infocmpPath === null) {
            $deferred->resolve(Probe::colorProfile());

            return $deferred->promise();
        }

        $term = self::getTerm();
        if ($term === null || $term === '' || $term === 'dumb') {
            // Do not fabricate a terminal type. Unknown TERM means the
            // env-anchored sync detection is the only honest answer.
            $deferred->resolve(Probe::colorProfile());

            return $deferred->promise();
        }
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

        $stdout = '';

        $process->on('stdout', function ($chunk) use (&$stdout): void {
            $stdout .= $chunk;
        });

        // Bounded-child ladder: at the ceiling TERM the child, a grace timer
        // later KILL it, and settle the caller with the sync detection. A
        // hung infocmp can therefore never strand the promise OR the slot.
        $timeoutTimer = $loop->addTimer(self::PROBE_TIMEOUT_SECONDS, static function () use ($process, $loop, $deferred, $release): void {
            $process->terminate();
            $loop->addTimer(self::KILL_GRACE_SECONDS, static function () use ($process): void {
                $process->terminate(defined('SIGKILL') ? \SIGKILL : 9);
            });
            $release();
            $deferred->resolve(Probe::colorProfile());
        });

        $process->on('exit', function (int $exitCode) use ($deferred, $stdout, $release, $loop, $timeoutTimer): void {
            $loop->cancelTimer($timeoutTimer);
            $release();
            if ($exitCode === 0 && (preg_match('/\bTc\b/', $stdout) || preg_match('/\bRGB\b/', $stdout))) {
                $deferred->resolve(ColorProfile::TrueColor);
            } else {
                // Fall back to sync detection (includes env-based detection + existing infocmp logic)
                $deferred->resolve(Probe::colorProfile());
            }
        });

        // Ensure we don't leave dangling processes
        $process->on('error', function () use ($deferred, $release, $loop, $timeoutTimer): void {
            $loop->cancelTimer($timeoutTimer);
            $release();
            $deferred->resolve(Probe::colorProfile());
        });

        return $deferred->promise();
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

    /** Monotonic token generator for the slot table. */
    private static int $nextToken = 1;

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

    /**
     * Get the TERM environment variable.
     */
    private static function getTerm(): ?string
    {
        $term = $_ENV['TERM'] ?? (getenv('TERM') ?: null);
        return $term === false ? null : $term;
    }
}
