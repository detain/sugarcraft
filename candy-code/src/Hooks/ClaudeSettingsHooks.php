<?php

declare(strict_types=1);

namespace SugarCraft\Code\Hooks;

use Symfony\Component\Yaml\Yaml;

/**
 * Reads the `hooks` block of a Claude Code settings file and hands back the
 * SAME flat entry rows {@see HookConfig::parse()} yields, so a launch
 * registers both dialects through ONE chain with identical validation.
 *
 * The shape Claude Code writes (nested where the native file is flat):
 *
 * ```json
 * {"hooks":{"PreToolUse":[{"matcher":"Bash","hooks":[{"type":"command","command":"./guard.sh","timeout":60}]}]}}
 * ```
 *
 * Each inner hook becomes one row named `cc:{scope}:{event}:{index}` — CC
 * entries carry no `name`, and {@see HookRegistry} keys by event+name, so
 * the generated name is what keeps two inner hooks on one event from
 * collapsing into one registration (the exact reason {@see HookConfig} made
 * `name` a key). The scope segment names the TIER the file was loaded from:
 * `user` for `~/.claude/settings.json`, `project` for a trusted project's
 * `.claude/settings.json`, `local` for its `.claude/settings.local.json`.
 * The SAME event at the SAME index in two different tiers therefore each
 * keeps its row instead of refusing the launch (C-EXT-1.b — under the old
 * `cc:{event}:{index}` spelling a user file and a project file agreeing on
 * one name took the whole launch down). The `cc:` prefix means a CC entry
 * can never claim a native `hooks.yaml` guard's name and never collides with
 * the reserved built-in names; a hand-written `hooks.yaml` entry that CLAIMS
 * one of these generated names does collide, and
 * {@see HookManager::loadEntries()}
 * refuses that launch-wide exactly as it refuses a native name collision —
 * a hook chain adds, it does not replace.
 *
 * VALIDATION IS NOT RE-IMPLEMENTED. The flattened entries are normalised
 * into the native document shape and handed to {@see HookConfig::parse()}
 * itself, so the event-name check, the matcher-compiles check (via
 * {@see HookConfig::pattern()}), the positive-finite `timeout` guard and the
 * no-coercion rules are the SAME code the YAML file runs through. A second
 * validator would be a second opinion nobody asked for, and the day the two
 * disagree is the day a hook loads under one and misbehaves under the other.
 *
 * HARD-SCOPED TO THE `hooks` KEY. The same file legitimately carries
 * `permissions`, `env`, `model` and more — candy-code's layered settings
 * authority owns those surfaces, and lifting them from here would fork that
 * authority into two loaders with two precedences over one file. Unknown
 * TOP-LEVEL keys are therefore not errors (a real CC file has many); they
 * are simply not this parser's subject. Only what sits under `hooks` is
 * read, and within it the same absence-is-silent law as the native format:
 * anything that is malformed LOUDLY refuses the launch, only a file that is
 * not there is a no-op.
 *
 * `type` RUNS ONLY `command`; the rest of the type question is
 * SKIP-AND-RECORD (C-EXT-1.b widened what v1:F-1 had covered). CC's newer
 * `prompt`, `agent`, `http` and `mcp_tool` types have no exit-code verdict
 * for candy-code to read, so running them would not be the guard the author
 * wrote — but a whole launch refusing over a type this loader simply does
 * not speak gave one vendor's settings file the blast radius of a hard
 * outage again. An entry with a STRING type that is not `command` now
 * contributes no row and is RECORDED by type name for the same
 * launch-notice aggregate. A MISSING or non-string `type` is not a dialect
 * candy-code recognises at all, and still refuses the launch by name.
 *
 * SKIP-AND-RECORD FOR WHAT CANNOT RUN (v1:F-1, widened by C-EXT-1.b): CC's
 * `Notification` event has no {@see HookEvent} case, and neither do the
 * newer events CC keeps shipping (plan ruling R3 for the native dialect
 * stands untouched). A FOREIGN file is not candy-code's to enforce against:
 * refusing the launch over one unmappable event name gave one vendor's
 * settings file the blast radius of a hard outage in EVERY launch mode.
 * So an event with no case contributes no rows and is RECORDED — event name
 * and source file — for {@see \SugarCraft\Code\Cli\Bootstrap} to drain as
 * one launch-notice aggregate; a guard that cannot be mapped still never
 * runs silently. The same verdict now covers what sits INSIDE a recognised
 * event: entries of an unsupported string `type`, and entries carrying keys
 * this dialect does not speak (`if`, `once`, anything outside
 * {@see ENTRY_KEYS}), are skipped whole and recorded by name on the same
 * aggregate. Everything ELSE about a foreign `hooks` block keeps the loud
 * law: malformed JSON, bad group keys, a missing or non-string `type` and
 * HookConfig's own verdicts refuse the launch by name, because those files
 * DID ask for a guard and candy-code cannot honestly run half of one.
 * The native `hooks.yaml` keeps the hard event refusal unchanged — that
 * file is candy-code's own dialect, and R3's reasoning was always about it.
 *
 * Relative commands are NOT anchored to the settings file's directory, and
 * that is CC's own semantics rather than an omission: Claude Code runs hook
 * commands with the project as cwd, and {@see ScriptHook} spawns with
 * `$context->projectRoot` — the pair already agree. ({@see HookConfig}'s
 * ISS-039 anchoring keys off the native user file's realpath, so it
 * correctly does not fire for these paths.)
 *
 * WHAT THE NATIVE VALIDATOR CAN NEVER SAY, AND THE DIALECT IT NEEDS. Two CC
 * keys have no native equivalent to flatten INTO, so they are carried as the
 * pair of stamps {@see parse()} adds after the validator returns:
 * `statusMessage` rides the native `description`, and `async: true` rides
 * `advisory`. Every row this file produces also carries `dialect`
 * ({@see ScriptHook::DIALECT_CLAUDE}), which is how {@see ScriptHook} knows the
 * file behind it was written against Claude Code's two-code exit shell — `0`
 * proceeds, `2` blocks, any other non-zero is a complaint rather than a deny —
 * rather than against candy-code's six-row table. An `async` hook runs inline
 * here, inside its timeout, because there is no detached runner in candy-code;
 * what it forfeits is the power to block. Both readings live in
 * {@see ScriptHook::claudeDialectVerdict()}; the rows they apply to are the
 * subject of the "What is read from a Claude settings file" section of
 * `docs/HOOKS.md`.
 */
