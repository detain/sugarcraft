<?php

declare(strict_types=1);

namespace SugarCraft\CodeTime;

use SugarCraft\Charts\Sparkline\Sparkline;
use SugarCraft\Core\Util\Ansi;
use SugarCraft\Core\Util\Color;
use SugarCraft\Core\Util\ColorProfile;
use SugarCraft\Core\Util\Sanitize;
use SugarCraft\Core\Util\Width;
use SugarCraft\Style\Border;
use SugarCraft\Style\Layout;
use SugarCraft\Style\Position;
use SugarCraft\Style\Style;

/**
 * Pure view function. Three sections stacked vertically: header,
 * project + language ranking side-by-side, and the daily-activity
 * sparkline timeline. Under the width derived from PANE_TEXT/FRAME_CHROME
 * the two ranking panes stack vertically instead of side-by-side.
 *
 * Colour policy: the TUI paints under the {@see ColorProfile} Program
 * detected and handed to the Dashboard via ColorProfileMsg — hex tints
 * are emitted only at Ansi256/TrueColor tiers, so NO_COLOR, CLICOLOR=0,
 * TERM=dumb and the rest of the detection ladder all land on the same
 * authority instead of a hand-rolled NO_COLOR reading. When the Model
 * carries no profile (library use without Program) the historical
 * fallback applies: a set, non-empty NO_COLOR suppresses tints, with 0
 * and false read as not suppressing, otherwise the frame paints as before.
 */
final class Renderer
{
    /** Text cells a ranking pane asks for; its frame is that plus the border and padding. */
    private const PANE_TEXT = 26;

    /** Cells of blank gutter between the two ranking panes side by side. */
    private const PANE_GAP = 2;

    /**
     * Cells a box consumes around its text: 2 border columns + 2 padding
     * columns. `Style::width()` sizes the TEXT, so a frame is always
     * `width + FRAME_CHROME` wide — the number every budget here subtracts.
     */
    private const FRAME_CHROME = 4;

    /** Columns the side-by-side join needs: two pane frames plus the gutter. */
    private const SIDE_BY_SIDE = (self::PANE_TEXT + self::FRAME_CHROME) * 2 + self::PANE_GAP;

    /** Default ranking entries per pane; the height ladder trims this. */
    private const MAX_ENTRIES = 6;

    public static function render(Dashboard $d): string
    {
        $frame = self::assemble($d, self::MAX_ENTRIES);
        if ($d->height === null || self::rows($frame) <= $d->height) {
            return $frame;
        }
        // M18: the frame is written top-down, so anything taller than the
        // viewport scrolls its own header away. Drop ranking entries first —
        // each one removes two stacked rows.
        for ($entries = self::MAX_ENTRIES - 1; $entries >= 0; --$entries) {
            $frame = self::assemble($d, $entries);
            if (self::rows($frame) <= $d->height) {
                return $frame;
            }
        }
        // Still short? Join the panes side by side at the narrowest legal
        // width — the join shares the panes' body lines — and clip whatever
        // still overhangs, so row 1 stays the top border.
        $joined = self::assemble(new Dashboard(
            $d->source,
            $d->endDay,
            $d->days,
            $d->stats,
            max((int) $d->width, self::SIDE_BY_SIDE),
            $d->colorProfile,
            $d->height,
            $d->backendLabel,
            $d->droppedLines,
            $d->unreadableFiles,
            $d->loadError,
        ), 0);
        $lines = array_slice(explode("\n", rtrim($joined, "\n")), 0, max(1, $d->height));
        return self::clipRows(implode("\n", $lines), $d->width) . "\n";
    }

    /** Rows a rendered frame occupies, excluding its trailing newline. */
    private static function rows(string $frame): int
    {
        return count(explode("\n", rtrim($frame, "\n")));
    }

