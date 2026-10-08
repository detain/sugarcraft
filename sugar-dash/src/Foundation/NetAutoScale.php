<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Foundation;

/**
 * btop's network-graph auto-scaling: a per-direction ceiling that only moves
 * after a sustained run of samples above it (grow) or far below it (shrink),
 * so a single spike or lull never makes the graph jump.
 *
 * Immutable like its {@see Threshold} neighbour: {@see offer()} returns the
 * next state, which drops straight into a TEA panel model's `update()`.
 *
 * Law (per direction, download processed before upload):
 * - speed > ceiling            → fast counter +1, slow counter -1 (floored 0)
 * - else ceiling > 10 KiB and speed < ceiling / 10
 *                              → slow counter +1, fast counter -1 (floored 0)
 * - a counter reaching 5 (or a forced rescale) sets the ceiling to
 *   max(trunc(avg × 1.3 fast | 3.0 slow), 10 KiB), avg being the integer
 *   mean of the last 5 samples once MORE than 5 are held, else the current
 *   sample; both counters of that direction reset.
 * - sync: a direction slower than the other direction's latest speed does not
 *   count, and a rescale copies its ceiling onto the other direction (whose
 *   counters reset) and ends the pass.
 *
 * Not modelled: btop trims its sample deque to `width * 2`; only the last 6
 * samples are kept here, which differs only for a graph narrower than 3
 * columns (a deque of fewer than 6 samples never averages in btop).
 *
 * Speeds near PHP_INT_MAX saturate instead of overflowing (btop's uint64
 * would wrap); no real link reaches that range.
 *
 * Mirrors aristocratos/btop Net::collect (src/linux/btop_collect.cpp, the
 * max_count / graph_max / rescale block).
 */
final class NetAutoScale
{
    /** btop's `10 << 10` floor — the ceiling never drops below 10 KiB/s. */
    public const FLOOR = 10 << 10;

    /** Counter value at which a rescale fires. */
    public const TRIGGER = 5;

    public const DOWNLOAD = 'download';
    public const UPLOAD = 'upload';

    private const FAST_FACTOR = 1.3;
    private const SLOW_FACTOR = 3.0;

    /** btop averages exactly the last 5 samples, and only once it holds more than 5. */
    private const WINDOW = 5;

    /**
     * @param array{download:int, upload:int} $max
     * @param array{download:array{0:int,1:int}, upload:array{0:int,1:int}} $counts [fast, slow]
     * @param array{download:int, upload:int} $speed latest sample per direction
     * @param array{download:list<int>, upload:list<int>} $history newest last, ≤ WINDOW + 1
     */
    private function __construct(
        public readonly bool $sync,
        private readonly array $max,
        private readonly array $counts,
        private readonly array $speed,
        private readonly array $history,
        private readonly bool $rescalePending,
        public readonly bool $rescaled,
    ) {}

    /**
     * Fresh scaler. Like btop's `Net::rescale{true}`, the first offer always
     * rescales, so the ceilings start at the first sample rather than 0.
     */
    public static function new(bool $sync = false): self
    {
        return new self(
            sync: $sync,
            max: [self::DOWNLOAD => 0, self::UPLOAD => 0],
            counts: [self::DOWNLOAD => [0, 0], self::UPLOAD => [0, 0]],
            speed: [self::DOWNLOAD => 0, self::UPLOAD => 0],
            history: [self::DOWNLOAD => [], self::UPLOAD => []],
            rescalePending: true,
            rescaled: false,
        );
    }

    /** Toggle btop's `net_sync` (shared download/upload ceiling). */
    public function withSync(bool $sync): self
    {
        return $this->mutate(sync: $sync);
    }

    /**
     * Feed one collect tick (bytes/sec per direction) and return the next state.
     *
     * Negative speeds are clamped to 0 — btop's speeds are unsigned.
     */
    public function offer(int $download, int $upload): self
    {
        $samples = [self::DOWNLOAD => max(0, $download), self::UPLOAD => max(0, $upload)];
        $max = $this->max;
        $counts = $this->counts;
        $speed = $this->speed;
        $history = $this->history;

        foreach ([self::DOWNLOAD, self::UPLOAD] as $dir) {
            $s = $samples[$dir];
            $speed[$dir] = $s;
            $history[$dir][] = $s;
            if (count($history[$dir]) > self::WINDOW + 1) {
                array_shift($history[$dir]);
            }

            // Upload compares against this tick's download, download against
            // the previous tick's upload — btop updates them in that order.
            if ($this->sync && $s < $speed[self::other($dir)]) {
                continue;
            }
            if ($s > $max[$dir]) {
                $counts[$dir][0]++;
                if ($counts[$dir][1] > 0) {
                    $counts[$dir][1]--;
                }
            } elseif ($max[$dir] > self::FLOOR && $s < intdiv($max[$dir], 10)) {
                $counts[$dir][1]++;
                if ($counts[$dir][0] > 0) {
                    $counts[$dir][0]--;
                }
            }
        }

        $rescaled = false;
        foreach ([self::DOWNLOAD, self::UPLOAD] as $dir) {
            $synced = false;
            foreach ([0, 1] as $sel) {
                if (!$this->rescalePending && $counts[$dir][$sel] < self::TRIGGER) {
                    continue;
                }
                $hist = $history[$dir];
                $avg = count($hist) > self::WINDOW
                    ? self::mean(array_slice($hist, -self::WINDOW))
                    : $speed[$dir];
                $max[$dir] = max(
                    self::saturate($avg * ($sel === 0 ? self::FAST_FACTOR : self::SLOW_FACTOR)),
                    self::FLOOR,
                );
                $counts[$dir] = [0, 0];
                $rescaled = true;
                $synced = $this->sync;
                break;
            }
            if ($synced) {
                $other = self::other($dir);
                $max[$other] = $max[$dir];
                $counts[$other] = [0, 0];
                break;
            }
        }

        return $this->mutate(
            max: $max,
            counts: $counts,
            speed: $speed,
            history: $history,
            rescalePending: false,
            rescaled: $rescaled,
        );
    }

