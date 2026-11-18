<?php

declare(strict_types=1);

namespace SugarCraft\ColorProfile;

use React\ChildProcess\Process;
use React\EventLoop\LoopInterface;
use React\Promise\Deferred;
use React\Promise\PromiseInterface;
use SugarCraft\ColorProfile\Probe\InfocmpBinary;
use SugarCraft\ColorProfile\Probe\ProbeReport;
use SugarCraft\ColorProfile\Probe\TerminalProbe;
use SugarCraft\ColorProfile\Probe\TerminfoParser;
use SugarCraft\ColorProfile\Probe\TerminfoReplayProbe;

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
 * resolves in-place through the bounded synchronous surface ({@see Probe}),
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
 * SIGTERM → SIGKILL and the exit handler cancels BOTH rungs (spawn failures
 * never reach a handler — they are caught at start(), CP-14), so a settled
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
 * (CP-13 — the async twin of the sync surface's dump memo): a repeat call for
 * the same TERM answers from memory without re-spawning. A child the deadline
 * ladder or a signal killed answered nothing, so it mints no entry and the
 * next call may spawn again.
 *
 * Probe results are ADVISORY ONLY: the terminfo snapshot answers "what could
 * this terminal type advertise", not "what will the renderer emit" — callers
 * that must honour user suppression should read DetectionChain themselves.
 *
 * The returned promise carries no cancellation handler — cancelling it
 * does not fall back, it only detaches the caller from the result.
 *
 * {@see report()} is the full capability report on the same loop contract
 * (Sixel, KittyKeyboard, ITerm2, Hyperlinks and the TERM_PROGRAM table, not
 * only the TrueColor verdict). It is the async replacement for the
 * deprecated synchronous {@see TerminalProbe}.
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
     * SIGTERM → SIGKILL while the caller receives the chain answer.
     */
    private const PROBE_TIMEOUT_SECONDS = 5.0;

    /** Grace between the TERM and the KILL rung of the escalation ladder. */
    private const KILL_GRACE_SECONDS = 1.0;

    /**
     * CP-28 @internal test seam: shrink the two ladder rungs so a behavior
     * pin can drive a real SIGTERM→SIGKILL escalation inside a couple of
     * seconds of wall time (sugar-colorprofile-029). Null = shipped
     * defaults above. Cleared by {@see self::resetForTesting()}; no public
     * production surface reads or writes it.
     */
    private static ?float $timeoutOverride = null;
    private static ?float $graceOverride = null;

    /**
     * Arm the test seam (or pass nulls to restore the documented defaults).
     *
     * @internal — exists only so tests/AsyncProbeBoundedTest.php can prove
     * the kill ladder REAPS, not merely that the source mentions the timers.
     */
    public static function setDeadlinesForTesting(?int $probeSeconds, ?int $graceSeconds): void
    {
        self::$timeoutOverride = $probeSeconds !== null ? (float) $probeSeconds : null;
        self::$graceOverride = $graceSeconds !== null ? (float) $graceSeconds : null;
    }

    private static function timeoutSeconds(): float
    {
        return self::$timeoutOverride ?? self::PROBE_TIMEOUT_SECONDS;
    }

    private static function graceSeconds(): float
    {
        return self::$graceOverride ?? self::KILL_GRACE_SECONDS;
    }

    /**
     * Kill a child and complete its REAP synchronously, on a live loop or a
     * dead one (CP-28, sugar-colorprofile-029 — every clause below is probe-
     * evidenced against the vendored react/child-process on this box).
     *
     * react emits 'exit' only once every READ pipe has fired its close event;
     * a silent child (killed mid-busy-loop, or one that never wrote) never
     * closes them itself, and even when the pipes do close the detection may
     * fall into vendor's periodic check — which is armed on the CHILD'S loop
     * and therefore never ticks once that loop has been abandoned. So merely
     * terminate()-ing strands both the handle and (after death) a /proc Z
     * entry. Calling Process::close() straight on a dead-but-unreaped handle
     * is no good either: its own pipe-foreach exhausts the close count
     * MID-CLOSE, the handler's canonical close() nulls the handle, and the
     * suspended outer frame lands on proc_close(null) — a TypeError.
     *
     * The order that is safe in every interleaving: SIGKILL, close the
     * pipes (whichever way the handler resolves it — immediate canonical
     * close, or arming its dead-loop periodic — it has already done its
     * single close or will do nothing further), and then let close() finish
     * the job ONLY if the handle is still open: proc_close blocks until the
     * killed child is reaped and never re-enters a handler that has already
     * spent its close count.
     */
    private static function killAndReap(Process $process): void
    {
        $process->terminate(defined('SIGKILL') ? \SIGKILL : 9);
        $process->stdout?->close();
        $process->stderr?->close();
        $process->stdin?->close();

        if (\is_resource((new \ReflectionProperty($process, 'process'))->getValue($process))) {
            // Handle still open: vendor never reached its canonical close
            // (the periodic branch on a loop that will not tick, or a child
            // still mid-death when the pipes went away). Reap here —
            // proc_close() inside close() blocks until the SIGKILLed child
            // is waited, so on return the /proc entry is gone.
            $process->close();
        }
    }

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
     * one ceiling every infocmp surface shares (sync surface, async pump).
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
     * InfocmpBinary's is private to the exec surface that owns it; the two
     * tables bound the same TERM-cardinality risk and must be edited
     * together if either ever moves.
     */
    private const MEMO_MAX_ENTRIES = 256;

    /**
     * Settled answers of COMPLETED infocmp children, keyed by lowercased
     * TERM (CP-13, sugar-colorprofile-050).
     *
     * The sync surface memoizes per TERM (the dump table inside
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
     * surface caches carries the same property, and its table mints entries
     * for clean misses too). The ENV-side half of any negative answer is
     * re-derived from the CURRENT chain per call, so env drift after the
     * first probe can never be served stale. A completed child (any exit code)
     * is memoized. A child the deadline ladder or a signal killed, and a failed
     * launch, mint no entry (CP-13, per the UPSTREAM-PLAN P1-15 behaviour map):
     * each is transient (load, fork or fd pressure) and must not demote a TERM
     * that advertises TrueColor for the life of the process. The sync door
     * ({@see InfocmpBinary::runBounded()}) still records overruns and signal
     * kills as misses, so the two surfaces differ on those two verdicts only.
     * Cap-full, unsafe-TERM and absent-binary early returns never launch a
     * child and never write.
     *
     * @var array<string, ColorProfile|null>
     */
    private static array $settledMemo = [];

    /**
     * Dump verdicts for {@see report()}, keyed by lowercased TERM: the capped
     * stdout of a completed child (any exit code). A child the deadline ladder
     * or a signal killed, and a failed launch, mint no entry (CP-13), so a
     * later report may spawn again. The cardinality ceiling is
     * {@see self::MEMO_MAX_ENTRIES}.
     *
     * @var array<string, string|null>
     */
    private static array $settledDumps = [];

    /**
     * Detect the terminal color profile asynchronously.
     *
     * With an explicit {@see LoopInterface} the infocmp leg runs as a child
     * on that loop; with none, the answer is the bounded synchronous surface,
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
            // synchronous surface is the full answer — chain plus the deadline-
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
        $spawned = self::spawnInfocmp(
            $loop,
            $infocmpPath,
            $term,
            $deferred,
            static function (?int $exitCode, string $stdout) use ($chain, $memoKey): ColorProfile {
                // A completed child is a verdict whatever its exit code: its
                // printed dump is TERM-static. A signal kill (null code) is not
                // a verdict and mints no entry (CP-13), so the promotion it
                // printed is honored for this call only.
                $promoted = TerminfoParser::hasCapName($stdout, TerminfoParser::TRUECOLOR_NAMES);
                if ($exitCode !== null) {
                    self::memoWrite($memoKey, $promoted ? ColorProfile::TrueColor : null);
                }

                return $promoted ? ColorProfile::TrueColor : self::settleFromChain($chain);
            },
            static function () use ($chain): ColorProfile {
                // A deadline overrun answered nothing: no entry, so a later
                // call may retry (CP-13). The caller takes the chain answer.
                return self::settleFromChain($chain);
            },
        );
        if (!$spawned) {
            // A failed launch is transient (fork or fd pressure) and says
            // nothing about the TERM, so it mints no entry (CP-13).
            return \React\Promise\resolve(self::settleFromChain($chain));
        }

        return $deferred->promise();
    }

    /**
     * Launch one bounded infocmp child on $loop and settle $deferred from its
     * outcome. This is the single spawn ladder every loop-bound surface
     * ({@see colorProfile()}, {@see report()}) shares: the same slot table,
     * the same SIGTERM → SIGKILL deadline, the same keep-a-prefix pipes.
     *
     * $onExit maps the exit (a null code means the child was killed by a
     * signal) and its capped stdout to the settled value; $onLadder maps a
     * deadline overrun to the settled value. The deferred is resolved at most
     * once, so a late exit after the ladder fired is harmless.
     *
     * @param \Closure(?int, string): mixed $onExit
     * @param \Closure(): mixed             $onLadder
     *
     * @return bool false when the child could not be launched: nothing stays
     *              booked and the caller settles from its own fallback
     */
    private static function spawnInfocmp(
        LoopInterface $loop,
        string $infocmpPath,
        string $term,
        Deferred $deferred,
        \Closure $onExit,
        \Closure $onLadder,
    ): bool {
        // NO WRAPPER TO SWALLOW THE LADDER (CP-28, sugar-colorprofile-029):
        // react v0.6 takes a command STRING (and its constructor contract
        // stays string), PHP proc_open hands that to `sh -c` — and dash
        // FORKED the script instead of exec-ing it (probe-evidenced on this
        // box), so every ladder signal died on the untrapped wrapper while
        // the wedged child survived ORPHANED, still holding the child pipes
        // open — which additionally starved react's pipe-close exit
        // detection forever. Leading `exec ` replaces the wrapper in place:
        // one process, tracked pid == signaled pid, the child's own TERM
        // disposition decides, and every field still rides
        // escapeshellarg() per the repo-wide CLI law (AGENTS.md).
        $command = 'exec ' . \escapeshellarg($infocmpPath) . ' -1 ' . \escapeshellarg($term);

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
                'deadline' => \microtime(true) + self::timeoutSeconds() + self::graceSeconds(),
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
                self::killAndReap($process);
            }

            return false;
        }

        // react/child-process 0.6 exposes the child pipes as stream PROPERTIES
        // (created inside start()); Process itself emits only 'exit'. Listening
        // for 'stdout'/'stderr' EVENTS silently never fires — the pump below
        // reads the streams directly.
        // The cut is exact, not per-chunk: a stream read can carry up to a full
        // pipe buffer, and appending it whole would let the prefix overshoot the
        // ceiling and pull a cap name past it into the scan. The sync door
        // (InfocmpBinary::execOnce) cuts the same way.
        $stdout = '';
        $process->stdout?->on('data', function ($chunk) use (&$stdout): void {
            $room = self::STDOUT_CAP_BYTES - \strlen($stdout);
            if ($room > 0) {
                $stdout .= \substr((string) $chunk, 0, $room);
            }
        });

        // The child's stderr must be drained or infocmp can block writing to
        // a full pipe and die on the SIGTERM→SIGKILL ladder over a cosmetic warning.
        // A bounded prefix is retained; everything past the cap is discarded.
        $stderr = '';
        $process->stderr?->on('data', function ($chunk) use (&$stderr): void {
            $room = self::STDERR_CAP_BYTES - \strlen($stderr);
            if ($room > 0) {
                $stderr .= \substr((string) $chunk, 0, $room);
            }
        });

        // Bounded-child ladder: at the ceiling TERM the child, a grace timer
        // later KILL it, and settle the caller with the chain answer. A hung
        // infocmp can therefore never strand the promise OR the slot.
        $graceTimer = null;
        $laddered = false;
        $timeoutTimer = $loop->addTimer(self::timeoutSeconds(), static function () use ($process, $loop, $deferred, $release, $onLadder, &$graceTimer, &$laddered): void {
            $laddered = true;
            $process->terminate();
            $graceTimer = $loop->addTimer(self::graceSeconds(), static function () use ($process): void {
                self::killAndReap($process);
            });
            $release();
            $deferred->resolve($onLadder());
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
        $process->on('exit', function (?int $exitCode, ?int $signal = null) use ($deferred, &$stdout, &$laddered, $release, $cancelLadder, $onExit): void {
            $cancelLadder();
            $release();
            // The deadline rung already settled this verdict through $onLadder;
            // a late exit must not re-run $onExit, which would memoize the
            // partial dump of a child the ladder killed (CP-13).
            if ($laddered) {
                return;
            }
            $deferred->resolve($onExit($exitCode, $stdout));
        });

        // There is deliberately no 'error' listener: react/child-process
        // 0.6.x never emits 'error' on Process (vendor grep: 'exit' is the
        // only event it forwards, and a spawn that fails dies inside
        // start() — CP-14's catch already settles the caller and frees the
        // cap slot there). The old block could not fire.

        return true;
    }

    /**
     * Full terminal capability report on the same loop contract as
     * {@see colorProfile()}: the infocmp leg runs as a child on the loop you
     * pass in, and the promise settles with the {@see ProbeReport} the sync
     * pipeline composes, not a second implementation of it.
     *
     * The composition is shared by construction. A recording pass runs the
     * sync pipeline and notes whether its terminfo leg asked for a dump (every
     * gate, including suppression, TERM shape and the binary, has run by then,
     * so a request means a child is genuinely needed). A replaying pass runs
     * the same pipeline with the child's capped dump at that leg's seam.
     *
     * Without a loop the promise resolves in place through the bounded sync
     * surface, as {@see colorProfile()} does. With a loop, the report is the
     * environment answer alone when no child can be spawned (cap reached,
     * binary missing, spawn failure). Otherwise it is the replayed dump, and a
     * timed-out or signal-killed child replays as "no dump", the same fail-open
     * the sync door gives.
     *
     * @param ?LoopInterface $loop Loop instance to spawn the child on; null resolves synchronously
     *
     * @return PromiseInterface<ProbeReport>
     */
    public static function report(?LoopInterface $loop = null): PromiseInterface
    {
        if ($loop === null) {
            // Same policy as colorProfile(): nothing is parked on a loop the
            // caller may never run; the bounded sync door answers in place.
            return \React\Promise\resolve(TerminalProbe::run());
        }

        $recording = TerminfoReplayProbe::recording();
        $environmentReport = $recording->runProbe();
        $term = $recording->requestedTerm();
        if ($term === null) {
            return \React\Promise\resolve($environmentReport);
        }

        $memoKey = \strtolower($term);
        if (\array_key_exists($memoKey, self::$settledDumps)) {
            return \React\Promise\resolve(TerminfoReplayProbe::replaying(self::$settledDumps[$memoKey])->runProbe());
        }

        self::reclaimStaleSlots();

        $infocmpPath = InfocmpBinary::path();
        if (\count(self::$inFlight) >= self::MAX_CONCURRENT_PROBES || $infocmpPath === null) {
            return \React\Promise\resolve($environmentReport);
        }

        $deferred = new Deferred();
        $spawned = self::spawnInfocmp(
            $loop,
            $infocmpPath,
            $term,
            $deferred,
            static function (?int $exitCode, string $stdout) use ($memoKey): ?string {
                // A signal kill arrives with a null code (react takes it from
                // its status refresh). Its partial stdout is no verdict: replaying
                // it would build the report from a truncated terminfo dump, and
                // memoizing it would make the truncation permanent. So it replays
                // as "no dump" and mints no entry (CP-13).
                if ($exitCode === null) {
                    return null;
                }

                // A completed child keeps its capped stdout as the verdict,
                // whatever its exit code.
                self::dumpMemoWrite($memoKey, $stdout);

                return $stdout;
            },
            static function (): ?string {
                // A deadline overrun answered nothing: no entry (CP-13), so a
                // later report may retry.
                return null;
            },
        );
        if (!$spawned) {
            // A failed launch is transient, so it mints no entry (CP-13).
            return \React\Promise\resolve($environmentReport);
        }

        return $deferred->promise()->then(
            static fn (?string $dump): ProbeReport => TerminfoReplayProbe::replaying($dump)->runProbe(),
        );
    }

    /**
     * Sole write site of the dump memo behind {@see report()}, enforcing the
     * same cardinality ceiling as {@see memoWrite()}.
     */
    private static function dumpMemoWrite(string $key, ?string $dump): void
    {
        if (\count(self::$settledDumps) >= self::MEMO_MAX_ENTRIES) {
            self::$settledDumps = [];
        }

        self::$settledDumps[$key] = $dump;
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
        self::$settledDumps = [];
        self::$timeoutOverride = null;
        self::$graceOverride = null;
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
            // CP-14 established that terminate() alone strands a zombie plus
            // its pipe fds — the child must be REAPED, not only killed. Its
            // remedy called Process::close() straight, which type-crashes
            // when the child is dead-but-unreaped: close()'s own pipe-foreach
            // exhausts react's close-count mid-call and the handler's nested
            // canonical close nulls the handle under the suspended frame
            // (proc_close(null)). killAndReap (CP-28) closes the pipes
            // FIRST, then finishes close() only if the handle is still open
            // — proc_close() blocks out the reaping of a killed child even
            // when the abandoned loop can never tick vendor's periodic check.
            self::killAndReap($slot['process']);
        }
    }
}