    /**
     * The frame body: header + ranking panes + timeline + help, clipped to
     * the terminal width. $entries caps each ranking pane.
     */
    private static function assemble(Dashboard $d, int $entries): string
    {
        $stats = $d->stats;

        $header = self::clip(self::header($d), $d->width);
        $projects = self::ranking(Lang::t('ui.top_projects'), $stats->perProject(), '#fde68a', $d->width, $d->colorProfile, $entries);
        // CT-S30: the lower pane answers the `t` toggle — By tag folds the
        // labels the store has always carried; By language remains the default.
        $languages = $d->byTags
            ? self::ranking(Lang::t('ui.by_tags'), $stats->perTag(), '#7dd3fc', $d->width, $d->colorProfile, $entries)
            : self::ranking(Lang::t('ui.by_language'), $stats->perLanguage(), '#7dd3fc', $d->width, $d->colorProfile, $entries);

        // Side by side only once two frames plus the gutter actually fit. The
        // threshold is derived from the numbers above, because the old literal
        // 56 was 6 cells short of the geometry it was guarding — the join
        // landed on terminals that could not hold it and the right pane lost
        // its border.
        $sideBySide = self::SIDE_BY_SIDE;
        $stacked = $d->width !== null && $d->width < $sideBySide;
        $top = $stacked
            ? $projects . "\n" . $languages
            : Layout::joinHorizontal(Position::TOP, $projects, str_repeat(' ', self::PANE_GAP), $languages);

        $timeline = self::timeline($d);

        // CT-S19: the in-frame diagnostics channel. The banner (a load that
        // threw — previous Stats keep the rest of the frame honest) rides
        // right under the header; the per-load data-loss counts land above
        // the help row. Both go through clean()/clip(), so a hostile path
        // or exception text cannot inject frame bytes.
        $banner = $d->loadError === null ? '' : self::clip(self::tint(
            Style::new(),
            '#f87171',
            $d->colorProfile,
        )->render(' ' . self::clean($d->loadError)), $d->width) . "\n";
        $notes = '';
        if ($d->droppedLines > 0) {
            $notes .= self::clip(self::tint(Style::new(), '#fca5a5', $d->colorProfile)
                ->render(' ' . Lang::t('ui.diagnostics_dropped', ['count' => $d->droppedLines])), $d->width) . "\n";
        }
        if ($d->unreadableFiles > 0) {
            $notes .= self::clip(self::tint(Style::new(), '#fca5a5', $d->colorProfile)
                ->render(' ' . Lang::t('ui.diagnostics_unreadable', ['count' => $d->unreadableFiles])), $d->width) . "\n";
        }

        // CT-S13: the translated help row is longer than English in most
        // locales (German/pl fill 67-75 cells). A row that does not fit falls
        // back to the key-only variant, which is identical in every locale
        // and names every binding; clip() then only ever fires for terminals
        // narrower than even that, exactly as before.
        $helpText = Lang::t('ui.help_row');
        if ($d->width !== null && Width::string($helpText) > $d->width) {
            $helpText = Lang::t('ui.help_row_compact');
        }
        $help = self::clip(self::tint(Style::new(), '#7d6e98', $d->colorProfile)
            ->render($helpText), $d->width);

        return self::clipRows($header . "\n" . $banner . $top . "\n" . $timeline . "\n" . $notes . $help, $d->width) . "\n";
    }

    /**
     * The single place that guarantees no row outgrows the terminal. Each
     * section sizes itself properly on its own; this is the choke the geometry
     * pins measure, and it is what sugar-core's renderer will not do for us.
     */
    private static function clipRows(string $frame, ?int $width): string
    {
        if ($width === null) {
            return $frame;
        }
        return implode("\n", array_map(
            static fn(string $line): string => self::clip($line, $width),
            explode("\n", $frame),
        ));
    }

    /**
     * Cut a row down to the terminal. sugar-core's renderer does not clip, so
     * an over-long line hard-wraps and takes the frame's borders with it.
     */
    private static function clip(string $line, ?int $width): string
    {
        if ($width === null) {
            return $line;
        }
        return Width::truncateAnsi($line, max(1, $width));
    }

    /**
     * Apply a hex tint unless the host asked for no colour.
     */
    private static function tint(Style $style, string $hex, ?ColorProfile $profile): Style
    {
        return self::colorSuppressed($profile) ? $style : $style->foreground(Color::hex($hex));
    }

