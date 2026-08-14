<?php

declare(strict_types=1);
// codacy ignore tainted-filename

namespace SugarCraft\Crush\Skills;

use SugarCraft\Crush\Support\HomeDirectory;
use Symfony\Component\Yaml\Yaml;

/**
 * Loads skills in three stages:
 * 1. name/description only at startup (loadSkillManifest)
 * 2. full SKILL.md body when task matches (loadSkillBody)
 * 3. scripts/references/assets subdirectories only when actually needed (loadSkillAsset)
 *
 * Supports context-fork mode (spawn sub-agent with no access to calling conversation).
 */
final class SkillLoader
{
    private const FRONTMATTER_PATTERN = '/^---\s*\n(.*?)\n---\s*\n/s';
    private const ASSET_SUBDIRS = ['scripts', 'references', 'assets'];

    /**
     * The env var that puts the skips back on stderr — see {@see recordSkip()}
     * for why they are off it by default.
     */
    public const DEBUG_SKIPS_ENV = 'SUGARCRUSH_DEBUG_SKILLS';

    /** @var array<string, string> sourcePath => why it was skipped */
    private array $skipped = [];

    /**
     * @param bool|null $reportSkips Force skip reporting on (true) or off
     *        (false) for this loader; null — the default every production
     *        caller uses — lets {@see DEBUG_SKIPS_ENV} decide.
     */
    public function __construct(private readonly ?bool $reportSkips = null) {}

    /**
     * Load all skills from a directory.
     *
     * @param string|null $ownedBy See {@see skillFilesIn()}: the extra
     *        directory a symlink inside $dir is allowed to resolve into,
     *        because the same person owns both. Null — the default, and what
     *        every PROJECT tree must use — confines the walk to $dir itself.
     *
     * @return array<string, Skill>
     */
    public function loadFromDirectory(string $dir, ?string $ownedBy = null): array
    {
        $skills = [];

        foreach ($this->skillFilesIn($dir, $ownedBy) as $path) {
            try {
                $skill = Skill::fromFile($path);
                $skillName = $this->skillKeyFor($dir, $path, $skill->name);
                $skill = $skill->withName($skillName);
                $skills[$skill->name] = $skill;
            } catch (\Throwable $e) {
                $this->recordSkip($path, $e->getMessage());
            }
        }

        return $skills;
    }

    /**
     * Every skill this loader could not read, keyed by the SKILL.md path it
     * gave up on — {@see recordSkip()}'s diagnostic, kept where a caller can
     * ask for it instead of thrown at stderr.
     *
     * @return array<string, string>
     */
    public function skipped(): array
    {
        return $this->skipped;
    }

    /**
     * Note an unreadable SKILL.md and move on.
     *
     * QUIET BY DEFAULT, and that changed when the foreign trees went live.
     * These files are OTHER TOOLS' — {@see ForeignSkillDiscovery} walks
     * `~/.claude/skills` and `~/.config/opencode/skills` — so "fix your
     * SKILL.md" is not advice the user of this CLI can act on, and one
     * unparseable third-party skill meant an `error_log()` line (i.e. stderr)
     * on EVERY launch. Stderr is not free here: the TUI renders to stdout under
     * an alt screen, and a skill scan also happens mid-session on the Ctrl+P
     * provider switch, so the line lands inside a frame the renderer believes
     * it owns — the same hazard
     * {@see \SugarCraft\Crush\Hooks\HookConfig::loadFromFile()} `@`-silences
     * its own read for.
     *
     * The diagnostic is kept rather than dropped: every skip is readable from
     * {@see skipped()}, and `SUGARCRUSH_DEBUG_SKILLS=1` puts the lines back on
     * stderr for whoever is actually debugging a missing skill.
     */
    private function recordSkip(string $path, string $reason): void
    {
        $this->skipped[$path] = $reason;

        $report = $this->reportSkips ?? self::debugSkipsRequested();
        if ($report) {
            error_log("Failed to load skill from {$path}: {$reason}");
        }
    }

