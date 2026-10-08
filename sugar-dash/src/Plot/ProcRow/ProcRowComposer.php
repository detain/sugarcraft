<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Plot\ProcRow;

use SugarCraft\Core\Util\Ansi;
use SugarCraft\Core\Util\Color;
use SugarCraft\Core\Util\ColorProfile;
use SugarCraft\Core\Util\Width;
use SugarCraft\Dash\Plot\Braille\DualSampleGraph;
use SugarCraft\Dash\Plot\DistanceFade;

/**
 * Composes one btop process-list row (normal, non-tree view):
 *
 *   pid name cmd threads user mem [5×1 cpu graph on graph_bg] cpu%
 *
 * Coloring follows Proc::draw. The selected (or followed) row is a
 * highlight bar — bg + fg, every field bold. Every other row fades its
 * text through the `proc` ramp by distance from the selection
 * (`proc_gradient`), and colors cpu/name, mem and threads by value through
 * the `process` ramp, blended back toward grey by the same distance
 * (`proc_colors`); see {@see DistanceFade}. The mini-graph prints the
 * family's graph_bg glyph (⣀ ▄ ░) in inactive_fg on every cell its
 * samples leave blank and its own glyphs in the cpu color — btop's
 * "underlay, move back 5, overdraw" composite.
 *
 * Policy stays with the caller: sorting, selection, scrolling, which
 * pids own graphs ({@see ProcGraphTracker}) and the column widths
 * ({@see ProcColumns}). Every row is exactly {@see width()} cells.
 *
 * Deviations, presentation-side: btop's cursor jumps (`Mv::to` after cmd,
 * `Mv::l(5)` before the graph) become fixed-width fields and per-cell
 * composition; SGR is emitted as a full reset + state on each style
 * change rather than btop's incremental Fx::b / Fx::ub toggles, so a row
 * renders identically wherever it is spliced; main_bg is not painted
 * (terminal default). Control characters — C0, DEL and C1, which btop
 * leaves in name/user and only maps C0 out of cmd — become spaces in
 * every text field so a process title cannot inject escapes. The plan
 * sketch said the selected row skips its graph; btop's source draws it
 * (bold, on the highlight bar), and so does this.
 *
 * Mirrors aristocratos/btop Proc::draw's normal-view row
 * (src/btop_draw.cpp).
 */
final class ProcRowComposer
{
    private const BOLD = "\x1b[1m";

    private ProcColumns $columns;
    private ProcRowPalette $palette;
    private bool $procColors = true;
    private bool $procGradient = true;
    private string $family = DualSampleGraph::FAMILY_BRAILLE;

    private function __construct(private int $width)
    {
        $this->columns = ProcColumns::btop($width + 2);
        $this->palette = ProcRowPalette::btop();
    }

    /**
     * @param int $width inner width of the proc box (box width − 2 borders)
     * @throws \InvalidArgumentException when btop's columns do not fit $width
     */
    public static function new(int $width): self
    {
        $self = new self($width);
        $self->assertFits($self->columns, $width);
        return $self;
    }

    /** Re-derive btop's widths for a new inner width. */
    public function withWidth(int $width, bool $cpuGraphs = true): self
    {
        $columns = ProcColumns::btop($width + 2, $cpuGraphs);
        $this->assertFits($columns, $width);
        return $this->mutate(['width' => $width, 'columns' => $columns]);
    }

    /**
     * Caller-owned layout; slack right of the cpu column is padded.
     *
     * @throws \InvalidArgumentException when the columns are wider than the row
     */
    public function withColumns(ProcColumns $columns): self
    {
        $this->assertFits($columns, $this->width);
        return $this->mutate(['columns' => $columns]);
    }

    public function withPalette(ProcRowPalette $palette): self
    {
        return $this->mutate(['palette' => $palette]);
    }

    /** btop `proc_colors`: value-colored cpu/name, mem and threads. */
    public function withProcColors(bool $on): self
    {
        return $this->mutate(['procColors' => $on]);
    }

    /** btop `proc_gradient`: distance fade of text and metric colors. */
    public function withProcGradient(bool $on): self
    {
        return $this->mutate(['procGradient' => $on]);
    }

    /**
     * Graph family whose graph_bg glyph underlays rows without a graph
     * (a row with a graph uses that graph's own family).
     *
     * @throws \InvalidArgumentException on an unknown family
     */
    public function withFamily(string $family): self
    {
        DualSampleGraph::underlay($family, 0, Color::rgb(0, 0, 0));
        return $this->mutate(['family' => $family]);
    }

    public function width(): int
    {
        return $this->width;
    }

    public function columns(): ProcColumns
    {
        return $this->columns;
    }

    public function palette(): ProcRowPalette
    {
        return $this->palette;
    }

    public function procColors(): bool
    {
        return $this->procColors;
    }

    public function procGradient(): bool
    {
        return $this->procGradient;
    }

    public function family(): string
    {
        return $this->family;
    }

