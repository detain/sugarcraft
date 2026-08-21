<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Context;

use SugarCraft\Crush\Support\ContainedPath;

/**
 * Renders a structural map of the workspace — which directories hold code and
 * what namespace each one is — as a fenced `<repo-map>` block for the system
 * prompt (crush_code.md P8.8).
 *
 * Third of the {@see EnvironmentBlock} / {@see MemoryBlock} family: same
 * directory, same capture-then-render split, same
 * {@see \SugarCraft\Crush\Runtime::buildSystemPrompt()} fold. Like
 * `MemoryBlock` and unlike `EnvironmentBlock`, EVERYTHING is frozen at
 * {@see capture()} — `render()` touches no filesystem — so a package added
 * mid-session reaches the prompt on the next `Runtime`, not the next step.
 *
 * WHAT THIS REPLACES
 * ------------------
 * Upstream Crush builds its repo map from a language server: real symbols,
 * real references, resolved by something that understands the language. There
 * is no LSP in this port's hot path, so this block does NOT claim to be that.
 * It is a map of PLACES, not of symbols — it tells the model which directory
 * to look in and what namespace it will find there, and leaves finding the
 * symbol to Grep and Glob, which are already in the prompt. No `Mirrors`
 * citation is attached to anything here for that reason: there is no upstream
 * method this is a port of, and inventing one would misdescribe both.
 *
 * THE DESIGN DECISION, STATED BECAUSE THE ITEM OFFERED TWO ANSWERS
 * ---------------------------------------------------------------
 * P8.8 asks for two halves. For a single-lib root: parse
 * `vendor/composer/autoload_classmap.php`/`autoload_psr4.php`, or use a
 * declaration-line regex. For the monorepo root: parse SugarCraft's own
 * `docs/MATCHUPS.md` and `PROJECT_NAMES.md` into the block.
 *
 * The second half is not implemented as written, and the omission is the
 * decision rather than a shortfall. `sugar-crush` is a general-purpose coding
 * agent that people point at their own repositories; a parser for two
 * hand-maintained markdown files in ONE repository is inert for every other
 * user of it, and it binds a shipped feature to the formatting of documents
 * that are edited for human readers and carry no compatibility promise. What
 * those two documents encode about this repository — that it is a monorepo of
 * sub-packages, what each one is called, and what each one is for — every
 * sub-package already states first-hand in its own `composer.json` `name`,
 * `description` and `autoload.psr-4`. So the monorepo half is implemented
 * GENERICALLY, from those manifests, and this repository is simply one of the
 * repositories it happens to work on. Measured on this checkout it finds 58
 * packages and their namespaces without reading either markdown file.
 *
 * The FIRST half is likewise not taken literally, for a narrower reason.
 * `vendor/composer/autoload_psr4.php` exists only after `composer install` has
 * run, and it lists every DEPENDENCY's prefixes alongside the project's own,
 * so using it means installing first and then filtering the project's own
 * prefixes back out by path. The project's `composer.json` `autoload.psr-4` is
 * the same fact first-hand, needs no install, and cannot go stale against a
 * manifest edit — so that is what is read. `autoload_classmap.php` is not read
 * at all: outside `--optimize-autoloader` it carries only classmap/files
 * entries, so on a normal install it is empty of exactly the classes a map
 * would want.
 *
 * WHAT WAS DELIBERATELY NOT BUILT
 * -------------------------------
 *   - A per-CLASS listing. The declaration-line regex the item offers as an
 *     alternative is cheap to run, but `src/` here declares 304 top-level
 *     types; at one line each that is several times this whole block's budget,
 *     emitted on every step, to tell the model things `Glob 'src/&#42;&#42;/&#42;.php'`
 *     tells it on demand. The map stops at the directory.
 *   - A fallback for a repository with no `composer.json` anywhere. Such a
 *     root renders nothing. A bare directory listing would be something the
 *     model can get from one `ls`, whereas the directory-to-NAMESPACE binding
 *     is the part that is not discoverable without reading manifests — and a
 *     block that pads itself out with what a tool already returns is how the
 *     prompt grows without getting more useful. This is an intentional seam:
 *     a language whose layout convention is as machine-readable as PSR-4's
 *     would slot in as another scanner beside {@see scanSourceDirectories()},
 *     not as a rewrite of the block.
 *   - `autoload-dev`. Test directories are excluded, so `tests/` does not
 *     appear. The block describes the code under maintenance; a model that
 *     needs the test tree finds it in one Glob, and including it would roughly
 *     double the section for every package that ships one.
 *
 * ON PATHS THAT COME FROM CONTENT
 * -------------------------------
 * EVERY path this class opens comes from the repository, and the first
 * revision of this paragraph said the opposite. It read: "the sub-package scan
 * only ever joins a `scandir()` entry to the root, and a directory entry
 * cannot contain a separator, so it cannot escape." The premise is true and
 * the conclusion does not follow. A directory ENTRY cannot contain a
 * separator; the directory it NAMES can be a symlink, git stores one as a tree
 * entry of mode `120000`, and `git clone` materialises it. So a repository
 * committing one line —
 *
 *     peek -> ../victim-private
 *
 * — put that outside package's `autoload.psr-4` prefix and its `description`,
 * an unbounded string its author wrote, into every system prompt of the
 * session. MEASURED against the ungated build from a real `git clone`:
 *
 *     - peek/  ->  Victim\Private\  INTERNAL ONLY - acme-bank settlement …
 *
 * The gate that shipped with that sentence covered the `autoload.psr-4` VALUES
 * and not the WALK, which is the distinction the sentence talked itself out
 * of: the path STRING was caller-supplied, and the file it RESOLVES TO was
 * repository-chosen. Three paths are repository-chosen here and all three are
 * now gated:
 *
 *   - a `scandir()` entry under the root, and each `repositories: {type:
 *     path}` url the root manifest names — the candidate sub-package
 *     directories, each refused unless {@see ContainedPath::below()} puts it
 *     strictly inside the root. `below()` and not `within()` because a
 *     sub-package cannot BE the root: `peek -> .` would otherwise list the
 *     root as a member of itself.
 *   - every `composer.json` opened, gated inside {@see readManifest()} rather
 *     than at its two callers, so the compare cannot be forgotten by a third.
 *     This is what closes the escape above even where the directory gate does
 *     not apply — `alpha/composer.json -> ../../outside/composer.json` is the
 *     same attack one level down.
 *   - a manifest's `autoload.psr-4` VALUES, which {@see
 *     scanSourceDirectories()} would otherwise follow anywhere. `within()`
 *     here and not `below()`, because a prefix mapped to `""` legitimately
 *     means the package root itself.
 *
 * `RecursiveDirectoryIterator` is not given `FOLLOW_SYMLINKS`, so once a
 * source root is admitted the walk beneath it cannot leave either.
 *
 * ON THE MISSING AND THE UNREADABLE
 * ---------------------------------
 * Every input is optional and every failure is silent by design. A root that
 * does not exist, a `composer.json` that is not valid JSON, a `psr-4` entry
 * pointing at a directory that was never created — each drops just the entry
 * it belongs to, and a block with no entries at all renders the EMPTY STRING
 * rather than an empty fence. This is a describe-what-is-there block, and the
 * alternative would be to report a repository's own manifest problems into
 * every turn of a conversation that is not about them.
 */
