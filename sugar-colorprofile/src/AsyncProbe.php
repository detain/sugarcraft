<?php

declare(strict_types=1);

namespace SugarCraft\ColorProfile;

use React\ChildProcess\Process;
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
 * The child is spawned ONLY on an explicitly supplied loop: pass a
 * {@see LoopInterface} to run the infocmp leg asynchronously on it, and the
 * async settle paths NEVER delegate to the synchronous probe mid-flight —
 * every resolve is composed from data already in hand, the environment-
 * anchored detection chain (a pure env read, no shell) plus whatever the
 * child's stdout managed to deliver. Called WITHOUT a loop the promise
 * resolves in-place through the bounded synchronous face ({@see Probe}),
 * because a plain-CLI caller has no loop it runs: react/event-loop 1.x
 * would otherwise lazily create its singleton and park the child there,
 * settling only when somebody else runs that loop.
 *
 * A chain-only fallback resolves WITHOUT spawning when:
 * - Environment suppression (NO_COLOR / CLICOLOR=0 / TERM=dumb) is in force —
 *   the async leg must not outrun the env gate the sync path honours
 * - {@see self::MAX_CONCURRENT_PROBES} children are already in flight
 * - The infocmp binary is not present
 * - TERM is unset or empty — the probe does not fabricate a terminal type
 *   the caller never declared
 * - TERM carries a shape infocmp would parse as its own option (shared
 *   predicate {@see InfocmpBinary::isSafeTermName()})
 * - The chain rung is not Ansi/Ansi256 — the child can only ADD TrueColor,
 *   so floors (Ascii) and already-TrueColor answers settle without a spawn,
 *   in parity with the sync leg's promotion gate
 *
 * Every spawned child is bounded: a wall-clock ceiling timer escalates
 * TERM → KILL and the exit handler cancels BOTH rungs (spawn failures never
 * reach a handler — they are caught at start(), CP-14), so a settled
 * caller can never be followed by a stray SIGKILL. Each child runs under the
 * shared minimal environment ({@see InfocmpBinary::childEnvironment()}) —
 * `TERM` and a fixed `PATH`, never the parent's `TERMINFO`/`TERMINFO_DIRS`/
 * `HOME`, which would steer the database lookup. Both child pipes drain
 * into keep-a-prefix 8 KiB buffers — past the ceiling the child is noise,
 * never an accumulator that grows with the pipe. Slots whose caller
 * abandoned the loop are reclaimed — child killed AND reaped — by the next
 * call, so the static slot table can never wedge permanently.
 *
 * A child that RUNS TO COMPLETION settles its verdict into a per-TERM memo
 * (CP-13 — the async twin of the sync face's dump memo): a repeat call for
 * the same TERM answers from memory without re-spawning, so the two faces
 * keep one cache policy for one question.
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
     * Keep-a-prefix bound for the child's stdout pump, mirroring the stderr
     * arm: the capability scan reads cap names at line-heads near the front
     * of an `infocmp -1` dump, and real single-terminal dumps stay far below
     * 8 KiB. Past the ceiling the child is noise — the accumulator stops
     * growing and the settle treats the truncated prefix as the whole dump
     * (advisory), instead of letting a pathological writer grow with the pipe.
     * The number is the door's: {@see InfocmpBinary::OUTPUT_CAP_BYTES} is the
     * one ceiling every infocmp surface shares (sync face, async pump).
     */
    private const STDOUT_CAP_BYTES = InfocmpBinary::OUTPUT_CAP_BYTES;

    /**
     * In-flight children keyed by an opaque token, each with the child handle
     * and its absolute deadline. Token (not counter) accounting is what makes
     * every release path — normal settle, start-failure catch and stale
     * reclaim — idempotent.
     *
     * @var array<int, array{process: Process, deadline: float}>
     */
    private static array $inFlight = [];

    /** Monotonic token generator for the slot table. */
    private static int $nextToken = 1;

    /**
     * Ceiling on {@see self::$settledMemo} — the twin of
     * {@see InfocmpBinary::MEMO_MAX_ENTRIES} (256): a long-lived process
     * honoring attacker-influenced TERM shapes must not creep the table for
     * its lifetime. Value-coupled, deliberately not a shared constant —
     * InfocmpBinary's is private to the exec face that owns it; the two
     * tables bound the same TERM-cardinality risk and must be edited
     * together if either ever moves.
     */
    private const MEMO_MAX_ENTRIES = 256;

    /**
     * Settled answers of COMPLETED infocmp children, keyed by lowercased
     * TERM (CP-13, sugar-colorprofile-050).
     *
     * The sync face memoizes per TERM (the dump table inside
     * {@see InfocmpBinary}); until this entry the async pump re-spawned a
     * real child on every Ansi/Ansi256-rung call — two cache policies on
     * one question, and on Termux-class hardware the repeated spawn is a
     * battery/thermal cost, not a scheduling nicety.
     *
     * The memo stores the ANSWER, never the pending promise: holding a
     * promise would let an abandoned-loop caller (spawned, loop never run,
     * slot later reclaimed by a different call) poison the table with a
     * never-settling entry — exactly the class of stall memoizing the
     * answer instead cannot create. Values are the child-specific verdict
     * only: {@see ColorProfile::TrueColor} when the completed child found
     * a promotion cap, or a NULL sentinel when it completed cleanly
     * without one (the terminfo verdict is TERM-static — the dump the sync
     * face caches carries the same property, and its table mints entries
     * for clean misses too). The ENV-side half of any negative answer is
     * re-derived from the CURRENT chain per call, so env drift after the
     * first probe can never be served stale. A child that died nonzero or
     * was ladder-killed proves nothing: only a zero exit mints an entry.
     * Cap-full/no-binary/unsettled-shape early returns never consulted the
     * child, so they never write.
     *
     * @var array<string, ColorProfile|null>
     */
    private static array $settledMemo = [];

    /**
     * Detect the terminal color profile asynchronously.
     *
     * With an explicit {@see LoopInterface} the infocmp leg runs as a child
     * on that loop; with none, the answer is the bounded synchronous face,
     * resolved in-place (the caller has no loop to settle on, and the
     * react/event-loop singleton must never be picked up implicitly — a
     * child parked there settles only when someone else runs that loop).
     *
     * @param ?LoopInterface $loop Loop instance to spawn the child on; null resolves synchronously
     *
     * @return PromiseInterface<ColorProfile> Resolves with the detected color profile
     */
    public static function colorProfile(?LoopInterface $loop = null): PromiseInterface
    {
        $chain = DetectionChain::detect();

        // Promotion gate (parity with the sync terminfo leg in Probe):
        // the infocmp child can only ever ADD TrueColor, so the terminfo
        // leg runs solely on the Ansi/Ansi256 rungs. On a colorless rung —
        // the NO_COLOR/CLICOLOR=0/TERM=dumb suppressions and falsy
        // FORCE_COLOR spellings all answer the Ascii floor since CP-05 —
        // the chain answer is final: spawning the child there let a TERM
        // cap-claim upgrade past a declared floor.
        $profile = $chain->toColorProfile();
        if ($profile !== ColorProfile::Ansi && $profile !== ColorProfile::Ansi256) {
            return \React\Promise\resolve($profile);
        }

        if ($loop === null) {
            // No loop handed in: spawn nothing on any loop. The bounded
            // synchronous face is the full answer — chain plus the deadline-
            // capped infocmp leg — and it settles the promise in-place
            // instead of parking a child on the react/event-loop singleton
            // that this caller may never run.
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

        // Exec gate (shared predicate with Probe/TerminalProbe): a TERM
        // infocmp would parse as its own option never reaches the child.
        if (!InfocmpBinary::isSafeTermName($term)) {
            return \React\Promise\resolve(self::settleFromChain($chain));
        }

        // CP-13 memo: a completed child already gave a TERM-static verdict
        // for this TERM in this process. Checked after every safety gate so
        // a shape the child would never consult can neither read nor grow
        // the table. A NULL entry (clean no-promotion) re-derives only the
        // env-side answer from the CURRENT chain — see $settledMemo.
        $memoKey = \strtolower($term);
        if (\array_key_exists($memoKey, self::$settledMemo)) {
            return \React\Promise\resolve(self::$settledMemo[$memoKey] ?? self::settleFromChain($chain));
        }

        $deferred = new Deferred();
        $command = $infocmpPath . ' -1 ' . escapeshellarg($term);

        $token = self::$nextToken++;
        $settled = false;
        $release = static function () use ($token, &$settled): void {
            if ($settled) {
                return;
            }
            $settled = true;
            unset(self::$inFlight[$token]);
        };

        // The constructor and start() are the only fallible calls in this
        // whole function (react/child-process 0.6.7: ctor @throws LogicException
        // when proc_open is disabled or Windows refuses pipes; start()
        // @throws RuntimeException on a failed spawn) and they sit OUTSIDE
        // every promise handler — uncaught, a throw would escape the promise
        // contract AND wedge a booked slot until deadline-reclaim SIGKILLed
        // a child that never existed. The cap slot is therefore booked only
        // once the child object is real, and the catch releases whatever was
        // booked. Fail-open to the chain, same shape as the cap arm.
        $process = null;
        try {
            $process = new Process($command, null, InfocmpBinary::childEnvironment($term));
            self::$inFlight[$token] = [
                'process' => $process,
                'deadline' => \microtime(true) + self::PROBE_TIMEOUT_SECONDS + self::KILL_GRACE_SECONDS,
            ];
            $process->start($loop);
        } catch (\Throwable) {
            $release();
            // A launched-but-unwired child (the loop refused its stream
            // watchers AFTER proc_open succeeded) still has to die here: no
            // ladder listener ever attached to it. A spawn that never made
            // it past proc_open left no child — vendor stores the boolean
            // false in that case, so the handle is checked before signaling.
            if ($process !== null && \is_resource((new \ReflectionProperty($process, 'process'))->getValue($process))) {
                $process->terminate(defined('SIGKILL') ? \SIGKILL : 9);
                $process->close();
            }

            return \React\Promise\resolve(self::settleFromChain($chain));
        }

        // react/child-process 0.6 exposes the child pipes as stream PROPERTIES
        // (created inside start()); Process itself emits only 'exit'. Listening
        // for 'stdout'/'stderr' EVENTS silently never fires — the pump below
        // reads the streams directly.
        $stdout = '';
        $process->stdout?->on('data', function ($chunk) use (&$stdout): void {
            if (\strlen($stdout) < self::STDOUT_CAP_BYTES) {
                $stdout .= $chunk;
            }
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
        $process->on('exit', function (?int $exitCode, ?int $signal = null) use ($deferred, &$stdout, $release, $cancelLadder, $chain, $term): void {
            $cancelLadder();
            $release();
            $promoted = $exitCode === 0 && TerminfoParser::hasCapName($stdout, TerminfoParser::TRUECOLOR_NAMES);
            if ($exitCode === 0) {
                // TERM-static verdict from a cleanly completed child only
                // (CP-13): promotion, or the NULL negative sentinel.
                self::memoWrite(\strtolower($term), $promoted ? ColorProfile::TrueColor : null);
            }
            $deferred->resolve($promoted ? ColorProfile::TrueColor : self::settleFromChain($chain));
        });

        // There is deliberately no 'error' listener: react/child-process
        // 0.6.x never emits 'error' on Process (vendor grep: 'exit' is the
        // only event it forwards, and a spawn that fails dies inside
        // start() — CP-14's catch already settles the caller and frees the
        // cap slot there). The old block could not fire.

        return $deferred->promise();
    }

    /**
     * Drop the static slot table and the settled-answer memo (test seam).
     *
     * @internal Suites that plant in-flight slots clear them between cases so
     *           the concurrency cap cannot leak across tests; the CP-13 memo
     *           drains here too so a planted child binary in one case can
     *           never seed another case's answer.
     */
    public static function resetForTesting(): void
    {
        self::$inFlight = [];
        self::$nextToken = 1;
        self::$settledMemo = [];
    }

    /**
     * @deprecated Renamed to {@see self::resetForTesting()} by CP-42 — see the
     *             Probe alias for the full rationale. Pure delegate, zero
     *             behavior difference; removal target 2.0.
     */
    public static function _reset(): void
    {
        self::resetForTesting();
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
     * Sole write site of the settled-answer memo, enforcing the cardinality
     * ceiling: at {@see self::MEMO_MAX_ENTRIES} the table clears before the
     * write (same bulk-reset shape as InfocmpBinary's dump memo — one clear,
     * not an LRU).
     */
    private static function memoWrite(string $key, ?ColorProfile $profile): void
    {
        if (\count(self::$settledMemo) >= self::MEMO_MAX_ENTRIES) {
            self::$settledMemo = [];
        }

        self::$settledMemo[$key] = $profile;
    }

    /**
     * Kill AND reap slots whose deadline passed — these exist only when a
     * caller spawned on a loop then never ran it, so no exit event could ever
     * fire and the vendor's own reaper never runs either. Reclamation makes
     * the cap self-healing.
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
            // The kill alone is not the end of it: react/child-process reaps
            // only through Process::close(), which its own code reaches from
            // stream-close events on the child's loop (0.6.7: close() is
            // public, closing each pipe then proc_close()-ing the handle).
            // An abandoned loop never delivers those events, so terminate()
            // without close() would strand a zombie plus its pipe fds for the
            // life of the PHP process — every abandoned probe accumulating one
            // — exactly what "dangling processes" cleanup promised and did
            // not do. close() is idempotent (null handle guard inside), and
            // proc_close() blocks only until the SIGKILLed child is reaped.
            // On the Windows face the constructor refuses pipe children
            // outright, so a slot here is always POSIX.
            $slot['process']->close();
        }
    }
}