    /**
     * With a detected profile, ANSI-16 and below cannot carry a hex tint at
     * all, so every tier under Ansi256 paints uncoloured (Ascii is
     * no-color.org's "text decoration kept, colour off" — the frame's bold
     * stays). Without one (rendered outside a Program) the historical rule
     * applies: NO_COLOR truthiness — set-and-not-one-of 0/false/empty.
     */
    private static function colorSuppressed(?ColorProfile $profile): bool
    {
        if ($profile !== null) {
            return !in_array($profile, [ColorProfile::Ansi256, ColorProfile::TrueColor], true);
        }
        $value = getenv('NO_COLOR');
        if ($value === false) {
            return false;
        }
        return $value !== '' && $value !== '0' && strtolower($value) !== 'false';
    }

    private static function header(Dashboard $d): string
    {
        $end = $d->endDay->format(Lang::t('ui.range_format'));
        $start = $d->endDay->modify('-' . ($d->days - 1) . ' days')->format(Lang::t('ui.range_format'));
        $total = Stats::formatHours($d->stats->totalSeconds());
        $title = self::tint(Style::new()->bold(), '#ff5f87', $d->colorProfile)
            ->render(' CandyCodetime ');
        $range = self::tint(Style::new(), '#a78bfa', $d->colorProfile)
            ->render("[{$start} – {$end}]");
        $tot = self::tint(Style::new()->bold(), '#6ee7b7', $d->colorProfile)
            ->render($total);
        // CT-S07: the provenance of every number on this screen, painted
        // next to the name. clean()-routed even though the CLI only ever
        // passes its own two words - the property is public and settable.
        $badge = '';
        if ($d->backendLabel !== null) {
            $badge = self::tint(Style::new(), '#7d6e98', $d->colorProfile)
                    ->render('[' . self::clean($d->backendLabel) . ']') . ' ';
        }
        return $title . '  ' . $badge . $range . '   ' . Lang::t('ui.total') . ' ' . $tot;
    }

    /**
     * One ranking pane: title, up to six `name … duration` rows, and a
     * "+N more" line when the cap hid something.
     *
     * The duration column is right-aligned and the name column takes exactly
     * what is left of the pane's text cells. Both are derived from a first pass
     * over the shown rows, because the old shape padded the name to a fixed 14
     * cells and then added a second, name-length-derived gap — which pushed the
     * duration past the pane's own text area, where it was cut.
     *
     * @param array<string,int> $rows
     */
    private static function ranking(string $title, array $rows, string $colour, ?int $terminal, ?ColorProfile $profile, int $entries = 6): string
    {
        // The frame is 4 cells wider than the text box, so shrinking the text
        // is what keeps a narrow terminal inside itself.
        $text = $terminal === null
            ? self::PANE_TEXT
            : max(6, min(self::PANE_TEXT, $terminal - self::FRAME_CHROME));

        $shown = [];
        foreach ($rows as $name => $secs) {
            if (count($shown) >= $entries) {
                break;
            }
            // clean() owns the whole sweep (untrusted() starts from its own
            // Ansi::strip pass), so the name is handed over raw.
            $clean = self::clean((string) $name);
            $shown[] = [$clean, Stats::formatHours($secs)];
        }

        $lines = [Width::truncate($title, $text)];
        if ($rows === []) {
            $lines[] = self::tint(Style::new(), '#7d6e98', $profile)->render(Width::truncate('  ' . Lang::t('ui.no_activity'), $text));
        } elseif ($shown === []) {
            // A cap of zero entries is not "no activity" — a screen short
            // enough to starve the pane must name how much it hides.
            $lines[] = self::tint(Style::new(), '#7d6e98', $profile)
                ->render(Width::truncate('  ' . Lang::t('ui.more_suffix', ['count' => count($rows)]), $text));
        } else {
            $durationWidth = 4;
            foreach ($shown as [, $duration]) {
                $durationWidth = max($durationWidth, Width::string($duration));
            }
            // Two cells of indent, two cells between name and duration.
            $nameWidth = max(4, min(14, $text - 4 - $durationWidth));
            foreach ($shown as [$name, $duration]) {
                $lines[] = sprintf(
                    '  %s  %s',
                    self::tint(Style::new(), $colour, $profile)->render(
                        Width::padRight(Width::truncate($name, $nameWidth), $nameWidth),
                    ),
                    Style::new()->bold()->render(Width::padLeft($duration, $durationWidth)),
                );
            }
            if (count($rows) > $entries) {
                // The hard cap used to hide projects silently; say how many are missing.
                $lines[] = self::tint(Style::new(), '#7d6e98', $profile)
                    ->render(Width::truncate('  ' . Lang::t('ui.more_suffix', ['count' => count($rows) - $entries]), $text));
            }
        }
        return Style::new()
            ->border(Border::rounded())
            ->padding(0, 1)
            ->width($text)
            ->render(implode("\n", $lines));
    }

