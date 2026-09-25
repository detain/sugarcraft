<?php

declare(strict_types=1);

namespace SugarCraft\Query\Admin\Calc;

use SugarCraft\Query\Admin\DsnParser;

/**
 * Collects the CPU/Load series for Workbench's Server Status parity.
 *
 * WHY two modes: MySQL Workbench samples the host's own CPU/load because it
 * runs on the machine (or tunnels over its SSH shell). A pure SQL client
 * gets NO OS counters through the wire protocol — SHOW GLOBAL STATUS only
 * carries server-internal numbers. So for local servers we read /proc
 * (loadavg + aggregate cpu jiffies delta), and for remote servers we sample
 * an honestly labeled "busy proxy": max(Threads_running/Threads_connected,
 * Threads_connected/max_connections), clamped to 0..1. The proxy answers
 * "how saturated does the server look right now", never "what is the OS CPU"
 * — the UI labels it accordingly so nobody mistakes one for the other.
 *
 * Stateful by contract (like Sampler): each sample() appends at most one
 * point per window; windows are fixed-size slices trimmed oldest-first,
 * which keeps 120 seconds of history at the 1s admin cadence. Rendering
 * never touches the filesystem — only these windows are read.
 *
 * File paths and the clock are injectable so tests run against temp fixture
 * files instead of a real /proc, with no timing dependence.
 */
final class HostLoadSampler
{
    /** Points retained per series; 120 samples == 2 minutes at the 1s cadence. */
    public const int WINDOW = 120;

    /** Host tokens that mean "the machine this client process runs on". */
    private const array LOOPBACK_HOSTS = ['localhost', '127.0.0.1', '::1'];

    /** @var list<float> load-average points, oldest first */
    private array $load = [];

    /** @var list<float> busy-fraction points (0..1), oldest first */
    private array $busy = [];

    /** Aggregate jiffies of the previous /proc/stat read: [total, busy]. */
    private ?array $previousJiffies = null;

    private ?float $lastSampleAt = null;

    /**
     * @param \Closure(): float $wallClock monotonic wall clock for sample stamps
     */
    private function __construct(
        private readonly HostLoadMode $mode,
        private readonly string $loadavgPath,
        private readonly string $statPath,
        private readonly \Closure $wallClock,
    ) {}

    /**
     * Sample the machine's own /proc counters.
     *
     * @param string|null $loadavgPath override for tests (temp fixture file)
     * @param string|null $statPath    override for tests (temp fixture file)
     * @param \Closure(): float|null   $wallClock      override for tests (scripted time)
     */
    public static function local(
        ?string $loadavgPath = null,
        ?string $statPath = null,
        ?\Closure $wallClock = null,
    ): self {
        return new self(
            HostLoadMode::HostProc,
            $loadavgPath ?? '/proc/loadavg',
            $statPath ?? '/proc/stat',
            $wallClock ?? static fn (): float => microtime(true),
        );
    }

    /**
     * Remote server: busy-proxy only, never touches the filesystem.
     *
     * @param \Closure(): float|null $wallClock override for tests (scripted time)
     */
    public static function proxy(?\Closure $wallClock = null): self
    {
        return new self(HostLoadMode::BusyProxy, '', '', $wallClock ?? static fn (): float => microtime(true));
    }

    /**
     * Choose the mode from a DSN — the boundary parse that keeps every
     * later call trusted (no re-inspecting the DSN inside sample()).
     *
     * Unparseable or host-less DSNs fall to the proxy: claiming OS stats
     * for an unknown host would be the dishonest direction, and an empty
     * dsn() from fakes/tests lands here too.
     */
    public static function forDsn(string $dsn, ?\Closure $wallClock = null): self
    {
        if ($dsn === '') {
            return self::proxy($wallClock);
        }

        // A unix socket means the mysqld shares this box's kernel tables.
        if (str_contains($dsn, 'unix_socket=') || str_contains($dsn, 'socket=')) {
            return self::local(null, null, $wallClock);
        }

        $host = DsnParser::extract($dsn, 'host');
        if ($host === null) {
            return self::proxy($wallClock);
        }

        $normalized = strtolower(trim($host, '[]'));

        return in_array($normalized, self::LOOPBACK_HOSTS, true)
            ? self::local(null, null, $wallClock)
            : self::proxy($wallClock);
    }

