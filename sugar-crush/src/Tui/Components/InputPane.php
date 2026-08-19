<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tui\Components;

use SugarCraft\Sprinkles\Border;
use SugarCraft\Sprinkles\Style;
use SugarCraft\Crush\App\App;

final class InputPane
{
    public static function render(App $a, int $cols): string
    {
        // Box spans the full terminal width: 2 border corners + 2 padding
        // columns frame a content area of cols - 4.
        $width = max(1, $cols - 4);

        $theme = $a->theme();
        $placeholder = Style::new()->foreground($theme->shellMuted)
            ->render('Type your message... (Enter to send, Ctrl+G for group)');

        $st = Style::new()
            ->border(Border::normal()->withTitle(' input '))
            ->padding(0, 1)
            ->width($width);

        $st = $a->pane === \SugarCraft\Crush\Tui\Pane::Input
            ? $st->borderForeground($theme->shellPrimary)
            : $st->borderForeground($theme->border);

        return $st->render($placeholder);
    }
}
