<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Permissions;

/**
 * Is one `sed` invocation read-only? {@see ReadOnlyCommands}' check for
 * `sed` (user decision 2026-10-11): a model slices and rewrites text with
 * `sed -n '1,40p' f` and `… | sed 's/^use //'` all day, and every one of those
 * cost the user a question.
 *
 * `sed` writes and runs programs in exactly these ways, and every one is
 * refused:
 *
 * - `-i` / `--in-place` (any spelling, clustered `-ni`, or GNU's long-option
 *   prefix `--in`) edits the files it reads;
 * - `-f FILE` / `--file` takes the script from a file this check never sees;
 * - the `w FILE` / `W FILE` commands and the `s///w FILE` flag write a file;
 * - GNU's `e` command and `s///e` flag run the pattern space as a shell
 *   command.
 *
 * So the OPTIONS are an allow-list (`-n`, `-e SCRIPT`, `-E`/`-r`, `-s`, `-u`,
 * `-z` and their long names, `--posix`, `--debug`, `--sandbox`) — any other
 * option, `-l N` included, is refused rather than guessed at — and the
 * SCRIPT is PARSED, not pattern-matched, by a reader of the sed grammar that
 * accepts only commands it knows to be inert (`p`, `d`, `s///` with
 * `g`/`p`/`i`/`m`/number flags, `y///`, `a`/`i`/`c` text, `r`/`R` reads,
 * branches and labels, blocks, `q`/`Q`, `l`, `=` …) and returns false for
 * anything it cannot read. GNU sed's `-e` scripts are joined with newlines
 * before parsing, exactly as sed joins them.
 *
 * WHERE GNU AND BSD SED DISAGREE, the check refuses. The one disagreement
 * that matters for finding a delimiter is a bracket expression: BSD skips a
 * `/` inside `[…]`, GNU ends the regex there. A regex whose `[` is not
 * closed before the delimiter GNU found is refused, so both readings agree
 * on where every regex ends.
 *
 * A script word that came from a loop variable (the
 * {@see ReadOnlyCommands::UNKNOWN} sentinel) is refused: its text is not
 * known. Operands are files to READ, and may be anything.
 */
final class SedCommand
{
    /** Short options that take no value. `i`, `f`, `l`, `b` and anything unknown are refused. */
    private const FLAGS = 'nrEsuz';

    /** Long options that take no value. */
    private const LONG_FLAGS = [
        '--quiet', '--silent', '--regexp-extended', '--separate', '--null-data',
        '--zero-terminated', '--unbuffered', '--posix', '--debug', '--sandbox',
    ];

    /** Commands with no argument. */
    private const BARE_COMMANDS = '=dDgGhHnNpPxzF';

    private function __construct()
    {
    }

    /**
     * @param list<string> $args the quote-removed words after `sed`
     */
    public static function isReadOnly(array $args): bool
    {
        $scripts = [];
        $operands = [];
        $optionsEnded = false;
        $count = \count($args);
        for ($i = 0; $i < $count; ++$i) {
            $arg = $args[$i];
            if (!$optionsEnded && $arg === '--') {
                $optionsEnded = true;
                continue;
            }
            if (!$optionsEnded && str_starts_with($arg, '--')) {
                $parts = explode('=', $arg, 2);
                if (in_array($parts[0], self::LONG_FLAGS, true) && \count($parts) === 1) {
                    continue;
                }
                if ($parts[0] !== '--expression') {
                    return false;
                }
                $script = $parts[1] ?? ($args[++$i] ?? null);
                if ($script === null) {
                    return false;
                }
                $scripts[] = $script;
                continue;
            }
            if (!$optionsEnded && $arg !== '-' && str_starts_with($arg, '-')) {
                $cluster = substr($arg, 1);
                $length = \strlen($cluster);
                for ($j = 0; $j < $length; ++$j) {
                    if (str_contains(self::FLAGS, $cluster[$j])) {
                        continue;
                    }
                    if ($cluster[$j] !== 'e') {
                        return false;
                    }
                    // `-e SCRIPT`, or `-eSCRIPT`: the rest of the cluster is the script.
                    $script = $j + 1 < $length ? substr($cluster, $j + 1) : ($args[++$i] ?? null);
                    if ($script === null) {
                        return false;
                    }
                    $scripts[] = $script;
                    break;
                }
                continue;
            }
            $operands[] = $arg;
        }

        if ($scripts === []) {
            if ($operands === []) {
                return false;
            }
            $scripts[] = array_shift($operands);
        }
        $script = implode("\n", $scripts);

        return !str_contains($script, ReadOnlyCommands::UNKNOWN) && self::scriptIsReadOnly($script);
    }

