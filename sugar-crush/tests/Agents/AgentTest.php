<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Agents;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Agents\Agent;

/**
 * Tests for Agent value object - represents a configured agent instance.
 */
final class AgentTest extends TestCase
{
    // -------------------------------------------------------------------------
    // fromArray() - deserialization
    // -------------------------------------------------------------------------

    public function testFromArray(): void
    {
        // Arrange
        $data = [
            'name' => 'test-agent',
            'description' => 'A test agent',
            'prompt' => 'You are a test agent.',
            'model' => 'claude-sonnet-4-6',
            'provider' => 'anthropic',
            'tools' => ['Read', 'Edit', 'Bash'],
            'skills' => ['php-best-practices'],
            'hooks' => ['pre_task'],
            'is_active' => true,
        ];

        // Act
        $agent = Agent::fromArray($data);

        // Assert
        $this->assertSame('test-agent', $agent->name);
        $this->assertSame('A test agent', $agent->description);
        $this->assertSame('You are a test agent.', $agent->prompt);
        $this->assertSame('claude-sonnet-4-6', $agent->model);
        $this->assertSame('anthropic', $agent->provider);
        $this->assertSame(['Read', 'Edit', 'Bash'], $agent->tools);
        $this->assertSame(['php-best-practices'], $agent->skillNames);
        $this->assertSame(['pre_task'], $agent->hooks);
        $this->assertTrue($agent->isActive);
    }

    public function testFromArrayWithDefaults(): void
    {
        // Act
        $agent = Agent::fromArray([]);

        // Assert - defaults
        $this->assertSame('', $agent->name);
        $this->assertSame('', $agent->description);
        $this->assertSame('', $agent->prompt);
        $this->assertSame('claude-sonnet-4-6', $agent->model);
        $this->assertSame('anthropic', $agent->provider);
        $this->assertSame([], $agent->tools);
        $this->assertSame([], $agent->skillNames);
        $this->assertSame([], $agent->hooks);
        $this->assertFalse($agent->isActive);
    }

    // -------------------------------------------------------------------------
    // toArray() - serialization
    // -------------------------------------------------------------------------

    public function testToArray(): void
    {
        // Arrange
        $agent = new Agent(
            name: 'my-agent',
            description: 'My agent description',
            prompt: 'You are my agent.',
            model: 'claude-sonnet-4-6',
            provider: 'anthropic',
            tools: ['Read', 'Edit'],
            skillNames: ['php-best-practices', 'security-audit'],
            hooks: ['pre_task', 'post_task'],
            isActive: true,
        );

        // Act
        $array = $agent->toArray();

        // Assert
        $this->assertIsArray($array);
        $this->assertSame('my-agent', $array['name']);
        $this->assertSame('My agent description', $array['description']);
        $this->assertSame('You are my agent.', $array['prompt']);
        $this->assertSame('claude-sonnet-4-6', $array['model']);
        $this->assertSame('anthropic', $array['provider']);
        $this->assertSame(['Read', 'Edit'], $array['tools']);
        $this->assertSame(['php-best-practices', 'security-audit'], $array['skills']);
        $this->assertSame(['pre_task', 'post_task'], $array['hooks']);
        $this->assertTrue($array['is_active']);
    }

    // -------------------------------------------------------------------------
    // withName() - immutable builder
    // -------------------------------------------------------------------------

    public function testWithName(): void
    {
        // Arrange
        $original = new Agent(
            name: 'original-name',
            description: 'Original description',
            prompt: 'Original prompt',
            model: 'claude-sonnet-4-6',
            provider: 'anthropic',
            tools: ['Read'],
            skillNames: ['skill-a'],
            hooks: ['hook-a'],
            isActive: false,
        );

        // Act
        $renamed = $original->withName('new-name');

        // Assert
        $this->assertSame('new-name', $renamed->name);
        $this->assertNotSame($original, $renamed); // new instance
    }

