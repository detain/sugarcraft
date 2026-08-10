<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Commands;

/**
 * The full list of slash commands {@see \SugarCraft\Crush\Chat::submit()}
 * dispatches on, as display metadata for the "/" popup ({@see
 * \SugarCraft\Crush\Renderer::renderSlashMenu()}) and the Ctrl+P command
 * palette. Adding a command here does not wire it up - it only makes an
 * already-dispatched command discoverable/autocompletable. See
 * `Chat::submit()`'s own `str_starts_with()` chain for the actual dispatch.
 */
final class CommandRegistry
{
    /**
     * @return list<CommandSpec>
     */
    public static function all(): array
    {
        return [
            CommandSpec::new('compact', 'Manually compact chat history to save context', 'Session'),
            CommandSpec::new('workflow', 'Run, pause, resume, or inspect a workflow', 'Workflow'),
            CommandSpec::new('share', 'Share the current session', 'Session'),
            CommandSpec::new('agents', 'List active agents, or inspect one by name', 'Agents'),
            CommandSpec::new('memory', 'Add, list, search, edit, or clear memory entries', 'Memory'),
            CommandSpec::new('branch', 'Fork the current session into a new branch', 'Session'),
            CommandSpec::new('rename', 'Rename the current session', 'Session'),
            CommandSpec::new('rewind', 'Restore chat state from an earlier checkpoint', 'Session'),
            CommandSpec::new('sessions', 'List all sessions', 'Session'),
            CommandSpec::new('theme', 'Switch the color theme', 'Appearance'),
        ];
    }

    /**
     * Commands whose name starts with $prefix (case-insensitive), in {@see
     * all()}'s declared order. An empty prefix (bare "/") returns every
     * command.
     *
     * @return list<CommandSpec>
     */
    public static function filter(string $prefix): array
    {
        $needle = strtolower($prefix);

        return array_values(array_filter(
            self::all(),
            static fn(CommandSpec $spec): bool => str_starts_with(strtolower($spec->name), $needle),
        ));
    }
}
