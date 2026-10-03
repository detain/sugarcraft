<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Commands\Specs;

/**
 * The built-in commands, ONE SPEC FILE PER COMMAND in this directory.
 *
 * A spec file is named `<NNNN>-<name>.php` and returns one {@see BuiltInCommand}.
 * The files are discovered by a directory scan and sorted by file name, so the
 * four-digit prefix IS the display order — the order the Ctrl+P palette, the
 * bare "/" popup, `/help` and the generated `docs/COMMANDS.md` table list them
 * in. Prefixes are spaced by 100 so a new command slots in anywhere without
 * renaming a neighbour.
 *
 * WHY DISCOVERED AND NOT LISTED. This list used to be one literal array in
 * `CommandRegistry::all()` plus a matching `match` arm per command in
 * `Chat::dispatchCommand()` plus a hand-kept README roster and COMMANDS.md table
 * — four places two independent changes both had to edit. Now adding a command
 * is adding one file (and running `php tools/gen-command-docs.php --write`), so
 * two commands added in parallel never touch the same line.
 *
 * WHAT THE SCAN READS. Only this directory, located from `__DIR__` — the
 * installation's own shipped source, never a path from config, the project or
 * `$HOME`. It is a `require` all the same, which is why
 * `Tests\Support\ReadPathCensusTest` names it as an execute path.
 */
final class BuiltInCommands
{
    /** The spec-file shape; anything else in the directory is a class, not a spec. */
    public const FILE_PATTERN = '/^(\d{4})-([a-z][a-z-]*)\.php$/';

    /** @var list<BuiltInCommand>|null */
    private static ?array $all = null;

    /** @var array<string, BuiltInCommand>|null by every dispatching spelling */
    private static ?array $bySpelling = null;

    private function __construct()
    {
    }

    /**
     * Every built-in, in spec-file order.
     *
     * @return list<BuiltInCommand>
     */
    public static function all(): array
    {
        if (self::$all !== null) {
            return self::$all;
        }

        $files = [];
        foreach (glob(__DIR__ . '/*.php') ?: [] as $path) {
            if (preg_match(self::FILE_PATTERN, basename($path), $m) === 1) {
                $files[basename($path)] = [$path, $m[2]];
            }
        }

        ksort($files, \SORT_STRING);

        $commands = [];
        $names = [];
        foreach ($files as $file => [$path, $name]) {
            $command = require $path;
            if (!$command instanceof BuiltInCommand) {
                throw new \LogicException("Commands/Specs/{$file} must return a BuiltInCommand");
            }

            // The file name and the row name are one fact: a spec renamed in
            // one place only would sort under a name it does not answer to.
            if ($command->name() !== $name) {
                throw new \LogicException("Commands/Specs/{$file} defines /{$command->name()}; name the file after the command");
            }

            foreach ([$command->name(), ...$command->aliases] as $spelling) {
                if (isset($names[$spelling])) {
                    throw new \LogicException("/{$spelling} is claimed by both {$names[$spelling]} and {$file}");
                }

                $names[$spelling] = $file;
            }

            $commands[] = $command;
        }

        return self::$all = $commands;
    }

    /** The built-in that `/$spelling` dispatches to (a name or an alias), or null. */
    public static function forSpelling(string $spelling): ?BuiltInCommand
    {
        if (self::$bySpelling === null) {
            self::$bySpelling = [];
            foreach (self::all() as $command) {
                foreach ($command->spellings() as $name) {
                    self::$bySpelling[$name] = $command;
                }
            }
        }

        return self::$bySpelling[$spelling] ?? null;
    }

    /**
     * Every alias, in spec-file order: spellings that dispatch without a row.
     *
     * @return list<string>
     */
    public static function aliases(): array
    {
        $aliases = [];
        foreach (self::all() as $command) {
            array_push($aliases, ...$command->aliases);
        }

        return $aliases;
    }
}
