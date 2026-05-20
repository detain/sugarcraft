<?php

declare(strict_types=1);

namespace SugarCraft\Query;

use JsonException;

/**
 * File-backed JSON store for saved SQL query snippets.
 *
 * Persists a list of named snippets to a JSON file so they survive
 * process restarts. Each snippet has a name, optional description,
 * SQL text, and timestamp.
 *
 * Immutable — all mutation methods return a new instance.
 *
 * @readonly
 */
final class SnippetStore
{
    /** @var list<Snippet> */
    public readonly array $snippets;

    private const DEFAULT_PATH = '/tmp/candy-query-snippets.json';

    /**
     * @param list<Snippet> $snippets
     */
    public function __construct(array $snippets = [], public readonly string $path = self::DEFAULT_PATH)
    {
        $this->snippets = $snippets;
    }

    /**
     * Load snippets from the JSON file. Returns an empty store if the
     * file does not exist or is corrupt.
     */
    public static function load(string $path = self::DEFAULT_PATH): self
    {
        if (!is_file($path)) {
            return new self([], $path);
        }

        $content = file_get_contents($path);
        if ($content === false || $content === '') {
            return new self([], $path);
        }

        try {
            /** @var list<array{name:string,description:string,sql:string,createdAt:int}> */
            $data = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return new self([], $path);
        }

        $snippets = array_map(
            static fn(array $row): Snippet => new Snippet(
                name: $row['name'],
                description: $row['description'] ?? '',
                sql: $row['sql'],
                createdAt: $row['createdAt'] ?? time(),
            ),
            $data,
        );

        return new self($snippets, $path);
    }

    /**
     * Persist snippets to the JSON file.
     *
     * @throws JsonException On serialization failure
     */
    public function flush(): void
    {
        $data = array_map(
            static fn(Snippet $s): array => [
                'name' => $s->name,
                'description' => $s->description,
                'sql' => $s->sql,
                'createdAt' => $s->createdAt,
            ],
            $this->snippets,
        );

        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
        $dir = dirname($this->path);
        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new JsonException("Cannot create directory: {$dir}");
        }
        file_put_contents($this->path, $json, LOCK_EX);
    }

    /**
     * Return a new store with $sql appended under $name.
     */
    public function add(string $name, string $sql, string $description = ''): self
    {
        // Guard: empty name or sql is a no-op.
        if ($name === '' || $sql === '') {
            return $this;
        }

        // Guard: duplicate name replaces the existing entry.
        $without = array_values(array_filter(
            $this->snippets,
            static fn(Snippet $s): bool => $s->name !== $name,
        ));

        $snippets = [...$without, new Snippet(
            name: $name,
            description: $description,
            sql: $sql,
            createdAt: time(),
        )];

        return $this->mutate($snippets);
    }

    /**
     * Return a new store with the snippet named $name removed.
     */
    public function delete(string $name): self
    {
        $snippets = array_values(array_filter(
            $this->snippets,
            static fn(Snippet $s): bool => $s->name !== $name,
        ));

        return $this->mutate($snippets);
    }

    /**
     * Look up a snippet by exact name. Returns null if not found.
     */
    public function find(string $name): ?Snippet
    {
        foreach ($this->snippets as $snippet) {
            if ($snippet->name === $name) {
                return $snippet;
            }
        }
        return null;
    }

    /**
     * Search snippets by name or SQL content (case-insensitive substring).
     *
     * @return list<Snippet>
     */
    public function search(string $term): array
    {
        if ($term === '') {
            return $this->snippets;
        }

        $lower = mb_strtolower($term);
        return array_values(array_filter(
            $this->snippets,
            static fn(Snippet $s): bool =>
                mb_stripos($s->name, $lower) !== false
                || mb_stripos($s->sql, $lower) !== false,
        ));
    }

    private function mutate(array $snippets): self
    {
        return new self(snippets: $snippets, path: $this->path);
    }
}

/**
 * A single saved SQL snippet.
 *
 * @readonly
 */
final class Snippet
{
    public function __construct(
        public readonly string $name,
        public readonly string $description,
        public readonly string $sql,
        public readonly int $createdAt,
    ) {}
}
