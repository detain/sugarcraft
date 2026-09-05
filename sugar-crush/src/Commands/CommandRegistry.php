<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Commands;

use SugarCraft\Crush\Palette\PaletteAction;
use SugarCraft\Fuzzy\MatchResult;
use SugarCraft\Fuzzy\Matcher\SmithWatermanMatcher;

/**
 * The ONE list of commands both surfaces read: the "/" popup ({@see
 * \SugarCraft\Crush\Renderer::renderSlashMenu()}) via {@see filter()}, and
 * the Ctrl+P palette via {@see PaletteAction::all()}, whose item list is
 * derived from the rows here rather than from the enum's own cases. Before
 * this, the two surfaces kept independent lists and drifted - a command
 * added to one was silently missing from the other.
 *
 * Adding a row here still does not wire a command up - it only makes an
 * already-dispatched command discoverable/autocompletable. See
 * `Chat::submit()`'s own name-keyed dispatch for the actual behaviour, and
 * note that rows flagged `slashVisible: false` are ones that dispatch has no
 * branch for (they are reachable only through the palette).
 *
 * That gap is no longer left to a reader to notice: `Commands\SlashDispatchTest`'s
 * `testEverySlashVisibleRegistryRowHasALiveDispatchHandler()` submits `/name`
 * for every visible row through the real `Chat::update()` and fails when the
 * turn goes to the MODEL instead of to a handler, so a row added here with no
 * dispatch branch reds the suite rather than shipping a command that does
 * nothing.
 */
final class CommandRegistry
{
    /**
     * The CONTROL-PLANE command names, which a repository-supplied `*.md` file
     * may NOT take over. {@see CommandLoader::loadAll()} enforces it.
     *
     * Every other built-in is overridable on purpose — that is the feature the
     * tiering exists for, and a project that wants its own `/compact` or
     * `/review` gets it. These seven are different in kind: they are how the
     * user drives, inspects, pays for and LEAVES the application, so a clone
     * that redefined one would be answering a keystroke the user aimed at the
     * app rather than at the model. `/exit` was measured doing exactly that —
     * an `exit.md` in a checkout turned the quit key into a prompt while idle,
     * and left it quitting mid-turn (the mid-turn arm in
     * {@see \SugarCraft\Crush\Chat::submit()} bypasses expansion), i.e. an
     * override whose effect depended on whether a reply was streaming.
     *
     * WHY A LIST AND NOT A FLAG ON {@see CommandSpec}: the property would live
     * on the registry rows, and the check has to run against the FILE-BASED row
     * that is trying to replace one — at which point the built-in it shadows is
     * already gone from the merged map. The names are the thing being reserved,
     * so the names are what is written down.
     *
     * `permissions` was reserved here for two rounds while {@see all()} listed
     * NO row for it and {@see \SugarCraft\Crush\Chat::dispatchCommand()} had
     * no arm — a name held against a project's `permissions.md` on behalf of a
     * command that did not exist, so the only thing the reservation actually
     * did was refuse the override. It now has both, and this paragraph is kept
     * rather than deleted because the shape it describes is the one to watch
     * for: a reserved name is a promise, and the only way to tell whether the
     * promise was kept is `Commands\SlashDispatchTest`, which drives every
     * VISIBLE row through the real dispatch. `quit` is the remaining
     * asymmetry and is deliberate — it has no row of its own because it is an
     * alias of `exit`, but it IS dispatched, so it is a reserved name that
     * works rather than one that does not.
     */
    public const CONTROL_PLANE = ['budget', 'clear', 'exit', 'help', 'model', 'permissions', 'quit'];

    /** Whether $name is one of {@see CONTROL_PLANE}. */
    public static function isControlPlane(string $name): bool
    {
        return \in_array($name, self::CONTROL_PLANE, true);
    }

