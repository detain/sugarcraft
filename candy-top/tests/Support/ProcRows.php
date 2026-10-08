<?php

declare(strict_types=1);

namespace SugarCraft\Top\Tests\Support;

use SugarCraft\Top\Collect\ContainerRef;
use SugarCraft\Top\Collect\Process;
use SugarCraft\Top\Collect\Sentinel;
use SugarCraft\Top\Panel\Proc\ProcEntry;

/**
 * Terse {@see Process} / {@see ProcEntry} builders for the proc-panel tests.
 */
final class ProcRows
{
    /**
     * @param array{ppid?: int, name?: string, cmd?: string, user?: string, state?: string, threads?: int, mem?: int, cpu?: float, cpuC?: float, ioR?: float, ioW?: float, argv0?: int, container?: ?ContainerRef, nice?: int} $o
     */
    public static function process(int $pid, array $o = []): Process
    {
        $name = $o['name'] ?? 'p' . $pid;

        return new Process(
            $pid,
            $o['ppid'] ?? 0,
            $name,
            $o['cmd'] ?? '/usr/bin/' . $name,
            $o['user'] ?? 'joe',
            1000,
            $o['state'] ?? 'S',
            $o['threads'] ?? 1,
            $o['nice'] ?? 0,
            $o['mem'] ?? 1024 * 1024,
            $o['cpu'] ?? 0.0,
            $o['cpuC'] ?? 0.0,
            $o['argv0'] ?? 0,
            $o['ioR'] ?? Sentinel::UNMEASURED,
            $o['ioW'] ?? Sentinel::UNMEASURED,
            Sentinel::UNMEASURED_INT,
            Sentinel::UNMEASURED_INT,
            $o['container'] ?? null,
        );
    }

    /**
     * @param array<string, mixed> $o see {@see process()}
     */
    public static function entry(int $pid, array $o = []): ProcEntry
    {
        $p = self::process($pid, $o);

        return ProcEntry::of($p, $p->cpu, $p->ioRead, $p->ioWrite);
    }

    /**
     * @param list<ProcEntry> $rows
     * @return list<int>
     */
    public static function pids(array $rows): array
    {
        return array_map(static fn (ProcEntry $e): int => $e->pid(), $rows);
    }
}
