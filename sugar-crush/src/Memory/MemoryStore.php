<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Memory;

use Symfony\Component\Yaml\Yaml;

/**
 * Manages persistent cross-session memory stored as Markdown files with YAML frontmatter.
 *
 * Each memory entry is stored as a separate .md file in the memoryPath directory.
 * Files are named {id}.md and contain YAML frontmatter followed by markdown content.
 */
final class MemoryStore
{
    public function __construct(
        private readonly string $memoryPath,
    ) {}

    /**
     * Add a new memory entry with the given content and scope.
     *
     * Creates a new MemoryEntry with a generated UUID, type='pattern',
     * empty tags, current timestamps, and writes it to {memoryPath}/{id}.md.
     *
     * @param string $content The memory content as markdown.
     * @param string $scope   The scope: 'user', 'project', or 'agent'.
     * @return string The generated UUID of the new entry.
     */
    public function add(string $content, string $scope = 'user'): string
    {
        $id = $this->generateUuid();
        $now = new \DateTimeImmutable();

        $entry = MemoryEntry::new(
            type: 'pattern',
            content: $content,
            scope: $scope,
            tags: [],
            id: $id,
        );

        $this->writeEntry($id, $entry);
        $this->generateIndex($scope);

        return $id;
    }

    /**
     * Search all memory entries for content matching the query string.
     *
     * Case-insensitive search that reads all .md files and filters by
     * whether the query appears in the content field.
     *
     * @param string $query The search query string.
     * @return MemoryEntry[] Matching entries.
     */
    public function search(string $query): array
    {
        $results = [];
        $files = glob($this->memoryPath . '/*.md');

        if ($files === false) {
            return [];
        }

        foreach ($files as $file) {
            $entry = $this->readEntry($file);
            if ($entry === null) {
                continue;
            }

            // Check content, type, and tags for a match
            $contentMatch = stripos($entry->content(), $query) !== false;
            $typeMatch = stripos($entry->type(), $query) !== false;
            $tagMatch = false;
            foreach ($entry->tags() as $tag) {
                if (stripos($tag, $query) !== false) {
                    $tagMatch = true;
                    break;
                }
            }

            if ($contentMatch || $typeMatch || $tagMatch) {
                $results[] = $entry;
            }
        }

        return $results;
    }

    /**
     * List all memory entries for a given scope.
     *
     * @param string $scope The scope to filter by.
     * @return MemoryEntry[] All entries matching the scope.
     */
    public function list(string $scope = 'user'): array
    {
        $results = [];
        $files = glob($this->memoryPath . '/*.md');

        if ($files === false) {
            return [];
        }

        foreach ($files as $file) {
            $entry = $this->readEntry($file);
            if ($entry !== null && $entry->scope() === $scope) {
                $results[] = $entry;
            }
        }

        return $results;
    }

    /**
     * Delete a memory entry by ID.
     *
     * @param string $id The UUID of the entry to delete.
     */
    public function delete(string $id): void
    {
        if (!preg_match('/^[0-9a-f]{32}$/', $id)) {
            throw new \InvalidArgumentException('Invalid memory entry id format');
        }

        $file = $this->memoryPath . '/' . $id . '.md';
        $scope = null;

        if (file_exists($file)) {
            $entry = $this->readEntry($file);
            if ($entry !== null) {
                $scope = $entry->scope();
            }
            unlink($file);
        }

        if ($scope !== null) {
            $this->generateIndex($scope);
        }
    }

    /**
     * Clear all memory entries for a given scope.
     *
     * @param string $scope The scope to clear.
     */
    public function clear(string $scope): void
    {
        $files = glob($this->memoryPath . '/*.md');

        if ($files === false) {
            return;
        }

        foreach ($files as $file) {
            $entry = $this->readEntry($file);
            if ($entry !== null && $entry->scope() === $scope) {
                unlink($file);
            }
        }

        $this->generateIndex($scope);
    }

    /**
     * Read and parse a single memory entry from a file.
     *
     * @param string $file The full path to the .md file.
     * @return MemoryEntry|null The parsed entry, or null if parsing fails.
     */
    private function readEntry(string $file): ?MemoryEntry
    {
        $content = file_get_contents($file);

        if ($content === false) {
            return null;
        }

        return $this->parseEntry($content);
    }

    /**
     * Parse a memory entry from markdown content with YAML frontmatter.
     *
     * @param string $raw The raw file content.
     * @return MemoryEntry|null The parsed entry, or null if parsing fails.
     */
    private function parseEntry(string $raw): ?MemoryEntry
    {
        if (!str_starts_with($raw, '---')) {
            return null;
        }

        // Limit of 3 splits on '---' so content containing '---' is handled correctly:
        // parts[0]="", parts[1]=frontmatter, parts[2]=content (everything after the 2nd ---)
        $parts = explode('---', $raw, 3);

        if (count($parts) < 3) {
            return null;
        }

        try {
            /** @var array{id: string, type: string, tags: array<string>, scope: string, createdAt: string, modifiedAt: string} $meta */
            $meta = Yaml::parse($parts[1]);

            return MemoryEntry::new(
                type: $meta['type'],
                content: trim($parts[2]),
                scope: $meta['scope'],
                tags: $meta['tags'] ?? [],
                id: $meta['id'],
            )->withCreatedAt(new \DateTimeImmutable($meta['createdAt']))
             ->withModifiedAt(new \DateTimeImmutable($meta['modifiedAt']));
        } catch (\Exception) {
            return null;
        }
    }

