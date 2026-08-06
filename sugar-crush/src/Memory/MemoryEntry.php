<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Memory;

/**
 * Represents a single memory entry in the cross-session memory system.
 *
 * Memory entries are stored as Markdown files with YAML frontmatter
 * containing metadata. Each entry has a type (such as pattern, convention,
 * decision, preference), tags for categorization, project and user scope,
 * created and modified timestamps, and content describing the memory.
 *
 * All values are immutable after construction — use with*() methods
 * to produce derived instances.
 */
final readonly class MemoryEntry
{
    /**
     * @param string               $id         Unique identifier (UUID format).
     * @param string               $type       Entry type: 'pattern', 'convention', 'decision', or 'preference'.
     * @param array<string>        $tags       Categorization tags.
     * @param string               $scope      Scope: 'user', 'project', or 'agent'.
     * @param string               $content    The memory content.
     * @param \DateTimeImmutable   $createdAt  When the entry was first created.
     * @param \DateTimeImmutable   $modifiedAt When the entry was last modified.
     */
    public function __construct(
        private string $id,
        private string $type,
        private array $tags,
        private string $scope,
        private string $content,
        private \DateTimeImmutable $createdAt,
        private \DateTimeImmutable $modifiedAt,
    ) {}

    /**
     * Factory creating a MemoryEntry with generated UUID and timestamps set to now.
     *
     * @param string         $type    Entry type: 'pattern', 'convention', 'decision', or 'preference'.
     * @param string         $content The memory content.
     * @param string         $scope   Scope: 'user', 'project', or 'agent'.
     * @param array<string>  $tags    Categorization tags (default: empty array).
     * @param string|null    $id      Optional explicit ID; a UUID is generated if not provided.
     */
    public static function new(
        string $type,
        string $content,
        string $scope,
        array $tags = [],
        ?string $id = null,
    ): self {
        $now = new \DateTimeImmutable();
        return new self(
            id: $id ?? self::generateUuid(),
            type: $type,
            tags: $tags,
            scope: $scope,
            content: $content,
            createdAt: $now,
            modifiedAt: $now,
        );
    }

    public function id(): string
    {
        return $this->id;
    }

    public function type(): string
    {
        return $this->type;
    }

    /**
     * @return array<string>
     */
    public function tags(): array
    {
        return $this->tags;
    }

    public function scope(): string
    {
        return $this->scope;
    }

    public function content(): string
    {
        return $this->content;
    }

    public function createdAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function modifiedAt(): \DateTimeImmutable
    {
        return $this->modifiedAt;
    }

    /**
     * Create a new entry with a different type value.
     */
    public function withType(string $type): self
    {
        return new self(
            id: $this->id,
            type: $type,
            tags: $this->tags,
            scope: $this->scope,
            content: $this->content,
            createdAt: $this->createdAt,
            modifiedAt: $this->modifiedAt,
        );
    }

    /**
     * Create a new entry with a different tags value.
     */
    public function withTags(array $tags): self
    {
        return new self(
            id: $this->id,
            type: $this->type,
            tags: $tags,
            scope: $this->scope,
            content: $this->content,
            createdAt: $this->createdAt,
            modifiedAt: $this->modifiedAt,
        );
    }

    /**
     * Create a new entry with a different scope value.
     */
    public function withScope(string $scope): self
    {
        return new self(
            id: $this->id,
            type: $this->type,
            tags: $this->tags,
            scope: $scope,
            content: $this->content,
            createdAt: $this->createdAt,
            modifiedAt: $this->modifiedAt,
        );
    }

    /**
     * Create a new entry with a different content value.
     */
    public function withContent(string $content): self
    {
        return new self(
            id: $this->id,
            type: $this->type,
            tags: $this->tags,
            scope: $this->scope,
            content: $content,
            createdAt: $this->createdAt,
            modifiedAt: $this->modifiedAt,
        );
    }

    /**
     * Create a new entry with a different createdAt value.
     */
    public function withCreatedAt(\DateTimeImmutable $createdAt): self
    {
        return new self(
            id: $this->id,
            type: $this->type,
            tags: $this->tags,
            scope: $this->scope,
            content: $this->content,
            createdAt: $createdAt,
            modifiedAt: $this->modifiedAt,
        );
    }

    /**
     * Create a new entry with a different modifiedAt value.
     */
    public function withModifiedAt(\DateTimeImmutable $modifiedAt): self
    {
        return new self(
            id: $this->id,
            type: $this->type,
            tags: $this->tags,
            scope: $this->scope,
            content: $this->content,
            createdAt: $this->createdAt,
            modifiedAt: $modifiedAt,
        );
    }

    /**
     * Return all fields as an array.
     *
     * @return array{id: string, type: string, tags: array<string>, scope: string, content: string, createdAt: \DateTimeImmutable, modifiedAt: \DateTimeImmutable}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type,
            'tags' => $this->tags,
            'scope' => $this->scope,
            'content' => $this->content,
            'createdAt' => $this->createdAt,
            'modifiedAt' => $this->modifiedAt,
        ];
    }

    /**
     * Create a new entry with a different value for the given field.
     *
     * @param string $key   Field name: 'type', 'tags', 'scope', 'content', 'createdAt', or 'modifiedAt'.
     * @param mixed  $value New value for the field.
     * @throws \InvalidArgumentException if $key is not a writable field.
     */
    public function with(string $key, mixed $value): self
    {
        return match ($key) {
            'type' => $this->withType($value),
            'tags' => $this->withTags($value),
            'scope' => $this->withScope($value),
            'content' => $this->withContent($value),
            'createdAt' => $this->withCreatedAt($value),
            'modifiedAt' => $this->withModifiedAt($value),
            default => throw new \InvalidArgumentException("Unknown field: {$key}"),
        };
    }

    /**
     * Generate a UUID v4 string.
     */
    private static function generateUuid(): string
    {
        $bytes = random_bytes(16);
        // Set version (4) and variant (RFC 4122) bits
        $bytes[6] = chr(ord($bytes[6]) & 0x0f | 0x40);
        $bytes[8] = chr(ord($bytes[8]) & 0x3f | 0x80);

        return bin2hex($bytes);
    }
}
