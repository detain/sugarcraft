<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Agents;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Agents\Agent;
use SugarCraft\Crush\Context\EnvironmentBlock;

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
    // systemPrompt() - prompt plus the session environment block
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
        $this->assertStringStartsWith("You are a specialized test agent.\n\n", $systemPrompt);
    }

    public function testSystemPromptEmpty(): void
    {
        // Arrange
        $agent = Agent::fromArray(['prompt' => '']);

        // Act
        $systemPrompt = $agent->systemPrompt();

        // Assert - no leading blank line is glued onto a promptless agent
        $this->assertStringStartsWith('<env>', $systemPrompt);
    }

    /**
     * The gap this closes: subagent prompts were a bare passthrough of
     * $this->prompt, so a subagent had no idea which directory, branch,
     * platform or model it was running under. Fails against the old
     * systemPrompt().
     */
    public function testSystemPromptAppendsCapturedEnvironmentBlock(): void
    {
        $agent = Agent::fromArray(['prompt' => 'Do the thing.', 'model' => 'minimax-m2.7']);

        $systemPrompt = $agent->systemPrompt();

        $this->assertStringContainsString('<env>', $systemPrompt);
        $this->assertStringContainsString('</env>', $systemPrompt);
        $this->assertStringContainsString('Working directory: ' . getcwd(), $systemPrompt);
        $this->assertStringContainsString('Model: minimax-m2.7', $systemPrompt);
        $this->assertStringContainsString('Current date: ' . date('Y-m-d'), $systemPrompt);
    }

    public function testSystemPromptPrefersTheCallerSuppliedEnvironmentBlock(): void
    {
        $agent = Agent::fromArray(['prompt' => 'Do the thing.', 'model' => 'agent-model']);

        $systemPrompt = $agent->systemPrompt(
            new EnvironmentBlock('/session/cwd', 'session-model', new DateTimeImmutable('2026-03-04 05:06:07')),
        );

        $this->assertStringContainsString('Working directory: /session/cwd', $systemPrompt);
        $this->assertStringContainsString('Model: session-model', $systemPrompt);
        $this->assertStringContainsString('Current date: 2026-03-04', $systemPrompt);
    }

    public function testSystemPromptUsesTheAttachedEnvironmentBlock(): void
    {
        $agent = Agent::fromArray(['prompt' => 'Do the thing.'])
            ->withEnvironment(new EnvironmentBlock('/attached/cwd', 'attached-model'));

        $systemPrompt = $agent->systemPrompt();

        $this->assertStringContainsString('Working directory: /attached/cwd', $systemPrompt);
        $this->assertStringContainsString('Model: attached-model', $systemPrompt);
    }

    // -------------------------------------------------------------------------
    // withEnvironment() - immutable builder
    // -------------------------------------------------------------------------

    public function testWithEnvironmentReturnsNewInstanceAndPreservesOtherFields(): void
    {
        $original = new Agent(
            name: 'my-agent',
            description: 'My agent description',
            prompt: 'You are my agent.',
            model: 'claude-sonnet-4-6',
            provider: 'anthropic',
            tools: ['Read'],
            skillNames: ['php-best-practices'],
            hooks: ['pre_task'],
            isActive: true,
        );

        $block = new EnvironmentBlock('/some/cwd', 'some-model');
        $attached = $original->withEnvironment($block);

        $this->assertNotSame($original, $attached);
        $this->assertNull($original->environment);
        $this->assertSame($block, $attached->environment);
        $this->assertSame('my-agent', $attached->name);
        $this->assertSame('My agent description', $attached->description);
        $this->assertSame('You are my agent.', $attached->prompt);
        $this->assertSame('claude-sonnet-4-6', $attached->model);
        $this->assertSame('anthropic', $attached->provider);
        $this->assertSame(['Read'], $attached->tools);
        $this->assertSame(['php-best-practices'], $attached->skillNames);
        $this->assertSame(['pre_task'], $attached->hooks);
        $this->assertTrue($attached->isActive);
    }

    public function testWithNameAndWithActiveCarryTheEnvironmentBlockForward(): void
    {
        $block = new EnvironmentBlock('/some/cwd', 'some-model');
        $agent = Agent::fromArray(['name' => 'a'])->withEnvironment($block);

        $this->assertSame($block, $agent->withName('b')->environment);
        $this->assertSame($block, $agent->withActive(true)->environment);
    }

    public function testToArrayOmitsTheEnvironmentSnapshot(): void
    {
        // A snapshot written into a persisted agent definition would outlive
        // the session that captured it.
        $agent = Agent::fromArray(['name' => 'a'])
            ->withEnvironment(new EnvironmentBlock('/some/cwd', 'some-model'));

        $this->assertArrayNotHasKey('environment', $agent->toArray());
    }
}
