<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tui\Components;

use SugarCraft\Core\Util\Color;
use SugarCraft\Sprinkles\Bar\Segment;
use SugarCraft\Sprinkles\Bar\StatusBar as BarStatusBar;
use SugarCraft\Sprinkles\Style;
use SugarCraft\Crush\App\App;
use SugarCraft\Crush\Util\TokenTracker;

/**
 * Renders the status bar at the bottom of the TUI.
 * Shows provider, model, token summary, active skills, and error/status.
 *
 * Thin themed wrapper over {@see BarStatusBar} (candy-sprinkles): the shared
 * primitive owns segment joining, separators and edge padding, while this
 * class only supplies the crush theme and the segment set. Renders in the
 * primitive's natural (width-less) mode — a leading/trailing space via
 * {@see BarStatusBar::caps()} and a `'  |  '` {@see BarStatusBar::separator()}.
 */
final class StatusBar
{
    public static function render(App $a, TokenTracker $tokens): string
    {
        $segments = [
            Segment::of($a->provider->name(), Style::new()->foreground(Color::hex('#6ee7b7'))),
            Segment::of($a->model, Style::new()->foreground(Color::hex('#fde68a'))),
            Segment::of($tokens->summary(), Style::new()->foreground(Color::hex('#7d6e98'))),
        ];

        if (!empty($a->enabledSkills)) {
            $skillNames = array_map(fn ($s) => $s->name, $a->enabledSkills);
            $segments[] = Segment::of(
                'Skills: ' . implode(', ', $skillNames),
                Style::new()->foreground(Color::hex('#a78bfa')),
            );
        }

        if ($a->error) {
            $segments[] = Segment::of(
                'error: ' . $a->error,
                Style::new()->foreground(Color::hex('#ff5f87'))->bold(),
            );
        } elseif ($a->status) {
            $segments[] = Segment::of(
                $a->status,
                Style::new()->foreground(Color::hex('#6ee7b7')),
            );
        }

        return BarStatusBar::new()
            ->separator('  |  ')
            ->caps(' ', ' ')
            ->left(...$segments)
            ->render();
    }
}
