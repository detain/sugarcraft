<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tui\Components;

use SugarCraft\Core\Util\Color;
use SugarCraft\Sprinkles\Border;
use SugarCraft\Sprinkles\Style;
use SugarCraft\Crush\App\App;

final class FilesPane
{
    public static function render(App $a, int $width, int $rows): string
    {
        $files = $a->contextFiles;

        if ($files === []) {
            $body = Style::new()->foreground(Color::hex('#7d6e98'))
                ->render('(no files attached)');
        } else {
            $lines = [];
            foreach ($files as $file) {
                $lines[] = Style::new()
                    ->foreground(Color::hex('#c5b6dd'))
                    ->render('📄 ' . basename($file));
            }
            $body = implode("\n", $lines);
        }

        $st = Style::new()
            ->border(Border::rounded()->withTitle(' files '))
            ->padding(0, 1)
            ->width($width);

        $st = $a->pane === \SugarCraft\Crush\Tui\Pane::Files
            ? $st->borderForeground(Color::hex('#00ffaa'))
            : $st->borderForeground(Color::hex('#ff66aa'));

        return $st->render($body);
    }
}
