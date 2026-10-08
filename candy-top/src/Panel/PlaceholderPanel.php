<?php

declare(strict_types=1);

namespace SugarCraft\Top\Panel;

use SugarCraft\Core\Msg;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Core\Util\Width;
use SugarCraft\Top\Collect\CpuSnapshot;
use SugarCraft\Top\Collect\MemorySnapshot;
use SugarCraft\Top\Collect\NetSnapshot;
use SugarCraft\Top\Collect\ProcSnapshot;
use SugarCraft\Top\Collect\Sentinel;
use SugarCraft\Top\Lang;
use SugarCraft\Top\Msg\SampledMsg;
use SugarCraft\Top\Source\Source;
use SugarCraft\Top\View\ClockFormat;
use SugarCraft\Top\View\Rect;
use SugarCraft\Top\View\Region;
use SugarCraft\Top\View\Units;

/**
 * Phase P-A stand-in for every box: wires a {@see Source} through the full
 * {@see Panel} lifecycle and paints the latest snapshot as plain readouts
 * (no graphs). P-B..P-E replace it box by box with the real CpuPanel /
 * MemPanel / NetPanel / ProcPanel — see {@see Panels::standard()}.
 */
final class PlaceholderPanel implements Panel
{
    private function __construct(
        private readonly string $box,
        private readonly Source $source,
        private readonly ?object $snapshot,
    ) {
    }

    public static function new(string $box, Source $source): self
    {
        return new self($box, $source, null);
    }

    public function box(): string
    {
        return $this->box;
    }

    /** The most recent snapshot, null before the first sample lands. */
    public function snapshot(): ?object
    {
        return $this->snapshot;
    }

    public function collect(PanelContext $context): ?\Closure
    {
        $box = $this->box;
        $source = $this->source;

        return static function () use ($box, $source): Msg {
            [$snapshot, $next] = $source->sample();

            return new SampledMsg($box, $snapshot, $next);
        };
    }

    /** Readouts only: never owns input. */
    public function modal(PanelContext $context): bool
    {
        return false;
    }

    /** Readouts only: no keys of its own, so every global key keeps working. */
    public function capturesKey(KeyMsg $key, PanelContext $context): bool
    {
        return false;
    }

    public function update(Msg $msg, PanelContext $context): PanelResult
    {
        if ($msg instanceof SampledMsg && $msg->box === $this->box) {
            return new PanelResult(new self($this->box, $msg->next, $msg->snapshot));
        }

        return new PanelResult($this);
    }

    public function paint(Region $region, PanelFrame $frame): void
    {
        $inner = $region->sub(Rect::new(1, 1, $region->width() - 2, $region->height() - 2));
        $s = $this->snapshot;
        match (true) {
            $s instanceof CpuSnapshot => $this->paintCpu($region, $inner, $frame, $s),
            $s instanceof MemorySnapshot => $this->paintMem($inner, $frame, $s),
            $s instanceof NetSnapshot => $this->paintNet($region, $inner, $frame, $s),
            $s instanceof ProcSnapshot => $this->paintProc($inner, $frame, $s),
            default => null,
        };
    }

    private function paintCpu(Region $box, Region $inner, PanelFrame $f, CpuSnapshot $s): void
    {
        $ink = $f->ink;
        $n = $inner->put(1, 0, Lang::t('cpu.label') . ' ', $ink->fg('title') . "\x1b[1m");
        $inner->put(1 + $n, 0, self::pct($s->total), self::grad($f, 'cpu', $s->total));
        $inner->put(1, 1, Lang::t('cpu.load_avg') . ' ' . implode(' ', array_map(
            static fn (float $l): string => $l < 0 ? Lang::t('value.unavailable') : number_format($l, 2, '.', ''),
            $s->load,
        )), $ink->fg('main_fg'));
        if ($s->uptime >= 0 && $f->config->bool('show_uptime')) {
            $inner->put(1, 2, Lang::t('cpu.uptime', ['uptime' => ClockFormat::dhms((int) $s->uptime)]), $ink->fg('graph_text'));
        }

        $cores = $f->layout->cpuCores;
        if ($cores === null) {
            return;
        }
        $grid = $box->subAbsolute(Rect::new($cores->x + 1, $cores->y + 1, $cores->width - 2, $cores->height - 2));
        $columns = max(1, $f->layout->coreColumns);
        $rows = max(1, $grid->height());
        $colWidth = intdiv(max(0, $grid->width()), $columns);
        foreach ($s->cores as $i => $pct) {
            $col = intdiv($i, $rows);
            if ($col >= $columns) {
                break;
            }
            $x = $col * $colWidth;
            $y = $i % $rows;
            $label = 'C' . $i;
            $grid->put($x, $y, $label, $ink->fg('main_fg') . "\x1b[1m");
            $grid->put($x + max(4, Width::string($label) + 1), $y, str_pad(self::pct($pct), 5, ' ', STR_PAD_LEFT), self::grad($f, 'cpu', $pct));
        }
    }