    /**
     * Every command known to either surface, in display order.
     *
     * @return list<CommandSpec>
     */
    public static function all(): array
    {
        return [
            CommandSpec::new(
                'new',
                'Start a fresh session',
                'Session',
                paletteAction: PaletteAction::NewSession,
                paletteLabel: 'New session',
                slashVisible: false,
            ),
            CommandSpec::new(
                'sessions',
                'List all sessions',
                'Session',
                paletteAction: PaletteAction::SwitchSession,
                paletteLabel: 'Switch session',
            ),
            CommandSpec::new(
                'model',
                'Switch the active model provider',
                'Model',
                paletteAction: PaletteAction::SwitchModel,
                paletteLabel: 'Switch model',
                // Optional, and the two forms do different things: bare
                // `/model` opens the same provider list Ctrl+P's Switch Model
                // opens, `/model <provider>` switches straight to that one.
                // See Chat::handleModelCommand().
                argumentHint: '[provider]',
            ),
            CommandSpec::new(
                'share',
                'Share the current session',
                'Session',
                paletteAction: PaletteAction::ShareSession,
                paletteLabel: 'Share session',
                argumentHint: '[format] [expiry]',
            ),
            CommandSpec::new(
                'docs',
                'Open the documentation',
                'App',
                paletteAction: PaletteAction::OpenDocs,
                paletteLabel: 'Open docs',
                slashVisible: false,
            ),
            CommandSpec::new(
                'exit',
                'Quit the app',
                'App',
                paletteAction: PaletteAction::Exit,
                paletteLabel: 'Exit',
                shortcut: 'Ctrl+C',
            ),
            CommandSpec::new(
                'theme',
                'Switch the color theme',
                'Appearance',
                paletteAction: PaletteAction::SwitchTheme,
                paletteLabel: 'Switch theme',
            ),
            CommandSpec::new(
                'agents',
                'List active agents, or inspect one by name',
                'Agents',
                paletteAction: PaletteAction::SwitchAgent,
                paletteLabel: 'Switch agent',
            ),
            CommandSpec::new(
                'mcp',
                'Manage MCP server auth (list/add/remove)',
                'MCP',
                paletteAction: PaletteAction::ToggleMcp,
                paletteLabel: 'Toggle MCPs',
                argumentHint: '<list|add|remove> [server]',
            ),
            // The hint rides in the description rather than in $shortcut:
            // $shortcut is only ever painted by the Ctrl+P palette
            // ({@see \SugarCraft\Crush\Renderer::renderPalette()}), and this
            // row carries no paletteAction, so a $shortcut here would be data
            // no surface shows.
            CommandSpec::new('keys', 'Show the keyboard shortcut reference (or press ?)', 'App'),
            // `/help` used to be a second spelling of `/keys` - both arms of
            // one `Chat::submit()` branch, both opening the keybinding
            // reference. It now lists the COMMANDS instead, which is what
            // `/help` means in every other CLI, and leaves the keyboard to
            // `/keys` and `?`. The row stayed rather than being deleted: it was
            // already the discoverable spelling, and the two surfaces this
            // registry feeds are exactly where a rename would have gone
            // unnoticed.
            CommandSpec::new('help', 'List every slash command', 'App'),
            // Category 'App', matching /keys and /budget: the gate is built once
            // per LAUNCH by Cli\Bootstrap::permissionGate() and carried across
            // /new, /clear and a provider switch by object identity, so filing
            // it under Session would advertise a scope it does not have.
            //
            // Read-only on purpose, and there is no `<mode>` argumentHint to
            // suggest otherwise. Changing the mode mid-session would hand a
            // model that has just been refused a way to ask the user for the
            // refusal to be lifted, in the same transcript; the mode comes from
            // the flag, the env or the settings file, and this shows you which.
            CommandSpec::new(
                'permissions',
                'Show this session\'s permission mode, its source, and the rules it decides by',
                'App',
            ),
            // Deliberately NOT near `permissions` in category, though the two read
            // alike. `/permissions` reports the gate that decides what a tool call
            // may DO, and is read-only by design - changing that mid-session would
            // hand a just-refused model a way to ask for the refusal to be lifted.
            // `/rules` changes what the prompt SAYS and is a toggle on purpose:
            // switching a rulebook off is exactly the correction a user should be
            // able to make mid-session, and it reaches no authority, only prose.
            // The hint is optional because both forms are real and do different
            // things, as with `model` above.
            CommandSpec::new(
                'rules',
                'List the rule packs, or toggle one for this session',
                'Rules',
                argumentHint: '[name]',
            ),
            CommandSpec::new('compact', 'Manually compact chat history to save context', 'Session'),
            // Deliberately NOT `/new`: this wipes the transcript and keeps the
            // session id, so the session file on disk keeps accumulating the
            // same conversation's checkpoints. `Chat::handleClearCommand()`
            // enumerates exactly what it does and does not touch.
            CommandSpec::new('clear', 'Clear the transcript, keeping this session', 'Session'),
            // Category 'App', not 'Session': the cap and the tracker behind it are
            // per-LAUNCH, carried by object identity through Chat::mutate() and
            // untouched by /new, /clear or a session switch. Filing it under
            // Session would advertise a scope it does not have.
            CommandSpec::new(
                'budget',
                'Show this session\'s reported spend, or cap it',
                'App',
                argumentHint: '[amount|off]',
            ),
            CommandSpec::new('workflow', 'Run, pause, resume, or inspect a workflow', 'Workflow'),
            CommandSpec::new('memory', 'Add, list, search, edit, or clear memory entries', 'Memory'),
            CommandSpec::new('branch', 'Fork the current session into a new branch', 'Session'),
            CommandSpec::new('rename', 'Rename the current session', 'Session', argumentHint: '<name>'),
            CommandSpec::new('rewind', 'Restore chat state from an earlier checkpoint', 'Session'),
            CommandSpec::new('bg', 'Run a task in a background session', 'Session', argumentHint: '<task>'),
            CommandSpec::new('fork', 'Clone this conversation into a background session', 'Session', argumentHint: '<prompt>'),
            CommandSpec::new(
                'websearch',
                'Search the web via SearXNG',
                'Tools',
                argumentHint: '<query> [--safesearch 0|1|2] [--time-range day|month|year]',
            ),
        ];
    }

