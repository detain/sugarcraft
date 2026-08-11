<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Skills;

use SugarCraft\Core\Util\Color;

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
     */
    public function color(): Color
    {
        return match ($this) {
            self::Native => Color::hex('#7d6e98'),
            self::Claude => Color::hex('#ffb86c'),
            self::Opencode => Color::hex('#8be9fd'),
            self::AgentSkillsSpec => Color::hex('#bd93f9'),
        };
    }
}
