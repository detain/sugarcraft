declare(strict_types=1);

namespace SugarCraft\Crush\Tui\Components;

use SugarCraft\Core\Util\Color;
use SugarCraft\Sprinkles\Border;
use SugarCraft\Sprinkles\Style;
use SugarCraft\Crush\App\App;

final class SkillsPane
{
    public static function render(App $a, int $width, int $rows): string
    {
        $skills = $a->enabledSkills;

        if ($skills === []) {
            $body = Style::new()->foreground(Color::hex('#7d6e98'))
                ->render('(no skills enabled)');
        } else {
            $lines = [];
            foreach ($skills as $skill) {
                $lines[] = Style::new()
                    ->foreground(Color::hex('#c5b6dd'))
                    ->render('• ' . $skill);
            }
            $body = implode("\n", $lines);
        }

        $st = Style::new()
            ->border(Border::rounded()->withTitle(' skills '))
            ->padding(0, 1)
            ->width($width);

        $st = $a->pane === \SugarCraft\Crush\Tui\Pane::Skills
            ? $st->borderForeground(Color::hex('#00ffaa'))
            : $st->borderForeground(Color::hex('#ff66aa'));

        return $st->render($body);
    }
}
