<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Agents;

use SugarCraft\Crush\Permissions\PermissionMode;
use SugarCraft\Crush\Skills\SkillSource;

/**
 * Represents a named, configurable agent preset used for delegation and
 * session spawning. Contains all tuning parameters for an agent's behaviour.
 *
 * `$source` records which tool's convention the definition was read from, so a
 * preset imported by {@see ForeignAgentPresetRegistry} from `.claude/agents/`
 * or `.opencode/agents/` can be badged as foreign; presets written for
 * sugar-crush itself keep the SkillSource::Native default.
 */
final class AgentPreset
{
    public function __construct(
        public readonly string $name,
        public readonly string $description,
        public readonly array $tools = [],
        public readonly array $disallowedTools = [],
        public readonly string $model = 'inherit',
        public readonly PermissionMode $permissionMode = PermissionMode::Default,
        public readonly ?int $maxTurns = null,
        public readonly array $skills = [],
        public readonly array $mcpServers = [],
        public readonly MemoryScope $memory = MemoryScope::User,
        public readonly bool $background = false,
        public readonly Effort $effort = Effort::Medium,
        public readonly ?Isolation $isolation = null,
        public readonly ?string $color = null,
        public readonly ?string $initialPrompt = null,
        public readonly SkillSource $source = SkillSource::Native,
    ) {}
}
