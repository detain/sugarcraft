<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Agents;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Agents\AgentDefinition;

/**
 * Tests for AgentDefinition - factory for pre-configured agent types.
 */
final class AgentDefinitionTest extends TestCase
{
    // -------------------------------------------------------------------------
    // Factory methods
    // -------------------------------------------------------------------------

    public function testCoder(): void
    {
        // Act
        $coder = AgentDefinition::coder();

        // Assert
        $this->assertSame(AgentDefinition::TYPE_CODER, $coder->type);
        $this->assertSame('coder', $coder->name);
        $this->assertSame('General coding assistant', $coder->description);
        $this->assertSame('You are a coding assistant. Help write, modify, and understand code.', $coder->prompt);
        $this->assertSame(['Read', 'Edit', 'Bash'], $coder->defaultTools);
        $this->assertSame([], $coder->defaultSkills);
    }

    public function testCoderWithCustomName(): void
    {
        // Act
        $coder = AgentDefinition::coder('my-coder');

        // Assert
        $this->assertSame('my-coder', $coder->name);
        $this->assertSame(AgentDefinition::TYPE_CODER, $coder->type);
    }

    public function testReviewer(): void
    {
        // Act
        $reviewer = AgentDefinition::reviewer();

        // Assert
        $this->assertSame(AgentDefinition::TYPE_REVIEWER, $reviewer->type);
        $this->assertSame('reviewer', $reviewer->name);
        $this->assertSame('Code review specialist', $reviewer->description);
        $this->assertSame('You are a code review specialist. Review code for bugs, security issues, and best practices.', $reviewer->prompt);
        $this->assertSame(['Read', 'Grep', 'Bash(git:*)'], $reviewer->defaultTools);
        $this->assertSame(['php-best-practices', 'security-audit'], $reviewer->defaultSkills);
    }

    public function testReviewerHasSecurityAuditSkill(): void
    {
        // Act
        $reviewer = AgentDefinition::reviewer();

        // Assert
        $this->assertContains('security-audit', $reviewer->defaultSkills);
    }

    public function testDebugger(): void
    {
        // Act
        $debugger = AgentDefinition::debugger();

        // Assert
        $this->assertSame(AgentDefinition::TYPE_DEBUGGER, $debugger->type);
        $this->assertSame('debugger', $debugger->name);
        $this->assertSame('Bug investigation and fixing', $debugger->description);
        $this->assertSame('You are a debugging specialist. Investigate bugs, trace issues, and propose fixes.', $debugger->prompt);
        $this->assertSame(['Read', 'Grep', 'Bash'], $debugger->defaultTools);
        $this->assertSame([], $debugger->defaultSkills);
    }

    public function testArchitect(): void
    {
        // Act
        $architect = AgentDefinition::architect();

        // Assert
        $this->assertSame(AgentDefinition::TYPE_ARCHITECT, $architect->type);
        $this->assertSame('architect', $architect->name);
        $this->assertSame('System design and architecture', $architect->description);
        $this->assertSame('You are a software architect. Design systems, propose patterns, and evaluate trade-offs.', $architect->prompt);
        $this->assertSame(['Read', 'Grep', 'Glob'], $architect->defaultTools);
        $this->assertSame([], $architect->defaultSkills);
    }

    public function testTester(): void
    {
        // Act
        $tester = AgentDefinition::tester();

        // Assert
        $this->assertSame(AgentDefinition::TYPE_TESTER, $tester->type);
        $this->assertSame('tester', $tester->name);
        $this->assertSame('Test writing and coverage', $tester->description);
        $this->assertSame('You are a testing specialist. Write tests, improve coverage, and ensure quality.', $tester->prompt);
        $this->assertSame(['Read', 'Bash'], $tester->defaultTools);
        $this->assertSame(['phpunit-master'], $tester->defaultSkills);
    }

