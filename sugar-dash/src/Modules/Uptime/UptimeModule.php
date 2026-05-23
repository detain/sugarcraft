<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Modules\Uptime;

use SugarCraft\Core\Cmd;
use SugarCraft\Core\Msg;
use SugarCraft\Dash\Module\BaseModule;

/**
 * Uptime module that displays system uptime.
 *
 * Uses Cmd::tick() for periodic refresh.
 */
final class UptimeModule extends BaseModule
{
    private const TICK_INTERVAL = 5.0;

    private string $uptime = 'N/A';

    public function name(): string
    {
        return 'uptime';
    }

    public function init(): ?\Closure
    {
        return Cmd::tick(self::TICK_INTERVAL, static fn(): Msg => new TickMsg());
    }

    public function update(Msg $msg): array
    {
        $newUptime = $this->readUptimeFromProc();
        $nextModule = $this->withUptime($newUptime);
        if ($msg instanceof TickMsg) {
            return [$nextModule, Cmd::tick(self::TICK_INTERVAL, static fn(): Msg => new TickMsg())];
        }
        return [$nextModule, null];
    }

    public function view(): string
    {
        return $this->uptime;
    }

    public function minSize(): array
    {
        return [15, 3];
    }

    private function withUptime(string $uptime): static
    {
        $clone = clone $this;
        $clone->uptime = $uptime;
        return $clone;
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
}
