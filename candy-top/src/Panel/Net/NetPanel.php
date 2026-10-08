<?php

declare(strict_types=1);

namespace SugarCraft\Top\Panel\Net;

use SugarCraft\Core\KeyType;
use SugarCraft\Core\MouseAction;
use SugarCraft\Core\MouseButton;
use SugarCraft\Core\Msg;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Core\Msg\MouseMsg;
use SugarCraft\Core\Util\Width;
use SugarCraft\Dash\Foundation\NetAutoScale;
use SugarCraft\Dash\Plot\Braille\DualSampleGraph;
use SugarCraft\Top\Collect\NetInterface;
use SugarCraft\Top\Collect\NetSnapshot;
use SugarCraft\Top\Config\Config;
use SugarCraft\Top\Msg\SampledMsg;
use SugarCraft\Top\Panel\Panel;
use SugarCraft\Top\Panel\PanelContext;
use SugarCraft\Top\Panel\PanelFrame;
use SugarCraft\Top\Panel\PanelResult;
use SugarCraft\Top\Source\Source;
use SugarCraft\Top\View\Region;

/**
 * btop's net box: download/upload history graphs with hysteresis
 * autoscale, the stats sub-box, and the interface / zero / auto / sync
 * title buttons with their keys `b` `n` `z` `a` `y`.
 *
 * State the panel owns (btop keeps it in the Net namespace):
 *  - the selected interface — btop `Net::selected_iface`. It is re-picked
 *    only when unset or gone (btop_collect.cpp:3036-3060): the `net_iface`
 *    option when that interface exists, else the collector's auto pick
 *    (connected first, then the highest rx+tx total; see
 *    {@see \SugarCraft\Top\Collect\Net}). `b`/`n` cycle it and are NOT
 *    persisted — btop never writes net_iface from a key.
 *  - per-interface bandwidth history (btop `net_info::bandwidth`), kept for
 *    every interface so `b`/`n` show the new one's past at once.
 *  - the `z` totals offset per interface, a snapshot of the totals at the
 *    moment `z` was pressed (btop `stat.offset`); a second `z` clears it,
 *    and an offset larger than the running total (counter reset) drops.
 *  - the {@see NetAutoScale} ceilings (btop graph_max / max_count /
 *    rescale), fed only while `net_auto` is on, for the selected interface.
 *
 * `a` / `y` flip net_auto / net_sync through {@see PanelResult::$set}
 * (btop `Config::flip`, persisted) and, like `b`/`n`, rescale at once
 * (btop `Runner::run("net", no_update)`), so the very next frame is drawn
 * against the new ceiling.
 *
 * btop #1008: an UNMEASURED rate (the collector's first sample of an
 * interface) never reaches the graphs or the scaler, and the stats keep
 * the last measured speed; a sample with no interfaces at all (a failed
 * /proc read) keeps the previous snapshot.
 *
 * Mirrors aristocratos/btop Net::draw (src/btop_draw.cpp:1488-1593), the
 * net-box input block (src/btop_input.cpp:594-640) and the selection /
 * autoscale half of Net::collect (src/linux/btop_collect.cpp:2994-3077).
 */
final class NetPanel implements Panel
{
    public const DOWNLOAD = NetAutoScale::DOWNLOAD;
    public const UPLOAD = NetAutoScale::UPLOAD;

    /** Samples kept per interface and direction (btop trims to width * 2; this covers a 512-column graph). */
    public const HISTORY = 1024;

    /** @var list<string> the keys this box acts on (btop_input.cpp:600-634) */
    public const KEYS = ['b', 'n', 'z', 'a', 'y'];

    /**
     * @param array<string, array{download: list<int>, upload: list<int>}> $history
     * @param array<string, array{download: int, upload: int}>             $speed   last MEASURED bytes/s
     * @param array<string, array{download: int, upload: int}>             $offsets `z` totals offsets
     */
    private function __construct(
        private readonly Source $source,
        private readonly ?NetSnapshot $snapshot,
        private readonly ?string $selected,
        private readonly array $history,
        private readonly array $speed,
        private readonly array $offsets,
        private readonly NetAutoScale $scale,
    ) {
    }