    /**
     * The rows the "/" popup may list - everything except palette-only
     * pseudo-commands.
     *
     * @return list<CommandSpec>
     */
    public static function slashCommands(): array
    {
        return array_values(array_filter(
            self::all(),
            static fn(CommandSpec $spec): bool => $spec->slashVisible,
        ));
    }

    /**
     * The rows the Ctrl+P palette lists, in declared order.
     *
     * @return list<CommandSpec>
     */
    public static function paletteEntries(): array
    {
        return array_values(array_filter(
            self::all(),
            static fn(CommandSpec $spec): bool => $spec->paletteAction !== null,
        ));
    }

    /**
     * The row that owns $action, or null when the action has no row (a bug -
     * {@see PaletteAction::spec()} turns it into an exception).
     */
    public static function forPaletteAction(PaletteAction $action): ?CommandSpec
    {
        foreach (self::all() as $spec) {
            if ($spec->paletteAction === $action) {
                return $spec;
            }
        }

        return null;
    }

    /**
     * Slash commands matching the in-progress "/name" the user is typing,
     * fuzzy-ranked by the same matcher the Ctrl+P palette uses, so "/rwd"
     * surfaces "/rewind" instead of nothing. An empty prefix (bare "/")
     * returns every slash command in declared order.
     *
     * DERIVED from {@see filterMatchResults()} rather than filtering in
     * parallel with it, for the reason {@see \SugarCraft\Crush\Chat::paletteMatches()}
     * is derived from `paletteMatchResults()`: two filters over the same rows
     * are two rows-and-indices lists that can fall out of step, and the "/"
     * popup pairs a spec with its matched-character indices on every row it
     * paints.
     *
     * $rows OVERRIDES the registry's own list, and null is not merely a default
     * spelling of it: a caller that has file-based commands
     * ({@see CommandLoader::loadAll()}) must filter over the MERGED set, or a
     * custom command would be dispatchable by typing its full name yet invisible
     * in the popup that is supposed to teach the name. The rows are a parameter
     * rather than something this class discovers because discovery needs a
     * project root and a `$HOME` — state a static registry has no business
     * holding, and which {@see \SugarCraft\Crush\Chat} already carries.
     *
     * @param list<CommandSpec>|null $rows the rows to match against; null uses
     *        {@see slashCommands()}. Callers pass ALREADY slash-visible rows —
     *        this method does not re-filter on `slashVisible`, so a palette-only
     *        row handed in here would be listed.
     * @return list<CommandSpec>
     */
    public static function filter(string $prefix, ?array $rows = null): array
    {
        // Keyed on NAME, which is only equivalent to the row list while names
        // are unique: two rows sharing one would return the later spec twice
        // while filterMatchResults() kept both rows, and the row-for-row test
        // could not see it (both sides of that comparison are names). Pinned by
        // `CommandRegistryTest::testCommandNamesAreUniqueBecauseFilterKeysOnThem()`.
        $rows ??= self::slashCommands();

        $byName = [];
        foreach ($rows as $spec) {
            $byName[$spec->name] = $spec;
        }

        return array_values(array_map(
            static fn(MatchResult $result): CommandSpec => $byName[$result->haystack],
            self::filterMatchResults($prefix, $rows),
        ));
    }

    /**
     * {@see filter()}'s rows with their matched-character indices kept, in the
     * SAME order - the spec list is just this list's haystacks looked up by
     * name (crush_code.md Phase 4 item 5: the indices used to be discarded
     * here, so {@see \SugarCraft\Crush\Renderer::renderSlashMenu()} had
     * nothing to highlight with while the Ctrl+P palette beside it did).
     *
     * An empty prefix yields index-less results, exactly as the palette's
     * empty query does - {@see \SugarCraft\Fuzzy\Highlighter} no-ops on
     * those, so bare "/" paints unstyled names rather than fully-highlighted
     * ones.
     *
     * @param list<CommandSpec>|null $rows see {@see filter()}; the two must be
     *        given the SAME list or the row-for-row pairing the popup relies on
     *        breaks, which is why {@see filter()} forwards its own.
     * @return list<MatchResult>
     */
    public static function filterMatchResults(string $prefix, ?array $rows = null): array
    {
        $commands = $rows ?? self::slashCommands();
        if ($prefix === '') {
            return array_map(
                static fn(CommandSpec $spec): MatchResult => new MatchResult('', $spec->name, 0, []),
                $commands,
            );
        }

        $names = array_map(static fn(CommandSpec $spec): string => $spec->name, $commands);

        $length = mb_strlen($prefix);
        $matches = [];
        foreach ((new SmithWatermanMatcher())->matchAll($prefix, $names) as $result) {
            // matchAll() is a LOCAL alignment: it happily reports a partial
            // hit (query "re" scores against "agents" on the "e" alone), which
            // would leave the popup listing every command. Keep only rows
            // where every query character landed AND the first one landed on
            // the command's first character - a slash command is typed from
            // its start, so anchoring is what makes the popup narrow as the
            // user types instead of widening.
            if (count($result->matchedIndices) !== $length || ($result->matchedIndices[0] ?? -1) !== 0) {
                continue;
            }

            $matches[] = $result;
        }

        return $matches;
    }
}
