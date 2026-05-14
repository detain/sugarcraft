<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Modules\Uptime;

use SugarCraft\Dash\Module\BaseModule;

/**
 * Uptime module that displays system uptime.
 */
final class UptimeModule extends BaseModule
{
    private string $uptime = 'unknown';

    public function name(): string
    {
        return 'uptime';
    }

    public function init(): array
    {
        $this->uptime = $this->readUptime();
        return [
            'name' => $this->name(),
            'minSize' => [15, 3],
            'interval' => 60,
        ];
    }

    public function update(array $state): array
    {
        $this->uptime = $this->readUptime();
        $state['uptime'] = $this->uptime;
        return $state;
    }

    public function view(array $state, int $width, int $height): string
    {
        return $this->uptime;
    }

    public function minSize(): array
    {
        return [15, 3];
    }

    private function readUptime(): string
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
}
