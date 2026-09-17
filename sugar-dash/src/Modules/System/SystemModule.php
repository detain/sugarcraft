<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Modules\System;

use SugarCraft\Core\Cmd;
use SugarCraft\Core\Msg;
use SugarCraft\Dash\Module\BaseModule;
use SugarCraft\Dash\Module\ProcAvailability;

/**
 * System module that displays CPU, memory, and uptime statistics.
 *
 * Mirrors the lattice system module pattern.
 * Uses Cmd::tick() for periodic refresh.
 */
final class SystemModule extends BaseModule
{
    private const HISTORY_SIZE = 30;
    private const TICK_INTERVAL = 2.0;

    /** @var array<int> */
    private array $cpuHistory = [];

    /** @var array<int> */
    private array $memHistory = [];

    // Percentages carry ProcAvailability::UNMEASURED (-1.0) once a /proc door
    // has proven itself unreadable (COMP-2 option b); the 0.0 initial value is
    // the pre-first-tick placeholder on EVERY platform and stays as-is.
    private float $cpuLoad = 0.0;
    private float $memLoad = 0.0;
    private float $gpuLoad = -1.0; // -1.0 = "no GPU present" (hides the row)
    private string $uptime = 'unknown';

    public function name(): string
    {
        return 'system';
    }

    public function init(): ?\Closure
    {
        return Cmd::tick(self::TICK_INTERVAL, static fn(): Msg => new RefreshMsg());
    }

    public function update(Msg $msg): array
    {
        // Compute fresh state without mutating $this — withSystemState() reads
        // /proc/* files directly and accumulates history internally.
        $newModule = $this->withSystemState();
        if ($msg instanceof RefreshMsg) {
            return [$newModule, Cmd::tick(self::TICK_INTERVAL, static fn(): Msg => new RefreshMsg())];
        }
        return [$newModule, null];
    }

    public function view(): string
    {
        $cpu = $this->cpuLoad;
        $mem = $this->memLoad;
        $gpu = $this->gpuLoad;
        $uptime = $this->uptime;

        $lines = $this->renderLoadRow('CPU', $cpu) . "\n" . $this->renderLoadRow('MEM', $mem);

        // GPU keeps the older COMP-2 stance the class invented: an absent GPU
        // (-1.0) HIDES the row entirely; CPU/MEM render the visible 'n/a'
        // sentinel instead (E731 option (b) adoption ruling).
        if ($gpu >= 0.0) {
            $lines .= "\n" . $this->renderLoadRow('GPU', $gpu);
        }

        $lines .= sprintf("\nUPTIME %s", $uptime);

        return $lines;
    }

    public function minSize(): array
    {
        return [30, 5];
    }

    /**
     * Create a clone with updated system state in the state array.
     *
     * Reads fresh values from /proc/* and accumulates history without
     * mutating $this — follows Elm-architecture immutability contract.
     *
     * Also updates direct properties on the clone so that view() (which reads
     * from direct properties) gets current values.
     */
    private function withSystemState(): static
    {
        // Read fresh values directly from /proc/*
        $cpuLoad = $this->readCpuLoad();
        $memLoad = $this->readMemLoad();
        $gpuLoad = $this->readGpuLoad();
        $uptime = $this->readUptime();

        // Accumulate history without mutating $this
        $cpuHistory = $this->cpuHistory;
        $memHistory = $this->memHistory;
        // COMP-2 (E731 option b): history must not accumulate unmeasured
        // samples — a sentinel entry would poison trend data with fake idle.
        if ($cpuLoad >= 0.0) {
            $cpuHistory[] = (int) $cpuLoad;
        }
        if ($memLoad >= 0.0) {
            $memHistory[] = (int) $memLoad;
        }
        if (count($cpuHistory) > self::HISTORY_SIZE) {
            array_shift($cpuHistory);
        }
        if (count($memHistory) > self::HISTORY_SIZE) {
            array_shift($memHistory);
        }

        $clone = $this->withState([
            'cpuLoad' => $cpuLoad,
            'memLoad' => $memLoad,
            'gpuLoad' => $gpuLoad,
            'uptime' => $uptime,
            'cpuHistory' => $cpuHistory,
            'memHistory' => $memHistory,
        ]);

        // Update direct properties on clone so view() (which reads from
        // direct properties) gets the current values. This is a controlled
        // mutation at a well-defined boundary - the original $this is unchanged.
        $clone->cpuLoad = $cpuLoad;
        $clone->memLoad = $memLoad;
        $clone->gpuLoad = $gpuLoad;
        $clone->uptime = $uptime;
        $clone->cpuHistory = $cpuHistory;
        $clone->memHistory = $memHistory;

        return $clone;
    }