    public static function new(Source $source): self
    {
        return new self($source, null, null, [], [], [], NetAutoScale::new());
    }

    public function box(): string
    {
        return 'net';
    }

    /** The latest snapshot holding at least one interface; null before the first sample. */
    public function snapshot(): ?NetSnapshot
    {
        return $this->snapshot;
    }

    /** btop `Net::selected_iface`; null before the first sample. */
    public function selected(): ?string
    {
        return $this->selected;
    }

    public function selectedInterface(): ?NetInterface
    {
        return $this->selected === null ? null : ($this->snapshot?->interfaces[$this->selected] ?? null);
    }

    public function scale(): NetAutoScale
    {
        return $this->scale;
    }

    /**
     * Measured bytes/s samples for `$iface` (default: the selected one), oldest first.
     *
     * @return list<int>
     */
    public function history(string $direction, ?string $iface = null): array
    {
        $iface ??= $this->selected;

        return $iface === null ? [] : ($this->history[$iface][$direction] ?? []);
    }

    /** Last measured bytes/s of the selected interface (0 before any measurement). */
    public function speed(string $direction): int
    {
        return $this->selected === null ? 0 : ($this->speed[$this->selected][$direction] ?? 0);
    }

    /** The selected interface's total minus its `z` offset. */
    public function total(string $direction): int
    {
        $iface = $this->selectedInterface();
        if ($iface === null) {
            return 0;
        }
        $total = $direction === self::DOWNLOAD ? $iface->rxTotal : $iface->txTotal;

        return max(0, $total - ($this->offsets[$iface->name][$direction] ?? 0));
    }

    /** Peak bytes/s the collector has seen on the selected interface. */
    public function top(string $direction): int
    {
        $iface = $this->selectedInterface();
        if ($iface === null) {
            return 0;
        }

        return (int) round(max(0.0, $direction === self::DOWNLOAD ? $iface->rxTop : $iface->txTop));
    }

    /** True while the selected interface's totals are offset by `z` (btop bolds the button). */
    public function zeroed(): bool
    {
        $o = $this->selected === null ? null : ($this->offsets[$this->selected] ?? null);

        return $o !== null && $o[self::DOWNLOAD] + $o[self::UPLOAD] > 0;
    }

    /**
     * The graph ceiling btop draws against: the autoscaled max while
     * net_auto is on (never below the 10 KiB floor, even before the first
     * rescale), else `net_download` / `net_upload` Mebibits as bytes.
     */
    public function graphMax(string $direction, Config $config): int
    {
        if ($config->bool('net_auto')) {
            return max(NetAutoScale::FLOOR, $this->scale->maxFor($direction));
        }
        $mbit = max(0, $config->int($direction === self::DOWNLOAD ? 'net_download' : 'net_upload'));

        return max(1, intdiv($mbit << 20, 8));
    }

    public function collect(PanelContext $context): ?\Closure
    {
        $source = $this->source;

        return static function () use ($source): Msg {
            [$snapshot, $next] = $source->sample();

            return new SampledMsg('net', $snapshot, $next);
        };
    }

    public function modal(PanelContext $context): bool
    {
        return false;
    }

    /**
     * Never claims: btop checks the net block LAST (after proc, cpu and
     * mem; btop_input.cpp:296-595), so the keys arrive through the
     * unclaimed broadcast and an earlier box keeps first refusal.
     */
    public function capturesKey(KeyMsg $key, PanelContext $context): bool
    {
        return false;
    }