    /**
     * Arm a rescale for the next {@see offer()} — btop's interface-change /
     * loss path (and `Net::rescale = true` after a config change). Counters
     * reset. Pass the newly selected interface's recent samples (oldest
     * first) to swap the averaging window, since btop averages the NEW
     * interface's bandwidth deque. These are the samples from BEFORE the
     * coming tick: the next offer() appends its own sample, so do not include
     * it here or it is counted twice.
     *
     * @param list<int>|null $downloadHistory
     * @param list<int>|null $uploadHistory
     */
    public function forceRescale(?array $downloadHistory = null, ?array $uploadHistory = null): self
    {
        $history = $this->history;
        if ($downloadHistory !== null) {
            $history[self::DOWNLOAD] = self::window($downloadHistory);
        }
        if ($uploadHistory !== null) {
            $history[self::UPLOAD] = self::window($uploadHistory);
        }
        return $this->mutate(
            counts: [self::DOWNLOAD => [0, 0], self::UPLOAD => [0, 0]],
            history: $history,
            rescalePending: true,
        );
    }

    /** Current download ceiling in bytes/sec (0 until the first offer). */
    public function downloadMax(): int
    {
        return $this->max[self::DOWNLOAD];
    }

    /** Current upload ceiling in bytes/sec (0 until the first offer). */
    public function uploadMax(): int
    {
        return $this->max[self::UPLOAD];
    }

    /**
     * Ceiling for one direction.
     *
     * @throws \InvalidArgumentException on an unknown direction
     */
    public function maxFor(string $direction): int
    {
        return $this->max[self::direction($direction)];
    }

    /**
     * The [fast, slow] hysteresis counters for one direction.
     *
     * @return array{0:int, 1:int}
     * @throws \InvalidArgumentException on an unknown direction
     */
    public function counters(string $direction): array
    {
        return $this->counts[self::direction($direction)];
    }

    /** Whether a rescale is armed for the next offer. */
    public function rescalePending(): bool
    {
        return $this->rescalePending;
    }

    /**
     * Integer mean, as btop's `accumulate(...) / 5`; a sum that overflows
     * int (array_sum promotes to float) falls back to a saturated float mean
     * rather than letting intdiv() throw a TypeError.
     *
     * @param list<int> $samples
     */
    private static function mean(array $samples): int
    {
        $sum = array_sum($samples);
        return is_int($sum) ? intdiv($sum, count($samples)) : self::saturate($sum / count($samples));
    }

    /** Truncating float→int cast (btop's `uint64_t(...)`) clamped to PHP_INT_MAX. */
    private static function saturate(float|int $value): int
    {
        if (is_int($value)) {
            return $value;
        }
        // (float) PHP_INT_MAX rounds up to 2^63, so >= catches every out-of-range value.
        return $value >= (float) PHP_INT_MAX ? PHP_INT_MAX : (int) $value;
    }

    private static function other(string $dir): string
    {
        return $dir === self::DOWNLOAD ? self::UPLOAD : self::DOWNLOAD;
    }

    private static function direction(string $direction): string
    {
        if ($direction !== self::DOWNLOAD && $direction !== self::UPLOAD) {
            throw new \InvalidArgumentException(sprintf(
                'Direction must be "%s" or "%s", got "%s"',
                self::DOWNLOAD,
                self::UPLOAD,
                $direction,
            ));
        }
        return $direction;
    }

    /**
     * @param list<int> $samples
     * @return list<int>
     */
    private static function window(array $samples): array
    {
        $samples = array_map(static fn(int $s): int => max(0, $s), array_values($samples));
        return array_slice($samples, -(self::WINDOW + 1));
    }

    /**
     * @param array{download:int, upload:int}|null $max
     * @param array{download:array{0:int,1:int}, upload:array{0:int,1:int}}|null $counts
     * @param array{download:int, upload:int}|null $speed
     * @param array{download:list<int>, upload:list<int>}|null $history
     */
    private function mutate(
        ?bool $sync = null,
        ?array $max = null,
        ?array $counts = null,
        ?array $speed = null,
        ?array $history = null,
        ?bool $rescalePending = null,
        ?bool $rescaled = null,
    ): self {
        return new self(
            sync: $sync ?? $this->sync,
            max: $max ?? $this->max,
            counts: $counts ?? $this->counts,
            speed: $speed ?? $this->speed,
            history: $history ?? $this->history,
            rescalePending: $rescalePending ?? $this->rescalePending,
            rescaled: $rescaled ?? $this->rescaled,
        );
    }
}
