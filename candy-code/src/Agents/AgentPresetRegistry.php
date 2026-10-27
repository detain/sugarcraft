<?php

declare(strict_types=1);

namespace SugarCraft\Code\Agents;

use SugarCraft\Code\Permissions\PermissionMode;
use SugarCraft\Code\Support\ContainedPath;
use SugarCraft\Code\Support\EnumSpelling;
use SugarCraft\Code\Support\Frontmatter;
use SugarCraft\Code\Support\FrontmatterKeyAudit;

/**
 * Registry for loading and resolving agent presets from the filesystem.
 * Presets are Markdown files with YAML frontmatter stored under search paths.
 *
 * Mirrors charmbracelet/crush preset discovery and description-matching logic.
 *
 * TWO BOUNDARIES, and this class had only the inner one. Per-ENTRY containment
 * (a `*.md` that resolves outside the directory it was listed from) is judged by
 * {@see ContainedPath::within()} below; the DIRECTORY a repository chose is
 * judged by {@see ContainedPath::below()} against $anchors, and until that
 * existed here the entry check was relocatable rather than binding — the same
 * defect {@see \SugarCraft\Code\Workflows\WorkflowRegistry::readableProjectDir()}
 * and {@see \SugarCraft\Code\Skills\SkillLoader::skillFilesIn()} each closed for
 * their own tier.
 *
 * MEASURED on this host against the pre-fix build, with a fixture checkout whose
 * only content was `.candy-code/agents -> <a directory outside the checkout>` —
 * the one line a repository can commit — and one frontmatter-bearing `notes.md`
 * in that directory: both `list()` and
 * {@see \SugarCraft\Code\Cli\Bootstrap::agentPresets()} returned
 *
 *     preset=notes  desc=PRIVATE NOTE DESCRIPTION  mode=bypass-permissions
 *     prompt=SENTINEL-PRIVATE-BODY sk-live-DEADBEEF
 *
 * with no refusal recorded anywhere. So an outside file's `description` became a
 * roster entry, its body became a sub-agent's `initialPrompt`, and a
 * `permissionMode: bypass-permissions` the repository does not contain was
 * honoured — reachable by `git clone`, since
 * {@see \SugarCraft\Code\Cli\Bootstrap::chat()} calls `list()` on every launch.
 * The same probe after the fix returns no presets and records the refusal.
 */
final class AgentPresetRegistry
{
    /**
     * Search-path directories this registry declined to read, path as spelled
     * => why — see {@see refusedDirectories()}.
     *
     * @var array<string, string>
     */
    private array $refusedDirectories = [];

    /**
     * Preset files the most recent {@see list()} could not turn into a preset,
     * path as globbed => why — see {@see skippedFiles()}.
     *
     * @var array<string, string>
     */
    private array $skippedFiles = [];

    /**
     * Files the most recent {@see list()} displaced by a same-named preset from
     * a higher-precedence search path, loser path => winner path — see
     * {@see shadowedFiles()}.
     *
     * @var array<string, string>
     */
    private array $shadowedFiles = [];

    /**
     * Working set for the shadow walk: the source path every name currently
     * claims, keyed by name, INCLUDING claims by files that a later candidate
     * may displace a third tier from. Rebuilt by {@see list()}.
     *
     * @var array<string, string>
     */
    private array $shadowCandidates = [];

    /**
     * Frontmatter keys the most recent {@see list()} found declared and
     * ignored, preset name => labels — see {@see ignoredFrontmatter()}.
     *
     * @var array<string, list<string>>
     */
    private array $ignoredFrontmatter = [];

    /**
     * The mapping {@see parsePresetFile()} read last, so {@see list()} can
     * audit the keys of a file that parsed without re-reading it.
     *
     * @var array<mixed>
     */
    private array $lastFrontmatter = [];

    /** @var list<string> */
    private readonly array $searchPaths;

    /** @var array<string, string> */
    private readonly array $anchors;

