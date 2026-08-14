<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Hooks;

use Symfony\Component\Yaml\Yaml;

/**
 * Parses the YAML hook file into the plain config arrays
 * {@see ScriptHook::fromConfig()} consumes.
 *
 * The shape a hook author writes:
 *
 * ```yaml
 * hooks:
 *   PreToolUse:
 *     - name: confirm-deploy      # optional; defaults to `command`
 *       matcher: '^Bash$'
 *       command: ./hooks/confirm-deploy.sh
 *       description: Ask before anything touches production
 *       disabled: false           # optional; true keeps the entry out of the chain
 * ```
 *
 * Those five are the ONLY keys an entry may carry, and an unrecognised one is
 * refused rather than ignored — see the entry-key check in {@see parse()}.
 *
 * What the script's EXIT CODE means — 0 allow, 1/2 deny, 3 ask, 4 modify — is
 * documented in full on {@see ScriptHook}, which is the class that reads it.
 * Exit 3 (ask) is the one that reaches the blocking permission prompt, and is
 * the reason this file format is worth anything beyond allow/deny.
 *
 * `name` is carried through because {@see HookRegistry} keys hooks by name:
 * without it, two entries sharing a command on one event silently collapse
 * into a single registration.
 *
 * ABSENCE IS SILENT, EVERYTHING ELSE IS LOUD. This file is a SECURITY surface
 * — a hook here is what a user writes when a permission mode cannot express
 * the rule they need — so every "we could not use what you wrote" case throws
 * instead of degrading to a shorter hook chain. It used to do the opposite:
 * a YAML syntax error, an unknown event name and an uncompilable matcher all
 * came back as "no hooks", or as a hook that could never match, with nothing
 * printed anywhere. A guard silently missing from the chain is the one failure
 * mode a guard must not have, and it is invisible precisely when it matters —
 * the tool call the hook existed to stop is the one that runs. Only a file
 * that is NOT THERE is a no-op, because that is the fresh install
 * ({@see \SugarCraft\Crush\Cli\Bootstrap::hooks()} turns everything else into
 * the same exit-2 launch refusal a broken permission config already produces).
 */
final class HookConfig
{
    /**
     * Every key a single hook entry may carry. Exact, not advisory — see the
     * check in {@see parse()} for why an unrecognised one is refused.
     */
    private const ENTRY_KEYS = ['name', 'matcher', 'command', 'description', 'disabled'];

    /**
     * The candidate PCRE delimiters, tried in order, for wrapping a
     * user-written `matcher:`.
     *
     * A FIXED `/` DELIMITER MADE A LAUNCH-STOPPING ERROR OUT OF A VALID REGEX.
     * `matcher: 'Read|Write/Edit'` compiles fine as a pattern and cannot
     * compile as `/Read|Write/Edit/i`, whose delimiter closes at the slash, so
     * {@see parse()} refused it as "not a valid regular expression" and
     * `bin/sugarcrush` exited 2 — over a slash. Picking the first delimiter the pattern does not contain is
     * both simpler and safer than escaping: escaping has to reason about
     * already-escaped `\/` and gets it wrong at the edges, while a delimiter
     * that does not occur in the pattern cannot change what the pattern means.
     */
    private const DELIMITERS = ['/', '#', '~', '%', '!', '@', ';', ':', '|', '+', '='];

    /**
     * The compilable PCRE for a hook's `matcher:`, case-insensitive.
     *
     * ONE definition, used by {@see parse()}'s validation and by BOTH matchers
     * — {@see HookRegistry::matcherMatches()} and
     * {@see HookDispatcher::matcherMatches()} — because any two of them
     * disagreeing is the silent-registration bug all over again: a pattern
     * validated under one delimiter and matched under another is a hook that
     * loads and never fires. The dispatcher was that second spelling until it
     * was routed through here; see its own doc-comment for the matcher it
     * dropped on the floor.
     */
    public static function pattern(string $matcher): string
    {
        foreach (self::DELIMITERS as $delimiter) {
            if (!str_contains($matcher, $delimiter)) {
                return $delimiter . $matcher . $delimiter . 'i';
            }
        }

        // A matcher containing all eleven candidates is not a tool-name
        // pattern; escaping the first one back is a defined answer rather than
        // a crash, and {@see parse()} reports it if it still will not compile.
        return '/' . str_replace('/', '\\/', $matcher) . '/i';
    }

