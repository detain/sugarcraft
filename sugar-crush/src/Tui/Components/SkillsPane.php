<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tui\Components;

use SugarCraft\Core\Util\Color;
use SugarCraft\Core\Util\Width;
use SugarCraft\Sprinkles\Border;
use SugarCraft\Sprinkles\Style;
use SugarCraft\Crush\App\App;
use SugarCraft\Crush\Skills\Skill;

/**
 * The shell's skills sidebar.
 *
 * Three states, in priority order: the open skill picker, the skills the
 * user has enabled, and — when neither applies — the skills actually
 * DISCOVERED on disk ({@see App::$availableSkills}, which {@see
 * \SugarCraft\Crush\Cli\Bootstrap::app()} fills from the same loader the
 * engine's registry uses). That third state is the crush_feat.md §10.5
 * wiring: a shell whose Skills pane reads "(no skills enabled)" while four
 * imported `.claude/skills` trees sit loaded is the failure mode, so the
 * pane shows what is available and marks what is on.
 *
 * Every row carries the §10.5 provenance badge ({@see
 * \SugarCraft\Crush\Skills\SkillSource::badge()}) so an imported foreign
 * skill is distinguishable from native content.
 */
final class SkillsPane
{
    /** Rows the rounded border's own top and bottom edges cost. */
    private const CHROME_ROWS = 2;

    /** Cells the border (2) plus the horizontal padding (2) cost per row. */
    private const CHROME_COLS = 4;

    public static function render(App $a, int $width, int $rows): string
    {
        // Every branch is capped at the rows the pane actually has: the
        // sidebar is clipped by Tui\Renderer anyway, and an unbounded list
        // costs a styled string per skill per frame for rows nobody sees.
        $budget = max(1, $rows - self::CHROME_ROWS);
        $labelWidth = max(1, $width - self::CHROME_COLS);

        if ($a->skillPickerOptions !== []) {
            $lines = [];
            // Scroll the window so the cursor stays visible on a list longer
            // than the pane: an off-screen cursor is the same "cannot select"
            // dead end as having no cursor at all.
            $offset = max(0, min($a->skillPickerIndex - $budget + 1, count($a->skillPickerOptions) - $budget));
            foreach (array_slice($a->skillPickerOptions, $offset, $budget, true) as $row => $skill) {
                $isCursor = $row === $a->skillPickerIndex;
                $lines[] = self::badgePrefix($skill) . Style::new()
                    ->foreground(Color::hex($isCursor ? '#00ffaa' : '#7d6e98'))
                    ->render(Width::truncate(
                        ($isCursor ? '▸ ' : '  ') . $skill->name . ' — ' . $skill->description,
                        $labelWidth,
                    ));
            }
            $body = implode("\n", $lines);
            $title = ' select a skill ';
        } else {
            $title = ' skills ';
            $enabled = $a->enabledSkills;

            if ($enabled !== []) {
                $lines = [];
                foreach (array_slice($enabled, 0, $budget) as $skill) {
                    // enabledSkills holds a mix of Skill objects (App::update()'s
                    // SelectSkillMsg path) and bare name strings (SkillManager's
                    // legacy path) depending on caller -- render either.
                    $label = $skill instanceof Skill ? $skill->name : (string) $skill;
                    $prefix = $skill instanceof Skill ? self::badgePrefix($skill) : '';
                    $lines[] = $prefix . Style::new()
                        ->foreground(Color::hex('#c5b6dd'))
                        ->render(Width::truncate('• ' . $label, $labelWidth));
                }
                $body = implode("\n", $lines);
            } else {
                $available = array_slice(array_values($a->availableSkills->all()), 0, $budget);

                if ($available === []) {
                    $body = Style::new()->foreground(Color::hex('#7d6e98'))
                        ->render('(no skills enabled)');
                } else {
                    $lines = [];
                    foreach ($available as $skill) {
                        // Dimmer than an enabled row and bulleted with '·'
                        // rather than '•': these are discovered-but-not-on,
                        // and the pane must not read as if they were active.
                        $lines[] = self::badgePrefix($skill) . Style::new()
                            ->foreground(Color::hex('#7d6e98'))
                            ->render(Width::truncate('· ' . $skill->name, $labelWidth));
                    }
                    $body = implode("\n", $lines);
                }
            }
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

    /**
     * The coloured provenance badge that precedes a skill's name, or '' for
     * native content (crush_feat.md §10.5 — a native skill deliberately gets
     * no badge, so the badge means "imported" at a glance).
     */
    private static function badgePrefix(Skill $skill): string
    {
        $badge = $skill->source->badge();

        return $badge === ''
            ? ''
            : Style::new()->foreground($skill->source->color())->render($badge . ' ');
    }
}