    private function fetchSystemData(): void
    {
        $this->cpuLoad = $this->readCpuLoad();
        $this->memLoad = $this->readMemLoad();
        $this->gpuLoad = $this->readGpuLoad();
        $this->uptime = $this->readUptime();

        // Dormant orphan (zero callers; removal STOP-listed per E731 record) —
        // mirrors the withSystemState no-accumulate law so any revival inherits
        // the correct sentinel shape.
        if ($this->cpuLoad >= 0.0) {
            $this->cpuHistory[] = (int) $this->cpuLoad;
        }
        if ($this->memLoad >= 0.0) {
            $this->memHistory[] = (int) $this->memLoad;
        }
        if (count($this->cpuHistory) > self::HISTORY_SIZE) {
            array_shift($this->cpuHistory);
        }
        if (count($this->memHistory) > self::HISTORY_SIZE) {
            array_shift($this->memHistory);
        }
    }

    private function readCpuLoad(): float
    {
        static $lastIdle = null;
        static $lastTotal = null;

        // COMP-2 door-probe (E731 option b): /proc is Linux-only — the door is
        // probed once per process via ProcAvailability and the answer memoized;
        // absence carries the UNMEASURED sentinel through the model so view()
        // renders 'n/a' instead of a fabricated 0% bar. The @ + ===false race
        // net (r83 s2 idiom) degrades the same way WITHOUT latching: a transient
        // probe→read failure self-heals on the next tick.
        if (!ProcAvailability::has('/proc/stat')) {
            return ProcAvailability::UNMEASURED;
        }

        $stat = @file_get_contents('/proc/stat');
        if ($stat === false) {
            return ProcAvailability::UNMEASURED;
        }

        preg_match('/^cpu\s+(.*)$/m', $stat, $matches);
        if (!isset($matches[1])) {
            // Malformed proc content is not "absent" — keep the historical 0.0
            // answer for a parse miss (only the doors gained the sentinel).
            return 0.0;
        }

        $fields = preg_split('/\s+/', trim($matches[1]));
        $values = array_map('intval', $fields);

        $user = $values[0] ?? 0;
        $nice = $values[1] ?? 0;
        $system = $values[2] ?? 0;
        $idle = $values[3] ?? 0;
        $iowait = $values[4] ?? 0;
        $irq = $values[5] ?? 0;
        $softirq = $values[6] ?? 0;

        $total = $user + $nice + $system + $idle + $iowait + $irq + $softirq;
        $idleTime = $idle + $iowait;

        if ($lastIdle === null || $lastTotal === null) {
            $lastIdle = $idleTime;
            $lastTotal = $total;
            return 0.0;
        }

        $totalDiff = $total - $lastTotal;
        $idleDiff = $idleTime - $lastIdle;

        $lastIdle = $idleTime;
        $lastTotal = $total;

        if ($totalDiff === 0) {
            return 0.0;
        }

        return min(100.0, ($totalDiff - $idleDiff) / $totalDiff * 100.0);
    }

