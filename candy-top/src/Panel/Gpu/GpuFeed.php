<?php

declare(strict_types=1);

namespace SugarCraft\Top\Panel\Gpu;

use React\Promise\PromiseInterface;
use SugarCraft\Top\Collect\GpuDevice;
use SugarCraft\Top\Collect\GpuSnapshot;
use SugarCraft\Top\Source\CollectorSource;
use SugarCraft\Top\Source\Source;

use function React\Promise\reject;
use function React\Promise\resolve;

/**
 * The ONE accelerator sampler of an App: the cpu box's GPU rows and
 * graphs, the gpu boxes and the proc box's Gpu% / GMem columns all read
 * the snapshots of this feed, so a host is sampled once per round however
 * many boxes show it (before it, each box ran its own collector — three
 * nvidia-smi cadences and three DRM fdinfo walks on a host showing all of
 * them; btop collects GPUs once per update too).
 *
 * Consumers ask from their collect() Cmds ({@see request()}); the feed
 * never samples on its own, so nothing runs while no consumer is shown or
 * wants GPUs (#1858).
 *
 * Rounds: a request from a consumer that has already received the latest
 * snapshot starts a new sample (each consumer asks once per data tick, so
 * the first to ask on a tick starts it); every other consumer gets that
 * snapshot without a second sample. A box toggled on mid-tick therefore
 * reuses the current snapshot instead of resampling, and a feed with one
 * consumer samples on every request, as a box with its own source did.
 *
 * Demand: each round samples for the UNION of the {@see GpuDemand}s of
 * the consumers that asked in this round or the previous one — shown_gpus
 * vendors, and per-process rows only while some consumer still wants them
 * (a consumer that stopped asking, because its box is hidden or no longer
 * needs GPUs, drops out after one round, so a hidden proc box stops the
 * compute-apps / pmon spawns). Per-process collection is flipped on the
 * collector only on a change ({@see GpuSampling::tuneFor()}), so pmon's
 * memoized "unsupported" and the spawns' backoffs survive.
 *
 * Non-blocking: a live collector is sampled with `sampleAsync()` — the
 * nvidia-smi spawns run as loop-driven children
 * ({@see \SugarCraft\Top\Collect\Gpu\SmiProcess::launch()}), sysfs and
 * fdinfo reads stay synchronous. Between nvidia-smi queries (every 5 s)
 * the sample settles at once. While a query is in flight a WAITING
 * request (the gpu boxes, the cpu box's GPU part) gets the in-flight
 * promise; a non-waiting one (the proc box's routine sample, whose list
 * must not lag behind pmon) gets the previous snapshot at once. A FRESH
 * request ({@see request()} `$fresh`: the proc box's `g` / gpu sort needs
 * per-process values now) waits, and starts a round with per-process
 * collection if the latest snapshot has none — the collector then queries
 * at once ({@see \SugarCraft\Top\Collect\Gpu::withProcesses()}).
 *
 * The holds stay with the consumers: every box folds the same snapshot
 * through its own {@see GpuHold} (#1008, bounded) or
 * {@see \SugarCraft\Top\Panel\Proc\GpuUsage} (hold / fresh laws).
 *
 * Mutable on purpose — the shared state between immutable panels, like
 * a connection they hold: panels never touch it from update() or paint(),
 * only from Cmds. As a {@see Source} its {@see sample()} is a
 * non-waiting request; panels call {@see request()}.
 */
final class GpuFeed implements Source
{
    /** @var array<string, GpuDemand> consumer => its latest demand */
    private array $demands = [];

    /** @var array<string, true> consumers that asked since the current round started */
    private array $window = [];

    /** @var array<string, true> consumers that asked during the previous round */
    private array $previous = [];

    /** @var array<string, int> consumer => sequence number of the snapshot it last received */
    private array $seen = [];

    private ?GpuSnapshot $latest = null;

    /** Completed rounds. */
    private int $seq = 0;

    /** Rounds started (a diagnostic: one per tick, however many consumers). */
    private int $rounds = 0;

    /** @var PromiseInterface<GpuSnapshot>|null */
    private ?PromiseInterface $inFlight = null;

    private bool $inFlightProcesses = false;

    /** Why the latest round failed; null once a round succeeds. */
    private ?\Throwable $error = null;

    private function __construct(
        private Source $source,
    ) {
    }

    /**
     * A feed over `$source`: `Platform::gpu()` (Accelerators — sampled
     * through the loop), a {@see \SugarCraft\Top\Source\Fake\FakeGpu}, or
     * any GpuSnapshot source. A feed passed in is returned as is, so a
     * panel can take either.
     */
    public static function of(Source $source): self
    {
        return $source instanceof self ? $source : new self($source);
    }

