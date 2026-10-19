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
 * Each inner hook becomes one row named `cc:{event}:{index}` — CC entries
 * carry no `name`, and {@see HookRegistry} keys by event+name, so the
 * generated name is what keeps two inner hooks on one event from collapsing
 * into one registration (the exact reason {@see HookConfig} made `name` a
 * key). The `cc:` prefix means a CC entry can never claim a native
 * `hooks.yaml` guard's name and never collides with the reserved built-in
 * names; two CC FILES defining the same event at the same index do collide,
 * and {@see HookManager::loadEntries()} refuses that launch-wide exactly as
 * it refuses a native name collision — a hook chain adds, it does not
 * replace.
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
 * `type` IS ONLY `command`. CC's newer prompt/agent hook types have no
 * exit-code verdict to read, so running them (or skipping them quietly)
 * would not be the hook the author wrote — an unknown type refuses the
 * launch by name.
 *
 * THE ONE DOCUMENTED SKIP: CC's `Notification` event is recognised and
 * skipped, not refused (plan ruling R3, v1). {@see HookEvent} has no
 * `Notification` case, and adding one widens every event-property match and
 * both HOOKS.md tables — an owner ruling this parser does not take. Until
 * that ruling lands, a `Notification` group contributes no rows; every
 * OTHER unknown event still walks into HookConfig's refusal. This paragraph
 * is the record: a user whose notification hook never fires finds the
 * deferral here rather than in a silent gap.
 *
 * Relative commands are NOT anchored to the settings file's directory, and
 * that is CC's own semantics rather than an omission: Claude Code runs hook
 * commands with the project as cwd, and {@see ScriptHook} spawns with
 * `$context->projectRoot` — the pair already agree. ({@see HookConfig}'s
 * ISS-039 anchoring keys off the native user file's realpath, so it
 * correctly does not fire for these paths.)
 */
final class ClaudeSettingsHooks
{
    /**
     * Basenames, under a `.claude` directory, whose `hooks` block is read.
     */
    public const FILENAMES = ['settings.json', 'settings.local.json'];

    /**
     * Events CC defines that {@see HookEvent} has no case for — skipped and
     * recorded, see the class docblock (ruling R3).
     */
    private const DEFERRED_EVENTS = ['Notification'];

    /** Keys a CC hook group may carry. */
    private const GROUP_KEYS = ['matcher', 'hooks'];

    /** Keys a CC inner hook entry may carry. */
    private const ENTRY_KEYS = ['type', 'command', 'timeout'];

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
     * @return list<array{name: string, event: string, matcher: string, command: string, description: string, disabled: bool, timeout: float, unattendedAsk: bool}>
     *
     * @throws \RuntimeException when the file exists and cannot be read
     * @throws \InvalidArgumentException when the file exists and cannot be used
     */
    public static function fromFile(string $path): array
    {
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

        return self::parse($content, $path);
    }

    /**
     * @param string $source what to call the content in an error message — a
     *        path when there is one, so the report names the file to fix
     *
     * @return list<array{name: string, event: string, matcher: string, command: string, description: string, disabled: bool, timeout: float, unattendedAsk: bool}>
     *
     * @throws \InvalidArgumentException when the content is not usable
     */
    public static function parse(string $content, string $source = 'Claude Code settings'): array
    {
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

        foreach ($events as $event => $groups) {
            if (!is_string($event)) {
                throw new \InvalidArgumentException(
                    "{$source}: a `hooks` key must be an event name.",
                );
            }

            if (\in_array($event, self::DEFERRED_EVENTS, true)) {
                continue;
            }

            if (!is_array($groups) || ($groups !== [] && !array_is_list($groups))) {
                throw new \InvalidArgumentException(
                    "{$source}: `hooks.{$event}` must be a list of hook groups.",
                );
            }

            foreach ($groups as $group) {
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

                foreach ($group['hooks'] as $entry) {
                    if (!is_array($entry) || array_is_list($entry)) {
                        throw new \InvalidArgumentException(
                            "{$source}: every entry under `hooks.{$event}[hooks]` must be an object.",
                        );
                    }

                    $unknownKeys = array_diff(array_keys($entry), self::ENTRY_KEYS);
                    if ($unknownKeys !== []) {
                        throw new \InvalidArgumentException(
                            "{$source}: '" . implode("', '", array_map('strval', $unknownKeys))
                            . "' is not a key a hook under `hooks.{$event}` has (valid keys are 'type', "
                            . "'command' and 'timeout'); refusing rather than registering a hook that is "
                            . 'not the one you wrote.',
                        );
                    }

                    $type = $entry['type'] ?? null;
                    if ($type !== 'command') {
                        throw new \InvalidArgumentException(
                            "{$source}: a hook under `hooks.{$event}` has type "
                            . (\is_string($type) ? "'{$type}'" : get_debug_type($type))
                            . "; candy-code runs Claude Code hooks of type 'command' only, and running "
                            . 'the others anyway — or skipping them quietly — would not be the guard you wrote.',
                        );
                    }

                    $index = $document[$event]['count'] ?? 0;
                    $row = [
                        'name' => "cc:{$event}:{$index}",
                        // CC spells "every tool" as `*` (or empty, or absent);
                        // the native default is the regex that means it.
                        'matcher' => $matcher === '' || $matcher === '*' ? '.*' : $matcher,
                        'command' => $entry['command'] ?? '',
                    ];

                    // Absent stays absent so HookConfig applies the default;
                    // present-but-bad (`"60"`, `0`, `null`) is HookConfig's
                    // loud territory, untouched by this parser.
                    if (\array_key_exists('timeout', $entry)) {
                        $row['timeout'] = $entry['timeout'];
                    }

                    $document[$event]['count'] = $index + 1;
                    $document[$event]['rows'][] = $row;
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
        return HookConfig::parse(Yaml::dump($normalized, 8), $source);
    }
}
