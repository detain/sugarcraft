<?php

declare(strict_types=1);

namespace SugarCraft\Code\Agents;

use SugarCraft\Code\Permissions\PermissionMode;
use SugarCraft\Code\Skills\SkillSource;

/**
 * Represents a named, configurable agent preset used for delegation and
 * session spawning. Contains all tuning parameters for an agent's behaviour.
 *
 * `$source` records which tool's convention the definition was read from, so a
 * preset imported by {@see ForeignAgentPresetRegistry} from `.claude/agents/`
 * or `.opencode/agents/` can be badged as foreign; presets written for
 * candy-code itself keep the SkillSource::Native default.
 *
 * `$effort` is null when the file declares none (roadmap 4.1-1): a delegated
 * run sends the declared effort with its requests, and an implied `medium`
 * would override the provider's own per-model default for every preset that
 * never asked for one.
 *
 * `$memory` is null when the file declares none, and null means no agent
 * memory at all (C-AGT-3.b): an implied `user` tier would give every preset
 * in the tree a private store, an index layer in its child prompts, and a
 * `Memory` grant none of them asked for. The tier a file does declare selects
 * where that agent's own notes live — see {@see \SugarCraft\Code\Memory\AgentMemory}.
 *
 * `$mode` (C-AGT-4.a) decides which ends of the agent surface PRESENT the
 * preset — the main-chat cycle, the delegating picker, or both. It is the one
 * field that describes selection rather than running, so it stops at the
 * preset: by the time an {@see Agent} exists the presenting question is
 * answered. Absence means {@see AgentMode::All}.
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
        public readonly ?MemoryScope $memory = null,
        public readonly bool $background = false,
        public readonly ?Effort $effort = null,
        public readonly ?Isolation $isolation = null,
        public readonly ?string $color = null,
        public readonly ?string $initialPrompt = null,
        /**
         * Which ends of the agent surface present this preset (C-AGT-4.a).
         * The default {@see AgentMode::All} keeps every pre-existing preset
         * behaving exactly as it did; the key is read by the picker, never by
         * a running agent, which is why it is the one preset field that does
         * not travel into {@see Agent}.
         */
        public readonly AgentMode $mode = AgentMode::All,
        public readonly SkillSource $source = SkillSource::Native,
        /**
         * The file this preset was read from (ISS-031), so `/agent <name>` can
         * name not just the tier but the exact path — the fact a shadowing
         * notice is worthless without. null for presets built in code and for
         * frames that predate the field; it is a DISPLAY seam, deliberately
         * absent from any persisted frame, the same omission contract
         * {@see Agent::$environmentRoot} keeps.
         */
        public readonly ?string $sourcePath = null,
    ) {}
}
