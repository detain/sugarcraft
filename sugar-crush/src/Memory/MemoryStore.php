<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Memory;

use SugarCraft\Crush\Agents\MemoryScope;
use SugarCraft\Crush\Context\Utf8Scrub;
use SugarCraft\Crush\Support\AtomicFileWriter;
use SugarCraft\Crush\Support\Frontmatter;
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
 * search() and get() take no scope argument, so they look in every scope
 * subdirectory instead of a single one.
 *
 * No path this store builds ever reaches `glob()` (audit 15d-22). A glob
 * pattern cannot hold a literal path: a checkout at `~/work/[acme]/site` turns
 * `[acme]` into a character class, so the store listed nothing under its own
 * root -- every repo note written, then never found -- or, worse, listed a
 * sibling tree (`~/work/a/site`) that the class happened to match. The
 * directories are read with scandir() ({@see scopeDirectories()},
 * {@see noteFiles()}) and a note is addressed by building its path
 * ({@see findNoteFile()}), so the store's own location is never interpreted.
 *
 * A note's id IS its file name without `.md` (audit 15d-23). The frontmatter
 * `id:` the store writes is a copy, never consulted on read: the listing
 * printed the frontmatter id while get()/update()/delete() looked the file up
 * by name, so a note whose two disagreed -- a `cp <id>.md variant.md`, or a
 * hand-written `deploy-notes.md` -- was listed under an id no command could
 * reach. With one source of truth every listed id is an addressable one, and
 * the stale `id:` of a copied file is rewritten on its next update(). New ids
 * are still 32-hex UUIDs; a hand-authored note may use any readable stem that
 * {@see isNoteId()} accepts, and a file whose stem it refuses is reported in
 * {@see skipped()} rather than listed under an id that cannot be addressed.
 */
final class MemoryStore
{
    private const MAX_INDEX_LINES = 200;
    private const MAX_INDEX_BYTES = 25 * 1024;
    private const MEMORY_INDEX_FILENAME = 'MEMORY.md';

    /**
     * The shape of a note id, which is also the stem of the file it names --
     * so this is the path-traversal guard for get()/update()/delete(), and is
     * strict on purpose: no separator, no leading dot (`.`, `..`, a dot-file,
     * {@see AtomicFileWriter}'s `.<name>.tmp.<hex>` temps), at most 64 bytes.
     * `\z` and `D`, not `$`: `$` also matches before a trailing newline.
     */
    private const NOTE_ID_PATTERN = '/\A[A-Za-z0-9_-][A-Za-z0-9._-]{0,63}\z/D';

    /**
     * Anchored on a whole `---` line at both ends -- the shape the skill and
     * foreign-memory importers already use. The previous `explode('---', ..., 3)`
     * split on the first `---` ANYWHERE, so a tag such as `a---b` cut the
     * frontmatter in half and the note vanished. The closing fence may end the
     * file, which a hand-written note with no body legitimately does.
     */
    private const FRONTMATTER_PATTERN = '/^---\s*\n(.*?)\n---[ \t]*(?:\r?\n|$)(.*)$/s';

    /**
     * Files the store could not read as a memory note, path => reason.
     *
     * A note lives in a git-visible, hand-editable file (a repo's
     * `.sugar-crush/memory/` is read with no trust gate), so a malformed one is
     * an ordinary event, not a programming error: it is skipped so the rest of
     * the store -- and every turn's system prompt, which lists it -- keeps
     * working, and recorded here so the skip can be reported instead of being
     * silent.
     *
     * @var array<string, string>
     */
    private array $skipped = [];

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

