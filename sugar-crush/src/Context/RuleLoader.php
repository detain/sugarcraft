<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Context;

use SugarCraft\Crush\Support\ContainedPath;
use SugarCraft\Crush\Support\HomeDirectory;

/**
 * Load the three tiers of standing rules and hand them to the prompt assembler.
 *
 * DESIGN SOURCE: prompt_plan.md Phase 6 P6.S2 (plan lines 2084-2115) and
 * prompt_expand.md §9.13, §2.5, §2.13, §10 seam 8. This is a SugarCraft
 * architecture type, not a port - charmbracelet/crush has no `RuleLoader`
 * symbol, so the repo's "Mirrors charmbracelet/..." convention does not apply.
 *
 * THE THREE TIERS, in the load order this class emits them (OD3 ruling):
 *   1. user    - `~/.sugar-crush/rules/*.md`, the person's own machine config
 *   2. project - `<repoRoot>/.sugar-crush/rules/*.md`, shipped in-repo
 *   3. root    - `<repoRoot>/RULES.md`, an optional single file at the top
 *
 * THE TIERS AND WHO CHOOSES THEM (OD1 ruling, resolving a wording clash in the
 * sources): the project rules tier IS allowed to exist. prompt_expand.md seam 8
 * phrases the rule as "user-tier only," but §2.12 is about WHO CHOOSES that a
 * piece of text is forced into the prompt, not WHERE files live - and the plan's
 * own tier list above names the project tier explicitly. The contradiction is
 * resolved here so a later agent does not under-build: containment (below) is
 * what makes a project file safe to read, exactly as it already does for
 * {@see InstructionFileLoader}; §2.12's "user chooses" governs the config-surface
 * step (P6.S4), not the existence of this directory.
 *
 * ONE TEMPLATE, ONE DISCIPLINE. {@see \SugarCraft\Crush\Commands\CommandLoader}
 * is the only existing user-tier loader in this tree and is the structural model
 * here - the directory walk, the anchor check, the per-entry containment gate,
 * and the single `ksort` ordering precedent. {@see InstructionFileLoader}
 * implements no user tier at all (it actively refuses `$HOME`), so what is cloned
 * from it is the refusal-RECORDING and realpath-dedup DISCIPLINE, not its tiers.
 * Three requirements are net-new with no precedent in this repo and are written
 * from spec: the file-count cap, the "list a tier's `*.md` files ordered by
 * filename" loop, and the second (case-insensitive) dedup pass.
 *
 * CONTAINMENT IS THE POINT (the threat, in CommandLoader's own words, is that a
 * rules file "cannot smuggle in ~/.ssh/config as a prompt"). Every read of every
 * tier is gated by {@see ContainedPath}. A refusal is never silently skipped: it
 * is recorded in {@see refusedPaths()} with a reason, because a silently skipped
 * read is indistinguishable from an empty file and a loader that cannot say WHY
 * it loaded nothing is unauditable.
 *
 * THE USER-TIER ANCHOR IS DELIBERATELY TIGHTER THAN COMMANDLOADER'S, and this is
 * the one place this loader does not mirror its template. `CommandLoader`
 * anchors the user tree at `$HOME`, which by design lets a
 * `~/.sugar-crush/commands` symlink reach anywhere inside `$HOME`. The plan's
 * done-when for THIS step names the threat as a rules dir symlinked at
 * `$HOME/.ssh` - and `.ssh` lives INSIDE `$HOME`, so a `$HOME` anchor would let
 * that through and the guard would be decorative. The rules user tier is
 * therefore anchored one level deeper, at `$HOME/.sugar-crush`: the boundary is
 * the application's own namespace, so a `rules` link escaping it - to `.ssh` or
 * anywhere else, inside `$HOME` or out - is refused. The cost is stated, not
 * hidden: a user who links `~/.sugar-crush/rules` at a directory elsewhere in
 * their own home gets a refusal rather than a load, and can see why in
 * {@see refusedPaths()}. The project tier keeps the standard checkout anchor
 * (`$repoRoot`), where a `<root>/.sugar-crush/rules -> $HOME/.ssh` link already
 * resolves outside the repo and is refused by the ordinary gate.
 *
 * DEDUP RUNS IN TWO PASSES (OD3), across all tiers in load order, first-seen
 * wins, and BOTH passes are pinned by tests:
 *   pass 1 - exact `realpath()`, the same physical file reached twice (e.g. a
 *            symlink inside an allowed boundary resolving to a sibling, or the
 *            project and user tiers landing on one directory when the checkout
 *            IS the home).
 *   pass 2 - `strtolower(realpath())`, so `rules.md` and `RULES.md` count as one
 *            even on a CASE-SENSITIVE filesystem where realpath() keeps them
 *            distinct. The plan cites upstream `crush` deduping twice for the
 *            case-insensitive-filesystem reason; that upstream behaviour is NOT
 *            corroborable from this checkout, so it is cited as SPEC motivating
 *            this second pass, not as a symbol being mirrored.
 *
 * CAPS: {@see MAX_DEPTH} bounds the recursive walk (a hard depth also stops a
 * symlink cycle inside a tier from recursing forever); {@see MAX_FILES} bounds
 * how many `*.md` files one tier directory contributes. A file past either cap
 * is recorded in {@see skippedFiles()}, never dropped in silence, but - unlike a
 * containment refusal - it goes to the skip ledger and NOT to
 * {@see refusedPaths()}, because a cap trip or a parse error is a truncation of
 * the user's own content, not the security event a refusal names.
 *
 * TRIGGERS ARE BUILT, NOT APPLIED. Each rule's `paths:`/`keywords:`/`description`
 * frontmatter becomes the P6.S1 value objects inside {@see Rule}; deciding
 * whether a rule fires for a given prompt or path is a later wiring step (P7.S4,
 * P6.S5). This loader returns every ENABLED rule it found, in tier-then-filename
 * order, deduplicated - a rule with no trigger keys is a standing rule that is
 * always eligible.
 */
