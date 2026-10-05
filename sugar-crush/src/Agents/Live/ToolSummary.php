<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Agents\Live;

/**
 * One tool call as the short phrase a live agent line shows after the tool's
 * name — `"LoginController" routes/` for a Grep, `src/Session/Store.php` for
 * a Read (Appendix P §4.1).
 *
 * The model-written `description` argument wins whenever there is one:
 * sugar-crush asks for it on every call, and it says what the call is FOR.
 * Without it, each tool's primary argument stands in:
 *  - `Read`/`Edit`/`Write` → the path;
 *  - `Grep` → the quoted pattern, then the path;
 *  - `Glob` → the pattern;
 *  - `Bash` → the first line of the command;
 *  - `WebFetch` → host and path;
 *  - `WebSearch` → the quoted query;
 *  - `mcp__server__tool` → `server/tool`.
 * Anything else summarises to an empty string, and the line shows the bare
 * tool name.
 *
 * The result is one line, whitespace-collapsed and byte-bounded. It is still
 * agent-controlled text: the renderer sanitises it like every other
 * agent-originated string.
 */
final class ToolSummary
{
    /** Byte ceiling on a summary; a longer one is cut and ends in '…'. */
    public const MAX_BYTES = 160;

    private function __construct()
    {
    }

    /**
     * @param array<string, mixed> $args the call's arguments, as the model sent them
     */
    public static function of(string $tool, array $args): string
    {
        $description = self::line($args['description'] ?? null);
        if ($description !== '') {
            return self::clip($description);
        }

        $path = self::line($args['file_path'] ?? $args['path'] ?? null);

        $summary = match ($tool) {
            'Read', 'Edit', 'Write' => $path,
            'ApplyPatch' => self::patchPaths($args['patch'] ?? null),
            'Grep' => self::quoted(self::line($args['pattern'] ?? null)) . ($path === '' ? '' : ' ' . $path),
            'Glob' => self::line($args['pattern'] ?? null),
            'Bash' => self::line(self::firstLine($args['command'] ?? null)),
            'WebFetch' => self::hostAndPath(self::line($args['url'] ?? null)),
            'WebSearch' => self::quoted(self::line($args['query'] ?? null)),
            default => self::mcp($tool),
        };

        return self::clip(trim($summary));
    }

    /**
     * The files a patch touches, the first and a count of the rest — or ''
     * for a patch that does not parse.
     */
    private static function patchPaths(mixed $patch): string
    {
        $paths = array_values(array_unique(\SugarCraft\Crush\Tools\Edit\PatchParser::paths($patch) ?? []));
        if ($paths === []) {
            return '';
        }

        $first = self::line($paths[0]);

        return \count($paths) === 1 ? $first : $first . ' +' . (\count($paths) - 1);
    }

    private static function mcp(string $tool): string
    {
        if (!str_starts_with($tool, 'mcp__')) {
            return '';
        }

        $parts = explode('__', substr($tool, 5), 2);

        return count($parts) === 2 && $parts[0] !== '' && $parts[1] !== ''
            ? $parts[0] . '/' . $parts[1]
            : '';
    }

    private static function hostAndPath(string $url): string
    {
        if ($url === '') {
            return '';
        }

        $parts = parse_url($url);
        if (!is_array($parts) || !isset($parts['host'])) {
            return $url;
        }

        return $parts['host'] . ($parts['path'] ?? '');
    }

    private static function quoted(string $text): string
    {
        return $text === '' ? '' : '"' . $text . '"';
    }

    private static function firstLine(mixed $value): string
    {
        if (!is_string($value)) {
            return '';
        }

        foreach (preg_split('/\R/u', trim($value)) ?: [] as $line) {
            if (trim($line) !== '') {
                return $line;
            }
        }

        return '';
    }

    /**
     * $value as one line: control characters and runs of whitespace (tabs
     * and newlines included) become single spaces. Non-strings are ''.
     */
    private static function line(mixed $value): string
    {
        if (!is_string($value)) {
            return '';
        }

        $flat = preg_replace('/[\x00-\x20\x7F]+/', ' ', $value);

        return trim(is_string($flat) ? $flat : '');
    }

    private static function clip(string $text): string
    {
        if (strlen($text) <= self::MAX_BYTES) {
            return $text;
        }

        // Room for the 3-byte '…', never split inside a codepoint.
        $cut = substr($text, 0, self::MAX_BYTES - 3);
        while ($cut !== '' && preg_match('//u', $cut) !== 1) {
            $cut = substr($cut, 0, -1);
        }

        return rtrim($cut) . '…';
    }
}
