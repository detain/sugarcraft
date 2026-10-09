<?php

declare(strict_types=1);

namespace SugarCraft\Top\Panel\Ipmi;

use SugarCraft\Sprinkles\Border;
use SugarCraft\Top\Collect\Ipmi\IpmiPsu;
use SugarCraft\Top\Collect\Ipmi\IpmiSensor;
use SugarCraft\Top\Collect\Ipmi\IpmiState;
use SugarCraft\Top\Collect\Ipmi\SensorKind;
use SugarCraft\Top\Collect\Ipmi\SensorStatus;
use SugarCraft\Top\Lang;
use SugarCraft\Top\Panel\Gfx\Just;
use SugarCraft\Top\Panel\IpmiPanel;
use SugarCraft\Top\Panel\PanelFrame;
use SugarCraft\Top\View\Ink;
use SugarCraft\Top\View\Rect;
use SugarCraft\Top\View\Region;
use SugarCraft\Top\View\Symbols;

/**
 * Paints the ipmi box. Three detail levels by the box's interior, the way
 * the gpu box's #1881 levels degrade:
 *  - FULL (≥ 64×14, or a band ≥ 100×5): titled sub-panels — power (braille gauge, BMC
 *    window, per-PSU in/out bars with efficiency, power rails), status
 *    (fault chips, SEL fill and newest entry), temperatures (gradient
 *    meters to each sensor's own critical limit), fans (bars to the
 *    threshold or the fastest fan seen, redundancy badge) and voltages
 *    (centred deviation bars between the thresholds) — in 2, 3 or 4
 *    columns by width;
 *  - COMPACT (≥ 34×6): the gauge beside the hottest temperatures, a fan
 *    summary and the fault chips;
 *  - TINY: one line each for power, the hottest temperatures and faults.
 *
 * When space is short temperatures sort hottest-relative-to-limit first,
 * intake air always on top. The header (machine · BMC vendor + firmware ·
 * BMC address) rides the top border, right-aligned after the box title.
 * Everything is theme colours and gradients ({@see IpmiPaint}).
 */
final class IpmiView
{
    /** Cells the App's `ipmi` title takes on the top border (`┐ipmi┌` at x+2) plus a gap. */
    private const TITLE_RESERVE = 10;

    /** Fewest rows worth a power history graph (and most it takes). */
    private const HISTORY_MIN = 3;

    private const HISTORY_MAX = 8;

    /** Minimum sub-column width per list section. */
    private const LIST_MIN = ['temps' => 30, 'fans' => 24, 'volts' => 30];

    /** Narrowest dense sub-column (name + value, no bar) per list section. */
    private const LIST_DENSE = ['temps' => 15, 'fans' => 14, 'volts' => 17];

    /** A list entry this wide gets a sparkline of its sensor's last readings (the bar stops growing). */
    private const SPARK_FROM = 52;

    /** The longest a list entry's bar grows once it has a sparkline. */
    private const BAR_MAX = 24;

    private function __construct()
    {
    }

    public static function paint(Region $r, PanelFrame $frame, IpmiData $d): void
    {
        $w = $r->width();
        $h = $r->height();
        if ($w < 6 || $h < 3) {
            return;
        }
        $ink = $frame->ink;
        $border = $frame->border;
        self::header($r, $frame, $d);
        $inner = Rect::new(1, 1, $w - 2, $h - 2);
        if (!$d->any()) {
            self::message($r, $ink, $inner, $d, $frame->tty());

            return;
        }
        if ($d->state->failed() || $d->thresholdsNext) {
            // The reason on the bottom border; or, before the one-off threshold walk
            // (no power reading lands while it runs), what the box is busy with.
            $text = ' ' . ($d->state->failed() ? Lang::t($d->state->key(), ['device' => $d->device]) : Lang::t('ipmi.thresholds')) . ' ';
            [$open, $close] = $border->embedJunctions(true);
            $x = max(2, $w - 3 - mb_strwidth($text));
            $line = $ink->fg('div_line');
            $r->put($x - 1, $h - 1, $open, $line);
            $r->put($x, $h - 1, IpmiPaint::clip($text, $w - $x - 2), $d->state->failed() ? Symbols::BOLD . IpmiPaint::status($ink, SensorStatus::NonCritical) : $ink->fg('graph_text'));
            $r->put($x + min(mb_strwidth($text), $w - $x - 2), $h - 1, $close, $line);
        }
        if (($inner->width >= 64 && $inner->height >= 14) || ($inner->width >= 100 && $inner->height >= 5)) {
            self::full($r, $frame, $d, $inner);
        } elseif ($inner->width >= 34 && $inner->height >= 6) {
            self::compact($r, $frame, $d, $inner);
        } else {
            self::tiny($r, $ink, $d, $inner, $frame->tty());
        }
    }

    // ---- header + messages -------------------------------------------------

    private static function header(Region $r, PanelFrame $frame, IpmiData $d): void
    {
        $info = $d->info;
        if ($info === null) {
            return;
        }
        $ink = $frame->ink;
        $parts = [];
        if ($info->machine() !== '') {
            $parts[] = [$info->machine(), Symbols::BOLD . $ink->fg('title')];
        }
        $bmc = trim($info->bmcVendor . ($info->firmware !== '' ? ' ' . Lang::t('ipmi.fw', ['fw' => $info->firmware]) : ''));
        if ($bmc !== '') {
            $parts[] = [$bmc, $ink->fg('main_fg')];
        }
        if ($info->bmcIp !== '') {
            $parts[] = [Lang::t('ipmi.bmc_ip', ['ip' => $info->bmcIp]), $ink->fg('graph_text')];
        }
        if (IpmiPanel::showSerials($frame->config) && $info->serials !== []) {
            $parts[] = [Lang::t('ipmi.serial', ['serial' => (string) reset($info->serials)]), $ink->fg('inactive_fg')];
        }
        $room = $r->width() - self::TITLE_RESERVE - 4;
        while ($parts !== [] && self::partsWidth($parts) > $room) {
            array_pop($parts);
        }
        if ($parts === [] && $info->machine() !== '' && $room >= 6) {
            $parts = [[IpmiPaint::clip($info->machine(), $room), Symbols::BOLD . $ink->fg('title')]];
        }
        if ($parts === []) {
            return;
        }
        $width = self::partsWidth($parts);
        $x = $r->width() - 3 - $width;
        [$open, $close] = $frame->border->embedJunctions(false);
        $line = $ink->fg('div_line');
        $r->put($x - 1, 0, $open, $line);
        $col = $x;
        foreach ($parts as $i => [$text, $sgr]) {
            if ($i > 0) {
                $col += $r->put($col, 0, ' · ', $ink->fg('inactive_fg'));
            }
            $col += $r->put($col, 0, $text, $sgr);
        }
        $r->put($col, 0, $close, $line);
    }

    /** @param list<array{0: string, 1: string}> $parts */
    private static function partsWidth(array $parts): int
    {
        $w = 0;
        foreach ($parts as $i => [$text]) {
            $w += mb_strwidth($text) + ($i > 0 ? 3 : 0);
        }

        return $w;
    }

