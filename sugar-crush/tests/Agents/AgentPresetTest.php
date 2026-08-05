<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Agents;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Agents\AgentPreset;
use SugarCraft\Crush\Agents\Effort;
use SugarCraft\Crush\Agents\Isolation;
use SugarCraft\Crush\Agents\MemoryScope;
use SugarCraft\Crush\Permissions\PermissionMode;

/**
 * Tests for AgentPreset DTO.
 */
final class AgentPresetTest extends TestCase
{
    // -------------------------------------------------------------------------
    // Full instantiation with all fields
    // -------------------------------------------------------------------------

    public function testInstantiationWithAllFields(): void
    {
        $preset = new AgentPreset(
            name: 'test-preset',
            description: 'A test preset for unit testing',
            tools: ['Read', 'Glob', 'Grep'],
            disallowedTools: ['Bash'],
            model: 'sonnet',
            permissionMode: PermissionMode::Plan,
            maxTurns: 50,
            skills: ['php-best-practices', 'code-review'],
            mcpServers: ['filesystem', 'database'],
            memory: MemoryScope::Project,
            background: true,
            effort: Effort::High,
            isolation: Isolation::Worktree,
            color: '#ff0000',
            initialPrompt: 'You are a helpful coding assistant.',
        );

        $this->assertSame('test-preset', $preset->name);
        $this->assertSame('A test preset for unit testing', $preset->description);
        $this->assertSame(['Read', 'Glob', 'Grep'], $preset->tools);
        $this->assertSame(['Bash'], $preset->disallowedTools);
        $this->assertSame('sonnet', $preset->model);
        $this->assertSame(PermissionMode::Plan, $preset->permissionMode);
        $this->assertSame(50, $preset->maxTurns);
        $this->assertSame(['php-best-practices', 'code-review'], $preset->skills);
        $this->assertSame(['filesystem', 'database'], $preset->mcpServers);
        $this->assertSame(MemoryScope::Project, $preset->memory);
        $this->assertTrue($preset->background);
        $this->assertSame(Effort::High, $preset->effort);
        $this->assertSame(Isolation::Worktree, $preset->isolation);
        $this->assertSame('#ff0000', $preset->color);
        $this->assertSame('You are a helpful coding assistant.', $preset->initialPrompt);
    }

    // -------------------------------------------------------------------------
    // Default values
    // -------------------------------------------------------------------------

    public function testDefaultToolsIsEmptyArray(): void
    {
        $preset = new AgentPreset(
            name: 'default-tools-test',
            description: 'Test default tools',
        );

        $this->assertSame([], $preset->tools);
    }

    public function testDefaultDisallowedToolsIsEmptyArray(): void
    {
        $preset = new AgentPreset(
            name: 'default-disallowed-tools-test',
            description: 'Test default disallowed tools',
        );

        $this->assertSame([], $preset->disallowedTools);
    }

    public function testDefaultModelIsInherit(): void
    {
        $preset = new AgentPreset(
            name: 'default-model-test',
            description: 'Test default model',
        );

        $this->assertSame('inherit', $preset->model);
    }

    public function testDefaultPermissionModeIsDefault(): void
    {
        $preset = new AgentPreset(
            name: 'default-permission-mode-test',
            description: 'Test default permission mode',
        );

        $this->assertSame(PermissionMode::Default, $preset->permissionMode);
    }

    public function testDefaultMaxTurnsIsNull(): void
    {
        $preset = new AgentPreset(
            name: 'default-max-turns-test',
            description: 'Test default max turns',
        );

        $this->assertNull($preset->maxTurns);
    }

    public function testDefaultSkillsIsEmptyArray(): void
    {
        $preset = new AgentPreset(
            name: 'default-skills-test',
            description: 'Test default skills',
        );

        $this->assertSame([], $preset->skills);
    }

    public function testDefaultMcpServersIsEmptyArray(): void
    {
        $preset = new AgentPreset(
            name: 'default-mcp-servers-test',
            description: 'Test default mcp servers',
        );

        $this->assertSame([], $preset->mcpServers);
    }

    public function testDefaultMemoryIsUser(): void
    {
        $preset = new AgentPreset(
            name: 'default-memory-test',
            description: 'Test default memory scope',
        );

        $this->assertSame(MemoryScope::User, $preset->memory);
    }

    public function testDefaultBackgroundIsFalse(): void
    {
        $preset = new AgentPreset(
            name: 'default-background-test',
            description: 'Test default background',
        );

        $this->assertFalse($preset->background);
    }

    public function testDefaultEffortIsMedium(): void
    {
        $preset = new AgentPreset(
            name: 'default-effort-test',
            description: 'Test default effort',
        );

        $this->assertSame(Effort::Medium, $preset->effort);
    }

    public function testDefaultIsolationIsNull(): void
    {
        $preset = new AgentPreset(
            name: 'default-isolation-test',
            description: 'Test default isolation',
        );

        $this->assertNull($preset->isolation);
    }

    public function testDefaultColorIsNull(): void
    {
        $preset = new AgentPreset(
            name: 'default-color-test',
            description: 'Test default color',
        );

        $this->assertNull($preset->color);
    }

    public function testDefaultInitialPromptIsNull(): void
    {
        $preset = new AgentPreset(
            name: 'default-initial-prompt-test',
            description: 'Test default initial prompt',
        );

        $this->assertNull($preset->initialPrompt);
    }

    // -------------------------------------------------------------------------
    // Properties are readonly
    // -------------------------------------------------------------------------

    public function testPropertiesAreReadonly(): void
    {
        $preset = new AgentPreset(
            name: 'readonly-test',
            description: 'Test readonly properties',
        );

        // Ensure the properties exist and are readable
        $this->assertSame('readonly-test', $preset->name);
        $this->assertSame('Test readonly properties', $preset->description);

        // If we tried to assign to a readonly property it would cause a fatal error
        // at runtime. We can verify readonly status by checking the property is
        // initialised via promoted parameter syntax which is only available for
        // readonly properties in PHP 8.3+.
        $reflection = new \ReflectionProperty(AgentPreset::class, 'name');
        $this->assertTrue($reflection->isReadOnly());
    }
}
