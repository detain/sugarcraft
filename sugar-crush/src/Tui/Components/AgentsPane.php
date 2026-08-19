<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tui\Components;

use SugarCraft\Sprinkles\Border;
use SugarCraft\Sprinkles\Style;
use SugarCraft\Crush\App\App;

final class AgentsPane
{
    public static function render(App $a, int $width, int $rows): string
    {
        $theme = $a->theme();
        $body = Style::new()->foreground($theme->shellMuted)
            ->render('(no active agents)');

        $st = Style::new()
            ->border(Border::rounded()->withTitle(' agents '))
            ->padding(0, 1)
            ->width($width);

        $st = $a->pane === \SugarCraft\Crush\Tui\Pane::Agents
            ? $st->borderForeground($theme->shellPrimary)
            : $st->borderForeground($theme->border);

        return $st->render($body);
    }
}
