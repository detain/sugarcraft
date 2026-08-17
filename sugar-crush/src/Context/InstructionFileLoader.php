<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Context;

use SugarCraft\Crush\Support\ContainedPath;

/**
 * Loads instruction files (CLAUDE.md, AGENTS.md) from the repo root and
 * resolves forced-instruction glob patterns from config.
 *
 * This class handles the nested instruction file discovery mechanism:
 * - Root-level files (CLAUDE.md, AGENTS.md) are always loaded at session start
 * - Forced instruction patterns from config are glob-resolved and loaded every session
 * - Per-path nested instruction files (CLAUDE.md, AGENTS.md in subdirectories)
 *   are loaded via loadForPath() and tracked internally to inject each file at most once
 *
 * `loadRoot()`/`loadForPath()` also expand `@path` import references via
 * ImportResolver, mirroring Claude Code's CLAUDE.md/AGENTS.md import syntax
 * (this repo's own root CLAUDE.md already uses `@./AGENTS.md`).
 *
 * EVERY read here is bounded by repoRoot through {@see ContainedPath}, and the
 * count is deliberate rather than incidental: FIVE call sites, one per read
 * decision this class makes — `loadRoot()`'s root entry, `loadForced()`'s glob
 * match, `loadForPath()`'s starting directory and its per-level candidate, and
 * `expandImports()`'s gate closure. There is no local prefix compare left, and
 * that is the point. Two of the five (`loadForced()`, `expandImports()`) existed
 * as hand-spelled compares for six review rounds, and they are WHY the other
 * three were missing: a sweep instrumented on `grep -rn str_starts_with src/`
 * found this file's two present compares, listed it as audited, and was
 * structurally incapable of noticing that `loadRoot()` and `loadForPath()` — the
 * primary path into the system prompt — compared nothing at all. Both escapes
 * were reproduced on this host before being closed; see
 * {@see \SugarCraft\Crush\Tests\Context\InstructionFileLoaderContainmentTest},
 * which drives them and holds the measured bytes.
 *
 * A REFUSAL IS NO LONGER SILENT IN EVERY DIRECTION. Each refusal is still
 * skipped rather than raised — this class's callers are tool results and it has
 * no channel to the user — but it is now RECORDED, and {@see refusedPaths()} is
 * the pull-based seam the other three repository-chosen tiers already expose.
 * See that method for the layout that motivated it and for what still does not
 * drain it.
 */
final class InstructionFileLoader
{
    /**
     * Every instruction file this loader has already put in front of the
     * model, keyed by resolved absolute path — whether emitted as its own
     * document (loadRoot()/loadForced()), inlined by an `@import`
     * expansion, or injected on-touch by loadForPath().
     *
     * One shared set across all four routes is what stops the same bytes
     * occupying the context window twice on EVERY turn. The motivating case
     * is this very repo: root CLAUDE.md contains `@./AGENTS.md`, so
     * ImportResolver inlines AGENTS.md's full body into the CLAUDE.md
     * document, and loadRoot() would then also emit AGENTS.md as a second
     * top-level document. It doubles as `@import` cycle protection — a file
     * that imports itself is marked before its own expansion runs.
     *
     * @var array<string, true>
     */
    private array $emittedPaths = [];

    /**
     * Every path this loader declined to read, path as spelled => why.
     *
     * THE SEAM, added because "skipped silently" was defended with an argument
     * that was weaker than it looked. Each of `loadRoot()`, `loadForced()` and
     * `loadForPath()` refuses a candidate without a word to anyone, and the
     * reason given was that a channel "would mean touching Runtime/Bootstrap".
     * That is true of a DISPLAY and false of a seam: the other three
     * repository-chosen tiers each expose a pull-based one
     * ({@see \SugarCraft\Crush\Agents\AgentPresetRegistry::refusedDirectories()},
     * {@see \SugarCraft\Crush\Skills\SkillManager::refusedDirectories()},
     * {@see \SugarCraft\Crush\Workflows\WorkflowRegistry::projectTierRefusal()})
     * and nothing but the display touches `Bootstrap`.
     *
     * The case that made it worth having is not exotic: a per-library
     * `CLAUDE.md` symlinked to the monorepo root's shared one is the natural
     * layout for a `--root <lib>` run in a monorepo whose root `CLAUDE.md` IS
     * the shared file — and it is refused, correctly, with no signal anywhere.
     * `find` confirms this repository ships no symlinked `CLAUDE.md`/`AGENTS.md`
     * today, so nothing is broken; the seam is here so the next person to hit it
     * has something to ask.
     *
     * @var array<string, string>
     */
    private array $refusedPaths = [];