    /**
     * Parse a sed script; true only when every command in it is one this
     * class knows reads and prints and nothing else.
     */
    public static function scriptIsReadOnly(string $script): bool
    {
        $n = \strlen($script);
        $i = 0;
        $depth = 0;
        while (true) {
            while ($i < $n && str_contains(" \t\n;", $script[$i])) {
                ++$i;
            }
            if ($i >= $n) {
                return $depth === 0;
            }
            if ($script[$i] === '#') {
                $eol = strpos($script, "\n", $i);
                $i = $eol === false ? $n : $eol;
                continue;
            }

            $addressed = false;
            if (!self::address($script, $i, $addressed, false)) {
                return false;
            }
            self::skipBlanks($script, $i);
            if ($addressed && ($script[$i] ?? '') === ',') {
                ++$i;
                self::skipBlanks($script, $i);
                $second = false;
                if (!self::address($script, $i, $second, true) || !$second) {
                    return false;
                }
                self::skipBlanks($script, $i);
            }
            while (($script[$i] ?? '') === '!') {
                ++$i;
                self::skipBlanks($script, $i);
            }
            if ($i >= $n) {
                return false;
            }

            $command = $script[$i++];
            if ($command === '{') {
                ++$depth;
                continue;
            }
            if ($command === '}') {
                if ($addressed || --$depth < 0) {
                    return false;
                }
            } elseif (str_contains(self::BARE_COMMANDS, $command)) {
                // nothing to read
            } elseif ($command === 'l' || $command === 'q' || $command === 'Q') {
                self::skipBlanks($script, $i);
                while ($i < $n && ctype_digit($script[$i])) {
                    ++$i;
                }
            } elseif ($command === ':' || $command === 'b' || $command === 't' || $command === 'T') {
                if ($command === ':' && $addressed) {
                    return false;
                }
                self::skipBlanks($script, $i);
                $start = $i;
                while ($i < $n && preg_match('/[A-Za-z0-9_.-]/', $script[$i]) === 1) {
                    ++$i;
                }
                if ($command === ':' && $i === $start) {
                    return false;
                }
            } elseif ($command === 'a' || $command === 'i' || $command === 'c' || $command === 'r' || $command === 'R') {
                // Text (a/i/c) or a file name to READ (r/R) — both run to the
                // end of the line, `;` included, in GNU and BSD alike; a
                // backslash-newline continues a/i/c text.
                while ($i < $n && $script[$i] !== "\n") {
                    $i += $script[$i] === '\\' ? 2 : 1;
                }
                continue;
            } elseif ($command === 's') {
                $delimiter = $script[$i++] ?? '';
                if ($delimiter === '' || $delimiter === '\\' || $delimiter === "\n") {
                    return false;
                }
                $regex = self::delimited($script, $i, $delimiter);
                if ($regex === null || !self::bracketsClose($regex) || self::delimited($script, $i, $delimiter) === null) {
                    return false;
                }
                // Flags: `w FILE` writes and `e` runs a command; anything
                // else unknown ends the flags and must then end the command.
                while ($i < $n && preg_match('/[gpiImM0-9]/', $script[$i]) === 1) {
                    ++$i;
                }
            } elseif ($command === 'y') {
                $delimiter = $script[$i++] ?? '';
                if ($delimiter === '' || $delimiter === '\\' || $delimiter === "\n"
                    || self::delimited($script, $i, $delimiter) === null
                    || self::delimited($script, $i, $delimiter) === null) {
                    return false;
                }
            } else {
                // `w`, `W`, `e`, `v`, `L` and anything unknown.
                return false;
            }

            // The command must end here: a blank run then `;`, a newline,
            // `}`, a comment or the end of the script.
            self::skipBlanks($script, $i);
            if ($i < $n && !str_contains(";\n}#", $script[$i])) {
                return false;
            }
        }
    }