    public function update(Msg $msg, PanelContext $context): PanelResult
    {
        if ($msg instanceof SampledMsg) {
            if ($msg->box !== 'net' || !$msg->snapshot instanceof NetSnapshot) {
                return new PanelResult($this);
            }

            return new PanelResult($this->absorb($msg->snapshot, $msg->next, $context->config));
        }
        if (!$context->visible()) {
            return new PanelResult($this);
        }
        if ($msg instanceof KeyMsg) {
            if ($msg->type !== KeyType::Char || $msg->ctrl || $msg->alt || !in_array($msg->rune, self::KEYS, true)) {
                return new PanelResult($this);
            }

            return $this->key($msg->rune, $context->config);
        }
        if ($msg instanceof MouseMsg) {
            $key = $this->clickedButton($msg, $context);

            return $key === null ? new PanelResult($this) : $this->key($key, $context->config);
        }

        return new PanelResult($this);
    }

    /**
     * The title button under a bare left click (btop `mouse_click` matched
     * against `Input::mouse_mappings`), or null.
     */
    public function clickedButton(MouseMsg $msg, PanelContext $context): ?string
    {
        $box = $context->box;
        if (
            $box === null || $this->selected === null || $this->snapshot === null
            || $msg->action !== MouseAction::Press || $msg->button !== MouseButton::Left
            || $msg->shift || $msg->alt || $msg->ctrl
            || !$context->hit($msg->x, $msg->y) || $msg->y - 1 !== $box->y
        ) {
            return null;
        }
        $col = $msg->x - 1 - $box->x;
        foreach (NetButtons::targets($box->width, self::ifaceWidth($this->selected)) as $key => [$at, $cells]) {
            if ($col >= $at && $col < $at + $cells) {
                return $key;
            }
        }

        return null;
    }

    public function paint(Region $region, PanelFrame $frame): void
    {
        NetView::paint($region, $frame, $this);
    }

    /** The interface name as the title button prints it: control-free, at most 15 columns. */
    public static function ifaceLabel(string $name): string
    {
        return Width::truncate((string) preg_replace('/[\x00-\x1f\x7f]/', '', $name), NetButtons::MAX_IFNAMSIZ);
    }

    /** btop `i_size`. */
    public static function ifaceWidth(string $name): int
    {
        return Width::string(self::ifaceLabel($name));
    }

    private function key(string $key, Config $config): PanelResult
    {
        if ($this->selected === null || $this->snapshot === null) {
            return new PanelResult($this);
        }

        return match ($key) {
            'b', 'n' => new PanelResult($this->cycled($key === 'n' ? 1 : -1, $config)),
            'z' => new PanelResult($this->zeroToggled()),
            'a' => new PanelResult(
                $this->mutate(scale: $this->rescaledFor($this->selected, !$config->bool('net_auto'), $config->bool('net_sync'))),
                null,
                ['net_auto' => !$config->bool('net_auto')],
            ),
            'y' => new PanelResult(
                $this->mutate(scale: $this->rescaledFor($this->selected, $config->bool('net_auto'), !$config->bool('net_sync'))),
                null,
                ['net_sync' => !$config->bool('net_sync')],
            ),
            default => new PanelResult($this),
        };
    }

    /** btop `b`/`n`: step through the interface list, wrapping, and rescale. */
    private function cycled(int $step, Config $config): self
    {
        $names = $this->snapshot?->names() ?? [];
        $index = array_search($this->selected, $names, true);
        if ($index === false || $names === []) {
            return $this;
        }
        $next = $names[($index + $step + count($names)) % count($names)];

        return $this->mutate(selected: $next, scale: $this->rescaledFor($next, $config->bool('net_auto'), $config->bool('net_sync')));
    }

    /** btop `z`: clear both offsets when either is set, else snapshot the current totals. */
    private function zeroToggled(): self
    {
        $iface = $this->selectedInterface();
        if ($iface === null) {
            return $this;
        }
        $offsets = $this->offsets;
        $offsets[$iface->name] = $this->zeroed()
            ? [self::DOWNLOAD => 0, self::UPLOAD => 0]
            : [self::DOWNLOAD => max(0, $iface->rxTotal), self::UPLOAD => max(0, $iface->txTotal)];

        return $this->mutate(offsets: $offsets);
    }