    private static function debugSkipsRequested(): bool
    {
        $value = getenv(self::DEBUG_SKIPS_ENV);

        // Same flag-variable convention every other SUGARCRUSH_* switch uses:
        // unset, empty and `0` all read as off.
        return $value !== false && $value !== '' && $value !== '0';
    }

    /**
     * How deep below a skill root the walk will descend.
     *
     * A skill lives at `<root>/<name>/SKILL.md`, or one nesting level further
     * for the grouped layouts {@see skillKeyFor()} builds `a/b` keys from, so
     * real trees are two or three levels deep. The bound exists because a
     * single symlink can graft a tree of ANY depth on: without it, a link to a
     * large directory turned a launch into a full recursive stat of it.
     */
    private const MAX_DEPTH = 6;

    /**
     * How many directories one walk will visit before it stops descending.
     *
     * Same reason as {@see MAX_DEPTH}, for breadth rather than depth: a
     * `skills/x -> /usr/share` link cost 8.29s on one measured launch and
     * `-> /` is unbounded. A real skills tree is tens of directories, so this
     * is two orders of magnitude of headroom and is only ever reached by
     * something that is not a skills tree.
     */
    private const MAX_DIRECTORIES = 2000;

    /**
     * Every `SKILL.md` under $dir, symlinks followed but CONFINED, each real
     * directory visited once.
     *
     * FOLLOWING SYMLINKS IS THE POINT. The walk used to be a
     * `RecursiveDirectoryIterator` without `FOLLOW_SYMLINKS`, which silently
     * skips a skill directory that is a link — and linking skills in from a
     * shared checkout is how the tools this loader now imports from are
     * commonly laid out (on the box this was found on, 8 of 14
     * `~/.config/opencode/skills` entries were links into
     * `~/.config/skillshare/skills`, and NONE were discovered). The damage was
     * not only missing skills: {@see SkillManager::loadAll()} documents
     * opencode as winning a name collision with Claude, and with half of one
     * tree invisible the winner depended on filesystem shape rather than on
     * the documented order.
     *
     * CONFINEMENT IS THE OTHER HALF, and without it following links was a file
     * disclosure. `$entry->isDir()` stats THROUGH a link and the `$seen` set
     * stops cycles but not ESCAPE, so a cloned repository carrying
     * `.claude/skills/escape -> $HOME` had this walk register a skill whose
     * BODY was a file from the user's home directory — and a skill body is
     * prompt context the model reads. Git stores symlinks, so that is
     * attacker-controlled content arriving with `git clone`, the same threat
     * class {@see \SugarCraft\Crush\Cli\Bootstrap::hookFiles()} gates the
     * project hook file for, and it needs no opt-in to reach. Every resolved
     * path is therefore required to stay inside the tree it was reached from;
     * one that does not is recorded as a skip and not walked.
     *
     * $ownedBy WIDENS that boundary to a second directory, and exists for
     * exactly the real layout above: `~/.config/opencode/skills/db-query`
     * points OUT of the skills tree and into `~/.config/skillshare`, so
     * confining a user tree to itself would revert the fix that made those
     * eight skills visible. The distinction it encodes is WHO WROTE THE LINK:
     * a link in the user's own `~/.claude/skills` is the user's, and may reach
     * anywhere else in the user's home; a link in `{project}/.claude/skills`
     * arrived with whatever was cloned, gets no widening, and cannot leave the
     * skills directory. It is a caller-supplied argument rather than something
     * inferred from the path because a checkout under `~/src` is inside the
     * home directory too — "is it in HOME" would hand every cloned repo the
     * user's own trust level.
     *
     * `$seen` is keyed by `realpath()` because following links reintroduces
     * cycles the plain walk could not have: `skills/a -> ..` is a directory
     * tree with no bottom. Visiting each real directory once terminates on any
     * such loop and also stops a skill reachable by two paths being read twice.
     * Keying by the WALKED path instead would not terminate — the two spellings
     * of one directory are different strings.
     *
     * The result is SORTED so that two SKILL.md files competing for one
     * registry key resolve the same way on every machine — readdir order is
     * not a contract.
     *
     * @param string|null $ownedBy an additional containment root, for a tree
     *        whose links are the user's own; null confines the walk to $dir
     *
     * @return list<string>
     */
    private function skillFilesIn(string $dir, ?string $ownedBy = null): array
    {
        $root = realpath($dir);
        if ($root === false || !is_dir($dir)) {
            return [];
        }

        $boundaries = [$root];
        $owner = $ownedBy === null ? false : realpath($ownedBy);
        if ($owner !== false) {
            $boundaries[] = $owner;
        }

        $found = [];
        $seen = [];
        $visited = 0;

        // Seeded with $dir AS SPELLED, not with its realpath: every path this
        // returns is fed back to {@see skillKeyFor()}, which derives the
        // registry key by slicing $dir off the front of it. Canonicalising the
        // seed would silently change every key on a tree named through a
        // symlink or a `..`. Containment is decided against $root instead.
        /** @var list<array{0: string, 1: int}> $pending path => depth below $dir */
        $pending = [[$dir, 0]];

        while ($pending !== []) {
            [$current, $depth] = array_pop($pending);

            $real = realpath($current);
            if ($real === false || isset($seen[$real])) {
                continue;
            }

            $seen[$real] = true;

            if (++$visited > self::MAX_DIRECTORIES) {
                $this->recordSkip(
                    $current,
                    'skill tree stopped after ' . self::MAX_DIRECTORIES
                    . ' directories; a symlink is grafting something much larger than a skills tree onto it',
                );
                break;
            }

            try {
                $entries = new \DirectoryIterator($current);
            } catch (\Throwable $e) {
                // An unreadable subdirectory is one skill tree branch missing,
                // not a reason to abandon the ones already found.
                $this->recordSkip($current, $e->getMessage());
                continue;
            }

            foreach ($entries as $entry) {
                if ($entry->isDot()) {
                    continue;
                }

                $path = $entry->getPathname();

                // isDir()/isFile() stat through a link, which is what makes a
                // symlinked skill directory walkable at all; getPathname()
                // stays the path UNDER $dir, so skillKeyFor()'s relative-path
                // key is still computed against the tree the user named.
                if ($entry->isDir()) {
                    if (!$this->contained($path, $boundaries)) {
                        continue;
                    }

                    if ($depth + 1 > self::MAX_DEPTH) {
                        $this->recordSkip(
                            $path,
                            'skill tree is deeper than ' . self::MAX_DEPTH . ' levels; not descending further',
                        );
                        continue;
                    }

                    $pending[] = [$path, $depth + 1];
                    continue;
                }

                // The file is checked as well as the directory: a bare
                // `SKILL.md -> ~/.ssh/id_rsa` at the top level never passes
                // through the directory arm above.
                if ($entry->isFile() && $entry->getBasename() === 'SKILL.md' && $this->contained($path, $boundaries)) {
                    $found[] = $path;
                }
            }
        }

        sort($found);

        return $found;
    }