    /**
     * @param list<string> $searchPaths directories to look for `<name>.md` in,
     *        in precedence order
     * @param array<string, string> $anchors trust anchors keyed by the search
     *        path: that path must resolve strictly inside its anchor
     *        ({@see ContainedPath::below()}) or the whole directory is refused.
     *        A path with no entry here is UNANCHORED, which this parameter used
     *        to describe as "the right answer for the user's own
     *        `~/.candy-code/agents` — nobody but the user chose where it
     *        points". That premise is not established by anything, and
     *        {@see \SugarCraft\Code\Cli\Bootstrap::agentPresetTiers()} carries
     *        the four-row measurement that refuted it: a symlink out of `$HOME`
     *        arrives in a tarball as readily as in a clone. Both tiers are
     *        anchored now — the project tier to the checkout, the user tier to
     *        `$HOME`, which is the boundary that keeps the working layout
     *        (`~/.candy-code/agents -> ~/.claude/agents`, inside the home) while
     *        refusing a link out of it. This parameter still ALLOWS an
     *        unanchored path, because that is a decision for the caller naming
     *        the tiers and not for the registry reading them.
     *
     * TWO THINGS HAPPEN TO THOSE ARGUMENTS HERE, and both exist because the
     * lookup that consumes them ({@see readableSearchPaths()}) is
     * `$this->anchors[$path] ?? null` — a MISS is an unanchored read, so a key
     * that does not match its search path exactly does not weaken the check, it
     * REMOVES it. Measured before this constructor did anything: a search path
     * spelled `<root>/.candy-code/agents/` with its anchor keyed without the
     * trailing slash listed a preset out of a directory symlinked clean out of
     * the checkout, with zero refusals recorded — the full escape, from one byte.
     *
     *  1. Both sides are NORMALISED (one trailing separator stripped, `/`
     *     preserved), so the one-byte difference cannot arise.
     *  2. An anchor key naming NO search path THROWS. The mismatch that survives
     *     normalisation is a programming error at the call site, and there is no
     *     safe default for it: silently anchoring nothing is the escape above,
     *     and silently anchoring everything would refuse the user's own tier. So
     *     it fails closed, loudly, at construction — before any read.
     *
     * `Bootstrap` passes one variable for both the path and the key and cannot
     * reach case 2 today; this class is public `final` API in a published
     * library, and nothing else pinned the mismatch.
     *
     * @throws \InvalidArgumentException when an anchor names no search path
     */
    public function __construct(array $searchPaths, array $anchors = [])
    {
        $this->searchPaths = array_values(array_map(self::normalisePath(...), $searchPaths));

        $normalised = [];
        foreach ($anchors as $path => $anchor) {
            $normalised[self::normalisePath((string) $path)] = $anchor;
        }

        $orphans = array_diff(array_keys($normalised), $this->searchPaths);
        if ($orphans !== []) {
            throw new \InvalidArgumentException(sprintf(
                'agent-preset trust anchor(s) named for %s, which is not among the search paths (%s); an anchor '
                . 'that matches no search path silently anchors nothing, so it is refused rather than ignored',
                implode(', ', $orphans),
                $this->searchPaths === [] ? 'none' : implode(', ', $this->searchPaths),
            ));
        }

        $this->anchors = $normalised;
    }

    /**
     * Trailing separators removed from both sides of the anchor lookup.
     *
     * ALL of them, not one: `rtrim($path, '/')` strips every trailing `/`, so
     * `<root>/agents///` and `<root>/agents` normalise to the same key. This
     * said "one trailing separator's worth" for a round while the code did the
     * wider thing — and the wider thing is the one the anchor lookup needs,
     * since a key and a search path differing by any number of separators is
     * the mismatch that silently REMOVES an anchor rather than weakening it.
     * {@see \SugarCraft\Code\Tests\Agents\AgentPresetAnchorKeyTest::testSeveralTrailingSeparatorsAreStillOneDirectory()}
     * is written against the wider behaviour.
     *
     * `rtrim($path, '/')` on its own turns a root of `/` into the empty string,
     * which {@see ContainedPath} then refuses outright — so `/` is returned as
     * itself. Nothing further is normalised: `realpath()` inside
     * {@see ContainedPath} is what resolves `.`, `..` and symlinks, and doing it
     * twice in two places is how the two answers drift apart.
     */
    private static function normalisePath(string $path): string
    {
        $trimmed = rtrim($path, '/');

        return $trimmed === '' ? $path : $trimmed;
    }

