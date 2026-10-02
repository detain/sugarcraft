<?php

declare(strict_types=1);
// codacy ignore tainted-filename

namespace SugarCraft\Crush\Skills;

use SugarCraft\Crush\Context\Utf8Scrub;
use SugarCraft\Crush\Support\ContainedPath;
use SugarCraft\Crush\Support\Frontmatter;
use SugarCraft\Crush\Support\HomeDirectory;

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
     * @var array<string, string> skills directory => why the whole tree was
     *      refused, kept apart from {@see $skipped} on purpose: the one-line
     *      launch notice built off that array says "N skill files could not be
     *      read", and a refused DIRECTORY is not an unreadable file. Reporting
     *      it through the same channel would have made a true message false.
     */
    private array $refusedDirectories = [];

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
     * @param string|null $anchoredIn See {@see skillFilesIn()}: the checkout
     *        $dir was derived from, which $dir ITSELF must resolve strictly
     *        inside. Every project tree must pass one; null means "this is not a
     *        directory a repository chose the location of".
     *
     * @return array<string, Skill>
     */
    public function loadFromDirectory(string $dir, ?string $ownedBy = null, ?string $anchoredIn = null): array
    {
        $skills = [];

        foreach ($this->skillFilesIn($dir, $ownedBy, $anchoredIn) as $path) {
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
     * Every skills DIRECTORY this loader refused to walk at all, keyed by the
     * path as it was spelled, mapped to why.
     *
     * Separate from {@see skipped()} because it is a different kind of answer: a
     * skip is one file this loader could not parse, and this is a whole tree it
     * declined to enter because a repository had moved it. Surfaced at launch by
     * {@see \SugarCraft\Crush\Cli\Bootstrap::reportProjectTierRefusals()},
     * alongside the workflow tier's equivalent — the two refusals are the same
     * event in two subsystems and a user who hits one has no reason to care
     * which class noticed.
     *
     * @return array<string, string>
     */
    public function refusedDirectories(): array
    {
        return $this->refusedDirectories;
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
     *
     * Public for exactly one outside caller: {@see SkillManager::loadAll()},
     * whose registration of a loaded manifest is the last step that can fail on
     * a repository's file, and whose failure belongs in the same {@see skipped()}
     * list the launch notice reads rather than in a second one.
     *
     * @internal
     */
    public function recordSkip(string $path, string $reason): void
    {
        $this->skipped[$path] = $reason;

        $report = $this->reportSkips ?? self::debugSkipsRequested();
        if ($report) {
            error_log("sugarcrush: Failed to load skill from {$path}: {$reason}");
        }
    }

    /**
     * Note a skills directory refused wholesale and walk nothing in it.
     *
     * Same quiet-by-default contract as {@see recordSkip()} — the reasoning
     * about stderr landing inside a frame the renderer owns applies identically —
     * into a separate array for the reason {@see $refusedDirectories} states.
     */
    private function recordRefusedDirectory(string $dir, string $reason): void
    {
        $this->refusedDirectories[$dir] = $reason;

        $report = $this->reportSkips ?? self::debugSkipsRequested();
        if ($report) {
            error_log("sugarcrush: Refused skills directory {$dir}: {$reason}");
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
    private const MAX_DEPTH = 7;

    /**
     * How many directories one walk will visit before it stops descending.
     *
     * Same reason as {@see MAX_DEPTH}, for breadth rather than depth: a
     * `skills/x -> /usr/share` link cost 8.29s on one measured launch and
     * `-> /` is unbounded. This cap is a runaway guard, not a sizing target:
     * a walk that reaches it has followed a link out of any skills tree and is
     * enumerating somebody's filesystem instead. The framing is pinned by
     * {@see \SugarCraft\Crush\Tests\Config\DocFigureProseDriftTest::testSkillDirectoryCapIsFramedAsARunawayGuard()}
     * — the sentence this replaces priced the headroom as a fixed magnitude
     * above an unpinned estimate of a real tree's size, the E633 defect (a
     * figure asserted, the conclusion resting on it never pinned, and not
     * checkable from this file at all).
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
     * CONFINEMENT TO $dir IS NOT ENOUGH ON ITS OWN, and that was this class's
     * open half. `$root` below is `realpath($dir)`, so when $dir is ITSELF a
     * symlink the boundary travels with it and nothing inside it can ever be
     * outside it — per-entry containment is being asked the wrong question. A
     * repository chooses where that directory is: `.sugar-crush/skills`,
     * `.claude/skills` and `.opencode/skills` are all paths inside the checkout,
     * and git stores a symlink happily. Measured on this host against the
     * pre-fix build, for all three of those paths committed as
     * `-> <a directory outside the checkout>`: `loadFromDirectory()` returned
     * the planted skill with `SENTINEL-SKILL-BODY` in its `content`,
     * `loadManifestsFromDirectory()` returned its `description`, and
     * `skillDirectoriesIn()` returned its absolute path — with ZERO skips
     * recorded, because nothing had been refused. A skill body is prompt
     * context, so that is a file-disclosure primitive reachable by `git clone`,
     * and it is a strictly LARGER payload than the workflow tier's equivalent
     * (which discloses `.yaml` basenames and `description`s):
     * {@see \SugarCraft\Crush\Workflows\WorkflowRegistry::readableProjectDir()}
     * had closed its half and this one was left open and documented as
     * "tracked separately".
     *
     * $anchoredIn closes it, with the same trust anchor and the same predicate:
     * $dir must resolve STRICTLY inside the checkout it was derived from
     * ({@see ContainedPath::below()}), which is the one path in the pair a
     * repository cannot have forged — moving it would mean a link ABOVE the
     * checkout. A link that stays inside the checkout is honoured, because
     * `.claude/skills -> shared/skills` is repository content pointing at
     * repository content; a link resolving ONTO the checkout root is not, for
     * the reason {@see ContainedPath} states. The refusal DROPS THE TREE rather
     * than emptying it — the built-in and user tiers are unaffected, exactly as
     * a refused workflow tier leaves the user's own directory alone — and it is
     * recorded through {@see recordRefusedDirectory()} so the launch can say so.
     *
     * A tree with no $anchoredIn is unchanged: the user's own
     * `~/.claude/skills` and the built-in directory are not locations a
     * repository picked, so there is no second boundary for them to be held
     * inside. Note also what needs no argument here that the workflow tier
     * needed one for: a dangling or unresolvable $dir returns nothing at all,
     * since `realpath($dir) === false` short-circuits below — this walker has no
     * "leave it readable so an error message can name it" case to defend.
     *
     * $ownedBy WIDENS the per-entry boundary to a second directory, and exists for
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
     * @param string|null $anchoredIn the checkout $dir was derived from, which
     *        $dir itself must resolve strictly inside; null for a directory
     *        whose location no repository chose
     *
     * @return list<string>
     */
    private function skillFilesIn(string $dir, ?string $ownedBy = null, ?string $anchoredIn = null): array
    {
        $root = realpath($dir);
        if ($root === false || !is_dir($dir)) {
            return [];
        }

        if ($anchoredIn !== null && !ContainedPath::below($dir, $anchoredIn)) {
            // NAMES THE ANCHOR, not "the checkout". Every refusal from here said
            // "a repository chooses where this directory is", which stopped being
            // true when {@see \SugarCraft\Crush\Skills\ForeignSkillDiscovery}
            // started anchoring the USER tier to `$HOME`: there the directory is
            // one the user chose and the objection is that it leaves their home.
            // A notice that misidentifies who chose the path sends its reader to
            // the wrong file — the same correction the two agent-preset
            // registries needed.
            $this->recordRefusedDirectory($dir, sprintf(
                'resolves to %s, %s the directory it is anchored to (%s) — a link out of that directory '
                . "would put unrelated files' SKILL.md bodies in the model's prompt context",
                $root,
                realpath($anchoredIn) === $root ? 'which is exactly' : 'outside',
                $anchoredIn,
            ));

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
     * @param string|null $ownedBy    See {@see skillFilesIn()}.
     * @param string|null $anchoredIn See {@see skillFilesIn()}.
     *
     * @return list<string>
     */
    public function skillDirectoriesIn(string $dir, ?string $ownedBy = null, ?string $anchoredIn = null): array
    {
        $dirs = [];

        foreach ($this->skillFilesIn($dir, $ownedBy, $anchoredIn) as $file) {
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
     * THE PREDICATE ITSELF LIVES IN {@see ContainedPath}, which is where the
     * "resolve both sides, compare with a trailing separator" argument is
     * written down once — including why the separator is load-bearing, and why
     * an ENTRY resolving onto its boundary counts as contained while a
     * DIRECTORY resolving onto its trust anchor does not. This method ran its own
     * copy of that idiom until the copy was found to be missing the
     * directory-level half, and {@see skillFilesIn()} asks it both questions.
     *
     * BE PRECISE ABOUT THE DOMAIN: this class makes THREE containment decisions
     * and all three ask {@see ContainedPath} now — {@see skillFilesIn()}'s
     * directory anchor, this method's per-entry check, and
     * {@see loadSkillAsset()}'s asset check. That third one was a hand-spelled
     * copy over 300 lines below this doc-block while the doc-block claimed there
     * was one implementation. Package-wide the claim is narrower still, and
     * {@see ContainedPath}'s own class doc-block carries the measured inventory of
     * the spellings that remain.
     *
     * A path that will not resolve at all is NOT contained: `realpath()`
     * answers false for a dangling link and for a target this process cannot
     * reach, and neither is something to walk. Refused paths are recorded as
     * skips by the caller's own arm rather than here, so the diagnostic names
     * the escape rather than the check.
     *
     * $boundaries arrive already canonical; asking {@see ContainedPath} to
     * resolve them again is one cached stat per boundary and the price of the
     * predicate not living at its call sites.
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
            if (ContainedPath::within($path, $boundary)) {
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
        // $projectRoot is passed on, not merely used to build the path: it is
        // the boundary the skills DIRECTORY is itself held inside. See
        // {@see skillFilesIn()}'s $anchoredIn for the escape that leaves open.
        return $this->loadFromDirectory($this->projectSkillsDir($projectRoot), null, $projectRoot);
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
     *
     * $baseDir is trimmed of trailing slashes first (audit 15d-18): slicing
     * `strlen($baseDir) + 1` bytes off a `skills/` spelling cut one byte into
     * the skill's own directory name, so `skills/deploy/SKILL.md` keyed as
     * `/` and a nested `skills/a/b/SKILL.md` as `/b`. The walk strips at most
     * one trailing slash itself ({@see \DirectoryIterator}), so a `skills//`
     * spelling also leaves a leading `/` on the slice, which is trimmed too.
     *
     * A path not spelled under $baseDir at all — which {@see skillFilesIn()}
     * never produces today, since it seeds the walk with $baseDir as spelled —
     * is keyed through both realpaths instead, and failing that by its own
     * name, rather than by whatever bytes a blind substr() leaves.
     */
    private function skillKeyFor(string $baseDir, string $skillFilePath, string $fallbackName): string
    {
        $base = rtrim($baseDir, '/');
        $relativeSkillDir = str_starts_with($skillFilePath, $base . '/')
            ? dirname(ltrim(substr($skillFilePath, strlen($base) + 1), '/'))
            : self::canonicalRelativeSkillDir($base, $skillFilePath);

        return $relativeSkillDir === null || $relativeSkillDir === '.' || $relativeSkillDir === ''
            ? $fallbackName
            : $relativeSkillDir;
    }

    /**
     * The skill's directory relative to $base when both are compared as
     * realpaths, or null when it does not lie under $base that way either.
     */
    private static function canonicalRelativeSkillDir(string $base, string $skillFilePath): ?string
    {
        $realBase = realpath($base === '' ? '/' : $base);
        $realSkillDir = realpath(dirname($skillFilePath));
        if ($realBase === false || $realSkillDir === false) {
            return null;
        }

        if ($realSkillDir === $realBase) {
            return '.';
        }

        $prefix = rtrim($realBase, '/') . '/';

        return str_starts_with($realSkillDir, $prefix) ? substr($realSkillDir, strlen($prefix)) : null;
    }

    /**
     * Load skills from multiple sources.
     *
     * Priority order: built-in < project < user ({@see SkillOrigin::precedence()};
     * later sources override earlier), and every override is recorded in
     * {@see skipped()} under the losing file's path ({@see mergeTier()}). Foreign
     * (.claude/.opencode) skills are merged one layer up, in
     * {@see SkillManager::loadAll()}, where a {@see SkillSource} tag has
     * somewhere to live -- see {@see loadAllManifests()} for the full argument.
     *
     * @return array<string, Skill>
     */
    public function loadAll(string $projectRoot = '.'): array
    {
        $tiers = [
            SkillOrigin::BuiltIn->value => fn(): array => $this->loadBuiltInSkills(),
            SkillOrigin::Project->value => fn(): array => $this->loadProjectSkills($projectRoot),
            SkillOrigin::User->value => fn(): array => $this->loadUserSkills(),
        ];

        // Lowest precedence first, in the one order SkillOrigin states — the
        // same fold {@see loadAllManifests()} runs, so the eager and the
        // manifest-only walk cannot disagree about who wins.
        $skills = [];
        foreach (SkillOrigin::precedence() as $origin) {
            $skills = $this->mergeTier($skills, self::originated($tiers[$origin->value](), $origin));
        }

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

        // HEAD ONLY, through the bounded reader (audit 15d-27): this stage wants
        // the frontmatter, and used to read the whole file to find it — a
        // repository's 50 MB SKILL.md was loaded whole at every launch. A file
        // over the whole-file ceiling is refused here rather than listed: its
        // body could never be loaded, so offering it to the model as a skill
        // would only promise something the Skill tool must then refuse.
        [$content, $truncated] = SkillFileReader::head($skillPath, 'SKILL.md');
        if ($truncated && str_starts_with($content, '---')
            && preg_match(self::FRONTMATTER_PATTERN, $content) !== 1) {
            throw new \RuntimeException(sprintf(
                'SKILL.md frontmatter does not close within its first %s bytes; not read further: %s',
                number_format(SkillFileReader::MAX_FRONTMATTER_BYTES),
                $skillPath,
            ));
        }

        // The description is listed in the system prompt, and the YAML parse
        // refuses invalid UTF-8 outright — one Latin-1 byte used to drop the
        // whole skill or, past the parse, fail every request's JSON encode
        // (audit 15d-08). Too small a field for a note; the body read carries
        // one ({@see loadSkillBody()}).
        $content = Utf8Scrub::clean($content);

        // Parse frontmatter only (stage 1 - don't load body)
        $parsed = preg_match(self::FRONTMATTER_PATTERN, $content, $matches)
            ? Frontmatter::parse($matches[1])
            : null;

        $name = basename($skillDir);

        // The raw YAML values used to go straight into this array and on to
        // SkillRegistry::registerFromManifest()'s typed Skill constructor,
        // OUTSIDE every catch: one `paths: src/**` in a cloned repository's
        // skill was an uncaught TypeError at launch (audit 15d-01). Typed here
        // instead, a bad field throws inside loadManifestsFromDirectory()'s
        // catch and becomes a recorded skip.
        $meta = SkillFrontmatter::fromParsed($parsed, $name);

        return [
            'name' => $name,
            'description' => $meta->description,
            'disableModelInvocation' => $meta->disableModelInvocation,
            'userInvocable' => $meta->userInvocable,
            'context' => $meta->context,
            'paths' => $meta->paths,
            'sourcePath' => realpath($skillPath) ?: $skillPath,
        ];
    }

    /**
     * Stage-1 equivalent of loadFromDirectory(): walks the same directory
     * tree for SKILL.md files, but loads only each one's manifest
     * (loadSkillManifest()) instead of the full Skill (Skill::fromFile()),
     * so no body content is read from disk at this stage.
     *
     * @param string|null $ownedBy    See {@see loadFromDirectory()}.
     * @param string|null $anchoredIn See {@see loadFromDirectory()}.
     *
     * @return array<string, array{name: string, description: string, disableModelInvocation: bool, userInvocable: bool, context: string, paths: array<string>, sourcePath: string}>
     */
    public function loadManifestsFromDirectory(string $dir, ?string $ownedBy = null, ?string $anchoredIn = null): array
    {
        $manifests = [];

        foreach ($this->skillFilesIn($dir, $ownedBy, $anchoredIn) as $path) {
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
     * sources and priority order (built-in < project < user,
     * {@see SkillOrigin::precedence()}; later overrides earlier, each override
     * recorded in {@see skipped()} by {@see mergeTier()}) but loads only each
     * one's manifest, not its body.
     *
     * Fixes the defect described in crush_feat.md section 7.E3: every
     * ReactPHP-loop session used to pay the full I/O + YAML-parse cost of
     * every built-in/user/project skill's body at startup even when zero
     * skills were invoked that session, defeating the point of the
     * already-designed three-stage progressive disclosure. The body is
     * designed to backfill just-in-time via loadSkillBody(), called from
     * Tools\BuiltIn\SkillTool::execute() -- and that tool IS registered into
     * Bootstrap::tools() now, so the backfill half is production reachable.
     * This paragraph said "not yet registered ... not yet reachable from
     * bin/sugarcrush (tracked separately: crush_feat.md section 7 item 2 /
     * W3.S8)" for several rounds after W3.S8 shipped; see
     * {@see SkillManager::loadAll()} for the same correction and for the tests
     * that hold it.
     *
     * Foreign-imported skills (.claude/skills, .opencode/skills) are merged one
     * layer up: {@see SkillManager::loadAll()} interleaves them with
     * {@see manifestTiers()} tier by tier, so a user's foreign skill outranks a
     * project's native one while a native skill still wins inside its own tier.
     * They are not merged here because a foreign skill carries a
     * {@see SkillSource} tag and a manifest array has nowhere to put one --
     * see SkillManager::loadAll()'s doc-block for the full argument.
     *
     * @return array<string, array{name: string, description: string, disableModelInvocation: bool, userInvocable: bool, context: string, paths: array<string>, sourcePath: string, origin: SkillOrigin}>
     */
    public function loadAllManifests(string $projectRoot = '.'): array
    {
        $manifests = [];
        foreach ($this->manifestTiers($projectRoot) as $tier) {
            $manifests = $this->mergeTier($manifests, $tier);
        }

        return $manifests;
    }

    /**
     * Each native tier's Stage-1 manifests, NOT yet merged, keyed by
     * {@see SkillOrigin} value in {@see SkillOrigin::precedence()} order —
     * lowest first.
     *
     * Exposed unmerged for {@see SkillManager::loadAll()}, which has to slot
     * the foreign trees in BETWEEN the native tiers (a user's `~/.claude/skills`
     * entry outranks a project's `.sugar-crush/skills` one) and so cannot work
     * from {@see loadAllManifests()}' already-folded result.
     *
     * Each tier's manifests carry the tier they were read from (`origin`),
     * the fact the system-prompt listing badges every line with (audit
     * 15d-02) — stamped here because this is the one place that knows which
     * directory a manifest came out of.
     *
     * @return array<string, array<string, array{name: string, description: string, disableModelInvocation: bool, userInvocable: bool, context: string, paths: array<string>, sourcePath: string, origin: SkillOrigin}>>
     */
    public function manifestTiers(string $projectRoot = '.'): array
    {
        $walks = [
            SkillOrigin::BuiltIn->value => fn(): array => $this->loadManifestsFromDirectory($this->builtInSkillsDir()),
            // The one tier whose directory a repository picked the location of,
            // so the root is passed as the anchor it must resolve inside
            // ({@see loadProjectSkills()}).
            SkillOrigin::Project->value => fn(): array => $this->loadManifestsFromDirectory(
                $this->projectSkillsDir($projectRoot),
                null,
                $projectRoot,
            ),
            // Same $ownedBy widening the eager walk gets — see {@see loadUserSkills()}.
            SkillOrigin::User->value => fn(): array => $this->loadManifestsFromDirectory(
                $this->userSkillsDir(),
                self::homeDir(),
            ),
        ];

        $tiers = [];
        foreach (SkillOrigin::precedence() as $origin) {
            $tiers[$origin->value] = self::originatedManifests($walks[$origin->value](), $origin);
        }

        return $tiers;
    }

    /**
     * Lay one tier's skills over the tiers already merged — later wins, the
     * order {@see loadAll()} and {@see loadAllManifests()} document — and
     * record every name the later tier takes over.
     *
     * THE SHADOWING WAS SILENT, which is audit 15d-03: a cloned repository's
     * `.sugar-crush/skills/deploy` replaced the user's `~/.sugar-crush/skills/
     * deploy` (or a built-in such as `security-audit`) and {@see skipped()}
     * stayed empty, so nothing short of reading the registry told anyone their
     * own skill was no longer the one being listed. WHO wins is decided by
     * {@see SkillOrigin::precedence()} (the user now beats the project, 15d-03
     * (b)); this makes each loss visible, through the same {@see skipped()}
     * record (and `SUGARCRUSH_DEBUG_SKILLS=1` stderr line) an unreadable file
     * gets — the notice half of that decision.
     *
     * One helper for both walks so the eager and manifest-only merges cannot
     * drift apart on what they report; {@see SkillManager::loadAll()} reports
     * its foreign-vs-native merge through {@see recordShadowing()} for the same
     * reason.
     *
     * @template T of Skill|array<string, mixed>
     * @param array<array-key, T> $merged   the earlier tiers, already merged
     * @param array<array-key, T> $incoming the tier that outranks them
     * @return array<array-key, T>
     */
    private function mergeTier(array $merged, array $incoming): array
    {
        foreach ($incoming as $name => $winner) {
            if (isset($merged[$name])) {
                [$loserPath, $loserTier] = self::provenanceOf($merged[$name]);
                [$winnerPath, $winnerTier] = self::provenanceOf($winner);
                $this->recordShadowing((string) $name, $loserPath, $loserTier, $winnerPath, $winnerTier);
            }

            $merged[$name] = $winner;
        }

        return $merged;
    }

    /**
     * Record that the skill at $loserPath was not loaded because another
     * skill of the same name took its place — the shared wording for every
     * merge that can shadow one ({@see mergeTier()},
     * {@see SkillManager::loadAll()}).
     *
     * Keyed by the LOSER's path, because that is the file whose content the
     * user expected and is not getting; the reason names the winner so the
     * reader knows which file to look at instead.
     *
     * @param string $loserTier  the tier badge of the skill that lost, e.g.
     *        `user` or `user, foreign: claude` ({@see SkillOrigin::badge()})
     * @param string $winnerTier the same for the skill that won
     *
     * @internal
     */
    public function recordShadowing(
        string $name,
        string $loserPath,
        string $loserTier,
        string $winnerPath,
        string $winnerTier,
    ): void {
        // A loser that IS the winner's file, or a byte-identical copy of it,
        // costs the user nothing: the content they expected is the content
        // loaded. That is the common case on a machine that syncs one skill
        // into several tools' trees (skillshare links the same SKILL.md into
        // `~/.claude/skills` and `~/.config/opencode/skills`), and reporting
        // each of those would bury the shadowing that matters — a repository
        // replacing a skill with a different one — in a launch notice full of
        // duplicates.
        if (self::sameSkillFile($loserPath, $winnerPath)) {
            return;
        }

        $this->recordSkip($loserPath, sprintf(
            // Tiers in brackets, as the system-prompt listing badges them, so a
            // two-part badge (`user, foreign: claude`) still reads as one unit.
            "shadowed by [%s] skill %s (same name '%s'); this [%s] skill was not loaded",
            $winnerTier,
            $winnerPath,
            $name,
            $loserTier,
        ));
    }

    /**
     * Whether two SKILL.md paths are the same file or carry the same bytes.
     * Only reached on a name collision, so the read is paid per collision,
     * never per skill.
     */
    private static function sameSkillFile(string $a, string $b): bool
    {
        if (!is_file($a) || !is_file($b)) {
            return false;
        }

        $realA = realpath($a);
        if ($realA !== false && $realA === realpath($b)) {
            return true;
        }

        if (filesize($a) !== filesize($b)) {
            return false;
        }

        $hashA = @hash_file('sha256', $a);

        return $hashA !== false && $hashA === @hash_file('sha256', $b);
    }

    /**
     * Where a merged skill came from and which tier it is, for the shadowing
     * notice — a {@see Skill} carries both on itself, a Stage-1 manifest in its
     * `sourcePath` / `origin` fields (stamped by {@see originatedManifests()}).
     *
     * @param Skill|array<string, mixed> $entry
     * @return array{0: string, 1: string}
     */
    private static function provenanceOf(Skill|array $entry): array
    {
        if ($entry instanceof Skill) {
            return [$entry->sourcePath, $entry->origin->badge($entry->source)];
        }

        $origin = $entry['origin'] ?? SkillOrigin::Project;

        return [
            (string) ($entry['sourcePath'] ?? $entry['name'] ?? '?'),
            $origin instanceof SkillOrigin ? $origin->badge(SkillSource::Native) : SkillOrigin::Project->value,
        ];
    }

    /**
     * Tag every skill of one tier with that tier.
     *
     * @param array<string, Skill> $skills
     * @return array<string, Skill>
     */
    private static function originated(array $skills, SkillOrigin $origin): array
    {
        return array_map(static fn(Skill $skill): Skill => $skill->withOrigin($origin), $skills);
    }

    /**
     * Stamp every manifest of one tier with that tier, for
     * {@see SkillRegistry::registerFromManifest()} to carry onto the Skill.
     *
     * @param array<string, array<string, mixed>> $manifests
     * @return array<string, array<string, mixed>>
     */
    private static function originatedManifests(array $manifests, SkillOrigin $origin): array
    {
        return array_map(static fn(array $manifest): array => [...$manifest, 'origin' => $origin], $manifests);
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

        // Bounded (audit 15d-27): the prompt budget caps what is spliced, not
        // what is read, so this is the read's own ceiling.
        $content = SkillFileReader::read($skillPath, 'SKILL.md');

        // Same scrub and same trailing note as {@see Skill::parse()}, so the
        // lazy body and the eager one are the same bytes (audit 15d-08).
        $content = Utf8Scrub::announced($content, "the SKILL.md of skill \"" . basename(dirname($skillPath)) . '"');

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

        // Must be within skill directory (no path traversal). {@see ContainedPath}
        // rather than a local prefix compare: this was a hand-spelled copy of that
        // idiom over 300 lines below the doc-block on {@see contained()} claiming
        // there was one implementation — and a dormant one (measured: no caller in
        // `src/` or `bin/`, only this suite), which is the shape that produced the
        // directory-level miss that doc-block describes.
        if (!ContainedPath::within($assetPath, $skillDir)) {
            throw new \RuntimeException("Asset path escapes skill directory: $relativePath");
        }

        if (!is_file($assetPath)) {
            throw new \RuntimeException("Asset not found: $assetPath");
        }

        return SkillFileReader::read($assetPath, 'skill asset');
    }
}
