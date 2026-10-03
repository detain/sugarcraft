<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Context;

use SugarCraft\Crush\Diagnostics\RuntimeNoticeSink;
use SugarCraft\Crush\Support\ContainedPath;
use SugarCraft\Crush\Support\HomeDirectory;

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
 * EVERY read here is bounded through {@see ContainedPath}, and the count is
 * deliberate rather than incidental: SIX call sites, one per read decision this
 * class makes — `loadRoot()`'s root entry, `loadAncestorRoots()`'s ancestor
 * entry, `loadForced()`'s glob match, `loadForPath()`'s starting directory and
 * its per-level candidate, and `expandImports()`'s gate closure. Pinned as a
 * count in {@see \SugarCraft\Crush\Tests\Support\ContainedPathInventoryTest}::ROUTED_CALL_SITES.
 *
 * BOUNDED BY WHICH ROOT IS NOW A REAL QUESTION, and the answer is per-tier
 * rather than "repoRoot" — five of the six pass `$repoRoot`, while
 * `loadAncestorRoots()` and the `expandImports()` gate it calls pass
 * {@see ancestorRoot()}, because an ancestor file is by construction outside
 * `$repoRoot` and judging it against `$repoRoot` would refuse all of them.
 * `$repoRoot`'s own entries are NOT relaxed by that: the gate the measured
 * escapes closed still compares against `$repoRoot` exactly as before. There is
 * no local prefix compare left, and
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
 * See that method for the layout that motivated it and for what drains it
 * (a launch notice since audit R1).
 *
 * EVERY READ IS SIZE-BOUNDED TOO (audit 15d-09, C3). The four document reads
 * and the `@import` expansion were unbounded `file_get_contents()` calls whose
 * bytes went whole into every request: a 3,080,000-byte AGENTS.md gave a
 * 3,085,377-byte system prompt and recorded nothing. Now a file is measured
 * with a stat before it is read, a document over {@see MAX_DOCUMENT_BYTES} is
 * never read at all, an import that would carry its document over that ceiling
 * is replaced by a pointer at its import site, and each such decision is a
 * {@see refusedPaths()} entry. What reaches the prompt is a
 * {@see pointer()} line naming the file and its size — never a clipped body.
 */
final class InstructionFileLoader
{
    /**
     * The most bytes ONE instruction document may carry as read and expanded —
     * the file itself plus every `@import` inlined into it.
     *
     * 60 KiB, and the number is the per-document budget
     * `Runtime::MAX_INSTRUCTION_DOCUMENT_BYTES` (64 KiB of FRAMED, post-escape
     * prompt bytes) minus 4 KiB of headroom for what the prompt splice adds
     * around a document: the `<project-instructions>` fence and its authority
     * preamble (under 500 bytes) and the three bytes {@see PromptFence::escape()}
     * adds per neutralised tag. The headroom is what keeps this read-side guard
     * from admitting a document the splice would then defer whole; the splice
     * stays the authority, pricing the framed bytes it actually emits.
     *
     * 64 KiB itself is the standing-rule precedent, chosen so one instruction
     * document may cost what one max-size rule file may
     * ({@see RuleLoader::MAX_FILE_BYTES}, Runtime's MAX_STANDING_RULE_BYTES).
     * The real document it must keep admitting is this monorepo's own: its root
     * CLAUDE.md with the AGENTS.md and CONTRIBUTING.md it imports expands to
     * 32,017 bytes as measured when this ceiling was set (25,110 when the
     * ancestor pass was written) — about half the ceiling, so the docs can
     * roughly double before the convention every session here relies on stops
     * inlining. Claude Code's own warning threshold for one memory file is about
     * 40,000 characters; this is looser, because sugar-crush defers rather than
     * warns, and a deferral costs the model a Read call.
     */
    public const MAX_DOCUMENT_BYTES = 61_440;

    /**
     * The most bytes {@see pointer()} returns, by construction — the same 1 KiB
     * line ceiling {@see RulePathNudge::pointer()} holds a deferred rule to, for
     * the same reason: the splice reserves room for its pointer lines up front,
     * and it can only reserve a number that is a bound.
     */
    private const MAX_POINTER_BYTES = 1024;

    private const POINTER_HEAD = 'Instruction file deferred: budget (';

    private const POINTER_TAIL = ' bytes; not in this prompt). Read ';

    private const POINTER_CLIP = ' [clipped]';

    /**
     * The instruction files this session deferred to a pointer instead of
     * inlining, real path => size in bytes. A subset of {@see $emittedPaths}
     * (the pointer IS what was put in front of the model), kept apart so a
     * second `@import` of the same file is answered with the pointer again
     * rather than with an "already included" note that would be false.
     *
     * @var array<string, int>
     */
    private array $deferredPaths = [];

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
     * that was weaker than it looked. Each of `loadRoot()`,
     * `loadAncestorRoots()`, `loadForced()` and `loadForPath()` refuses a
     * candidate without a word to anyone, and the
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

