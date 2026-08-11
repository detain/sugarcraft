<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Skills;

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
}