    private function readMemLoad(): float
    {
        // COMP-2 door-probe (E731 option b) — see readCpuLoad() for the
        // probe-once/sentinel/race-net posture.
        if (!ProcAvailability::has('/proc/meminfo')) {
            return ProcAvailability::UNMEASURED;
        }

        $meminfo = @file_get_contents('/proc/meminfo');
        if ($meminfo === false) {
            return ProcAvailability::UNMEASURED;
        }

        preg_match('/^MemTotal:\s+(\d+)/m', $meminfo, $totalMatches);
        preg_match('/^MemAvailable:\s+(\d+)/m', $meminfo, $availMatches);

        $total = (int) ($totalMatches[1] ?? 0);
        $available = (int) ($availMatches[1] ?? 0);

        if ($total === 0) {
            // Parse miss on present-but-unexpected content — historical 0.0
            // answer kept; only the availability legs carry the sentinel.
            return 0.0;
        }

        return ($total - $available) / $total * 100.0;
    }

    private function readGpuLoad(): float
    {
        // Memoize "no GPU present" so nvidia-smi is not re-spawned every 2s.
        // Once we get -1.0 (null output or non-numeric), we remember it forever
        // for this process lifetime and never spawn the subprocess again.
        /** @var bool */
        static $gpuAbsent = false;

        if ($gpuAbsent) {
            return -1.0;
        }

        $output = @shell_exec(
            'nvidia-smi --query-gpu=utilization.gpu --format=csv,noheader,nounits 2>/dev/null'
        );

        if ($output === null) {
            $gpuAbsent = true;
            return -1.0;
        }

        $value = trim($output);
        if (is_numeric($value)) {
            return (float) $value;
        }

        $gpuAbsent = true;
        return -1.0;
    }

    private function readUptime(): string
    {
        // COMP-2 door-probe (E731 option b) — see readCpuLoad(); the degraded
        // answer is now the shared visible 'n/a' sentinel (was 'unknown'). The
        // pre-first-tick property default 'unknown' stays: "not yet sampled"
        // and "never measurable here" are different facts.
        if (!ProcAvailability::has('/proc/uptime')) {
            return ProcAvailability::UNAVAILABLE_SENTINEL;
        }

        $uptimeData = @file_get_contents('/proc/uptime');
        if ($uptimeData === false) {
            return ProcAvailability::UNAVAILABLE_SENTINEL;
        }

        $seconds = (float) trim(explode(' ', $uptimeData)[0]);
        return $this->formatUptime($seconds);
    }

    private function formatUptime(float $seconds): string
    {
        // Cast once: uptime granularity is whole seconds, and PHP 8.3+
        // deprecates the implicit float->int conversion the `%` operator
        // does. intdiv() makes the integer math explicit.
        $secs = (int) $seconds;
        $days = intdiv($secs, 86400);
        $hours = intdiv($secs % 86400, 3600);
        $minutes = intdiv($secs % 3600, 60);

        if ($days > 0) {
            return "{$days}d {$hours}h {$minutes}m";
        }
        if ($hours > 0) {
            return "{$hours}h {$minutes}m";
        }
        return "{$minutes}m";
    }

    private function renderBar(float $percent, int $width): string
    {
        if ($width < 1) {
            return '';
        }

        $filled = (int) ($percent / 100 * $width);
        $empty = $width - $filled;

        return str_repeat('█', $filled) . str_repeat('░', $empty);
    }

    /**
     * The pure COMP-2 render decision for one percentage row (E731 option b):
     * a measured value renders "<LABEL> <pct>% <bar>" exactly as before; the
     * UNMEASURED sentinel renders "<LABEL> n/a" — visible, honest, no fake
     * zero-length bar. Same inputs always produce the same row (no I/O).
     */
    private function renderLoadRow(string $label, float $percent): string
    {
        if ($percent < 0.0) {
            return $label . ' ' . ProcAvailability::UNAVAILABLE_SENTINEL;
        }

        return sprintf('%s %3.0f%% %s', $label, $percent, $this->renderBar($percent, 70));
    }
}
