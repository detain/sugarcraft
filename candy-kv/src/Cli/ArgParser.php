<?php

declare(strict_types=1);

namespace SugarCraft\Kv\Cli;

/**
 * Argument parser for the candy-kv CLI.
 *
 * Mirrors charmbracelet/skate argument parsing.
 *
 * @internal This class is for CLI use only and is not part of the public API.
 */
final class ArgParser
{
    /**
     * Parse arguments for the `set` command.
     *
     * The --ttl option accepts both spellings: the attached form
     * `--ttl=3600` and the separated form `--ttl 3600` (documented in
     * README as the space form). A non-numeric value is refused loudly
     * rather than silently stored as 0.
     *
     * @param list<string> $argv  Full argv array.
     * @param int         $offset Offset where set subcommand args begin.
     * @return array{key: string|null, value: string|null, ttl: int|null}
     * @throws \InvalidArgumentException when --ttl carries a non-integer value.
     */
    public static function set(array $argv, int $offset): array
    {
        $remaining = \array_slice($argv, $offset);
        $positional = [];
        $ttl = null;

        $count = \count($remaining);
        for ($i = 0; $i < $count; $i++) {
            $arg = $remaining[$i];
            if (\str_starts_with($arg, '--ttl=')) {
                $ttl = self::ttlSeconds(\substr($arg, 6), \substr($arg, 6));
            } elseif ($arg === '--ttl') {
                $next = $remaining[$i + 1] ?? null;
                if ($next === null) {
                    throw new \InvalidArgumentException('Option --ttl requires a value.');
                }
                $i++;
                $ttl = self::ttlSeconds($next, $next);
            } elseif (\str_starts_with($arg, '--')) {
                // Ignore unknown flags for set (--no-atomic is not applicable).
            } else {
                $positional[] = $arg;
            }
        }

        return [
            'key' => $positional[0] ?? null,
            'value' => $positional[1] ?? null,
            'ttl' => $ttl,
        ];
    }

    /**
     * Validate a TTL option payload as a non-negative integer of seconds.
     *
     * @throws \InvalidArgumentException naming the offending value.
     */
    private static function ttlSeconds(string $raw, string $shown): int
    {
        if (\preg_match('/^[0-9]+$/', $raw) !== 1) {
            throw new \InvalidArgumentException(
                \sprintf('Option --ttl expects a non-negative integer of seconds, got "%s".', $shown)
            );
        }

        return (int) $raw;
    }

    /**
     * Parse arguments for the `list` command.
     *
     * The -d option accepts both spellings: the attached form `-d:` (or
     * `-d|`) and the separated form `-d :`. A bare -d at the end of the
     * argument list keeps the pinned default tab delimiter.
     *
     * @param list<string> $argv  Full argv array.
     * @param int         $offset Offset where list subcommand args begin.
     * @return array{mode: 'all'|'keys'|'values', reverse: bool, delimiter: string, pattern: string|null}
     */
    public static function list(array $argv, int $offset): array
    {
        $mode = 'all';
        $reverse = false;
        $delimiter = "\t";
        $pattern = null;

        $remaining = \array_slice($argv, $offset);
        $positional = [];

        $count = \count($remaining);
        for ($i = 0; $i < $count; $i++) {
            $arg = $remaining[$i];
            if ($arg === '-k') {
                $mode = 'keys';
            } elseif ($arg === '-v') {
                $mode = 'values';
            } elseif ($arg === '-r') {
                $reverse = true;
            } elseif (\str_starts_with($arg, '-d')) {
                $attached = \substr($arg, 2);
                if ($attached !== '') {
                    $delimiter = $attached;
                } else {
                    $next = $remaining[$i + 1] ?? null;
                    $delimiter = $next ?? "\t";
                    if ($next !== null) {
                        $i++;
                    }
                }
            } elseif (\str_starts_with($arg, '-')) {
                // Ignore unknown flags.
            } else {
                $positional[] = $arg;
            }
        }

        return [
            'mode' => $mode,
            'reverse' => $reverse,
            'delimiter' => $delimiter,
            'pattern' => $positional[0] ?? null,
        ];
    }

    /**
     * Parse arguments for the `import` command.
     *
     * @param list<string> $argv  Full argv array.
     * @param int         $offset Offset where import subcommand args begin.
     * @return array{format: string|null, path: string|null, atomic: bool}
     */
    public static function import(array $argv, int $offset): array
    {
        $format = null;
        $path = null;
        $atomic = true;

        $remaining = \array_slice($argv, $offset);
        foreach ($remaining as $arg) {
            if (\str_starts_with($arg, '--')) {
                if ($arg === '--no-atomic') {
                    $atomic = false;
                }
            } elseif ($format === null) {
                $format = $arg;
            } elseif ($path === null) {
                $path = $arg;
            }
        }

        return ['format' => $format, 'path' => $path, 'atomic' => $atomic];
    }

    /**
     * Parse arguments for the `export` command.
     *
     * @param list<string> $argv  Full argv array.
     * @param int         $offset Offset where export subcommand args begin.
     * @return array{format: string|null, db: string|null, pattern: string|null}
     */
    public static function export(array $argv, int $offset): array
    {
        $format = null;
        $db = null;
        $pattern = null;

        $remaining = \array_slice($argv, $offset);
        $positional = [];

        foreach ($remaining as $arg) {
            if (\str_starts_with($arg, '-')) {
                // Ignore unknown flags.
            } else {
                $positional[] = $arg;
            }
        }

        $format = $positional[0] ?? null;
        $db = $positional[1] ?? null;
        $pattern = $positional[2] ?? null;

        return ['format' => $format, 'db' => $db, 'pattern' => $pattern];
    }
}