final class ClaudeSettingsHooks
{
    /** Basename of the shared Claude Code settings file. */
    public const SETTINGS_FILE_NAME = 'settings.json';

    /** Basename of Claude Code's machine-local settings file. */
    public const LOCAL_FILE_NAME = 'settings.local.json';

    /**
     * Basenames, under a `.claude` directory, whose `hooks` block is read.
     */
    public const FILENAMES = [self::SETTINGS_FILE_NAME, self::LOCAL_FILE_NAME];

    /** The name's scope segment for `~/.claude/settings.json`. */
    public const SCOPE_USER = 'user';

    /** The name's scope segment for a trusted project's `settings.json`. */
    public const SCOPE_PROJECT = 'project';

    /** The name's scope segment for a project's `settings.local.json`. */
    public const SCOPE_LOCAL = 'local';

    /** Every scope {@see fromFile()} and {@see parse()} accept. */
    public const SCOPES = [self::SCOPE_USER, self::SCOPE_PROJECT, self::SCOPE_LOCAL];

    /**
     * Events seen in a CC file that {@see HookEvent} has no case for, keyed
     * by event name: how many groups carried them, and which files did.
     * The record behind the launch-notice aggregate
     * ({@see \SugarCraft\Code\Cli\Bootstrap::reportSkippedClaudeSettingsEvents()}).
     *
     * @var array<string, array{groups: int, files: array<string, true>}>
     */
    private static array $skippedEvents = [];

