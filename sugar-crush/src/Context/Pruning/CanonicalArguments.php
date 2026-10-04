<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Context\Pruning;

use SugarCraft\Crush\Tools\ToolCall;

/**
 * The identity of a tool call for the path-keyed strategies (roadmap 2.3,
 * DCP's deduplication): two calls that would do the same thing get the same
 * {@see key()}, and a file-touching call names its file by one spelling
 * ({@see path()}).
 *
 * WHAT IS IGNORED. The `description` argument — the one-line reason the
 * built-in tools ask the model for — is free text that differs between two
 * otherwise identical reads, so it never splits a key. Key order and null
 * values never do either.
 *
 * WHAT IS NOT. A relative and an absolute spelling of one file stay two
 * paths: the projector has no root to resolve against, and guessing would
 * merge files that only share a suffix. A strategy that misses a match costs
 * a little context; one that merges two files loses the wrong output.
 */
final class CanonicalArguments
{
    /** Arguments that never take part in a call's identity. */
    public const IGNORED = ['description'];

    /** The arguments a file-touching built-in names its file with, in order. */
    public const PATH_ARGUMENTS = ['file_path', 'path'];

    /** The canonical identity of $call: its tool name and its arguments. */
    public static function key(ToolCall $call): string
    {
        $arguments = $call->arguments();
        foreach (self::IGNORED as $ignored) {
            unset($arguments[$ignored]);
        }

        return $call->name() . "\0" . (json_encode(
            self::sorted($arguments),
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE,
        ) ?: '');
    }

    /**
     * The file $call names, normalised — `./` segments and repeated or
     * trailing slashes dropped — or null when it names none.
     */
    public static function path(ToolCall $call): ?string
    {
        foreach (self::PATH_ARGUMENTS as $name) {
            $value = $call->arguments()[$name] ?? null;
            if (is_string($value) && trim($value) !== '') {
                return self::normalisedPath(trim($value));
            }
        }

        return null;
    }

    public static function normalisedPath(string $path): string
    {
        $absolute = str_starts_with($path, '/');
        $segments = [];
        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            $segments[] = $segment;
        }
        $joined = implode('/', $segments);

        return $absolute ? '/' . $joined : ($joined === '' ? '.' : $joined);
    }

    /**
     * Whether $call reads its whole file — a `Read` with no `offset` or
     * `limit` — so it supersedes every earlier read of that file.
     */
    public static function readsWholeFile(ToolCall $call): bool
    {
        $arguments = $call->arguments();

        return $call->name() === 'Read'
            && ($arguments['offset'] ?? null) === null
            && ($arguments['limit'] ?? null) === null;
    }

    private static function sorted(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        $value = array_filter($value, static fn (mixed $v): bool => $v !== null);
        if (!array_is_list($value)) {
            ksort($value, SORT_STRING);
        }

        return array_map(self::sorted(...), $value);
    }
}