    /**
     * Render one row.
     *
     * @param int  $row       0-based index among the drawn rows (btop `lc`)
     * @param int  $selected  1-based selected row, 0 = none (btop `selected`)
     * @param int  $selectMax visible list rows (btop `select_max`), ≥ 1
     * @param ?DualSampleGraph $graph one-row graph for this pid, or null for underlay only
     * @param bool $followed  draw the followed-process highlight
     * @throws \InvalidArgumentException on $selectMax < 1 or a graph taller than one row
     */
    public function row(
        ProcRow $proc,
        int $row,
        int $selected,
        int $selectMax,
        ?DualSampleGraph $graph = null,
        bool $followed = false,
        ?ColorProfile $profile = null,
    ): string {
        if ($selectMax < 1) {
            throw new \InvalidArgumentException(sprintf('ProcRowComposer needs selectMax >= 1, got %d', $selectMax));
        }
        if ($graph !== null && $graph->height() !== 1) {
            throw new \InvalidArgumentException('ProcRowComposer graphs must be one row tall');
        }
        $profile ??= ColorProfile::detect();
        $p = $this->palette;
        $c = $this->columns;
        $highlight = $followed || ($row + 1 === $selected);

        // Styles are [fg, bold]; $bg is row-wide.
        if ($highlight) {
            $bg = $followed ? $p->followedBg : $p->selectedBg;
            $fg = $followed ? $p->followedFg : $p->selectedFg;
            $text = $cpuStyle = $memStyle = $threadStyle = $tailCpu = [$fg, true];
            $base = $underlay = $tail = [$fg, false];
        } else {
            $bg = null;
            $calc = DistanceFade::distance($selected, $row);
            $textFg = $this->procGradient ? DistanceFade::fadeColor($p->proc, $calc, $selectMax) : $p->mainFg;
            $text = [$textFg, false];
            $base = [$p->mainFg, false];
            $underlay = [$p->inactiveFg, false];
            $tail = $base;
            if ($this->procColors) {
                [$cpuStyle, $memStyle, $threadStyle] = array_map(
                    fn (int $v): array => [
                        $this->procGradient
                            ? DistanceFade::metricColor($p->procColor, $p->process, $v, $calc, $selectMax)
                            : DistanceFade::flatMetricColor($p->process, $v),
                        false,
                    ],
                    DistanceFade::metricValues($proc->cpu, $proc->memPercent, $proc->threads),
                );
                $tailCpu = $cpuStyle;
            } else {
                // btop's c_color = Fx::b: bold over whatever fg the row
                // already carries (the faded text color).
                $cpuStyle = $memStyle = $threadStyle = [$textFg, true];
                // Here `end` is only Fx::ub, so the inactive_fg btop emits
                // ahead of the underlay (even with graphs off) is never
                // reset: graph glyphs and cpu% come out bold inactive_fg.
                $tailCpu = [$p->inactiveFg, true];
                $tail = $underlay;
            }
        }

        $segs = [];
        $segs[] = [self::rjust((string) $proc->pid, ProcColumns::PID) . ' ', $text];
        $segs[] = [self::ljust(self::sanitize($proc->name), $c->prog), $cpuStyle];
        $segs[] = [' ', $base];
        if ($c->cmd > 0) {
            $segs[] = [self::ljust(self::sanitize($proc->cmd), $c->cmd) . ' ', $text];
        }
        if ($c->threads > 0) {
            $segs[] = [self::rjust(self::threadsLabel($proc->threads), $c->threads), $threadStyle];
            $segs[] = [' ', $base];
        }
        $segs[] = [self::ljust(self::userLabel(self::sanitize($proc->user), $c->user), $c->user) . ' ', $text];
        $segs[] = [self::rjust(self::sanitize($proc->memLabel ?? self::memLabel($proc->memPercent)), ProcColumns::MEM), $memStyle];
        $segs[] = [' ', $base];
        if ($c->cpuGraphs) {
            foreach ($this->graphCells($graph) as $cell) {
                $segs[] = $cell === null ? [$this->underlayGlyph($graph), $underlay] : [$cell, $tailCpu];
            }
        }
        $segs[] = [' ', $tail];
        $segs[] = [self::rjust(self::cpuLabel($proc->cpu), ProcColumns::CPU), $tailCpu];
        $segs[] = [str_repeat(' ', 2 + $this->width - $c->width()), $tail];

        return self::emit($segs, $bg, $profile);
    }

    /**
     * btop's cpu% text before right-justifying: `{:.2f}` cut to 3 chars
     * below 10 and in [100, 1000) ("5.6", "100"), kept whole in between
     * ("12.34", which the 4-wide column then cuts to "12.3"), and above
     * 10 000 scaled to thousands with a "k" ("12k"). The cut is a
     * truncation, not a rounding, as btop's string resize is.
     */
    public static function cpuLabel(float $cpu): string
    {
        $cpu = is_finite($cpu) ? max(0.0, $cpu) : 0.0;
        $s = sprintf('%.2f', $cpu);
        if ($cpu < 10 || ($cpu >= 100 && $cpu < 1000)) {
            return substr($s, 0, 3);
        }
        if ($cpu >= 10_000) {
            $s = substr(sprintf('%.2f', $cpu / 1000), 0, 3);
            if (str_ends_with($s, '.')) {
                $s = substr($s, 0, -1);
            }
            return $s . 'k';
        }
        return $s;
    }