    /**
     * Load hooks from a YAML file, or nothing at all when there is no file.
     *
     * @return array<array{name: string, event: string, matcher: string, command: string, description: string, disabled: bool}>
     *
     * @throws \RuntimeException when the file exists and cannot be read
     * @throws \InvalidArgumentException when the file exists and cannot be used
     */
    public static function loadFromFile(string $path): array
    {
        if (!is_file($path)) {
            // Something IS at that path and it is not a readable regular file
            // — a directory, a dangling symlink. "Not a hook file" is not the
            // same as "no hook file", and only the second is a no-op.
            if (file_exists($path) || is_link($path)) {
                throw new \RuntimeException(
                    "{$path} exists but is not a readable file.",
                );
            }

            return [];
        }

        // `@`-silenced because the false branch below IS the handling, and the
        // raw warning would land in the middle of the TUI's own output — the
        // same reason {@see \SugarCraft\Crush\Cli\Bootstrap::permissionConfig()}
        // silences its read.
        $content = @file_get_contents($path);
        if ($content === false) {
            throw new \RuntimeException(
                "{$path} exists but could not be read (check its permissions).",
            );
        }

        return self::parse($content, $path);
    }

    /**
     * Parse hooks from YAML content.
     *
     * @param string $source what to call the content in an error message —
     *        a path when there is one, so the report names the file the user
     *        has to go and fix
     *
     * @return array<array{name: string, event: string, matcher: string, command: string, description: string, disabled: bool}>
     *
     * @throws \InvalidArgumentException when the content is not a usable hook file
     */
    public static function parse(string $content, string $source = 'hook config'): array
    {
        try {
            $data = Yaml::parse($content);
        } catch (\Throwable $e) {
            throw new \InvalidArgumentException(
                "{$source} is not usable YAML ({$e->getMessage()}).",
                0,
                $e,
            );
        }

        // An empty document (or one whose `hooks:` key has nothing under it)
        // configures nothing, which is a thing a user can legitimately mean —
        // unlike the malformed cases below, there is no intent being lost.
        if ($data === null || $data === '') {
            return [];
        }

        if (!is_array($data)) {
            throw new \InvalidArgumentException(
                "{$source} must be a YAML mapping with a top-level 'hooks:' key.",
            );
        }

        // `is_array()` alone answered TRUE for a top-level YAML *list*, whose
        // every entry `$data['hooks']` then threw away — nought hooks, no
        // error, the exact silent-empty this class exists to refuse. Same
        // distinction {@see \SugarCraft\Crush\Cli\Bootstrap::permissionConfig()}
        // draws with its `str_starts_with(ltrim($contents), '{')` test on the
        // JSON text, made here on the decoded shape because YAML's own parse
        // does not preserve the source form. `[]` is exempt: `hooks: {}` and an
        // empty flow mapping both decode to it, and both are the legitimate
        // "configured nothing" this method must keep tolerating.
        if ($data !== [] && array_is_list($data)) {
            throw new \InvalidArgumentException(
                "{$source} is a YAML list, not a mapping with a top-level 'hooks:' key, "
                . 'so none of its entries could be read as hooks.',
            );
        }

        // A TYPO'D TOP-LEVEL KEY IS THE SAME FAILURE WEARING A DIFFERENT HAT.
        // `$data['hooks'] ?? []` read `hook:` — or `Hooks:`, or `hooks :` — as
        // "no hooks configured", so a user who believed they had installed a
        // guard had installed nothing and was told nothing. There is exactly
        // one key this format defines, which makes the check exact rather than
        // a guess.
        $unknown = array_diff(array_keys($data), ['hooks']);
        if ($unknown !== []) {
            throw new \InvalidArgumentException(
                "{$source}: '" . implode("', '", array_map('strval', $unknown))
                . "' is not a key this file has (the only top-level key is 'hooks'); "
                . 'refusing rather than reading it as an empty hook chain.',
            );
        }

        // `??` already covers the `hooks:`-with-nothing-under-it case: YAML
        // decodes a valueless key to null and the coalesce turns that into the
        // empty list, which is the legitimate "configured nothing" above. A
        // second null test after it could never fire.
        $hooksData = $data['hooks'] ?? [];

        if (!is_array($hooksData)) {
            throw new \InvalidArgumentException(
                "{$source}: 'hooks' must be a mapping of event name to a list of hooks.",
            );
        }

        $hooks = [];

        /** @var array<string, array<string, true>> $seen */
        $seen = [];

        foreach ($hooksData as $event => $configs) {
            if (!is_string($event) || HookEvent::tryFrom($event) === null) {
                throw new \InvalidArgumentException(
                    "{$source}: '{$event}' is not a hook event. Known events: "
                    . implode(', ', array_column(HookEvent::cases(), 'value')) . '.',
                );
            }

            if (!is_array($configs)) {
                throw new \InvalidArgumentException(
                    "{$source}: 'hooks.{$event}' must be a list of hook entries.",
                );
            }

            foreach ($configs as $config) {
                if (!is_array($config)) {
                    throw new \InvalidArgumentException(
                        "{$source}: every entry under 'hooks.{$event}' must be a mapping.",
                    );
                }

                // A TYPO'D ENTRY KEY IS THE SAME FAILURE AS A TYPO'D TOP-LEVEL
                // ONE, one level down, and it was the shape that survived the
                // top-level check. `mather:` fell back to the `.*` default and
                // ran the hook on every tool call; `timeout: 5` and
                // `event: PostToolUse` were accepted and ignored; `disabled:
                // true` and `enabled: false` were accepted and the hook RAN
                // — which is the worst of them, because {@see HookRegistry}
                // has a first-class disable()/isDisabled() pair, so `disabled`
                // is the natural thing for a user to reach for and it did
                // exactly nothing. Same exact-key check as the top level: this
                // format defines five keys and no more.
                $unknownKeys = array_diff(array_keys($config), self::ENTRY_KEYS);
                if ($unknownKeys !== []) {
                    $hint = match (true) {
                        in_array('enabled', $unknownKeys, true) => " (to switch an entry off, write 'disabled: true')",
                        in_array('event', $unknownKeys, true) => " (the event is the key this entry is nested under, not a field on it)",
                        default => '',
                    };

                    throw new \InvalidArgumentException(
                        "{$source}: '" . implode("', '", array_map('strval', $unknownKeys))
                        . "' is not a key a hook entry under 'hooks.{$event}' has (valid keys are '"
                        . implode("', '", self::ENTRY_KEYS) . "'){$hint}; "
                        . 'refusing rather than registering a hook that is not the one you wrote.',
                    );
                }

                $command = $config['command'] ?? '';
                if (!is_string($command) || trim($command) === '') {
                    throw new \InvalidArgumentException(
                        "{$source}: a hook under 'hooks.{$event}' has no 'command' to run.",
                    );
                }

                $name = $config['name'] ?? $command;
                if (!is_string($name) || trim($name) === '') {
                    throw new \InvalidArgumentException(
                        "{$source}: a hook under 'hooks.{$event}' has an empty 'name'.",
                    );
                }

                $matcher = $config['matcher'] ?? '.*';
                // Rejected HERE rather than at match time, where
                // {@see HookRegistry::matcherMatches()} answers false for a
                // pattern that will not compile: that is the right thing for a
                // hand-written PHP hook (one bad matcher must not crash the
                // chain) and the wrong thing for a config file, because the
                // hook is then registered, listed, and silently never runs.
                if (!is_string($matcher) || @preg_match(self::pattern($matcher), '') === false) {
                    throw new \InvalidArgumentException(
                        "{$source}: hook '{$name}' has a 'matcher' that is not a valid regular expression, "
                        . 'so it could never match a tool name.',
                    );
                }

                $description = $config['description'] ?? '';
                if (!is_string($description)) {
                    throw new \InvalidArgumentException(
                        "{$source}: hook '{$name}' has a 'description' that is not text.",
                    );
                }

                $disabled = $config['disabled'] ?? false;
                if (!is_bool($disabled)) {
                    // Not coerced. `disabled: 'no'` is a truthy string, and a
                    // guard that silently switched itself OFF because the user
                    // wrote the word "no" is the fail-open in its purest form.
                    throw new \InvalidArgumentException(
                        "{$source}: hook '{$name}' has a 'disabled' that is not true or false.",
                    );
                }

                // {@see HookRegistry} keys by event+name, so a repeat is one
                // hook quietly overwriting another — the exact collapse `name`
                // was added to prevent, and a way for a later entry to disarm
                // an earlier one by reusing its name.
                if (isset($seen[$event][$name])) {
                    throw new \InvalidArgumentException(
                        "{$source}: two hooks on {$event} are both named '{$name}'; "
                        . 'names key the hook chain, so one would silently replace the other.',
                    );
                }

                $seen[$event][$name] = true;

                $hooks[] = [
                    'name' => $name,
                    'event' => $event,
                    'matcher' => $matcher,
                    'command' => $command,
                    'description' => $description,
                    'disabled' => $disabled,
                ];
            }
        }

        return $hooks;
    }
}