    private readonly ImportResolver $importResolver;

    /**
     * Session-lifetime caches for the two whole-session loaders.
     *
     * Runtime::buildSystemPrompt() runs once per agentic step, so without
     * memoization every model round-trip would re-read the same root files
     * from disk and re-expand their `@import` references.
     *
     * The trade-off is deliberate: an edit to CLAUDE.md/AGENTS.md made
     * mid-session is not picked up until the loader is rebuilt.
     *
     * Note that on the live TUI path EngineBackend::completeAsync() forks a
     * child per user turn, so a warmed cache dies with that child; the win
     * is collapsing the up-to-$maxSteps Runtime::run() reads inside a single
     * complete() down to one. Per-session caching would have to live on the
     * parent side of the fork.
     *
     * @var string[]|null
     */
    private ?array $rootCache = null;

    /** @var string[]|null */
    private ?array $forcedCache = null;

    /**
     * @param string $repoRoot Absolute path to the repository root
     * @param string[] $forcedInstructions Glob patterns from config, force-loaded every session
     * @param ImportResolver|null $importResolver Expander for `@path` references; defaults to ImportResolver::new()
     */
    public function __construct(
        private readonly string $repoRoot,
        private readonly array $forcedInstructions = [],
        ?ImportResolver $importResolver = null,
    ) {
        $this->importResolver = $importResolver ?? ImportResolver::new();
    }

    /**
     * Load CLAUDE.md and AGENTS.md from the repo root.
     *
     * These root-level instruction files are always loaded at session start,
     * providing cross-cutting conventions that apply everywhere.
     *
     * CLAUDE.md is read first, so when it `@import`s AGENTS.md (the shape
     * this repo's own root files use) AGENTS.md is emitted at the position
     * of that import and NOT repeated afterwards as a second document.
     * Precedence is unchanged — the earlier file still wins the slot.
     *
     * A root file that RESOLVES outside repoRoot is skipped, silently, for the
     * reason {@see loadForced()} gives for its own matches: a repository can
     * commit `CLAUDE.md` as a symlink, and this class has no channel to the
     * user, so the honest behaviour is to read nothing rather than to read it.
     *
     * @return string[] Contents of whichever root files exist and resolve inside
     *                  repoRoot (missing files, files resolving outside it, and
     *                  files already inlined by an earlier file's import, are
     *                  skipped)
     */
    public function loadRoot(): array
    {
        if ($this->rootCache !== null) {
            return $this->rootCache;
        }

        $rootFiles = [
            $this->repoRoot . '/CLAUDE.md',
            $this->repoRoot . '/AGENTS.md',
        ];

        $contents = [];
        foreach ($rootFiles as $path) {
            if (!is_file($path)) {
                continue;
            }

            // `<root>/CLAUDE.md` is a path whose TARGET a cloned repository
            // chooses, and `is_file()` follows symlinks, so nothing above this
            // line bounded the read. MEASURED on this host before this gate,
            // with repoRoot=<sb>/repo and `<sb>/repo/CLAUDE.md -> <sb>/outside/
            // secret.txt`: `loadRoot()` returned `["TOP-SECRET-AAA\n"]`, that
            // outside file's body, as a system-prompt document — see
            // {@see \SugarCraft\Crush\Tests\Context\InstructionFileLoaderContainmentTest}.
            // The `realpath()` on the next line was computed but spent only as
            // a cache key.
            //
            // within() rather than below() records the QUESTION — "may this
            // ENTRY be read" — and NOT a behavioural choice: the entry is a
            // file, so it cannot resolve onto a directory boundary, and
            // swapping the predicate here changes no verdict. That is measured
            // rather than asserted; see {@see \SugarCraft\Crush\Tests\Context\InstructionFileLoaderContainmentTest}'s
            // predicate-swap note for the four sites where the two agree and
            // the one place in this package where they do not.
            if (!ContainedPath::within($path, $this->repoRoot)) {
                $this->refusedPaths[$path] = 'resolves outside the checkout (' . $this->repoRoot . ')';

                continue;
            }

            $realPath = realpath($path) ?: $path;
            if (isset($this->emittedPaths[$realPath])) {
                continue;
            }

            // Marked BEFORE expanding so a file importing itself resolves to
            // the already-included note rather than recursing to MAX_DEPTH.
            $this->emittedPaths[$realPath] = true;

            // The RESOLVED path is what is read, matching {@see loadForced()}.
            // Both were gated on `realpath()` and then read the SPELLED path,
            // which re-resolves the whole chain a second time and widens the
            // window between the verdict and the read for every symlinked
            // component, not just the last one. Narrower, not closed — see the
            // TOCTOU paragraph on {@see ContainedPath}. The import base
            // directory stays the SPELLED `dirname($path)`: an `@./x.md` in a
            // CLAUDE.md that a repository vendored through a link means "next
            // to where the repository put it", and resolving that too would
            // silently change which file an import names.
            $raw = file_get_contents($realPath);
            $contents[] = $raw === false ? '' : $this->expandImports($raw, dirname($path));
        }

        return $this->rootCache = $contents;
    }

