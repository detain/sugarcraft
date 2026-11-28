<?php

declare(strict_types=1);

namespace SugarCraft\Cli\Internal;

use SugarCraft\Cli\CommandSpec;
use SugarCraft\Cli\Option;

/**
 * Read-only views of a {@see CommandSpec} shared by the generators (help
 * page, completion scripts, man page), so the three agree with each other
 * and with {@see SpecParser} on which options a command accepts and how its
 * synopsis reads.
 *
 * @internal Not part of the public API; may change without notice.
 */
final class SpecView
{
    /**
     * The command path from `$root` down through `$names`.
     *
     * @param list<string> $names subcommand names below the root
     * @return non-empty-list<CommandSpec>
     * @throws \InvalidArgumentException when a name is not a subcommand of its parent
     */
    public static function path(CommandSpec $root, array $names): array
    {
        $path = [$root];
        foreach ($names as $name) {
            $parent = $path[count($path) - 1];
            $sub = $parent->subcommand($name);
            if ($sub === null) {
                throw new \InvalidArgumentException(sprintf(
                    'Command "%s" has no subcommand "%s".',
                    implode(' ', array_map(static fn (CommandSpec $s): string => $s->name(), $path)),
                    $name,
                ));
            }
            $path[] = $sub;
        }
        return $path;
    }

    /**
     * Options the leaf of `$path` inherits: the persistent options of every
     * ancestor, root first. The leaf's own options are
     * {@see CommandSpec::options()}; together they are exactly what
     * {@see SpecParser} accepts at the leaf.
     *
     * @param non-empty-list<CommandSpec> $path
     * @return list<Option>
     */
    public static function inherited(array $path): array
    {
        $inherited = [];
        foreach (array_slice($path, 0, -1) as $ancestor) {
            foreach ($ancestor->options() as $option) {
                if ($option->persistent()) {
                    $inherited[] = $option;
                }
            }
        }
        return $inherited;
    }

    /**
     * Every option accepted at the leaf of `$path`: its own, then the
     * inherited ones.
     *
     * @param non-empty-list<CommandSpec> $path
     * @return list<Option>
     */
    public static function visible(array $path): array
    {
        return [...array_values($path[count($path) - 1]->options()), ...self::inherited($path)];
    }

    /** The full command name of `$path`, e.g. `candy-code run`. */
    public static function commandLine(array $path): string
    {
        return implode(' ', array_map(static fn (CommandSpec $s): string => $s->name(), $path));
    }

    /**
     * The usage synopsis after the command line: `[options]` when the leaf
     * accepts any option, each required option spelled out, then
     * `<command>` and the positional arguments the counts allow.
     *
     * @param non-empty-list<CommandSpec> $path
     */
    public static function synopsisTail(array $path, string $optionsWord, string $commandWord, string $argWord): string
    {
        $leaf = $path[count($path) - 1];
        $visible = self::visible($path);
        $parts = [];
        if ($visible !== []) {
            $parts[] = '[' . $optionsWord . ']';
        }
        foreach ($visible as $option) {
            if ($option->required()) {
                $parts[] = $option->display() . ($option->takesValue() ? ' <' . $option->valueName() . '>' : '');
            }
        }
        $max = $leaf->effectiveMaxArguments();
        if ($leaf->subcommands() !== []) {
            $parts[] = $max === 0 ? '<' . $commandWord . '>' : '[<' . $commandWord . '>]';
        }
        $min = $leaf->minArguments();
        for ($i = 0; $i < $min; $i++) {
            $parts[] = '<' . $argWord . '>';
        }
        if ($max === null || $max > $min) {
            $parts[] = $max !== null && $max - $min === 1 ? '[<' . $argWord . '>]' : '[<' . $argWord . '>...]';
        }
        return implode(' ', $parts);
    }

    /**
     * The help-row key of an option: `-m, --model <MODEL>`, with a
     * four-space stand-in for the short alias when `$alignShort` is set and
     * the option has none, and a trailing `...` on a repeatable option.
     */
    public static function optionKey(Option $option, bool $alignShort): string
    {
        $short = $option->short();
        $key = $short !== null ? '-' . $short . ', ' : ($alignShort ? '    ' : '');
        $key .= $option->display();
        if ($option->takesValue()) {
            $key .= ' <' . $option->valueName() . '>';
        }
        return $option->repeatable() ? $key . '...' : $key;
    }

    /**
     * The configured default as help text, or null when there is nothing
     * worth printing (no default, or a flag's natural `false`).
     */
    public static function defaultText(Option $option): ?string
    {
        $default = $option->default();
        if ($default === null || ($default === false && !$option->takesValue())) {
            return null;
        }
        $text = self::scalarText($default);
        return $text === '' ? '""' : $text;
    }

    private static function scalarText(mixed $value): string
    {
        return match (true) {
            is_bool($value) => $value ? 'true' : 'false',
            is_string($value) => $value,
            is_int($value), is_float($value) => (string) $value,
            $value instanceof \BackedEnum => (string) $value->value,
            $value instanceof \UnitEnum => $value->name,
            is_array($value) => implode(', ', array_map(self::scalarText(...), $value)),
            $value instanceof \Stringable => (string) $value,
            default => get_debug_type($value),
        };
    }
}