    /**
     * Control bytes that survive Ansi::strip (it removes escapes, not C0) would
     * reach the terminal raw from store data the user never typed. The sweep is
     * sugar-core's {@see Sanitize::untrusted()}: escape sequences, C0 controls,
     * DEL and the UTF-8-encoded C1 class (U+0080–U+009F — CSI included, which
     * the old C0-only `/u` class let through to run on the terminal). TAB, LF
     * and CR, which untrusted() deliberately preserves, are stripped on top:
     * the frame rule is one row per name, so a stored newline must not forge a
     * row and a stored CR must not rewind the cursor over the border.
     *
     * The sweep is byte-oriented, so malformed UTF-8 keeps its clean bytes
     * rather than the whole name: the old `/u` pass returned false on any
     * single invalid byte and the `?? ''` fallback blanked the entry in
     * silence — fail-open-by-erasure is not a defense.
     *
     * The door is {@see Sanitize::untrustedForDisplay()} (not bare
     * untrusted()): invisible bidi overrides and zero-width characters are
     * the Trojan Source class — U+202E repaints the rest of the row reversed,
     * and a ZWSP makes two different project names look byte-identical on
     * screen. The display policy marks them as `<U+XXXX>` instead of keeping
     * them invisible, so a painted name can never say something other than
     * what it is; markers are printable ASCII and flow through the width
     * budget like any other character.
     */
    private static function clean(string $text): string
    {
        return str_replace(["\t", "\n", "\r"], '', Sanitize::untrustedForDisplay($text));
    }

    private static function timeline(Dashboard $d): string
    {
        $minutes = array_map(static fn(int $s): int => intdiv($s, 60), $d->stats->timeline());
        $days = $d->stats->days;
        $count = count($minutes);
        if ($count === 0) {
            $days = [];
        }

        // Border + padding on both sides and no row-level indent: every body
        // line is built inside exactly $area cells, so the frame is never wider
        // than the terminal that asked for it. (It used to be the terminal plus
        // two cells at every size, because a 2-cell body indent was added on top
        // of a sparkline already sized to width - 4.)
        $area = $d->width === null
            ? max(1, $count) * 4
            : max(1, $d->width - self::FRAME_CHROME);

        // The heading is budgeted against the frame's own interior, not the bar
        // area: an empty window still has to say what it is.
        $heading = $d->width === null
            ? max(24, $area)
            : max(1, $d->width - self::FRAME_CHROME);

        // A window with more days than cells cannot be drawn as one slot per
        // day; keep the most recent days that fit and say how many were dropped.
        $shown = $count === 0 ? [] : array_slice($minutes, -$area);
        $labels = $count === 0 ? [] : array_slice($days, -$area);
        $hidden = $count - count($shown);
        $slotWidths = self::slots($area, count($shown));
        $suffix = $hidden > 0 ? '  ' . Lang::t('ui.earlier_suffix', ['count' => $hidden]) : '';

        // The heading gives up '(minutes)' before it gives up the count of days
        // that fell off the left: the count is information, the word is not.
        $full = ' ' . Lang::t('ui.activity_minutes_heading') . $suffix;
        $short = ' ' . Lang::t('ui.activity_heading') . $suffix;
        $body = Style::new()->bold()->render(
            self::heading([$full, $short, ' ' . Lang::t('ui.activity_heading')], $heading),
        );

        if ($shown === []) {
            return Style::new()->border(Border::rounded())->padding(0, 1)->render($body . "\n" . Lang::t('ui.no_data'));
        }

        $flat = max($shown) === 0;
        $bars = self::bars($shown, $slotWidths, $flat);
        $weekdays = array_map(
            static fn(\DateTimeImmutable $day): string => $day->format(Lang::t('ui.weekday_format')),
            $labels,
        );
        $rows = [$body, $bars, self::marks($weekdays, $slotWidths, $d->colorProfile)];
        // CT-S08: a milestone inside the window earns its own row under the
        // weekday labels so the label/bar grid never changes shape.
        $marks = self::milestoneMarks($d, $labels, $slotWidths);
        if ($marks !== null) {
            $rows[] = $marks;
        }

        return Style::new()
            ->border(Border::rounded())
            ->padding(0, 1)
            ->render(implode("\n", $rows));
    }

