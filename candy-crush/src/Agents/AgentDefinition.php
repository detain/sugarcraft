<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Agents;

final readonly class AgentDefinition
{
    public const TYPE_CODER = 'coder';
    public const TYPE_REVIEWER = 'reviewer';
    public const TYPE_DEBUGGER = 'debugger';
    public const TYPE_ARCHITECT = 'architect';
    public const TYPE_TESTER = 'tester';
    public const TYPE_DEVOPS = 'devops';

    public function __construct(
        public string $type,
        public string $name,
        public string $description,
        public string $prompt,
        public array $defaultTools,
        public array $defaultSkills,
    ) {}

    public static function coder(string $name = 'coder'): self
    {
        return new self(
            type: self::TYPE_CODER,
            name: $name,
            description: 'General coding assistant',
            prompt: 'You are a coding assistant. Help write, modify, and understand code.',
            defaultTools: ['Read', 'Edit', 'Bash'],
            defaultSkills: [],
        );
    }

    public static function reviewer(string $name = 'reviewer'): self
    {
        return new self(
            type: self::TYPE_REVIEWER,
            name: $name,
            description: 'Code review specialist',
            prompt: 'You are a code review specialist. Review code for bugs, security issues, and best practices.',
            defaultTools: ['Read', 'Grep', 'Bash(git:*)'],
            defaultSkills: ['php-best-practices', 'security-audit'],
        );
    }

    public static function debugger(string $name = 'debugger'): self
    {
        return new self(
            type: self::TYPE_DEBUGGER,
            name: $name,
            description: 'Bug investigation and fixing',
            prompt: 'You are a debugging specialist. Investigate bugs, trace issues, and propose fixes.',
            defaultTools: ['Read', 'Grep', 'Bash'],
            defaultSkills: [],
        );
    }

    public static function architect(string $name = 'architect'): self
    {
        return new self(
            type: self::TYPE_ARCHITECT,
            name: $name,
            description: 'System design and architecture',
            prompt: 'You are a software architect. Design systems, propose patterns, and evaluate trade-offs.',
            defaultTools: ['Read', 'Grep', 'Glob'],
            defaultSkills: [],
        );
    }

    public static function tester(string $name = 'tester'): self
    {
        return new self(
            type: self::TYPE_TESTER,
            name: $name,
            description: 'Test writing and coverage',
            prompt: 'You are a testing specialist. Write tests, improve coverage, and ensure quality.',
            defaultTools: ['Read', 'Bash'],
            defaultSkills: ['phpunit-master'],
        );
    }

    public static function devops(string $name = 'devops'): self
    {
        return new self(
            type: self::TYPE_DEVOPS,
            name: $name,
            description: 'CI/CD and deployment',
            prompt: 'You are a DevOps specialist. Handle CI/CD, deployment, and infrastructure.',
            defaultTools: ['Read', 'Bash', 'Glob'],
            defaultSkills: [],
        );
    }

    public static function fromType(string $type, string $name): ?self
    {
        return match ($type) {
            self::TYPE_CODER => self::coder($name),
            self::TYPE_REVIEWER => self::reviewer($name),
            self::TYPE_DEBUGGER => self::debugger($name),
            self::TYPE_ARCHITECT => self::architect($name),
            self::TYPE_TESTER => self::tester($name),
            self::TYPE_DEVOPS => self::devops($name),
            default => null,
        };
    }
}
