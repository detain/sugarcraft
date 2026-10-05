<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Permissions;

use SugarCraft\Crush\Tools\PathJail;

/**
 * The one place a `Bash` line's leading `cd <dir inside the project> &&` is
 * recognised, so an "always" grant can treat it as the no-op it is for
 * permission purposes (user decision 2026-10-11).
 *
 * WHY. Models prefix almost every command with `cd /the/project && …`. Read
 * literally that is a chain, and a chain can only ever be remembered as the
 * exact call — so `always` on `cd /repo && git status --short` covered that
 * one string and nothing else, and plain `git status` asked again. Changing
 * into a directory that is already inside the project grants nothing the
 * remainder does not, so {@see SessionPermissionMemo} judges the REMAINDER:
 * what `always` remembers, and what a later call is checked against, on every
 * path that consults a grant.
 *
 * WHAT IS STRIPPED — exactly one leading `cd <path> &&`, and only when ALL of:
 * - the line parses completely ({@see ShellWords}) and its first control
 *   operator is that `&&`;
 * - `<path>` is ONE plain word or one quoted string bash will not rewrite: no
 *   `$`, backtick, glob or brace character, no leading `~` (`~user` is
 *   somebody else's home) and no leading `-` (`cd -`, `cd -P`);
 * - it resolves — relative to the project root — to an EXISTING directory
 *   inside the project root (the root itself included), symlinks followed by
 *   `realpath()` ({@see PathJail::resolveDir()}): `..` that climbs out, or a
 *   link that points out, is not inside;
 * - with `CDPATH` set, a relative path must start with `./` or `../` — bash
 *   would otherwise search `CDPATH` first and could land somewhere else;
 * - the remainder is a non-empty command.
 *
 * Anything else is not stripped and stays what it was (fail closed): `cd`
 * alone, `cd -`, `pushd`, `cd x; …`, `cd x || …`, `cd $HOME && …`,
 * `cd "$(x)" && …`, `cd /etc && …`. The remainder is judged like any other
 * command — a pipe, a redirection, `;`, `||` or a further `&&` in it keeps the
 * grant exact-only — and only ONE `cd` comes off: `cd a && cd /etc && x` leaves
 * `cd /etc && x`, a chain.
 *
 * NEVER A REFUSAL'S INPUT. A configured `Deny`, Plan mode, protect-files and
 * security-finding asks still judge the whole line as written; this only
 * shapes what an `Allow` the user gave for the session can match.
 */
final class LeadingCd
{
    /**
     * One bare word of characters bash leaves alone, or one quoted string
     * with nothing inside that bash would expand (`'…'` never expands;
     * `"…"` without `$`, backtick, `\` or `!`).
     */
    private const PREFIX = '/\A[ \t]*cd[ \t]+(?<path>[A-Za-z0-9_.\/@%+,:=-]+|\'[^\'\n]+\'|"[^"$`\\\\!\n]+")[ \t]*&&(?<rest>.*)\z/s';

    /**
     * The command with its leading in-project `cd <path> &&` removed, or null
     * when there is no such prefix to remove (see the class docblock).
     */
    public static function strip(string $command, ?string $projectRoot): ?string
    {
        if ($projectRoot === null || $projectRoot === '' || str_contains($command, "\0")) {
            return null;
        }
        if (preg_match(self::PREFIX, $command, $match) !== 1) {
            return null;
        }

        $rest = trim($match['rest']);
        if ($rest === '' || strpbrk($rest[0], '&|;)') !== false) {
            return null;
        }

        $raw = $match['path'];
        $path = ($raw[0] === '\'' || $raw[0] === '"') ? substr($raw, 1, -1) : $raw;
        if ($path === '' || $path[0] === '-' || $path[0] === '~') {
            return null;
        }

        // The tokeniser must read the same prefix the pattern did: one `cd`
        // with one literal argument, ended by `&&`, in a line bash would run.
        $parsed = ShellWords::parse($command);
        if (!$parsed->complete || ($parsed->operators[0] ?? null) !== '&&'
            || ($parsed->commands[0] ?? null) !== ['cd', $path]
            || in_array(true, $parsed->expandable[0] ?? [true], true)) {
            return null;
        }
        foreach ($parsed->redirections as $redirection) {
            if ($redirection['command'] === 0) {
                return null;
            }
        }

        $cdpath = getenv('CDPATH');
        if (is_string($cdpath) && $cdpath !== '' && $path[0] !== '/'
            && !str_starts_with($path, './') && !str_starts_with($path, '../') && $path !== '.' && $path !== '..') {
            return null;
        }

        $resolved = PathJail::resolveDir($projectRoot, $path);
        if ($resolved === null || !is_dir($resolved)) {
            return null;
        }

        return $rest;
    }
}
