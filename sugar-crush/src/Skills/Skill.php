<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Skills;

use Symfony\Component\Yaml\Yaml;

/**
 * A skill loaded from a SKILL.md file.
 */
final readonly class Skill
{
    public function __construct(
        public string $name,
        public string $description,
        public bool $userInvocable,
        public bool $disableModelInvocation,
        public ?string $allowedTools,
        public ?string $disallowedTools,
        public ?string $model,
        public string $effort,
        public string $context,
        public array $paths,
        public string $content,
        public string $sourcePath,
    ) {}

    /**
     * Parse a SKILL.md file and return a Skill.
     */
    public static function fromFile(string $path): self
    {
        // Check existence first so a missing path throws cleanly instead of
        // emitting a PHP warning from file_get_contents before we throw.
        if (!is_file($path)) {
            throw new \RuntimeException("Failed to read skill file: $path");
        }
        $content = file_get_contents($path);
        if ($content === false) {
            throw new \RuntimeException("Failed to read skill file: $path");
        }

        // Require frontmatter - SKILL.md files must have valid frontmatter
        if (!preg_match('/^---\s*\n.*?\n---\s*\n/s', $content)) {
            throw new \InvalidArgumentException("Skill file must have frontmatter: $path");
        }

        // Use parent directory name as skill name (SKILL.md is always inside a skill directory)
        $skillName = basename(dirname($path));

        return self::parse($content, $skillName, $path);
    }

    /**
     * Parse SKILL.md content.
     */
    public static function parse(string $content, string $name, string $sourcePath = ''): self
    {
        // Split frontmatter from content
        if (preg_match('/^---\s*\n(.*?)\n---\s*\n/s', $content, $matches)) {
            $frontmatter = $matches[1];
            $body = substr($content, strlen($matches[0]));
            $meta = Yaml::parse($frontmatter);
        } else {
            $meta = [];
            $body = $content;
        }

        return new self(
            name: $name,
            description: $meta['description'] ?? "Skill: $name",
            userInvocable: (bool)($meta['user-invocable'] ?? true),
            disableModelInvocation: (bool)($meta['disable-model-invocation'] ?? false),
            allowedTools: $meta['allowed-tools'] ?? null,
            disallowedTools: $meta['disallowed-tools'] ?? null,
            model: $meta['model'] ?? null,
            effort: $meta['effort'] ?? 'medium',
            context: $meta['context'] ?? 'thread',
            paths: $meta['paths'] ?? [],
            content: trim($body),
            sourcePath: $sourcePath,
        );
    }

    /**
     * Check if this skill matches a prompt.
     */
    public function matchesPrompt(string $prompt): bool
    {
        // Simple keyword matching on description
        $keywords = array_filter(explode(' ', strtolower($this->description)));

        foreach ($keywords as $keyword) {
            if (strlen($keyword) > 3 && stripos($prompt, $keyword) !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * Get the system prompt contribution from this skill.
     */
    public function systemPromptContribution(): string
    {
        return "\n\n## Skill: {$this->name}\n\n{$this->content}";
    }

    /**
     * Convert to array for serialization.
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'description' => $this->description,
            'user_invokable' => $this->userInvocable,
            'disable_model_invocation' => $this->disableModelInvocation,
            'allowed_tools' => $this->allowedTools,
            'disallowed_tools' => $this->disallowedTools,
            'model' => $this->model,
            'effort' => $this->effort,
            'context' => $this->context,
            'paths' => $this->paths,
            'source_path' => $this->sourcePath,
        ];
    }

    public function withName(string $name): self
    {
        return new self(
            name: $name,
            description: $this->description,
            userInvocable: $this->userInvocable,
            disableModelInvocation: $this->disableModelInvocation,
            allowedTools: $this->allowedTools,
            disallowedTools: $this->disallowedTools,
            model: $this->model,
            effort: $this->effort,
            context: $this->context,
            paths: $this->paths,
            content: $this->content,
            sourcePath: $this->sourcePath,
        );
    }
}