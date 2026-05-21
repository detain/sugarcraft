<?php

declare(strict_types=1);

namespace SugarCraft\Crush;

use Stringable;

/**
 * Result of parsing a slash-command input.
 *
 * @readonly
 * @immutable
 */
final class ParsedCommand
{
    /**
     * @param non-empty-string           $name  Lowercase command name without the leading /
     * @param list<non-empty-string>     $args  Positional arguments, shell-quoted and split
     */
    public function __construct(
        public readonly string $name,
        public readonly array $args = [],
    ) {}

    public function withArgs(array $args): self
    {
        return new self($this->name, $args);
    }
}

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
     * Split a raw argument string into positional arguments,
     * respecting single- and double-quote boundaries and
     * stripping quotes from the resulting tokens.
     *
     * @return list<non-empty-string>
     */
    private function splitArgs(string $raw): array
    {
        $tokens = [];
        $current = '';
        $quote = null;

        $len = strlen($raw);
        for ($i = 0; $i < $len; $i++) {
            $ch = $raw[$i];

            if ($quote !== null) {
                if ($ch === $quote) {
                    $quote = null;
                } else {
                    $current .= $ch;
                }
                continue;
            }

            if ($ch === "'" || $ch === '"') {
                $quote = $ch;
                continue;
            }

            if ($ch === ' ' || $ch === "\t") {
                if ($current !== '') {
                    $tokens[] = $current;
                    $current = '';
                }
                continue;
            }

            $current .= $ch;
        }

        if ($current !== '') {
            $tokens[] = $current;
        }

        /** @var list<non-empty-string> */
        return $tokens;
    }
}
