declare(strict_types=1);

namespace SugarCraft\Crush\Tui\Components;

use SugarCraft\Core\Util\Color;
use SugarCraft\Sprinkles\Border;
use SugarCraft\Sprinkles\Style;
use SugarCraft\Crush\App\App;

final class ToolsPane
{
    public static function render(App $a, int $width, int $rows): string
    {
        $body = Style::new()->foreground(Color::hex('#7d6e98'))
            ->render('(tool history empty)');

        $st = Style::new()
            ->border(Border::rounded()->withTitle(' tools '))
            ->padding(0, 1)
            ->width($width);

        $st = $a->pane === \SugarCraft\Crush\Tui\Pane::Tools
            ? $st->borderForeground(Color::hex('#00ffaa'))
            : $st->borderForeground(Color::hex('#ff66aa'));

        return $st->render($body);
    }
}