    /**
     * Resolve glob patterns from config and load matching file contents.
     *
     * These patterns (e.g. "candy-shine/CALIBER_LEARNINGS.md") force-load
     * regardless of what the agent has touched, providing cross-cutting
     * guidance that shouldn't depend on an agent opening the right file.
     *
     * Patterns are user config but their matches land verbatim in the model's
     * system prompt, so containment is enforced twice: absolute patterns are
     * rejected outright, and every glob match is realpath()-checked against
     * repoRoot afterwards. The second check is what stops a relative pattern
     * that traverses out (`../../../etc/ssh/*`) from exfiltrating files —
     * mirroring the boundary closure expandImports() hands to ImportResolver.
     *
     * Containment is judged on the realpath()-RESOLVED target, so a symlink
     * that lives inside the repo but points outside it (a common way to
     * vendor shared docs) is deliberately skipped too — a forced instruction
     * that silently never appears is most likely this.
     *
     * A match already emitted as a root document (or inlined by one of their
     * imports) is skipped: Runtime::buildSystemPrompt() drains loadRoot()
     * before loadForced(), so a glob that happens to cover CLAUDE.md adds
     * nothing but a second copy.
     *
     * @return string[] Contents of matching in-repo files (patterns with no
     *                  matches, matches outside repoRoot, and matches already
     *                  emitted elsewhere are skipped)
     */
    public function loadForced(): array
    {
        if ($this->forcedCache !== null) {
            return $this->forcedCache;
        }

        if ($this->forcedInstructions === []) {
            return $this->forcedCache = [];
        }

        $contents = [];
        foreach ($this->forcedInstructions as $pattern) {
            // Reject absolute paths — they bypass repoRoot and are a security risk.
            if (str_starts_with($pattern, '/')) {
                continue;
            }

            $fullPattern = $this->repoRoot . '/' . $pattern;
            $matches = glob($fullPattern);

            if ($matches === false || $matches === []) {
                continue;
            }

            foreach ($matches as $path) {
                if (!is_file($path)) {
                    continue;
                }

                // A relative pattern can still traverse out via "..", so the
                // resolved match — not the pattern — is what must be contained.
                // Routed through {@see ContainedPath} rather than spelled here:
                // this file's two hand-spelled compares are precisely why six
                // rounds read it as audited while its other two read paths had
                // NO compare to find. One extra `realpath()` per glob match (a
                // cached stat) buys the file zero local containment idiom.
                if (!ContainedPath::within($path, $this->repoRoot)) {
                    $this->refusedPaths[$path] = 'a forced-instruction match resolving outside the checkout ('
                        . $this->repoRoot . ')';

                    continue;
                }

                $realPath = realpath($path);
                if ($realPath === false) {
                    continue; // unreachable: within() just resolved it
                }

                if (isset($this->emittedPaths[$realPath])) {
                    continue;
                }

                $raw = file_get_contents($realPath);
                if ($raw !== false) {
                    $this->emittedPaths[$realPath] = true;
                    $contents[] = $raw;
                }
            }
        }

        return $this->forcedCache = $contents;
    }