    /**
     * Entry-level skips (C-EXT-1.b): hook types that are not `command` and
     * keys this dialect does not speak, keyed by the skipped name — what kind
     * of skip it was first seen as, how many groups carried it, under which
     * events, and in which files. Drains on the same aggregate as
     * {@see $skippedEvents}.
     *
     * @var array<string, array{kind: string, groups: int, events: array<string, true>, files: array<string, true>}>
     */
    private static array $skippedEntries = [];

    /**
     * The PHYSICAL groups whose every usable entry was skipped because the
     * group's EVENT had no case (C-EXT-1.b round 2): keyed
     * `source\0event\0group-index`, so the same group can never be counted
     * twice no matter how many names were recorded for it. The per-name
     * `groups` counters above stay what they always were — a diagnostic of
     * how often one name was skipped — and are deliberately NOT what the
     * launch row's group figure is built from: summing them double-counts a
     * group that carried two different skips.
     *
     * @var array<string, true>
     */
    private static array $skippedEventGroups = [];

    /**
     * Same census for the entry-level skips ({@see $skippedEntries}): one key
     * per group that carried at least one off-dialect type or key.
     *
     * @var array<string, true>
     */
    private static array $skippedEntryGroups = [];

    /** Keys a CC hook group may carry. */
    private const GROUP_KEYS = ['matcher', 'hooks'];

    /**
     * Keys a CC inner hook entry may carry.
     *
     * The first three are the ones with a native equivalent. `statusMessage`
     * and `async` are here because Claude Code WRITES them on real hooks — a
     * file that carries either is not a file that means something else — and
     * what the loader does with each is the pair of bullets in the "What is
     * read from a Claude settings file" section of `docs/HOOKS.md`. They are
     * keys of THIS dialect only: a native `hooks.yaml` entry carrying either
     * is still refused by {@see HookConfig}.
     */
    private const ENTRY_KEYS = ['type', 'command', 'timeout', 'statusMessage', 'async'];

    /** Whether $path names a file this parser reads. */
    public static function isClaudeSettingsPath(string $path): bool
    {
        return \in_array(basename($path), self::FILENAMES, true)
            && basename(\dirname($path)) === '.claude';
    }

    /**
     * Load hooks from a CC settings file, or nothing at all when there is no
     * file — the exact polarity {@see HookConfig::loadFromFile()} documents.
     *
     * @param string $scope which tier the file is — one of {@see SCOPES};
     *        it becomes the scope segment of every generated row name so two
     *        tiers naming one event at one index no longer collide
     *
     * @return list<array{name: string, event: string, matcher: string, command: string, description: string, disabled: bool, timeout: float, unattendedAsk: bool, dialect: string, advisory: bool}>
     *
     * @throws \InvalidArgumentException when $scope is not a tier this loader speaks
     * @throws \RuntimeException when the file exists and cannot be read
     * @throws \InvalidArgumentException when the file exists and cannot be used
     */
    public static function fromFile(string $path, string $scope): array
    {
        self::guardScope($scope);

        if (!is_file($path)) {
            if (file_exists($path) || is_link($path)) {
                throw new \RuntimeException(
                    "{$path} exists but is not a readable file.",
                );
            }

            return [];
        }

        $content = @file_get_contents($path);
        if ($content === false) {
            throw new \RuntimeException(
                "{$path} exists but could not be read (check its permissions).",
            );
        }

        return self::parse($content, $path, $scope);
    }

    /**
     * The scope is launch-wide registration state, not a label to guess at:
     * a wrong spelling would silently merge one tier's rows into another's
     * namespace, which is the collision this segment exists to prevent.
     */
    private static function guardScope(string $scope): void
    {
        if (!\in_array($scope, self::SCOPES, true)) {
            throw new \InvalidArgumentException(
                "'{$scope}' is not a Claude settings tier (valid scopes are 'user', "
                . "'project' and 'local'); refusing rather than naming rows into the wrong tier.",
            );
        }
    }