    /**
     * The {@see refusedPaths()} entries already put on the user's screen
     * mid-session ({@see announceMidSessionRefusals()}), so each is said
     * once. Carried across a forked tool child like {@see $emittedPaths}
     * ({@see announcedRefusals()}, {@see markRefusalsAnnounced()}).
     *
     * @var array<string, true>
     */
    private array $announcedRefusals = [];

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
     * Each entry is one document as {@see loadDocuments()} returns it.
     *
     * @var list<array{path: string, body: ?string, bytes: int}>|null
     */
    private ?array $rootCache = null;

    /** @var list<array{path: string, body: ?string, bytes: int}>|null */
    private ?array $forcedCache = null;

    /**
     * Memoized answer to {@see ancestorRoot()}, with its own resolved flag
     * because `null` is a real answer ("no monorepo parent is in scope") and
     * not "not computed yet".
     */
    private bool $ancestorRootResolved = false;

    private ?string $ancestorRoot = null;

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
     * MONOREPO PARENTS ARE READ FIRST (P8.11). Until this change `$repoRoot` was
     * both the only directory consulted and the only boundary, so pointing
     * `--root` at one library of a monorepo dropped the monorepo's own
     * conventions from the system prompt entirely and recorded NOTHING — not
     * even in {@see refusedPaths()}, because a file that is never a candidate is
     * never refused. MEASURED on this checkout before the change, which is its
     * own fixture (the monorepo root ships `CLAUDE.md` + `AGENTS.md`;
     * `sugar-crush/` ships neither):
     *
     *     new InstructionFileLoader('<mono>/sugar-crush')->loadRoot()  =>  []
     *     new InstructionFileLoader('<mono>')->loadRoot()              =>  1 document, 25,110 B
     *
     * Both figures are of THIS repository at the time of the change and will
     * drift with its docs; the shape — everything versus nothing — is the claim.
     * {@see ancestorRoot()} owns where the upward walk stops and why, which is a
     * containment question rather than a convenience one.
     *
     * ORDER IS GENERAL BEFORE SPECIFIC: each ancestor directory outermost-first,
     * then `$repoRoot`'s own files last. Later text dominates in a prompt, so
     * the most specific instructions are the ones that end up nearest the
     * conversation. Dedup is the existing `$emittedPaths` set keyed by REALPATH,
     * so the layout the {@see refusedPaths()} docblock calls natural — a
     * per-library `CLAUDE.md` symlinked to the monorepo's shared one — now
     * delivers those bytes once, from the ancestor pass, instead of nowhere.
     * (The per-library link is still refused by the `$repoRoot` gate below, and
     * still recorded; what changed is that the content arrives anyway.)
     *
     * A document over {@see MAX_DOCUMENT_BYTES} is returned as its
     * {@see pointer()} line rather than its body; {@see loadDocuments()} is the
     * path-keyed form that tells the two apart.
     *
     * @return string[] Contents of whichever ancestor and root files exist and
     *                  resolve inside their own boundary (missing files, files
     *                  resolving outside it, and files already inlined by an
     *                  earlier file's import, are skipped)
     */
    public function loadRoot(): array
    {
        if ($this->rootCache !== null) {
            return self::bodies($this->rootCache);
        }

        // Ancestors first, and gated against the ANCESTOR root rather than
        // $repoRoot: an ancestor file is by construction outside $repoRoot, so
        // reusing that boundary would refuse every one of them. Two boundaries,
        // each naming the domain it bounds — $repoRoot still bounds $repoRoot's
        // own entries below, which is the gate the measured escapes closed and
        // is deliberately NOT widened here.
        $contents = $this->loadAncestorRoots();

        $rootFiles = [
            $this->repoRoot . '/CLAUDE.md',
            $this->repoRoot . '/AGENTS.md',
        ];

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
            $raw = $this->readBounded($realPath, $path, $bytes);
            if ($raw === null) {
                $contents[] = ['path' => $path, 'body' => null, 'bytes' => (int) $bytes];
            } elseif ($raw === false) {
                $contents[] = ['path' => $path, 'body' => '', 'bytes' => 0];
            } else {
                [$doc, $note] = $this->utf8Document($raw, $path);
                $body = $this->expandImports($doc, dirname($path), $this->repoRoot) . $note;
                $contents[] = ['path' => $path, 'body' => $body, 'bytes' => strlen($body)];
            }
        }

        $this->rootCache = $contents;

        return self::bodies($contents);
    }

    /**
     * Every whole-session instruction document, in prompt order — the ancestor
     * and root files {@see loadRoot()} reads, then the forced matches
     * {@see loadForced()} reads — each with the path it was read from.
     *
     * THE PATH-KEYED SIBLING OF THOSE TWO, and the one the system-prompt splice
     * reads, because pricing a document against a budget is only half the job:
     * a document that does not fit must be NAMED in the pointer that replaces
     * it, and a bare list of strings has lost which file each one was. The two
     * string-list methods keep their contract for their other callers.
     *
     * `body` is null when this loader did not read the file at all because it is
     * over {@see MAX_DOCUMENT_BYTES}; the refusal is already recorded and the
     * caller owes the model a {@see pointer()} line for it. `bytes` is the
     * file's size on disk in that case and the expanded body's length
     * otherwise. Same memoized reads as the two string-list methods — calling
     * both forms reads nothing twice.
     *
     * @return list<array{path: string, body: ?string, bytes: int}>
     */
    public function loadDocuments(): array
    {
        return [...$this->rootDocuments(), ...$this->forcedDocuments()];
    }