    public function testWithNamePreservesOtherFields(): void
    {
        // Arrange
        $original = new Agent(
            name: 'original-name',
            description: 'Original description',
            prompt: 'Original prompt',
            model: 'claude-sonnet-4-6',
            provider: 'anthropic',
            tools: ['Read', 'Edit', 'Bash'],
            skillNames: ['php-best-practices', 'security-audit'],
            hooks: ['pre_task'],
            isActive: true,
        );

        // Act
        $renamed = $original->withName('renamed-agent');

        // Assert - name changed
        $this->assertSame('renamed-agent', $renamed->name);
        // Assert - other fields preserved
        $this->assertSame('Original description', $renamed->description);
        $this->assertSame('Original prompt', $renamed->prompt);
        $this->assertSame('claude-sonnet-4-6', $renamed->model);
        $this->assertSame('anthropic', $renamed->provider);
        $this->assertSame(['Read', 'Edit', 'Bash'], $renamed->tools);
        $this->assertSame(['php-best-practices', 'security-audit'], $renamed->skillNames);
        $this->assertSame(['pre_task'], $renamed->hooks);
        $this->assertTrue($renamed->isActive);
        // Assert - original unchanged
        $this->assertSame('original-name', $original->name);
    }

    // -------------------------------------------------------------------------
    // withActive() - immutable builder
    // -------------------------------------------------------------------------

    public function testWithActive(): void
    {
        // Arrange
        $original = new Agent(
            name: 'my-agent',
            description: 'My agent',
            prompt: 'You are my agent.',
            model: 'claude-sonnet-4-6',
            provider: 'anthropic',
            tools: [],
            skillNames: [],
            hooks: [],
            isActive: false,
        );

        // Act
        $activated = $original->withActive(true);
        $deactivated = $activated->withActive(false);

        // Assert
        $this->assertTrue($activated->isActive);
        $this->assertFalse($deactivated->isActive);
        $this->assertNotSame($original, $activated); // new instance
        $this->assertNotSame($activated, $deactivated); // new instance
    }

    public function testWithActivePreservesOtherFields(): void
    {
        // Arrange
        $original = new Agent(
            name: 'my-agent',
            description: 'My agent description',
            prompt: 'You are my agent.',
            model: 'claude-sonnet-4-6',
            provider: 'anthropic',
            tools: ['Read', 'Edit', 'Bash'],
            skillNames: ['php-best-practices'],
            hooks: ['pre_task', 'post_task'],
            isActive: false,
        );

        // Act
        $activated = $original->withActive(true);

        // Assert - isActive changed
        $this->assertTrue($activated->isActive);
        // Assert - other fields preserved
        $this->assertSame('my-agent', $activated->name);
        $this->assertSame('My agent description', $activated->description);
        $this->assertSame('You are my agent.', $activated->prompt);
        $this->assertSame('claude-sonnet-4-6', $activated->model);
        $this->assertSame('anthropic', $activated->provider);
        $this->assertSame(['Read', 'Edit', 'Bash'], $activated->tools);
        $this->assertSame(['php-best-practices'], $activated->skillNames);
        $this->assertSame(['pre_task', 'post_task'], $activated->hooks);
        // Assert - original unchanged
        $this->assertFalse($original->isActive);
    }

    // -------------------------------------------------------------------------
    // systemPrompt() - returns the prompt
    // -------------------------------------------------------------------------

    public function testSystemPrompt(): void
    {
        // Arrange
        $agent = new Agent(
            name: 'test-agent',
            description: 'Test agent',
            prompt: 'You are a specialized test agent.',
            model: 'claude-sonnet-4-6',
            provider: 'anthropic',
            tools: [],
            skillNames: [],
            hooks: [],
            isActive: true,
        );

        // Act
        $systemPrompt = $agent->systemPrompt();

        // Assert
        $this->assertSame('You are a specialized test agent.', $systemPrompt);
    }

    public function testSystemPromptEmpty(): void
    {
        // Arrange
        $agent = Agent::fromArray(['prompt' => '']);

        // Act
        $systemPrompt = $agent->systemPrompt();

        // Assert
        $this->assertSame('', $systemPrompt);
    }
}