    /**
     * Write a memory entry to a file.
     *
     * @param string      $id    The UUID of the entry.
     * @param MemoryEntry $entry The entry to write.
     */
    private function writeEntry(string $id, MemoryEntry $entry): void
    {
        $file = $this->memoryPath . '/' . $id . '.md';

        $frontmatter = Yaml::dump([
            'id' => $entry->id(),
            'type' => $entry->type(),
            'tags' => $entry->tags(),
            'scope' => $entry->scope(),
            'createdAt' => $entry->createdAt()->format(\DateTimeInterface::ATOM),
            'modifiedAt' => $entry->modifiedAt()->format(\DateTimeInterface::ATOM),
        ]);

        $fileContent = "---\n" . $frontmatter . "---\n" . $entry->content();

        $written = file_put_contents($file, $fileContent);
        if ($written === false) {
            throw new \RuntimeException("Failed to write memory file: {$file}");
        }
    }

    private const MAX_INDEX_LINES = 200;
    private const MAX_INDEX_BYTES = 25 * 1024;
    private const INDEX_SUBDIR = 'indexes';

    /**
     * Generate a MEMORY.md index file for the given scope.
     *
     * The index summarizes all entries in that scope, capped at roughly
     * the first 200 lines or 25KB — whichever is reached first.
     * The index file is written to {memoryPath}/{scope}.md.
     *
     * @param string $scope The scope: 'user', 'project', or 'agent'.
     */
    public function generateIndex(string $scope): void
    {
        $entries = $this->list($scope);
        $lines = [];
        $bytesWritten = 0;

        $lines[] = "# Memory Index — {$scope}";
        $lines[] = "";
        $lines[] = "This index is auto-generated and capped at " . self::MAX_INDEX_LINES . " lines / " . (self::MAX_INDEX_BYTES / 1024) . "KB.";
        $lines[] = "";
        $lines[] = "## Entries";
        $lines[] = "";

        foreach ($entries as $entry) {
            $summaryLines = $this->summarizeEntry($entry);
            foreach ($summaryLines as $line) {
                $lineBytes = strlen($line) + 1;
                if (count($lines) >= self::MAX_INDEX_LINES || $bytesWritten + $lineBytes > self::MAX_INDEX_BYTES) {
                    break 2;
                }
                $lines[] = $line;
                $bytesWritten += $lineBytes;
            }
        }

        $lines[] = "";
        $lines[] = "*Index generated at: " . (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM) . "*";

        $indexDir = $this->memoryPath . '/' . self::INDEX_SUBDIR;
        if (!is_dir($indexDir)) {
            mkdir($indexDir, 0755, true);
        }
        $indexFile = $indexDir . '/' . $scope . '.md';
        $content = implode("\n", $lines);
        $written = file_put_contents($indexFile, $content);
        if ($written === false) {
            throw new \RuntimeException("Failed to write index file: {$indexFile}");
        }
    }

    /**
     * Load the MEMORY.md index for the given scope.
     *
     * Returns the raw index content, or null if the index file does not exist.
     *
     * @param string $scope The scope: 'user', 'project', or 'agent'.
     * @return string|null The index file content, or null if not found.
     */
    public function loadIndex(string $scope): ?string
    {
        $indexFile = $this->memoryPath . '/' . self::INDEX_SUBDIR . '/' . $scope . '.md';

        if (!file_exists($indexFile)) {
            return null;
        }

        $content = file_get_contents($indexFile);
        return $content !== false ? $content : null;
    }

    /**
     * Build a short summary for a single memory entry suitable for an index.
     *
     * @return array<string> One or more lines summarizing the entry.
     */
    private function summarizeEntry(MemoryEntry $entry): array
    {
        $lines = [];
        $tags = empty($entry->tags()) ? '' : ' [' . implode(', ', $entry->tags()) . ']';
        $preview = mb_strlen($entry->content()) > 120
            ? mb_substr($entry->content(), 0, 120) . '…'
            : $entry->content();
        $lines[] = '- **[' . $entry->type() . ']** `' . $entry->id() . '`' . $tags;
        $lines[] = '  ' . $preview;
        $lines[] = '';
        return $lines;
    }

    /**
     * Generate a UUID v4 string.
     */
    private function generateUuid(): string
    {
        $bytes = random_bytes(16);
        // Set version (4) and variant (RFC 4122) bits
        $bytes[6] = chr(ord($bytes[6]) & 0x0f | 0x40);
        $bytes[8] = chr(ord($bytes[8]) & 0x3f | 0x80);

        return bin2hex($bytes);
    }
}