    /**
     * btop sets `Net::rescale = true` and runs `Runner::run("net", no_update)`
     * after `b`/`n`/`a`/`y`: Net::collect skips sampling but runs the
     * rescale pass at once (only while net_auto is on, read AFTER the key's
     * own flip), so the graph is redrawn against the new ceiling in the same
     * frame. With net_auto off the counters just reset and a rescale stays
     * armed for whenever autoscale comes back.
     */
    private function rescaledFor(?string $iface, bool $netAuto, bool $netSync): NetAutoScale
    {
        $down = $this->history(self::DOWNLOAD, $iface);
        $up = $this->history(self::UPLOAD, $iface);

        return $netAuto
            ? $this->scale->withSync($netSync)->rescaleNow($down, $up)
            : $this->scale->withSync($netSync)->forceRescale($down, $up);
    }

    private function absorb(NetSnapshot $snapshot, Source $next, Config $config): self
    {
        if ($snapshot->interfaces === []) {
            // #1008: a failed read keeps the last picture; with no picture
            // yet there is nothing to keep.
            return $this->mutate(source: $next);
        }

        $selected = $this->selected;
        $scale = $this->scale;
        if ($selected === null || !isset($snapshot->interfaces[$selected])) {
            $pinned = $config->string('net_iface');
            $selected = $pinned !== '' && isset($snapshot->interfaces[$pinned]) ? $pinned : $snapshot->selectedName;
            // btop zeroes max_count and arms the rescale; the window is the
            // new interface's samples from before this tick.
            $scale = $scale->forceRescale(
                $selected === null ? [] : ($this->history[$selected][self::DOWNLOAD] ?? []),
                $selected === null ? [] : ($this->history[$selected][self::UPLOAD] ?? []),
            );
        }

        $history = [];
        $speed = [];
        $offsets = [];
        $measured = false;
        foreach ($snapshot->interfaces as $name => $iface) {
            $h = $this->history[$name] ?? [self::DOWNLOAD => [], self::UPLOAD => []];
            $s = $this->speed[$name] ?? [self::DOWNLOAD => 0, self::UPLOAD => 0];
            $rates = [self::DOWNLOAD => $iface->rxRate, self::UPLOAD => $iface->txRate];
            foreach ($rates as $dir => $rate) {
                if ($rate < 0.0) {
                    continue;
                }
                $value = (int) round(min($rate, (float) DualSampleGraph::SAMPLE_LIMIT));
                $h[$dir][] = $value;
                if (count($h[$dir]) > self::HISTORY) {
                    $h[$dir] = array_slice($h[$dir], -self::HISTORY);
                }
                $s[$dir] = $value;
            }
            if ($name === $selected) {
                $measured = $iface->rxRate >= 0.0 && $iface->txRate >= 0.0;
            }
            $history[$name] = $h;
            $speed[$name] = $s;

            if (isset($this->offsets[$name])) {
                $o = $this->offsets[$name];
                // btop: `if (offset > val + rollover) offset = 0` — the counter restarted.
                foreach ([self::DOWNLOAD => $iface->rxTotal, self::UPLOAD => $iface->txTotal] as $dir => $total) {
                    if ($o[$dir] > $total) {
                        $o[$dir] = 0;
                    }
                }
                $offsets[$name] = $o;
            }
        }

        if ($measured && $config->bool('net_auto') && $selected !== null) {
            $scale = $scale->withSync($config->bool('net_sync'))
                ->offer($speed[$selected][self::DOWNLOAD], $speed[$selected][self::UPLOAD]);
        }

        return new self($next, $snapshot, $selected, $history, $speed, $offsets, $scale);
    }

    /**
     * @param array<string, array{download: int, upload: int}>|null $offsets
     */
    private function mutate(
        ?Source $source = null,
        ?string $selected = null,
        ?array $offsets = null,
        ?NetAutoScale $scale = null,
    ): self {
        return new self(
            $source ?? $this->source,
            $this->snapshot,
            $selected ?? $this->selected,
            $this->history,
            $this->speed,
            $offsets ?? $this->offsets,
            $scale ?? $this->scale,
        );
    }
}