    /**
     * Every directory under $dir that actually holds a SKILL.md, from the same
     * confined walk {@see skillFilesIn()} performs.
     *
     * The public seam {@see SkillDiscovery} uses. It had its own
     * `DirectoryIterator` walk that returned every `isDir()` entry — and
     * `isDir()` stats THROUGH a symlink — so `{repo}/.sugar-crush/skills/escape
     * -> $HOME` came back as a skill directory: the identical escape the
     * containment check in this class was written to close, in a second
     * implementation of the same walk. One containment algorithm is the point;
     * two is how the second one stays wrong.
     *
     * Canonical paths, because that is what the walker it replaced returned
     * (it `realpath()`ed the base directory and iterated that), and because a
     * caller keying by basename should not get two spellings of one directory.
     *
     * @param string|null $ownedBy See {@see skillFilesIn()}.
     *
     * @return list<string>
     */
    public function skillDirectoriesIn(string $dir, ?string $ownedBy = null): array
    {
        $dirs = [];

        foreach ($this->skillFilesIn($dir, $ownedBy) as $file) {
            $real = realpath(\dirname($file));
            if ($real !== false) {
                $dirs[$real] = true;
            }
        }

        return array_keys($dirs);
    }

    /**
     * Whether $path resolves inside one of $boundaries.
     *
     * A path that will not resolve at all is NOT contained: `realpath()`
     * answers false for a dangling link and for a target this process cannot
     * reach, and neither is something to walk. Refused paths are recorded as
     * skips by the caller's own arm rather than here, so the diagnostic names
     * the escape rather than the check.
     *
     * @param list<string> $boundaries already-canonical containment roots
     */
    private function contained(string $path, array $boundaries): bool
    {
        $real = realpath($path);
        if ($real === false) {
            return false;
        }

        foreach ($boundaries as $boundary) {
            // The trailing separator on both sides is what stops `/a/b` being
            // read as containing `/a/bevil` — the prefix match that would have
            // made the boundary decorative.
            if ($real === $boundary || str_starts_with($real . '/', rtrim($boundary, '/') . '/')) {
                return true;
            }
        }

        $this->recordSkip(
            $path,
            "resolves to {$real}, outside the skill tree it was reached from; "
            . 'a symlink out of a skills directory would put an unrelated file in the model'
            . "'s prompt context",
        );

        return false;
    }

