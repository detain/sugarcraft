<?php

declare(strict_types=1);
// codacy ignore tainted-filename

namespace SugarCraft\Crush\Agents;

use SugarCraft\Crush\Support\ContainedPath;
use SugarCraft\Crush\Support\HomeDirectory;
use SugarCraft\Crush\Permissions\PermissionMode;
use SugarCraft\Crush\Skills\SkillSource;
use Symfony\Component\Yaml\Yaml;

/**
 * Discovers agent definitions written for OTHER coding CLIs -- Claude Code's
 * `.claude/agents/*.md` and opencode's `.opencode/agents/*.md` -- and maps them
 * onto sugar-crush's own {@see AgentPreset}, tagged with the originating
 * {@see SkillSource} so the palette can badge imported presets.
 *
 * Claude Code's subagent frontmatter and AgentPreset's field set are already
 * the same vocabulary (name/description/tools/disallowedTools/model/
 * permissionMode/maxTurns/skills/mcpServers/memory/background/effort/
 * isolation/color/initialPrompt), so that direction is a near-identity map.
 *
 * opencode's dialect is NOT: it spells tools as a `name: bool` map and carries
 * a `permission:` block whose bash entry may hold per-command glob rules
 * ("git push": deny). AgentPreset has no per-command granularity, so those
 * rules are collapsed to a single allow/ask/deny decision per tool and the
 * loss is reported through {@see warnings()} and error_log rather than being
 * discarded silently.
 *
 * NOT YET WIRED INTO THE RUNTIME. Nothing in `src/` or `bin/` constructs this
 * class, so {@see AgentPreset::$source} still has no reader and importing a
 * foreign agent has no runtime effect. The NATIVE {@see AgentPresetRegistry}
 * is no longer in that position: crush_code.md Phase 1 item 1 made
 * `Bootstrap::agentPresets()` construct it and merge its presets into the
 * launch roster. The remaining step for this class is crush_code.md Phase 1
 * item 3 — call {@see discover()} alongside it from the same place (mirroring
 * how `Bootstrap::skillRegistry()` consumes ForeignSkillDiscovery) and badge
 * the imported presets in the palette.
 *
 * DORMANT IS NOT UNGATED, and for one round it was. This class reads TWO
 * repository-chosen directories — `{projectRoot}/.claude/agents` and
 * `{projectRoot}/.opencode/agents` — and the body of every `*.md` in them
 * becomes a sub-agent's `initialPrompt`, under whatever `permissionMode:` the
 * file declares. Until the gates below existed it did that with no containment
 * of any kind, while the NATIVE tier refused the byte-identical shape. MEASURED
 * on this host with `{projectRoot}/.claude/agents -> <a directory outside the
 * checkout>`, one committed line:
 *
 *     FOREIGN discoverClaude:   presets=["leak"] permissionMode=bypass-permissions
 *                               initialPrompt='SIXTH-ESCAPE-BODY sk-live-CAFEBABE'
 *     NATIVE  agentPresets():   presets=[]       refusals={…"outside the checkout"…}
 *
 * Gated NOW rather than at wiring time, for the reason
 * {@see \SugarCraft\Crush\Commands\CommandLoader::loadFromDirectory()} states
 * for its own anchor: a containment rule added when the consumer lands is one
 * written after the consumer already trusts the loader. The refusals are pulled
 * through {@see refusedDirectories()}, the same pull-based seam
 * {@see AgentPresetRegistry::refusedDirectories()} exposes, so the wiring step
 * has a collector to drain instead of a reason to add one.
 */
final class ForeignAgentPresetRegistry
{
    private const FRONTMATTER_PATTERN = '/^---\s*\n(.*?)\n---\s*\n/s';

