<?php

declare(strict_types=1);
// codacy ignore tainted-filename

namespace SugarCraft\Crush\Memory;

use SugarCraft\Crush\Agents\MemoryScope;
use SugarCraft\Crush\Skills\SkillSource;
use Symfony\Component\Yaml\Yaml;

/**
 * Imports memory/scratchpad files written by OTHER coding CLIs into
 * sugar-crush's own {@see MemoryStore}.
 *
 * READ-ONLY BY DESIGN. Claude Code's `~/.claude/projects/<slug>/memory/` tree
 * is harness-managed -- it regenerates its own MEMORY.md index and owns the
 * frontmatter shape -- so this class only ever reads from a foreign tree and
 * writes into sugar-crush's store. There is deliberately no export direction.
 *
 * Provenance is recorded as a `source:<skill-source>` tag derived from the
 * shared {@see SkillSource} enum, the same vocabulary
 * {@see \SugarCraft\Crush\Skills\ForeignSkillDiscovery} and
 * {@see \SugarCraft\Crush\Agents\ForeignAgentPresetRegistry} use to badge
 * imported skills and agent presets. Reusing that enum rather than inventing
 * a memory-specific source list keeps one name per foreign tool across the
 * three importers, so a UI filter written against SkillSource covers memory too.
 *
 * Imports are NOT idempotent: {@see MemoryStore::add()} mints a fresh UUID per
 * call, so importing the same directory twice produces two copies of every
 * entry. De-duplication belongs to the trigger point rather than here -- the
 * spec's own design puts it in a sentinel file
 * (`.sugar-crush/memory/.imported-claude`) written by whoever invokes the
 * import, because only the caller knows whether a re-import was intentional.
 *
 * NOT YET WIRED INTO THE RUNTIME. Nothing in `src/` or `bin/` constructs this
 * class. The spec's trigger points are a `/memory import claude|opencode` chat
 * subcommand (alongside the existing `/memory add|list|clear` handled by
 * `Chat::handleMemoryCommand()`) or a first-run one-shot prompt; both live in
 * `Chat.php`, which a later step owns. Until that lands, importing a foreign
 * memory tree has no runtime effect.
 */
final class ForeignMemoryImporter
{
    /** Leading YAML frontmatter block, as written by Claude Code's memory files. */
    private const FRONTMATTER_PATTERN = '/^---\s*\n(.*?)\n---\s*\n/s';

    /**
     * Claude Code regenerates this file from the other entries in the
     * directory, so importing it would duplicate every real entry as one
     * summary blob.
     */
    private const INDEX_FILENAME = 'MEMORY.md';

    public function __construct(private readonly MemoryStore $store) {}

    /**
     * Import Claude Code's per-project auto-memory entries.
     *
     * Claude Code stores them at `<claudeHome>/projects/<slug>/memory/*.md`
     * where <slug> is the absolute project path with every `/` replaced by `-`
     * (so `/home/sites/sugarcraft` becomes `-home-sites-sugarcraft`). Each
     * entry carries YAML frontmatter; a file without frontmatter is not one of
     * Claude Code's entries and is skipped rather than imported as a blob.
     *
     * @param  string      $projectRoot Absolute project path, as Claude Code slugs it.
     * @param  string|null $claudeHome  Override for `~/.claude` (tests, non-default installs).
     * @return int Number of entries imported.
     */
    public function importClaudeCode(string $projectRoot, ?string $claudeHome = null): int
    {
        $home = $claudeHome ?? (($_SERVER['HOME'] ?? '/root') . '/.claude');
        $dir = rtrim($home, '/') . '/projects/' . $this->claudeProjectSlug($projectRoot) . '/memory';

        $imported = 0;

        foreach ($this->markdownFiles($dir) as $file) {
            if (basename($file) === self::INDEX_FILENAME) {
                continue;
            }

            $raw = file_get_contents($file);
            if ($raw === false || preg_match(self::FRONTMATTER_PATTERN, $raw, $m) !== 1) {
                continue;
            }

            try {
                $meta = Yaml::parse($m[1]);
            } catch (\Throwable $e) {
                // One unparseable foreign file must not abort the import of
                // every other entry in the directory.
                error_log("ForeignMemoryImporter: skipping {$file}: {$e->getMessage()}");
                continue;
            }

            // Yaml::parse returns whatever the block encodes -- null for an
            // empty block, a scalar for a bare value. Only a map can carry the
            // fields read below.
            $meta = is_array($meta) ? $meta : [];
            $body = trim(substr($raw, strlen($m[0])));

            $this->store->add(
                content: $this->title($meta, $file) . "\n\n" . $body,
                scope: MemoryScope::Local,
                tags: $this->claudeTags($meta),
            );
            $imported++;
        }

        return $imported;
    }