    /**
     * CT-S08: '*' per slot whose day carries at least one milestone, or null
     * when the window has none (the row must not exist for a plain frame).
     * Milestones are bucketed by local calendar date - the same law as the
     * day files and the timeline buckets.
     *
     * @param list<\DateTimeImmutable> $labels
     * @param list<int> $slots
     */
    private static function milestoneMarks(Dashboard $d, array $labels, array $slots): ?string
    {
        $days = [];
        foreach ($d->stats->milestones as $m) {
            $days[date('Y-m-d', $m->time)] = true;
        }
        $line = '';
        $any = false;
        foreach ($slots as $index => $width) {
            $day = $labels[$index] ?? null;
            $hit = $day !== null && isset($days[$day->format('Y-m-d')]);
            $any = $any || $hit;
            $line .= Width::padRight($hit ? '*' : '', $width);
        }
        if (!$any) {
            return null;
        }
        return self::tint(Style::new()->bold(), '#ff5f87', $d->colorProfile)->render($line);
    }

    /**
     * The first candidate that fits the heading budget, falling back to a cut of
     * the plainest one. A terminal too small for any of them still gets a frame.
     *
     * @param list<string> $candidates
     */
    private static function heading(array $candidates, int $budget): string
    {
        foreach ($candidates as $candidate) {
            if (Width::string($candidate) <= $budget) {
                return $candidate;
            }
        }
        return Width::truncate((string) end($candidates), $budget);
    }

    /**
     * Cell budget per day, left-weighted so the remainder lands on the most
     * recent days. The bar row and the label row consume the same widths, which
     * is what keeps each weekday under its own column.
     *
     * @return list<int>
     */
    private static function slots(int $area, int $days): array
    {
        if ($days === 0) {
            return [];
        }
        $base = intdiv($area, $days);
        $remainder = $area % $days;
        $slots = [];
        foreach (range(0, $days - 1) as $index) {
            // Fill from the right: the newest day is the one being read.
            $slots[] = $base + ($index >= $days - $remainder ? 1 : 0);
        }
        return $slots;
    }

    /**
     * One level glyph per day from sugar-charts, stretched to that day's slot.
     * A week with no recorded minutes renders blank cells: Sparkline maps a
     * zero-range series onto its middle glyph, which read as steady activity on
     * an empty account.
     *
     * @param list<int> $minutes
     * @param list<int> $slots
     */
    private static function bars(array $minutes, array $slots, bool $flat): string
    {
        if ($flat) {
            return str_repeat(' ', array_sum($slots));
        }
        $strip = preg_split('//u', Ansi::strip(Sparkline::new($minutes, count($minutes))->view()), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $line = '';
        foreach ($slots as $index => $width) {
            $line .= str_repeat((string) ($strip[$index] ?? ' '), max(1, $width));
        }
        return $line;
    }

    /**
     * Weekday labels, each inside its day's slot, so the label row and the bar
     * row share one grid. The old row joined labels with three spaces and left
     * the sparkline to slide its own window, which matched nothing.
     *
     * When slots get too narrow for three letters the row degrades to single
     * initials rather than 'SatSunMon', which reads as one long word.
     *
     * @param list<string> $weekdays
     * @param list<int> $slots
     */
    private static function marks(array $weekdays, array $slots, ?ColorProfile $profile): string
    {
        $narrow = $slots !== [] && min($slots) < 4;
        $line = '';
        foreach ($slots as $index => $width) {
            $label = (string) ($weekdays[$index] ?? '');
            $line .= Width::padRight(
                Width::truncate($narrow ? substr($label, 0, 1) : $label, $width),
                $width,
            );
        }
        return self::tint(Style::new(), '#7d6e98', $profile)->render($line);
    }
}