    /**
     * opencode names its built-in tools in lowercase; sugar-crush's own tools
     * report StudlyCase names ({@see \SugarCraft\Crush\Tools\BuiltIn\Bash::name()}).
     * Names absent from this map pass through untouched -- a foreign tool
     * sugar-crush does not implement is harmless in an allow/deny list.
     *
     * The map is by CAPABILITY, not by name: opencode splits file mutation
     * across `edit`/`write`/`patch` and listing across `glob`/`list`, while
     * sugar-crush performs all of those through Edit and Glob. Passing `write`
     * through untouched would make `write: false` an inert deny against a
     * capability the imported agent demonstrably still has, so the aliases
     * fold onto the tool that actually performs the work; recordDecision()'s
     * strictest-wins merge then resolves an `edit: true` / `write: false`
     * disagreement in favour of the deny.
     */
    private const OPENCODE_TOOL_NAMES = [
        'bash' => 'Bash',
        'edit' => 'Edit',
        'write' => 'Edit',
        'patch' => 'Edit',
        'read' => 'Read',
        'glob' => 'Glob',
        'list' => 'Glob',
        'grep' => 'Grep',
        'webfetch' => 'WebFetch',
    ];

    /** Strictest-wins ordering used when one tool picks up several decisions. */
    private const DECISION_RANK = ['allow' => 0, 'ask' => 1, 'deny' => 2];

    /** @var list<string> Lossy-mapping notices from the most recent discover* call. */
    private array $warnings = [];

    /**
     * Directories the most recent discover* call declined to read, path as
     * spelled => why — see {@see refusedDirectories()}.
     *
     * @var array<string, string>
     */
    private array $refusedDirectories = [];

    /**
     * Discover every foreign agent preset reachable from $projectRoot.
     *
     * Claude Code presets win a filename collision with an opencode preset --
     * the same tool precedence {@see \SugarCraft\Crush\Skills\SkillLoader::loadAll()}
     * applies to foreign skills.
     *
     * The union operator (left wins) rather than a spread or array_merge:
     * PHP casts a numeric-string key to int, and both of those renumber int
     * keys. A preset file named `12.md` present in both trees would come back
     * as two entries under 0 and 1 -- losing the collision AND the
     * filename-stem key this method promises.
     *
     * @return array<string, AgentPreset> keyed by preset filename stem
     */
    public function discover(string $projectRoot): array
    {
        $this->reset();

        $claude = $this->scanClaude($projectRoot);

        return $claude + $this->scanOpencode($projectRoot);
    }

    /**
     * Discover Claude Code agents from ~/.claude/agents and
     * {projectRoot}/.claude/agents, tagged SkillSource::Claude.
     *
     * @return array<string, AgentPreset> keyed by preset filename stem
     */
    public function discoverClaude(string $projectRoot): array
    {
        $this->reset();

        return $this->scanClaude($projectRoot);
    }

    /**
     * Discover opencode agents from ~/.config/opencode/agents and
     * {projectRoot}/.opencode/agents, tagged SkillSource::Opencode.
     *
     * @return array<string, AgentPreset> keyed by preset filename stem
     */
    public function discoverOpencode(string $projectRoot): array
    {
        $this->reset();

        return $this->scanOpencode($projectRoot);
    }

    /**
     * Human-readable notices about fine-grained foreign rules that could not
     * survive the mapping, collected during the most recent discover* call.
     *
     * @return list<string>
     */
    public function warnings(): array
    {
        return $this->warnings;
    }

    /**
     * Which directories this registry refused to read, and why.
     *
     * The pull-based seam {@see AgentPresetRegistry::refusedDirectories()},
     * {@see \SugarCraft\Crush\Skills\SkillManager::refusedDirectories()} and
     * {@see \SugarCraft\Crush\Workflows\WorkflowRegistry::projectTierRefusal()}
     * each expose for their own tier, provided here for the same reason: a
     * dropped directory is otherwise indistinguishable from an empty one, and
     * "your repository's `.claude/agents` was rejected" is not something a
     * shorter roster can say. Nothing drains it yet — see the class doc-block
     * — which is exactly why it exists before the wiring rather than after.
     *
     * Recomputed by every discover* call rather than accumulated, so a refusal
     * never outlives the condition that caused it.
     *
     * @return array<string, string> directory as spelled => why it was refused
     */
    public function refusedDirectories(): array
    {
        return $this->refusedDirectories;
    }

    /** One launch's notices, not one object's — see {@see refusedDirectories()}. */
    private function reset(): void
    {
        $this->warnings = [];
        $this->refusedDirectories = [];
    }