    /**
     * Load user skills from ~/.sugar-crush/skills/.
     *
     * @return array<string, Skill>
     */
    public function loadUserSkills(): array
    {
        // The user's own tree, so its links may reach the rest of the user's
        // home — see {@see skillFilesIn()}'s $ownedBy for why that widening is
        // spelled out here rather than inferred from the path.
        return $this->loadFromDirectory($this->userSkillsDir(), self::homeDir());
    }

    /**
     * Load project skills from .sugar-crush/skills/.
     *
     * @return array<string, Skill>
     */
    public function loadProjectSkills(string $projectRoot): array
    {
        return $this->loadFromDirectory($this->projectSkillsDir($projectRoot));
    }

    /**
     * Load built-in skills.
     *
     * @return array<string, Skill>
     */
    public function loadBuiltInSkills(): array
    {
        return $this->loadFromDirectory($this->builtInSkillsDir());
    }

    /** ~/.sugar-crush/skills — shared by the eager and manifest-only loaders. */
    private function userSkillsDir(): string
    {
        return self::homeDir() . '/.sugar-crush/skills';
    }

    /**
     * This user's home directory — {@see HomeDirectory} is the ONE resolution
     * every `~`-rooted path in this package goes through, and its doc-block
     * carries the full argument for why two of them was a production bug.
     */
    private static function homeDir(): string
    {
        return HomeDirectory::path();
    }

    /** <projectRoot>/.sugar-crush/skills — shared by the eager and manifest-only loaders. */
    private function projectSkillsDir(string $projectRoot): string
    {
        return rtrim($projectRoot, '/') . '/.sugar-crush/skills';
    }

    /** src/Skills/BuiltIn — shared by the eager and manifest-only loaders. */
    private function builtInSkillsDir(): string
    {
        $reflection = new \ReflectionClass($this);

        return dirname($reflection->getFileName()) . '/BuiltIn';
    }

    /**
     * Compute a discovered skill's registry key the same way for both the
     * eager (loadFromDirectory) and manifest-only (loadManifestsFromDirectory)
     * walkers: a skill nested more than one level under $baseDir is keyed by
     * its path relative to $baseDir (so sibling skills sharing a leaf dirname
     * don't collide); a top-level skill keeps its own name.
     */
    private function skillKeyFor(string $baseDir, string $skillFilePath, string $fallbackName): string
    {
        $relativePath = substr($skillFilePath, strlen($baseDir) + 1);
        $relativeSkillDir = dirname($relativePath);

        return $relativeSkillDir === '.' ? $fallbackName : $relativeSkillDir;
    }

    /**
     * Load skills from multiple sources.
     *
     * Priority order: built-in < user < project (later sources override
     * earlier). Foreign (.claude/.opencode) skills are merged one layer up, in
     * {@see SkillManager::loadAll()}, where a {@see SkillSource} tag has
     * somewhere to live -- see {@see loadAllManifests()} for the full argument
     * and for why a native name wins the collision.
     *
     * @return array<string, Skill>
     */
    public function loadAll(string $projectRoot = '.'): array
    {
        // Built-in first (lowest priority)
        $builtin = $this->loadBuiltInSkills();

        // User skills override builtins
        $user = $this->loadUserSkills();
        $skills = array_merge($builtin, $user);

        // Project skills override both
        $project = $this->loadProjectSkills($projectRoot);
        $skills = array_merge($skills, $project);

        return $skills;
    }

