<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Commands;

use SugarCraft\Crush\Palette\PaletteAction;
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
 * `Chat::submit()`'s own `str_starts_with()` chain for the actual dispatch,
 * and note that rows flagged `slashVisible: false` are ones that chain has
 * no branch for (they are reachable only through the palette).
 */
final class CommandRegistry
{
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
                slashVisible: false,
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
            // `/help` is a SECOND row rather than an alias field because this
            // registry is what both discovery surfaces read: Chat::submit()
            // matches '/keys' and '/help' in the same arm, so a registry that
            // knew only one of them left the spelling most other CLIs use
            // working when typed in full and invisible in the "/" popup — the
            // exact drift this class exists to close, one level down.
            //
            // The behaviour is what justifies the row; README.md's key table
            // names both spellings too, but a doc file is not what makes this
            // necessary and the first version of this comment cited it as
            // though it were — while the row it cited had been assigned to
            // another lane and was not in the same commit.
            CommandSpec::new('help', 'Show the keyboard shortcut reference (same as /keys)', 'App'),
            CommandSpec::new('compact', 'Manually compact chat history to save context', 'Session'),
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
     * @return list<CommandSpec>
     */
    public static function filter(string $prefix): array
    {
        $commands = self::slashCommands();
        if ($prefix === '') {
            return $commands;
        }

        $byName = [];
        foreach ($commands as $spec) {
            $byName[$spec->name] = $spec;
        }

        $length = mb_strlen($prefix);
        $matches = [];
        foreach ((new SmithWatermanMatcher())->matchAll($prefix, array_keys($byName)) as $result) {
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

            $matches[] = $byName[$result->haystack];
        }

        return $matches;
    }
}