    /**
     * Load a preset by name from the search paths.
     * Searches for <name>.md in each search path in order.
     */
    public function load(string $name): AgentPreset
    {
        foreach ($this->readableSearchPaths() as $path) {
            $filePath = $path . '/' . $name . '.md';
            // No separate existence check: within() resolves BOTH sides, so a
            // true answer already proves this file exists — `realpath()` is
            // false for anything it cannot resolve. It is also what stops a
            // $name of `../../etc/passwd` reading through the search path.
            if (ContainedPath::within($filePath, $path)) {
                return $this->parsePresetFile($filePath);
            }
        }

        throw new \RuntimeException("Preset '{$name}' not found in search paths.");
    }

    /**
     * List all available presets from all search paths.
     *
     * Applies the same two checks {@see load()} does, and for the same reasons:
     * a `.md` entry that resolves outside its search path — a symlink such as
     * `agents/link.md -> /elsewhere/stolen.md` — is not a preset that directory
     * declares, and a search path the repository pointed out of the checkout is
     * not that repository's presets at all. The two methods share one trust
     * boundary, and this is the one
     * {@see \SugarCraft\Code\Cli\Bootstrap::agentPresets()} actually calls, so
     * leaving either open here makes load()'s copy decorative.
     *
     * @return array<string, AgentPreset> Map of preset name => AgentPreset
     */
    public function list(): array
    {
        $presets = [];
        $this->skippedFiles = [];
        $this->shadowedFiles = [];
        $this->shadowCandidates = [];
        $this->ignoredFrontmatter = [];

        foreach ($this->readableSearchPaths() as $path) {
            if (!is_dir($path)) {
                continue;
            }

            $files = glob($path . '/*.md') ?: [];
            foreach ($files as $file) {
                if (!ContainedPath::within($file, $path)) {
                    continue;
                }

                $name = basename($file, '.md');
                // First search path takes precedence on name conflicts. The
                // loser is RECORDED (ISS-031): precedence has always been
                // decided here, but a file silently displaced by a same-named
                // one from another tier was indistinguishable from a file that
                // never existed — which is the other half of what made the
                // old project > user order dangerous, and the reason it is a
                // NOTICE and not a comment. The displaced file may itself
                // shadow a THIRD tier below it, so it is kept as a candidate
                // winner until a parse decides otherwise; a SKIPPED file does
                // not claim its name (the load-bearing half — recording the
                // shadow but skipping the candidate re-introduces the exact
                // silent-displacement this seam exists to end).
                // {@see shadowedFiles()}.
                $displaced = isset($presets[$name])
                    ? ($presets[$name]->sourcePath ?? '')
                    : ($this->shadowCandidates[$name] ?? null);
                if ($displaced !== null) {
                    $this->shadowedFiles[$file] = $displaced;

                    continue;
                }

                // ONE FILE, ONE VERDICT. This parse used to be bare, so the
                // first malformed `.md` in ANY tier threw out of list() and
                // took every preset in every tier with it — Bootstrap's
                // degradation then registered none at all. Measured (audit
                // AG-2): a tier holding a valid `good.md` and a `reviewer.md`
                // with Claude Code's `tools: Read, Grep, Glob` returned a
                // TypeError instead of `good`. The bad file is now recorded
                // in skippedFiles() and the walk continues.
                //
                // A skipped file does not claim its name, so a same-named
                // preset in a LOWER tier still loads: the skipped file is not
                // a preset, and the lower-tier one is the only definition of
                // that name this registry can actually honour.
                //
                // The catch is the three failure families a hand-authored file
                // produces — unreadable/no frontmatter and YAML parse errors
                // (\RuntimeException, Symfony's ParseException among them), a
                // field of the wrong shape (\InvalidArgumentException, from
                // arrayToPreset()), and a typed-constructor mismatch arrayToPreset()
                // did not anticipate (\TypeError) — and deliberately not \Throwable,
                // so a genuine programming error still surfaces.
                try {
                    $preset = $this->parsePresetFile($file);
                    $presets[$name] = $preset;
                    // The candidate stays even when no later file shadows it;
                    // it is read only by the displacement check above, so a
                    // shadow record can name the WINNER's path, not just the
                    // winner's name.
                    $this->shadowCandidates[$name] = $preset->sourcePath ?? '';
                    // The audit exists to tell a USER that a file they (or an
                    // import) wrote asks for something this build ignores.
                    // The presets shipped INSIDE this package are none of the
                    // user's business: launching from the package's own tree
                    // warned on every start about `memory:`/`color:` in the
                    // bundled trio — files a user cannot act on (reviews
                    // rv-qa-3, rv-enduser-7). Bundle the notice out of scope
                    // by directory identity, not by key: everything below the
                    // package's own `.candy-code/agents/` stays quiet, and
                    // every user- or project-authored preset keeps its voice.
                    if (!self::isBundledPresetPath($file)) {
                        $ignored = FrontmatterKeyAudit::inspect(FrontmatterKeyAudit::AGENT, $this->lastFrontmatter);
                        if ($ignored !== []) {
                            $this->ignoredFrontmatter[$name] = $ignored;
                        }
                    }
                } catch (\RuntimeException | \InvalidArgumentException | \TypeError $e) {
                    $this->skippedFiles[$file] = $e->getMessage();
                }
            }
        }

        return $presets;
    }

