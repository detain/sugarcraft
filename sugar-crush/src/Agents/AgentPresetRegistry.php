<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Agents;

use Symfony\Component\Yaml\Yaml;
use SugarCraft\Crush\Permissions\PermissionMode;
use SugarCraft\Crush\Support\ContainedPath;

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
 * defect {@see \SugarCraft\Crush\Workflows\WorkflowRegistry::readableProjectDir()}
 * and {@see \SugarCraft\Crush\Skills\SkillLoader::skillFilesIn()} each closed for
 * their own tier.
 *
 * MEASURED on this host against the pre-fix build, with a fixture checkout whose
 * only content was `.sugar-crush/agents -> <a directory outside the checkout>` —
 * the one line a repository can commit — and one frontmatter-bearing `notes.md`
 * in that directory: both `list()` and
 * {@see \SugarCraft\Crush\Cli\Bootstrap::agentPresets()} returned
 *
 *     preset=notes  desc=PRIVATE NOTE DESCRIPTION  mode=bypass-permissions
 *     prompt=SENTINEL-PRIVATE-BODY sk-live-DEADBEEF
 *
 * with no refusal recorded anywhere. So an outside file's `description` became a
 * roster entry, its body became a sub-agent's `initialPrompt`, and a
 * `permissionMode: bypass-permissions` the repository does not contain was
 * honoured — reachable by `git clone`, since
 * {@see \SugarCraft\Crush\Cli\Bootstrap::chat()} calls `list()` on every launch.
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
     *        `~/.sugar-crush/agents` — nobody but the user chose where it
     *        points". That premise is not established by anything, and
     *        {@see \SugarCraft\Crush\Cli\Bootstrap::agentPresetTiers()} carries
     *        the four-row measurement that refuted it: a symlink out of `$HOME`
     *        arrives in a tarball as readily as in a clone. Both tiers are
     *        anchored now — the project tier to the checkout, the user tier to
     *        `$HOME`, which is the boundary that keeps the working layout
     *        (`~/.sugar-crush/agents -> ~/.claude/agents`, inside the home) while
     *        refusing a link out of it. This parameter still ALLOWS an
     *        unanchored path, because that is a decision for the caller naming
     *        the tiers and not for the registry reading them.
     *
     * TWO THINGS HAPPEN TO THOSE ARGUMENTS HERE, and both exist because the
     * lookup that consumes them ({@see readableSearchPaths()}) is
     * `$this->anchors[$path] ?? null` — a MISS is an unanchored read, so a key
     * that does not match its search path exactly does not weaken the check, it
     * REMOVES it. Measured before this constructor did anything: a search path
     * spelled `<root>/.sugar-crush/agents/` with its anchor keyed without the
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
     * {@see \SugarCraft\Crush\Tests\Agents\AgentPresetAnchorKeyTest::testSeveralTrailingSeparatorsAreStillOneDirectory()}
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
     * {@see \SugarCraft\Crush\Cli\Bootstrap::agentPresets()} actually calls, so
     * leaving either open here makes load()'s copy decorative.
     *
     * @return array<string, AgentPreset> Map of preset name => AgentPreset
     */
    public function list(): array
    {
        $presets = [];

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
                // First search path takes precedence on name conflicts
                if (!isset($presets[$name])) {
                    $presets[$name] = $this->parsePresetFile($file);
                }
            }
        }

        return $presets;
    }

    /**
     * Which search paths this registry refused to read, and why.
     *
     * The seam the refusal needs in order not to be silent, and the same one
     * {@see \SugarCraft\Crush\Skills\SkillManager::refusedDirectories()} and
     * {@see \SugarCraft\Crush\Workflows\WorkflowRegistry::projectTierRefusal()}
     * provide for their tiers: a dropped directory is otherwise indistinguishable
     * from an empty one, and "your repository's agents directory was rejected" is
     * not something a shorter roster can say.
     * {@see \SugarCraft\Crush\Cli\Bootstrap::agentPresets()} merges it into the
     * one collector all three feed.
     *
     * Recomputed by {@see load()}/{@see list()} on every call rather than
     * accumulated, so a refusal never outlives the condition that caused it.
     *
     * KEYED BY THE NORMALISED SPELLING, not by the spelling the caller passed:
     * {@see __construct()} strips trailing separators from every search path
     * before storing it (`<root>/.sugar-crush/agents/` becomes
     * `<root>/.sugar-crush/agents`), and this map is built from those. The
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
     * checkout that ships no `.sugar-crush/agents`. Dropping loses nothing here
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
                // {@see \SugarCraft\Crush\Cli\Bootstrap::agentPresetTiers()}
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

        $data = Yaml::parse($matches[1]);
        if (!is_array($data)) {
            throw new \RuntimeException("Invalid YAML frontmatter in: {$filePath}");
        }

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
            name: $data['name'] ?? basename($filePath, '.md'),
            description: $data['description'] ?? '',
            tools: $data['tools'] ?? [],
            disallowedTools: $data['disallowedTools'] ?? [],
            model: $data['model'] ?? 'inherit',
            permissionMode: $this->parsePermissionMode($data['permissionMode'] ?? 'default'),
            maxTurns: isset($data['maxTurns']) ? (int) $data['maxTurns'] : null,
            skills: $data['skills'] ?? [],
            mcpServers: $data['mcpServers'] ?? [],
            memory: $this->parseMemoryScope($data['memory'] ?? 'user'),
            background: (bool) ($data['background'] ?? false),
            effort: $this->parseEffort($data['effort'] ?? 'medium'),
            isolation: $this->parseIsolation($data['isolation'] ?? null),
            color: $data['color'] ?? null,
            initialPrompt: self::resolveInitialPrompt($data['initialPrompt'] ?? null, $body),
        );
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

    private function parsePermissionMode(string $value): PermissionMode
    {
        return match (strtolower($value)) {
            'accept-edits' => PermissionMode::AcceptEdits,
            'plan' => PermissionMode::Plan,
            'auto' => PermissionMode::Auto,
            'dont-ask' => PermissionMode::DontAsk,
            'bypass-permissions' => PermissionMode::BypassPermissions,
            default => PermissionMode::Default,
        };
    }

    private function parseMemoryScope(string $value): MemoryScope
    {
        return match (strtolower($value)) {
            'project' => MemoryScope::Project,
            'local' => MemoryScope::Local,
            default => MemoryScope::User,
        };
    }

    private function parseEffort(string $value): Effort
    {
        return match (strtolower($value)) {
            'low' => Effort::Low,
            'high' => Effort::High,
            'xhigh' => Effort::XHigh,
            'max' => Effort::Max,
            default => Effort::Medium,
        };
    }

    private function parseIsolation(?string $value): ?Isolation
    {
        if ($value === null) {
            return null;
        }

        return match (strtolower($value)) {
            'worktree' => Isolation::Worktree,
            'none' => Isolation::None,
            default => null,
        };
    }
}