    private static function message(Region $r, Ink $ink, Rect $inner, IpmiData $d, bool $tty = false): void
    {
        $state = $d->state;
        $reason = Lang::t($state->key(), ['device' => $d->device !== '' ? $d->device : '/dev/ipmi0']);
        $hint = match ($state) {
            IpmiState::NoTool => Lang::t('ipmi.hint.no_tool'),
            IpmiState::NoDevice => Lang::t('ipmi.hint.no_device'),
            IpmiState::NoAccess => Lang::t('ipmi.hint.no_access'),
            IpmiState::NoResponse, IpmiState::TimedOut, IpmiState::Stuck => Lang::t('ipmi.hint.retry'),
            default => '',
        };
        $y = $inner->y + max(0, intdiv($inner->height - ($hint === '' ? 1 : 2), 2));
        $sgr = $state->failed() ? Symbols::BOLD . IpmiPaint::status($ink, SensorStatus::NonCritical) : $ink->fg('graph_text');
        self::centre($r, $inner, $y, ($state->failed() ? ($tty ? '! ' : '⚠ ') : '') . $reason, $sgr);
        if ($hint !== '' && $y + 1 < $inner->bottom()) {
            self::centre($r, $inner, $y + 1, $hint, $ink->fg('inactive_fg'));
        }
    }

    private static function centre(Region $r, Rect $area, int $y, string $text, string $sgr): void
    {
        $text = IpmiPaint::clip($text, $area->width);
        $r->put($area->x + max(0, intdiv($area->width - mb_strwidth($text), 2)), $y, $text, $sgr);
    }

    // ---- FULL ----------------------------------------------------------------

    private static function full(Region $r, PanelFrame $frame, IpmiData $d, Rect $in): void
    {
        $best = null;
        $score = PHP_INT_MAX;
        foreach (self::arrangements($in) as $columns) {
            $cost = self::cost($columns, $d, $frame);
            if ($cost < $score) {
                $score = $cost;
                $best = $columns;
            }
        }
        foreach ($best ?? [] as [$col, $names]) {
            $cols = self::fit($names, $d, $col, $frame);
            $specs = [];
            foreach ($names as $name) {
                $spec = self::spec($name, $d, $col->width, $frame, $cols[$name] ?? 1);
                if ($spec !== null) {
                    $specs[] = $spec;
                }
            }
            $y = $col->y;
            foreach (self::stack($specs, $col->height) as [$name, $height]) {
                self::section($r, $frame, $d, $name, Rect::new($col->x, $y, $col->width, $height), $cols[$name] ?? 1);
                $y += $height;
            }
        }
    }

    /**
     * The section arrangements that fit `$in`: two columns (power +
     * status | the sensor lists), three (power + status | temperatures |
     * fans + voltages) and the four-column strip a short, wide box needs
     * (power | temperatures | fans + voltages | status).
     *
     * @return list<list<array{0: Rect, 1: list<string>}>>
     */
    private static function arrangements(Rect $in): array
    {
        $iw = $in->width;
        $ih = $in->height;
        $col = static fn (int $x, int $w): Rect => Rect::new($in->x + $x, $in->y, $w, $ih);
        $out = [];
        if ($iw >= 64 && $ih >= 14) {
            $a = (int) min($iw >= 180 ? 64 : 56, max(34, round($iw * 0.42)));
            $out[] = [[$col(0, $a), ['power', 'status']], [$col($a, $iw - $a), ['temps', 'fans', 'volts']]];
        }
        if ($iw >= 120 && $ih >= 14) {
            $a = (int) min(64, max(40, round($iw * 0.32)));
            $t = intdiv($iw - $a, 2);
            $out[] = [[$col(0, $a), ['power', 'status']], [$col($a, $t), ['temps']], [$col($a + $t, $iw - $a - $t), ['fans', 'volts']]];
        }
        if ($iw >= 100) {
            $a = (int) min(56, max(30, round($iw * 0.26)));
            $st = (int) min(46, max(26, round($iw * 0.22)));
            $rest = $iw - $a - $st;
            $t = intdiv($rest, 2);
            $out[] = [[$col(0, $a), ['power']], [$col($a, $t), ['temps']], [$col($a + $t, $rest - $t), ['fans', 'volts']], [$col($a + $rest, $st), ['status']]];
        }
        if ($iw >= 110 && $ih <= 12) {
            // A short band: every sensor group gets its own column.
            $a = (int) min(48, max(26, round($iw * 0.22)));
            $st = (int) min(40, max(24, round($iw * 0.2)));
            $rest = $iw - $a - $st;
            $t = (int) round($rest * 0.38);
            $f = intdiv($rest - $t, 2);
            $out[] = [
                [$col(0, $a), ['power']],
                [$col($a, $t), ['temps']],
                [$col($a + $t, $f), ['fans']],
                [$col($a + $t + $f, $rest - $t - $f), ['volts']],
                [$col($a + $rest, $st), ['status']],
            ];
        }

        return $out;
    }

    /**
     * How badly an arrangement fits: rows of content that do not fit
     * (heavily), then blank cells.
     *
     * @param list<array{0: Rect, 1: list<string>}> $columns
     */
    private static function cost(array $columns, IpmiData $d, PanelFrame $frame): int
    {
        $cost = 0;
        foreach ($columns as [$col, $names]) {
            $cols = self::fit($names, $d, $col, $frame);
            $need = 0;
            foreach ($names as $name) {
                $spec = self::spec($name, $d, $col->width, $frame, $cols[$name] ?? 1);
                $need += $spec[1] ?? 0;
            }
            $cost += $need > $col->height ? 10000 * ($need - $col->height) : ($col->height - $need) * $col->width;
        }

        return $cost;
    }

    /**
     * Sub-columns per list section: one each, then the tallest section
     * that still has room widens by one until the column's sections fit
     * (fewest columns = the least blank space).
     *
     * @param list<string> $names
     * @return array<string, int>
     */
    private static function fit(array $names, IpmiData $d, Rect $col, PanelFrame $frame): array
    {
        $cols = [];
        foreach ($names as $name) {
            if (isset(self::LIST_MIN[$name])) {
                $cols[$name] = 1;
            }
        }
        while (true) {
            $total = 0;
            foreach ($names as $name) {
                $total += self::spec($name, $d, $col->width, $frame, $cols[$name] ?? 1)[1] ?? 0;
            }
            if ($total <= $col->height) {
                return $cols;
            }
            $best = null;
            $rows = 0;
            foreach ($cols as $name => $n) {
                $count = \count(self::items($d, $name));
                $per = (int) ceil($count / $n);
                if ($n < self::cols($col->width - 2, self::LIST_DENSE[$name], 4) && $per > $rows && $per > 1) {
                    $rows = $per;
                    $best = $name;
                }
            }
            if ($best === null) {
                return $cols;
            }
            $cols[$best]++;
        }
    }

    /** @return list<IpmiSensor> */
    private static function items(IpmiData $d, string $name): array
    {
        return match ($name) {
            'temps' => $d->readings->temps,
            'fans' => $d->readings->fans,
            'volts' => $d->readings->volts,
            default => [],
        };
    }

