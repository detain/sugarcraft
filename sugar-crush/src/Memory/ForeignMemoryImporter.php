<?php

declare(strict_types=1);
// codacy ignore tainted-filename

namespace SugarCraft\Crush\Memory;

use SugarCraft\Crush\Support\ContainedPath;
use SugarCraft\Crush\Support\Frontmatter;
use SugarCraft\Crush\Support\HomeDirectory;
use SugarCraft\Crush\Agents\MemoryScope;
use SugarCraft\Crush\Skills\SkillSource;

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
 *
 * DORMANT IS NOT UNGATED. One of the two directories this class reads —
 * `{projectRoot}/.opencode/memory` — is a path a CLONED REPOSITORY chooses, and
 * for one round it was read with no containment at all, which is the same shape
 * as the escape the native agent-preset tier already refuses. Both boundaries
 * are now here: the directory against the checkout that named it
 * ({@see importOpencode()}), and each `*.md` entry against the directory it was
 * listed from ({@see markdownFiles()}). Refusals are pulled through
 * {@see refusedDirectories()}.
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

    /**
     * Directories and entries the most recent import declined to read, path as
     * spelled => why — see {@see refusedDirectories()}.
     *
     * @var array<string, string>
     */
    private array $refusedDirectories = [];

    public function __construct(private readonly MemoryStore $store) {}

    /**
     * What this importer refused to read, and why.
     *
     * The pull-based seam three other tiers already expose
     * ({@see \SugarCraft\Crush\Agents\AgentPresetRegistry::refusedDirectories()},
     * {@see \SugarCraft\Crush\Skills\SkillManager::refusedDirectories()},
     * {@see \SugarCraft\Crush\Workflows\WorkflowRegistry::projectTierRefusal()}),
     * provided before the wiring rather than after it: an import that returns
     * `0` because the directory was REFUSED is otherwise indistinguishable from
     * one that returns `0` because the directory was empty.
     *
     * Recomputed by every import* call rather than accumulated, so a refusal
     * never outlives the condition that caused it.
     *
     * @return array<string, string> path as spelled => why it was refused
     */
    public function refusedDirectories(): array
    {
        return $this->refusedDirectories;
    }

    /**
     * Import Claude Code's per-project auto-memory entries.
     *
     * Claude Code stores them at `<claudeHome>/projects/<slug>/memory/*.md`
     * where <slug> is the absolute project path with every `/` replaced by `-`
     * (so `/home/sites/sugarcraft` becomes `-home-sites-sugarcraft`). Each
     * entry carries YAML frontmatter; a file without frontmatter is not one of
     * Claude Code's entries and is skipped rather than imported as a blob.
     *
     * THE USER TIER GOES THROUGH {@see HomeDirectory::owned()}, not
     * {@see HomeDirectory::path()}, and this was the last reader in the package
     * still on the latter for a tier whose bodies reach the model.
     * `path()`'s documented stand-in is `sys_get_temp_dir()` — mode 1777 on
     * every stock Linux — and the per-ENTRY containment below does not help
     * when the whole DIRECTORY is attacker-created, because every entry then
     * resolves neatly inside it. MEASURED on this host with `HOME` pointed at a
     * mode-1777 directory, against the build that read `path()`:
     *
     *     imported=1  refusedDirectories=[]  body='ATTACKER-MEMORY-BODY sk-live-C0FFEE'
     *                                        tagged source:claude
     *     HomeDirectory::owned() = NULL   for that same home
     *
     * — an entry a different local user wrote entering the memory store with
     * this tool's own provenance badge on it. A real launch refuses earlier, at
     * {@see \SugarCraft\Crush\Cli\Bootstrap::trustedConfigDirPath()}, and this
     * class is dormant; both were the argument this package already rejected
     * for {@see \SugarCraft\Crush\Agents\ForeignAgentPresetRegistry::userDir()},
     * which was gated in the same commit that left this line alone.
     *
     * An EXPLICIT `$claudeHome` is not gated — it is the caller naming a
     * directory rather than this class deriving one, which is what the
     * parameter is for.
     *
     * @param  string      $projectRoot Absolute project path, as Claude Code slugs it.
     * @param  string|null $claudeHome  Override for `~/.claude` (tests, non-default installs).
     * @return int Number of entries imported.
     */
    public function importClaudeCode(string $projectRoot, ?string $claudeHome = null): int
    {
        $this->refusedDirectories = [];

        if ($claudeHome === null) {
            $owned = HomeDirectory::owned();
            if ($owned === null) {
                $this->refusedDirectories['~/.claude'] = 'this process cannot establish that the home directory '
                    . 'it resolved is yours — it does not exist, or it is world-writable, or it is owned by '
                    . 'another account — so a memory tree found there would be whatever the last local user to '
                    . 'write in it put there, and its bodies go straight into the model\'s context';

                return 0;
            }

            $claudeHome = $owned . '/.claude';
        }

        $dir = rtrim($claudeHome, '/') . '/projects/' . $this->claudeProjectSlug($projectRoot) . '/memory';

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
                $meta = Frontmatter::parse($m[1]);
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
        $this->refusedDirectories = [];

        $dir = rtrim($projectRoot, '/') . '/.opencode/memory';
        $imported = 0;

        // THE ONE REPOSITORY-CHOSEN DIRECTORY THIS CLASS READS, anchored for the
        // reason {@see \SugarCraft\Crush\Agents\ForeignAgentPresetRegistry::scan()}
        // and {@see \SugarCraft\Crush\Commands\CommandLoader::loadFromDirectory()}
        // anchor theirs: `{projectRoot}/.opencode/memory` is a path a clone
        // chooses, so a committed `.opencode/memory -> <outside>` would import
        // arbitrary files into this session's memory store under a
        // `source:opencode` tag that says they came from the project. Dormant
        // (nothing constructs this class yet) is not a reason to leave it open —
        // a containment rule added when the consumer lands is one written after
        // the consumer already trusts the importer.
        //
        // The refusal NOTICE is narrower than the decision, matching
        // {@see \SugarCraft\Crush\Agents\AgentPresetRegistry::readableSearchPaths()}:
        // a directory that simply is not there is the overwhelmingly common
        // case and says nothing worth reporting, so only a directory that
        // EXISTS and resolves out is named.
        if (is_dir($dir) && !ContainedPath::below($dir, $projectRoot)) {
            $this->refusedDirectories[$dir] = sprintf(
                'resolves to %s, %s the checkout it was reached from (%s), so its files are not this '
                . "project's memory",
                (string) realpath($dir),
                realpath($projectRoot) === realpath($dir) ? 'which is exactly' : 'outside',
                $projectRoot,
            );

            return 0;
        }

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
     * Every `*.md` in $dir that still RESOLVES inside it, or an empty list when
     * the directory is absent (a user who has never run the foreign tool is the
     * common case, not an error) or unreadable (glob() returns false).
     *
     * The per-ENTRY containment is the second of the two boundaries
     * {@see importOpencode()} describes, and it applies to BOTH importers rather
     * than only the anchored one: `glob()` does not resolve symlinks, so
     * `memory/notes.md -> ~/.ssh/id_ed25519` is one committed line that would
     * otherwise land in the memory store under a `source:` tag naming the
     * project. Refusals are named through {@see refusedDirectories()}.
     *
     * @return list<string>
     */
    private function markdownFiles(string $dir): array
    {
        if (!is_dir($dir)) {
            return [];
        }

        $files = [];
        foreach (glob(rtrim($dir, '/') . '/*.md') ?: [] as $file) {
            if (!ContainedPath::within($file, $dir)) {
                $this->refusedDirectories[$file] = sprintf(
                    'resolves outside %s, the directory it was listed from, so it is not a memory entry that '
                    . 'directory holds',
                    $dir,
                );

                continue;
            }

            $files[] = $file;
        }

        return $files;
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