    /**
     * Record that a document this loader DID read was nonetheless not inlined,
     * because the caller's own budget had no room for it.
     *
     * The system-prompt splice prices FRAMED, post-escape bytes and knows the
     * budget left after the documents before it, neither of which this class
     * can see — so it is the splice that decides and this map that remembers,
     * keeping {@see refusedPaths()} the one place a deferred document can be
     * asked about whichever side made the call. Re-recording the same path
     * overwrites, so a splice that runs once per agentic step adds one entry.
     */
    public function recordDeferral(string $path, string $why): void
    {
        $this->refusedPaths[$path] = $why;
    }

    /**
     * The one line that stands in for an instruction file that is not inlined:
     * what it is, how big, and the path to Read it from.
     *
     * Spelled HERE and nowhere else, so a whole document the splice defers and
     * an `@import` deferred at its own site ({@see ImportResolver::deferredImport()})
     * tell the model the same thing in the same words. The path is the payload
     * and is therefore last, escaped through {@see PromptFence::escape()} (it is
     * a repository-chosen string bound for the system prompt), and kept whole
     * whenever it fits {@see MAX_POINTER_BYTES}; only a path that cannot fit even
     * alone — over 950 bytes — clips the line. Held to that ceiling by
     * construction, which is what lets the splice reserve room for pointers
     * before it prices the first document.
     */
    public static function pointer(string $path, int $bytes): string
    {
        $line = self::POINTER_HEAD . number_format($bytes) . self::POINTER_TAIL . PromptFence::escape($path);

        if (strlen($line) <= self::MAX_POINTER_BYTES) {
            return $line;
        }

        return mb_strcut($line, 0, self::MAX_POINTER_BYTES - strlen(self::POINTER_CLIP), 'UTF-8') . self::POINTER_CLIP;
    }

    /** The ceiling {@see pointer()} enforces, for a caller pricing pointer lines. */
    public static function maxPointerBytes(): int
    {
        return self::MAX_POINTER_BYTES;
    }

    /**
     * @param list<array{path: string, body: ?string, bytes: int}> $documents
     * @return list<string>
     */
    private static function bodies(array $documents): array
    {
        return array_map(
            static fn(array $document): string => $document['body'] ?? self::pointer($document['path'], $document['bytes']),
            $documents,
        );
    }

    /**
     * The read-side ceiling, applied before a document's bytes are read.
     *
     * Returns the document's body, or null when it is over
     * {@see MAX_DOCUMENT_BYTES} — measured with a stat first, so a multi-GB file
     * is never loaded to be measured, and read bounded second, so a file that
     * grows between the two still cannot deliver more than the ceiling plus the
     * one byte that proves it overran. `false` keeps the read-failure meaning
     * each caller already gave it. On null the refusal is recorded, the file is
     * marked deferred, and `$bytes` holds the size the pointer will report.
     */
    private function readBounded(string $realPath, string $spelledPath, ?int &$bytes): string|false|null
    {
        $size = filesize($realPath);
        if ($size !== false && $size > self::MAX_DOCUMENT_BYTES) {
            $bytes = $size;
            $this->defer($realPath, $spelledPath, $size);

            return null;
        }

        $raw = file_get_contents($realPath, false, null, 0, self::MAX_DOCUMENT_BYTES + 1);
        if ($raw !== false && strlen($raw) > self::MAX_DOCUMENT_BYTES) {
            $bytes = $size !== false ? max($size, strlen($raw)) : strlen($raw);
            $this->defer($realPath, $spelledPath, $bytes);

            return null;
        }

        $bytes = $raw === false ? 0 : strlen($raw);

        return $raw;
    }

    private function defer(string $realPath, string $spelledPath, int $bytes): void
    {
        $this->deferredPaths[$realPath] = $bytes;
        $this->emittedPaths[$realPath] = true;
        $this->refusedPaths[$spelledPath] = 'is ' . number_format($bytes) . ' bytes, over the '
            . number_format(self::MAX_DOCUMENT_BYTES) . '-byte instruction-document ceiling; not read, '
            . 'deferred to a pointer line';
    }

    /**
     * The memoized ancestor-then-root documents {@see loadRoot()} reads — the
     * read loop stays in that method, which is the read decision the docs and
     * the containment census credit with it; this only hands its records out.
     *
     * @return list<array{path: string, body: ?string, bytes: int}>
     */
    private function rootDocuments(): array
    {
        if ($this->rootCache === null) {
            $this->loadRoot();
        }

        return $this->rootCache ?? [];
    }

    /**
     * The memoized forced-instruction documents {@see loadForced()} reads,
     * handed out the same way as {@see rootDocuments()}.
     *
     * @return list<array{path: string, body: ?string, bytes: int}>
     */
    private function forcedDocuments(): array
    {
        if ($this->forcedCache === null) {
            $this->loadForced();
        }

        return $this->forcedCache ?? [];
    }