    /**
     * [name, natural height, minimum height, may grow] of a section in a
     * column `$width` wide, or null when it has nothing to show.
     *
     * @return ?array{0: string, 1: int, 2: int, 3: bool}
     */
    private static function spec(string $name, IpmiData $d, int $width, PanelFrame $frame, int $cols = 1): ?array
    {
        $rd = $d->readings;
        $iw = $width - 2;

        return match ($name) {
            'power' => $d->watts() === null && $rd->psus === [] ? null : [
                'power',
                2 + ($d->watts() === null ? 0 : PowerGauge::height(min($iw - 2, 56)) + 2) + \count($rd->psus) + ($rd->rails !== [] ? 1 : 0),
                2 + 2,
                $d->watts() !== null,
            ],
            'status' => ['status', 2 + self::statusLines($d, $iw, $frame), 4, false],
            'temps', 'fans', 'volts' => self::items($d, $name) === [] ? null : [$name, 2 + (int) ceil(\count(self::items($d, $name)) / max(1, $cols)), 3, false],
            default => null,
        };
    }

    /** Sub-columns of at least `$min` cells (plus a gap) that fit `$width`, at most 3. */
    private static function cols(int $width, int $min, int $most = 3): int
    {
        return max(1, min($most, intdiv($width + 2, $min + 2)));
    }

    /**
     * Heights for sections stacked in `$avail` rows: natural heights,
     * shrunk (the one with most room above its minimum first) until they
     * fit, the last sections dropped when even the minimums do not. Spare
     * rows go first to a section that can use them (the power history
     * graph, at least {@see HISTORY_MIN} and at most {@see HISTORY_MAX}),
     * the rest evenly over the sections, so the column always reaches the
     * bottom.
     *
     * @param list<array{0: string, 1: int, 2: int, 3: bool}> $specs
     * @return list<array{0: string, 1: int}>
     */
    private static function stack(array $specs, int $avail): array
    {
        while ($specs !== [] && array_sum(array_column($specs, 2)) > $avail) {
            array_pop($specs);
        }
        if ($specs === []) {
            return [];
        }
        $heights = array_column($specs, 1);
        while (array_sum($heights) > $avail) {
            $best = -1;
            $slack = 0;
            foreach ($specs as $i => $spec) {
                if ($heights[$i] - $spec[2] > $slack) {
                    $slack = $heights[$i] - $spec[2];
                    $best = $i;
                }
            }
            if ($best < 0) {
                break;
            }
            $heights[$best]--;
        }
        $spare = $avail - array_sum($heights);
        foreach ($specs as $i => $spec) {
            if ($spec[3] && $spare >= self::HISTORY_MIN) {
                $take = min($spare, self::HISTORY_MAX);
                $heights[$i] += $take;
                $spare -= $take;
                break;
            }
        }
        // The rest evenly, the last sections first, so every box in the column grows a little.
        for ($i = \count($heights) - 1; $spare > 0; $i = ($i + \count($heights) - 1) % \count($heights)) {
            $heights[$i] += intdiv($spare + \count($heights) - 1, \count($heights)) > 0 ? 1 : 0;
            $spare--;
        }

        return array_map(static fn (array $s, int $h): array => [$s[0], $h], $specs, $heights);
    }

    private static function section(Region $r, PanelFrame $frame, IpmiData $d, string $name, Rect $box, int $cols): void
    {
        $ink = $frame->ink;
        $inner = Rect::new($box->x + 1, $box->y + 1, $box->width - 2, $box->height - 2);
        [$badge, $bw] = self::badge($ink, $d, $name, $frame->tty());
        IpmiPaint::frame($r, $box, $ink, $frame->border, Lang::t('ipmi.section.' . $name), $badge, $bw);
        if ($inner->width < 4 || $inner->height < 1) {
            return;
        }
        match ($name) {
            'power' => self::power($r, $ink, $d, $inner, $frame->tty()),
            'status' => self::status($r, $frame, $d, $inner),
            'temps' => self::temps($r, $frame->border, $ink, $d, $inner, $box, $cols, $frame->tty()),
            'fans' => self::fans($r, $frame->border, $ink, $d, $inner, $box, $cols, $frame->tty()),
            'volts' => self::volts($r, $frame->border, $ink, $d, $inner, $box, $cols, $frame->tty()),
            default => null,
        };
    }

    /**
     * The right-hand title badge of a section: the redundancy of the fans,
     * the alarm count of a sensor group, the supplies' count.
     *
     * @return array{0: string, 1: int}
     */
    private static function badge(Ink $ink, IpmiData $d, string $name, bool $tty = false): array
    {
        $rd = $d->readings;
        $group = match ($name) {
            'temps' => $rd->temps,
            'fans' => $rd->fans,
            'volts' => $rd->volts,
            default => null,
        };
        if ($name === 'fans' && $rd->redundancy !== null) {
            $text = Lang::t($rd->redundancy->key());

            return [IpmiPaint::status($ink, $rd->redundancy->severity()) . IpmiPaint::dot($tty) . ' ' . $ink->fg('main_fg') . $text, mb_strwidth($text) + 2];
        }
        if ($name === 'power' && $d->watts() !== null && $d->power !== null && $d->power->period > 0) {
            $text = Lang::t('ipmi.window_period', ['s' => $d->power->period]);

            return [$ink->fg('graph_text') . $text, mb_strwidth($text)];
        }
        if ($group === null || $group === []) {
            return ['', 0];
        }
        $worst = SensorStatus::Ok;
        $alarms = 0;
        foreach ($group as $s) {
            $sev = $s->severity();
            $worst = SensorStatus::worst($worst, $sev);
            $alarms += $sev->severity() > 0 ? 1 : 0;
        }
        $text = $alarms === 0 ? Lang::t('ipmi.count', ['n' => \count($group)]) : Lang::t('ipmi.alarms', ['n' => $alarms]);

        return [IpmiPaint::status($ink, $worst) . IpmiPaint::dot($tty) . ' ' . $ink->fg('graph_text') . $text, mb_strwidth($text) + 2];
    }

    // ---- power -------------------------------------------------------------