    /**
     * Load nested instruction file for a touched path.
     *
     * Walks up from the touched file's directory toward repoRoot, checking
     * each level for CLAUDE.md or AGENTS.md. CLAUDE.md is preferred over
     * AGENTS.md at the same level. Each nested file is injected at most once
     * per session — subsequent calls for the same path return null if already
     * injected, and a file the root documents already carried (directly or via
     * an `@import`) counts as injected too.
     *
     * CONTAINED, and it was not: both the walk's starting directory and every
     * candidate file are checked against repoRoot through
     * {@see ContainedPath::within()}. A refused candidate is SKIPPED silently
     * and the walk continues, matching {@see loadForced()} — this class has no
     * channel to the user (its callers are tool results), so a nested
     * instruction file that inexplicably never appears is most likely a link
     * out of the checkout.
     *
     * @param string $touchedPath Absolute path to the file that was touched
     * @return string|null The nested instruction file content, or null if none
     *                     found, if the touched path is outside repoRoot, or if
     *                     the only candidate resolves outside it
     */
    public function loadForPath(string $touchedPath): ?string
    {
        $repoRoot = realpath($this->repoRoot);
        if ($repoRoot === false) {
            // A boundary that will not resolve is not a boundary. The old code
            // fell back to the configured string and walked anyway.
            return null;
        }

        // TWO gates, because gating only the candidate FILES would be enough
        // for containment but would leave a walk with no business running.
        //
        // GATE 1 — the walk must not START outside the checkout. The loop below
        // climbs by `dirname()` and used to terminate on the STRING equality
        // `$dir !== $repoRoot`, which a directory outside the checkout never
        // satisfies, so it climbed to `/` reading every `CLAUDE.md`/`AGENTS.md`
        // it passed. MEASURED before this gate, repoRoot=<sb>/repo and
        // touchedPath=<sb>/outside/anything.php returned `"ANCESTOR-BBB\n"` —
        // the body of <sb>/CLAUDE.md, an ANCESTOR of the checkout, no symlink
        // involved. The same string compare also missed when the checkout was
        // merely SPELLED through a symlink ($repoRoot is resolved, $dir was
        // not), which climbed above the root with nothing committed at all.
        //
        // within() rather than below() records the question, and NOT a
        // behavioural choice — an earlier revision argued it as one. `$dir ===
        // $repoRoot` returns null under EITHER predicate, because `while ($dir
        // !== $repoRoot)` never runs, so the two are indistinguishable here.
        // Measured: swapping this to below() leaves the whole containment suite
        // green. The distinction IS observable, but at the DIRECTORY anchors of
        // the other tiers — {@see \SugarCraft\Crush\Workflows\WorkflowRegistry::readableProjectDir()},
        // {@see \SugarCraft\Crush\Skills\SkillLoader::skillFilesIn()},
        // {@see \SugarCraft\Crush\Agents\AgentPresetRegistry::readableSearchPaths()}
        // and {@see \SugarCraft\Crush\Commands\CommandLoader::loadFromDirectory()},
        // each of which has a test that fails under the wrong predicate.
        //
        // $dir is RESOLVED here so that the loop's remaining `$dir !==
        // $repoRoot` is a compare between two canonical paths, which is what
        // makes it a real bound: every `dirname()` step of a resolved path
        // inside a resolved boundary lands on that boundary exactly. Every live
        // caller hands a path whose directory exists — Read/Edit/Glob read the
        // file first, and Write `mkdir -p`s the parent BEFORE calling here
        // ({@see \SugarCraft\Crush\Tools\BuiltIn\Write}) — so resolving costs
        // no reachable case. A path in a directory that does not exist yet
        // returns null rather than walking, which is what it already did for
        // the fully-nonexistent path this class is tested on.
        // THE PREVIOUS ANSWER FOR THIS PATH IS DROPPED BEFORE A NEW ONE IS
        // COMPUTED. The map ACCUMULATES across calls (see {@see refusedPaths()}
        // for why), and an accumulated entry that is never revisited can outlive
        // the condition that caused it: `loadForPath('<repo>/notyet/x.php')`
        // recorded "the directory holding it does not resolve", the session then
        // created `notyet/CLAUDE.md`, and the very next identical call returned
        // that file's contents while the refusal still said it had been refused.
        // Re-deciding a path is what makes the map's entry for THAT path
        // current; entries for paths this session never touches again are the
        // residue {@see refusedPaths()} states.
        unset($this->refusedPaths[$touchedPath]);

        $dir = realpath(dirname($touchedPath));
        if ($dir === false || !ContainedPath::within($dir, $repoRoot)) {
            $this->refusedPaths[$touchedPath] = $dir === false
                ? 'the directory holding it does not resolve, so no walk was started'
                : 'the directory holding it (' . $dir . ') is outside the checkout (' . $repoRoot . ')';

            return null;
        }

        // Walk up the directory tree toward repoRoot
        while ($dir !== $repoRoot) {
            // Check for CLAUDE.md first (preferred), then AGENTS.md
            foreach (['CLAUDE.md', 'AGENTS.md'] as $filename) {
                $fullPath = $dir . '/' . $filename;
                if (!is_file($fullPath)) {
                    continue;
                }

                // GATE 2 — $dir is contained, but the ENTRY inside it need not
                // be: `<root>/src/CLAUDE.md -> <outside>/secret.md` is one
                // committed line and `is_file()` follows it. within() records
                // the entry question, as in loadRoot(); a file cannot resolve
                // onto a directory boundary, so the predicate choice is not
                // observable here either.
                if (!ContainedPath::within($fullPath, $repoRoot)) {
                    $this->refusedPaths[$fullPath] = 'resolves outside the checkout (' . $repoRoot . ')';

                    continue;
                }

                $realPath = realpath($fullPath) ?: $fullPath;
                if (!isset($this->emittedPaths[$realPath])) {
                    // A file that was refused earlier and passes both gates now
                    // is no longer refused — same reason as the `unset()` above,
                    // for the entry rather than the touched path.
                    unset($this->refusedPaths[$fullPath]);

                    $this->emittedPaths[$realPath] = true;
                    // Resolved, for the reason loadRoot() reads resolved; the
                    // import base stays spelled, for the reason it stays spelled.
                    $raw = file_get_contents($realPath);
                    return $raw === false ? null : $this->expandImports($raw, dirname($fullPath));
                }
            }

            // Move to parent directory
            $parent = dirname($dir);
            if ($parent === $dir) {
                break;
            }
            $dir = $parent;
        }

        return null;
    }

