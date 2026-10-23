<?php

declare(strict_types=1);

namespace SugarCraft\CodeTime;

use SugarCraft\Charts\Sparkline\Sparkline;
use SugarCraft\Core\Util\Ansi;
use SugarCraft\Core\Util\Color;
use SugarCraft\Core\Util\Width;
use SugarCraft\Style\Border;
use SugarCraft\Style\Layout;
use SugarCraft\Style\Position;
use SugarCraft\Style\Style;

/**
 * Pure view function. Three sections stacked vertically: header,
 * project + language ranking side-by-side, and the daily-activity
 * sparkline timeline. Under ~56 columns the two ranking panes stack
 * vertically instead of side-by-side.
 *
 * Honours NO_COLOR (any value that is not empty/`0`/`false`): the frame
 * keeps its bold/structure but every hex tint is dropped — same truthy
 * rule sugar-colorprofile applies to the variable, so a host that sets
 * it gets a plain-text dashboard rather than a second theme knob (the
 * removed Theme class never reached this code path; there is nothing to
 * re-skin here).
 */
final class Renderer
{
    public static function render(Dashboard $d): string
    {
        $stats = $d->stats;

        $header = self::header($d);
        $projects = self::ranking('Top projects',  $stats->perProject(),  '#fde68a', 26);
        $languages = self::ranking('By language',  $stats->perLanguage(), '#7dd3fc', 26);

        // Stack panes vertically when width is known and below side-by-side threshold (~56)
        $top = ($d->width !== null && $d->width < 56)
            ? $projects . "\n" . $languages
            : Layout::joinHorizontal(Position::TOP, $projects, '  ', $languages);

        $timeline = self::timeline($d);

        $help = self::tint(Style::new(), '#7d6e98')
            ->render('  ←  prev day  ·  →  next day  ·  r  reload  ·  q  quit');

        return $header . "\n" . $top . "\n" . $timeline . "\n" . $help . "\n";
    }

    /**
     * Apply a hex tint unless the host asked for no colour.
     */
    private static function tint(Style $style, string $hex): Style
    {
        return self::colorSuppressed() ? $style : $style->foreground(Color::hex($hex));
    }

    /**
     * NO_COLOR truthiness — mirrors the rule sugar-colorprofile uses for the
     * variable (set-and-not-one-of 0/false/empty disables colour).
     */
    private static function colorSuppressed(): bool
    {
        $value = getenv('NO_COLOR');
        if ($value === false) {
            return false;
        }
        return $value !== '' && $value !== '0' && strtolower($value) !== 'false';
    }

    private static function header(Dashboard $d): string
    {
        $end = $d->endDay->format('M j');
        $start = $d->endDay->modify('-' . ($d->days - 1) . ' days')->format('M j');
        $total = Stats::formatHours($d->stats->totalSeconds());
        $title = self::tint(Style::new()->bold(), '#ff5f87')
            ->render(' CandyCodetime ');
        $range = self::tint(Style::new(), '#a78bfa')
            ->render("[{$start} – {$end}]");
        $tot = self::tint(Style::new()->bold(), '#6ee7b7')
            ->render($total);
        return $title . '  ' . $range . '   total: ' . $tot;
    }

    /** @param array<string,int> $rows */
    private static function ranking(string $title, array $rows, string $colour, int $width): string
    {
        $lines = [Style::new()->bold()->render($title)];
        if ($rows === []) {
            $lines[] = self::tint(Style::new(), '#7d6e98')->render('  (no activity)');
        } else {
            $i = 0;
            foreach ($rows as $name => $secs) {
                if ($i++ >= 6) break;
                $duration = Stats::formatHours($secs);
                $name = Ansi::strip($name);
                $name = preg_replace('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/u', '', $name) ?? '';
                $name = Width::truncate($name, 14);
                $displayWidth = Width::string($name);
                $lines[] = sprintf(
                    '  %s%s  %s',
                    self::tint(Style::new(), $colour)->render(Width::padRight($name, 14)),
                    str_repeat(' ', max(0, 16 - $displayWidth)),
                    Style::new()->bold()->render($duration),
                );
            }
            if (count($rows) > 6) {
                // The hard cap used to hide projects silently; say how many are missing.
                $lines[] = self::tint(Style::new(), '#7d6e98')
                    ->render('  +' . (count($rows) - 6) . ' more');
            }
        }
        return Style::new()
            ->border(Border::rounded())
            ->padding(0, 1)
            ->width($width)
            ->render(implode("\n", $lines));
    }

    private static function timeline(Dashboard $d): string
    {
        $minutes = array_map(static fn(int $s): int => intdiv($s, 60), $d->stats->timeline());
        $computedWidth = max(20, count($minutes) * 4);
        // A terminal narrower than the frame's own borders used to drive
        // width-4 negative and Sparkline::new() threw — clamp to at least one
        // cell. On wide terminals the sparkline now stretches to fill the
        // frame instead of leaving dead space after the 4-cells-per-day floor.
        $width = ($d->width !== null) ? max(1, $d->width - 4) : $computedWidth;
        $sparkline = $minutes === []
            ? '(no data)'
            : Sparkline::new($minutes, $width)->view();
        $labels = [];
        foreach ($d->stats->days as $day) {
            $labels[] = $day->format('D');
        }
        $body = Style::new()->bold()->render(' Daily activity (minutes)') . "\n  "
            . $sparkline . "\n  "
            . implode('   ', array_map(
                static fn($l) => self::tint(Style::new(), '#7d6e98')->render($l),
                $labels,
            ));
        return Style::new()
            ->border(Border::rounded())
            ->padding(0, 1)
            ->render($body);
    }
}
