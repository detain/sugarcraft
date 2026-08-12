<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tui\Components;

use SugarCraft\Core\Util\Color;
use SugarCraft\Sprinkles\Border;
use SugarCraft\Sprinkles\Style;
use SugarCraft\Crush\App\App;
use SugarCraft\Crush\Skills\Skill;

final class SkillsPane
{
    public static function render(App $a, int $width, int $rows): string
    {
        if ($a->skillPickerOptions !== []) {
            $lines = [];
            foreach ($a->skillPickerOptions as $skill) {
                // Provenance badge precedes the name so an imported .claude/
                // or .opencode/ skill is distinguishable from native content.
                $badge = $skill->source->badge();
                $badgePrefix = $badge === ''
                    ? ''
                    : Style::new()->foreground($skill->source->color())->render($badge . ' ');
                $lines[] = $badgePrefix . Style::new()
                    ->foreground(Color::hex('#00ffaa'))
                    ->render('▸ ' . $skill->name . ' — ' . $skill->description);
            }
            $body = implode("\n", $lines);
            $title = ' select a skill ';
        } else {
            $skills = $a->enabledSkills;

            if ($skills === []) {
                $body = Style::new()->foreground(Color::hex('#7d6e98'))
                    ->render('(no skills enabled)');
            } else {
                $lines = [];
                foreach ($skills as $skill) {
                    // enabledSkills holds a mix of Skill objects (App::update()'s
                    // SelectSkillMsg path) and bare name strings (SkillManager's
                    // legacy path) depending on caller -- render either.
                    $label = $skill instanceof Skill ? $skill->name : (string)$skill;
                    $lines[] = Style::new()
                        ->foreground(Color::hex('#c5b6dd'))
                        ->render('• ' . $label);
                }
                $body = implode("\n", $lines);
            }
            $title = ' skills ';
        }

        $st = Style::new()
            ->border(Border::rounded()->withTitle($title))
            ->padding(0, 1)
            ->width($width);

        $st = $a->pane === \SugarCraft\Crush\Tui\Pane::Skills
            ? $st->borderForeground(Color::hex('#00ffaa'))
            : $st->borderForeground(Color::hex('#ff66aa'));

        return $st->render($body);
    }
}
