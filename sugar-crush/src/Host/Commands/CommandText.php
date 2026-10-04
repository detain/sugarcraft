<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Host\Commands;

/**
 * How a slash command's draft splits into its name and its argument — the
 * one rule every {@see HostCommand} and `Chat`'s remaining handlers read
 * (roadmap O-2h moved it here from `Chat::commandArgument()`, which now
 * delegates).
 */
final class CommandText
{
    private function __construct()
    {
    }

    /**
     * The argument text of a dispatched command: everything after "/name" and
     * the ONE separator {@see \SugarCraft\Crush\CommandParser} accepted there,
     * trimmed; '' when the command was typed bare.
     *
     * The separator is a space OR a ':', because the parser ends the name at
     * whichever comes first and dispatch routes on that name — so
     * `/rename:Release prep` reaches the same command as `/rename Release prep`.
     * The handlers used to slice the raw draft at a fixed offset
     * (`substr($inputText, 7)` for "/rename"), which only ever skipped the
     * name: the colon spelling arrived with its ':' still on the front and
     * stored a session called ":Release prep", and `/rewind:all` read as a
     * step count (audit 15b-22). Only one separator is consumed, so a
     * space-spelled argument that itself begins with ':' keeps it.
     *
     * Not the parser's argument list: those are unquoted tokens, and these
     * commands want the raw text (a session name keeps its spacing, a
     * workflow sub-command re-splits it its own way).
     */
    public static function argument(string $text): string
    {
        if (preg_match('/^\s*\/[^\s:]*[\s:]?(.*)$/s', $text, $m) !== 1) {
            return '';
        }

        return trim($m[1]);
    }

    /**
     * {@see argument()} split on whitespace; empty for a bare command.
     *
     * @return list<string>
     */
    public static function words(string $text): array
    {
        $argument = self::argument($text);

        return $argument === '' ? [] : (preg_split('/\s+/', $argument, -1, PREG_SPLIT_NO_EMPTY) ?: []);
    }

    /**
     * A slash command split into whitespace tokens, the command word first
     * (normalised to `/name`) and {@see argument()}'s words after it — for
     * the commands that read positional words.
     *
     * Splitting the whole draft instead left the colon spelling's sub-command
     * glued to the name: `/pane:dock left` came out as `["/pane:dock", "left"]`,
     * so the verb read as `left` (audit 15b-24).
     *
     * @return list<string>
     */
    public static function tokens(string $text): array
    {
        $name = preg_match('/^\s*(\/[^\s:]*)/', $text, $m) === 1 ? $m[1] : '';

        return [$name, ...self::words($text)];
    }
}