    /**
     * An optional address at $i: a line number (`3`, `0`, `first~step`), `$`,
     * `/regex/` or `\cregexc` with `I`/`M` flags — or, as the second of a
     * range, `+N` / `~N`. False on a malformed one; $found says whether one
     * was there.
     */
    private static function address(string $script, int &$i, bool &$found, bool $second): bool
    {
        $char = $script[$i] ?? '';
        $found = true;
        if (ctype_digit($char) || ($second && ($char === '+' || $char === '~'))) {
            if (!ctype_digit($char)) {
                ++$i;
                if (!ctype_digit($script[$i] ?? '')) {
                    return false;
                }
            }
            while (ctype_digit($script[$i] ?? '')) {
                ++$i;
            }
            if (!$second && ($script[$i] ?? '') === '~') {
                ++$i;
                if (!ctype_digit($script[$i] ?? '')) {
                    return false;
                }
                while (ctype_digit($script[$i] ?? '')) {
                    ++$i;
                }
            }

            return true;
        }
        if ($char === '$') {
            ++$i;

            return true;
        }
        if ($char === '/' || $char === '\\') {
            $delimiter = '/';
            if ($char === '\\') {
                $delimiter = $script[$i + 1] ?? '';
                if ($delimiter === '' || $delimiter === "\n" || $delimiter === '\\') {
                    return false;
                }
                ++$i;
            }
            ++$i;
            $regex = self::delimited($script, $i, $delimiter);
            if ($regex === null || !self::bracketsClose($regex)) {
                return false;
            }
            while (($script[$i] ?? '') === 'I' || ($script[$i] ?? '') === 'M') {
                ++$i;
            }

            return true;
        }
        $found = false;

        return true;
    }

    /**
     * The text from $i up to the next unescaped $delimiter, which is
     * consumed; null at the end of the script or a raw newline. A backslash
     * escapes the byte after it, the delimiter included — GNU's own reading.
     */
    private static function delimited(string $script, int &$i, string $delimiter): ?string
    {
        $n = \strlen($script);
        $start = $i;
        while ($i < $n) {
            $char = $script[$i];
            if ($char === '\\') {
                $i += 2;
                continue;
            }
            if ($char === "\n") {
                return null;
            }
            if ($char === $delimiter) {
                return substr($script, $start, $i++ - $start);
            }
            ++$i;
        }

        return null;
    }

    /**
     * Does every `[` bracket expression in $regex close inside it? (POSIX
     * rules: a leading `^`, a leading `]`, and `[:class:]`, `[.coll.]`,
     * `[=equiv=]` spans.) A bracket that runs past the end is where BSD sed
     * would read the delimiter as part of the regex and GNU would not.
     */
    private static function bracketsClose(string $regex): bool
    {
        $n = \strlen($regex);
        for ($i = 0; $i < $n; ++$i) {
            if ($regex[$i] === '\\') {
                ++$i;
                continue;
            }
            if ($regex[$i] !== '[') {
                continue;
            }
            $j = $i + 1;
            if (($regex[$j] ?? '') === '^') {
                ++$j;
            }
            if (($regex[$j] ?? '') === ']') {
                ++$j;
            }
            while (true) {
                if ($j >= $n) {
                    return false;
                }
                if ($regex[$j] === '[' && str_contains(':.=', $regex[$j + 1] ?? "\0")) {
                    $close = strpos($regex, $regex[$j + 1] . ']', $j + 2);
                    if ($close === false) {
                        return false;
                    }
                    $j = $close + 2;
                    continue;
                }
                if ($regex[$j] === ']') {
                    break;
                }
                ++$j;
            }
            $i = $j;
        }

        return true;
    }

    private static function skipBlanks(string $script, int &$i): void
    {
        while (($script[$i] ?? '') === ' ' || ($script[$i] ?? '') === "\t") {
            ++$i;
        }
    }
}
