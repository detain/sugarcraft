<?php

declare(strict_types=1);

namespace SugarCraft\Top\Collect\FreeBsd;

use SugarCraft\Top\Collect\DiskDevice;
use SugarCraft\Top\Collect\DiskIoSnapshot;
use SugarCraft\Top\Collect\Sentinel;

/**
 * Per-device read/write throughput from devstat(9), via
 * `iostat -x -I -c 1`.
 *
 * Mirrors aristocratos/btop Mem::collect_disk (src/freebsd/btop_collect.cpp),
 * which reads devstat DSM_TOTAL_BYTES_READ / _WRITE per device. `-I`
 * makes iostat print those running totals instead of rates: kr/i and kw/i
 * are KiB since boot, sb/i the device's total busy seconds — exactly the
 * counters this collector differences, like the Linux one does with
 * /proc/diskstats. Columns are located by header name, so a future
 * iostat that reorders them still parses (and one that drops kr/i yields
 * no devices rather than wrong ones).
 *
 * Busy % = Δsb / Δt (devstat's DSM_TOTAL_BUSY_TIME). btop's FreeBSD
 * port has no busy figure and fakes io_activity from MiB moved; this is
 * the real one. Physical filtering (only_physical) drops SCSI passthrough
 * (pass*), memory disks (md*) and enclosure services (ses*) — not block
 * devices a mount can live on. Device names are iostat's (truncated by
 * it to 8 characters).
 *
 * Rates are UNMEASURED on a device's first sample. One iostat child per
 * sample (-c 1: one report, no interval sleep).
 */
final class DiskIo
{
    private const array VIRTUAL = ['pass', 'md', 'ses'];

    /**
     * @param array<string, array{0: float, 1: float, 2: float}> $previous [bytes read, bytes written, busy seconds]
     */
    private function __construct(
        private readonly Probe $probe,
        private readonly bool $physicalOnly,
        private readonly array $previous,
        private readonly ?float $lastAt,
    ) {
    }

    public static function new(?Probe $probe = null, bool $physicalOnly = true): self
    {
        return new self($probe ?? LiveProbe::new(), $physicalOnly, [], null);
    }

    /**
     * @return array{0: DiskIoSnapshot, 1: self}
     */
    public function sample(): array
    {
        $now = $this->probe->monotonic();
        $raw = $this->probe->run(['iostat', '-x', '-I', '-c', '1']);
        if ($raw === null) {
            return [new DiskIoSnapshot([]), $this];
        }

        $elapsed = $this->lastAt === null ? 0.0 : $now - $this->lastAt;
        $previous = [];
        $devices = [];
        foreach (self::parse($raw) as $name => $current) {
            if ($this->physicalOnly && preg_match('/^(' . implode('|', self::VIRTUAL) . ')\d+$/', $name) === 1) {
                continue;
            }
            $previous[$name] = $current;
            $old = $this->previous[$name] ?? null;

            $readRate = $writeRate = $busy = Sentinel::UNMEASURED;
            if ($old !== null && $elapsed > 0.0) {
                $readRate = max(0.0, $current[0] - $old[0]) / $elapsed;
                $writeRate = max(0.0, $current[1] - $old[1]) / $elapsed;
                $busy = $current[2] < 0.0 ? Sentinel::UNMEASURED
                    : max(0.0, min(100.0, max(0.0, $current[2] - $old[2]) / $elapsed * 100.0));
            }
            $devices[$name] = new DiskDevice($name, $readRate, $writeRate, $busy, (int) $current[0], (int) $current[1]);
        }

        return [new DiskIoSnapshot($devices), new self($this->probe, $this->physicalOnly, $previous, $now)];
    }

    /**
     * @return array<string, array{0: float, 1: float, 2: float}> device => [bytes read, bytes written, busy seconds (−1 unknown)]
     */
    public static function parse(string $text): array
    {
        $index = null;
        $out = [];
        foreach (explode("\n", $text) as $line) {
            $cols = preg_split('/\s+/', trim($line), -1, \PREG_SPLIT_NO_EMPTY) ?: [];
            if ($cols === []) {
                continue;
            }
            if ($cols[0] === 'device') {
                $kr = array_search('kr/i', $cols, true);
                $kw = array_search('kw/i', $cols, true);
                $sb = array_search('sb/i', $cols, true);
                $index = $kr === false || $kw === false ? null : [$kr, $kw, $sb];
                continue;
            }
            if ($index === null || !isset($cols[$index[0]], $cols[$index[1]])
                || !is_numeric($cols[$index[0]]) || !is_numeric($cols[$index[1]])) {
                continue;
            }
            $busy = $index[2] !== false && is_numeric($cols[$index[2]] ?? '') ? (float) $cols[$index[2]] : -1.0;
            $out[$cols[0]] = [(float) $cols[$index[0]] * 1024.0, (float) $cols[$index[1]] * 1024.0, $busy];
        }

        return $out;
    }
}