    /**
     * @return array<string, AgentPreset>
     */
    private function scanClaude(string $projectRoot): array
    {
        return $this->scan(
            [
                [self::userDir('/.claude/agents'), HomeDirectory::owned()],
                [rtrim($projectRoot, '/') . '/.claude/agents', $projectRoot],
            ],
            SkillSource::Claude,
        );
    }

    /**
     * @return array<string, AgentPreset>
     */
    private function scanOpencode(string $projectRoot): array
    {
        return $this->scan(
            [
                [self::userDir('/.config/opencode/agents'), HomeDirectory::owned()],
                [rtrim($projectRoot, '/') . '/.opencode/agents', $projectRoot],
            ],
            SkillSource::Opencode,
        );
    }

    /**
     * The user-tier directory $suffix names, or null when this process cannot
     * establish that the home it resolved is this user's.
     *
     * {@see HomeDirectory::owned()} rather than {@see HomeDirectory::path()},
     * and the difference is the whole point: `path()`'s documented stand-in is
     * `sys_get_temp_dir()`, mode 1777 on every stock Linux, and the bodies read
     * out of these directories become sub-agent prompts. MEASURED on this host
     * with `php -d disable_functions=posix_geteuid,posix_getpwuid` and `HOME`
     * unset — the exact condition the stand-in exists for — `path()` returned
     * `/tmp` (drwxrwxrwt). A user tier that cannot be attributed to the user is
     * therefore skipped rather than read out of a directory anyone can write.
     */
    private static function userDir(string $suffix): ?string
    {
        $home = HomeDirectory::owned();

        return $home === null ? null : $home . $suffix;
    }

