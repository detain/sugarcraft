<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Agents;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Agents\Agent;
use SugarCraft\Code\Agents\AgentPreset;
use SugarCraft\Code\Agents\Effort;
use SugarCraft\Code\Agents\Isolation;
use SugarCraft\Code\Agents\StagePreset;
use SugarCraft\Code\Permissions\PermissionMode;

/** P1-6: a stage's `preset:` resolves through the roster a Task delegation reads. */
final class StagePresetTest extends TestCase
{
    public function testAPresetCarriesEveryFieldAStageNeeds(): void
    {
        $agent = Agent::fromPreset(new AgentPreset(
            name: 'reviewer',
            description: 'reviews',
            tools: ['Read', 'Bash(git *)'],
            disallowedTools: ['Write', 'Edit'],
            model: 'opus',
            permissionMode: PermissionMode::Plan,
            maxTurns: 40,
            skills: ['php-pro'],
            effort: Effort::High,
            isolation: Isolation::Worktree,
            initialPrompt: 'You review code.',
        ), 'anthropic', 'session-model');

        $preset = StagePreset::resolve('reviewer', [$agent]);

        self::assertSame('reviewer', $preset->name);
        self::assertSame('You review code.', $preset->systemPrompt);
        self::assertSame(['Read', 'Bash(git *)'], $preset->tools);
        self::assertSame(['Write', 'Edit'], $preset->disallowedTools);
        self::assertSame('opus', $preset->model);
        self::assertSame(Effort::High, $preset->effort);
        self::assertSame(Isolation::Worktree, $preset->isolation);
        self::assertSame(['php-pro'], $preset->skills);
        self::assertSame(40, $preset->maxTurns);
        self::assertSame(PermissionMode::Plan, $preset->permissionMode);
        self::assertSame($agent, $preset->agent);
    }

    public function testAnInheritingPresetNamesNoModelAndDeclaresNoEffort(): void
    {
        $agent = Agent::fromPreset(new AgentPreset(name: 'scout', description: 'd'), 'p', 'session-model');

        $preset = StagePreset::fromAgent($agent);

        self::assertNull($preset->model, 'model: inherit stays on the session model');
        self::assertNull($preset->effort);
        self::assertNull($preset->isolation);
        self::assertSame([], $preset->tools);
    }

    public function testAnUnknownPresetIsRefusedWithTheKnownNames(): void
    {
        $roster = [
            Agent::fromPreset(new AgentPreset(name: 'coder', description: 'd'), 'p', 'm'),
            Agent::fromPreset(new AgentPreset(name: 'architect', description: 'd'), 'p', 'm'),
        ];

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('unknown agent preset "reviwer" (known: architect, coder)');

        StagePreset::resolve('reviwer', $roster);
    }
}