    /**
     * The real paths whose bodies have already been emitted into the model's
     * context this session.
     *
     * Exists so the "emit once" mark can cross a process boundary: a tool run
     * inside one of {@see \SugarCraft\Crush\Runtime}'s forked tool children
     * mutates the child's copy-on-write copy of this loader, which dies with
     * the child. The child exports these paths and the parent unions them back
     * in via {@see markEmitted()} — see
     * {@see \SugarCraft\Crush\Tools\CarriesSessionState}.
     *
     * `strval` over the raw keys for the same reason
     * {@see \SugarCraft\Crush\Skills\SkillPathNudge::announced()} needs it:
     * PHP coerces a decimal-integer string array key to `int`. Unreachable in
     * practice here (these are realpaths, so they start with `/`), and kept
     * only so the two halves of one interface cannot drift.
     *
     * @return list<string>
     */
    public function emittedPaths(): array
    {
        return array_map(strval(...), array_keys($this->emittedPaths));
    }

    /**
     * The instruction files this loader declined to read, and why.
     *
     * ACCUMULATED, not recomputed, which is the opposite of every sibling seam
     * and is deliberate: {@see loadRoot()} and {@see loadForced()} memoize, so a
     * second call returns the cache without re-deciding anything, and
     * {@see loadForPath()} is called once per touched path over a whole session.
     * A map rebuilt per call would therefore report the LAST touched path's
     * refusals and forget the root file's. One loader is one session here
     * (`Runtime` builds it once), so the accumulation has the same lifetime the
     * siblings' per-call maps have.
     *
     * WHAT ACCUMULATION COSTS, stated because the paragraph above argues only
     * for it and a future consumer will get this wrong: AN ENTRY CAN OUTLIVE ITS
     * CONDITION. Measured — `loadForPath('<repo>/notyet/x.php')` returned null
     * and recorded "the directory holding it does not resolve"; the session then
     * created `notyet/CLAUDE.md`; the same call returned `"LEGIT\n"` while the
     * map still reported the refusal.
     *
     * {@see loadForPath()} now drops the previous verdict for a path before
     * re-deciding it, and drops an entry's refusal when that entry passes both
     * gates, so any path this session TOUCHES AGAIN is current. What remains —
     * and is the residue rather than a bug — is a path refused once and never
     * touched again, whose entry is a statement about the moment it was made.
     * Consumers must read this as "was refused at some point during this
     * session", not as "is refused now".
     *
     * Growth is bounded by the number of DISTINCT paths a session touches:
     * duplicate keys overwrite, so a hot loop over one path adds one entry.
     *
     * NOT drained by anything yet. {@see \SugarCraft\Crush\Cli\Bootstrap}'s
     * collector is fed by the three tiers whose refusals are DIRECTORY-shaped
     * and known at launch; these are file-shaped and arrive as the session
     * touches paths, so surfacing them is a display decision this does not make.
     *
     * @return array<string, string> path as spelled => why it was not read
     */
    public function refusedPaths(): array
    {
        return $this->refusedPaths;
    }

