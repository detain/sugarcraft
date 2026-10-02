<?php

declare(strict_types=1);
// codacy ignore tainted-filename

namespace SugarCraft\Crush\Memory;

use SugarCraft\Crush\Context\Utf8Scrub;
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
 * WIRED INTO THE RUNTIME as of P7.S6: `Chat::memoryImport()` constructs this
 * class for the `/memory import claude|opencode` chat subcommand, surfaces the
 * refusals {@see refusedDirectories()} collects into the command response, and
 * writes the one-shot sentinel the paragraph above assigns to the caller. The
 * spec's other trigger point — a first-run one-shot prompt — is still
 * unimplemented; it is named as a gap, not as a future tense of this class.
 *
 * WIRED, AND GATED. One of the two directories this class reads —
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
     * Longest project slug Claude Code writes verbatim; a longer one is cut to
     * this many characters and suffixed with a hash of the path — see
     * {@see claudeProjectSlug()}.
     */
    private const CLAUDE_SLUG_MAX_LENGTH = 200;

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
     * where <slug> is the absolute project path with every character that is
     * not an ASCII letter or digit replaced by `-` — see
     * {@see claudeProjectSlug()}. Each entry carries YAML frontmatter; a file
     * without frontmatter is not one of Claude Code's entries and is skipped
     * rather than imported as a blob.
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
      * class no longer leans on dormancy for safety — it gates at its own read;
      * "someone else refuses earlier" and "nobody constructs this class" were
      * the arguments this package already rejected for
      * {@see \SugarCraft\Crush\Agents\ForeignAgentPresetRegistry::userDir()},
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

        $dir = $this->claudeMemoryDirectory($claudeHome, $projectRoot);

        $imported = 0;

        foreach ($this->markdownFiles($dir) as $file) {
            if (basename($file) === self::INDEX_FILENAME) {
                continue;
            }

            $raw = file_get_contents($file);
            if ($raw === false) {
                continue;
            }

            // Imported notes become memory notes, which reach the system
            // prompt; and the YAML parse below refuses invalid UTF-8, which
            // dropped the whole file (audit 15d-08).
            $raw = Utf8Scrub::clean($raw);
            if (preg_match(self::FRONTMATTER_PATTERN, $raw, $m) !== 1) {
                continue;
            }

            try {
                $meta = Frontmatter::parse($m[1]);
            } catch (\Throwable $e) {
                // One unparseable foreign file must not abort the import of
                // every other entry in the directory.
                error_log("sugarcrush: ForeignMemoryImporter: skipping {$file}: {$e->getMessage()}");
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
         // `source:opencode` tag that says they came from the project. Dormancy
         // (nothing constructing this class) was never a reason to leave it
         // open — a containment rule added when the consumer lands is one
         // written after the consumer already trusts the importer. The consumer
         // landed in P7.S6; the rule was here first.
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
            $content = Utf8Scrub::clean($content);

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
     * Every `*.md` in $dir that still RESOLVES inside it, in byte order, or an
     * empty list when the directory is absent (a user who has never run the
     * foreign tool is the common case, not an error) or unreadable.
     *
     * The names come from scandir(), not `glob($dir . '/*.md')`: a glob
     * pattern cannot hold a literal path, so a checkout or home under
     * `~/work/[acme]/` turned `[acme]` into a character class and the import
     * listed nothing — or a sibling directory the class happened to match
     * (the MemoryStore half of this was audit 15d-22). What glob's `*.md`
     * gave is kept: dot-files are skipped and the list is byte-order sorted.
     *
     * The per-ENTRY containment is the second of the two boundaries
     * {@see importOpencode()} describes, and it applies to BOTH importers rather
     * than only the anchored one: a directory listing does not resolve
     * symlinks, so `memory/notes.md -> ~/.ssh/id_ed25519` is one committed line
     * that would otherwise land in the memory store under a `source:` tag
     * naming the project. Refusals are named through {@see refusedDirectories()}.
     *
     * @return list<string>
     */
    private function markdownFiles(string $dir): array
    {
        $names = is_dir($dir) ? @scandir($dir, \SCANDIR_SORT_NONE) : false;
        if ($names === false) {
            return [];
        }

        $names = array_values(array_filter(
            $names,
            static fn(string $name): bool => $name !== '' && $name[0] !== '.' && str_ends_with($name, '.md'),
        ));
        sort($names, \SORT_STRING);

        $dir = rtrim($dir, '/');
        $files = [];
        foreach ($names as $name) {
            $file = $dir . '/' . $name;
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
     * The Claude Code memory directory for $projectRoot under $claudeHome.
     *
     * Claude Code's own spelling ({@see claudeProjectSlug()}) is tried first.
     * Until audit 15d-06 this class spelled the slug by replacing only `/`, so
     * `/srv/web.site` was looked up as `-srv-web.site` while Claude Code wrote
     * `-srv-web-site`; that older spelling is still tried when the correct
     * one holds no memory directory, so a tree someone arranged by hand to
     * suit the old lookup keeps importing. It is a fallback and never a second
     * source: one directory is read per import, and a path whose two
     * spellings coincide (no `.`, `_`, space, …) is simply one directory.
     */
    private function claudeMemoryDirectory(string $claudeHome, string $projectRoot): string
    {
        $projects = rtrim($claudeHome, '/') . '/projects/';
        $dir = $projects . self::claudeProjectSlug($projectRoot) . '/memory';
        if (is_dir($dir)) {
            return $dir;
        }

        $legacy = $projects . self::legacyClaudeProjectSlug($projectRoot) . '/memory';

        return $legacy !== $dir && is_dir($legacy) ? $legacy : $dir;
    }

    /**
     * Claude Code's project-directory slug, as Claude Code 2.1.287 computes
     * it (read out of its bundle): every UTF-16 code unit of the path that is
     * not `[A-Za-z0-9]` becomes `-`, with no collapsing and no trimming. So
     * the leading `/` survives as a leading `-`, and `/x/.claude/y` gives
     * `-x--claude-y`. This host's real `~/.claude/projects/` confirms both:
     * `/home/sites/webhooks.interserver.net` is
     * `-home-sites-webhooks-interserver-net`, and a worktree under
     * `phlix-server/.claude/worktrees/` is `-…-phlix-server--claude-worktrees-…`.
     * Replacing only `/` (the spelling before audit 15d-06) missed every
     * project whose path held a `.`, `_`, space or non-ASCII character.
     *
     * Code UNITS, not bytes or code points, because that is what a JavaScript
     * regex replaces: `é` is one `-`, but a character outside the Basic
     * Multilingual Plane is a surrogate pair and becomes `--`. A path that is
     * not valid UTF-8 is dashed byte by byte — how Claude Code's runtime
     * decodes such a path was not verified, so that case may not match.
     *
     * A slug longer than {@see CLAUDE_SLUG_MAX_LENGTH} is cut there and
     * suffixed with `-` plus the base-36 absolute value of the path's 32-bit
     * Java-style string hash ({@see claudePathHash()}), as Claude Code does.
     *
     * The trailing slash is stripped first: a caller passing `/home/x/` would
     * otherwise slug to `-home-x-` and silently find no memory directory,
     * since Claude Code's working directory never carries one.
     */
    private static function claudeProjectSlug(string $projectRoot): string
    {
        $path = self::withoutTrailingSlash($projectRoot);

        $slug = preg_match('//u', $path) === 1
            ? (string) preg_replace_callback(
                '/[^A-Za-z0-9]/u',
                // A 4-byte UTF-8 sequence is exactly a supplementary-plane
                // character, i.e. a surrogate pair in UTF-16.
                static fn(array $m): string => strlen($m[0]) === 4 ? '--' : '-',
                $path,
            )
            : (string) preg_replace('/[^A-Za-z0-9]/', '-', $path);

        if (strlen($slug) <= self::CLAUDE_SLUG_MAX_LENGTH) {
            return $slug;
        }

        return substr($slug, 0, self::CLAUDE_SLUG_MAX_LENGTH) . '-' . self::claudePathHash($path);
    }

    /**
     * Claude Code's long-slug suffix: `Math.abs(h).toString(36)` where `h` is
     * the 32-bit `h = h * 31 + unit` hash over the path's UTF-16 code units
     * (the bytes, for a path that is not valid UTF-8 — unverified, see
     * {@see claudeProjectSlug()}).
     */
    private static function claudePathHash(string $path): string
    {
        $units = [];
        if (preg_match('//u', $path) === 1) {
            foreach (mb_str_split($path, 1, 'UTF-8') as $char) {
                $codePoint = (int) mb_ord($char, 'UTF-8');
                if ($codePoint < 0x10000) {
                    $units[] = $codePoint;
                    continue;
                }
                $codePoint -= 0x10000;
                $units[] = 0xD800 | ($codePoint >> 10);
                $units[] = 0xDC00 | ($codePoint & 0x3FF);
            }
        } else {
            foreach (str_split($path) as $byte) {
                $units[] = ord($byte);
            }
        }

        // `(h << 5) - h + unit | 0` in JavaScript: the same value mod 2^32,
        // re-signed below the way `| 0` reads it.
        $hash = 0;
        foreach ($units as $unit) {
            $hash = (($hash << 5) - $hash + $unit) & 0xFFFFFFFF;
        }
        if ($hash >= 0x80000000) {
            $hash -= 0x100000000;
        }

        return base_convert((string) abs($hash), 10, 36);
    }

    /**
     * The slug this class looked for before audit 15d-06 — only `/` turned
     * into `-` — kept solely as {@see claudeMemoryDirectory()}'s fallback.
     */
    private static function legacyClaudeProjectSlug(string $projectRoot): string
    {
        return '-' . ltrim(str_replace('/', '-', self::withoutTrailingSlash($projectRoot)), '-');
    }

    /**
     * $path without trailing `/`, except that the filesystem root stays `/`
     * (Claude Code slugs a session started at `/` as `-`).
     */
    private static function withoutTrailingSlash(string $path): string
    {
        $trimmed = rtrim($path, '/');

        return $trimmed === '' && $path !== '' ? '/' : $trimmed;
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
