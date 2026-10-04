<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Support;

use SugarCraft\Crush\Agents\Agent;

/**
 * The one roster-entry factory the Task-delegation tests share, so the
 * sub-agent fixtures cannot drift apart copy by copy (DuplicatedTestHelperDriftTest).
 * Its prompt is always "You are {name}." — the tests assert on that exact text.
 */
final class RosterAgent
{
    /**
     * @param list<string> $tools
     * @param list<string> $skills
     */
    public static function named(string $name, array $tools = [], array $skills = [], ?int $maxTurns = null): Agent
    {
        return new Agent(
            name: $name,
            description: "test agent {$name}",
            prompt: "You are {$name}.",
            model: 'test-model',
            provider: 'test',
            tools: $tools,
            skillNames: $skills,
            hooks: [],
            isActive: true,
            maxTurns: $maxTurns,
            // A roster entry that names no model of its own: the delegated
            // run stays on the calling engine's (4.1-1).
            inheritsModel: true,
        );
    }
}