    /**
     * @param string $source what to call the content in an error message — a
     *        path when there is one, so the report names the file to fix
     * @param string $scope the tier rows are named for (see {@see fromFile()})
     *
     * @return list<array{name: string, event: string, matcher: string, command: string, description: string, disabled: bool, timeout: float, unattendedAsk: bool, dialect: string, advisory: bool}>
     *
     * @throws \InvalidArgumentException when the content is not usable
     */
    public static function parse(string $content, string $source = 'Claude Code settings', string $scope = self::SCOPE_USER): array
    {
        self::guardScope($scope);

        try {
            $data = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new \InvalidArgumentException(
                "{$source} is not usable JSON ({$e->getMessage()}).",
                0,
                $e,
            );
        }

        // A `null` document configures nothing, HookConfig's empty-document
        // tolerance wearing JSON's clothes.
        if ($data === null) {
            return [];
        }

        if (!is_array($data) || ($data !== [] && array_is_list($data))) {
            throw new \InvalidArgumentException(
                "{$source} must be a JSON object; a list has no `hooks` key to read.",
            );
        }

        if (($data['hooks'] ?? null) === null) {
            // The overwhelmingly common case: a CC settings file that only
            // carries permissions/env/model. Those belong to the layered
            // settings authority, not here.
            return [];
        }

        $events = $data['hooks'];
        if (!is_array($events) || ($events !== [] && array_is_list($events))) {
            throw new \InvalidArgumentException(
                "{$source}: `hooks` must be an object mapping event names to lists of hook groups.",
            );
        }

        $document = [];

        // Which generated row names asked for a verdict that may not block,
        // keyed by the `cc:{scope}:{event}:{index}` name above. Collected
        // during the walk and applied after the native validator has had its
        // say, because the validator returns the rows this ends up stamping.
        $advisoryByName = [];

        foreach ($events as $event => $groups) {
            if (!is_string($event)) {
                throw new \InvalidArgumentException(
                    "{$source}: a `hooks` key must be an event name.",
                );
            }

            if (HookEvent::tryFrom($event) === null) {
                // Skip-and-record (v1:F-1): see the class docblock. The count
                // is best-effort over whatever shape the groups list has —
                // an unmappable event's payload is never executed, so only
                // the NAME is owed to the user with any precision.
                $shape = is_array($groups) && array_is_list($groups) ? $groups : [];
                self::$skippedEvents[$event]['groups'] =
                    (self::$skippedEvents[$event]['groups'] ?? 0) + count($shape);
                self::$skippedEvents[$event]['files'][$source] = true;
                foreach (array_keys($shape) as $groupIndex) {
                    self::$skippedEventGroups["{$source}\0{$event}\0{$groupIndex}"] = true;
                }

                continue;
            }

            if (!is_array($groups) || ($groups !== [] && !array_is_list($groups))) {
                throw new \InvalidArgumentException(
                    "{$source}: `hooks.{$event}` must be a list of hook groups.",
                );
            }

            foreach ($groups as $groupIndex => $group) {
                if (!is_array($group) || array_is_list($group)) {
                    throw new \InvalidArgumentException(
                        "{$source}: every entry under `hooks.{$event}` must be an object with a `hooks` list.",
                    );
                }

                $unknown = array_diff(array_keys($group), self::GROUP_KEYS);
                if ($unknown !== []) {
                    throw new \InvalidArgumentException(
                        "{$source}: '" . implode("', '", array_map('strval', $unknown))
                        . "' is not a key a `hooks.{$event}` group has (valid keys are 'matcher' and 'hooks'); "
                        . 'refusing rather than registering a hook that is not the one you wrote.',
                    );
                }

                $matcher = $group['matcher'] ?? '*';
                if (!is_string($matcher)) {
                    throw new \InvalidArgumentException(
                        "{$source}: the `matcher` of a `hooks.{$event}` group is not text.",
                    );
                }

                if (!isset($group['hooks']) || !is_array($group['hooks'])) {
                    throw new \InvalidArgumentException(
                        "{$source}: a `hooks.{$event}` group carries no `hooks` list to run.",
                    );
                }

                // What this group holds that cannot run, keyed by skipped
                // name => the kind of skip it was FIRST seen as (C-EXT-1.b).
                // Recorded once per group so the aggregate's group count
                // stays honest when several entries repeat one skip.
                $groupSkips = [];

                foreach ($group['hooks'] as $entry) {
                    if (!is_array($entry) || array_is_list($entry)) {
                        throw new \InvalidArgumentException(
                            "{$source}: every entry under `hooks.{$event}[hooks]` must be an object.",
                        );
                    }

                    $unknownKeys = array_diff(array_keys($entry), self::ENTRY_KEYS);
                    if ($unknownKeys !== []) {
                        // Skip-and-record whole (C-EXT-1.b): CC grows keys on
                        // real hooks (`if`, `once`), and a foreign file that
                        // asks for behaviour this dialect cannot speak is the
                        // same outage blast radius as an unmappable event or
                        // type — but the entry is STILL never run silently:
                        // every unknown key is named on the launch aggregate,
                        // and the entry contributes no row either way. The
                        // group itself stays valid, so nothing else in the
                        // file is forfeited.
                        foreach ($unknownKeys as $key) {
                            $groupSkips[\strval($key)] = 'key';
                        }

                        continue;
                    }

                    $type = $entry['type'] ?? null;
                    if ($type !== 'command') {
                        if (!\is_string($type)) {
                            // A missing or non-string type is not a dialect
                            // candy-code recognises at all — that stays loud.
                            throw new \InvalidArgumentException(
                                "{$source}: a hook under `hooks.{$event}` has type "
                                . (\is_string($type) ? "'{$type}'" : get_debug_type($type))
                                . "; candy-code runs Claude Code hooks of type 'command' only, and running "
                                . 'the others anyway — or skipping them quietly — would not be the guard you wrote.',
                            );
                        }

                        // Skip-and-record by type name (C-EXT-1.b), same
                        // reasoning as the unknown-key arm above.
                        $groupSkips[$type] = 'type';

                        continue;
                    }

                    $index = $document[$event]['count'] ?? 0;
                    $row = [
                        'name' => "cc:{$scope}:{$event}:{$index}",
                        // CC spells "every tool" as `*` (or empty, or absent);
                        // the native default is the regex that means it.
                        'matcher' => $matcher === '' || $matcher === '*' ? '.*' : $matcher,
                        'command' => $entry['command'] ?? '',
                    ];

                    // `statusMessage` is CC's spinner label, and the native row
                    // has the same slot for the same reader: `description`,
                    // which is what `/hooks` shows. Whether it is TEXT stays
                    // HookConfig's verdict rather than this parser's, per the
                    // "validation is not re-implemented" rule above.
                    if (\array_key_exists('statusMessage', $entry)) {
                        $row['description'] = $entry['statusMessage'];
                    }

                    // `async` decides whether this hook may stop a tool call, so
                    // it is not a value to guess at: HookConfig applies the same
                    // no-coercion law to the two booleans it owns
                    // (`disabled`, `unattendedAsk`) and this flag is the third
                    // of that family.
                    $async = $entry['async'] ?? false;
                    if (!\is_bool($async)) {
                        throw new \InvalidArgumentException(
                            "{$source}: a hook under `hooks.{$event}` has an 'async' that is "
                            . (\is_string($async) ? "'{$async}'" : get_debug_type($async))
                            . "; 'async' is a boolean in Claude Code, and guessing here would decide "
                            . 'whether this hook is allowed to block the tool call it watches.',
                        );
                    }

                    // Absent stays absent so HookConfig applies the default;
                    // present-but-bad (`"60"`, `0`, `null`) is HookConfig's
                    // loud territory, untouched by this parser.
                    if (\array_key_exists('timeout', $entry)) {
                        $row['timeout'] = $entry['timeout'];
                    }

                    $document[$event]['count'] = $index + 1;
                    $document[$event]['rows'][] = $row;
                    $advisoryByName["cc:{$scope}:{$event}:{$index}"] = $async;
                }

                if ($groupSkips !== []) {
                    // ONE physical group, however many different names it
                    // carried (C-EXT-1.b round 2): this is the census the
                    // launch row's group figure is built from, so a group
                    // holding a `prompt` entry AND an `if` key is one group
                    // that contributed no guard, not two.
                    self::$skippedEntryGroups["{$source}\0{$event}\0{$groupIndex}"] = true;
                }

                foreach ($groupSkips as $skipped => $kind) {
                    // One record per name per group that carried it, whatever
                    // number of entries inside the group repeated the skip.
                    self::$skippedEntries[$skipped]['kind'] ??= $kind;
                    self::$skippedEntries[$skipped]['groups'] =
                        (self::$skippedEntries[$skipped]['groups'] ?? 0) + 1;
                    self::$skippedEntries[$skipped]['events'][$event] = true;
                    self::$skippedEntries[$skipped]['files'][$source] = true;
                }
            }
        }

        if ($document === []) {
            return [];
        }

        $normalized = ['hooks' => []];
        foreach ($document as $event => $built) {
            $normalized['hooks'][$event] = $built['rows'];
        }

        // The reuse step: the rows go through the native validator verbatim.
        // Symfony's dumper quotes anything whose bare form would re-parse as
        // a different type, so a command literally named `60` stays a string.
        $rows = HookConfig::parse(Yaml::dump($normalized, 8), $source);

        // And then the two keys the native validator can never produce, stamped
        // on afterwards. `dialect` and `advisory` are not in
        // HookConfig::ENTRY_KEYS — a `hooks.yaml` that spelled either is
        // refused there — so a stamped row is the proof that this file format,
        // and nothing else, produced it. {@see ScriptHook} reads the pair to
        // apply CC's exit-code dialect. This adds no opinion about a row's
        // shape; every judgement about the values above it was HookConfig's.
        foreach ($rows as &$row) {
            $row['dialect'] = ScriptHook::DIALECT_CLAUDE;
            $row['advisory'] = $advisoryByName[$row['name']] ?? false;
        }
        unset($row);

        return $rows;
    }

