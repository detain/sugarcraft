<?php

declare(strict_types=1);

namespace SugarCraft\Pty\Windows;

use SugarCraft\Pty\PtyException;

/**
 * Builds and reads a Windows command line by the MSVCRT rules the C runtime
 * start-up code and {@see \SugarCraft\Pty\Windows\CommandLine::toArgv()}
 * implement — the rules `CommandLineToArgvW()` documents and `CreateProcessW`
 * hands to every non-trivial program.
 *
 * ## Why this is not `escapeshellarg()`
 *
 * POSIX quoting and Windows quoting are different languages, and getting it
 * wrong is not a broken path — it is an argument-boundary escape. The two
 * facts that make a naive port unsafe, both pinned by tests:
 *
 *  - A double quote is escaped by a BACKSLASH (`\"`), not by doubling it as
 *    cmd.exe's own parser does. `CreateProcessW` never invokes cmd.exe; it
 *    hands the string to the CRT parser, which wants the backslash.
 *  - A run of backslashes in front of a quote must be DOUBLED before that
 *    quote is escaped. `a\` + `"` is written `a\\\"`, and dropping the doubling
 *    turns the caller's data into a quote-delimiter change, i.e. a new token.
 *
 * ## Why an empty argument is `""` and not nothing
 *
 * Joining `['php', '', '-l']` with spaces yields `php  -l`, and the parser
 * collapses the run of whitespace into one separator: the empty argument
 * vanishes and `-l` becomes argument two. Windows has no zero-length argv
 * entry otherwise. Same shape, same reason, for an argument that is only
 * whitespace.
 *
 * ## Why NUL in an argument is a refusal rather than a fix
 *
 * The command line reaches `CreateProcessW` as a C string, so a NUL byte ends
 * it there. Everything after it — including a subsequent argument the caller
 * meant to be inert — silently disappears from the child's view, which is an
 * argument-injection primitive that no quoting can express. It is refused at
 * the boundary that can still name it.
 *
 * Mirrors charmbracelet/x/exec's Windows `escapeArgs` step, and the
 * `CommandLineToArgvW` contract at
 * https://learn.microsoft.com/en-us/cpp/c-language/parsing-c-command-line-arguments
 */
final class CommandLine
{
    /**
     * Characters that force an argument to be quoted.
     *
     * Whitespace splits tokens, and a quote changes delimiter state — those are
     * the only characters the CRT parser gives a special meaning to outside a
     * quoted run. A tab is included because the parser treats it exactly as a
     * space, whatever `explode(' ')` would have assumed.
     *
     * @var list<string>
     */
    private const NEEDS_QUOTES = [' ', "\t", "\n", "\v", "\r", '"'];

    /**
     * Render an argument vector as one command line.
     *
     * @param list<string> $argv Arguments in order; element zero is
     *                           conventionally the program name, because
     *                           `CreateProcessW`'s `lpCommandLine` starts there
     * @return string The command line, arguments separated by a single space
     * @throws PtyException on a NUL byte in any argument
     */
    public static function fromArgv(array $argv): string
    {
        $parts = [];

        foreach ($argv as $argument) {
            if (!\is_string($argument)) {
                throw new PtyException(\sprintf(
                    'a command-line argument must be a string, %s given',
                    \get_debug_type($argument),
                ));
            }

            $parts[] = self::escape($argument);
        }

        return \implode(' ', $parts);
    }

    /**
     * Quote one argument.
     *
     * @throws PtyException on a NUL byte
     */
    public static function escape(string $argument): string
    {
        if (\str_contains($argument, "\0")) {
            throw new PtyException(
                'a command-line argument cannot contain a NUL byte: CreateProcessW ends the '
                . 'command line there, so everything after it would silently vanish',
            );
        }

        // An argument with nothing special in it is passed through untouched,
        // including any backslashes: `"a\b"` would parse back to `a\b` only by
        // luck, and quoting where it is not needed invites the trailing-
        // backslash doubling to matter when it need not.
        if ($argument !== '' && !self::needsQuoting($argument)) {
            return $argument;
        }

        $out = '';
        $backslashes = 0;
        $length = \strlen($argument);

        for ($i = 0; $i < $length; $i++) {
            $char = $argument[$i];

            if ($char === '\\') {
                $backslashes++;

                continue;
            }

            if ($char === '"') {
                // The run is doubled so that HALF of it survives as literal
                // backslashes on the way back, and the quote itself is escaped.
                $out .= \str_repeat('\\', $backslashes * 2) . '\\"';
                $backslashes = 0;

                continue;
            }

            $out .= \str_repeat('\\', $backslashes) . $char;
            $backslashes = 0;
        }

        // A run left over at the end sits directly against the closing quote.
        // Undoubled it would escape that quote and merge the argument with the
        // next token.
        $out .= \str_repeat('\\', $backslashes * 2);

        return '"' . $out . '"';
    }

    /**
     * Read a command line back into an argument vector, by the
     * `CommandLineToArgvW` rules.
     *
     * This is not a convenience. It is the only way the quoting rules above can
     * be tested for anything but their own echo: `escape()` is written forward,
     * this is written from the parser's side, and a corpus round-tripping
     * through both is evidence the two agree with the documented contract
     * rather than with each other.
     *
     * @return list<string>
     * @throws PtyException on a NUL byte, for the same reason as {@see escape()}
     */
    public static function toArgv(string $commandLine): array
    {
        if (\str_contains($commandLine, "\0")) {
            throw new PtyException(
                'a command line cannot contain a NUL byte; a real one is truncated at that point '
                . 'by CreateProcessW and would have reached here as a shorter string',
            );
        }

        $args = [];
        $current = '';
        $started = false;
        $inQuotes = false;
        $length = \strlen($commandLine);
        $i = 0;

        while ($i < $length) {
            $char = $commandLine[$i];

            if (!$inQuotes && ($char === ' ' || $char === "\t" || $char === "\n" || $char === "\v" || $char === "\r")) {
                if ($started) {
                    $args[] = $current;
                    $current = '';
                    $started = false;
                }

                $i++;

                continue;
            }

            $started = true;

            if ($char === '\\') {
                $run = 0;

                while ($i + $run < $length && $commandLine[$i + $run] === '\\') {
                    $run++;
                }

                $followsQuote = $i + $run < $length && $commandLine[$i + $run] === '"';

                if (!$followsQuote) {
                    // Backslashes that do not precede a quote are literal, ALL
                    // of them — including a run that reaches the end of the
                    // line, which is why `a\` round-trips undoubled.
                    $current .= \str_repeat('\\', $run);
                    $i += $run;

                    continue;
                }

                $current .= \str_repeat('\\', \intdiv($run, 2));
                $i += $run;

                if ($run % 2 === 1) {
                    // An odd run leaves one backslash to escape the quote, so
                    // the quote is data and the delimiter state does not change.
                    $current .= '"';
                } else {
                    $inQuotes = !$inQuotes;
                }

                $i++; // Consume the quote either way.

                continue;
            }

            if ($char === '"') {
                $inQuotes = !$inQuotes;
                $i++;

                continue;
            }

            $current .= $char;
            $i++;
        }

        if ($started || $inQuotes) {
            $args[] = $current;
        }

        return $args;
    }

    private static function needsQuoting(string $argument): bool
    {
        foreach (self::NEEDS_QUOTES as $char) {
            if (\str_contains($argument, $char)) {
                return true;
            }
        }

        return false;
    }

    private function __construct() {}
}