final class RuleLoader
{
    /**
     * Deepest subdirectory the tier walk descends, measured in path segments
     * below the tier directory. Mirrors {@see \SugarCraft\Crush\Commands\CommandLoader}'s
     * cap of the same name; a hard depth also bounds the walk over a user- or
     * repo-controlled tree so a symlink cycle cannot recurse forever.
     *
     * MEASURED on this host: `RecursiveIteratorIterator::setMaxDepth(4)` reaches
     * a file nested four directories below the tier root (`a/b/c/d/x.md`) and
     * stops short of five (`a/b/c/d/e/y.md`) - the pair the depth test pins.
     */
    private const MAX_DEPTH = 4;

    /**
     * How many `*.md` rule files one tier directory may contribute, counted
     * after containment but before de-duplication. Bounded because the walk is
     * over content the repository (project/root tiers) or another process
     * (via a link) chose, and an unbounded list of files becomes an unbounded
     * prompt. Net-new: no cap of this kind exists elsewhere in this repo, so it
     * is a plain safety ceiling rather than a mirror of a sibling value.
     */
    private const MAX_FILES = 64;

    /**
     * Containment refusals: path as spelled => why it was not read.
     *
     * Loader-local (OD2 ruling), in {@see InstructionFileLoader::refusedPaths()}'s
     * shape - a map this object owns and a caller reads, NOT a
     * {@see \SugarCraft\Crush\Cli\Bootstrap} drain. Consequence recorded there:
     * because nothing drains it at launch, `.sugar-crush/rules` is a declared
     * GAP in the project-tier refusal inventory rather than a wired feeder.
     *
     * Accumulated on the instance across every load call, keyed on the path as
     * GIVEN (the reader can go and look at that path; the resolved target is the
     * far end of the link that caused the refusal).
     *
     * @var array<string, string>
     */
    private array $refusedPaths = [];

    /**
     * Per-file skips that are NOT containment events: a cap overflow or a parse
     * failure, path as spelled => reason. Kept separate from
     * {@see $refusedPaths} so a security refusal cannot be buried under
     * ordinary truncation noise (see the class docblock on caps).
     *
     * @var array<string, string>
     */
    private array $skippedFiles = [];

    /**
     * @param string    $repoRoot       The checkout these tiers are anchored to - the project and root tiers resolve inside it, the user tier does not use it.
     * @param bool|null $reportRefusals Force stderr reporting on (true) or off (false); null (every production caller) lets {@see DEBUG_RULES_REFUSALS_ENV} decide. Present so a test can exercise the reporting half without a leaking putenv().
     */
    public function __construct(
        private readonly string $repoRoot,
        private readonly ?bool $reportRefusals = null,
    ) {
    }