    /**
     * The unknown events this process's CC parses have skipped so far.
     *
     * @return array<string, array{groups: int, files: array<string, true>}>
     */
    public static function skippedEvents(): array
    {
        return self::$skippedEvents;
    }

    /**
     * The unsupported entry types and keys this process's CC parses have
     * skipped so far (C-EXT-1.b).
     *
     * @return array<string, array{kind: string, groups: int, events: array<string, true>, files: array<string, true>}>
     */
    public static function skippedEntries(): array
    {
        return self::$skippedEntries;
    }

    /**
     * The distinct physical hook groups this process's CC parses skipped
     * anything in (C-EXT-1.b, round 2) — the census behind the launch row's
     * "%d hook group(s)". Keyed `source\0event\0group-index`; the union of
     * the two per-kind sets is what makes the figure honest: a group that
     * carried two different skips is still one group, which summing the
     * per-name `groups` counters would have double-counted.
     *
     * @return array<string, true>
     */
    public static function skippedGroups(): array
    {
        // `+` rather than array_merge: unreachable today (an unmapped event
        // never reaches the entry walk) but cheap insurance that one physical
        // group is one key whatever record claimed it.
        return self::$skippedEventGroups + self::$skippedEntryGroups;
    }

    /**
     * Test seam: the record is process-scoped launch state, and suites that
     * mint their own CC files need their own view of it.
     */
    public static function resetSkippedEventsForTesting(): void
    {
        self::$skippedEvents = [];
        self::$skippedEntries = [];
        self::$skippedEventGroups = [];
        self::$skippedEntryGroups = [];
    }

    /**
     * Test seam for {@see skippedEntries()} when a suite needs the entry
     * record alone; {@see resetSkippedEventsForTesting()} clears both. The
     * entry group census belongs to that record and clears with it.
     */
    public static function resetSkippedEntriesForTesting(): void
    {
        self::$skippedEntries = [];
        self::$skippedEntryGroups = [];
    }
}