    /**
     * Preset files the most recent {@see list()} skipped because they could
     * not be parsed into a preset, path as globbed => why.
     *
     * The per-file sibling of {@see refusedDirectories()}, and the seam that
     * keeps the isolation in {@see list()} from becoming silence: a skipped
     * file is an agent the author wrote and will not get, and a shorter roster
     * cannot say which one or why. The same `path => reason` shape as
     * {@see \SugarCraft\Code\Commands\CommandLoader::skippedFiles()}.
     * {@see \SugarCraft\Code\Cli\Bootstrap::agentPresets()} reports it.
     *
     * Recomputed by every {@see list()} call rather than accumulated, like
     * {@see refusedDirectories()}. {@see load()} does not touch it: a caller
     * naming one preset gets that file's failure as an exception instead.
     *
     * @return array<string, string> preset file path => why it was skipped
     */
    public function skippedFiles(): array
    {
        return $this->skippedFiles;
    }

    /**
     * Preset files the most recent {@see list()} displaced by a same-named
     * preset from a HIGHER-precedence search path, loser path => winner path
     * (winner path `''` when the winner predates the field).
     *
     * ISS-031: precedence is a decision, and a decision nobody can see is
     * silence wearing a rule's clothes. This is the seam that lets
     * {@see \SugarCraft\Code\Cli\Bootstrap::agentPresets()} turn "your repo
     * ships reviewer.md and it did nothing" into a launch row naming both
     * files. Recomputed per {@see list()} like {@see skippedFiles()}; a
     * DISPLACED file is not a skipped one — it parsed fine, it simply lost —
     * so the two rosters stay separate.
     *
     * @return array<string, string>
     */
    public function shadowedFiles(): array
    {
        return $this->shadowedFiles;
    }

    /**
     * Keys the presets {@see list()} returned declare and nothing acts on —
     * inert fields and unknown keys — preset name => display labels from
     * {@see FrontmatterKeyAudit::inspect()}.
     *
     * The third per-file seam beside {@see skippedFiles()}, for the file that
     * DID load: `permissionMode: plan` on a preset is accepted, carried onto
     * the roster row, and then `Task` runs under the session's mode, so the
     * author's request is dropped as silently as a malformed file used to be.
     * Nothing here refuses the preset — an imported Claude Code agent with an
     * unknown key is still the agent its author wanted.
     * {@see \SugarCraft\Code\Cli\Bootstrap::agentPresets()} reports it as
     * one aggregated launch row. Recomputed by every {@see list()}.
     *
     * @return array<string, list<string>>
     */
    public function ignoredFrontmatter(): array
    {
        return $this->ignoredFrontmatter;
    }

    /**
     * The package-bundled presets directory (`<package root>/.candy-code/
     * agents`, beside this class's own `src/`), or null when this install
     * ships none.
     *
     * Under a phar install the directory lives INSIDE the archive, where
     * realpath() answers false for every entry — so the `phar://` spelling
     * is kept verbatim after an `is_dir()` proof, and {@see
     * isBundledPresetPath()} matches candidate files by normalized prefix
     * instead. (C-SES-9.c: the build packs `.candy-code/agents`, and this
     * gate is what keeps its own trio out of the frontmatter audit.)
     */
    private static function bundledPresetsDirectory(): ?string
    {
        $candidates = \dirname(__DIR__, 2) . '/.candy-code/agents';
        if (\str_starts_with(__DIR__, 'phar://')) {
            $inPhar = self::normalisePath($candidates);

            return is_dir($inPhar) ? $inPhar : null;
        }

        $real = realpath($candidates);

        return $real === false || !is_dir($real) ? null : $real;
    }

