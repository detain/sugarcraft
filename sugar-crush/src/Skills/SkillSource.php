<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Skills;

use SugarCraft\Core\Util\Color;
use SugarCraft\Crush\Theme;

/**
 * Where a Skill/AgentPreset definition was discovered on disk. Surfaced in
 * the palette/menu as a provenance badge so a user importing a foreign
 * .claude/skills or .opencode/skills tree can tell native sugar-crush
 * content apart from an imported one.
 */
enum SkillSource: string
{
    case Native = 'native';
    case Claude = 'claude';
    case Opencode = 'opencode';
    case AgentSkillsSpec = 'spec'; // bare 6-field agentskills.io SKILL.md, no tool-specific extras

    /**
     * Text badge shown before a skill's name in the palette/menu.
     */
    public function badge(): string
    {
        return match ($this) {
            self::Native => '',                 // no badge — the default, no visual noise
            self::Claude => '[claude]',
            self::Opencode => '[opencode]',
            self::AgentSkillsSpec => '[spec]',
        };
    }

    /**
     * Colour the provenance badge is painted in. Each foreign source gets a
     * distinct hue so the badge is scannable at a glance without reading it;
     * Native's value is never used because its badge() is empty.
     *
     * Sourced from the active {@see Theme} rather than from literals. The four
     * literals this replaced (#7d6e98, #ffb86c, #8be9fd, #bd93f9) were three
     * dracula tokens plus a hand-picked grey, so on a light terminal the
     * `[claude]` badge measured 1.9:1 against white and the badge that exists
     * to be scanned at a glance could not be seen at all.
     *
     * DOMAIN of the distinctness promise: the four tokens are pairwise distinct
     * in all five palettes as authored. They can collapse onto a shared
     * fallback when {@see Theme} has to escalate a token for legibility against
     * a hostile terminal background (e.g. a mid-grey, where every palette token
     * fails and all four land on the monochrome floor). That trade is
     * deliberate: an unscannable badge still reads, an invisible one does not.
     */
    public function color(Theme $theme): Color
    {
        return match ($this) {
            self::Native => $theme->shellMuted,
            self::Claude => $theme->shellWarning,
            self::Opencode => $theme->shellInfo,
            self::AgentSkillsSpec => $theme->shellPrimary,
        };
    }
}
