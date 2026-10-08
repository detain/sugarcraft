<?php

declare(strict_types=1);

namespace SugarCraft\Top\Panel\Mem;

use SugarCraft\Top\Collect\Mount;
use SugarCraft\Top\Collect\MemorySnapshot;
use SugarCraft\Top\Collect\MountSelection;
use SugarCraft\Top\Collect\Platform;
use SugarCraft\Top\Config\Config;
use SugarCraft\Top\Lang;
use SugarCraft\Top\Panel\Gfx\History;
use SugarCraft\Top\Panel\PanelContext;
use SugarCraft\Top\Panel\PanelFrame;
use SugarCraft\Top\Source\Source;
use SugarCraft\Top\View\Rect;
use SugarCraft\Top\View\Region;

/**
 * The P-D {@see DisksSection}: btop's disks half of the mem box — state
 * (per-mount io_read / io_write / io_activity histories, the last mount
 * list, the swap pseudo-disk's figures) plus the line-up the draw loop
 * walks; {@see DisksView} paints it.
 *
 * Sampling options are read from the CURRENT config inside {@see source()}
 * (asked by MemPanel::collect): disks_filter (with `exclude=`),
 * use_fstab, only_physical and zfs_hide_datasets retune the mounts
 * collector through {@see MountSelection}. Display options (io_mode,
 * io_graph_combined, io_graph_speeds, show_io_stat, swap_disk,
 * disks_order) are read at paint time, so `i` flips the view on the very
 * next frame: every history is kept whatever the mode.
 *
 * Histories follow btop's Mem::collect: the first sample of a device
 * pushes 0 whatever it read (btop `if (io_read.empty()) push_back(0)`),
 * a sample without the device keeps the deques untouched, later UNMEASURED
 * readings repeat the last good value (#1008, {@see History}), deques are
 * capped at `width * 2` of the mem box, and an unmounted disk loses them.
 *
 * Mirrors aristocratos/btop Mem::collect disks block
 * (linux/btop_collect.cpp:2425-2742) and its disks_order (+ PR #1700).
 */
final class Disks implements DisksSection
{
    /** Fallback history cap before the first WindowSizeMsg. */
    private const DEFAULT_HISTORY = 512;

    /** The swap pseudo-disk's key in the line-up and in disks_order (btop "swap"). */
    public const SWAP = 'swap';

    /**
     * @param list<Mount>         $mounts last sampled mounts
     * @param array<string, bool> $io     mount points with an IO device
     */
    private function __construct(
        private readonly Source $source,
        private readonly History $history,
        private readonly array $mounts,
        private readonly array $io,
        private readonly ?MemorySnapshot $memory,
    ) {
    }

    public static function new(Source $source): self
    {
        return new self($source, History::new(), [], [], null);
    }

    /** The section MemPanel::standard installs: the host's collectors ({@see Platform}) or the fakes. */
    public static function standard(Config $config, bool $fake = false, ?Platform $platform = null): self
    {
        return self::new($fake ? DisksSource::fake($config->updateMs() / 1000) : DisksSource::live($platform));
    }

    /** The disks selection the CURRENT config asks for. */
    public static function selection(Config $config): MountSelection
    {
        return MountSelection::new(
            $config->bool('only_physical'),
            $config->bool('use_fstab'),
            $config->string('disks_filter'),
            $config->bool('zfs_hide_datasets'),
        );
    }

    public function source(PanelContext $context): ?Source
    {
        if (!$context->config->bool('show_disks')) {
            return null;
        }

        return $this->source instanceof DisksSource
            ? $this->source->withSelection(self::selection($context->config))
            : $this->source;
    }