    private function paintMem(Region $inner, PanelFrame $f, MemorySnapshot $s): void
    {
        $ink = $f->ink;
        // Memory owns the columns left of the mem|disks seam.
        $left = $f->layout->memDivider !== null
            ? $inner->sub(Rect::new(0, 0, $f->layout->memWidth - 1, $inner->height()))
            : $inner;
        $width = $left->width();
        $rows = [
            ['mem.used', $s->used, 'used'],
            ['mem.available', $s->available, 'available'],
            ['mem.cached', $s->cached, 'cached'],
            ['mem.free', $s->free, 'free'],
        ];
        $left->put(1, 0, Lang::t('mem.total') . ' ' . self::bytes($s->total), $ink->fg('title') . "\x1b[1m");
        foreach ($rows as $i => [$key, $bytes, $gradient]) {
            $pct = $s->total > 0 && $bytes >= 0 ? 100.0 * $bytes / $s->total : Sentinel::UNMEASURED;
            $left->put(1, 1 + $i, Width::truncate(Lang::t($key) . ' ' . self::bytes($bytes), max(0, $width - 7)), $ink->fg('main_fg'));
            $left->put(max(1, $width - 5), 1 + $i, str_pad(self::pct($pct), 5, ' ', STR_PAD_LEFT), self::grad($f, $gradient, $pct));
        }
        if ($s->swapTotal > 0) {
            $left->put(1, 6, Lang::t('mem.swap') . ' ' . self::bytes($s->swapUsed) . ' / ' . self::bytes($s->swapTotal), $ink->fg('main_fg'));
        }
    }

    private function paintNet(Region $box, Region $inner, PanelFrame $f, NetSnapshot $s): void
    {
        $ink = $f->ink;
        $iface = $s->selectedName !== null ? ($s->interfaces[$s->selectedName] ?? null) : null;
        if ($iface === null) {
            $inner->put(1, 0, Lang::t('value.unavailable'), $ink->fg('inactive_fg'));

            return;
        }
        $inner->put(1, 0, $iface->name, $ink->fg('title') . "\x1b[1m");
        $stats = $f->layout->netStats;
        $target = $stats === null ? $inner : $box->subAbsolute(Rect::new($stats->x + 2, $stats->y + 1, $stats->width - 3, $stats->height - 2));
        $target->put(0, 0, '▼ ' . self::rate($iface->rxRate), self::grad($f, 'download', 80));
        $target->put(0, 1, '  ' . Lang::t('net.top') . ' ' . self::rate($iface->rxTop), $ink->fg('main_fg'));
        $target->put(0, 2, '  ' . Lang::t('net.total') . ' ' . self::bytes($iface->rxTotal), $ink->fg('main_fg'));
        $target->put(0, 4, '▲ ' . self::rate($iface->txRate), self::grad($f, 'upload', 80));
        $target->put(0, 5, '  ' . Lang::t('net.top') . ' ' . self::rate($iface->txTop), $ink->fg('main_fg'));
        $target->put(0, 6, '  ' . Lang::t('net.total') . ' ' . self::bytes($iface->txTotal), $ink->fg('main_fg'));
    }

    private function paintProc(Region $inner, PanelFrame $f, ProcSnapshot $s): void
    {
        $ink = $f->ink;
        $w = $inner->width();
        $inner->put(0, 0, self::procRow($w, Lang::t('proc.col.pid'), Lang::t('proc.col.program'), Lang::t('proc.col.user'), Lang::t('proc.col.mem'), Lang::t('proc.col.cpu')), $ink->fg('title') . "\x1b[1m");
        $procs = $s->processes;
        usort($procs, static fn ($a, $b): int => $b->cpu <=> $a->cpu ?: $a->pid <=> $b->pid);
        foreach (array_slice($procs, 0, max(0, $inner->height() - 1)) as $i => $p) {
            $inner->put(0, 1 + $i, self::procRow($w, (string) $p->pid, $p->name, $p->user, self::bytes($p->mem), number_format($p->cpu, 1, '.', '')), $ink->fg('main_fg'));
        }
    }

    private static function procRow(int $width, string $pid, string $name, string $user, string $mem, string $cpu): string
    {
        $nameWidth = max(4, $width - 7 - 11 - 10 - 7);

        return str_pad($pid, 7, ' ', STR_PAD_LEFT) . ' '
            . Width::padRight(Width::truncate($name, $nameWidth - 1), $nameWidth)
            . Width::padRight(Width::truncate($user, 10), 11)
            . str_pad($mem, 10, ' ', STR_PAD_LEFT)
            . str_pad($cpu, 6, ' ', STR_PAD_LEFT);
    }

    private static function grad(PanelFrame $f, string $name, float $pct): string
    {
        return $pct < 0 ? $f->ink->fg('inactive_fg') : $f->ink->gradient($name, (int) round($pct));
    }

    private static function pct(float $pct): string
    {
        return $pct < 0 ? Lang::t('value.unavailable') : round($pct) . '%';
    }

    private static function bytes(int|float $bytes): string
    {
        return $bytes < 0 ? Lang::t('value.unavailable') : Units::bytes($bytes);
    }

    private static function rate(float $bytes): string
    {
        return $bytes < 0 ? Lang::t('value.unavailable') : Units::bytes($bytes, true);
    }
}