    /**
     * The env var that puts this loader's refusals on stderr, off by default.
     *
     * A sibling of {@see \SugarCraft\Crush\Commands\CommandLoader::DEBUG_REFUSALS_ENV}
     * in every respect except its identifier. The identifier differs on purpose:
     * the environment-roster guard resolves a `getenv(self::NAME)` argument
     * to the value declared by a constant of that NAME, and two loaders each
     * holding a constant literally spelled `DEBUG_REFUSALS_ENV` make that
     * resolution ambiguous - the roster derived from source then mis-attributes one
     * loader's read to the other and reports a variable that is read as though it
     * were not. `DEBUG_RULES_REFUSALS_ENV` is unique, so both names resolve to
     * exactly the string each is declared to be. unset, empty and `0` all read as
     * off, matching every other SUGARCRUSH_* switch. Adding the
     * SUGARCRUSH_DEBUG_RULES value is what obliges the `docs/ENVIRONMENT.md` roster
     * row that accompanies this commit.
     */
    public const DEBUG_RULES_REFUSALS_ENV = 'SUGARCRUSH_DEBUG_RULES';

    /**
     * Every tier loaded, in load order, deduplicated, enabled only.
     *
     * The single assembly-facing entry point: it walks user, then project, then
     * root, and folds the three lists through the two de-dup passes so a file
     * reached by more than one tier is emitted once (first-seen wins). The
     * returned rules are those whose `enabled:` frontmatter is true (or absent);
     * a disabled rule is parsed (so it can still occupy a de-dup slot and cannot
     * reappear as an enabled twin) but excluded here.
     *
     * @return list<Rule>
     */
    public function load(): array
    {
        $ordered = [
            ...$this->loadUserRules(),
            ...$this->loadProjectRules(),
            ...$this->loadRootRules(),
        ];

        $seenReal = [];
        $seenLower = [];
        $result = [];

        foreach ($ordered as $rule) {
            $real = realpath($rule->path);
            // A rule reached this far was read from a file that existed a moment
            // ago; if realpath() now fails the file vanished mid-load. Skip it
            // without recording a refusal (it was never a containment decision) -
            // an unreadable path is neither loaded content nor a smuggled one.
            if ($real === false) {
                continue;
            }

            if (isset($seenReal[$real])) {
                $this->noteDuplicate($real, $seenReal[$real], 'the exact same file');

                continue;
            }

            $lower = strtolower($real);
            if (isset($seenLower[$lower])) {
                $this->noteDuplicate($real, $seenLower[$lower], 'a case variant of a file already loaded');

                continue;
            }

            $seenReal[$real] = $rule->path;
            $seenLower[$lower] = $rule->path;

            if (!$rule->enabled) {
                continue;
            }

            $result[] = $rule;
        }

        return $result;
    }

    /**
     * Tier 1 - `~/.sugar-crush/rules/*.md`.
     *
     * Anchored at `$HOME/.sugar-crush`, one level tighter than
     * {@see \SugarCraft\Crush\Commands\CommandLoader::loadUserCommands()}'s
     * `$HOME` anchor - the reasoning for the divergence is the class docblock's
     * "USER-TIER ANCHOR IS DELIBERATELY TIGHTER" paragraph. Null from
     * {@see HomeDirectory::owned()} (this process cannot establish whose home it
     * is in) yields an early return with a recorded refusal rather than a
     * fallback: there is no anchor to hold the directory inside, and `path()`'s
     * temp-dir fallback would smuggle the wrong tree into the prompt.
     *
     * @return list<Rule>
     */
    public function loadUserRules(): array
    {
        $home = HomeDirectory::owned();
        if ($home === null) {
            $reason = 'Skipping user rules: this process cannot establish that $HOME is this user\'s own '
                . 'directory (see HomeDirectory::owned()), so there is no anchor to hold '
                . '~/.sugar-crush/rules inside.';
            $this->report($reason);
            // Recorded under the literal `~/...` spelling - establishing a
            // resolved path is exactly what failed, so there is none to name.
            $this->refusedPaths['~/.sugar-crush/rules'] = $reason;

            return [];
        }

        return $this->loadFromDirectory(
            $home . '/.sugar-crush/rules',
            $home . '/.sugar-crush',
            'user',
        );
    }

