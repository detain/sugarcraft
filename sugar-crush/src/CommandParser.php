<?php

declare(strict_types=1);

namespace SugarCraft\Crush;

use Stringable;

/**
 * Parses user input for slash-commands.
 *
 * Detects inputs beginning with `/`, extracts the command name,
 * and splits remaining text into positional arguments respecting
 * shell-style quoting.
 *
 * Mirrors charmbracelet/crush input parsing.
 */
final class CommandParser
{
    private const SLASH = '/';

    /**
     * Parse a raw input string.
     *
     * Returns a ParsedCommand when the input is a slash-command,
     * or null when the input is ordinary text (no leading /).
     */
    public function parse(string $input): ?ParsedCommand
    {
        if ($input === '') {
            return null;
        }

        $trimmed = $input;
        while ($trimmed !== '' && $trimmed[0] === ' ') {
            $trimmed = substr($trimmed, 1);
        }

        if ($trimmed === '' || $trimmed[0] !== self::SLASH) {
            return null;
        }

        // Strip the leading slash
        $rest = substr($trimmed, 1);
        if ($rest === '') {
            return null;
        }

        // Command name is up to first whitespace or ':'
        $nameEnd = null;
        $nameRaw = $rest;
        $argsRaw = '';

        $colonPos = strpos($rest, ':');
        $spacePos = strpos($rest, ' ');

        if ($colonPos !== false && ($spacePos === false || $colonPos < $spacePos)) {
            $nameEnd = $colonPos;
            $argsRaw = trim(substr($rest, $colonPos + 1));
        } elseif ($spacePos !== false) {
            $nameEnd = $spacePos;
            $argsRaw = trim(substr($rest, $spacePos + 1));
        }

        if ($nameEnd !== null) {
            $nameRaw = substr($rest, 0, $nameEnd);
        }

        $name = $this->normalizeName($nameRaw);

        if ($name === '') {
            return null;
        }

        $args = $argsRaw !== '' ? $this->splitArgs($argsRaw) : [];

        return new ParsedCommand($name, $args);
    }

    /**
     * Normalize command name to lowercase alphanumeric + hyphens.
     */
    private function normalizeName(string $raw): string
    {
        $filtered = preg_replace('/[^a-zA-Z0-9\-_]/', '', $raw);
        if ($filtered === null || $filtered === '') {
            return '';
        }
        return strtolower($filtered);
    }

    /**
     * Split a raw argument string into positional arguments, honouring
     * single- and double-quote spans and stripping their quotes.
     *
     * A QUOTE OPENS A SPAN ONLY AT THE START OF A TOKEN. Mid-word it is prose:
     * `don't` and `it's` are words, and treating their apostrophe as an opening
     * quote swallowed the rest of the line into one token with the apostrophe
     * deleted (audit 15b-23). After a span closes, text up to the next
     * whitespace joins the same token (`"foo bar"baz` is `foo barbaz`), as in
     * `sh`; a quote reached there is literal too.
     *
     * AN UNTERMINATED QUOTE IS KEPT LITERALLY: with no closing partner it is
     * not a quote at all, so the character stays in the token and the rest of
     * the line splits on whitespace as usual (`'abc def` is `'abc`, `def`).
     * Running it silently to the end of the line guessed at a boundary the
     * user never typed.
     *
     * AN EMPTY SPAN IS AN ARGUMENT: `"" second` is two arguments, the first
     * empty. Dropping it renumbers every later one, so `$1` in a custom
     * command template received what the user typed as `$2`.
     *
     * @return list<string>
     */
    private function splitArgs(string $raw): array
    {
        $tokens = [];
        $len = strlen($raw);
        $i = 0;

        while ($i < $len) {
            $ch = $raw[$i];

            if ($ch === ' ' || $ch === "\t") {
                $i++;
                continue;
            }

            $current = '';

            if ($ch === "'" || $ch === '"') {
                $close = strpos($raw, $ch, $i + 1);
                if ($close !== false) {
                    $current = substr($raw, $i + 1, $close - $i - 1);
                    $i = $close + 1;
                } else {
                    // Unterminated: keep the quote character as text.
                    $current = $ch;
                    $i++;
                }
            }

            while ($i < $len && $raw[$i] !== ' ' && $raw[$i] !== "\t") {
                $current .= $raw[$i];
                $i++;
            }

            $tokens[] = $current;
        }

        return $tokens;
    }
}
