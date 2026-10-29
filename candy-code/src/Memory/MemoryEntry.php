<?php

declare(strict_types=1);

namespace SugarCraft\Code\Memory;

/**
 * Represents a single memory entry in the cross-session memory system.
 *
 * Memory entries are stored as Markdown files with YAML frontmatter
 * containing metadata. Each entry has a type (such as pattern, convention,
 * decision, preference, intent), tags for categorization, project and user
 * scope, created and modified timestamps, and content describing the memory.
 * An `intent` entry also carries a `when:` trigger — the standing condition
 * under which {@see \SugarCraft\Code\Context\RulePathNudge} surfaces it at
 * tool time — normalised to its canonical `path:<glob>` or `keyword:<word>`
 * form by {@see normalizeWhen()} before it is ever written.
 *
 * All values are immutable after construction — use with*() methods
 * to produce derived instances.
 */
final readonly class MemoryEntry
{
    /** The canonical prefix marking a path-glob `when:` trigger. */
    public const WHEN_PATH_PREFIX = 'path:';

    /** The canonical prefix marking a keyword `when:` trigger. */
    public const WHEN_KEYWORD_PREFIX = 'keyword:';

    /** Characters that make a bare `when:` value read as a path glob. */
    private const WHEN_GLOB_METACHARS = ['*', '?', '['];

    /** Prefixes the grammar recognises but does not fire on today. */
    private const WHEN_UNWIRED_PREFIXES = ['tool', 'glob', 'regex'];

    /**
     * @param string               $id         Unique identifier (UUID format).
     * @param string               $type       Entry type: 'pattern', 'convention', 'decision', 'preference', or 'intent'.
     * @param array<string>        $tags       Categorization tags.
     * @param string               $scope      Scope: 'user', 'project', or 'agent'.
     * @param string               $content    The memory content.
     * @param \DateTimeImmutable   $createdAt  When the entry was first created.
     * @param \DateTimeImmutable   $modifiedAt When the entry was last modified.
     * @param string               $when       Normalised `when:` trigger for an intent note ('' for every other type).
     */
    public function __construct(
        private string $id,
        private string $type,
        private array $tags,
        private string $scope,
        private string $content,
        private \DateTimeImmutable $createdAt,
        private \DateTimeImmutable $modifiedAt,
        private string $when = '',
    ) {}

    /**
     * Factory creating a MemoryEntry with generated UUID and timestamps set to now.
     *
     * @param string         $type    Entry type: 'pattern', 'convention', 'decision', 'preference', or 'intent'.
     * @param string         $content The memory content.
     * @param string         $scope   Scope: 'user', 'project', or 'agent'.
     * @param array<string>  $tags    Categorization tags (default: empty array).
     * @param string|null    $id      Optional explicit ID; a UUID is generated if not provided.
     * @param string         $when    Trigger for an intent note, in either raw or canonical form; stored normalised.
     */
    public static function new(
        string $type,
        string $content,
        string $scope,
        array $tags = [],
        ?string $id = null,
        string $when = '',
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
            when: $when === '' ? '' : self::normalizeWhen($when),
        );
    }

    /**
     * Validate and canonicalise a `when:` trigger string (C-CTX-3.f).
     *
     * Grammar: `path:<glob>` fires when a touched path matches the glob;
     * `keyword:<word>[,<word>...]` fires when a touched path contains one of
     * the words as a whole word. A bare value is auto-classified: containing a
     * glob metacharacter or starting with `/` makes it a path glob, anything
     * else a keyword. The stored form is always the canonical prefixed one, so
     * {@see whenPathGlob()} and {@see whenKeywords()} never re-guess.
     *
     * Tool-name triggers are refused on purpose: the rule-nudge seam sees
     * paths, not the calling tool's name, so a `tool:` form could never fire
     * honestly (naming a tool is expressible as `keyword:<toolname>` only for
     * paths that carry the word — the refusal says so rather than lying).
     *
     * @throws \InvalidArgumentException when the trigger is blank, malformed,
     *                                   or a recognised-but-unwired form.
     */
    public static function normalizeWhen(string $when): string
    {
        $trimmed = trim($when);
        if ($trimmed === '') {
            throw new \InvalidArgumentException('when: must not be blank');
        }

        foreach (self::WHEN_UNWIRED_PREFIXES as $prefix) {
            if (preg_match('/^' . preg_quote($prefix, '/') . ':/i', $trimmed) === 1) {
                throw new \InvalidArgumentException(
                    "when: '{$prefix}:' triggers are not wired — the intent seam matches paths; use 'path:<glob>' or 'keyword:<word>'"
                );
            }
        }

        $lower = mb_strtolower($trimmed);
        if (str_starts_with($lower, self::WHEN_PATH_PREFIX)) {
            $glob = trim(substr($trimmed, strlen(self::WHEN_PATH_PREFIX)));
            if ($glob === '') {
                throw new \InvalidArgumentException('when: path: needs a glob after the prefix');
            }
            return self::WHEN_PATH_PREFIX . $glob;
        }

        if (str_starts_with($lower, self::WHEN_KEYWORD_PREFIX)) {
            $words = self::whenWords(substr($trimmed, strlen(self::WHEN_KEYWORD_PREFIX)));
            return self::WHEN_KEYWORD_PREFIX . implode(',', $words);
        }

        foreach (self::WHEN_GLOB_METACHARS as $char) {
            if (str_contains($trimmed, $char)) {
                return self::WHEN_PATH_PREFIX . $trimmed;
            }
        }
        if (str_starts_with($trimmed, '/')) {
            return self::WHEN_PATH_PREFIX . $trimmed;
        }

        return self::WHEN_KEYWORD_PREFIX . implode(',', self::whenWords($trimmed));
    }

    /**
     * Split a keyword list, refusing blanks and duplicates-after-lowercase.
     *
     * @return list<string>
     */
    private static function whenWords(string $raw): array
    {
        $parts = array_filter(explode(',', $raw), static fn (string $part): bool => trim($part) !== '');
        $words = [];
        foreach ($parts as $part) {
            $word = trim($part);
            if ($word === '') {
                continue;
            }
            $words[mb_strtolower($word)] = $word;
        }
        if ($words === []) {
            throw new \InvalidArgumentException('when: keyword: needs at least one word');
        }
        return array_values($words);
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

    /** The normalised `when:` trigger of an intent note; '' for every other type. */
    public function when(): string
    {
        return $this->when;
    }

    /** The path glob of a `path:`-form trigger; null for any other form. */
    public function whenPathGlob(): ?string
    {
        return str_starts_with($this->when, self::WHEN_PATH_PREFIX)
            ? substr($this->when, strlen(self::WHEN_PATH_PREFIX))
            : null;
    }

    /**
     * The words of a `keyword:`-form trigger; null for any other form.
     *
     * @return list<string>|null
     */
    public function whenKeywords(): ?array
    {
        if (!str_starts_with($this->when, self::WHEN_KEYWORD_PREFIX)) {
            return null;
        }
        return explode(',', substr($this->when, strlen(self::WHEN_KEYWORD_PREFIX)));
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
            when: $this->when,
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
            when: $this->when,
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
            when: $this->when,
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
            when: $this->when,
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
            when: $this->when,
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
            when: $this->when,
        );
    }

    /**
     * Create a new entry with a different `when:` trigger (C-CTX-3.f).
     *
     * Accepts raw or canonical spelling and stores the normalised form; ''
     * clears the trigger (an intent with no `when:` is just a note body —
     * the nudge never announces it). A malformed trigger throws like at save
     * time, so the setter is no weaker a gate than MemoryWriter's.
     */
    public function withWhen(string $when): self
    {
        return new self(
            id: $this->id,
            type: $this->type,
            tags: $this->tags,
            scope: $this->scope,
            content: $this->content,
            createdAt: $this->createdAt,
            modifiedAt: $this->modifiedAt,
            when: $when === '' ? '' : self::normalizeWhen($when),
        );
    }

    /**
     * Return all fields as an array.
     *
     * @return array{id: string, type: string, tags: array<string>, scope: string, content: string, createdAt: string, modifiedAt: string, when: string}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type,
            'tags' => $this->tags,
            'scope' => $this->scope,
            'content' => $this->content,
            'createdAt' => $this->createdAt->format(\DateTimeInterface::ATOM),
            'modifiedAt' => $this->modifiedAt->format(\DateTimeInterface::ATOM),
            'when' => $this->when,
        ];
    }

    /**
     * Create a new entry with a different value for the given field.
     *
     * @param string $key   Field name: 'type', 'tags', 'scope', 'content', 'createdAt', 'modifiedAt', or 'when'.
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
            'when' => $this->withWhen($value),
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
