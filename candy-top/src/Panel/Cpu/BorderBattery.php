<?php

declare(strict_types=1);

namespace SugarCraft\Top\Panel\Cpu;

use SugarCraft\Top\Collect\BatterySnapshot;
use SugarCraft\Top\Collect\Platform;
use SugarCraft\Top\Collect\SelectableBattery;
use SugarCraft\Top\Config\Config;
use SugarCraft\Top\Lang;
use SugarCraft\Top\Panel\Gfx\PositionMeter;
use SugarCraft\Top\Panel\PanelContext;
use SugarCraft\Top\Panel\PanelFrame;
use SugarCraft\Top\Source\CollectorSource;
use SugarCraft\Top\Source\Fake\FakeBattery;
use SugarCraft\Top\Source\Source;
use SugarCraft\Top\View\ClockFormat;
use SugarCraft\Top\View\Rect;
use SugarCraft\Top\View\Region;
use SugarCraft\Top\View\Symbols;

/**
 * The P-D {@see BatteryBadge}: btop's `┐BAT▼ 87% ■■■■■■■■■■ 02:54 12.48W┌`
 * on the cpu box border (btop_draw.cpp:765-806, Cpu::draw "Draw battery
 * if enabled and present").
 *
 *  - show_battery off: not sampled, not painted, no clock reserve;
 *  - selected_battery is applied to the collector at collect time
 *    ({@see SelectableBattery::withSelected()});
 *  - status symbol ▲ charging / ▼ discharging / ■ full / ○ anything else;
 *  - the 10-cell meter (btop `Meter{10, "cpu", invert}`: the cpu gradient
 *    read top-down) and the reserve only on terminals >= 100 columns;
 *  - time is `sec_to_dhms(seconds, no_days=false, no_seconds=true)`, shown
 *    only when > 0; watts as `%.2fW` while show_battery_watts and measured;
 *  - position `Term::width - len - 17` (1-based), where len counts the
 *    meter (11), the strings and the update_ms digits — so the badge sits
 *    left of the `- 2000ms +` button and moves with it.
 *
 * #1008: an UNMEASURED time or wattage keeps the last good one while the
 * status is unchanged (a status change makes the old figure meaningless:
 * time-to-empty is not time-to-full). An absent battery hides the badge,
 * as btop's has_battery = false does.
 *
 * Deviation: with cpu_bottom btop still draws the badge on the TOP border
 * (Cpu::draw writes at `y`, with the `_down` junctions); here it follows
 * the clock and buttons onto the bottom border.
 */
final class BorderBattery implements BatteryBadge
{
    /** btop bat_symbols. */
    public const SYMBOLS = ['charging' => '▲', 'discharging' => '▼', 'full' => '■', 'unknown' => '○'];

    /** btop bat_meter width. */
    public const METER = 10;

    private function __construct(
        private readonly Source $source,
        private readonly ?BatterySnapshot $last,
    ) {
    }

    public static function new(Source $source): self
    {
        return new self($source, null);
    }

    /** The badge CpuPanel::standard installs: the host's collector ({@see Platform}) or the fake. */
    public static function standard(Config $config, bool $fake = false, ?Platform $platform = null): self
    {
        return self::new($fake ? FakeBattery::new() : ($platform ?? Platform::detect())->battery($config->string('selected_battery')));
    }

    public function source(PanelContext $context): ?Source
    {
        if (!$context->config->bool('show_battery')) {
            return null;
        }
        $selected = $context->config->string('selected_battery');
        $source = $this->source;
        if ($source instanceof FakeBattery) {
            return $source->withSelected($selected);
        }
        // The interface, not Collect\Battery: FreeBSD's collector retunes too.
        if ($source instanceof CollectorSource && ($c = $source->collector()) instanceof SelectableBattery) {
            return CollectorSource::of($c->withSelected($selected));
        }

        return $source;
    }

    public function withSample(object $snapshot, Source $next, PanelContext $context): self
    {
        if (!$snapshot instanceof BatterySnapshot) {
            return new self($next, $this->last);
        }
        if (!$snapshot->present()) {
            return new self($next, null);
        }
        $last = $this->last;
        if ($last !== null && $last->status === $snapshot->status && ($snapshot->seconds < 0 || $snapshot->watts < 0)) {
            $snapshot = new BatterySnapshot(
                $snapshot->name,
                $snapshot->percent,
                $snapshot->status,
                $snapshot->seconds < 0 ? $last->seconds : $snapshot->seconds,
                $snapshot->watts < 0 ? $last->watts : $snapshot->watts,
            );
        }

        return new self($next, $snapshot);
    }

    /** btop `show_battery and has_battery`. */
    public function present(Config $config): bool
    {
        return $this->last !== null && $config->bool('show_battery');
    }

    /** The last present reading (with #1008 holds applied); null when none. */
    public function last(): ?BatterySnapshot
    {
        return $this->last;
    }

    public function paint(Region $box, PanelFrame $frame): void
    {
        $bat = $this->last;
        if ($bat === null || !$this->present($frame->config) || $box->width() < 3 || $box->height() < 2) {
            return;
        }
        $config = $frame->config;
        $ink = $frame->ink;
        $termWidth = $frame->layout->width;
        $wide = $termWidth >= 100;
        $time = $bat->seconds > 0 ? substr(ClockFormat::dhms($bat->seconds), 0, -3) : '';
        $percent = $bat->percent . '%';
        $watts = $bat->watts >= 0 && $config->bool('show_battery_watts') ? sprintf('%.2fW', $bat->watts) : '';
        $symbol = self::SYMBOLS[$bat->status] ?? self::SYMBOLS['unknown'];
        $len = ($wide ? 11 : 0) + strlen($time) + strlen($percent) + strlen($watts) + strlen((string) $config->updateMs());
        $x = $termWidth - $len - 18 - $frame->box->x;
        $bottom = $frame->layout->cpuBottom;
        $y = $bottom ? $box->height() - 1 : 0;

        $title = $ink->fg('title');
        $inner = $title . Symbols::BOLD . Lang::t('battery.label') . $symbol . ' ' . $percent;
        if ($wide) {
            $inner .= Symbols::UNBOLD . ' ' . PositionMeter::render($ink, self::METER, $bat->percent, 'cpu', true) . Symbols::BOLD;
        }
        if ($time !== '') {
            $inner .= ' ' . $title . $time;
        }
        if ($watts !== '') {
            $inner .= ' ' . $title . Symbols::BOLD . $watts;
        }
        // Clipped to the border run between the corners, like every embed.
        $edge = $box->sub(Rect::new(1, $y, $box->width() - 2, 1));
        $x -= 1;
        [$open, $close] = $frame->border->embedJunctions($bottom);
        $line = $ink->fg('cpu_box');
        $x += $edge->put($x, 0, $open, $line);
        $x += $edge->ansi($x, 0, $inner . Symbols::UNBOLD);
        $edge->put($x, 0, $close, $line);
    }
}