    private static function power(Region $r, Ink $ink, IpmiData $d, Rect $in, bool $tty = false): void
    {
        $rd = $d->readings;
        $watts = $d->watts();
        $psus = $rd->psus;
        $rails = $rd->rails !== [] ? 1 : 0;
        $below = 2 + \count($psus) + $rails;
        $gw = min($in->width - 2, 56);
        $gh = min(PowerGauge::height($gw), $in->height - $below);
        // Give up supply rows for a gauge only when that buys a real one
        // (4+ rows); a short section keeps the supplies under a power bar.
        if ($gh < 3 && $in->height - (2 + $rails) >= 4 + 1) {
            while ($gh < 4 && \count($psus) > 1) {
                array_pop($psus);
                $below--;
                $gh = min(PowerGauge::height($gw), $in->height - $below);
            }
        }
        $history = $in->height - $below - max(0, $gh);
        $y = $in->y;
        $scale = $d->scale();
        if ($watts !== null && $gh >= 3 && !$tty) {
            $gw = min($gw, 4 * $gh);
            $gx = $in->x + intdiv($in->width - $gw, 2);
            $win = $d->window();
            $rows = PowerGauge::render(
                $ink,
                $gw,
                $gh,
                $watts / $scale,
                $win === null ? null : $win[0] / $scale,
                $win === null ? null : $win[1] / $scale,
                $win === null ? null : $win[2] / $scale,
                'cpu',
            );
            foreach ($rows as $i => $row) {
                $r->ansi($gx, $y + $i, $row);
            }
            $y += $gh;
            // Readout: the scale's ends under the arc's ends, the reading big in the middle.
            $pct = (int) round(100 * $watts / $scale);
            $big = IpmiPaint::watts($watts);
            $max = IpmiPaint::watts($scale, false);
            $mid = $big . ' ' . $pct . '%';
            $mx = $in->x + intdiv($in->width - mb_strwidth($mid), 2);
            if ($gx + 1 < $mx && $mx + mb_strwidth($mid) < $gx + $gw - mb_strwidth($max)) {
                $r->put($gx, $y, '0', $ink->fg('inactive_fg'));
                $r->put($gx + $gw - mb_strwidth($max), $y, $max, $ink->fg('inactive_fg'));
            }
            $r->put($mx, $y, $big, Symbols::BOLD . $ink->gradient('cpu', $pct));
            $r->put($mx + mb_strwidth($big) + 1, $y, $pct . '%', $ink->fg('graph_text'));
            $y++;
        } elseif ($watts !== null) {
            self::powerLine($r, $ink, $d, $in->x, $y, $in->width);
            $y++;
            $psus = $rd->psus; // no gauge: every supply row it can fit
        }
        $win = $d->window();
        if ($win !== null && $y < $in->bottom()) {
            self::windowLine($r, $ink, $in->x, $y, $in->width, $win);
            $y++;
        }
        if ($watts !== null && !$tty && $history >= self::HISTORY_MIN && $y + $history <= $in->bottom()) {
            // The history auto-ranges to its own peak (the gauge already shows the draw against capacity).
            $hw = $in->width - 2;
            $peak = IpmiData::nice(max([1.0, ...\array_slice($d->watts, -2 * $hw)]) * 1.1);
            foreach (PowerHistory::render($ink, $hw, $history, $d->watts, $peak, 'cpu') as $i => $row) {
                $r->ansi($in->x + 1, $y + $i, $row);
            }
            $r->put($in->x + 1, $y, IpmiPaint::watts($peak), $ink->fg('graph_text'));
            $y += $history;
        }
        [$textW, $effW] = self::psuColumns($psus);
        foreach ($psus as $psu) {
            if ($y >= $in->bottom()) {
                break;
            }
            self::psuLine($r, $ink, $psu, $in->x, $y, $in->width, $scale / max(1, \count($rd->livePsus())), $textW, $effW, $tty);
            $y++;
        }
        if ($rails === 1 && $y < $in->bottom()) {
            $col = $in->x;
            foreach ($rd->rails as $i => $rail) {
                $label = $d->label($rail) . ' ';
                $value = IpmiPaint::watts((float) $rail->value);
                if ($col + mb_strwidth($label . $value) + ($i > 0 ? 3 : 0) > $in->right()) {
                    break;
                }
                if ($i > 0) {
                    $col += $r->put($col, $y, ' · ', $ink->fg('inactive_fg'));
                }
                $col += $r->put($col, $y, $label, $ink->fg('graph_text'));
                $col += $r->put($col, $y, $value, $ink->fg('main_fg'));
            }
        }
    }

    /** @param array{0: float, 1: float, 2: float} $win */
    private static function windowLine(Region $r, Ink $ink, int $x, int $y, int $width, array $win): void
    {
        $items = [['ipmi.min', $win[0]], ['ipmi.avg', $win[1]], ['ipmi.max', $win[2]]];
        $parts = [];
        $total = -2;
        foreach ($items as [$key, $v]) {
            $label = Lang::t($key);
            $value = IpmiPaint::watts($v, false);
            $parts[] = [$label, $value];
            $total += mb_strwidth($label) + 1 + mb_strwidth($value) + 2;
        }
        $total += 2; // " W"
        if ($total > $width) {
            // Short form: just the range.
            $text = IpmiPaint::watts($win[0], false) . '–' . IpmiPaint::watts($win[2]);
            if (mb_strwidth($text) <= $width) {
                $r->put($x + intdiv($width - mb_strwidth($text), 2), $y, $text, $ink->fg('graph_text'));
            }

            return;
        }
        $col = $x + max(0, intdiv($width - $total, 2));
        foreach ($parts as $i => [$label, $value]) {
            if ($i > 0) {
                $col += $r->put($col, $y, '  ', '');
            }
            $col += $r->put($col, $y, $label . ' ', $ink->fg('inactive_fg'));
            $col += $r->put($col, $y, $value, $i === 1 ? $ink->fg('hi_fg') : $ink->fg('main_fg'));
        }
        $r->put($col, $y, ' W', $ink->fg('inactive_fg'));
    }

    /** The watts text of a supply: "480→448 W" (in→out), or one figure when only one side reads or both agree. */
    private static function psuText(IpmiPsu $psu): string
    {
        $in = $psu->inWatts();
        $out = $psu->outWatts();

        return $in !== null && $out !== null && abs($in - $out) > 0.5
            ? sprintf('%d→%d W', (int) round($in), (int) round($out))
            : sprintf('%d W', (int) round($in ?? $out ?? 0.0));
    }

    /**
     * Shared right-hand column widths so every supply's bar has the same
     * length: [watts text, efficiency (0 when no supply has one)].
     *
     * @param list<IpmiPsu> $psus
     * @return array{0: int, 1: int}
     */
    private static function psuColumns(array $psus): array
    {
        $text = 0;
        $eff = 0;
        foreach ($psus as $p) {
            if ($p->live()) {
                $text = max($text, mb_strwidth(self::psuText($p)));
                $eff = $p->efficiency() !== null ? 4 : $eff;
            }
        }

        return [$text, $eff];
    }

    private static function psuLine(Region $r, Ink $ink, IpmiPsu $psu, int $x, int $y, int $width, float $share, int $textW, int $effW, bool $tty = false): void
    {
        $label = Lang::t('ipmi.psu', ['n' => $psu->index]);
        $col = $x + $r->put($x, $y, Just::left($label, 5), $ink->fg('title'));
        if (!$psu->live()) {
            $r->put($col + 1, $y, Lang::t('ipmi.absent'), $ink->fg('inactive_fg'));

            return;
        }
        $in = $psu->inWatts();
        $out = $psu->outWatts();
        $eff = $psu->efficiency();
        $bw = $width - 6 - $textW - $effW - 1;
        $cap = $psu->capacity() ?? $share;
        if ($bw >= 4) {
            $r->ansi($col + 1, $y, IpmiPaint::bar($ink, $bw, 100 * ($in ?? $out ?? 0.0) / max(1.0, $cap), 'available', $tty ? '■' : '━', $tty ? '■' : '─'));
        }
        $sev = $psu->severity();
        $tx = $x + $width - $effW - $textW;
        $r->put($tx, $y, Just::right(self::psuText($psu), $textW), $sev->severity() > 0 ? Symbols::BOLD . IpmiPaint::status($ink, $sev) : $ink->fg('main_fg'));
        if ($eff !== null && $effW > 0) {
            $r->put($tx + $textW, $y, Just::right(sprintf('%d%%', (int) round($eff * 100)), $effW), $ink->gradient('free', (int) round(max(0.0, min(1.0, ($eff - 0.80) / 0.18)) * 100)));
        }
    }

    // ---- status ------------------------------------------------------------

