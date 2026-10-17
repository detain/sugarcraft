<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tui\Components;

use SugarCraft\Code\Lang;
use SugarCraft\Style\Border;
use SugarCraft\Style\Style;
use SugarCraft\Code\App\App;

final class InputPane
{
    public static function render(App $a, int $cols): string
    {
        // Box spans the full terminal width: 2 border corners + 2 padding
        // columns frame a content area of cols - 4.
        $width = max(1, $cols - 4);

        $theme = $a->theme();
        $placeholder = Style::new()->foreground($theme->shellMuted)
            ->render(Lang::t('tui.input.placeholder'));

        $st = Style::new()
            ->border(Border::normal()->withTitle(' ' . Lang::t('tui.pane.title.input') . ' '))
            ->padding(0, 1)
            ->width($width);

        $st = $a->pane === \SugarCraft\Code\Tui\Pane::Input
            ? $st->borderForeground($theme->shellPrimary)
            : $st->borderForeground($theme->border);

        return $st->render($placeholder);
    }
}
