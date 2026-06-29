<?php

declare(strict_types=1);

namespace SugarCraft\Skate\Cli;

/**
 * Argument parser for the skate CLI.
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
     * @param list<string> $argv  Full argv array.
     * @param int         $offset Offset where set subcommand args begin.
     * @return array{key: string|null, value: string|null, ttl: int|null}
     */
    public static function set(array $argv, int $offset): array
    {
        $key = null;
        $value = null;
        $ttl = null;

        $remaining = \array_slice($argv, $offset);
        $positional = [];

        foreach ($remaining as $arg) {
            if (\str_starts_with($arg, '--ttl=')) {
                $ttl = (int) \substr($arg, 6);
            } elseif (\str_starts_with($arg, '--')) {
                // Ignore unknown flags for set (--no-atomic is not applicable).
            } else {
                $positional[] = $arg;
            }
        }

        $key = $positional[0] ?? null;
        $value = $positional[1] ?? null;

        return ['key' => $key, 'value' => $value, 'ttl' => $ttl];
    }

    /**
     * Parse arguments for the `list` command.
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

        foreach ($remaining as $arg) {
            if ($arg === '-k') {
                $mode = 'keys';
            } elseif ($arg === '-v') {
                $mode = 'values';
            } elseif ($arg === '-r') {
                $reverse = true;
            } elseif (\str_starts_with($arg, '-d')) {
                $delimiter = \substr($arg, 2) ?: "\t";
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