    /**
     * Tier 2 - `<repoRoot>/.sugar-crush/rules/*.md`, shipped in-repo.
     *
     * `$repoRoot` is the trust anchor, not merely used to build the path: it is
     * the one path in the pair the repository cannot forge, since a link that
     * moved it would have to sit above the clone. A
     * `<root>/.sugar-crush/rules -> $HOME/.ssh` link therefore resolves outside
     * the anchor and is refused by the ordinary directory gate.
     *
     * @return list<Rule>
     */
    public function loadProjectRules(): array
    {
        return $this->loadFromDirectory(
            rtrim($this->repoRoot, '/') . '/.sugar-crush/rules',
            $this->repoRoot,
            'project',
        );
    }

    /**
     * Tier 3 - the optional single `<repoRoot>/RULES.md`.
     *
     * A file, not a directory, so it carries no sub-walk and no depth/file caps;
     * it still gets the full containment gate and the refusal ledger. Absent is
     * the normal case (no rules file) and is NOT a refusal; present-but-outside
     * the checkout - a `RULES.md` symlink aimed above the repo - is refused and
     * recorded, exactly like the directory tiers.
     *
     * @return list<Rule>
     */
    public function loadRootRules(): array
    {
        $path = rtrim($this->repoRoot, '/') . '/RULES.md';
        if (!is_file($path)) {
            return [];
        }

        $real = realpath($path);
        if ($real === false || !ContainedPath::within($path, $this->repoRoot)) {
            $reason = sprintf(
                'Skipping root rules file %s: it does not resolve inside the checkout it was reached from (%s).',
                $path,
                $this->repoRoot,
            );
            $this->report($reason);
            $this->refusedPaths[$path] = $reason;

            return [];
        }

        $rule = $this->readRule($real, 'root', 'RULES.md');
        if ($rule === null) {
            return [];
        }

        return [$rule];
    }

    /**
     * Accessor - every containment refusal made so far, path as spelled => why.
     *
     * Cumulative across calls on this instance (mirrors the loader-local refusal
     * maps on {@see \SugarCraft\Crush\Commands\CommandLoader} and
     * {@see InstructionFileLoader}); read it as "what this loader refused during
     * this session", not "what is refused right now."
     *
     * @return array<string, string>
     */
    public function refusedPaths(): array
    {
        return $this->refusedPaths;
    }

    /**
     * Accessor - every per-file skip that was not a containment event, keyed the
     * same way (path as spelled => reason): a cap overflow or a parse failure.
     *
     * @return array<string, string>
     */
    public function skippedFiles(): array
    {
        return $this->skippedFiles;
    }

    /**
     * Walk one tier directory into a filename-ordered rule list.
     *
     * The whole method is the containment contract in one place: realpath the
     * directory (a missing directory is the normal "tier not configured" case,
     * so it returns empty WITHOUT a refusal), refuse the directory if it escapes
     * its anchor, then for each file refuse an entry that escapes the resolved
     * directory and skip (recorded) any file past the count cap or that fails to
     * parse. Ordering is filesystem-dependent until the `ksort` at the end, which
     * is the tree's only sort precedent and what makes tier contents stable
     * across machines.
     *
     * @param string      $dir       The tier directory as spelled by the caller.
     * @param string|null $anchoredIn The boundary the directory must live strictly inside; null for a directory no repository chose.
     * @param string      $tier      One of user/project/root, threaded to {@see Rule::new()}.
     *
     * @return list<Rule>
     */
    private function loadFromDirectory(string $dir, ?string $anchoredIn, string $tier): array
    {
        $realDir = realpath($dir);
        if ($realDir === false || !is_dir($realDir)) {
            return [];
        }

        if ($anchoredIn !== null && !ContainedPath::below($dir, $anchoredIn)) {
            $reason = sprintf(
                'Skipping %s rules directory %s: resolves to %s, outside the boundary it is anchored to (%s).',
                $tier,
                $dir,
                $realDir,
                $anchoredIn,
            );
            $this->report($reason);
            $this->refusedPaths[$dir] = $reason;

            return [];
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($realDir, \RecursiveDirectoryIterator::SKIP_DOTS)
        );
        $iterator->setMaxDepth(self::MAX_DEPTH);

        $byKey = [];
        $accepted = 0;
        foreach ($iterator as $file) {
            if (!$file instanceof \SplFileInfo || !$file->isFile()) {
                continue;
            }
            if (strtolower($file->getExtension()) !== 'md') {
                continue;
            }

            $spelled = $file->getPathname();

            // A symlink inside a rules directory can point anywhere; require the
            // target to still live under the resolved directory so a rules file
            // cannot smuggle ~/.ssh/config in as a prompt. {@see ContainedPath}
            // rather than a local prefix compare, so this class is not another
            // spelling of the predicate. This is the security gate, so the
            // refusal it makes is a containment one.
            if (!ContainedPath::within($spelled, $realDir)) {
                $reason = sprintf('Skipping rules file outside %s: %s', $realDir, $spelled);
                $this->report($reason);
                $this->refusedPaths[$spelled] = $reason;

                continue;
            }

            if ($accepted >= self::MAX_FILES) {
                $reason = sprintf(
                    'Skipping rules file %s: this tier already reached its %d-file cap.',
                    $spelled,
                    self::MAX_FILES,
                );
                $this->report($reason);
                $this->skippedFiles[$spelled] = $reason;

                continue;
            }

            $realPath = (string) realpath($spelled);
            $key = $this->ruleKeyFor($realDir, $realPath);
            $rule = $this->readRule($realPath, $tier, $key);
            if ($rule === null) {
                continue;
            }

            $byKey[$key] = $rule;
            $accepted++;
        }

        ksort($byKey);

        return array_values($byKey);
    }