    /**
     * True when $file is one of the presets shipped INSIDE this package —
     * {@see bundledPresetsDirectory()} by resolved-path identity, so a user
     * or project directory that merely shares the name is still audited. An
     * unresolvable path (deleted between glob and audit) is not bundled.
     * A `phar://` candidate lives inside the archive itself, where no path
     * resolves; it is matched by normalized prefix against the bundled
     * directory instead.
     */
    private static function isBundledPresetPath(string $file): bool
    {
        $bundled = self::bundledPresetsDirectory();
        if ($bundled === null) {
            return false;
        }

        $resolved = \str_starts_with($file, 'phar://')
            ? self::normalisePath($file)
            : realpath($file);

        return $resolved !== false && str_starts_with($resolved, $bundled . '/');
    }

    /**
     * Which search paths this registry refused to read, and why.
     *
     * The seam the refusal needs in order not to be silent, and the same one
     * {@see \SugarCraft\Code\Skills\SkillManager::refusedDirectories()} and
     * {@see \SugarCraft\Code\Workflows\WorkflowRegistry::projectTierRefusal()}
     * provide for their tiers: a dropped directory is otherwise indistinguishable
     * from an empty one, and "your repository's agents directory was rejected" is
     * not something a shorter roster can say.
     * {@see \SugarCraft\Code\Cli\Bootstrap::agentPresets()} merges it into the
     * one collector all three feed.
     *
     * Recomputed by {@see load()}/{@see list()} on every call rather than
     * accumulated, so a refusal never outlives the condition that caused it.
     *
     * KEYED BY THE NORMALISED SPELLING, not by the spelling the caller passed:
     * {@see __construct()} strips trailing separators from every search path
     * before storing it (`<root>/.candy-code/agents/` becomes
     * `<root>/.candy-code/agents`), and this map is built from those. The
     * `@return` here said "as spelled" for a round after that normalisation
     * landed, which is the kind of one-word drift that makes a consumer key a
     * lookup on the wrong string.
     *
     * @return array<string, string> search path, normalised => why it was refused
     */
    public function refusedDirectories(): array
    {
        return $this->refusedDirectories;
    }

    /**
     * The search paths a read may actually touch, in precedence order.
     *
     * A path that does not resolve is DROPPED, whatever the reason — a dangling
     * link, a link one component higher whose own target is missing, or simply a
     * checkout that ships no `.candy-code/agents`. Dropping loses nothing here
     * (there is no file to read and no error message that names the directory,
     * unlike the workflow registry's not-found line) and it means the security
     * decision does not depend on telling those cases apart. The refusal NOTICE
     * is narrower than the decision on purpose: it fires for a link AT the search
     * path, which is the shape that can be named confidently and the shape a
     * repository commits, and stays quiet about the missing directory almost
     * every checkout has.
     *
     * @return list<string>
     */
    private function readableSearchPaths(): array
    {
        $this->refusedDirectories = [];

        $readable = [];
        foreach ($this->searchPaths as $path) {
            $real = realpath($path);
            if ($real === false) {
                if (is_link($path)) {
                    $this->refusedDirectories[$path] = 'is a symlink that resolves to nothing this process can '
                        . 'read, so it names no presets — and a committed link to a path that does not exist yet '
                        . 'is a request to read whatever appears there later';
                }

                continue;
            }

            $anchor = $this->anchors[$path] ?? null;
            if ($anchor !== null && !ContainedPath::below($path, $anchor)) {
                // NAMES THE ANCHOR, not "the checkout". This message said "a
                // repository chooses where this directory is" for every refusal,
                // which stopped being true the moment
                // {@see \SugarCraft\Code\Cli\Bootstrap::agentPresetTiers()}
                // started anchoring the USER tier to `$HOME` — there the
                // directory is one the user chose and the objection is that it
                // points out of their home, not that a repository picked it. A
                // refusal notice that misidentifies who chose the path sends the
                // reader to the wrong file.
                $this->refusedDirectories[$path] = sprintf(
                    'resolves to %s, %s the directory it is anchored to (%s) — a link out of that directory '
                    . "would put unrelated files' descriptions in the agent roster and their bodies in a "
                    . "sub-agent's prompt, under whatever permissionMode they declare",
                    $real,
                    realpath($anchor) === $real ? 'which is exactly' : 'outside',
                    $anchor,
                );

                continue;
            }

            $readable[] = $path;
        }

        return $readable;
    }