    public function withSample(object $snapshot, Source $next, PanelContext $context, ?MemorySnapshot $memory = null): self
    {
        if (!$snapshot instanceof DisksSample) {
            return new self($next, $this->history, $this->mounts, $this->io, $memory ?? $this->memory);
        }
        // btop trims io deques to `width * 2` with Mem::width — the mem box.
        $cap = 2 * max(1, $context->box?->width ?? $context->layout?->width ?? intdiv(self::DEFAULT_HISTORY, 2));
        $history = $this->history;
        $keep = [];
        $io = [];
        foreach ($snapshot->mounts as $mount) {
            $device = $snapshot->io[$mount->mountpoint] ?? null;
            $values = $device === null ? null : ['read' => $device->readRate, 'write' => $device->writeRate, 'activity' => $device->busy];
            foreach (['read', 'write', 'activity'] as $series) {
                $key = self::key($series, $mount->mountpoint);
                if ($values !== null) {
                    // btop: a newly seen disk's first push is 0 whatever it read.
                    $history = $history->push($key, $history->has($key) ? $values[$series] : 0, $cap);
                }
                // A mount still listed keeps its deques even when this
                // sample had no device for it (a one-off unreadable
                // /proc/diskstats); btop erases them only on unmount.
                if ($history->has($key)) {
                    $keep[] = $key;
                    $io[$mount->mountpoint] = true;
                }
            }
        }

        return new self($next, $history->only($keep), $snapshot->mounts, $io, $memory ?? $this->memory);
    }

    public function paint(Region $box, PanelFrame $frame, Rect $area): void
    {
        DisksView::paint($box, $frame, $area, $this);
    }

    public function history(): History
    {
        return $this->history;
    }

    /** History key of `$series` (read | write | activity) for line-up key `$key`. */
    public static function key(string $series, string $key): string
    {
        return $series . ':' . $key;
    }

    /** @return list<Mount> */
    public function mounts(): array
    {
        return $this->mounts;
    }

    /**
     * The line-up btop's draw loop walks (`mem.disks_order` over
     * `mem.disks`): "/" first, then the swap pseudo-disk (swap_disk with
     * swap present), then the other mounts in mount-table order — and
     * #1700's disks_order pulled to the front.
     *
     * @return list<DiskRow>
     */
    public function rows(Config $config): array
    {
        $rows = [];
        foreach ($this->mounts as $m) {
            $rows[$m->mountpoint] = new DiskRow(
                $m->mountpoint,
                $m->name,
                $m->total,
                $m->used,
                $m->free,
                (int) $m->usedPercent(),
                (int) $m->freePercent(),
                $this->io[$m->mountpoint] ?? false,
            );
        }
        $keys = array_keys($rows);
        $mem = $this->memory;
        if ($config->bool('swap_disk') && $mem !== null && $mem->swapTotal > 0) {
            $rows[self::SWAP] = new DiskRow(
                self::SWAP,
                Lang::t('disks.swap'),
                $mem->swapTotal,
                max(0, $mem->swapUsed),
                max(0, $mem->swapFree),
                (int) max(0.0, $mem->swapPercent('swap_used')),
                (int) max(0.0, $mem->swapPercent('swap_free')),
                false,
            );
            $root = array_search('/', $keys, true);
            array_splice($keys, $root === false ? 0 : 1, 0, [self::SWAP]);
        }

        return array_map(static fn (string|int $k): DiskRow => $rows[(string) $k], self::order(array_map('strval', $keys), $config->disksOrder()));
    }

    /** btop `disk_ios`: disks whose IO is read. */
    public function ioCount(): int
    {
        return count($this->io);
    }

    /**
     * #1700 apply_disks_order: the keys named in `$wanted` first, in that
     * order (unknown names ignored), then every other key in its default
     * order.
     *
     * @param list<string> $keys
     * @param list<string> $wanted
     * @return list<string>
     */
    public static function order(array $keys, array $wanted): array
    {
        $out = [];
        foreach ($wanted as $k) {
            if (in_array($k, $keys, true) && !in_array($k, $out, true)) {
                $out[] = $k;
            }
        }
        foreach ($keys as $k) {
            if (!in_array($k, $out, true)) {
                $out[] = $k;
            }
        }

        return $out;
    }
}