final readonly class RepoMapBlock
{
    /**
     * Retained bytes of rendered entry lines, PER SECTION.
     *
     * Per section rather than shared, following {@see EnvironmentBlock}'s
     * staged/unstaged split for the same reason: a workspace that has both a
     * long package list and a long source tree must not have the first starve
     * the second. On the two shapes this block was built for they are close to
     * mutually exclusive — a monorepo root is usually a `metapackage` with no
     * autoload of its own (this repository's root is exactly that), and a leaf
     * package has no sub-packages — so in practice one section is empty and
     * the whole block is bounded by one of these, not two.
     *
     * WHY 8192, AND WHY THAT IS NOT THE TIER IT LOOKS LIKE. {@see
     * EnvironmentBlock::DIFF_MAX_BYTES} is argued as a rung on a ladder that
     * ranks content by how much the model asked for it — a note list below a
     * diff below a tool result. This block does not sit on that ladder, and
     * saying so is the point rather than an evasion. That ladder ranks things
     * whose size GROWS WITH USE: memory notes accumulate as the user writes
     * them, a diff grows as the agent edits, so for both of those the cap IS
     * the feature and what renders under it is a sample. A repository's shape
     * is not like that. It is fixed for the session, it does not respond to
     * anything the agent does, and the normal case is that NOTHING is dropped.
     * So this constant is sized to the largest real input measured rather than
     * placed on the growth ladder, and the two arguments do not compete.
     *
     * THE MEASUREMENT: the SugarCraft monorepo root, 58 sub-packages, renders
     * 6,878 B of package lines at the {@see MAX_ENTRY_BYTES} clip below —
     * 1,314 B of headroom, about eleven more packages at this repository's
     * mean line. `sugar-crush` itself as a single-package root renders 33
     * source-directory lines in 1,915 B, a quarter of the cap. Both figures
     * are the sum of the LINES, which is what this constant is compared
     * against; the joined strings are 57 and 32 bytes longer, because the
     * newline separators are outside the budget (see {@see renderSection()}). DOMAIN: those
     * are two real repositories, not a property of monorepos; a workspace with
     * several hundred packages will be truncated, and the header says so in
     * that case rather than pretending the list is complete.
     */
    public const MAX_SECTION_BYTES = 8192;

    /**
     * Per-line ceiling, in bytes, INCLUDING the truncation marker.
     *
     * Applied to the ASSEMBLED line rather than to the description field
     * alone, which is the lesson {@see MemoryBlock::MAX_ENTRY_BYTES} paid for:
     * clipping only the field that usually carries the bytes leaves every
     * other field unbounded, and here `description` is not even the only
     * unbounded one — a namespace prefix and a directory path are both
     * author-chosen strings with no length limit. Clipping the line bounds
     * whichever field turns out to carry the bytes.
     *
     * 120 leaves ~70 B for the description after a typical
     * `- candy-core/  ->  SugarCraft\Core\  ` prefix. MEASURED on this
     * repository's 58 manifests, whose descriptions have a 159 B median (the
     * sorted middle pair is 158 and 160) and a 165 B mean — an earlier
     * revision said "a 160 B median", which is the upper of the two middle
     * values and not their midpoint: 49
     * of the 58 lines clip, for 6,878 B. Raising the cap buys progressively
     * less — 140 costs +947 B to un-clip four more lines, 160 costs +1,836 B
     * for seven and lands at 8,714 B, over {@see MAX_SECTION_BYTES}, at which
     * point packages start being DROPPED instead of clipped. That is the
     * trade the figure is chosen on: a clipped line still carries the
     * directory and the namespace, which is the half the model cannot guess,
     * while a dropped package carries nothing.
     */
    public const MAX_ENTRY_BYTES = 120;

    /**
     * Ceiling on `composer.json` files OPENED while scanning for sub-packages.
     *
     * An I/O bound, not a render bound, and the distinction is why it is a
     * separate constant from {@see MAX_SECTION_BYTES}: that one stops the
     * prompt from growing, this one stops {@see capture()} from opening ten
     * thousand files in a root somebody pointed at their home directory. The
     * two are deliberately not derived from each other — the byte cap can be
     * retuned without changing how much of the disk is touched.
     *
     * OPENED, not FOUND, and the first revision counted the wrong one: it
     * broke on `count($packages) >= MAX_PACKAGES`, so a root of ten thousand
     * manifest-less directories opened ten thousand `composer.json` paths and
     * found nothing, which is the exact cost the sentence above says this
     * bound exists to refuse. It also bounds how many `repositories` entries
     * are glob-expanded, for the same reason on the other input.
     *
     * This constant's VALUE is not what its argument turns on — the argument
     * is that there is a bound at all — so {@see
     * \SugarCraft\Crush\Tests\Context\RepoMapBlockTest} pins it as a literal
     * rather than deriving the cap test's expectation from it and calling that
     * coverage. {@see MAX_ENTRY_BYTES} is the one whose value IS the argument.
     */
    public const MAX_PACKAGES = 256;

    /**
     * Ceiling on `.php` files VISITED across all PSR-4 source roots.
     *
     * Same reasoning as {@see MAX_PACKAGES}, for the recursive half. A `psr-4`
     * entry mapping a prefix to `""` or `"."` is legal and points the walk at
     * the whole repository, so this is the bound that keeps a malformed
     * manifest from turning prompt assembly into a full-tree crawl. Generous
     * because it is a backstop and not a policy: `src/` here is 285 files, so
     * a normal package is about SEVENTY times under it — not the "two orders
     * of magnitude" an earlier revision of this sentence claimed, which was
     * the arithmetic being rounded in the direction that flattered the bound.
     *
     * Both figures in this file that restate `src/`'s census — 285 files here
     * and 304 top-level types above — are asserted against the derivation by
     * {@see \SugarCraft\Crush\Tests\Tools\BuiltInToolCorpusTest::testTheSecondaryDeclarationCensus()},
     * because they shipped STALE: they were written as 284/303 in the same
     * commit that moved the census to 285/304 thirty lines away in its own
     * message. A restated number no test asserts is the recurring defect of
     * this codebase, and this one occurred inside the file that caused the
     * bump.
     */
    public const MAX_SOURCE_FILES = 20000;

    /**
     * Appended to a line cut at {@see MAX_ENTRY_BYTES}, and paid for OUT OF
     * that budget rather than added on top of it, so the documented ceiling is
     * the real one.
     */
    private const TRUNCATION_MARKER = ' […truncated]';

    /**
     * Directory names never descended into, in either scan.
     *
     * `vendor` because a dependency tree is not this repository's map and
     * because reading it would list every installed package as if it were a
     * sub-package of the workspace; `node_modules` for the same reason on the
     * other side of a polyglot repo, where it is also the single directory
     * most likely to make {@see MAX_SOURCE_FILES} bite. Dot-directories are
     * skipped separately, by prefix.
     */
    private const SKIP_DIRS = ['vendor', 'node_modules'];

    /**
     * @param list<array{dir: string, name: string, namespace: ?string, description: string}> $packages
     * @param list<array{path: string, namespace: string, files: int}>                        $sourceDirectories
     */
    private function __construct(
        private array $packages,
        private array $sourceDirectories,
    ) {}

    /**
     * Read the workspace at `$root`.
     *
     * Takes the root explicitly and never falls back to `getcwd()`: on a
     * `--root <lib>` run the map has to describe the directory the tools are
     * jailed to, which is the same rule {@see EnvironmentBlock::capture()}
     * follows and which
     * `tests/Integration/BinSugarcrushWiringTest::testNoRootResolvingSiteFallsBackToBareGetcwd()`
     * enforces across `src/`.
     */
    public static function capture(string $root): self
    {
        $root = rtrim($root, '/');

        if ($root === '' || !is_dir($root)) {
            return new self([], []);
        }

        return new self(self::scanPackages($root), self::scanSourceDirectories($root));
    }

    /** An explicitly empty map, for a session with no workspace to describe. */
    public static function empty(): self
    {
        return new self([], []);
    }

    /**
     * Sub-packages found under the root — its immediate subdirectories and the
     * path repositories its manifest names — ordered by directory name,
     * relative to the root, and before any render cap is applied.
     *
     * @return list<array{dir: string, name: string, namespace: ?string, description: string}>
     */
    public function packages(): array
    {
        return $this->packages;
    }

    /**
     * Directories under the root package's own PSR-4 source roots that contain
     * at least one `.php` file, ordered by path and before any cap is applied.
     *
     * `files` counts the `.php` files directly IN that directory, not in its
     * subdirectories — each subdirectory is its own entry, so summing the
     * column gives the tree's total exactly once.
     *
     * @return list<array{path: string, namespace: string, files: int}>
     */
    public function sourceDirectories(): array
    {
        return $this->sourceDirectories;
    }

    /**
     * The fenced block, or the empty string when there is nothing to map.
     *
     * Empty string rather than an empty fence, for {@see MemoryBlock}'s
     * reason: a workspace this block cannot describe must add nothing to the
     * prompt, not a container the model has to interpret.
     */
    public function render(): string
    {
        $sections = [];

        $packages = $this->renderSection(array_map(
            fn(array $p): string => '- ' . $p['dir'] . '/'
                . ($p['namespace'] !== null ? '  ->  ' . $p['namespace'] : '')
                . ($p['description'] !== '' ? '  ' . $p['description'] : ''),
            $this->packages,
        ));

        if ($packages !== null) {
            $sections[] = 'Packages in this workspace — each immediate subdirectory of the root, plus every '
                . 'directory a path repository in the root composer.json names. Directory, the PSR-4 '
                . 'namespace prefix its composer.json declares first, and its composer description. Read '
                . "from those manifests only; nothing here was checked against the code.\n\n"
                . $packages;
        }

        $sourceDirs = $this->renderSection(array_map(
            fn(array $d): string => '- ' . $d['path'] . '/  ->  ' . $d['namespace']
                . '  (' . $d['files'] . ' files)',
            $this->sourceDirectories,
        ));

        if ($sourceDirs !== null) {
            $sections[] = 'Directories under this package\'s own PSR-4 source roots — directory, the namespace '
                . 'PSR-4 maps it to, and how many .php files sit directly in it. The namespace is what PSR-4 '
                . "implies, not what the files were read to declare, and test roots are not included.\n\n"
                . $sourceDirs;
        }

        if ($sections === []) {
            return '';
        }

        // Every figure interpolated from the constant that enforces it, so the
        // sentence in the prompt cannot outlive the limit it describes.
        $header = sprintf(
            'A map of where code lives in this workspace, derived from its composer.json files at the start '
            . 'of the session. It lists places, not symbols — use Grep and Glob to find a symbol inside one. '
            . 'Each section keeps at most %d bytes of entries and clips any single entry to %d bytes, so a '
            . 'large workspace may be mapped incompletely; a directory missing from this map is not evidence '
            . 'that it does not exist.',
            self::MAX_SECTION_BYTES,
            self::MAX_ENTRY_BYTES,
        );

        return "<repo-map>\n" . $header . "\n\n" . implode("\n\n", $sections) . "\n</repo-map>";
    }

    /**
     * One section's lines, clipped and budgeted, or null when it has none.
     *
     * The budget is checked BEFORE appending and with no exemption for the
     * first line, which is what makes {@see MAX_SECTION_BYTES} a ceiling
     * rather than a ceiling-plus-one-entry; `MAX_ENTRY_BYTES` is far below it,
     * so the first line always fits and the exemption would protect nothing.
     * The comparison is `>` and not `>=`, so a section landing EXACTLY on the
     * budget keeps its last entry — pinned by
     * `RepoMapBlockTest::testASectionEndingExactlyOnTheByteBudgetKeepsItsLastEntry()`,
     * because that is the one byte the paragraph above argues about and a
     * `>=` mutation survived the suite this file shipped with.
     *
     * The budget counts LINES, not the joined string: the `\n` separators and
     * the omission notice are outside it, so an N-line section renders N-1
     * bytes over the constant. The header says "bytes of entries" for that
     * reason.
     * A line that does not fit ENDS the section rather than being skipped over
     * in favour of a shorter one further down, so the alphabetical order the
     * header implies survives the cap.
     *
     * @param list<string> $lines
     */
    private function renderSection(array $lines): ?string
    {
        $rendered = [];
        $bytes = 0;

        foreach ($lines as $line) {
            $line = $this->clip($line);
            $lineBytes = strlen($line);

            if ($bytes + $lineBytes > self::MAX_SECTION_BYTES) {
                break;
            }

            $rendered[] = $line;
            $bytes += $lineBytes;
        }

        if ($rendered === []) {
            return null;
        }

        $omitted = count($lines) - count($rendered);

        if ($omitted > 0) {
            $rendered[] = sprintf('- (%d further entr%s omitted by the size limit)', $omitted, $omitted === 1 ? 'y' : 'ies');
        }

        return implode("\n", $rendered);
    }

    /**
     * Cut to {@see MAX_ENTRY_BYTES} INCLUDING the marker, without splitting a
     * UTF-8 character.
     *
     * `mb_strcut()` rather than `substr()` because the budget is in bytes but
     * the cut has to land on a character boundary — composer descriptions in
     * this very repository are full of em dashes. Invalid UTF-8 reaching the
     * system prompt does not degrade gracefully: `json_encode()` refuses it,
     * which fails the whole provider request rather than mangling one line.
     */
    private function clip(string $text): string
    {
        if (strlen($text) <= self::MAX_ENTRY_BYTES) {
            return $text;
        }

        $room = self::MAX_ENTRY_BYTES - strlen(self::TRUNCATION_MARKER);

        return rtrim(mb_strcut($text, 0, $room, 'UTF-8')) . self::TRUNCATION_MARKER;
    }

    /**
     * Directories under `$root` that carry a `composer.json` naming a package.
     *
     * TWO SOURCES OF CANDIDATES, and the second one is why this is not
     * "immediate children" any more. The first revision looked at immediate
     * children only and wrote that a `packages/*` layout is "a deliberate
     * floor": recursing to find manifests means walking a tree of unknown size
     * before knowing whether it holds any. That reasoning is sound and the
     * floor it justified was still the wrong one, because `packages/*` is the
     * DOMINANT Composer monorepo convention — it is the shape in Composer's
     * own `repositories` documentation — and a block whose whole justification
     * for rejecting this item's literal form was "generic, not repo-specific"
     * cannot then render the empty string for it. MEASURED before this change:
     * a workspace with `packages/alpha` and `packages/beta`, both with PSR-4,
     * both named by the root's `repositories`, rendered nothing at all.
     *
     * So the second source is the root manifest's own `repositories` entries
     * of `{"type": "path"}`, whose `url` is expanded with {@see glob()}. That
     * keeps the floor the reasoning above actually asked for — nothing here
     * walks a tree looking for manifests; it opens exactly the directories the
     * repository DECLARED, which is a bounded list the root manifest states in
     * one place. A `packages/*` layout that does NOT declare itself in
     * `repositories` is still not found, and that remains the seam: the next
     * step would be a bounded fixed-depth probe, not a recursive search.
     *
     * The root's OWN manifest is excluded either way — it is the subject of
     * the map, not a member of it, and its autoload is what
     * {@see scanSourceDirectories()} reads instead.
     *
     * @return list<array{dir: string, name: string, namespace: ?string, description: string}>
     */
    private static function scanPackages(string $root): array
    {
        $packages = [];
        $opened = 0;

        foreach (self::packageCandidates($root) as $relative) {
            // The bound is on manifests OPENED, which is what MAX_PACKAGES'
            // doc-block says it is; counting packages FOUND instead would let
            // a root full of manifest-less directories open without limit.
            if ($opened >= self::MAX_PACKAGES) {
                break;
            }

            $dir = $root . '/' . $relative;

            if (!self::isScannableDir($dir, basename($relative))) {
                continue;
            }

            // See ON PATHS THAT COME FROM CONTENT: the entry NAME cannot hold
            // a separator, the directory it names can be a symlink out of the
            // tree, and a `git clone` materialises one.
            if (!ContainedPath::below($dir, $root)) {
                continue;
            }

            ++$opened;

            $manifest = self::readManifest($dir . '/composer.json', $root);

            if ($manifest === null || !is_string($manifest['name'] ?? null) || $manifest['name'] === '') {
                continue;
            }

            $prefixes = array_keys(self::psr4($manifest));

            $packages[] = [
                'dir' => $relative,
                'name' => $manifest['name'],
                // The FIRST prefix a manifest declares, not the only one. A
                // package may declare several; this column answers "what will
                // I find in here", and the first is the one a `composer.json`
                // conventionally leads with. The rest are reachable from the
                // manifest itself, which the model can read.
                'namespace' => $prefixes === [] ? null : (string) $prefixes[0],
                'description' => self::oneLine(is_string($manifest['description'] ?? null) ? $manifest['description'] : ''),
            ];
        }

        return $packages;
    }

    /**
     * Candidate sub-package directories, relative to `$root`, sorted and
     * deduplicated — every immediate child, plus every path-repository url the
     * root manifest globs to.
     *
     * Sorted explicitly rather than relying on `scandir()`'s own order, which
     * only covers half the list now; `SORT_STRING` because that is the
     * comparison `scandir()` uses, so a flat workspace's order is unchanged.
     *
     * TWO COSTS, both known and both bounded by what a repository can write
     * rather than by what it holds. The root manifest is read here and again by
     * {@see scanSourceDirectories()} — two `file_get_contents()` of one small
     * file per capture, which is cheaper than threading a decoded manifest
     * through a class with no instance state to hold it. And a pattern of
     * `*&#47;*&#47;*&#47;*&#47;*` costs one deep `glob()`: {@see MAX_PACKAGES}
     * bounds how many patterns are expanded, not how wide any one of them is.
     * That is the same trade the block already makes for `autoload.psr-4`, and
     * the backstop there — {@see MAX_SOURCE_FILES} — is on the walk rather
     * than on the glob.
     *
     * @return list<string>
     */
    private static function packageCandidates(string $root): array
    {
        $candidates = [];

        foreach (scandir($root) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                $candidates[$entry] = true;
            }
        }

        $manifest = self::readManifest($root . '/composer.json', $root);
        $repositories = $manifest === null ? null : ($manifest['repositories'] ?? null);
        $expanded = 0;

        if (is_array($repositories)) {
            foreach ($repositories as $repository) {
                // Bounded by the same constant as the manifest reads: a
                // `repositories` block is content, and one with ten thousand
                // entries must not cost ten thousand glob() calls.
                if ($expanded >= self::MAX_PACKAGES) {
                    break;
                }

                if (!is_array($repository) || ($repository['type'] ?? null) !== 'path' || !is_string($repository['url'] ?? null)) {
                    continue;
                }

                $pattern = trim(str_replace('\\', '/', $repository['url']), '/');

                if ($pattern === '') {
                    continue;
                }

                ++$expanded;

                foreach (glob($root . '/' . $pattern, GLOB_ONLYDIR) ?: [] as $match) {
                    // glob() substitutes wildcards into the pattern it was
                    // given and resolves nothing, so every match still carries
                    // the literal `$root . '/'` this call prepended — which is
                    // why the relative name is taken by offset and NOT by a
                    // prefix compare. A pattern of `../*` therefore yields a
                    // relative name of `../sibling`, and the one thing standing
                    // between that and the prompt is the ContainedPath::below()
                    // gate in the caller. Writing a prefix compare here would
                    // read like a second gate while gating nothing.
                    $candidates[substr($match, strlen($root) + 1)] = true;
                }
            }
        }

        // array_keys() hands back an INT for a directory named `123`, and
        // `basename()` under strict_types will not take one.
        $relative = array_map(strval(...), array_keys($candidates));
        sort($relative, SORT_STRING);

        return $relative;
    }

    /**
     * Directories holding `.php` files under the root package's own PSR-4
     * source roots, each paired with the namespace PSR-4 maps it to.
     *
     * @return list<array{path: string, namespace: string, files: int}>
     */
    private static function scanSourceDirectories(string $root): array
    {
        $manifest = self::readManifest($root . '/composer.json', $root);

        if ($manifest === null) {
            return [];
        }

        $rows = [];
        $visited = 0;

        foreach (self::psr4($manifest) as $prefix => $dirs) {
            foreach ((array) $dirs as $dir) {
                if (!is_string($dir)) {
                    continue;
                }

                $relative = trim(str_replace('\\', '/', $dir), '/');
                $absolute = $relative === '' ? $root : $root . '/' . $relative;

                if (!is_dir($absolute)) {
                    continue;
                }

                // The one place in this class where a path comes from CONTENT
                // rather than from the launch. `autoload.psr-4` values are
                // written by whoever wrote the repository, and `"../../.."` is
                // a legal JSON string -- without this compare a hostile (or
                // merely careless) manifest would walk the block outside the
                // root and render foreign directory names and file counts into
                // every system prompt of the session. `within()` rather than
                // `below()` because a prefix mapped to `""` legitimately means
                // the package root itself, which is the boundary rather than
                // something strictly inside it.
                if (!ContainedPath::within($absolute, $root)) {
                    continue;
                }

                foreach (self::phpFileDirectories($absolute, $visited) as $sub => $files) {
                    // The prefix is a namespace, so its trailing separator is
                    // kept: a reader can concatenate a class name onto the
                    // rendered string and get a real FQN.
                    $namespace = ltrim(rtrim((string) $prefix, '\\') . '\\'
                        . ($sub === '' ? '' : str_replace('/', '\\', $sub) . '\\'), '\\');
                    $path = $relative === '' ? $sub : ($sub === '' ? $relative : $relative . '/' . $sub);
                    $path = $path === '' ? '.' : $path;

                    // Keyed by path AND namespace, and ASSIGNED rather than
                    // summed. Two prefixes may legally map to one directory,
                    // in which case both namespaces are true of it and both
                    // are listed with the same file count -- summing would
                    // report the files twice and keep only one namespace.
                    $rows[$path . "\0" . $namespace] = [
                        'path' => $path,
                        'namespace' => $namespace,
                        'files' => $files,
                    ];
                }
            }
        }

        ksort($rows);

        return array_values($rows);
    }

    /**
     * `.php` file counts per directory beneath `$base`, keyed by the path
     * relative to `$base` (`''` for `$base` itself).
     *
     * `$visited` is threaded by reference across every source root rather than
     * reset per root, so {@see MAX_SOURCE_FILES} bounds the WHOLE capture and
     * not each root independently — otherwise a manifest with ten roots would
     * cost ten times the documented ceiling.
     *
     * @return array<string, int>
     */
    private static function phpFileDirectories(string $base, int &$visited): array
    {
        $counts = [];

        $walk = new \RecursiveIteratorIterator(
            new \RecursiveCallbackFilterIterator(
                // Symlinks are NOT followed (the flag is absent by design):
                // a self-referential link would otherwise turn the walk into a
                // cycle bounded only by MAX_SOURCE_FILES.
                new \RecursiveDirectoryIterator($base, \FilesystemIterator::SKIP_DOTS),
                static fn(\SplFileInfo $file): bool => !$file->isDir()
                    || self::isScannableDir($file->getPathname(), $file->getFilename()),
            ),
        );

        /** @var \SplFileInfo $file */
        foreach ($walk as $file) {
            if ($visited >= self::MAX_SOURCE_FILES) {
                break;
            }

            if (!$file->isFile() || strtolower($file->getExtension()) !== 'php') {
                continue;
            }

            ++$visited;

            $dir = \dirname($file->getPathname());
            $relative = $dir === $base ? '' : substr($dir, strlen($base) + 1);
            $counts[$relative] = ($counts[$relative] ?? 0) + 1;
        }

        return $counts;
    }

    /**
     * A real directory worth descending into: not a dot-directory, not one of
     * {@see SKIP_DIRS}.
     *
     * Shared by both scans so the two cannot drift into disagreeing about what
     * `vendor` is.
     */
    private static function isScannableDir(string $path, string $name): bool
    {
        if ($name === '' || $name[0] === '.' || in_array($name, self::SKIP_DIRS, true)) {
            return false;
        }

        return is_dir($path);
    }

    /**
     * A decoded `composer.json` OBJECT, or null when it is absent, outside
     * `$root`, unreadable, not valid JSON, or not a JSON object.
     *
     * THE CONTAINMENT COMPARE LIVES HERE, at the sink, rather than at the two
     * callers. `is_file()`, `is_readable()` and `file_get_contents()` all
     * follow symlinks, so this one function is where a repository-chosen path
     * turns into somebody else's bytes; putting the gate at the callers is how
     * the third caller ships without one. See ON PATHS THAT COME FROM CONTENT
     * on the class for the escape this closes and the sentence that denied it.
     *
     * "NOT A JSON OBJECT" USED TO BE A PROMISE THIS DID NOT KEEP. The test was
     * `is_array($decoded)`, and `json_decode('[1,2,3]', true)` is an array — so
     * a manifest holding a JSON ARRAY was returned as one. Harmless at both
     * call sites (`$m['name'] ?? null` is null on a list, and `psr4()` refuses
     * it), which is precisely why it survived: the existing test covered a
     * SCALAR, the one shape the old check did reject. `array_is_list()` is the
     * distinction PHP has for "decoded from `[]` rather than `{}`" — an empty
     * `{}` decodes to `[]` and is a list, and is also an object with no keys,
     * so it is admitted and drops on the `name` check one line later exactly
     * as a `{"foo":1}` manifest does.
     *
     * @return ?array<string, mixed>
     */
    private static function readManifest(string $path, string $root): ?array
    {
        if (!ContainedPath::within($path, $root)) {
            return null;
        }

        if (!is_file($path) || !is_readable($path)) {
            return null;
        }

        $raw = @file_get_contents($path);

        if ($raw === false) {
            return null;
        }

        $decoded = json_decode($raw, true);

        if (!is_array($decoded) || ($decoded !== [] && array_is_list($decoded))) {
            return null;
        }

        return $decoded;
    }

    /**
     * The manifest's `autoload.psr-4` map, or an empty array.
     *
     * `autoload-dev` is deliberately not merged in — see the class docblock.
     *
     * @param array<string, mixed> $manifest
     *
     * @return array<string, mixed>
     */
    private static function psr4(array $manifest): array
    {
        $autoload = $manifest['autoload'] ?? null;

        if (!is_array($autoload) || !is_array($autoload['psr-4'] ?? null)) {
            return [];
        }

        return $autoload['psr-4'];
    }

    /** Every run of whitespace — newlines included — collapsed to one space. */
    private static function oneLine(string $text): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }
}