    /**
     * Load every `*.md` in each directory. $dirs MUST be ordered
     * lowest-priority-first: the merge is last-write-wins, so the project
     * checkout overrides the user's home directory on a shared filename.
     *
     * Presets are keyed by filename stem, not by their `name:` field, because
     * {@see AgentPresetRegistry::load()} resolves native presets by filename
     * too -- foreign and native presets have to share one key space to be
     * mergeable.
     *
     * TWO BOUNDARIES, the pair {@see \SugarCraft\Crush\Skills\SkillLoader::skillFilesIn()}
     * and {@see \SugarCraft\Crush\Commands\CommandLoader::loadFromDirectory()}
     * each need. The DIRECTORY a repository chose must resolve strictly inside
     * the checkout that named it ({@see ContainedPath::below()}), and each
     * `*.md` ENTRY must still resolve inside the directory it was listed from
     * ({@see ContainedPath::within()}). Without the first the second is
     * relocatable rather than binding: `realpath()` on both sides means a
     * boundary directory that is itself a symlink travels with the link.
     *
     * THE USER TIER IS ANCHORED TO `$HOME`, and this paragraph used to say the
     * opposite: "a null anchor is an UNANCHORED read, which is the right answer
     * for the user's own `~/.claude/agents` — nobody but the user chose where it
     * points". That premise is not established by anything this class checks.
     * MEASURED on the native sibling, `$HOME` mode 0700 and owned, its only
     * content `.sugar-crush/agents -> <outside>` delivered by `tar xzf`: the
     * roster came back with `permissionMode: bypass-permissions` and an outside
     * file's body as the sub-agent prompt, and NO `.git` was involved — the
     * symlink arrived in a tarball. See
     * {@see \SugarCraft\Crush\Cli\Bootstrap::agentPresetTiers()} for the full
     * four-row measurement and for what the anchor costs. `$HOME` is the anchor
     * rather than the checkout because it is the boundary that makes "the user's
     * own directory" TRUE: a link to `~/.claude/agents` is inside it, a link to
     * `/opt/shared` is not.
     *
     * A null DIRECTORY is a user tier that could not be attributed to this user
     * at all; see {@see userDir()}. A null ANCHOR is still an unanchored read
     * and no caller passes one today — the shape is kept because the project
     * tier's anchor is `$projectRoot`, which a caller may legitimately not have.
     *
     * A LIST OF PAIRS rather than a `directory => anchor` map, for the reason
     * {@see AgentPresetRegistry::__construct()} had to grow a normaliser and a
     * throw: a map keyed by path makes a one-byte spelling difference between
     * the key and the search path silently REMOVE the anchor rather than weaken
     * it. There is no key here to mismatch.
     *
     * @param  list<array{0: string|null, 1: string|null}> $dirs [directory, checkout anchor]
     * @return array<string, AgentPreset>
     */
    private function scan(array $dirs, SkillSource $source): array
    {
        // SkillSource has four cases and only two of them name a foreign agent
        // dialect this class can read. A two-way `=== Claude ? … : …` made
        // Native and AgentSkillsSpec fall into the opencode branch, which would
        // silently mis-read their frontmatter (tools-as-map, prompt-as-
        // initialPrompt). Rejected here, before the file loop, so a wrong
        // caller surfaces as an exception instead of being swallowed by the
        // per-file catch below, which exists to tolerate malformed FILES.
        match ($source) {
            SkillSource::Claude, SkillSource::Opencode => null,
            SkillSource::Native, SkillSource::AgentSkillsSpec => throw new \InvalidArgumentException(
                "ForeignAgentPresetRegistry has no agent-frontmatter dialect for SkillSource::{$source->name}.",
            ),
        };

        $presets = [];

        foreach ($dirs as [$dir, $anchor]) {
            if ($dir === null || !is_dir($dir)) {
                continue;
            }

            if ($anchor !== null && !ContainedPath::below($dir, $anchor)) {
                // NAMES THE ANCHOR, not "the checkout": the user tier is
                // anchored to `$HOME` now, where the objection is that the link
                // leaves the user's own home rather than that a repository
                // chose it. See the native sibling's identical correction in
                // {@see AgentPresetRegistry::readableSearchPaths()}.
                $this->refusedDirectories[$dir] = sprintf(
                    'resolves to %s, %s the directory it is anchored to (%s) — a link out of that directory '
                    . "would put unrelated files' bodies in a sub-agent's prompt, under whatever "
                    . 'permissionMode they declare',
                    (string) realpath($dir),
                    realpath($anchor) === realpath($dir) ? 'which is exactly' : 'outside',
                    $anchor,
                );

                continue;
            }

            foreach (glob(rtrim($dir, '/') . '/*.md') ?: [] as $file) {
                // The directory is contained; an ENTRY inside it need not be.
                // `agents/link.md -> ~/.ssh/config` is one committed line, and
                // `glob()` does not resolve it.
                if (!ContainedPath::within($file, $dir)) {
                    $this->refusedDirectories[$file] = sprintf(
                        'resolves outside %s, the directory it was listed from, so it is not an agent that '
                        . 'directory declares',
                        $dir,
                    );

                    continue;
                }

                try {
                    [$data, $body] = $this->frontmatter($file);
                    $name = basename($file, '.md');
                    $presets[$name] = match ($source) {
                        SkillSource::Claude => $this->claudePreset($data, $name, $body),
                        SkillSource::Opencode => $this->opencodePreset($data, $name, $body),
                    };
                } catch (\Throwable $e) {
                    // One malformed foreign file must not abort the import of
                    // every other agent in the directory.
                    error_log("ForeignAgentPresetRegistry: skipping {$file}: {$e->getMessage()}");
                }
            }
        }

        return $presets;
    }

    /**
     * Read and parse a preset file's YAML frontmatter block.
     *
     * Deliberately duplicates the read/regex/Yaml::parse body of
     * {@see AgentPresetRegistry::parsePresetFile()}: that method is private and
     * fused to arrayToPreset(), so sharing it would mean adding a public
     * frontmatter utility to a registry class that has no other static API.
     * The same block also lives in {@see \SugarCraft\Crush\Skills\SkillLoader}
     * and {@see \SugarCraft\Crush\Skills\Skill::parse()}, so the honest fix is
     * one repo-wide frontmatter reader those four call -- a refactor well
     * outside a foreign-preset import. Left duplicated until that lands.
     *
     * Returns the parsed frontmatter AND the markdown after it, because in both
     * foreign dialects the body is where the agent's prompt is written.
     *
     * @return array{0: array<string, mixed>, 1: string} [frontmatter, body]
     */
    private function frontmatter(string $file): array
    {
        $content = file_get_contents($file);
        if ($content === false) {
            throw new \RuntimeException("Could not read agent file: {$file}");
        }

        if (!preg_match(self::FRONTMATTER_PATTERN, $content, $matches)) {
            throw new \RuntimeException("No YAML frontmatter found in: {$file}");
        }

        $data = Yaml::parse($matches[1]);
        if (!is_array($data)) {
            throw new \RuntimeException("Invalid YAML frontmatter in: {$file}");
        }

        return [$data, trim(substr($content, strlen($matches[0])))];
    }