    /**
     * The monorepo root whose instruction files are in scope for this
     * `$repoRoot`, or null when there is none.
     *
     * WHERE THE WALK STOPS IS THE WHOLE DESIGN, and it is a containment question
     * rather than a convenience one: an upward walk with no floor reads whatever
     * `CLAUDE.md` it passes into a system prompt, and `/` is not a bound. Four
     * rules, in the order they are checked, each returning null rather than
     * guessing:
     *
     * 1. `$repoRoot` does not resolve — a boundary that will not resolve is not
     *    a boundary, the same answer {@see loadForPath()} gives.
     * 2. `$repoRoot` IS ITSELF A CHECKOUT (`.git` present, directory or file).
     *    This is the ambiguity a path-repo checkout or a submodule creates, and
     *    it is answered conservatively: a library that is its own working tree
     *    has no monorepo parent, so its parent directory is somebody else's
     *    filesystem and nothing above it is read. `file_exists` rather than
     *    `is_dir` because a worktree and a submodule both spell `.git` as a
     *    FILE, and treating those as "not a checkout" would walk straight out
     *    of them.
     * 3. NO `.git` ANYWHERE ABOVE. Then there is no enclosing project and the
     *    walk yields nothing — this, not a depth limit, is what makes reading a
     *    user's home directory impossible for an ordinary `--root /tmp/scratch`:
     *    the walk needs a positive VCS marker to stop AT, and absent one it
     *    returns null instead of climbing to `/`.
     * 4. THE WALK REACHES the user's home directory — at it or above it, which
     *    is stronger than the rule this started as and had to be. A home under
     *    version control is a dotfiles repo, not a monorepo, and its `CLAUDE.md`
     *    is a personal-tier file; adopting it as a PROJECT tier would silently
     *    import one repository's instructions into every unrelated project
     *    inside it. The first version compared only the MARKER against `$HOME`,
     *    which left the outcome its own rationale forbids reachable one level
     *    up. MEASURED: a checkout at `<b>/A`, `HOME=<b>/A/home` with no `.git`
     *    of its own, `--root <b>/A/home/proj/lib` — the walk passed straight
     *    THROUGH `$HOME`, stopped at `<b>/A`, and `$HOME/CLAUDE.md` was emitted
     *    as a project-tier document. So the home check now runs on every step of
     *    the walk and BEFORE the marker test, which subsumes the marker case
     *    (a home that is itself a checkout is rejected at the same step it would
     *    have been matched) and closes the pass-through one. The consequence,
     *    stated because it is a real narrowing: nothing at or above `$HOME` is
     *    ever project tier, so a monorepo that encloses the user's home is not
     *    discoverable — the conservative answer rules 2 and 3 already give.
     *    Skipped when {@see \SugarCraft\Crush\Support\HomeDirectory::resolved()}
     *    cannot tell whose home this is, because a guard that cannot identify
     *    its subject must not invent one.
     *
     * The answer is memoized, and the reason is the OBSERVABLE one rather than
     * a speed argument: {@see loadRoot()} memoizes too, but this is also the
     * pull-based seam a display would read, and the walk's inputs are files on
     * disk that a build step can create or delete mid-session — so re-walking
     * per call would let two callers in one session get two DIFFERENT answers
     * for the same root. That is what
     * {@see \SugarCraft\Crush\Tests\Context\InstructionFileLoaderTest::testTheAncestorRootIsMemoizedAgainstAMarkerThatDisappearsMidSession()}
     * pins, by moving the marker between two calls; an `assertSame($x(), $x())`
     * would pass against a pure function and prove nothing, which is what the
     * first version of that test did.
     */
    public function ancestorRoot(): ?string
    {
        if ($this->ancestorRootResolved) {
            return $this->ancestorRoot;
        }

        $this->ancestorRootResolved = true;

        $root = realpath($this->repoRoot);
        if ($root === false || file_exists($root . '/.git')) {
            return $this->ancestorRoot = null;
        }

        $home = HomeDirectory::resolved();
        $realHome = \is_string($home) ? realpath($home) : false;

        $dir = \dirname($root);
        while (true) {
            // Rule 4, checked BEFORE the marker and on EVERY step: reaching
            // $HOME ends the walk whether or not $HOME is itself a checkout.
            // Marker-only was the first version and it let the walk pass
            // straight through a home whose parent was a checkout — see the
            // measured case in this method's docblock. Equality against the
            // RESOLVED home, so a home reached through a symlinked path is
            // still recognised.
            if ($realHome !== false && $dir === $realHome) {
                return $this->ancestorRoot = null;
            }

            if (file_exists($dir . '/.git')) {
                return $this->ancestorRoot = $dir;
            }

            $parent = \dirname($dir);
            if ($parent === $dir) {
                return $this->ancestorRoot = null; // reached `/` with no marker
            }
            $dir = $parent;
        }
    }

