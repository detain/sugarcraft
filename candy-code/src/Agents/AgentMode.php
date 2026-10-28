<?php

declare(strict_types=1);

namespace SugarCraft\Code\Agents;

/**
 * Which ends of the agent surface a preset is meant to appear on
 * (C-AGT-4.a), spelled exactly as opencode's `mode:` frontmatter key spells
 * it — `primary`, `subagent`, `all` — because the foreign readers copy that
 * dialect key verbatim and a rename here would turn every imported opencode
 * agent's declared mode into the silent default.
 *
 * This is a PRESENTING filter, not a runtime field: it decides which presets
 * the primary-agent cycle offers and which ones the delegating picker may
 * auto-select; a running agent carries no mode because by the time an Agent
 * exists, the presenting question is already answered. That is why it is the
 * one AgentPreset field {@see \SugarCraft\Code\Tests\Agents\AgentPresetFieldCarryTest}
 * excuses from the carry to Agent.
 *
 * {@see AgentMode::All} is the default because an absent key says NOTHING,
 * and the interpretation of nothing is "offer me everywhere", not "hide me":
 * every preset shipped before this key existed behaves exactly as it did.
 *
 * Mirrors opencode's agent `mode` field.
 */
enum AgentMode: string
{
    /** Presentable as the MAIN chat's agent; not offered to delegation. */
    case Primary = 'primary';

    /** Presentable to the delegating picker; not offered by the main cycle. */
    case Subagent = 'subagent';

    /** Both — the default, and what absence of the key means. */
    case All = 'all';
}