    // -------------------------------------------------------------------------
    // Staged Loading Methods
    // -------------------------------------------------------------------------

    /**
     * Stage 1: Load only name + description frontmatter from SKILL.md.
     *
     * Returns a manifest array with:
     *   - name: skill directory name
     *   - description: from frontmatter or "Skill: $name"
     *   - disableModelInvocation: bool
     *   - userInvocable: bool
     *   - context: 'thread' or 'fork' (context-fork mode)
     *   - paths: glob patterns for path-based auto-scoping (SkillRegistry::getForPaths())
     *   - sourcePath: absolute path to SKILL.md
     *
     * paths comes from frontmatter (already parsed above), so surfacing it
     * here doesn't cost the body-read this stage exists to avoid.
     *
     * @return array{name: string, description: string, disableModelInvocation: bool, userInvocable: bool, context: string, paths: array<string>, sourcePath: string}
     */
    public function loadSkillManifest(string $skillDir): array
    {
        $skillPath = rtrim($skillDir, '/') . '/SKILL.md';

        if (!is_file($skillPath)) {
            throw new \RuntimeException("SKILL.md not found in: $skillDir");
        }

        $content = file_get_contents($skillPath);
        if ($content === false) {
            throw new \RuntimeException("Failed to read skill manifest: $skillPath");
        }

        // Parse frontmatter only (stage 1 - don't load body)
        if (preg_match(self::FRONTMATTER_PATTERN, $content, $matches)) {
            $frontmatter = Yaml::parse($matches[1]);
        } else {
            $frontmatter = [];
        }

        $name = basename($skillDir);

        return [
            'name' => $name,
            'description' => $frontmatter['description'] ?? "Skill: $name",
            'disableModelInvocation' => (bool)($frontmatter['disable-model-invocation'] ?? false),
            'userInvocable' => (bool)($frontmatter['user-invocable'] ?? true),
            'context' => $frontmatter['context'] ?? 'thread',
            'paths' => $frontmatter['paths'] ?? [],
            'sourcePath' => realpath($skillPath) ?: $skillPath,
        ];
    }

    /**
     * Stage-1 equivalent of loadFromDirectory(): walks the same directory
     * tree for SKILL.md files, but loads only each one's manifest
     * (loadSkillManifest()) instead of the full Skill (Skill::fromFile()),
     * so no body content is read from disk at this stage.
     *
     * @param string|null $ownedBy See {@see loadFromDirectory()}.
     *
     * @return array<string, array{name: string, description: string, disableModelInvocation: bool, userInvocable: bool, context: string, paths: array<string>, sourcePath: string}>
     */
    public function loadManifestsFromDirectory(string $dir, ?string $ownedBy = null): array
    {
        $manifests = [];

        foreach ($this->skillFilesIn($dir, $ownedBy) as $path) {
            try {
                $manifest = $this->loadSkillManifest(dirname($path));
                $manifest['name'] = $this->skillKeyFor($dir, $path, $manifest['name']);
                $manifests[$manifest['name']] = $manifest;
            } catch (\Throwable $e) {
                // Same skip contract as loadFromDirectory(), through the same
                // recorder -- see recordSkip().
                $this->recordSkip($path, $e->getMessage());
            }
        }

        return $manifests;
    }