    /**
     * Map Claude Code subagent frontmatter onto an AgentPreset. The key names
     * already line up field-for-field; only `tools`/`disallowedTools` need
     * normalising, because Claude Code accepts them as a comma-separated
     * string as well as a list.
     *
     * Claude Code writes a subagent's system prompt as the markdown BODY, so
     * $body is the prompt whenever no `initialPrompt:` key declares one; a
     * declared key wins, being the more specific statement of intent.
     *
     * @param array<string, mixed> $data
     */
    private function claudePreset(array $data, string $fallbackName, string $body = ''): AgentPreset
    {
        return new AgentPreset(
            name: isset($data['name']) ? (string) $data['name'] : $fallbackName,
            description: isset($data['description']) ? (string) $data['description'] : '',
            tools: $this->toolList($data['tools'] ?? []),
            disallowedTools: $this->toolList($data['disallowedTools'] ?? $data['disallowed-tools'] ?? []),
            model: isset($data['model']) ? (string) $data['model'] : 'inherit',
            permissionMode: $this->enum(PermissionMode::class, $data['permissionMode'] ?? null) ?? PermissionMode::Default,
            maxTurns: isset($data['maxTurns']) ? (int) $data['maxTurns'] : null,
            skills: (array) ($data['skills'] ?? []),
            mcpServers: (array) ($data['mcpServers'] ?? []),
            memory: $this->enum(MemoryScope::class, $data['memory'] ?? null) ?? MemoryScope::User,
            background: (bool) ($data['background'] ?? false),
            effort: $this->enum(Effort::class, $data['effort'] ?? null) ?? Effort::Medium,
            isolation: $this->enum(Isolation::class, $data['isolation'] ?? null),
            color: isset($data['color']) ? (string) $data['color'] : null,
            initialPrompt: self::resolveInitialPrompt($data['initialPrompt'] ?? null, $body),
            source: SkillSource::Claude,
        );
    }

    /**
     * Map opencode agent frontmatter onto an AgentPreset. opencode's
     * `mode`/`temperature`/`disable` keys have no AgentPreset counterpart and
     * are left behind; its `prompt` is sugar-crush's `initialPrompt`, and its
     * `tools:`/`permission:` blocks collapse into `tools`/`disallowedTools`.
     *
     * opencode also accepts the prompt as the markdown BODY, so $body is used
     * when no `prompt:` key declares one; a declared `prompt:` wins.
     *
     * @param array<string, mixed> $data
     */
    private function opencodePreset(array $data, string $fallbackName, string $body = ''): AgentPreset
    {
        [$allowed, $denied] = $this->opencodeToolLists($data, $fallbackName);

        return new AgentPreset(
            name: isset($data['name']) ? (string) $data['name'] : $fallbackName,
            description: isset($data['description']) ? (string) $data['description'] : '',
            tools: $allowed,
            disallowedTools: $denied,
            model: isset($data['model']) ? (string) $data['model'] : 'inherit',
            skills: (array) ($data['skills'] ?? []),
            initialPrompt: self::resolveInitialPrompt($data['prompt'] ?? null, $body),
            source: SkillSource::Opencode,
        );
    }

    /**
     * The imported preset's prompt: the dialect's declared prompt key if there
     * is one, else the markdown body.
     *
     * Both foreign conventions put a subagent's system prompt in the body, so
     * without this an imported `reviewer.md` mapped onto an AgentPreset with a
     * null prompt and (once registered) an Agent carrying nothing but its
     * environment block. Null rather than '' when neither is present, to
     * preserve AgentPreset's own "no prompt" value.
     */
    private static function resolveInitialPrompt(mixed $declared, string $body): ?string
    {
        if (is_string($declared) && $declared !== '') {
            return $declared;
        }

        return $body === '' ? null : $body;
    }