    public function testDevops(): void
    {
        // Act
        $devops = AgentDefinition::devops();

        // Assert
        $this->assertSame(AgentDefinition::TYPE_DEVOPS, $devops->type);
        $this->assertSame('devops', $devops->name);
        $this->assertSame('CI/CD and deployment', $devops->description);
        $this->assertSame('You are a DevOps specialist. Handle CI/CD, deployment, and infrastructure.', $devops->prompt);
        $this->assertSame(['Read', 'Bash', 'Glob'], $devops->defaultTools);
        $this->assertSame([], $devops->defaultSkills);
    }

    // -------------------------------------------------------------------------
    // fromType() - type-based factory
    // -------------------------------------------------------------------------

    public function testFromTypeCoder(): void
    {
        // Act
        $agent = AgentDefinition::fromType(AgentDefinition::TYPE_CODER, 'my-coder');

        // Assert
        $this->assertNotNull($agent);
        $this->assertSame(AgentDefinition::TYPE_CODER, $agent->type);
        $this->assertSame('my-coder', $agent->name);
    }

    public function testFromTypeUnknown(): void
    {
        // Act
        $agent = AgentDefinition::fromType('unknown-type', 'some-name');

        // Assert
        $this->assertNull($agent);
    }

    public function testFromTypeRoundTrip(): void
    {
        // Test that fromType produces the same result as the direct factory
        $types = [
            AgentDefinition::TYPE_CODER => AgentDefinition::TYPE_CODER,
            AgentDefinition::TYPE_REVIEWER => AgentDefinition::TYPE_REVIEWER,
            AgentDefinition::TYPE_DEBUGGER => AgentDefinition::TYPE_DEBUGGER,
            AgentDefinition::TYPE_ARCHITECT => AgentDefinition::TYPE_ARCHITECT,
            AgentDefinition::TYPE_TESTER => AgentDefinition::TYPE_TESTER,
            AgentDefinition::TYPE_DEVOPS => AgentDefinition::TYPE_DEVOPS,
        ];

        foreach ($types as $type) {
            // Act
            $fromType = AgentDefinition::fromType($type, 'round-trip-test');
            $directFactory = match ($type) {
                AgentDefinition::TYPE_CODER => AgentDefinition::coder('round-trip-test'),
                AgentDefinition::TYPE_REVIEWER => AgentDefinition::reviewer('round-trip-test'),
                AgentDefinition::TYPE_DEBUGGER => AgentDefinition::debugger('round-trip-test'),
                AgentDefinition::TYPE_ARCHITECT => AgentDefinition::architect('round-trip-test'),
                AgentDefinition::TYPE_TESTER => AgentDefinition::tester('round-trip-test'),
                AgentDefinition::TYPE_DEVOPS => AgentDefinition::devops('round-trip-test'),
            };

            // Assert
            $this->assertNotNull($fromType);
            $this->assertSame($directFactory->type, $fromType->type, "Type mismatch for $type");
            $this->assertSame($directFactory->name, $fromType->name, "Name mismatch for $type");
            $this->assertSame($directFactory->description, $fromType->description, "Description mismatch for $type");
            $this->assertSame($directFactory->prompt, $fromType->prompt, "Prompt mismatch for $type");
            $this->assertSame($directFactory->defaultTools, $fromType->defaultTools, "Tools mismatch for $type");
            $this->assertSame($directFactory->defaultSkills, $fromType->defaultSkills, "Skills mismatch for $type");
        }
    }

    public function testAllTypesHaveFromType(): void
    {
        // Ensure all type constants are handled by fromType
        $allTypes = [
            AgentDefinition::TYPE_CODER,
            AgentDefinition::TYPE_REVIEWER,
            AgentDefinition::TYPE_DEBUGGER,
            AgentDefinition::TYPE_ARCHITECT,
            AgentDefinition::TYPE_TESTER,
            AgentDefinition::TYPE_DEVOPS,
        ];

        foreach ($allTypes as $type) {
            $agent = AgentDefinition::fromType($type, 'test-agent');
            $this->assertNotNull($agent, "fromType should handle type: $type");
        }
    }
}