    /**
     * Resolve a preset by matching a task description against preset descriptions.
     * Uses keyword overlap scoring — returns the preset whose description shares
     * the most keywords with the task description, enabling auto-delegation.
     */
    public function resolve(string $taskDescription): ?AgentPreset
    {
        $taskWords = $this->extractKeywords($taskDescription);
        if ($taskWords === []) {
            return null;
        }

        $bestPreset = null;
        $bestScore = 0;

        foreach ($this->list() as $preset) {
            $descWords = $this->extractKeywords($preset->description);
            $overlap = array_intersect($taskWords, $descWords);
            $score = count($overlap);

            if ($score > $bestScore) {
                $bestScore = $score;
                $bestPreset = $preset;
            }
        }

        // Require at least 2 overlapping words to avoid spurious matches
        return $bestScore >= 2 ? $bestPreset : null;
    }

    /**
     * Extract lowercase keywords from a string (filters short/common words).
     *
     * @return array<string> List of meaningful word tokens
     */
    private function extractKeywords(string $text): array
    {
        $stopWords = [
            'a', 'an', 'the', 'and', 'or', 'but', 'in', 'on', 'at', 'to',
            'for', 'of', 'with', 'by', 'from', 'as', 'is', 'was', 'are',
            'be', 'been', 'being', 'have', 'has', 'had', 'do', 'does', 'did',
            'will', 'would', 'could', 'should', 'may', 'might', 'can',
            'this', 'that', 'these', 'those', 'it', 'its',
        ];

        $words = preg_split('/\s+/', strtolower($text));
        $keywords = array_filter($words, fn($w) => strlen($w) > 2 && !in_array($w, $stopWords, true));

        return array_values($keywords);
    }

    /**
     * Parse a preset Markdown file with YAML frontmatter into an AgentPreset.
     */
    private function parsePresetFile(string $filePath): AgentPreset
    {
        $content = file_get_contents($filePath);
        if ($content === false) {
            throw new \RuntimeException("Could not read preset file: {$filePath}");
        }

        // Extract YAML frontmatter block
        if (!preg_match('/^---\s*\n(.*?)\n---\s*\n/s', $content, $matches)) {
            throw new \RuntimeException("No YAML frontmatter found in: {$filePath}");
        }

        $data = Frontmatter::parse($matches[1]);
        if (!is_array($data)) {
            throw new \RuntimeException("Invalid YAML frontmatter in: {$filePath}");
        }
        $this->lastFrontmatter = $data;

        // Everything after the closing `---` is the preset's prompt. $matches[0]
        // is the whole delimited block, so slicing by its length is what leaves
        // the body and nothing else.
        return $this->arrayToPreset($data, $filePath, trim(substr($content, strlen($matches[0]))));
    }

    /**
     * Build an AgentPreset from a parsed YAML array.
     *
     * @param string $filePath Path of the preset file the data was parsed from,
     *                         used as the name fallback when no `name:` key is set.
     * @param string $body     The markdown after the frontmatter, used as the
     *                         preset's prompt when no `initialPrompt:` key is
     *                         set. An explicit `initialPrompt:` WINS: it is the
     *                         more specific statement of intent, and a file
     *                         carrying both is asking for the declared one.
     */
    private function arrayToPreset(array $data, string $filePath, string $body = ''): AgentPreset
    {
        return new AgentPreset(
            name: self::stringField($data, 'name', $filePath) ?? basename($filePath, '.md'),
            description: self::stringField($data, 'description', $filePath) ?? '',
            tools: self::nameList($data, 'tools', $filePath),
            disallowedTools: self::nameList($data, 'disallowedTools', $filePath),
            model: self::stringField($data, 'model', $filePath) ?? 'inherit',
            permissionMode: self::parsePermissionMode(self::stringField($data, 'permissionMode', $filePath), $filePath),
            maxTurns: isset($data['maxTurns']) ? (int) $data['maxTurns'] : null,
            skills: self::nameList($data, 'skills', $filePath),
            mcpServers: self::nameList($data, 'mcpServers', $filePath),
            memory: self::parseMemoryScope(self::stringField($data, 'memory', $filePath), $filePath),
            background: (bool) ($data['background'] ?? false),
            effort: self::parseEffort(self::stringField($data, 'effort', $filePath), $filePath),
            isolation: self::parseIsolation(self::stringField($data, 'isolation', $filePath), $filePath),
            color: self::stringField($data, 'color', $filePath),
            initialPrompt: self::resolveInitialPrompt($data['initialPrompt'] ?? null, $body),
            sourcePath: $filePath,
        );
    }

