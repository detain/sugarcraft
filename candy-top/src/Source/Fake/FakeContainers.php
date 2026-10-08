<?php

declare(strict_types=1);

namespace SugarCraft\Top\Source\Fake;

use SugarCraft\Top\Collect\ContainerCollector;
use SugarCraft\Top\Collect\ContainerInfo;
use SugarCraft\Top\Collect\Containers;
use SugarCraft\Top\Collect\ContainerSnapshot;

/**
 * The `--fake` container collector: the live grouping
 * ({@see Containers::group()}) over the fake process list, so selecting a
 * container really filters the proc box, with deterministic figures in
 * place of cgroup files and a fixed docker name table in place of the
 * socket.
 *
 * Figures: cpu is the sum of the container's processes (btop's
 * fallback); memory adds a per-container page cache on top of their RSS,
 * and some containers carry a memory.max, so both the "limit" and the
 * "no limit → MemTotal" paths of the detail meter show.
 */
final class FakeContainers implements ContainerCollector
{
    /** short id → docker name (what `/containers/json` would answer). */
    public const DOCKER_NAMES = [
        '3f2a1b9c0d1e' => 'web-1',
        'b7e14c0a9d22' => 'pg-main',
    ];

    /** container name (after naming) → memory.max bytes. */
    private const LIMITS = [
        'web-1' => 512 * 1024 * 1024,
        'pg-main' => 2 * 1024 * 1024 * 1024,
        'build' => 4 * 1024 * 1024 * 1024,
    ];

    /** @param list<ContainerInfo> $current */
    private function __construct(
        private readonly array $current,
    ) {
    }

    public static function new(): self
    {
        return new self([]);
    }

    public function enabled(): bool
    {
        return true;
    }

    public function rebased(): self
    {
        return $this;
    }

    /** Deterministic: no clock, every sample counts. */
    public function due(int $minWindowUs): bool
    {
        return true;
    }

    public function collect(array $processes, int $memTotal, int $cores, bool $perCore, int $historyCap): array
    {
        [$ctrs, $newDocker] = Containers::group($this->current, $processes);
        if ($newDocker) {
            $ctrs = Containers::named($ctrs, self::response());
        }
        $out = [];
        foreach (Containers::sorted($ctrs) as $i => $c) {
            $c = $c->withMem($c->mem + (16 + 24 * $i) * 1024 * 1024)->withMemLimit(self::LIMITS[$c->name] ?? 0);
            $out[] = $c->withHistoryPoint(Containers::graphPercent($c->cpu, $perCore, max(1, $cores)), $historyCap);
        }

        return [new ContainerSnapshot($out, $memTotal, max(1, $cores)), new self($out)];
    }

    /** A `/containers/json` reply naming {@see DOCKER_NAMES}, in the daemon's field order. */
    public static function response(): string
    {
        $entries = [];
        foreach (self::DOCKER_NAMES as $short => $name) {
            $entries[] = '{"Id":"' . str_pad($short, 64, '0') . '","Names":["/' . $name . '"],"Image":"demo"}';
        }

        return "HTTP/1.0 200 OK\r\nContent-Type: application/json\r\n\r\n[" . implode(',', $entries) . ']';
    }
}