    /**
     * Reduce opencode's `tools:` map and `permission:` block to one
     * allow/ask/deny decision per tool, then split into the allow list and the
     * deny list. `ask` lands in neither: it means "prompt at call time", which
     * is exactly what an unlisted tool already does.
     *
     * Strictest decision wins when the two blocks disagree (deny > ask >
     * allow) -- an import must never widen the permissions the foreign
     * definition granted, which is also why a `tools:` value that is not a
     * literal boolean grants nothing at all.
     *
     * @param  array<string, mixed> $data
     * @return array{list<string>, list<string>}
     */
    private function opencodeToolLists(array $data, string $agentName): array
    {
        /** @var array<string, string> $decisions */
        $decisions = [];

        foreach ((array) ($data['tools'] ?? []) as $tool => $enabled) {
            // A YAML list (`tools:\n  - read`) parses to [0 => 'read'], so the
            // NAME is the value and the int key is only its index. Reading the
            // key as the name imported the indices themselves ([0, 1]) as tool
            // names. A bare list of names is an allow list, the same reading
            // Claude Code's `tools:` list and AgentPreset::$tools already have.
            if (is_int($tool) && is_string($enabled)) {
                $listed = trim($enabled);
                if ($listed !== '') {
                    $this->recordDecision($decisions, $listed, 'allow');
                }
                continue;
            }

            // ONLY a literal true/false is a decision. `$enabled ? 'allow' :
            // 'deny'` turned every truthy non-bool -- a string, a number, a
            // nested map -- into an ALLOW, so one malformed or unexpected line
            // WIDENED the permissions the author wrote. Fail closed instead:
            // report it and record nothing, leaving the tool in neither list so
            // the runtime prompts, exactly as an unrecognised `permission:`
            // value is handled below.
            if (!is_bool($enabled)) {
                $this->warn(sprintf(
                    'opencode agent "%s": tools.%s was %s, not a boolean — ignored, so the tool falls back to prompting rather than being granted.',
                    $agentName,
                    (string) $tool,
                    get_debug_type($enabled),
                ));
                continue;
            }

            $this->recordDecision($decisions, (string) $tool, $enabled ? 'allow' : 'deny');
        }

        foreach ((array) ($data['permission'] ?? []) as $tool => $rule) {
            if (is_array($rule)) {
                $this->recordDecision($decisions, (string) $tool, $this->collapseRules((string) $tool, $rule, $agentName));
                continue;
            }

            // Validate here rather than leaving it to recordDecision()'s silent
            // drop: an unreadable rule inside a fine-grained map is reported, so
            // an unreadable scalar rule must be too. The tool still lands in
            // neither list (the runtime then prompts), but the author learns the
            // rule they wrote had no effect.
            $decision = strtolower((string) $rule);
            if (!isset(self::DECISION_RANK[$decision])) {
                $this->warn(sprintf(
                    'opencode agent "%s": permission.%s had an unrecognised value "%s" — ignored, so the tool falls back to prompting.',
                    $agentName,
                    (string) $tool,
                    $decision,
                ));
                continue;
            }

            $this->recordDecision($decisions, (string) $tool, $decision);
        }

        $allowed = [];
        $denied = [];
        foreach ($decisions as $tool => $decision) {
            if ($decision === 'allow') {
                $allowed[] = $tool;
            } elseif ($decision === 'deny') {
                $denied[] = $tool;
            }
        }

        return [$allowed, $denied];
    }

