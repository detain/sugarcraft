<?php

declare(strict_types=1);

namespace SugarCraft\Top\Config;

/**
 * Parses btop-format config.conf text into a {@see Config}.
 *
 * Mirrors aristocratos/btop Config::load: `#` lines are comments, each other
 * line is `name = value`; names outside the persisted roster are skipped
 * silently (forward/backward compatibility with other btop versions);
 * bools and ints take the first whitespace token, strings take a
 * `"quoted run"` or else the first token; a value that fails validation is
 * dropped with a warning and the option keeps its previous value. A later
 * duplicate key overrides an earlier one.
 *
 * Deviation: parsing is line-based. btop's stream reader lets a line with no
 * `=` (or an unterminated quote) swallow the following lines; here such a
 * line is ignored / ends at the line break.
 */
final class ConfigReader
{
    private function __construct()
    {
    }

    /** Parse $contents on top of $base (defaults when null). */
    public static function parse(string $contents, ?Config $base = null): LoadResult
    {
        $config = $base ?? Config::new();
        $warnings = [];

        $lines = preg_split('/\r\n|\n|\r/', $contents) ?: [];
        $needsRewrite = !self::isCurrentHeader($lines[0] ?? '');

        foreach ($lines as $line) {
            $line = ltrim($line);
            if ($line === '' || $line[0] === '#') {
                continue;
            }
            $eq = strpos($line, '=');
            if ($eq === false) {
                continue;
            }
            $name = trim(substr($line, 0, $eq));
            $option = Schema::option($name);
            if ($option === null || !$option->persisted) {
                continue;
            }

            try {
                $config = $config->withParsed($name, self::token(ltrim(substr($line, $eq + 1)), $option->type), true);
            } catch (InvalidOptionValue $e) {
                $warnings[] = $e->getMessage();
            }
        }

        return new LoadResult($config, $warnings, $needsRewrite || $warnings !== []);
    }

    /**
     * Whether $line is a `#?` header naming the btop version this key set
     * mirrors — btop rewrites a file whose first line lacks its own version.
     * Matching the version marker rather than the whole line means our own
     * header and a btop 1.4.7 file both count as current; btop writes
     * `v.1.4.7` (older releases `v. 1.3.0`), so a space after `v.` is allowed.
     */
    public static function isCurrentHeader(string $line): bool
    {
        return str_starts_with($line, '#?')
            && preg_match('/\bbtop v\. ?' . preg_quote(Schema::BTOP_VERSION, '/') . '(?![\d.])/', $line) === 1;
    }

    private static function token(string $rest, OptionType $type): string
    {
        if ($type === OptionType::String && str_starts_with($rest, '"')) {
            $close = strpos($rest, '"', 1);

            return $close === false ? rtrim(substr($rest, 1)) : substr($rest, 1, $close - 1);
        }

        $parts = preg_split('/\s+/', $rest, 2);

        return $parts[0] ?? '';
    }
}
