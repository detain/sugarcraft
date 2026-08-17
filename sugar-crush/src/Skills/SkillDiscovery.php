<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Skills;

use SugarCraft\Crush\Support\HomeDirectory;

/**
 * Discovers skill directories across project, user, and per-lib search paths.
 *
 * Search paths (in priority order, highest last):
 *   1. Project skills:   {projectRoot}/.sugar-crush/skills/
 *   2. User skills:      ~/.sugar-crush/skills/
 *   3. Per-lib skills:   {libPath}/.sugar-crush/skills/  (for each lib in $libPaths)
 *
 * discoverAll() merges all three sources with later paths overriding earlier
 * when the same skill name is found in multiple locations.
 *
 * DORMANT SEAM, kept and completed rather than dropped: nothing in `src/` or
 * `bin/` calls this class today — {@see SkillManager::loadAll()} is what the
 * live CLI runs, through {@see SkillLoader} — so this is a lookup surface
 * waiting for a caller (a doctor report, a `/skills` listing that wants paths
 * rather than parsed skills). It walks the same trees through
 * {@see SkillLoader::skillDirectoriesIn()} precisely because it is unwired: a
 * second, uncontained walk sitting here is how a future caller inherits an
 * escape that the loader closed.
 */
final class SkillDiscovery
{
    private const PROJECT_SUBDIR = '.sugar-crush/skills';
    private const USER_SUBDIR = '.sugar-crush/skills';

    public function __construct(private readonly SkillLoader $loader = new SkillLoader()) {}

    /**
     * Every skill file this discovery's walks gave up on, keyed by path —
     * {@see SkillLoader::skipped()} for the instance behind them, including the
     * symlinks refused for resolving outside the tree they were reached from.
     *
     * @return array<string, string>
     */
    public function skipped(): array
    {
        return $this->loader->skipped();
    }

    /**
     * Every skills directory these walks refused wholesale, keyed by path —
     * {@see SkillLoader::refusedDirectories()} for the instance behind them.
     *
     * Exposed alongside {@see skipped()} so the future caller this dormant seam
     * is waiting for inherits both halves of the diagnostic rather than only the
     * per-file one.
     *
     * @return array<string, string>
     */
    public function refusedDirectories(): array
    {
        return $this->loader->refusedDirectories();
    }

    /**
     * Discover project-level skills under {projectRoot}/.sugar-crush/skills/.
     *
     * @return array<string> absolute skill directory paths
     */
    public function discoverProjectSkills(string $projectRoot): array
    {
        $path = $this->buildProjectPath($projectRoot);

        // The root is the anchor the directory itself is held inside, not just
        // the thing the path was built from — see
        // {@see SkillLoader::skillFilesIn()}'s $anchoredIn. A dormant seam
        // inheriting the escape the live one closed is exactly what this class's
        // own doc-block warns about.
        return $this->discoverSkillsAt($path, null, $projectRoot);
    }

    /**
     * Discover user-level skills under ~/.sugar-crush/skills/.
     *
     * @return array<string> absolute skill directory paths
     */
    public function discoverUserSkills(): array
    {
        $path = self::homeDir() . '/' . self::USER_SUBDIR;

        // The user's own tree, so its links may reach the rest of the user's
        // home — the same $ownedBy widening {@see SkillLoader::loadUserSkills()}
        // spells out, and for the same real layout (skills linked in from a
        // shared checkout elsewhere under `~`).
        return $this->discoverSkillsAt($path, self::homeDir());
    }

    /**
     * This user's home directory — see {@see HomeDirectory}, the one
     * resolution every `~`-rooted path in this package goes through.
     */
    private static function homeDir(): string
    {
        return HomeDirectory::path();
    }

    /**
     * Discover per-lib nested skills under {libPath}/.sugar-crush/skills/.
     *
     * @return array<string> absolute skill directory paths
     */
    public function discoverLibSkills(string $libPath): array
    {
        $path = rtrim($libPath, '/') . '/.sugar-crush/skills';

        // A lib path is a checkout too — a vendored dependency is somebody
        // else's repository content, which is the same provenance the project
        // tree's anchor exists for.
        return $this->discoverSkillsAt($path, null, $libPath);
    }