    /**
     * A list-of-names field (`tools`, `disallowedTools`, `skills`,
     * `mcpServers`), accepted in either spelling a preset author writes.
     *
     * THE COMMA STRING IS CLAUDE CODE'S SPELLING (`tools: Read, Grep, Glob`),
     * and the one a preset copied from `.claude/agents` arrives in. It used to
     * reach AgentPreset's `array` parameter raw and throw a TypeError — which,
     * before {@see list()} isolated each file, emptied the whole roster (audit
     * AG-2). It is split the way
     * {@see ForeignAgentPresetRegistry::toolList()} splits that dialect: on
     * commas, each entry trimmed, empties dropped. That method is private and
     * also rewrites Claude's `Bash(git:*)` prefix form, a translation that
     * belongs at the FOREIGN import boundary and not on a native file, so the
     * split is restated here rather than shared.
     *
     * A YAML list must hold strings. A number or a nested map inside it is not
     * a name any consumer can match — {@see \SugarCraft\McpClient\McpRouter::serverAllowed()}
     * already throws on one at call time — so it is refused here, where the
     * failure can name the file and cost only that file.
     *
     * @param array<string, mixed> $data
     * @return list<string>
     * @throws \InvalidArgumentException when the value is neither a string nor a list of strings
     */
    private static function nameList(array $data, string $key, string $filePath): array
    {
        $value = $data[$key] ?? null;
        if ($value === null) {
            return [];
        }

        if (is_string($value)) {
            $names = [];
            foreach (explode(',', $value) as $item) {
                $item = trim($item);
                if ($item !== '') {
                    $names[] = $item;
                }
            }

            return $names;
        }

        if (is_array($value)) {
            foreach ($value as $item) {
                if (!is_string($item)) {
                    throw new \InvalidArgumentException(sprintf(
                        '%s: `%s:` must be a list of names or a comma-separated string; it holds a %s entry',
                        $filePath,
                        $key,
                        get_debug_type($item),
                    ));
                }
            }

            return array_values($value);
        }

        throw new \InvalidArgumentException(sprintf(
            '%s: `%s:` must be a list of names or a comma-separated string, %s given',
            $filePath,
            $key,
            get_debug_type($value),
        ));
    }

    /**
     * A scalar-string field, or null when the key is absent.
     *
     * REFUSED RATHER THAN CAST when YAML typed it as anything else. `name: 123`
     * and `permissionMode: 5` used to hit AgentPreset's typed constructor (or
     * the string-typed enum parsers) as an int and throw a TypeError naming
     * neither the file nor the field; a cast would instead accept
     * `permissionMode: true` as the string "1" and fall silently to the
     * default mode. The message names both, and {@see list()} turns it into
     * a one-file skip.
     *
     * @param array<string, mixed> $data
     * @throws \InvalidArgumentException when the value is present and not a string
     */
    private static function stringField(array $data, string $key, string $filePath): ?string
    {
        $value = $data[$key] ?? null;
        if ($value === null || is_string($value)) {
            return $value;
        }

        throw new \InvalidArgumentException(sprintf(
            '%s: `%s:` must be a string, %s given (quote it if it is meant literally)',
            $filePath,
            $key,
            get_debug_type($value),
        ));
    }

