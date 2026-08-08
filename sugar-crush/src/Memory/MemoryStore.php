<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Memory;

use SugarCraft\Crush\Agents\MemoryScope;
use Symfony\Component\Yaml\Yaml;

/**
 * Manages persistent cross-session memory stored as Markdown files with YAML frontmatter.
 *
 * Each memory entry is stored as a separate .md file, partitioned on disk by scope:
 * {memoryPath}/{scope}/{id}.md. Partitioning by directory (rather than only by the
 * 'scope' YAML field) means 'project', 'user', and 'agent' memories genuinely live
 * at different physical paths, not just under a different label inside one shared
 * directory.
 *
 * The MEMORY.md index mirrors that same per-scope partitioning: each scope gets
 * its own {memoryPath}/{scope}/MEMORY.md, generated only from that scope's entries.
 * A single shared index file could never represent more than one scope's entries
 * at a time -- every mutation of scope A would silently overwrite whatever the
 * index last said about scope B (and clearing an empty-looking scope A could even
 * delete the one index file entirely, even though scope B's files were untouched
 * on disk). Per-scope index files make loadIndex()/generateIndex() immune to that:
 * touching one scope never reads, writes, or deletes another scope's index.
 *
 * Design decision: every public method that takes a scope accepts
 * `string|MemoryScope` (Chat.php's /memory command parsing only ever has a raw
 * string from user input, so requiring MemoryScope everywhere would ripple into
 * every call site; but the enum is the one that must actually govern physical
 * layout, per the original requirement). normalizeScope() is the single
 * resolver: it turns either form into the canonical string used as the
 * on-disk subdirectory name, and scopeDirectory() runs every scope through it
 * before touching the filesystem. A bare string is passed through unchanged
 * (this is how Chat.php's 'user'/'project'/'agent' vocabulary keeps working
 * without modification); a MemoryScope is resolved via ->value, EXCEPT
 * MemoryScope::Local, which is deliberately mapped to the string 'agent'.
 *
 * That last mapping closes a real naming mismatch: MemoryScope's own enum
 * cases are User/Project/Local ('user'/'project'/'local'), but every existing
 * string-based caller (Chat.php's /memory add|list|clear --scope regexes)
 * only ever accepts/emits 'user'|'project'|'agent' -- 'local' does not appear
 * anywhere else in this codebase. Without the explicit mapping, a future
 * caller that passed MemoryScope::Local would silently write to a local/
 * directory that no string-based caller (list/clear/search) ever looks at,
 * fragmenting scope storage rather than partitioning it. normalizeScope()
 * treats MemoryScope::Local and the string 'agent' as the same physical
 * scope so the enum can be adopted without a coordinated rename of Chat.php's
 * vocabulary.
 *
 * search() and get() take no scope argument, so they glob across every scope
 * subdirectory instead of a single one.
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
     * empty tags, current timestamps, and writes it to
     * {memoryPath}/{scope}/{id}.md.
     *
     * @param string              $content The memory content as markdown.
     * @param string|MemoryScope  $scope   The scope: 'user', 'project', 'agent',
     *                                     or the equivalent MemoryScope case.
     * @param array<string>       $tags    Optional categorization tags.
     * @return string The generated UUID of the new entry.
     */
    public function add(string $content, string|MemoryScope $scope = 'user', array $tags = []): string
    {
        $id = $this->generateUuid();
        $now = new \DateTimeImmutable();
        $scope = $this->normalizeScope($scope);

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
     * Search all memory entries, across every scope, for content matching the query string.
     *
     * Case-insensitive search that reads all .md files under every scope
     * subdirectory and filters by whether the query appears in the content field.
     *
     * @param string $query The search query string.
     * @return MemoryEntry[] Matching entries.
     */
    public function search(string $query): array
    {
        $results = [];
        $files = glob($this->memoryPath . '/*/*.md');

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
     * Reads only {memoryPath}/{scope}/*.md -- other scopes' directories are
     * never touched, so this is authoritative for "what lives in this scope".
     *
     * @param string|MemoryScope $scope The scope to filter by.
     * @return MemoryEntry[] All entries matching the scope.
     */
    public function list(string|MemoryScope $scope = 'user'): array
    {
        $scope = $this->normalizeScope($scope);
        $results = [];
        $dir = $this->scopeDirectory($scope, false);
        $files = is_dir($dir) ? glob($dir . '/*.md') : [];

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
     * Ids don't carry their scope, so this globs across every scope
     * subdirectory to find the matching file.
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

        $matches = glob($this->memoryPath . '/*/' . $id . '.md');
        if ($matches === false || $matches === []) {
            return null;
        }

        return $this->readEntry($matches[0]);
    }

    /**
     * Update an existing memory entry, or insert it if it does not exist.
     *
     * This is an upsert: writeEntry() will create the file if missing.
     * UUID validation is enforced here rather than inside writeEntry() so
     * that callers get a clear InvalidArgumentException immediately, rather
     * than a generic RuntimeException from a failed file_put_contents().
     *
     * If the update changes $entry->scope() relative to the existing entry,
     * the stale copy in the old scope directory is removed so the same id
     * doesn't end up living in two scope directories at once, and both the
     * old and new scope's indexes are regenerated.
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

        $existingMatches = glob($this->memoryPath . '/*/' . $id . '.md');
        $oldFile = ($existingMatches !== false && $existingMatches !== []) ? $existingMatches[0] : null;
        $oldScope = $oldFile !== null ? $this->readEntry($oldFile)?->scope() : null;

        $this->writeEntry($id, $entry);

        $newFile = $this->scopeDirectory($entry->scope(), false) . '/' . $id . '.md';
        if ($oldFile !== null && $oldFile !== $newFile) {
            unlink($oldFile);
        }

        $this->generateIndex($entry->scope());
        if ($oldScope !== null && $oldScope !== $entry->scope()) {
            $this->generateIndex($oldScope);
        }
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

        $matches = glob($this->memoryPath . '/*/' . $id . '.md');
        $file = ($matches !== false && $matches !== []) ? $matches[0] : null;
        $scope = 'user';

        if ($file !== null) {
            $entry = $this->readEntry($file);
            $scope = $entry?->scope() ?? 'user';

            if (@unlink($file) === false && file_exists($file)) {
                throw new \RuntimeException("Failed to delete memory file: {$file}");
            }
        }

        $this->generateIndex($scope);
    }

    /**
     * Clear all memory entries for a given scope.
     *
     * @param string|MemoryScope $scope The scope to clear.
     */
    public function clear(string|MemoryScope $scope): void
    {
        $scope = $this->normalizeScope($scope);
        $dir = $this->scopeDirectory($scope, false);
        $files = is_dir($dir) ? glob($dir . '/*.md') : [];

        if ($files === false) {
            $files = [];
        }

        foreach ($files as $file) {
            unlink($file);
        }

        $this->generateIndex($scope);
    }

    /**
     * Generate a markdown index file for the given scope.
     *
     * Creates {memoryPath}/{scope}/MEMORY.md containing header, summarized
     * entries (capped at MAX_INDEX_LINES actual rendered lines and
     * MAX_INDEX_BYTES bytes), and footer. Scoped to its own subdirectory so
     * regenerating scope A's index never reads, writes, or deletes scope B's
     * index -- see the class docblock.
     *
     * @param string|MemoryScope $scope The scope to index.
     */
    public function generateIndex(string|MemoryScope $scope): void
    {
        $scope = $this->normalizeScope($scope);
        $entries = $this->list($scope);
        $indexPath = $this->scopeDirectory($scope, false) . '/' . self::MEMORY_INDEX_FILENAME;

        // If no entries remain, remove the existing index and return early.
        if (empty($entries)) {
            if (file_exists($indexPath)) {
                unlink($indexPath);
            }
            return;
        }

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

            // Enforce line cap mid-way if we're about to exceed it. Counted
            // against the ACTUAL rendered newlines of the joined string, not
            // count($lines) -- a single summary line can itself embed "\n"
            // characters (e.g. multi-line memory content), so the two counts
            // diverge and array-element count alone under-counts real lines.
            if ($this->renderedLineCount($lines) >= self::MAX_INDEX_LINES) {
                break;
            }
        }

        $lines[] = "\n---\nGenerated by sugar-crush memory system\n";

        $content = implode("\n", $lines);

        // Authoritative line cap: re-derive the real line count from the
        // FINAL joined string rather than trusting the incremental check
        // above (the footer line is appended after that check runs).
        $renderedLines = explode("\n", $content);
        if (count($renderedLines) > self::MAX_INDEX_LINES) {
            $content = implode("\n", array_slice($renderedLines, 0, self::MAX_INDEX_LINES));
        }

        // Byte cap: mb_strcut() cuts by byte offset but rounds down to the
        // nearest complete character, so multibyte UTF-8 sequences are never
        // split mid-way the way a raw substr() byte cut could split them.
        if (strlen($content) > self::MAX_INDEX_BYTES) {
            $content = mb_strcut($content, 0, self::MAX_INDEX_BYTES, 'UTF-8');
        }

        $written = file_put_contents($indexPath, $content);
        if ($written === false) {
            throw new \RuntimeException("Failed to write index file: {$indexPath}");
        }
    }

    /**
     * Load and return the content of the given scope's index file.
     *
     * @param string|MemoryScope $scope The scope whose index to load.
     * @return string|null The index content, or null if no index exists.
     */
    public function loadIndex(string|MemoryScope $scope = 'user'): ?string
    {
        $indexPath = $this->scopeDirectory($scope, false) . '/' . self::MEMORY_INDEX_FILENAME;

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
     * Count the actual number of rendered lines that implode("\n", $lines)
     * would produce -- i.e. real "\n" occurrences in the joined string plus
     * one, not the number of array elements.
     *
     * @param array<string> $lines
     */
    private function renderedLineCount(array $lines): int
    {
        return substr_count(implode("\n", $lines), "\n") + 1;
    }

    /**
     * Resolve a scope argument -- either the legacy raw string vocabulary
     * ('user'/'project'/'agent', as used by Chat.php) or a MemoryScope enum
     * case -- to the canonical string that actually names the on-disk
     * subdirectory. A string is passed through unchanged; a MemoryScope is
     * resolved via ->value, EXCEPT MemoryScope::Local, which maps to the
     * string 'agent' rather than 'local' -- see the class docblock for why
     * that mapping exists (it reconciles the enum's own vocabulary with the
     * string vocabulary every existing caller already uses).
     *
     * @param string|MemoryScope $scope
     */
    private function normalizeScope(string|MemoryScope $scope): string
    {
        if ($scope instanceof MemoryScope) {
            return $scope === MemoryScope::Local ? 'agent' : $scope->value;
        }

        return $scope;
    }

    /**
     * Resolve the on-disk subdirectory of memoryPath that a given scope's
     * entries live in, optionally creating it.
     *
     * The scope string is sanitized to a safe directory name so an
     * unexpected scope value can't escape memoryPath or collide with
     * MEMORY_INDEX_FILENAME.
     *
     * @param string|MemoryScope $scope           The scope to resolve.
     * @param bool               $createIfMissing Whether to mkdir() the directory if absent.
     */
    private function scopeDirectory(string|MemoryScope $scope, bool $createIfMissing): string
    {
        $safeScope = preg_replace('/[^A-Za-z0-9_-]/', '_', $this->normalizeScope($scope));
        if ($safeScope === null || $safeScope === '') {
            $safeScope = 'default';
        }

        $dir = $this->memoryPath . '/' . $safeScope;

        if ($createIfMissing && !is_dir($dir) && !mkdir($dir, 0777, true) && !is_dir($dir)) {
            throw new \RuntimeException("Failed to create scope directory: {$dir}");
        }

        return $dir;
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
     * Write a memory entry to a file under its scope's subdirectory.
     *
     * @param string      $id    The UUID of the entry.
     * @param MemoryEntry $entry The entry to write.
     */
    private function writeEntry(string $id, MemoryEntry $entry): void
    {
        $dir = $this->scopeDirectory($entry->scope(), true);
        $file = $dir . '/' . $id . '.md';

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