    /** btop's mem% text: "0%" under 0.01, else `{:.1f}` cut to 3 chars, trailing "." dropped. */
    public static function memLabel(float $percent): string
    {
        $p = is_finite($percent) ? max(0.0, min(100.0, $percent)) : 0.0;
        if ($p < 0.01) {
            return '0%';
        }
        $s = sprintf('%.1f', $p);
        if (strlen($s) > 3) {
            $s = substr($s, 0, 3);
        }
        if (str_ends_with($s, '.')) {
            $s = substr($s, 0, -1);
        }
        return $s . '%';
    }

    /** btop shortens five-digit thread counts to thousands: 12345 → "12K". */
    public static function threadsLabel(int $threads): string
    {
        return $threads > 9999 ? intdiv($threads, 1000) . 'K' : (string) $threads;
    }

    /** btop marks a cut user name with a trailing "+" inside the column. */
    public static function userLabel(string $user, int $width): string
    {
        if (Width::string($user) <= $width) {
            return $user;
        }
        return Width::truncate($user, max(0, $width - 1)) . '+';
    }

    /**
     * The graph column's cells, oldest left; null marks a transparent
     * cell (underlay shows through). A graph wider than the column shows
     * its newest cells, a narrower one is left-padded with transparency.
     *
     * @return list<?string>
     */
    private function graphCells(?DualSampleGraph $graph): array
    {
        if ($graph === null) {
            return array_fill(0, ProcColumns::GRAPH, null);
        }
        // Rendered uncolored with no underlay, a one-row graph prints a
        // space exactly where btop's frame is transparent (Mv::r(1)): the
        // only blank glyph in every table is the band (0,0) entry.
        $plain = $graph->withoutUnderlay()->withoutGradient()->render(ColorProfile::NoTty);
        $cells = array_map(
            static fn (string $g): ?string => $g === ' ' ? null : $g,
            mb_str_split($plain),
        );
        $cells = array_slice($cells, -ProcColumns::GRAPH);
        return [...array_fill(0, ProcColumns::GRAPH - count($cells), null), ...$cells];
    }

    private function underlayGlyph(?DualSampleGraph $graph): string
    {
        return $graph !== null
            ? $graph->underlayGlyph()
            : DualSampleGraph::SYMBOLS[$this->family . '_up'][6];
    }

    /**
     * @param list<array{0:string, 1:array{0:Color, 1:bool}}> $segs
     */
    private static function emit(array $segs, ?Color $bg, ColorProfile $profile): string
    {
        $out = '';
        $active = '';
        $bgSgr = $bg === null ? '' : $bg->toBg($profile);
        foreach ($segs as [$text, [$fg, $bold]]) {
            if ($text === '') {
                continue;
            }
            $sgr = $bgSgr . $fg->toFg($profile) . ($bold && $profile !== ColorProfile::NoTty ? self::BOLD : '');
            if ($sgr !== $active) {
                // Full reset before each state keeps a bold or bg from
                // leaking into a field that should not carry it.
                $out .= Ansi::reset() . $sgr;
                $active = $sgr;
            }
            $out .= $text;
        }
        return $active === '' ? $out : $out . Ansi::reset();
    }

    /** Left-justify into exactly $w cells, cutting the tail (btop ljust, limit on). */
    private static function ljust(string $s, int $w): string
    {
        if ($w <= 0) {
            return '';
        }
        return Width::padRight(Width::truncate($s, $w), $w);
    }

    /** Right-justify into exactly $w cells; an overlong value keeps its head (btop rjust, limit on). */
    private static function rjust(string $s, int $w): string
    {
        if (Width::string($s) > $w) {
            return self::ljust($s, $w);
        }
        return Width::padLeft($s, $w);
    }

    private static function sanitize(string $s): string
    {
        $s = mb_scrub($s, 'UTF-8');
        return preg_replace('/[\x{00}-\x{1F}\x{7F}-\x{9F}]/u', ' ', $s) ?? '';
    }

    private function assertFits(ProcColumns $columns, int $width): void
    {
        if ($columns->width() > $width) {
            throw new \InvalidArgumentException(sprintf(
                'ProcRowComposer columns need %d cells, row is %d',
                $columns->width(),
                $width,
            ));
        }
    }

    /**
     * @param array<string, mixed> $props
     */
    private function mutate(array $props): self
    {
        $clone = clone $this;
        foreach ($props as $name => $value) {
            $clone->{$name} = $value;
        }
        return $clone;
    }
}