    /**
     * The snapshot for `$consumer` (a stable name: `cpu`, `gpu`, `proc`).
     *
     * @param bool $wait  true: resolve with the snapshot of the round in flight; false:
     *                    while a sample is in flight, resolve at once with the previous one
     * @param bool $fresh the consumer needs per-process rows NOW (implies waiting): a
     *                    latest snapshot without them starts a round that collects them
     * @return PromiseInterface<GpuSnapshot> settled at once unless a spawn is in flight
     */
    public function request(string $consumer, GpuDemand $demand, bool $wait = true, bool $fresh = false): PromiseInterface
    {
        $this->demands[$consumer] = $demand;
        $this->window[$consumer] = true;
        $needsProcesses = $fresh && $demand->processes;
        if ($this->inFlight !== null) {
            if ($needsProcesses && !$this->inFlightProcesses) {
                // The round in flight was not asked for processes: follow it with one that is.
                return $this->inFlight->then(fn (): PromiseInterface => $this->request($consumer, $demand, true, true));
            }
            if ($wait || $fresh) {
                $this->seen[$consumer] = $this->seq + 1;

                return $this->inFlight;
            }

            return resolve($this->current());
        }
        // A fresh ask is served by any landed snapshot that has per-process rows;
        // a routine one by a snapshot it has not received yet (else: next round).
        $stale = $this->latest === null || ($needsProcesses
            ? $this->latest->processes === null
            : ($this->seen[$consumer] ?? -1) >= $this->seq);
        if (!$stale) {
            $this->seen[$consumer] = $this->seq;

            return resolve($this->latest);
        }

        return $this->round($consumer, $wait || $fresh);
    }

    /** The latest snapshot (empty before the first sample). */
    public function current(): GpuSnapshot
    {
        return $this->latest ?? new GpuSnapshot([]);
    }

    /** The source the next round samples (tests / diagnostics). */
    public function source(): Source
    {
        return $this->source;
    }

    /** Rounds started so far. */
    public function rounds(): int
    {
        return $this->rounds;
    }

    /**
     * The error the latest round failed with (a collector bug: the
     * collectors are total), null after a successful round. The failed
     * round itself reads as unmeasured devices, never as a rejection.
     */
    public function lastError(): ?\Throwable
    {
        return $this->error;
    }

    /** Whether a sample is in flight (an nvidia-smi child is running). */
    public function busy(): bool
    {
        return $this->inFlight !== null;
    }

    /**
     * Source view: a non-waiting request on behalf of `source` (the
     * feed is its own next source). Never blocks, never throws for a
     * pending sample.
     *
     * @return array{0: GpuSnapshot, 1: self}
     */
    public function sample(): array
    {
        $snapshot = null;
        $this->request('source', GpuDemand::new(), false)->then(static function (GpuSnapshot $s) use (&$snapshot): void {
            $snapshot = $s;
        });

        return [$snapshot ?? $this->current(), $this];
    }

    /**
     * @return PromiseInterface<GpuSnapshot>
     */
    private function round(string $trigger, bool $wait): PromiseInterface
    {
        $demand = null;
        foreach (array_keys($this->window + $this->previous) as $consumer) {
            $d = $this->demands[$consumer] ?? null;
            if ($d !== null) {
                $demand = $demand === null ? $d : $demand->union($d);
            }
        }
        $demand ??= GpuDemand::new();
        $this->previous = $this->window;
        $this->window = [$trigger => true];
        $this->rounds++;
        $before = $this->current();
        $this->seen[$trigger] = $this->seq + 1;

        $done = false;
        $round = self::sampleOf(GpuSampling::tuneFor($this->source, $demand))->then(
            function (array $result) use (&$done): GpuSnapshot {
                [$snapshot, $next] = $result;
                $this->source = $next instanceof Source ? $next : $this->source;
                $this->latest = $snapshot instanceof GpuSnapshot ? $snapshot : new GpuSnapshot([]);
                $this->error = null;
                $this->seq++;
                $this->inFlight = null;
                $done = true;

                return $this->latest;
            },
            function (\Throwable $e) use (&$done): GpuSnapshot {
                // A failed sample, never a rejection: a round the proc box started
                // (resolve($before)) has nobody waiting on it, and react/promise
                // prints an unhandled rejection to stderr — over the TUI. Every
                // device reads unmeasured, so the consumers' holds (#1008, bounded)
                // treat it like a failed query; the source is kept for a retry and
                // the error stays readable through lastError().
                $this->error = $e;
                $this->latest = self::failed($this->current());
                $this->seq++;
                $this->inFlight = null;
                $done = true;

                return $this->latest;
            },
        );
        if ($done) {
            return $round;
        }
        $this->inFlight = $round;
        $this->inFlightProcesses = $demand->processes;

        return $wait ? $round : resolve($before);
    }

    /**
     * One sample of `$source`: through the loop when its collector can
     * (`sampleAsync()`), else synchronously.
     *
     * @return PromiseInterface<array{0: object, 1: Source}>
     */
    private static function sampleOf(Source $source): PromiseInterface
    {
        if ($source instanceof CollectorSource && method_exists($collector = $source->collector(), 'sampleAsync')) {
            return $collector->sampleAsync()->then(static fn (array $r): array => [$r[0], CollectorSource::of($r[1])]);
        }

        try {
            return resolve($source->sample());
        } catch (\Throwable $e) {
            return reject($e);
        }
    }

    /** `$last` with every device a stand-in that measured nothing. */
    private static function failed(GpuSnapshot $last): GpuSnapshot
    {
        $unmeasured = static fn (GpuDevice $d): GpuDevice => $d->unmeasured();

        return new GpuSnapshot(array_map($unmeasured, $last->devices), null, array_map($unmeasured, $last->npus));
    }
}