    /**
     * The preset's prompt: a declared `initialPrompt:` if there is one, else
     * the markdown body.
     *
     * The body is where Claude Code and opencode both put a subagent's prompt,
     * so a `reviewer.md` written to either convention used to register with an
     * empty prompt here — the agent arrived carrying nothing but its
     * environment block. Null (rather than '') when neither is present, so
     * AgentPreset's own "no prompt" value is preserved.
     */
    private static function resolveInitialPrompt(mixed $declared, string $body): ?string
    {
        if (is_string($declared) && $declared !== '') {
            return $declared;
        }

        return $body === '' ? null : $body;
    }

    /**
     * Each of the four resolvers below used to be a hand-written
     * `match (strtolower($value))` that restated its enum's own backed values
     * and swallowed everything else into a silent default. Two things were
     * wrong with that, and only the second one bit:
     *
     *  - It duplicated the enum. Adding a case meant remembering to add an arm.
     *  - It knew ONE spelling. `permissionMode: acceptEdits` -- Claude Code's
     *    spelling of `accept-edits`, and what `.candy-code/agents/coder.md`
     *    in this very repository declares -- matched no arm and resolved to
     *    {@see PermissionMode::Default}, with nothing logged. The preset had
     *    been losing its permission mode for as long as it had existed.
     *
     * {@see EnumSpelling::resolve()} is the sibling reader's camel-to-kebab
     * retry, extracted so both readers share one answer.
     *
     * THE FOUR FIELDS A DELEGATED RUN ACTS ON REFUSE AN UNKNOWN SPELLING
     * (roadmap 4.1-1, the fourth added by C-AGT-3.b). `permissionMode` and
     * `effort` change what `Task` does, `isolation` will (4.9), and `memory`
     * selects the per-agent store its notes and index use, so a typo there is
     * no longer a harmless no-op: `effort: maximum` silently becoming
     * `medium` — or `permissionMode: plna` silently becoming the session's
     * mode, or `memory: projet` silently becoming no store at all — is a
     * preset that does something its author did not write. The refusal names
     * the file, the field and the accepted values, and {@see list()} turns it
     * into a one-file skip the launch reports.
     *
     * @throws \InvalidArgumentException for a declared value no case spells
     */
    private static function parsePermissionMode(?string $value, string $filePath): PermissionMode
    {
        return $value === null ? PermissionMode::Default : self::declaredCase(PermissionMode::class, 'permissionMode', $value, $filePath);
    }

    /**
     * Null when the file declares no memory tier — the agent then has no
     * per-agent store at all (see {@see \SugarCraft\Code\Memory\AgentMemory}
     * and {@see AgentPreset}); a declared tier must spell a real case.
     *
     * @throws \InvalidArgumentException for a declared value no case spells
     */
    private static function parseMemoryScope(?string $value, string $filePath): ?MemoryScope
    {
        return $value === null ? null : self::declaredCase(MemoryScope::class, 'memory', $value, $filePath);
    }

    /**
     * Null when the file declares no effort — the provider's own default
     * stands, never an implied `medium` (see {@see AgentPreset}).
     *
     * @throws \InvalidArgumentException for a declared value no case spells
     */
    private static function parseEffort(?string $value, string $filePath): ?Effort
    {
        return $value === null ? null : self::declaredCase(Effort::class, 'effort', $value, $filePath);
    }

    /**
     * @throws \InvalidArgumentException for a declared value no case spells
     */
    private static function parseIsolation(?string $value, string $filePath): ?Isolation
    {
        return $value === null ? null : self::declaredCase(Isolation::class, 'isolation', $value, $filePath);
    }

    /**
     * The case of $enum that $value spells, or a refusal naming the file, the
     * field and every accepted value.
     *
     * @template T of \BackedEnum
     * @param class-string<T> $enum
     * @return T
     * @throws \InvalidArgumentException
     */
    private static function declaredCase(string $enum, string $field, string $value, string $filePath): \BackedEnum
    {
        $case = EnumSpelling::resolve($enum, $value);
        if ($case !== null) {
            return $case;
        }

        throw new \InvalidArgumentException(sprintf(
            '%s: `%s: %s` is not a value candy-code knows; use one of %s',
            $filePath,
            $field,
            $value,
            implode(', ', array_map(static fn (\BackedEnum $c): string => (string) $c->value, $enum::cases())),
        ));
    }
}