    /**
     * Read and parse one rules file, recording a parse failure and returning
     * null rather than throwing.
     *
     * A malformed file must not abort the tier (one bad `---` block should not
     * hide the dozen good rules beside it), but it must not be silent either: it
     * lands in the skip ledger with the parser's own message.
     */
    private function readRule(string $realPath, string $tier, string $key): ?Rule
    {
        try {
            $content = file_get_contents($realPath);
        } catch (\Throwable $e) {
            $content = false;
        }

        if ($content === false) {
            $reason = sprintf('Failed to read rules file %s.', $realPath);
            $this->report($reason);
            $this->skippedFiles[$realPath] = $reason;

            return null;
        }

        try {
            return Rule::new($realPath, $tier, $content, $key);
        } catch (\Throwable $e) {
            $reason = sprintf('Failed to load rule from %s: %s', $realPath, $e->getMessage());
            $this->report($reason);
            $this->skippedFiles[$realPath] = $reason;

            return null;
        }
    }

    /**
     * The file's path relative to its tier directory, minus the `.md`
     * extension, separators normalised to "/" - the key {@see loadFromDirectory()}
     * sorts on and the fallback name a name-less file gets. Derives the sort
     * order from filename (the plan's "ordered by filename") rather than from a
     * `name:` field that two files may legitimately share.
     */
    private function ruleKeyFor(string $baseDir, string $filePath): string
    {
        $relative = substr($filePath, strlen($baseDir) + 1);
        $relative = str_replace(DIRECTORY_SEPARATOR, '/', $relative);

        return substr($relative, 0, -strlen('.md'));
    }

    /**
     * Record that a de-dup pass dropped $dropped in favour of $kept.
     *
     * A de-dup is not a refusal and not an error - it is two spellings of one
     * intended rule - so it goes to the skip ledger with both paths named, which
     * is what lets a test tell "deduped" apart from "cap-skipped" apart from
     * "containment-refused."
     */
    private function noteDuplicate(string $droppedReal, string $keptPath, string $why): void
    {
        $reason = sprintf('Skipping duplicate rules file %s: %s, already loaded from %s.', $droppedReal, $why, $keptPath);
        $this->report($reason);
        $this->skippedFiles[$droppedReal] = $reason;
    }

    /**
     * Put one refusal on stderr, if anyone asked for it - one funnel so the call
     * sites cannot drift on the gate. See {@see DEBUG_RULES_REFUSALS_ENV}.
     */
    private function report(string $message): void
    {
        if ($this->reportRefusals ?? self::debugRefusalsRequested()) {
            error_log($message);
        }
    }

    private static function debugRefusalsRequested(): bool
    {
        $value = getenv(self::DEBUG_RULES_REFUSALS_ENV);

        return $value !== false && $value !== '' && $value !== '0';
    }
}
