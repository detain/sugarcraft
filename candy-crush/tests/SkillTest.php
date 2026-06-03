<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Skills\Skill;

/**
 * Tests for Skill value object - represents a skill loaded from a SKILL.md file.
 */
final class SkillTest extends TestCase
{
    // -------------------------------------------------------------------------
    // parse() - YAML frontmatter parsing
    // -------------------------------------------------------------------------

    public function testParseWithFrontmatter(): void
    {
        // Arrange
        $content = <<<'SKILL'
---
description: A test skill for PHP development
user-invocable: true
disable-model-invocation: false
allowed-tools: file_read,file_write
disallowed-tools: null
model: gpt-4o
effort: high
context: thread
paths:
  - /src/**/*.php
  - /tests/**/*.php
---
This is the skill content body.
SKILL;

        // Act
        $skill = Skill::parse($content, 'test-skill', '/path/to/test-skill/SKILL.md');

        // Assert
        $this->assertSame('test-skill', $skill->name);
        $this->assertSame('A test skill for PHP development', $skill->description);
        $this->assertTrue($skill->userInvocable);
        $this->assertFalse($skill->disableModelInvocation);
        $this->assertSame('file_read,file_write', $skill->allowedTools);
        $this->assertNull($skill->disallowedTools);
        $this->assertSame('gpt-4o', $skill->model);
        $this->assertSame('high', $skill->effort);
        $this->assertSame('thread', $skill->context);
        $this->assertSame(['/src/**/*.php', '/tests/**/*.php'], $skill->paths);
        $this->assertSame('This is the skill content body.', $skill->content);
        $this->assertSame('/path/to/test-skill/SKILL.md', $skill->sourcePath);
    }

    public function testParseWithoutFrontmatter(): void
    {
        // Arrange
        $content = 'This is plain content without any frontmatter.';

        // Act
        $skill = Skill::parse($content, 'plain-skill', '/path/to/plain-skill/SKILL.md');

        // Assert
        $this->assertSame('plain-skill', $skill->name);
        $this->assertSame('Skill: plain-skill', $skill->description); // default
        $this->assertTrue($skill->userInvocable); // default
        $this->assertFalse($skill->disableModelInvocation); // default
        $this->assertNull($skill->allowedTools); // default
        $this->assertNull($skill->disallowedTools); // default
        $this->assertNull($skill->model); // default
        $this->assertSame('medium', $skill->effort); // default
        $this->assertSame('thread', $skill->context); // default
        $this->assertSame([], $skill->paths); // default
        $this->assertSame('This is plain content without any frontmatter.', $skill->content);
        $this->assertSame('/path/to/plain-skill/SKILL.md', $skill->sourcePath);
    }

    public function testParseWithAllFrontmatterFields(): void
    {
        // Arrange
        $content = <<<'SKILL'
---
description: Full featured skill
user-invocable: false
disable-model-invocation: true
allowed-tools: tool_a
disallowed-tools: tool_b
model: claude-sonnet
effort: low
context: session
paths:
  - /docs
---
Full content here.
SKILL;

        // Act
        $skill = Skill::parse($content, 'full-skill', '/path/to/full-skill/SKILL.md');

        // Assert
        $this->assertSame('full-skill', $skill->name);
        $this->assertSame('Full featured skill', $skill->description);
        $this->assertFalse($skill->userInvocable);
        $this->assertTrue($skill->disableModelInvocation);
        $this->assertSame('tool_a', $skill->allowedTools);
        $this->assertSame('tool_b', $skill->disallowedTools);
        $this->assertSame('claude-sonnet', $skill->model);
        $this->assertSame('low', $skill->effort);
        $this->assertSame('session', $skill->context);
        $this->assertSame(['/docs'], $skill->paths);
        $this->assertSame('Full content here.', $skill->content);
    }

    public function testParseDefaults(): void
    {
        // Arrange
        $content = <<<'SKILL'
---
description: Partial frontmatter only
---
Only description set.
SKILL;

        // Act
        $skill = Skill::parse($content, 'partial-skill', '');

        // Assert - explicit values
        $this->assertSame('partial-skill', $skill->name);
        $this->assertSame('Partial frontmatter only', $skill->description);
        // Assert - defaults
        $this->assertTrue($skill->userInvocable);
        $this->assertFalse($skill->disableModelInvocation);
        $this->assertNull($skill->allowedTools);
        $this->assertNull($skill->disallowedTools);
        $this->assertNull($skill->model);
        $this->assertSame('medium', $skill->effort);
        $this->assertSame('thread', $skill->context);
        $this->assertSame([], $skill->paths);
        $this->assertSame('Only description set.', $skill->content);
        $this->assertSame('', $skill->sourcePath);
    }

    // -------------------------------------------------------------------------
    // fromFile() - file loading
    // -------------------------------------------------------------------------

    public function testFromFileNotFound(): void
    {
        // Arrange
        $nonExistentPath = '/non/existent/path/SKILL.md';

        // Act & Assert
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage("Failed to read skill file: $nonExistentPath");

        Skill::fromFile($nonExistentPath);
    }

    // -------------------------------------------------------------------------
    // matchesPrompt() - keyword matching
    // -------------------------------------------------------------------------