    /**
     * Stage-1 equivalent of loadAll(): discovers every skill across the same
     * sources and priority order (built-in < user < project, later
     * overrides earlier) but loads only each one's manifest, not its body.
     *
     * Fixes the defect described in crush_feat.md section 7.E3: every
     * ReactPHP-loop session used to pay the full I/O + YAML-parse cost of
     * every built-in/user/project skill's body at startup even when zero
     * skills were invoked that session, defeating the point of the
     * already-designed three-stage progressive disclosure. The body is
     * designed to backfill just-in-time via loadSkillBody(), called from
     * Tools\BuiltIn\SkillTool::execute() -- but that tool is not yet
     * registered into Bootstrap::tools()/EngineBackend, so the backfill
     * half is implemented and tested, not yet reachable from
     * bin/sugarcrush (tracked separately: crush_feat.md section 7 item 2 /
     * W3.S8).
     *
     * Foreign-imported skills (.claude/skills, .opencode/skills) are NATIVE
     * SOURCES' business only in the sense that they are merged one layer up:
     * {@see SkillManager::loadAll()} calls {@see ForeignSkillDiscovery} and
     * registers its results BEFORE these manifests, so a native name wins a
     * collision. They are not merged here because a foreign skill carries a
     * {@see SkillSource} tag and a manifest array has nowhere to put one --
     * see SkillManager::loadAll()'s doc-block for the full argument.
     *
     * @return array<string, array{name: string, description: string, disableModelInvocation: bool, userInvocable: bool, context: string, paths: array<string>, sourcePath: string}>
     */
    public function loadAllManifests(string $projectRoot = '.'): array
    {
        // Built-in overrides nothing (lowest priority)
        $manifests = $this->loadManifestsFromDirectory($this->builtInSkillsDir());

        // User skills override builtins
        // Same $ownedBy widening the eager walk gets — see {@see loadUserSkills()}.
        $manifests = array_merge($manifests, $this->loadManifestsFromDirectory($this->userSkillsDir(), self::homeDir()));

        // Project skills override everything else
        $manifests = array_merge($manifests, $this->loadManifestsFromDirectory($this->projectSkillsDir($projectRoot)));

        return $manifests;
    }

    /**
     * Stage 2: Load full SKILL.md body content.
     *
     * Returns the content after frontmatter, trimmed.
     */
    public function loadSkillBody(string $skillPath): string
    {
        if (!is_file($skillPath)) {
            throw new \RuntimeException("Skill file not found: $skillPath");
        }

        $content = file_get_contents($skillPath);
        if ($content === false) {
            throw new \RuntimeException("Failed to read skill body: $skillPath");
        }

        // Strip frontmatter to get body
        if (preg_match(self::FRONTMATTER_PATTERN, $content, $matches)) {
            $body = substr($content, strlen($matches[0]));
        } else {
            $body = $content;
        }

        return trim($body);
    }

    /**
     * Stage 3: Load a file from scripts/references/assets subdirectories.
     *
     * @param string $skillPath Absolute path to the skill's SKILL.md
     * @param string $relativePath Relative path within the skill's subdirectories (must be within scripts/references/assets)
     * @return string The file contents
     */
    public function loadSkillAsset(string $skillPath, string $relativePath): string
    {
        $skillDir = dirname($skillPath);
        $assetPath = $skillDir . '/' . $relativePath;

        // Validate relativePath is within allowed subdirectories
        $firstComponent = explode('/', ltrim($relativePath, '/'))[0];
        if (!in_array($firstComponent, self::ASSET_SUBDIRS, true)) {
            throw new \RuntimeException(
                "Asset path must be within " . implode('/', self::ASSET_SUBDIRS) . " subdirectory: $relativePath"
            );
        }

        // Security: ensure the path is within the skill directory
        $realSkillDir = realpath($skillDir);
        $realAssetPath = realpath($assetPath);

        if ($realSkillDir === false) {
            throw new \RuntimeException("Invalid skill path: $skillPath");
        }

        if ($realAssetPath === false) {
            throw new \RuntimeException("Asset path does not exist: $relativePath");
        }

        // Must be within skill directory (no path traversal)
        if (!str_starts_with($realAssetPath . '/', $realSkillDir . '/')) {
            throw new \RuntimeException("Asset path escapes skill directory: $relativePath");
        }

        if (!is_file($assetPath)) {
            throw new \RuntimeException("Asset not found: $assetPath");
        }

        $content = file_get_contents($assetPath);
        if ($content === false) {
            throw new \RuntimeException("Failed to read asset: $assetPath");
        }

        return $content;
    }
}