    /**
     * Collapse a fine-grained `permission.<tool>` glob map ("git push": deny)
     * into the single strictest decision it contains, and record what was lost
     * -- AgentPreset carries no per-command rules, and silently dropping a
     * deny rule would hand an imported agent more power than its author gave it.
     *
     * Seeding the collapse with "allow" would do exactly that whenever no rule
     * is legible to us: a typo (`"git push": block`), an empty map, or a rule
     * keyword opencode adds later all yield zero recognised decisions, and an
     * "allow" default would then promote the tool into the allow list off the
     * back of a rule the author wrote to restrict it. Nothing recognised
     * therefore collapses to "ask" -- the tool lands in neither list and the
     * runtime prompts, which is the safe reading of an unreadable rule.
     *
     * @param array<string, mixed> $rules
     */
    private function collapseRules(string $tool, array $rules, string $agentName): string
    {
        $decision = null;
        $dropped = [];
        $unrecognised = [];

        foreach ($rules as $pattern => $rule) {
            $rule = strtolower((string) $rule);
            $dropped[] = "{$pattern}: {$rule}";
            if (!isset(self::DECISION_RANK[$rule])) {
                $unrecognised[] = "{$pattern}: {$rule}";
                continue;
            }
            if ($decision === null || self::DECISION_RANK[$rule] > self::DECISION_RANK[$decision]) {
                $decision = $rule;
            }
        }

        $decision ??= 'ask';

        $warning = sprintf(
            'opencode agent "%s": permission.%s had %d fine-grained rule(s) (%s) with no AgentPreset equivalent — collapsed to "%s".',
            $agentName,
            $tool,
            count($dropped),
            implode(', ', $dropped),
            $decision,
        );

        if ($unrecognised !== []) {
            $warning .= sprintf(
                ' Unrecognised rule value(s) ignored: %s.',
                implode(', ', $unrecognised),
            );
        }
        $this->warn($warning);

        return $decision;
    }

    /**
     * Record a lossy-mapping notice for {@see warnings()} and mirror it to
     * error_log, so an import running before the palette wiring lands (see the
     * class docblock) still leaves a trace the user can find.
     */
    private function warn(string $warning): void
    {
        $this->warnings[] = $warning;
        error_log('ForeignAgentPresetRegistry: ' . $warning);
    }

    /**
     * @param array<string, string> $decisions
     */
    private function recordDecision(array &$decisions, string $tool, string $decision): void
    {
        if (!isset(self::DECISION_RANK[$decision])) {
            return;
        }

        $tool = self::OPENCODE_TOOL_NAMES[strtolower($tool)] ?? $tool;
        $current = $decisions[$tool] ?? null;
        if ($current === null || self::DECISION_RANK[$decision] > self::DECISION_RANK[$current]) {
            $decisions[$tool] = $decision;
        }
    }

    /**
     * Normalise a `tools:` value that may be a YAML list or Claude Code's
     * comma-separated string form into a list of tool names.
     *
     * @return list<string>
     */
    private function toolList(mixed $value): array
    {
        $items = is_string($value) ? explode(',', $value) : (array) $value;
        $names = [];
        foreach ($items as $item) {
            $name = trim((string) $item);
            if ($name !== '') {
                $names[] = $name;
            }
        }

        return $names;
    }

    /**
     * Resolve a frontmatter scalar to a backed enum case, case-insensitively.
     * Returns null for absent or unrecognised values so each caller can apply
     * its own default.
     *
     * The camelCase retry exists because Claude Code spells its permissionMode
     * values `acceptEdits`/`bypassPermissions` while sugar-crush's
     * {@see PermissionMode} is kebab-case. Lowercasing alone leaves `accepteedits`
     * unmatched, and this class's whole contract is that lossy mappings are
     * reported rather than dropped -- a silent fall-back to
     * PermissionMode::Default on the one Claude field spelled differently would
     * be an unreported loss in what is otherwise a near-identity map. The plain
     * lowercase lookup is tried first so a value that is already kebab (or has
     * no case boundary, like `xhigh`) never reaches the split.
     *
     * @template T of \BackedEnum
     * @param  class-string<T> $enum
     * @return T|null
     */
    private function enum(string $enum, mixed $value): ?\BackedEnum
    {
        if (!is_string($value) && !is_int($value)) {
            return null;
        }

        $raw = (string) $value;

        return $enum::tryFrom(strtolower($raw))
            ?? $enum::tryFrom(strtolower((string) preg_replace('/(?<!^)[A-Z]/', '-$0', $raw)));
    }
}
