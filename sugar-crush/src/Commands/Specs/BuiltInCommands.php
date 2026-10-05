<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Commands\Specs;

use SugarCraft\Core\I18n\T;

/**
 * The built-in commands, ONE SPEC FILE PER COMMAND in the package's
 * `builtin-commands/` directory ({@see self::specDir()}).
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
 * WHY NOT UNDER src/. A spec file returns a value and declares no type, and
 * every file under `src/` must declare the PSR-4 symbol its path names
 * (`Tests\Tools\BuiltInToolCorpusTest`). So the specs live beside `src/`,
 * not in it, and every `.php` file in their directory is a spec.
 *
 * WHAT THE SCAN READS. Only that directory, located from `__DIR__` — the
 * installation's own shipped source, never a path from config, the project or
 * `$HOME`. It is a `require` all the same, which is why
 * `Tests\Support\ReadPathCensusTest` names it as an execute path.
 *
 * CACHED PER LOCALE. A spec's description, category, palette label and
 * argument hint are `Lang::t()` lookups evaluated when the file is required
 * (audit 15b-14), so one scan answers one locale only. The generated pages
 * pin `en` around their read ({@see CommandDocGenerator}), and a scan cached
 * under the operator's locale must not answer that read.
 */
final class BuiltInCommands
{
    /** The spec-file shape; every `.php` file in {@see self::specDir()} must match it. */
    public const FILE_PATTERN = '/^(\d{4})-([a-z][a-z-]*)\.php$/';

    /** @var array<string, list<BuiltInCommand>> by locale */
    private static array $all = [];

    /** @var array<string, array<string, BuiltInCommand>> by locale, then by every dispatching spelling */
    private static array $bySpelling = [];

    private function __construct()
    {
    }

    /** The shipped spec directory: `builtin-commands/` at the package root. */
    public static function specDir(): string
    {
        return \dirname(__DIR__, 3) . '/builtin-commands';
    }

    /**
     * Every built-in, in spec-file order.
     *
     * @return list<BuiltInCommand>
     */
    public static function all(): array
    {
        $locale = T::locale();
        if (isset(self::$all[$locale])) {
            return self::$all[$locale];
        }

        $files = [];
        foreach (glob(self::specDir() . '/*.php') ?: [] as $path) {
            // A misnamed spec would otherwise be skipped without a word.
            if (preg_match(self::FILE_PATTERN, basename($path), $m) !== 1) {
                throw new \LogicException('builtin-commands/' . basename($path) . ' is not named <NNNN>-<name>.php');
            }

            $files[basename($path)] = [$path, $m[2]];
        }

        ksort($files, \SORT_STRING);

        $commands = [];
        $names = [];
        foreach ($files as $file => [$path, $name]) {
            $command = require $path;
            if (!$command instanceof BuiltInCommand) {
                throw new \LogicException("builtin-commands/{$file} must return a BuiltInCommand");
            }

            // The file name and the row name are one fact: a spec renamed in
            // one place only would sort under a name it does not answer to.
            if ($command->name() !== $name) {
                throw new \LogicException("builtin-commands/{$file} defines /{$command->name()}; name the file after the command");
            }

            foreach ([$command->name(), ...$command->aliases] as $spelling) {
                if (isset($names[$spelling])) {
                    throw new \LogicException("/{$spelling} is claimed by both {$names[$spelling]} and {$file}");
                }

                $names[$spelling] = $file;
            }

            $commands[] = $command;
        }

        return self::$all[$locale] = $commands;
    }

    /** The built-in that `/$spelling` dispatches to (a name or an alias), or null. */
    public static function forSpelling(string $spelling): ?BuiltInCommand
    {
        $locale = T::locale();
        if (!isset(self::$bySpelling[$locale])) {
            self::$bySpelling[$locale] = [];
            foreach (self::all() as $command) {
                foreach ($command->spellings() as $name) {
                    self::$bySpelling[$locale][$name] = $command;
                }
            }
        }

        return self::$bySpelling[$locale][$spelling] ?? null;
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
