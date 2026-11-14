<?php

declare(strict_types=1);

namespace SugarCraft\Sql;

/**
 * SQL-O23 (once-030): placeholder detection for the run-path bind prompt.
 *
 * Pure syntax scan over the raw editor text: every `?` and every `:name`
 * OUTSIDE quoted strings, quoted identifiers and comments is a binding the
 * user meant (the library's prepare() surface already exists — until this
 * row nothing on the run path used it, so users interpolated literals by
 * hand and fed the destructive-arm matcher concatenated SQL). Positional
 * placeholders yield null; named ones yield the bare name. Mixed forms
 * yield both kinds in one list — PDO rejects the mix, so the caller refuses
 * with a status instead of shipping a guaranteed prepare error.
 *
 * String-literal rules follow the MySQL shape the drivers run in: doubled
 * quotes escape inside '...' and "...", backslash escapes both, backtick
 * identifiers are opaque, `--`/`#` start line comments and `/* *\/` a block.
 * PostgreSQL's dollar-quoted bodies are not recognised (a rare embedder
 * shape) — detection skips nothing a normal statement contains.
 */
final class QueryParams
{
    private function __construct()
    {
    }

    /**
     * Ordered placeholder kinds: null = positional, string = name.
     *
     * @return list<string|null>
     */
    public static function detect(string $sql): array
    {
        $out = [];
        $len = strlen($sql);
        $i = 0;
        while ($i < $len) {
            $ch = $sql[$i];
            if ($ch === "'" || $ch === '"' || $ch === '`') {
                $i = self::skipQuoted($sql, $i, $ch);
                continue;
            }
            if ($ch === '-' && ($sql[$i + 1] ?? '') === '-') {
                $i = self::skipLine($sql, $i);
                continue;
            }
            if ($ch === '#') {
                $i = self::skipLine($sql, $i);
                continue;
            }
            if ($ch === '/' && ($sql[$i + 1] ?? '') === '*') {
                $end = strpos($sql, '*/', $i + 2);
                $i = $end === false ? $len : $end + 2;
                continue;
            }
            if ($ch === '?') {
                $out[] = null;
                $i++;
                continue;
            }
            if ($ch === ':') {
                if (($sql[$i + 1] ?? '') === ':') {
                    // PostgreSQL ::cast — not a placeholder.
                    $i += 2;
                    continue;
                }
                $first = $sql[$i + 1] ?? '';
                if (!(ctype_alpha($first) || $first === '_')) {
                    // ':30' in a time literal is not a name either.
                    $i++;
                    continue;
                }
                $name = $first;
                $j = $i + 2;
                while ($j < $len && (ctype_alpha($sql[$j]) || $sql[$j] === '_' || ctype_digit($sql[$j]))) {
                    $name .= $sql[$j];
                    $j++;
                }
                $out[] = $name;
                $i = $j;
                continue;
            }
            if ($ch === '\\') {
                // A bare backslash outside strings is noise; step over so a
                // following quote is not mistaken for an opener.
                $i += 2;
                continue;
            }
            $i++;
        }

        return $out;
    }

    /**
     * True when the list mixes positional and named placeholders.
     *
     * @param list<string|null> $detected
     */
    public static function isMixed(array $detected): bool
    {
        $hasPos = in_array(null, $detected, true);
        foreach ($detected as $d) {
            if ($d !== null) {
                return $hasPos;
            }
        }

        return false;
    }

    private static function skipQuoted(string $sql, int $i, string $quote): int
    {
        $len = strlen($sql);
        $i++;
        while ($i < $len) {
            $ch = $sql[$i];
            if ($ch === '\\' && $quote !== '`') {
                $i += 2;
                continue;
            }
            if ($ch === $quote) {
                if (($sql[$i + 1] ?? '') === $quote) {
                    $i += 2;
                    continue;
                }

                return $i + 1;
            }
            $i++;
        }

        return $len;
    }

    private static function skipLine(string $sql, int $i): int
    {
        $end = strpos($sql, "\n", $i);

        return $end === false ? strlen($sql) : $end + 1;
    }
}
