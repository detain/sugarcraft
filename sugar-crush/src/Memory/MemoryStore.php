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
    private const MAX_INDEX_LINES = 200;
    private const MAX_INDEX_BYTES = 25 * 1024;
    private const MEMORY_INDEX_FILENAME = 'MEMORY.md';

    public function __construct(
        private readonly string $memoryPath,
    ) {
        if (!is_dir($this->memoryPath) || !is_writable($this->memoryPath)) {
            throw new \InvalidArgumentException(
                "Memory path must be a writable directory: {$this->memoryPath}"
            );
        }
    }

    /**
     * Add a new memory entry with the given content and scope.
     *
     * Creates a new MemoryEntry with a generated UUID, type='pattern',
     * empty tags, current timestamps, and writes it to {memoryPath}/{id}.md.
     *
     * @param string         $content The memory content as markdown.
     * @param string         $scope  The scope: 'user', 'project', or 'agent'.
     * @param array<string>  $tags   Optional categorization tags.
     * @return string The generated UUID of the new entry.
     */
    public function add(string $content, string $scope = 'user', array $tags = []): string
    {
        $id = $this->generateUuid();
        $now = new \DateTimeImmutable();

        $entry = MemoryEntry::new(
            type: 'pattern',
            content: $content,
            scope: $scope,
            tags: $tags,
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
     * Retrieve a single memory entry by its ID.
     *
     * @param string $id The UUID of the entry.
     * @return MemoryEntry|null The entry, or null if not found.
     */
    public function get(string $id): ?MemoryEntry
    {
        // UUIDs are generated as 32-char lowercase hex strings (v4).
        // Rejecting non-matching input at the boundary prevents unnecessary
        // filesystem I/O and makes invalid IDs fail fast rather than silently
        // returning null after a failed file_exists() check.
        if (!preg_match('/^[0-9a-f]{32}$/', $id)) {
            return null;
        }

        $file = $this->memoryPath . '/' . $id . '.md';
        if (!file_exists($file)) {
            return null;
        }

        return $this->readEntry($file);
    }

    /**
     * Update an existing memory entry, or insert it if it does not exist.
     *
     * This is an upsert: writeEntry() will create the file if missing.
     * UUID validation is enforced here rather than inside writeEntry() so
     * that callers get a clear InvalidArgumentException immediately, rather
     * than a generic RuntimeException from a failed file_put_contents().
     *
     * @param string      $id    The UUID of the entry to update.
     * @param MemoryEntry $entry The updated entry data.
     */
    public function update(string $id, MemoryEntry $entry): void
    {
        // UUIDs are generated as 32-char lowercase hex strings (v4).
        // Enforcing strict format here fails fast on programmer error rather
        // than allowing malformed filenames to reach the filesystem.
        if (!preg_match('/^[0-9a-f]{32}$/', $id)) {
            throw new \InvalidArgumentException('Invalid memory entry id format');
        }

        $this->writeEntry($id, $entry);
        $this->generateIndex($entry->scope());
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

        // Capture scope before deleting so we can regenerate the index.
        $entry = $this->readEntry($file);
        $scope = $entry?->scope() ?? 'user';

        if (@unlink($file) === false && file_exists($file)) {
            throw new \RuntimeException("Failed to delete memory file: {$file}");
        }

        $this->generateIndex($scope);
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
     * Generate a markdown index file for the given scope.
     *
     * Creates {memoryPath}/MEMORY.md containing header, summarized entries
     * (capped at MAX_INDEX_LINES or MAX_INDEX_BYTES), and footer.
     *
     * @param string $scope The scope to index.
     */
    public function generateIndex(string $scope): void
    {
        $entries = $this->list($scope);

        // If no entries remain, remove the existing index and return early.
        if (empty($entries)) {
            $indexPath = $this->memoryPath . '/' . self::MEMORY_INDEX_FILENAME;
            if (file_exists($indexPath)) {
                unlink($indexPath);
            }
            return;
        }

        $indexPath = $this->memoryPath . '/' . self::MEMORY_INDEX_FILENAME;
        $timestamp = (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM);

        $lines = [];
        $lines[] = "# Memory Index ({$scope})\n";
        $lines[] = "\nLoaded at: {$timestamp}\n";
        $lines[] = "\n---\n";

        foreach ($entries as $entry) {
            $summaryLines = $this->summarizeEntry($entry);
            foreach ($summaryLines as $line) {
                $lines[] = $line;
            }
            // Enforce line cap mid-way if we're about to exceed.
            if (count($lines) >= self::MAX_INDEX_LINES) {
                break;
            }
        }

        $lines[] = "\n---\nGenerated by sugar-crush memory system\n";

        $content = implode("\n", $lines);

        // Enforce byte cap.
        if (strlen($content) > self::MAX_INDEX_BYTES) {
            $content = substr($content, 0, self::MAX_INDEX_BYTES);
        }

        $written = file_put_contents($indexPath, $content);
        if ($written === false) {
            throw new \RuntimeException("Failed to write index file: {$indexPath}");
        }
    }

    /**
     * Load and return the content of the index file.
     *
     * @return string|null The index content, or null if no index exists.
     */
    public function loadIndex(): ?string
    {
        $indexPath = $this->memoryPath . '/' . self::MEMORY_INDEX_FILENAME;

        if (!file_exists($indexPath)) {
            return null;
        }

        $content = file_get_contents($indexPath);
        return $content !== false ? $content : null;
    }

    /**
     * Summarize a memory entry as an array of markdown lines (~3 lines).
     *
     * Format:
     *   [TYPE] id: tags
     *   content preview (first 80 chars)
     *   (blank line)
     *
     * @param MemoryEntry $entry The entry to summarize.
     * @return array<string> Array of markdown lines.
     */
    private function summarizeEntry(MemoryEntry $entry): array
    {
        $tags = implode(', ', $entry->tags());
        $tagLine = $tags === '' ? '' : ": {$tags}";

        $lines = [];
        $lines[] = '[' . strtoupper($entry->type()) . '] ' . $entry->id() . $tagLine;

        $preview = strlen($entry->content()) > 80
            ? substr($entry->content(), 0, 80) . '...'
            : $entry->content();
        $lines[] = $preview;
        $lines[] = '';

        return $lines;
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