    /**
     * Discover all skills across project, user, and lib search paths.
     *
     * Priority order (later overrides earlier on name conflicts):
     *   1. Project skills
     *   2. User skills
     *   3. Per-lib skills (in the order provided by $libPaths)
     *
     * @param array<string> $libPaths   ordered list of library paths to scan for nested skills
     * @param string        $projectRoot root path for project skills (default '.')
     * @return array<string>           skill name => absolute path (deduplicated)
     */
    public function discoverAll(array $libPaths = [], string $projectRoot = '.'): array
    {
        $discovered = [];

        // 1. Project skills (lowest priority)
        foreach ($this->discoverProjectSkills($projectRoot) as $path) {
            $discovered[$this->skillNameFromPath($path)] = $path;
        }

        // 2. User skills (override project)
        foreach ($this->discoverUserSkills() as $path) {
            $discovered[$this->skillNameFromPath($path)] = $path;
        }

        // 3. Per-lib skills (highest priority, in given order)
        foreach ($libPaths as $libPath) {
            foreach ($this->discoverLibSkills($libPath) as $path) {
                $discovered[$this->skillNameFromPath($path)] = $path;
            }
        }

        return $discovered;
    }

    /**
     * Resolve the absolute path for a named skill by searching through $searchPaths.
     *
     * Returns null when the skill is not found in any of the provided search paths.
     *
     * CONTAINED, THROUGH THE SAME WALK THE REST OF THIS CLASS USES. It used to
     * concatenate — `rtrim($basePath,'/') . '/' . $skillName` — and ask
     * `is_dir()`, which is the escape this class's own doc-comment warns a
     * future caller would inherit, one method below it: a `$skillName` of
     * `'../../..'` names a directory outside the search path and `is_dir()`
     * agreed, and `skills/escape -> $HOME` was resolved as a skill because
     * `is_dir()` stats THROUGH a symlink. Matching a name against what
     * {@see discoverSkillsAt()} found cannot leave the tree: a basename holds
     * no separator, and the walk has already refused the links that resolve
     * outside.
     *
     * TWO CONSEQUENCES OF ROUTING IT, both shared with {@see discoverSkillsAt()}:
     * a directory holding no `SKILL.md` is not a skill and no longer resolves,
     * and the path handed back is canonical. Containment is per $basePath —
     * there is no `$ownedBy` widening here, because a caller passing arbitrary
     * search paths has not said any of them is the user's own tree the way
     * {@see discoverUserSkills()} does.
     *
     * @param string        $skillName   simple skill name (e.g. "my-skill")
     * @param array<string> $searchPaths absolute paths to scan for skill directories
     * @return string|null              absolute path to the skill directory, or null if not found
     */
    public function resolveSkillPath(string $skillName, array $searchPaths): ?string
    {
        foreach ($searchPaths as $basePath) {
            foreach ($this->discoverSkillsAt($basePath) as $candidate) {
                if ($this->skillNameFromPath($candidate) === $skillName) {
                    return $candidate;
                }
            }
        }

        return null;
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    private function buildProjectPath(string $projectRoot): string
    {
        return rtrim($projectRoot, '/') . '/' . self::PROJECT_SUBDIR;
    }

    /**
     * Scan a base skills directory and return absolute paths to each skill
     * directory under it.
     *
     * ROUTED THROUGH {@see SkillLoader::skillDirectoriesIn()} rather than
     * walked here. The walk this replaced returned every `isDir()` entry, and
     * `isDir()` stats THROUGH a symlink with no containment check at all, so
     * `{repo}/.sugar-crush/skills/escape -> $HOME` was returned as a skill
     * directory — the escape {@see SkillLoader::contained()} exists to refuse,
     * re-implemented one class over. The loader's walk is also what decides
     * that a directory holding no `SKILL.md` is not a skill, which is the one
     * behavioural difference: an empty subdirectory is no longer reported.
     *
     * Its `realpath()` diagnostic is not lost with it — a link that will not
     * resolve is recorded by the loader and readable from {@see skipped()},
     * which is where it belongs: `error_log()` writes to stderr, and this is
     * reachable from a path where the TUI owns the screen.
     *
     * @param string|null $ownedBy   widens containment to a second root, for a
     *        tree whose links are the user's own; null confines the walk
     * @param string|null $anchoredIn the checkout $path must itself resolve
     *        strictly inside; null for a directory whose location no repository
     *        chose ({@see SkillLoader::skillFilesIn()})
     *
     * @return array<string>
     */
    private function discoverSkillsAt(string $path, ?string $ownedBy = null, ?string $anchoredIn = null): array
    {
        return $this->loader->skillDirectoriesIn($path, $ownedBy, $anchoredIn);
    }

    /**
     * Extract the skill name (directory basename) from an absolute skill path.
     */
    private function skillNameFromPath(string $path): string
    {
        return basename($path);
    }
}
