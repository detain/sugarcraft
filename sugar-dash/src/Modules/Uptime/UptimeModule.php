<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Modules\Uptime;

use SugarCraft\Core\Msg;
use SugarCraft\Dash\Module\BaseModule;

/**
 * Uptime module that displays system uptime.
 */
final class UptimeModule extends BaseModule
{
    public function name(): string
    {
        return 'uptime';
    }

    public function init(): ?\Closure
    {
        $this->readUptime();
        return null;
    }

    public function update(Msg $msg): array
    {
        $uptime = $this->readUptimeFromProc();
        return [$this->withState(['uptime' => $uptime]), null];
    }

    public function view(): string
    {
        $state = $this->getState();
        return $state['uptime'] ?? 'N/A';
    }

    public function minSize(): array
    {
        return [15, 3];
    }

    private function readUptime(): string
    {
        return $this->readUptimeFromProc();
    }

    private function readUptimeFromProc(): string
    {
        $uptimeData = @file_get_contents('/proc/uptime');
        if ($uptimeData === false) {
            return 'N/A';
        }

        $seconds = (float) trim(explode(' ', $uptimeData)[0]);
        return $this->formatUptime($seconds);
    }

    private function formatUptime(float $seconds): string
    {
        $days = intval($seconds / 86400);
        $hours = intval(($seconds % 86400) / 3600);
        $minutes = intval(($seconds % 3600) / 60);

        if ($days > 0) {
            return "{$days}d {$hours}h {$minutes}m";
        }
        if ($hours > 0) {
            return "{$hours}h {$minutes}m";
        }
        return "{$minutes}m";
    }
}