    /**
     * The fault chips, in order: chassis power, cooling, drives,
     * intrusion, supplies, sensor alarms.
     *
     * @return list<array{0: string, 1: SensorStatus}>
     */
    private static function chips(IpmiData $d): array
    {
        $chips = [];
        $c = $d->chassis;
        if ($c !== null) {
            $chips[] = $c->powerFault()
                ? [Lang::t('ipmi.chip.power_fault'), SensorStatus::Critical]
                : [Lang::t($c->powerOn ? 'ipmi.chip.power_on' : 'ipmi.chip.power_off'), $c->powerOn ? SensorStatus::Ok : SensorStatus::NonCritical];
            $chips[] = [Lang::t($c->fanFault ? 'ipmi.chip.fan_fault' : 'ipmi.chip.cooling_ok'), $c->fanFault ? SensorStatus::Critical : SensorStatus::Ok];
            $chips[] = [Lang::t($c->driveFault ? 'ipmi.chip.drive_fault' : 'ipmi.chip.drives_ok'), $c->driveFault ? SensorStatus::Critical : SensorStatus::Ok];
            $chips[] = [Lang::t($c->intrusion ? 'ipmi.chip.intrusion' : 'ipmi.chip.closed'), $c->intrusion ? SensorStatus::Critical : SensorStatus::Ok];
        }
        $rd = $d->readings;
        if ($rd->psus !== []) {
            $live = \count($rd->livePsus());
            $all = \count($rd->psus);
            $chips[] = [Lang::t('ipmi.chip.psus', ['n' => $live, 'of' => $all]), $live < $all ? SensorStatus::NonCritical : SensorStatus::Ok];
        }
        if ($d->sensorsRead) {
            $alarms = $rd->alarms();
            $chips[] = $alarms === 0
                ? [Lang::t('ipmi.chip.sensors_ok'), SensorStatus::Ok]
                : [Lang::t('ipmi.alarms', ['n' => $alarms]), $rd->worst()];
        }

        return $chips;
    }

    /** Rows the status section wants inside a `$width`-cell interior. */
    private static function statusLines(IpmiData $d, int $width, PanelFrame $frame): int
    {
        $lines = \count(self::wrapChips(self::chips($d), max(1, $width)));
        if ($d->sel !== null) {
            $lines += 1 + \count(self::wrap($d->sel->latest, $width - mb_strwidth(Lang::t('ipmi.sel_last')) - 1, 2));
        }
        if (IpmiPanel::showSerials($frame->config) && ($d->info?->serials ?? []) !== []) {
            $lines += \count($d->info->serials);
        }

        return max(1, $lines);
    }

    /**
     * Word-wrap `$text` into at most `$max` lines of `$width` cells; the
     * last line is clipped.
     *
     * @return list<string>
     */
    private static function wrap(string $text, int $width, int $max): array
    {
        if ($text === '' || $width < 1 || $max < 1) {
            return [];
        }
        $lines = [];
        $line = '';
        foreach (preg_split('/\s+/', trim($text)) ?: [] as $word) {
            $next = $line === '' ? $word : $line . ' ' . $word;
            if (mb_strwidth($next) <= $width || $line === '') {
                $line = $next;
                continue;
            }
            $lines[] = $line;
            $line = $word;
        }
        $lines[] = $line;
        if (\count($lines) > $max) {
            $lines = [...\array_slice($lines, 0, $max - 1), implode(' ', \array_slice($lines, $max - 1))];
        }

        return array_map(static fn (string $l): string => IpmiPaint::clip($l, $width), $lines);
    }

    /**
     * @param list<array{0: string, 1: SensorStatus}> $chips
     * @return list<list<array{0: string, 1: SensorStatus}>>
     */
    private static function wrapChips(array $chips, int $width): array
    {
        $lines = [];
        $line = [];
        $used = 0;
        foreach ($chips as $chip) {
            $w = mb_strwidth($chip[0]) + 2;
            if ($line !== [] && $used + 1 + $w > $width) {
                $lines[] = $line;
                $line = [];
                $used = 0;
            }
            $used += ($line === [] ? 0 : 1) + $w;
            $line[] = $chip;
        }
        if ($line !== []) {
            $lines[] = $line;
        }

        return $lines;
    }

    /**
     * A chip: the word on a pastel tile of its state colour (` power on `),
     * dark text over it. Falls back to a dot + word when the palette has no
     * background for that gradient stop (TTY themes still have one).
     */
    private static function chip(Region $r, Ink $ink, int $x, int $y, string $text, SensorStatus $status, bool $tty = false): int
    {
        $pct = match ($status) {
            SensorStatus::Ok => 0,
            SensorStatus::NonCritical => 55,
            default => 100,
        };
        $bg = $ink->palette()->at('process', $pct)?->toBg($ink->profile()) ?? '';
        $fg = $ink->palette()->color('main_bg')?->toFg($ink->profile()) ?? "\x1b[30m";
        if ($bg === '') {
            $n = $r->put($x, $y, IpmiPaint::dot($tty) . ' ', IpmiPaint::status($ink, $status));

            return $n + $r->put($x + $n, $y, $text, $ink->fg('main_fg'));
        }

        return $r->put($x, $y, ' ' . $text . ' ', $bg . $fg . ($status->severity() >= 2 ? Symbols::BOLD : ''));
    }

    private static function status(Region $r, PanelFrame $frame, IpmiData $d, Rect $in): void
    {
        $ink = $frame->ink;
        $y = $in->y;
        foreach (self::wrapChips(self::chips($d), $in->width) as $line) {
            if ($y >= $in->bottom()) {
                return;
            }
            $x = $in->x;
            foreach ($line as [$text, $status]) {
                $x += self::chip($r, $ink, $x, $y, $text, $status, $frame->tty()) + 1;
            }
            $y++;
        }
        $sel = $d->sel;
        if ($sel !== null && $y < $in->bottom()) {
            $pct = $sel->percentUsed ?? 0;
            $label = Lang::t('ipmi.sel');
            $x = $in->x + $r->put($in->x, $y, $label . ' ', $ink->fg('title'));
            $full = $pct >= 100 || $sel->overflow;
            $text = Lang::t($full ? 'ipmi.sel_full' : 'ipmi.sel_fill', ['pct' => $pct, 'n' => $sel->entries]);
            $bw = min(16, $in->right() - $x - mb_strwidth($text) - 1);
            if ($bw >= 4) {
                $r->ansi($x, $y, IpmiPaint::bar($ink, $bw, (float) $pct, 'process', $frame->tty() ? '■' : '▰', $frame->tty() ? '■' : '▱'));
                $x += $bw + 1;
            }
            $sev = $full ? SensorStatus::Critical : ($pct >= 75 ? SensorStatus::NonCritical : SensorStatus::Ok);
            $r->put($x, $y, IpmiPaint::clip($text, $in->right() - $x), $sev === SensorStatus::Ok ? $ink->fg('main_fg') : Symbols::BOLD . IpmiPaint::status($ink, $sev));
            $y++;
            if ($sel->latest !== '' && $y < $in->bottom()) {
                $lead = Lang::t('ipmi.sel_last') . ' ';
                $x = $in->x + $r->put($in->x, $y, $lead, $ink->fg('inactive_fg'));
                foreach (self::wrap($sel->latest, $in->right() - $x, $in->bottom() - $y) as $line) {
                    $r->put($x, $y++, $line, $ink->fg('graph_text'));
                }
            }
        }
        if (IpmiPanel::showSerials($frame->config)) {
            foreach ($d->info?->serials ?? [] as $label => $serial) {
                if ($y >= $in->bottom()) {
                    break;
                }
                $x = $in->x + $r->put($in->x, $y, Lang::t('ipmi.serial.' . $label) . ' ', $ink->fg('inactive_fg'));
                $r->put($x, $y, IpmiPaint::clip($serial, $in->right() - $x), $ink->fg('main_fg'));
                $y++;
            }
        }
    }