    public function testMatchesPromptWithKeyword(): void
    {
        // Arrange - description keywords: 'laravel', 'developer', 'skill', 'tests' (php=3 chars filtered)
        $content = <<<'SKILL'
---
description: PHP Laravel developer skill with tests
---
Skill content.
SKILL;
        $skill = Skill::parse($content, 'php-laravel');

        // Act & Assert
        // 'laravel' should match 'I need a Laravel developer'
        $this->assertTrue($skill->matchesPrompt('I need a Laravel developer'));
        // 'php' is only 3 chars, filtered out, so this should return false
        $this->assertFalse($skill->matchesPrompt('Looking for PHP expertise'));
    }

    public function testMatchesPromptNoMatch(): void
    {
        // Arrange - description keywords: 'developer', 'skill' (php=3 chars filtered)
        $content = <<<'SKILL'
---
description: PHP developer skill
---
Skill content.
SKILL;
        $skill = Skill::parse($content, 'php-dev');

        // Act & Assert - use prompts with NO keywords from description
        // 'developer' is a keyword, so avoid prompts containing it
        $this->assertFalse($skill->matchesPrompt('I need a Ruby specialist')); // ruby not in desc, specialist is 9 chars but not in desc
        $this->assertFalse($skill->matchesPrompt('Looking for Python expertise')); // python not in desc
    }

    public function testMatchesPromptShortKeyword(): void
    {
        // Arrange - description with words <= 3 chars should be ignored
        // 'java' (4 chars) should match, 'python' (6 chars) should not since java is 4 chars
        $content = <<<'SKILL'
---
description: Java Spring Boot developer
---
Skill content.
SKILL;
        $skill = Skill::parse($content, 'java-dev');

        // Act & Assert
        // 'Java' (4 chars) should be a keyword and match
        $this->assertTrue($skill->matchesPrompt('I need Java expertise'));
        // 'Spring' (6 chars) should match
        $this->assertTrue($skill->matchesPrompt('Looking for Spring framework'));
        // 'Python' (6 chars) is not in description, so should not match
        $this->assertFalse($skill->matchesPrompt('Looking for Python expertise'));
    }

    // -------------------------------------------------------------------------
    // systemPromptContribution()
    // -------------------------------------------------------------------------

    public function testSystemPromptContribution(): void
    {
        // Arrange
        $content = <<<'SKILL'
---
description: A test skill
---
## Custom Skill Content

This skill provides specific functionality.
SKILL;
        $skill = Skill::parse($content, 'test-skill', '/path/test');

        // Act
        $contribution = $skill->systemPromptContribution();

        // Assert
        $expected = "\n\n## Skill: test-skill\n\n## Custom Skill Content\n\nThis skill provides specific functionality.";
        $this->assertSame($expected, $contribution);
    }

    // -------------------------------------------------------------------------
    // toArray()
    // -------------------------------------------------------------------------

    public function testToArray(): void
    {
        // Arrange
        $content = <<<'SKILL'
---
description: Test skill for array conversion
user-invocable: false
disable-model-invocation: true
allowed-tools: read,write
model: gpt-4
effort: high
context: session
paths:
  - /src
---
Content here.
SKILL;
        $skill = Skill::parse($content, 'array-test', '/path/array-test/SKILL.md');

        // Act
        $array = $skill->toArray();

        // Assert
        $this->assertIsArray($array);
        $this->assertSame('array-test', $array['name']);
        $this->assertSame('Test skill for array conversion', $array['description']);
        $this->assertFalse($array['user_invokable']); // note: snake_case in serialization
        $this->assertTrue($array['disable_model_invocation']);
        $this->assertSame('read,write', $array['allowed_tools']);
        $this->assertNull($array['disallowed_tools']);
        $this->assertSame('gpt-4', $array['model']);
        $this->assertSame('high', $array['effort']);
        $this->assertSame('session', $array['context']);
        $this->assertSame(['/src'], $array['paths']);
        $this->assertSame('/path/array-test/SKILL.md', $array['source_path']);
        // Note: 'content' is intentionally excluded from toArray()
        $this->assertArrayNotHasKey('content', $array);
    }

    // -------------------------------------------------------------------------
    // withName() - immutable builder
    // -------------------------------------------------------------------------

    public function testWithName(): void
    {
        // Arrange
        $original = Skill::parse(
            'Some content here.',
            'original-name',
            '/path/original'
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
        $original = Skill::parse(
            <<<'SKILL'
---
description: Original description
user-invocable: false
disable-model-invocation: true
allowed-tools: tool_a
model: gpt-4
effort: high
context: session
paths:
  - /src
  - /tests
---
Original content.
SKILL,
            'original-name',
            '/path/original'
        );

        // Act
        $renamed = $original->withName('renamed-skill');

        // Assert - name changed
        $this->assertSame('renamed-skill', $renamed->name);
        // Assert - other fields preserved
        $this->assertSame('Original description', $renamed->description);
        $this->assertFalse($renamed->userInvocable);
        $this->assertTrue($renamed->disableModelInvocation);
        $this->assertSame('tool_a', $renamed->allowedTools);
        $this->assertNull($renamed->disallowedTools);
        $this->assertSame('gpt-4', $renamed->model);
        $this->assertSame('high', $renamed->effort);
        $this->assertSame('session', $renamed->context);
        $this->assertSame(['/src', '/tests'], $renamed->paths);
        $this->assertSame('Original content.', $renamed->content);
        $this->assertSame('/path/original', $renamed->sourcePath);
        // Assert - original unchanged
        $this->assertSame('original-name', $original->name);
    }
}