        foreach ($this->allNoteFiles() as $file) {
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

        foreach ($this->noteFiles($dir) as $file) {
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
     * Ids don't carry their scope, so this looks in every scope
     * subdirectory for the matching file.
     *
     * @param string $id The note id -- its file name without `.md`.
     * @return MemoryEntry|null The entry, or null if not found (or $id is not a note id).
     */
    public function get(string $id): ?MemoryEntry
    {
        // The id becomes a path component, so anything that is not a note id
        // is refused before it reaches the filesystem.
        if (!self::isNoteId($id)) {
            return null;
        }

        $file = $this->findNoteFile($id);

        return $file === null ? null : $this->readEntry($file);
    }

    /**
     * Update an existing memory entry, or insert it if it does not exist.
     *
     * This is an upsert: writeEntry() will create the file if missing.
     * Id validation is enforced here rather than inside writeEntry() so
     * that callers get a clear InvalidArgumentException immediately, rather
     * than a generic RuntimeException from a failed file_put_contents().
     *
     * If the update changes $entry->scope() relative to the existing entry,
     * the stale copy in the old scope directory is removed so the same id
     * doesn't end up living in two scope directories at once, and both the
     * old and new scope's indexes are regenerated.
     *
     * @param string      $id    The note id (its file name without `.md`); it
     *                           wins over $entry->id() if the two differ.
     * @param MemoryEntry $entry The updated entry data.
     */
    public function update(string $id, MemoryEntry $entry): void
    {
        // The id names the file written, so a malformed one (a separator, a
        // leading dot, the index's own name) fails here, before any write.
        self::assertNoteId($id);

        $oldFile = $this->findNoteFile($id);
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
     * @param string $id The note id -- its file name without `.md`.
     */
    public function delete(string $id): void
    {
        self::assertNoteId($id);

        $file = $this->findNoteFile($id);
        $scope = 'user';

        if ($file !== null) {
            $entry = $this->readEntry($file);
            $scope = $entry?->scope() ?? 'user';

            if (@unlink($file) === false && file_exists($file)) {
                throw new \RuntimeException("Failed to delete memory file: {$file}");
            }

            unset($this->skipped[$file]);
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

        foreach ($this->noteFiles($dir) as $file) {
            unlink($file);
            unset($this->skipped[$file]);
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

        // No timestamp (audit 15d-23): the index is a pure function of the
        // scope's notes. A repo store's index sits in a git-visible tree, and a
        // fresh `Loaded at:` line on every write turned each note change into a
        // diff in a second file -- and made two branches that each added a
        // note conflict on it.
        $lines = [];
        $lines[] = "# Memory Index ({$scope})\n";
        $lines[] = "---\n";

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

        // An unchanged index is left alone, so a mutation that does not
        // change what the index says does not touch the file at all.
        if ($this->loadIndex($scope) === $content) {
            return;
        }

        // Audit M3: temp+rename publish, never a torn index. 0600 because a
        // memory index can quote user facts; the pre-fix direct write landed
        // 0644 under a default umask.
        try {
            AtomicFileWriter::write($indexPath, $content, 0600);
        } catch (\RuntimeException $e) {
            throw new \RuntimeException("Failed to write index file: {$indexPath}", 0, $e);
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

        // 0700, not the pre-audit 0777: scope dirs hold the memory .md files
        // themselves (audit M3); the umask would have shaved it to 0755 on a
        // normal box, still world-traversable and listable.
        if ($createIfMissing && !is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
            throw new \RuntimeException("Failed to create scope directory: {$dir}");
        }

        return $dir;
    }

    /**
     * Every scope subdirectory of memoryPath, sorted by path.
     *
     * Replaces the directory-level `*` of the old memoryPath globs (audit
     * 15d-22) and keeps its shape: a name with a leading dot is
     * not a scope (glob's `*` never matched one), and the order is the byte
     * order glob() returned under PHP's default C collation, so the first
     * match for an id is the one it always was.
     *
     * @return list<string>
     */
    private function scopeDirectories(): array
    {
        $dirs = [];
        foreach (self::directoryNames($this->memoryPath) as $name) {
            $dir = $this->memoryPath . '/' . $name;
            if (is_dir($dir)) {
                $dirs[] = $dir;
            }
        }

        return $dirs;
    }

    /**
     * The `*.md` files directly inside $dir, sorted by path -- the old
     * `glob($dir . '/*.md')` without interpreting $dir as a pattern.
     *
     * A dot-file is left out as glob left it out, which also keeps
     * {@see AtomicFileWriter}'s in-flight `.<name>.tmp.<hex>` siblings out of
     * every listing. The index file is included, as it was; readEntry() is
     * what declines it.
     *
     * @return list<string>
     */
    private function noteFiles(string $dir): array
    {
        $files = [];
        foreach (self::directoryNames($dir) as $name) {
            if (str_ends_with($name, '.md')) {
                $files[] = $dir . '/' . $name;
            }
        }

        return $files;
    }

    /**
     * Every scope's note files, sorted by full path as the old two-level
     * glob sorted them.
     *
     * @return list<string>
     */
    private function allNoteFiles(): array
    {
        $files = [];
        foreach ($this->scopeDirectories() as $dir) {
            array_push($files, ...$this->noteFiles($dir));
        }
        sort($files, \SORT_STRING);

        return $files;
    }

    /**
     * The file holding note $id, looked for in each scope directory by its
     * built path rather than by globbing `<id>.md` under every scope (audit
     * 15d-22). The caller has already validated $id with {@see isNoteId()},
     * so it is a plain file name and never the index's. First match in path
     * order, as glob()'s `$matches[0]` was.
     */
    private function findNoteFile(string $id): ?string
    {
        foreach ($this->scopeDirectories() as $dir) {
            $file = $dir . '/' . $id . '.md';
            if (file_exists($file)) {
                return $file;
            }
        }

        return null;
    }

    /**
     * Whether $id can name a note: {@see NOTE_ID_PATTERN}, and not the stem
     * of the index file in any case. The index check matters because the id
     * is turned into `<scope dir>/<id>.md` -- `MEMORY` would make delete()
     * unlink the index and update() overwrite it, and on a case-insensitive
     * filesystem `memory` names the same file.
     */
    private static function isNoteId(string $id): bool
    {
        return preg_match(self::NOTE_ID_PATTERN, $id) === 1
            && strcasecmp($id . '.md', self::MEMORY_INDEX_FILENAME) !== 0;
    }

    /**
     * @throws \InvalidArgumentException when $id cannot name a note
     */
    private static function assertNoteId(string $id): void
    {
        if (!self::isNoteId($id)) {
            throw new \InvalidArgumentException(
                'Invalid memory entry id format: an id is a note\'s file name without `.md` '
                . '-- letters, digits, `.`, `_` and `-`, at most 64, not starting with `.`'
            );
        }
    }

    /**
     * The non-dot entry names of $dir in byte order; empty when $dir is
     * missing or unreadable, which is what glob() reported for either.
     *
     * @return list<string>
     */
    private static function directoryNames(string $dir): array
    {
        $names = is_dir($dir) ? @scandir($dir, \SCANDIR_SORT_NONE) : false;
        if ($names === false) {
            return [];
        }

        $names = array_values(array_filter(
            $names,
            static fn(string $name): bool => $name !== '' && $name[0] !== '.',
        ));
        sort($names, \SORT_STRING);

        return $names;
    }

    /**
     * The files skipped as unreadable memory notes since this store was built,
     * keyed by path, each with the reason it was refused.
     *
     * An entry is dropped again the moment its file parses (the author fixed
     * it) or is removed through {@see delete()} / {@see clear()}, so the map
     * describes the files as they are now, not a history of past mistakes.
     *
     * $within narrows the map to the files of that one scope's directory — the
     * question {@see \SugarCraft\Crush\Context\MemoryBlock::capture()} asks,
     * since it announces only the project notes it failed to read and the map
     * also holds whatever an earlier search() or list() of another scope
     * skipped on this same store.
     *
     * @return array<string, string>
     */
    public function skipped(string|MemoryScope|null $within = null): array
    {
        if ($within === null) {
            return $this->skipped;
        }

        $dir = $this->scopeDirectory($within, false);

        return array_filter(
            $this->skipped,
            static fn(string $file): bool => \dirname($file) === $dir,
            \ARRAY_FILTER_USE_KEY,
        );
    }

    /**
     * Read every note in every scope of this store and return the files that
     * could not be read, path => reason — {@see skipped()} made complete.
     *
     * {@see skipped()} only knows what a list() or search() has already met,
     * and the prompt lists the project scope alone, so a broken user- or
     * agent-scope note was reported to nobody until the user happened to list
     * that scope. This is the read a launch notice needs to say "some of your
     * notes are being ignored" at all (audit 15d-04 follow-up). Same listing as
     * {@see search()}, so it sees exactly the files the store itself can.
     *
     * A path that has vanished since an earlier read is forgotten here rather
     * than reported: the map describes the files as they are now.
     *
     * @return array<string, string> sorted by path
     */
    public function unreadable(): array
    {
        $files = $this->allNoteFiles();

        foreach ($files as $file) {
            $this->readEntry($file);
        }

        $this->skipped = array_intersect_key($this->skipped, array_flip($files));
        $unreadable = $this->skipped;
        ksort($unreadable, \SORT_STRING);

        return $unreadable;
    }

    /**
     * Read and parse a single memory entry from a file.
     *
     * Catches \Throwable rather than \Exception on purpose: every failure a
     * hand edit can provoke -- a YAML int where a string was expected, a
     * missing key -- surfaced as a TypeError, which `catch (\Exception)` let
     * escape through list() into MemoryBlock::capture() and failed EVERY turn
     * of the session (audit 15d-04). decode() validates field by field so the
     * reason is precise; the broad catch is the backstop for anything it
     * did not anticipate.
     *
     * @param string $file The full path to the .md file.
     * @return MemoryEntry|null The parsed entry, or null if the file is skipped.
     */
    private function readEntry(string $file): ?MemoryEntry
    {
        // The per-scope index shares the `*.md` listing with the notes but is
        // generated output, not a note -- never a skip worth reporting.
        if (basename($file) === self::MEMORY_INDEX_FILENAME) {
            return null;
        }

        // The stem is the id (audit 15d-23), so a file whose stem no command
        // could take back -- `my note.md`, `memory.md` -- is reported, not
        // listed under an id that `/memory edit|delete` would refuse.
        if (!self::isNoteId(basename($file, '.md'))) {
            $this->skipped[$file] = 'the file name is not a usable memory id -- rename it to letters, '
                . 'digits, `.`, `_` and `-` (at most 64, not starting with `.`, not `MEMORY`)';

            return null;
        }

        try {
            $content = @file_get_contents($file);

            if ($content === false) {
                throw new \UnexpectedValueException('file could not be read');
            }

            // Valid UTF-8 before the parse (audit 15d-08): the YAML reader
            // refuses invalid bytes, which skipped the whole note, and a body
            // that got past it reached the system prompt raw and failed the
            // JSON encode of every request. A hand-edited note in a legacy
            // encoding loses those characters to `?`, not the note.
            $entry = $this->decode(Utf8Scrub::clean($content), $file);
        } catch (\Throwable $e) {
            $this->skipped[$file] = $e->getMessage();

            return null;
        }

        unset($this->skipped[$file]);

        return $entry;
    }

    /**
     * Parse a memory entry from markdown content with YAML frontmatter.
     *
     * Tolerant where the author's intent is unambiguous -- an unquoted date
     * (which YAML hands back as an int timestamp), a single bare `tags:`
     * string, omitted timestamps -- and refuses, with a reason, where it is
     * not: no frontmatter, a non-mapping block, or a missing/non-string
     * type/scope, none of which has a value we could honestly invent.
     *
     * The id is the file name, not the frontmatter's `id:` (see the class
     * docblock), so a missing or stale `id:` is no defect.
     *
     * @throws \UnexpectedValueException naming the defect
     */
    private function decode(string $raw, string $file): MemoryEntry
    {
        if (preg_match(self::FRONTMATTER_PATTERN, $raw, $m) !== 1) {
            throw new \UnexpectedValueException('no YAML frontmatter block (a leading and a closing "---" line)');
        }

        try {
            $meta = Frontmatter::parse($m[1]);
        } catch (\Throwable $e) {
            throw new \UnexpectedValueException('invalid YAML frontmatter: ' . $e->getMessage(), 0, $e);
        }

        if (!is_array($meta) || ($meta !== [] && array_is_list($meta))) {
            throw new \UnexpectedValueException('frontmatter is not a key: value mapping');
        }

        $fallback = $this->fileTime($file);
        $createdAt = $this->dateField($meta, 'createdAt') ?? $fallback;
        $modifiedAt = $this->dateField($meta, 'modifiedAt') ?? $createdAt;

        return MemoryEntry::new(
            type: $this->stringField($meta, 'type'),
            content: trim($m[2] ?? ''),
            scope: $this->stringField($meta, 'scope'),
            tags: $this->tagsField($meta),
            id: basename($file, '.md'),
        )->withCreatedAt($createdAt)
         ->withModifiedAt($modifiedAt);
    }

    /**
     * @param array<mixed> $meta
     */
    private function stringField(array $meta, string $key): string
    {
        $value = $meta[$key] ?? null;

        if (!is_string($value) || trim($value) === '') {
            throw new \UnexpectedValueException(
                $value === null ? "missing `{$key}:`" : "`{$key}:` must be a non-empty string, got " . get_debug_type($value)
            );
        }

        return $value;
    }

    /**
     * A bare `tags: x` is read as the one-element list the author meant;
     * numbers are stringified (YAML types `tags: [2024]` as an int). Anything
     * else -- a nested list, a mapping, a boolean -- is refused rather than
     * guessed at.
     *
     * @param array<mixed> $meta
     * @return list<string>
     */
    private function tagsField(array $meta): array
    {
        $tags = $meta['tags'] ?? [];

        if (is_string($tags)) {
            $tags = [$tags];
        }

        if (!is_array($tags) || !array_is_list($tags)) {
            throw new \UnexpectedValueException('`tags:` must be a list of strings');
        }

        $out = [];
        foreach ($tags as $tag) {
            if (!is_string($tag) && !is_int($tag) && !is_float($tag)) {
                throw new \UnexpectedValueException('`tags:` must be a list of strings, found ' . get_debug_type($tag));
            }
            $out[] = (string) $tag;
        }

        return $out;
    }

    /**
     * Null when the key is absent, so the caller can choose the fallback.
     *
     * YAML without PARSE_DATETIME turns an unquoted `2024-01-01` into an int
     * Unix timestamp, which is read via the `@` form; a DateTimeInterface is
     * accepted too, for a parse that did enable that flag.
     *
     * @param array<mixed> $meta
     */
    private function dateField(array $meta, string $key): ?\DateTimeImmutable
    {
        $value = $meta[$key] ?? null;

        try {
            return match (true) {
                $value === null => null,
                $value instanceof \DateTimeInterface => \DateTimeImmutable::createFromInterface($value),
                is_int($value) => new \DateTimeImmutable('@' . $value),
                is_string($value) && trim($value) !== '' => new \DateTimeImmutable($value),
                default => throw new \UnexpectedValueException('wrong type ' . get_debug_type($value)),
            };
        } catch (\Throwable $e) {
            throw new \UnexpectedValueException("`{$key}:` is not a date: " . $e->getMessage(), 0, $e);
        }
    }

    /**
     * The timestamp a note with no recorded dates is given: when its file was
     * last written, which is the closest thing to the truth on disk.
     */
    private function fileTime(string $file): \DateTimeImmutable
    {
        $mtime = @filemtime($file);

        return $mtime !== false ? new \DateTimeImmutable('@' . $mtime) : new \DateTimeImmutable();
    }

    /**
     * Write a memory entry to a file under its scope's subdirectory.
     *
     * @param string      $id    The note id, already validated; names the file.
     * @param MemoryEntry $entry The entry to write.
     */
    private function writeEntry(string $id, MemoryEntry $entry): void
    {
        $dir = $this->scopeDirectory($entry->scope(), true);
        $file = $dir . '/' . $id . '.md';

        // The frontmatter copy of the id is written from the file name, so a
        // note the store writes never disagrees with itself.
        $frontmatter = Yaml::dump([
            'id' => $id,
            'type' => $entry->type(),
            'tags' => $entry->tags(),
            'scope' => $entry->scope(),
            'createdAt' => $entry->createdAt()->format(\DateTimeInterface::ATOM),
            'modifiedAt' => $entry->modifiedAt()->format(\DateTimeInterface::ATOM),
        ]);

        $fileContent = "---\n" . $frontmatter . "---\n" . $entry->content();

        // Audit M3: a memory note is the user's own words with no regeneration
        // story — exactly the payload that must never be catchable half-written.
        try {
            AtomicFileWriter::write($file, $fileContent, 0600);
        } catch (\RuntimeException $e) {
            throw new \RuntimeException("Failed to write memory file: {$file}", 0, $e);
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
