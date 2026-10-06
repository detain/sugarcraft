<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Permissions;

/**
 * Is one `awk` / `gawk` / `mawk` invocation read-only? {@see ReadOnlyCommands}'
 * check for them (user decision 2026-10-11) — READ-ONLY ONLY FOR SIMPLE
 * INLINE PROGRAMS, and strict on purpose, because awk is a programming
 * language: `print > "f"` writes, `print | "cmd"` and `system()` run
 * commands, `"cmd" | getline` and `getline < "f"` read from a command or an
 * arbitrary file, gawk's `@load` loads a shared object and `-f` / `-i` /
 * `--exec` take the program from a file this check never sees.
 *
 * So the OPTIONS are `-F SEP` and `-v NAME=VALUE` (attached or not) and `--`,
 * nothing else — `-f`, `-i`, `-E`/`--exec`, `-l`, mawk's `-W …` and gawk's
 * `-o`/`-p`/`-d` (which WRITE profile and dump files) are refused — and no
 * operand after the program may start with `-`. The PROGRAM, given inline,
 * is refused when it contains:
 *
 * - `system`, `close` or `fflush` anywhere (even inside a string: the check
 *   does not need to know where strings end to be sure of this);
 * - an `@` (gawk's `@load`, `@include`, `@namespace` and indirect calls,
 *   which can name `system`);
 * - a `|` that is not half of `||` — pipes to and from commands, `|&`
 *   coprocesses;
 * - `getline` together with any `<` (`getline < "file"`);
 * - a `>` that is not provably a comparison. awk reads a `>` inside an
 *   action as output redirection unless it is in parentheses, and telling
 *   strings and regexes from code needs a parser this is not, so a `>` is
 *   accepted only in a program with no `"`, `'`, `/`, `\` or `#` at all —
 *   where braces and parentheses can be counted exactly — and only outside
 *   every `{…}` (a pattern: `$3 > 1000 { print $1 }`) or inside `(…)`
 *   (`if ($2 > 5) print`). Anything else with a `>` is refused.
 *
 * A program word that came from a loop variable (the
 * {@see ReadOnlyCommands::UNKNOWN} sentinel) is refused. Operands are files
 * to READ (or `NAME=VALUE` assignments).
 */
final class AwkCommand
{
    private function __construct()
    {
    }

    /**
     * @param list<string> $args the quote-removed words after the command name
     */
    public static function isReadOnly(array $args): bool
    {
        $program = null;
        $count = \count($args);
        for ($i = 0; $i < $count; ++$i) {
            $arg = $args[$i];
            if ($program !== null) {
                if ($arg !== '-' && str_starts_with($arg, '-')) {
                    return false;
                }
                continue;
            }
            if ($arg === '--') {
                if (!isset($args[$i + 1])) {
                    return false;
                }
                $program = $args[++$i];
                continue;
            }
            if ($arg === '-F' || $arg === '-v') {
                $value = $args[++$i] ?? null;
                if ($value === null || ($arg === '-v' && !self::isAssignment($value))) {
                    return false;
                }
                continue;
            }
            if (str_starts_with($arg, '-F') && \strlen($arg) > 2) {
                continue;
            }
            if (str_starts_with($arg, '-v') && \strlen($arg) > 2) {
                if (!self::isAssignment(substr($arg, 2))) {
                    return false;
                }
                continue;
            }
            if ($arg !== '-' && str_starts_with($arg, '-')) {
                return false;
            }
            $program = $arg;
        }

        return $program !== null && self::programIsReadOnly($program);
    }

    /**
     * See the class docblock: true only for a program this can show neither
     * writes, runs a command, nor reads anything but its input.
     */
    public static function programIsReadOnly(string $program): bool
    {
        if (str_contains($program, ReadOnlyCommands::UNKNOWN)) {
            return false;
        }
        foreach (['system', 'close', 'fflush', '@'] as $refused) {
            if (str_contains($program, $refused)) {
                return false;
            }
        }
        if (str_contains(str_replace('||', '', $program), '|')) {
            return false;
        }
        if (str_contains($program, 'getline') && str_contains($program, '<')) {
            return false;
        }
        if (!str_contains($program, '>')) {
            return true;
        }
        if (strpbrk($program, "\"'/\\#") !== false) {
            return false;
        }

        $braces = 0;
        $parens = 0;
        $length = \strlen($program);
        for ($i = 0; $i < $length; ++$i) {
            switch ($program[$i]) {
                case '{':
                    ++$braces;
                    break;
                case '}':
                    --$braces;
                    break;
                case '(':
                    ++$parens;
                    break;
                case ')':
                    --$parens;
                    break;
                case '>':
                    if ($braces > 0 && $parens <= 0) {
                        return false;
                    }
                    break;
            }
            if ($braces < 0 || $parens < 0) {
                return false;
            }
        }

        // Unbalanced, the count above described a program awk will not run
        // as counted.
        return $braces === 0 && $parens === 0;
    }

    /** `NAME=VALUE`, NAME an identifier — what `-v` takes. */
    private static function isAssignment(string $value): bool
    {
        return preg_match('/^[A-Za-z_][A-Za-z0-9_]*=/', $value) === 1;
    }
}
