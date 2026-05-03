<?php

declare(strict_types=1);

namespace CandyCore\Tick;

use CandyCore\Charts\Sparkline\Sparkline;
use CandyCore\Core\Util\Color;
use CandyCore\Sprinkles\Border;
use CandyCore\Sprinkles\Layout;
use CandyCore\Sprinkles\Position;
use CandyCore\Sprinkles\Style;

/**
 * Pure view function. Three sections stacked vertically: header,
 * project + language ranking side-by-side, and the daily-activity
 * sparkline timeline.
 */
final class Renderer
{
    public static function render(Dashboard $d): string
    {
        $stats = $d->stats;

        $header = self::header($d);
        $projects = self::ranking('Top projects',  $stats->perProject(),  '#fde68a', 26);
        $languages = self::ranking('By language',  $stats->perLanguage(), '#7dd3fc', 26);
        $top = Layout::joinHorizontal(Position::TOP, $projects, '  ', $languages);

        $timeline = self::timeline($d);

        $help = Style::new()->foreground(Color::hex('#7d6e98'))
            ->render('  ←  prev day  ·  →  next day  ·  r  reload  ·  q  quit');

        return $header . "\n" . $top . "\n" . $timeline . "\n" . $help . "\n";
    }

    private static function header(Dashboard $d): string
    {
        $end = $d->endDay->format('M j');
        $start = $d->endDay->modify('-' . ($d->days - 1) . ' days')->format('M j');
        $total = Stats::formatHours($d->stats->totalSeconds());
        $title = Style::new()->bold()->foreground(Color::hex('#ff5f87'))
            ->render(' SugarTick ');
        $range = Style::new()->foreground(Color::hex('#a78bfa'))
            ->render("[{$start} – {$end}]");
        $tot = Style::new()->bold()->foreground(Color::hex('#6ee7b7'))
            ->render($total);
        return $title . '  ' . $range . '   total: ' . $tot;
    }

    /** @param array<string,int> $rows */
    private static function ranking(string $title, array $rows, string $colour, int $width): string
    {
        $lines = [Style::new()->bold()->render($title)];
        if ($rows === []) {
            $lines[] = Style::new()->foreground(Color::hex('#7d6e98'))->render('  (no activity)');
        } else {
            $i = 0;
            foreach ($rows as $name => $secs) {
                if ($i++ >= 6) break;
                $duration = Stats::formatHours($secs);
                $name = mb_strimwidth($name, 0, 14, '…');
                $lines[] = sprintf(
                    '  %s%s  %s',
                    Style::new()->foreground(Color::hex($colour))->render(str_pad($name, 14)),
                    str_repeat(' ', max(0, 16 - mb_strlen($name))),
                    Style::new()->bold()->render($duration),
                );
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
        $width   = max(20, count($minutes) * 4);
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
                static fn($l) => Style::new()->foreground(Color::hex('#7d6e98'))->render($l),
                $labels,
            ));
        return Style::new()
            ->border(Border::rounded())
            ->padding(0, 1)
            ->render($body);
    }
}