    /**
     * Import opencode's project-local memory files.
     *
     * opencode's memory files carry no frontmatter, so the whole file is
     * imported under a title derived from its filename.
     *
     * @param  string $projectRoot Project checkout root.
     * @return int Number of entries imported.
     */
    public function importOpencode(string $projectRoot): int
    {
        $dir = rtrim($projectRoot, '/') . '/.opencode/memory';
        $imported = 0;

        foreach ($this->markdownFiles($dir) as $file) {
            $content = file_get_contents($file);
            if ($content === false) {
                continue;
            }

            $this->store->add(
                content: '# ' . basename($file, '.md') . "\n\n" . trim($content),
                scope: MemoryScope::Local,
                tags: [$this->sourceTag(SkillSource::Opencode)],
            );
            $imported++;
        }

        return $imported;
    }

    /**
     * Every `*.md` in $dir, or an empty list when the directory is absent
     * (a user who has never run the foreign tool is the common case, not an
     * error) or unreadable (glob() returns false).
     *
     * @return list<string>
     */
    private function markdownFiles(string $dir): array
    {
        if (!is_dir($dir)) {
            return [];
        }

        return array_values(glob(rtrim($dir, '/') . '/*.md') ?: []);
    }

    /**
     * Claude Code's project-directory slug: the absolute path with `/` turned
     * into `-`, keeping the leading separator so `/home/x` becomes `-home-x`.
     *
     * The trailing slash is stripped first: a caller passing `/home/x/` would
     * otherwise slug to `-home-x-` and silently find no memory directory,
     * since Claude Code never writes a trailing separator into the name.
     */
    private function claudeProjectSlug(string $projectRoot): string
    {
        $path = rtrim($projectRoot, '/');

        return '-' . ltrim(str_replace('/', '-', $path), '-');
    }

    /**
     * First line of an imported Claude Code entry: its `description`, falling
     * back to the filename stem.
     *
     * The type check is what makes the fallback reachable in practice --
     * frontmatter is user/harness-authored YAML, so `description:` can decode
     * to a list or a date object, and concatenating one of those would raise
     * an "Array to string conversion" warning instead of importing the entry.
     *
     * @param array<mixed> $meta
     */
    private function title(array $meta, string $file): string
    {
        $description = $meta['description'] ?? null;

        if (is_string($description) && trim($description) !== '') {
            return trim($description);
        }

        return basename($file, '.md');
    }

    /**
     * Provenance tags for an imported Claude Code entry: the source tag, plus
     * the originating session id when the foreign entry records one, so a
     * user can trace an imported memory back to the conversation that wrote it.
     *
     * @param  array<mixed> $meta
     * @return list<string>
     */
    private function claudeTags(array $meta): array
    {
        $tags = [$this->sourceTag(SkillSource::Claude)];

        $metadata = $meta['metadata'] ?? null;
        $origin = is_array($metadata) ? ($metadata['originSessionId'] ?? null) : null;

        // Non-scalar (or empty) ids are dropped rather than stringified: a
        // nested map would concatenate to the literal "Array", which is a
        // worse tag than no origin tag at all.
        if (is_scalar($origin) && trim((string) $origin) !== '') {
            $tags[] = 'origin:' . trim((string) $origin);
        }

        return $tags;
    }

    /**
     * The `source:<tool>` provenance tag for a foreign tool, spelled from the
     * shared {@see SkillSource} vocabulary.
     */
    private function sourceTag(SkillSource $source): string
    {
        return 'source:' . $source->value;
    }
}