    // ---- sensor lists --------------------------------------------------------

    /**
     * Temperatures in `$rows` slots: intake first, then BMC order when all
     * fit, else hottest relative to its limit first.
     *
     * @param list<IpmiSensor> $temps
     * @return array{0: list<IpmiSensor>, 1: int} shown, hidden count
     */
    public static function pickTemps(array $temps, int $slots): array
    {
        if (\count($temps) <= $slots) {
            $inlet = array_values(array_filter($temps, IpmiNames::inlet(...)));
            $rest = array_values(array_filter($temps, static fn (IpmiSensor $s): bool => !IpmiNames::inlet($s)));

            return [[...$inlet, ...$rest], 0];
        }
        $inlet = array_values(array_filter($temps, IpmiNames::inlet(...)));
        $rest = array_values(array_filter($temps, static fn (IpmiSensor $s): bool => !IpmiNames::inlet($s)));
        usort($rest, static fn (IpmiSensor $a, IpmiSensor $b): int => [$b->severity()->severity(), $b->value / IpmiNames::tempLimit($b)] <=> [$a->severity()->severity(), $a->value / IpmiNames::tempLimit($a)]);
        $shown = \array_slice([...\array_slice($inlet, 0, 1), ...$rest], 0, max(0, $slots));

        return [$shown, \count($temps) - \count($shown)];
    }

    private static function temps(Region $r, Border $border, Ink $ink, IpmiData $d, Rect $in, Rect $box, int $cols, bool $tty = false): void
    {
        [$shown, $hidden] = self::pickTemps($d->readings->temps, $cols * $in->height);
        $nameW = self::nameWidth($d, $shown, $in->width, $cols);
        self::grid($r, $in, $cols, $shown, static function (IpmiSensor $s, int $x, int $y, int $w) use ($r, $ink, $nameW, $d, $tty): void {
            $limit = IpmiNames::tempLimit($s);
            $pct = 100 * (float) $s->value / $limit;
            $sev = $s->severity();
            $inlet = IpmiNames::inlet($s);
            if ($w < self::LIST_MIN['temps']) {
                $value = sprintf('%d°C', (int) round((float) $s->value));
                self::dense($r, $ink, $x, $y, $w, $d->label($s), $value, $sev->severity() > 0 ? Symbols::BOLD . IpmiPaint::status($ink, $sev) : Symbols::BOLD . $ink->gradient('temp', (int) round(min(100.0, $pct))), $inlet);

                return;
            }
            $name = Just::left($d->label($s), $nameW);
            $r->put($x, $y, $name, $inlet ? Symbols::BOLD . $ink->fg('hi_fg') : $ink->fg('main_fg'));
            $value = sprintf('%3d°C', (int) round((float) $s->value));
            $vx = $x + $nameW + 1;
            $r->put($vx, $y, $value, $sev->severity() > 0 ? Symbols::BOLD . IpmiPaint::status($ink, $sev) : Symbols::BOLD . $ink->gradient('temp', (int) round(min(100.0, $pct))));
            $lim = sprintf('%d', (int) round($limit));
            $bw = $w - $nameW - 1 - 5 - 1 - 1 - mb_strwidth($lim);
            $spark = 0;
            if ($w >= self::SPARK_FROM && !$tty) {
                $spark = $bw - min(self::BAR_MAX, max(10, intdiv($bw, 2))) - 1;
                $bw -= $spark + 1;
            }
            if ($bw >= 3) {
                $r->ansi($vx + 6, $y, IpmiPaint::bar($ink, $bw, $pct, 'temp'));
                $r->put($vx + 6 + $bw + 1, $y, $lim, $ink->fg($s->upper() !== null ? 'graph_text' : 'inactive_fg'));
            }
            $series = $d->series[$s->name] ?? [];
            if ($spark >= 4 && $series !== []) {
                // Auto-ranged around the sensor's own swing (at least 4 °C) so a slow drift still shows.
                $lo = min($series);
                $hi = max(max($series), $lo + 4.0);
                $r->ansi($x + $w - $spark, $y, PowerHistory::spark($ink, $spark, $series, $lo - 0.5, $hi, 'temp'));
            }
        });
        self::more($r, $border, $ink, $box, $hidden);
    }

    private static function fans(Region $r, Border $border, Ink $ink, IpmiData $d, Rect $in, Rect $box, int $cols, bool $tty = false): void
    {
        $fans = $d->readings->fans;
        $shown = \array_slice($fans, 0, $cols * $in->height);
        $nameW = self::nameWidth($d, $shown, $in->width, $cols, 10);
        self::grid($r, $in, $cols, $shown, static function (IpmiSensor $s, int $x, int $y, int $w) use ($r, $ink, $nameW, $d, $tty): void {
            $value = $s->kind === SensorKind::FanDuty ? sprintf('%.0f%%', (float) $s->value) : IpmiPaint::rpm((float) $s->value);
            $sev = $s->severity();
            if ($w < self::LIST_MIN['fans']) {
                $pct = (int) round(min(100.0, 100 * (float) $s->value / $d->fanScale($s)));
                self::dense($r, $ink, $x, $y, $w, $d->label($s), $value, $sev->severity() > 0 ? Symbols::BOLD . IpmiPaint::status($ink, $sev) : $ink->gradient('download', $pct));

                return;
            }
            $r->put($x, $y, Just::left($d->label($s), $nameW), $ink->fg('main_fg'));
            $vw = 5;
            $bw = $w - $nameW - 1 - $vw - 1;
            $spark = 0;
            if ($w >= self::SPARK_FROM && !$tty) {
                $spark = $bw - min(self::BAR_MAX, max(10, intdiv($bw, 2))) - 1;
                $bw -= $spark + 1;
            }
            if ($bw >= 3) {
                $r->ansi($x + $nameW + 1, $y, IpmiPaint::bar($ink, $bw, 100 * (float) $s->value / $d->fanScale($s), 'download', $tty ? '■' : '▰', $tty ? '■' : '▱'));
            }
            $r->put($x + $nameW + 1 + max(0, $bw) + 1, $y, Just::right($value, $vw), $sev->severity() > 0 ? Symbols::BOLD . IpmiPaint::status($ink, $sev) : $ink->fg('main_fg'));
            $series = $d->series[$s->name] ?? [];
            if ($spark >= 4 && $series !== []) {
                // Auto-ranged around the fan's own swing (at least 5% of its scale).
                $lo = min($series);
                $hi = max(max($series), $lo + 0.05 * $d->fanScale($s));
                $r->ansi($x + $w - $spark, $y, PowerHistory::spark($ink, $spark, $series, $lo, $hi, 'download'));
            }
        });
        self::more($r, $border, $ink, $box, \count($fans) - \count($shown));
    }