    /**
     * Union $paths into the emitted set — see {@see emittedPaths()}.
     *
     * A union, never a replacement: several forked children can report
     * overlapping sets in no defined order, and each of them started from a
     * copy of this loader's own state. Re-marking an already-marked path is a
     * no-op by construction.
     *
     * The memoized {@see loadRoot()}/{@see loadForced()} caches are left
     * alone: they hold what THIS process already emitted, and marking a path
     * cannot un-emit it.
     *
     * CAST rather than an `is_string()` filter — see
     * {@see \SugarCraft\Crush\Skills\SkillPathNudge::markAnnounced()}: a
     * numeric-string key arrives back as an `int` and a type filter would drop
     * it. Not reachable for a realpath, mirrored here so the two
     * {@see \SugarCraft\Crush\Tools\CarriesSessionState} implementations stay
     * identical in shape.
     *
     * @param list<string|int> $paths
     */
    public function markEmitted(array $paths): void
    {
        foreach ($paths as $path) {
            if (is_string($path) || is_int($path)) {
                $path = (string) $path;
                if ($path !== '') {
                    $this->emittedPaths[$path] = true;
                }
            }
        }
    }

    /**
     * Expand `@path` import references in freshly-read instruction content.
     *
     * A boundary-check closure is handed straight into
     * ImportResolver::expand(), which threads it through EVERY recursive
     * expansion call (not just the references present in the outermost
     * $content) -- so a reference that resolves outside $repoRoot is
     * blocked and replaced with an inline warning note no matter how many
     * @import hops deep it is found, mirroring Claude Code's approval-dialog
     * concept for imports that leave the project (at minimum, sugar-crush
     * has no interactive approval flow yet, so this is the "at minimum a
     * warning-tagged note" fallback). In-repo references are left for
     * ImportResolver to resolve and recurse into as normal.
     *
     * The same hook is where every followed import is recorded in
     * $emittedPaths, and where a repeat of an already-emitted file is
     * replaced by a short note instead of a second full copy of its body.
     * ImportResolver threads the closure through its recursion, so this is
     * the one place that sees every file the expansion would inline, at any
     * depth — which is why the de-duplication lives here rather than in
     * ImportResolver (which is stateless and shared) or in the caller (which
     * only ever sees the finished string).
     */
    private function expandImports(string $content, string $baseDir): string
    {
        $gate = function (string $realPath, string $pathFragment): ?string {
            // ImportResolver `is_file()`-checks and `realpath()`s before calling
            // this, so $realPath is always a resolvable existing path and
            // within()'s re-resolution of it cannot change the verdict — it
            // costs one cached stat to keep this file free of a local idiom.
            if (!ContainedPath::within($realPath, $this->repoRoot)) {
                // Recorded as well as noted inline: this is the ONE refusal in
                // this class that was never silent, and leaving it out of the
                // map would make `refusedPaths()` an incomplete picture of the
                // same question.
                $this->refusedPaths[$realPath] = "an @import from '{$pathFragment}' resolving outside the "
                    . 'checkout (' . $this->repoRoot . ')';

                return "<import-blocked reason=\"outside-repo-root\">Import '{$pathFragment}' resolves to"
                    . " '{$realPath}', outside the repository root, and was not followed.</import-blocked>";
            }

            if (isset($this->emittedPaths[$realPath])) {
                return "<import-skipped reason=\"already-included\">Import '{$pathFragment}' is already"
                    . ' included in this prompt and was not repeated.</import-skipped>';
            }

            $this->emittedPaths[$realPath] = true;

            return null; // in-repo, not yet seen -- let ImportResolver expand it
        };

        return $this->importResolver->expand($content, $baseDir, 0, $gate);
    }
}
