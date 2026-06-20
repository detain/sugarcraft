<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tui\Components;

use SugarCraft\Core\Util\Color;
use SugarCraft\Sprinkles\Border;
use SugarCraft\Sprinkles\Style;
use SugarCraft\Crush\App\App;

final class InputPane
{
    public static function render(App $a, int $cols): string
    {
        $width = max(20, $cols - 6);

        $placeholder = Style::new()->foreground(Color::hex('#7d6e98'))
            ->render('Type your message... (Enter to send, Ctrl+G for group)');

        $st = Style::new()
            ->border(Border::rounded()->withTitle(' input '))
            ->padding(0, 1)
            ->width($width);

        $st = $a->pane === \SugarCraft\Crush\Tui\Pane::Input
            ? $st->borderForeground(Color::hex('#00ffaa'))
            : $st->borderForeground(Color::hex('#ff66aa'));

        return $st->render($placeholder);
    }
}