    private static function volts(Region $r, Border $border, Ink $ink, IpmiData $d, Rect $in, Rect $box, int $cols, bool $tty = false): void
    {
        $volts = $d->readings->volts;
        $shown = \array_slice($volts, 0, $cols * $in->height);
        $nameW = self::nameWidth($d, $shown, $in->width, $cols, 11);
        self::grid($r, $in, $cols, $shown, static function (IpmiSensor $s, int $x, int $y, int $w) use ($r, $ink, $nameW, $tty, $d): void {
            $v = (float) $s->value;
            $sev = $s->severity();
            $text = sprintf($v >= 10 ? '%.2fV' : '%.3fV', $v);
            if ($w < self::LIST_MIN['volts']) {
                self::dense($r, $ink, $x, $y, $w, $d->label($s), $text, $sev->severity() > 0 ? Symbols::BOLD . IpmiPaint::status($ink, $sev) : IpmiPaint::status($ink, SensorStatus::Ok));

                return;
            }
            $r->put($x, $y, Just::left($d->label($s), $nameW), $ink->fg('main_fg'));
            $vx = $x + $nameW + 1;
            $r->put($vx, $y, Just::right($text, 7), $sev->severity() > 0 ? Symbols::BOLD . IpmiPaint::status($ink, $sev) : $ink->fg('main_fg'));
            $bw = $w - $nameW - 1 - 7 - 1;
            if ($bw < 5) {
                return;
            }
            $series = $d->series[$s->name] ?? [];
            if ($w >= self::SPARK_FROM && !$tty) {
                $spark = $bw - min(self::BAR_MAX + 6, max(12, intdiv($bw, 2))) - 1;
                $bw -= $spark + 1;
                if ($spark >= 4 && $series !== []) {
                    // Auto-ranged around the rail's own swing (at least 1% of it).
                    $lo = min($series);
                    $hi = max(max($series), $lo + 0.01 * abs($lo));
                    $r->ansi($x + $w - $spark, $y, PowerHistory::spark($ink, $spark, $series, $lo, $hi, 'free'));
                }
            }
            $lo = $s->lnc ?? $s->lcr ?? $s->lnr;
            $hi = $s->unc ?? $s->ucr ?? $s->unr;
            $mid = IpmiNames::nominal($s) ?? ($lo !== null && $hi !== null ? ($lo + $hi) / 2 : $v);
            $lo ??= $mid - abs($mid) * 0.1;
            $hi ??= $mid + abs($mid) * 0.1;
            $r->ansi($vx + 8, $y, IpmiPaint::deviation($ink, $bw, $v, $lo, $mid, $hi, Symbols::BOLD . IpmiPaint::status($ink, $sev === SensorStatus::Unknown ? SensorStatus::Ok : $sev), $tty));
        });
        self::more($r, $border, $ink, $box, \count($volts) - \count($shown));
    }

    /** A dense entry: the name cut to fit, the value right-aligned in its colour. */
    private static function dense(Region $r, Ink $ink, int $x, int $y, int $w, string $name, string $value, string $sgr, bool $strong = false): void
    {
        $vw = mb_strwidth($value);
        $r->put($x, $y, Just::left($name, max(0, $w - $vw - 1)), $strong ? Symbols::BOLD . $ink->fg('hi_fg') : $ink->fg('main_fg'));
        $r->put($x + $w - $vw, $y, $value, $sgr);
    }

    /**
     * Lay `$items` out column-major in `$cols` sub-columns of `$in`, one
     * per row, with a 2-cell gutter, each entry at most `$max` wide.
     *
     * @param list<IpmiSensor> $items
     * @param \Closure(IpmiSensor, int, int, int): void $draw
     */
    private static function grid(Region $r, Rect $in, int $cols, array $items, \Closure $draw, int $max = PHP_INT_MAX): void
    {
        $rows = max(1, (int) ceil(\count($items) / $cols));
        $cw = min($max, intdiv($in->width - 2 * ($cols - 1), $cols));
        foreach ($items as $i => $s) {
            $c = intdiv($i, $rows);
            $row = $i % $rows;
            if ($row >= $in->height) {
                continue;
            }
            $draw($s, $in->x + $c * ($cw + 2), $in->y + $row, $cw);
        }
    }

    /** @param list<IpmiSensor> $items */
    private static function nameWidth(IpmiData $d, array $items, int $width, int $cols, int $cap = 13): int
    {
        $cw = intdiv($width - 2 * ($cols - 1), $cols);
        $longest = 4;
        foreach ($items as $s) {
            $longest = max($longest, mb_strwidth($d->label($s)));
        }

        return max(4, min($longest, $cap, intdiv($cw, 2)));
    }

    /** `┘+N more└` on a section's bottom border, right-aligned. */
    private static function more(Region $r, Border $border, Ink $ink, Rect $box, int $hidden): void
    {
        if ($hidden <= 0) {
            return;
        }
        $text = Lang::t('ipmi.more', ['n' => $hidden]);
        $x = $box->right() - 3 - mb_strwidth($text);
        if ($x <= $box->x + 1) {
            return;
        }
        [$open, $close] = $border->embedJunctions(true);
        $y = $box->bottom() - 1;
        $r->put($x - 1, $y, $open, $ink->fg('div_line'));
        $r->put($x, $y, $text, $ink->fg('graph_text'));
        $r->put($x + mb_strwidth($text), $y, $close, $ink->fg('div_line'));
    }

    // ---- COMPACT + TINY --------------------------------------------------------

    private static function compact(Region $r, PanelFrame $frame, IpmiData $d, Rect $in): void
    {
        $ink = $frame->ink;
        $watts = $d->watts();
        $left = 0;
        $chipsY = $in->bottom() - 1;
        $listBottom = $chipsY;
        if ($watts !== null && $in->width >= 50 && $in->height >= 6 && !$frame->tty()) {
            $gh = min($in->height - 3, 6);
            $gw = min(4 * $gh, (int) round($in->width * 0.42));
            $gh = min($gh, PowerGauge::height($gw));
            $scale = $d->scale();
            $win = $d->window();
            foreach (PowerGauge::render($ink, $gw, $gh, $watts / $scale, $win === null ? null : $win[0] / $scale, $win === null ? null : $win[1] / $scale, $win === null ? null : $win[2] / $scale, 'cpu') as $i => $row) {
                $r->ansi($in->x, $in->y + $i, $row);
            }
            $pct = (int) round(100 * $watts / $scale);
            $big = IpmiPaint::watts($watts);
            $line = $big . ' ' . $pct . '%';
            $bx = $in->x + max(0, intdiv($gw - mb_strwidth($line), 2));
            $r->put($bx, $in->y + $gh, $big, Symbols::BOLD . $ink->gradient('cpu', $pct));
            $r->put($bx + mb_strwidth($big) + 1, $in->y + $gh, $pct . '%', $ink->fg('graph_text'));
            if ($win !== null && $in->y + $gh + 1 < $chipsY) {
                $mm = Lang::t('ipmi.min') . ' ' . IpmiPaint::watts($win[0], false) . ' ' . Lang::t('ipmi.max') . ' ' . IpmiPaint::watts($win[2], false);
                if (mb_strwidth($mm) > $gw) {
                    $mm = IpmiPaint::watts($win[0], false) . '–' . IpmiPaint::watts($win[2]);
                }
                $r->put($in->x + max(0, intdiv($gw - mb_strwidth($mm), 2)), $in->y + $gh + 1, IpmiPaint::clip($mm, $gw), $ink->fg('inactive_fg'));
            }
            $left = $gw + 2;
        } elseif ($watts !== null) {
            self::powerLine($r, $ink, $d, $in->x, $in->y, $in->width);
            $in = Rect::new($in->x, $in->y + 1, $in->width, $in->height - 1);
        }
        $area = Rect::new($in->x + $left, $in->y, $in->width - $left, max(0, $listBottom - $in->y));
        $y = $area->y;
        $fans = $d->readings->fans;
        $tempRows = $area->height - ($fans !== [] ? 1 : 0);
        [$temps] = self::pickTemps($d->readings->temps, max(0, $tempRows));
        $nameW = self::nameWidth($d, $temps, $area->width, 1, 11);
        foreach ($temps as $s) {
            $limit = IpmiNames::tempLimit($s);
            $pct = 100 * (float) $s->value / $limit;
            $sev = $s->severity();
            $r->put($area->x, $y, Just::left($d->label($s), $nameW), IpmiNames::inlet($s) ? Symbols::BOLD . $ink->fg('hi_fg') : $ink->fg('main_fg'));
            $r->put($area->x + $nameW + 1, $y, sprintf('%3d°C', (int) round((float) $s->value)), $sev->severity() > 0 ? Symbols::BOLD . IpmiPaint::status($ink, $sev) : Symbols::BOLD . $ink->gradient('temp', (int) round(min(100.0, $pct))));
            $bw = min(24, $area->width - $nameW - 7);
            if ($bw >= 3) {
                $r->ansi($area->x + $nameW + 7, $y, IpmiPaint::bar($ink, $bw, $pct, 'temp'));
            }
            $y++;
        }
        if ($fans !== [] && $y < $area->bottom()) {
            self::fanSummary($r, $ink, $d, $area->x, $y, $area->width, $frame->tty());
        }
        self::chipLine($r, $ink, $d, $in->x, $chipsY, $in->width, $frame->tty());
    }