    /**
     * Read `CLAUDE.md`/`AGENTS.md` from every directory between
     * {@see ancestorRoot()} and `$repoRoot`, outermost first.
     *
     * INCLUSIVE of the ancestor root, EXCLUSIVE of `$repoRoot` — `$repoRoot`'s
     * own two files are read by {@see loadRoot()} afterwards, under
     * `$repoRoot`'s own gate, and reading them here as well would either
     * duplicate them or move which boundary judges them.
     *
     * The intermediate directories are included, not just the two ends: in
     * `<mono>/packages/web/app` rooted at `app`, `packages/web/CLAUDE.md` is the
     * file most likely to matter, and stopping at the extremes would skip
     * exactly it.
     *
     * CONTAINMENT is judged against the ancestor root, which is the boundary the
     * walk established. Every candidate is an ENTRY question — may this file be
     * read — so {@see ContainedPath::within()}, matching the other four entry
     * gates in this class; a file cannot resolve onto a directory boundary, so
     * the predicate choice is not observable here either. A candidate resolving
     * outside the ancestor root (a `packages/web/CLAUDE.md` symlinked to
     * `/etc/…`) is refused AND RECORDED, so this pass does not reintroduce the
     * silent-escape shape the class docblock describes.
     *
     * @return list<array{path: string, body: ?string, bytes: int}>
     */
    private function loadAncestorRoots(): array
    {
        $ancestorRoot = $this->ancestorRoot();
        if ($ancestorRoot === null) {
            return [];
        }

        $root = realpath($this->repoRoot);
        if ($root === false) {
            return []; // unreachable: ancestorRoot() resolved it to get here
        }

        // Collected climbing UP from $repoRoot's parent, then reversed, so the
        // emitted order is outermost-first without a second walk.
        $dirs = [];
        for ($dir = \dirname($root); $dir !== $ancestorRoot; $dir = \dirname($dir)) {
            $parent = \dirname($dir);
            if ($parent === $dir) {
                // Unreachable while $ancestorRoot is a true ancestor of $root;
                // a bare `break` rather than a walk to `/` if that ever changes.
                break;
            }
            $dirs[] = $dir;
        }
        $dirs[] = $ancestorRoot;

        $contents = [];
        foreach (array_reverse($dirs) as $dir) {
            foreach (['CLAUDE.md', 'AGENTS.md'] as $filename) {
                $path = $dir . '/' . $filename;
                if (!is_file($path)) {
                    continue;
                }

                if (!ContainedPath::within($path, $ancestorRoot)) {
                    $this->refusedPaths[$path] = 'an ancestor instruction file resolving outside the '
                        . 'enclosing checkout (' . $ancestorRoot . ')';

                    continue;
                }

                $realPath = realpath($path) ?: $path;
                if (isset($this->emittedPaths[$realPath])) {
                    continue;
                }

                $this->emittedPaths[$realPath] = true;

                // Read RESOLVED, import base SPELLED — both for the reasons
                // loadRoot() states. The import gate is handed $ancestorRoot,
                // NOT $repoRoot, and that is load-bounded rather than incidental:
                // an ancestor file's own siblings are outside $repoRoot by
                // construction, so $repoRoot would refuse every `@import` it
                // makes — which is precisely the 6,614 B loss expandImports()'s
                // docblock measures. A file's imports are bounded by ITS OWN
                // checkout; $repoRoot's own files still pass $repoRoot on the
                // call in loadRoot(). (An earlier revision of this comment
                // asserted the opposite of the line below it; widening the
                // boundary to \dirname($ancestorRoot) is a mutation that
                // testAnAncestorImportLeavingTheEnclosingCheckoutIsStillBlocked
                // kills, which is what makes the argument checkable.)
                $raw = $this->readBounded($realPath, $path, $bytes);
                if ($raw === null) {
                    $contents[] = ['path' => $path, 'body' => null, 'bytes' => (int) $bytes];
                } elseif ($raw === false) {
                    $contents[] = ['path' => $path, 'body' => '', 'bytes' => 0];
                } else {
                    [$doc, $note] = $this->utf8Document($raw, $path);
                    $body = $this->expandImports($doc, \dirname($path), $ancestorRoot) . $note;
                    $contents[] = ['path' => $path, 'body' => $body, 'bytes' => strlen($body)];
                }
            }
        }

        return $contents;
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
     * A match over {@see MAX_DOCUMENT_BYTES} is returned as its {@see pointer()}
     * line rather than its body — a glob such as `docs/*.md` reaches a generated
     * reference file as easily as a convention; see {@see loadDocuments()}.
     *
     * @return string[] Contents of matching in-repo files (patterns with no
     *                  matches, matches outside repoRoot, and matches already
     *                  emitted elsewhere are skipped)
     */
    public function loadForced(): array
    {
        if ($this->forcedCache !== null) {
            return self::bodies($this->forcedCache);
        }

        if ($this->forcedInstructions === []) {
            $this->forcedCache = [];

            return [];
        }

        $contents = [];
        foreach ($this->forcedInstructions as $pattern) {
            // Reject absolute paths — they bypass repoRoot and are a security risk.
            if (str_starts_with($pattern, '/')) {
                continue;
            }

            // Only the configured pattern is a pattern; the checkout path is
            // literal. Unescaped, a root such as `~/work/[acme]/site` turns
            // `[acme]` into a character class and every forced instruction
            // silently matched nothing — or matched a sibling tree that
            // containment then refused (audit 15d-22). glob() honours
            // backslash escapes, so the four characters it treats as special
            // without GLOB_BRACE are escaped (a `]` is only special after an
            // unescaped `[`). Matches come back with the real, unescaped path.
            $fullPattern = addcslashes($this->repoRoot, '\\*?[') . '/' . $pattern;
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

                $raw = $this->readBounded($realPath, $path, $bytes);
                if ($raw === null) {
                    $contents[] = ['path' => $path, 'body' => null, 'bytes' => (int) $bytes];
                } elseif ($raw !== false) {
                    $this->emittedPaths[$realPath] = true;
                    // A glob can match a binary as easily as a document, and
                    // one invalid byte here used to fail every request
                    // (audit 15d-08) — see utf8Document().
                    [$doc, $note] = $this->utf8Document($raw, $path);
                    $body = $doc . $note;
                    $contents[] = ['path' => $path, 'body' => $body, 'bytes' => strlen($body)];
                }
            }
        }

        $this->forcedCache = $contents;

        return self::bodies($contents);
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
     * {@see ContainedPath::within()}. A refused candidate is SKIPPED and the
     * walk continues, matching {@see loadForced()}. The model is not told
     * (its callers are tool results); the user is, since audit R1's last
     * residual — one transcript notice per refused or deferred instruction
     * file, raised through the mid-session notice seam
     * ({@see announceMidSessionRefusals()}).
     *
     * @param string $touchedPath Absolute path to the file that was touched
     * @return string|null The nested instruction file content, or null if none
     *                     found, if the touched path is outside repoRoot, or if
     *                     the only candidate resolves outside it
     */
    public function loadForPath(string $touchedPath): ?string
    {
        // Every exit below tells the user about the instruction files THIS
        // call refused (audit R1); see announceMidSessionRefusals(). A finally
        // rather than a wrapper method, so the walk's contained reads stay in
        // the method docs/MEMORY.md credits them to.
        $before = $this->refusedPaths;

        try {
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
            // AN ANCESTOR `CLAUDE.md` IS NOW READ — BY A DIFFERENT ROUTE, AND THIS
            // GATE IS STILL THE RIGHT ANSWER HERE. {@see loadAncestorRoots()} reads
            // files above $repoRoot deliberately, which reads at first glance like
            // the escape above being reopened. It is not the same mechanism: that
            // pass climbs from $repoRoot to a POSITIVE VCS MARKER, decides once at
            // session start, and cannot be steered; this walk starts wherever the
            // agent's last tool call happened to point, so relaxing it would hand
            // the choice of what lands in the system prompt to an arbitrary touched
            // path. Pinned by
            // `InstructionFileLoaderTest::testLoadForPathStillRefusesAWalkStartingOutsideTheCheckoutDespiteTheGitAncestor()`.
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
                        // Bounded like the session documents: a nested file this
                        // size would otherwise ride whole into a tool result.
                        $raw = $this->readBounded($realPath, $fullPath, $bytes);
                        if ($raw === null) {
                            return self::pointer($fullPath, (int) $bytes);
                        }
                        if ($raw === false) {
                            return null;
                        }

                        [$doc, $note] = $this->utf8Document($raw, $fullPath);

                        return $this->expandImports($doc, dirname($fullPath), $repoRoot) . $note;
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
        } finally {
            $this->announceMidSessionRefusals($before, $touchedPath);
        }
    }

    /**
     * The row {@see announceMidSessionRefusals()} puts in the transcript:
     * `%s` the instruction file, `%s` the path whose touch reached it, `%s`
     * why it was left out (a {@see refusedPaths()} reason).
     */
    public const MID_SESSION_REFUSAL_NOTICE_FORMAT =
        'Instruction file %s was left out of the model\'s context when a tool touched %s (%s).';

    /**
     * Tell the USER about each instruction file this {@see loadForPath()}
     * call refused or deferred (audit R1, the residual after the launch
     * notice).
     *
     * The launch drains {@see refusedPaths()} once
     * ({@see \SugarCraft\Crush\Cli\Bootstrap::reportPromptBudgetDeferrals()}),
     * and that covers the root, ancestor, forced and imported documents. A
     * NESTED `CLAUDE.md`/`AGENTS.md` arrives only when a tool touches a path
     * under it, after the terminal is taken, so its refusal — a link out of
     * the checkout, a file over {@see MAX_DOCUMENT_BYTES}, an `@import` that
     * does not fit — reached the model as a pointer line or as nothing, and
     * the user who wrote the file and expects it obeyed was told nothing.
     *
     * Through {@see RuntimeNoticeSink}, the mid-session transcript seam:
     * this runs in whichever process the tool does — the TUI, the forked turn
     * child, a forked tool child — and the sink's cross-fork transport is
     * what carries the row to the parent's transcript, as a UI-only notice.
     *
     * ONLY ENTRIES THIS CALL ADDED, and never the touched path's own: that
     * one says no walk was started (a path outside the checkout, a directory
     * that does not resolve), which is about the file the agent opened, not
     * about any instruction file, and reading `/etc/hosts` is not news. An
     * entry already in the map before the call was decided earlier — by the
     * launch, which has its own notice, or by an earlier touch, which
     * announced it then. And ONCE per entry for the session
     * ({@see $announcedRefusals}): a forked turn starts from the parent's
     * loader, so a file refused in one turn is announced again in a later
     * one only because the parent never learns of the first.
     *
     * @param array<string, string> $before {@see $refusedPaths} as it stood when the call began
     */
    private function announceMidSessionRefusals(array $before, string $touchedPath): void
    {
        foreach ($this->refusedPaths as $path => $why) {
            $path = (string) $path;
            if ($path === $touchedPath
                || isset($this->announcedRefusals[$path])
                || (isset($before[$path]) && $before[$path] === $why)
            ) {
                continue;
            }

            $this->announcedRefusals[$path] = true;
            RuntimeNoticeSink::warn(sprintf(
                self::MID_SESSION_REFUSAL_NOTICE_FORMAT,
                $this->underRoot($path),
                $this->underRoot($touchedPath),
                rtrim($why, '.'),
            ));
        }
    }

    /**
     * $path relative to the checkout when it is under it — `src/CLAUDE.md`,
     * not the checkout's absolute path — so a clipped notice row spends its
     * characters on the verdict, as the launch notice does. A path outside
     * the checkout keeps its full spelling, because there it IS the
     * information.
     */
    private function underRoot(string $path): string
    {
        $roots = array_unique([rtrim($this->repoRoot, '/') . '/', rtrim((string) realpath($this->repoRoot), '/') . '/']);
        foreach ($roots as $root) {
            if ($root !== '/' && str_starts_with($path, $root)) {
                return substr($path, \strlen($root));
            }
        }

        return $path;
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
     * The instruction files this loader declined to read — or read and did not
     * inline, for budget ({@see readBounded()}, {@see recordDeferral()}, and an
     * `@import` the gate deferred) — and why.
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
     * DRAINED AT LAUNCH since audit R1, by
     * {@see \SugarCraft\Crush\Cli\Bootstrap::reportPromptBudgetDeferrals()}:
     * one aggregate row naming each root, ancestor, forced or imported file the
     * prompt leaves out and why, after pricing a launch loader through
     * {@see \SugarCraft\Crush\Runtime::planInstructionDocuments()} so the
     * splice's budget verdicts are in the map too. Not through
     * `Bootstrap`'s directory collector, which is fed by the tiers whose
     * refusals are DIRECTORY-shaped; these are file-shaped. Entries
     * {@see loadForPath()} adds mid-session are surfaced as they are made,
     * one transcript notice each ({@see announceMidSessionRefusals()}).
     *
     * @return array<string, string> path as spelled => why it was not read
     */
    public function refusedPaths(): array
    {
        return $this->refusedPaths;
    }

    /**
     * The {@see refusedPaths()} entries already announced to the user
     * mid-session — the export half of {@see $announcedRefusals}, for
     * {@see \SugarCraft\Crush\Tools\CarriesSessionState}.
     *
     * @return list<string>
     */
    public function announcedRefusals(): array
    {
        return array_map(strval(...), array_keys($this->announcedRefusals));
    }

    /**
     * Union $paths into {@see $announcedRefusals} — a union for the reason
     * {@see markEmitted()} is one, and cast for the same reason.
     *
     * @param list<string|int> $paths
     */
    public function markRefusalsAnnounced(array $paths): void
    {
        foreach ($paths as $path) {
            if (\is_string($path) || \is_int($path)) {
                $this->announcedRefusals[(string) $path] = true;
            }
        }
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
     * An instruction document's bytes as valid UTF-8, and the note announcing
     * the repair ('' when the file was already valid).
     *
     * EVERY read in this class goes through here, because every one of them
     * lands in the system prompt and the system prompt is JSON-encoded into
     * every request: before this, one Latin-1 byte in a `CLAUDE.md` made
     * Guzzle throw "Malformed UTF-8 characters" on every turn of the session
     * (audit 15d-08). Scrubbed BEFORE the import expansion so each imported
     * file is judged on its own bytes ({@see ImportResolver} scrubs those), and
     * the note is returned separately so the caller appends it AFTER the
     * expansion — a note spelling a path is never itself read as an `@import`.
     *
     * @return array{0: string, 1: string}
     */
    private function utf8Document(string $raw, string $path): array
    {
        [$doc, $replaced] = Utf8Scrub::scrub($raw);
        if ($replaced === 0) {
            return [$doc, ''];
        }

        // Named relative to the checkout when it is inside it: the absolute
        // path would put this machine's directory layout into the prompt for
        // no information the model can use.
        $root = rtrim($this->repoRoot, '/') . '/';
        $shown = str_starts_with($path, $root) ? substr($path, strlen($root)) : $path;

        return [$doc, Utf8Scrub::notice($replaced, $shown)];
    }

    /**
     * Expand `@path` import references in freshly-read instruction content.
     *
     * $boundary IS THE CHECKOUT THE EXPANDED FILE BELONGS TO, not always
     * `$repoRoot`. It was `$repoRoot` unconditionally, and that was measurably
     * wrong the moment {@see loadAncestorRoots()} started reading files ABOVE
     * `$repoRoot`: the monorepo `CLAUDE.md` here opens with `@./AGENTS.md` and
     * `@./CONTRIBUTING.md`, both of which are its siblings and both of which the
     * `$repoRoot` boundary refused — MEASURED with `--root <mono>/sugar-crush`,
     * `loadRoot()` came back with 18,496 B and two `<import-blocked>` notes
     * against 25,110 B for the same file read from `<mono>`. `CONTRIBUTING.md`
     * (6,992 B on disk) was lost outright, because no other route emits it;
     * `AGENTS.md` survived only by being re-read as a SECOND top-level document
     * rather than inlined where its import sits. The 6,614 B gap between those
     * two totals is the NET DOCUMENT-SIZE DIFFERENCE — the lost body minus the
     * two blocked-import notes that replaced it — and is not the size of any
     * file, which is the arithmetic the first draft of this note mislabelled.
     * A file's imports are bounded by ITS OWN checkout; passing the boundary in
     * is what makes that true for both tiers, and it widens nothing for
     * `$repoRoot`'s own files, which still pass `$repoRoot`.
     *
     * A boundary-check closure is handed straight into
     * ImportResolver::expand(), which threads it through EVERY recursive
     * expansion call (not just the references present in the outermost
     * $content) -- so a reference that resolves outside $boundary is
     * blocked and replaced with an inline warning note no matter how many
     * `@import` hops deep it is found, mirroring Claude Code's approval-dialog
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
    private function expandImports(string $content, string $baseDir, string $boundary): string
    {
        // THE DOCUMENT'S CEILING IS SHARED BY EVERYTHING INLINED INTO IT (C3):
        // an import is priced against what is LEFT of MAX_DOCUMENT_BYTES after
        // the parent's own bytes and every import inlined before it, at every
        // depth, so thirty 10 KB imports cannot add up to a 300 KB document that
        // no single file check would have refused. Priced by stat, before the
        // read. The note replacing a deferred import is charged too, so the
        // running figure stays a statement about the bytes this expansion emits.
        $remaining = self::MAX_DOCUMENT_BYTES - strlen($content);

        $gate = function (string $realPath, string $pathFragment) use ($boundary, &$remaining): ?string {
            // ImportResolver `is_file()`-checks and `realpath()`s before calling
            // this, so $realPath is always a resolvable existing path and
            // within()'s re-resolution of it cannot change the verdict — it
            // costs one cached stat to keep this file free of a local idiom.
            if (!ContainedPath::within($realPath, $boundary)) {
                // Recorded as well as noted inline: this is the ONE refusal in
                // this class that was never silent, and leaving it out of the
                // map would make `refusedPaths()` an incomplete picture of the
                // same question.
                $this->refusedPaths[$realPath] = "an @import from '{$pathFragment}' resolving outside the "
                    . 'checkout (' . $boundary . ')';

                return "<import-blocked reason=\"outside-repo-root\">Import '{$pathFragment}' resolves to"
                    . " '{$realPath}', outside the repository root, and was not followed.</import-blocked>";
            }

            // Checked before the already-included note: a deferred file was put
            // in front of the model as a pointer, not as its body, so the honest
            // answer for a second import of it is the pointer again.
            if (isset($this->deferredPaths[$realPath])) {
                $note = ImportResolver::deferredImport($pathFragment, $realPath, $this->deferredPaths[$realPath]);
                $remaining -= strlen($note);

                return $note;
            }

            if (isset($this->emittedPaths[$realPath])) {
                return "<import-skipped reason=\"already-included\">Import '{$pathFragment}' is already"
                    . ' included in this prompt and was not repeated.</import-skipped>';
            }

            $size = filesize($realPath);
            if ($size !== false && $size > $remaining) {
                $this->deferredPaths[$realPath] = $size;
                $this->emittedPaths[$realPath] = true;
                $this->refusedPaths[$realPath] = "an @import from '{$pathFragment}' of " . number_format($size)
                    . ' bytes, more than the ' . number_format(max(0, $remaining)) . ' bytes left of its document\'s '
                    . number_format(self::MAX_DOCUMENT_BYTES) . '-byte instruction-document ceiling; not read, '
                    . 'deferred to a pointer at its import site';

                $note = ImportResolver::deferredImport($pathFragment, $realPath, $size);
                $remaining -= strlen($note);

                return $note;
            }

            $remaining -= (int) $size;
            $this->emittedPaths[$realPath] = true;

            return null; // in-repo, not yet seen, fits -- let ImportResolver expand it
        };

        return $this->importResolver->expand($content, $baseDir, 0, $gate);
    }
}
