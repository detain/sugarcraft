<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Modules\System;

use SugarCraft\Dash\Module\BaseModule;

/**
 * System module that displays CPU, memory, and uptime statistics.
 *
 * Mirrors the lattice system module pattern.
 */
final class SystemModule extends BaseModule
{
    private float $cpuLoad = 0.0;
    private float $memLoad = 0.0;
    private float $gpuLoad = -1.0;
    private string $uptime = 'unknown';

    /** @var array<int> */
    private array $cpuHistory = [];

    /** @var array<int> */
    private array $memHistory = [];

    private const HISTORY_SIZE = 30;

    public function name(): string
    {
        return 'system';
    }

    public function init(): array
    {
        $this->fetchSystemData();
        return [
            'name' => $this->name(),
            'minSize' => [30, 5],
            'interval' => 2,
        ];
    }

    public function update(array $state): array
    {
        $this->fetchSystemData();
        $state['cpuLoad'] = $this->cpuLoad;
        $state['memLoad'] = $this->memLoad;
        $state['gpuLoad'] = $this->gpuLoad;
        $state['uptime'] = $this->uptime;
        $state['cpuHistory'] = $this->cpuHistory;
        $state['memHistory'] = $this->memHistory;
        return $state;
    }

    public function view(array $state, int $width, int $height): string
    {
        $cpu = $this->cpuLoad;
        $mem = $this->memLoad;
        $gpu = $this->gpuLoad;
        $uptime = $this->uptime;

        $cpuBar = $this->renderBar($cpu, $width - 10);
        $memBar = $this->renderBar($mem, $width - 10);

        $lines = sprintf("CPU %3.0f%% %s\nMEM %3.0f%% %s",
            $cpu, $cpuBar,
            $mem, $memBar
        );

        if ($gpu >= 0) {
            $gpuBar = $this->renderBar($gpu, $width - 10);
            $lines .= sprintf("\nGPU %3.0f%% %s", $gpu, $gpuBar);
        }

        $lines .= sprintf("\nUPTIME %s", $uptime);

        return $lines;
    }

    public function minSize(): array
    {
        return [30, 5];
    }

    private function fetchSystemData(): void
    {
        // Read CPU load from /proc/stat
        $this->cpuLoad = $this->readCpuLoad();

        // Read memory from /proc/meminfo
        $this->memLoad = $this->readMemLoad();

        // Read GPU load via nvidia-smi if available
        $this->gpuLoad = $this->readGpuLoad();

        // Read uptime
        $this->uptime = $this->readUptime();

        // Update history
        $this->cpuHistory[] = (int) $this->cpuLoad;
        $this->memHistory[] = (int) $this->memLoad;
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

        $stat = @file_get_contents('/proc/stat');
        if ($stat === false) {
            return 0.0;
        }

        preg_match('/^cpu\s+(.*)$/m', $stat, $matches);
        if (!isset($matches[1])) {
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
        $meminfo = @file_get_contents('/proc/meminfo');
        if ($meminfo === false) {
            return 0.0;
        }

        preg_match('/^MemTotal:\s+(\d+)/m', $meminfo, $totalMatches);
        preg_match('/^MemAvailable:\s+(\d+)/m', $meminfo, $availMatches);

        $total = (int) ($totalMatches[1] ?? 0);
        $available = (int) ($availMatches[1] ?? 0);

        if ($total === 0) {
            return 0.0;
        }

        return ($total - $available) / $total * 100.0;
    }

    private function readGpuLoad(): float
    {
        $output = @shell_exec('nvidia-smi --query-gpu=utilization.gpu --format=csv,noheader,nounits 2>/dev/null');
        if ($output === null) {
            return -1.0;
        }

        $value = trim($output);
        if (is_numeric($value)) {
            return (float) $value;
        }

        return -1.0;
    }

    private function readUptime(): string
    {
        $uptimeData = @file_get_contents('/proc/uptime');
        if ($uptimeData === false) {
            return 'unknown';
        }

        $seconds = (float) trim(explode(' ', $uptimeData)[0]);
        return $this->formatUptime($seconds);
    }

    private function formatUptime(float $seconds): string
    {
        $days = (int) ($seconds / 86400);
        $hours = (int) (($seconds % 86400) / 3600);
        $minutes = (int) (($seconds % 3600) / 60);

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
}
