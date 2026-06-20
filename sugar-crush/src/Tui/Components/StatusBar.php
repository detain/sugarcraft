<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tui\Components;

use SugarCraft\Core\Util\Color;
use SugarCraft\Sprinkles\Style;
use SugarCraft\Crush\App\App;
use SugarCraft\Crush\Util\TokenTracker;

/**
 * Renders the status bar at the bottom of the TUI.
 * Shows provider, model, token summary, active skills, and error/status.
 */
final class StatusBar
{
    public static function render(App $a, TokenTracker $tokens): string
    {
        $provider = Style::new()->foreground(Color::hex('#6ee7b7'))->render($a->provider->name());
        $model = Style::new()->foreground(Color::hex('#fde68a'))->render($a->model);
        $tokenSummary = Style::new()->foreground(Color::hex('#7d6e98'))->render($tokens->summary());

        $skills = '';
        if (!empty($a->enabledSkills)) {
            $skillNames = array_map(fn($s) => $s->name, $a->enabledSkills);
            $skills = Style::new()->foreground(Color::hex('#a78bfa'))->render(
                'Skills: ' . implode(', ', $skillNames)
            );
        }

        $status = $a->error
            ? Style::new()->foreground(Color::hex('#ff5f87'))->bold()->render('error: ' . $a->error)
            : ($a->status
                ? Style::new()->foreground(Color::hex('#6ee7b7'))->render($a->status)
                : '');

        $parts = array_filter([$provider, $model, $tokenSummary, $skills, $status]);
        return ' ' . implode('  |  ', $parts) . ' ';
    }
}