    private static function tiny(Region $r, Ink $ink, IpmiData $d, Rect $in, bool $tty = false): void
    {
        $y = $in->y;
        $bottom = $in->bottom();
        if ($d->watts() !== null) {
            self::powerLine($r, $ink, $d, $in->x, $y++, $in->width);
        }
        $chips = $bottom - $y >= 2;
        [$temps] = self::pickTemps($d->readings->temps, max(0, $bottom - $y - ($chips ? 1 : 0)));
        foreach ($temps as $s) {
            $label = IpmiPaint::clip($d->label($s), max(3, $in->width - 6));
            $sev = $s->severity();
            $r->put($in->x, $y, $label, $ink->fg('main_fg'));
            $pct = 100 * (float) $s->value / IpmiNames::tempLimit($s);
            $r->put($in->right() - 5, $y, sprintf('%3d°C', (int) round((float) $s->value)), $sev->severity() > 0 ? Symbols::BOLD . IpmiPaint::status($ink, $sev) : $ink->gradient('temp', (int) round(min(100.0, $pct))));
            $y++;
        }
        if ($chips && $y < $bottom) {
            self::chipLine($r, $ink, $d, $in->x, $bottom - 1, $in->width, $tty);
        }
    }

    private static function powerLine(Region $r, Ink $ink, IpmiData $d, int $x, int $y, int $width): void
    {
        $watts = (float) $d->watts();
        $scale = $d->scale();
        $label = IpmiPaint::watts($watts);
        $pct = (int) round(100 * $watts / $scale);
        $bw = $width - mb_strwidth($label) - 1;
        if ($bw >= 4) {
            $r->ansi($x, $y, IpmiPaint::bar($ink, $bw, (float) $pct, 'cpu'));
        }
        $r->put($x + max(0, $bw + 1), $y, IpmiPaint::clip($label, $width), Symbols::BOLD . $ink->gradient('cpu', $pct));
    }

    private static function fanSummary(Region $r, Ink $ink, IpmiData $d, int $x, int $y, int $width, bool $tty = false): void
    {
        $fans = $d->readings->fans;
        $values = array_map(static fn (IpmiSensor $s): float => (float) $s->value, $fans);
        $duty = $fans[0]->kind === SensorKind::FanDuty;
        $fmt = static fn (float $v): string => $duty ? sprintf('%.0f%%', $v) : IpmiPaint::rpm($v);
        $text = Lang::t('ipmi.fans_summary', ['n' => \count($fans), 'min' => $fmt(min($values)), 'max' => $fmt(max($values))]);
        $worst = SensorStatus::Ok;
        foreach ($fans as $f) {
            $worst = SensorStatus::worst($worst, $f->severity());
        }
        $red = $d->readings->redundancy;
        if ($red !== null) {
            $worst = SensorStatus::worst($worst, $red->severity());
        }
        $col = $x + $r->put($x, $y, IpmiPaint::dot($tty) . ' ', IpmiPaint::status($ink, $worst));
        $col += $r->put($col, $y, IpmiPaint::clip($text, $width - 2), $ink->fg('main_fg'));
        $avg = array_sum($values) / \count($values);
        $bw = $x + $width - $col - 1;
        if ($bw >= 4) {
            $r->ansi($col + 1, $y, IpmiPaint::bar($ink, min(12, $bw), 100 * $avg / $d->fanScale($fans[0]), 'download', $tty ? '■' : '▰', $tty ? '■' : '▱'));
        }
    }

    /** The chips that are not ok, or one "all ok" chip; then the SEL fill when it is high. */
    private static function chipLine(Region $r, Ink $ink, IpmiData $d, int $x, int $y, int $width, bool $tty = false): void
    {
        $chips = array_values(array_filter(self::chips($d), static fn (array $c): bool => $c[1]->severity() > 0));
        $sel = $d->sel;
        if ($sel !== null && ($sel->percentUsed ?? 0) >= 75) {
            $full = ($sel->percentUsed ?? 0) >= 100 || $sel->overflow;
            $chips[] = [Lang::t('ipmi.chip.sel', ['pct' => $sel->percentUsed]), $full ? SensorStatus::Critical : SensorStatus::NonCritical];
        }
        if ($chips === []) {
            $chips[] = [Lang::t('ipmi.chip.all_ok'), SensorStatus::Ok];
        }
        $col = $x;
        foreach ($chips as [$text, $status]) {
            if ($col + mb_strwidth($text) + 2 > $x + $width) {
                break;
            }
            $col += self::chip($r, $ink, $col, $y, $text, $status, $tty) + 1;
        }
        // Then the facts that are fine, quietly, while they fit.
        $facts = [];
        $rd = $d->readings;
        if ($rd->psus !== [] && $rd->absentPsus() === []) {
            $facts[] = Lang::t('ipmi.chip.psus', ['n' => \count($rd->livePsus()), 'of' => \count($rd->psus)]);
        }
        if ($sel !== null && ($sel->percentUsed ?? 0) < 75) {
            $facts[] = Lang::t('ipmi.chip.sel', ['pct' => $sel->percentUsed ?? 0]);
        }
        if ($rd->redundancy !== null && $rd->redundancy->severity()->severity() === 0) {
            $facts[] = Lang::t('ipmi.fans_redundant');
        }
        $col--; // over the gap after the last chip
        foreach ($facts as $fact) {
            $text = ' · ' . $fact;
            if ($col + mb_strwidth($text) > $x + $width) {
                break;
            }
            $col += $r->put($col, $y, $text, $ink->fg('graph_text'));
        }
    }
}