    /**
     * Append one sample. Local mode reads /proc; proxy mode consumes the
     * latest status/server variable snapshots.
     *
     * @param array<string, string>|null $statusVars SHOW GLOBAL STATUS frame
     * @param array<string, string>|null $serverVars SHOW VARIABLES frame (max_connections)
     */
    public function sample(?array $statusVars, ?array $serverVars): void
    {
        if ($this->mode === HostLoadMode::HostProc) {
            $this->sampleProc();
        } else {
            $this->sampleProxy($statusVars, $serverVars);
        }

        $this->lastSampleAt = ($this->wallClock)();
    }

    public function mode(): HostLoadMode
    {
        return $this->mode;
    }

    /**
     * @return list<float>
     */
    public function loadWindow(): array
    {
        return $this->load;
    }

    /**
     * @return list<float>
     */
    public function busyWindow(): array
    {
        return $this->busy;
    }

    public function latestLoad(): ?float
    {
        $last = $this->load === [] ? null : $this->load[count($this->load) - 1];

        return $last;
    }

    public function latestBusy(): ?float
    {
        $last = $this->busy === [] ? null : $this->busy[count($this->busy) - 1];

        return $last;
    }

    public function lastSampleAt(): ?float
    {
        return $this->lastSampleAt;
    }

    /**
     * loadavg first field + busy fraction from aggregate cpu jiffies delta.
     *
     * Unreadable /proc files silently skip their own point: a missing
     * sample is honest gaps in the window, while a fabricated zero would
     * draw a flat line that lies about an idle machine.
     */
    private function sampleProc(): void
    {
        $load = $this->readLoadAverage();
        if ($load !== null) {
            $this->push($this->load, $load);
        }

        $jiffies = $this->readCpuJiffies();
        if ($jiffies === null) {
            return;
        }

        [$total, $busy] = $jiffies;
        $previous = $this->previousJiffies;
        $this->previousJiffies = $jiffies;

        if ($previous === null) {
            return; // first read has no delta to divide by
        }

        $deltaTotal = $total - $previous[0];
        if ($deltaTotal <= 0.0) {
            return; // jiffy aliasing: same tick sampled twice, no signal
        }

        $ratio = ($busy - $previous[1]) / $deltaTotal;
        $this->push($this->busy, max(0.0, min(1.0, $ratio)));
    }

    /**
     * Normalised server activity, labeled proxy — never shown as OS CPU.
     */
    private function sampleProxy(?array $statusVars, ?array $serverVars): void
    {
        if ($statusVars === null || $statusVars === []) {
            return;
        }

        $running = (float) ($statusVars['Threads_running'] ?? 0);
        $connected = (float) ($statusVars['Threads_connected'] ?? 0);
        $maxConnections = (float) ($serverVars['max_connections'] ?? 0);

        // Share of open connections actively running, and share of the
        // configured ceiling in use — the worse of the two saturations.
        $runningShare = $connected > 0 ? $running / $connected : 0.0;
        $capacityShare = $maxConnections > 0 ? $connected / $maxConnections : 0.0;

        $this->push($this->busy, max(0.0, min(1.0, max($runningShare, $capacityShare))));
    }

    private function readLoadAverage(): ?float
    {
        $contents = @file_get_contents($this->loadavgPath);
        if ($contents === false) {
            return null;
        }

        // "0.52 0.58 0.59 2/389 12345" — 1-minute average is field zero in
        // every Linux shape; BSD-style labels do not occur under /proc.
        $first = preg_split('/\s+/', trim($contents))[0] ?? '';

        return is_numeric($first) ? (float) $first : null;
    }

    /**
     * @return array{0: float, 1: float}|null [total jiffies, busy jiffies]
     */
    private function readCpuJiffies(): ?array
    {
        $contents = @file_get_contents($this->statPath);
        if ($contents === false) {
            return null;
        }

        foreach (explode("\n", $contents) as $line) {
            if (!str_starts_with($line, 'cpu ')) {
                continue; // per-cpu rows: the aggregate carries the whole box
            }

            $fields = preg_split('/\s+/', trim($line));
            array_shift($fields); // drop the "cpu" label

            $values = array_map('floatval', array_values(array_filter(
                $fields ?? [],
                static fn (string $field): bool => $field !== '' && is_numeric($field),
            )));
            if ($values === []) {
                return null;
            }

            $total = array_sum($values);
            // Field order: user nice system idle iowait irq softirq steal…
            $idle = ($values[3] ?? 0.0) + ($values[4] ?? 0.0);

            return [$total, $total - $idle];
        }

        return null;
    }

    /**
     * Append and trim to the fixed window, oldest-first.
     *
     * @param list<float> $window
     */
    private function push(array &$window, float $value): void
    {
        $window[] = $value;
        if (count($window) > self::WINDOW) {
            array_shift($window);
        }
    }
}
