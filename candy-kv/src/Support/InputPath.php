<?php

declare(strict_types=1);

namespace SugarCraft\Kv\Support;

/**
 * The shared allowlist law for every caller-supplied read path.
 *
 * WHY THIS EXISTS (candy-kv-019). Every filesystem sink in this package —
 * `import <fmt> <path>`, both public `importFromFile()` doors, and
 * `Store::setFile()` — takes a string chosen by a caller. PHP turns such a
 * string into a CODE PATH whenever it carries a stream-wrapper prefix:
 * `phar://` runs the archive's metadata through unserialize() the instant
 * any stat/read touches the URL (file_exists() included), and `data://` or
 * `php://filter` switch the channel underneath every file law the CLI
 * documents. The argv path is the caller's own machine, but
 * `importFromFile()` and `setFile()` are PUBLIC library APIs whose paths
 * flow from config files and uploads in wrapping automation — deserializing
 * attacker archives inside the secrets-store process is not a feature any
 * door accepts. One guard, referenced by all doors, per the Ttl precedent.
 *
 * The documented plain-path contract survives untouched: `-` and
 * `/dev/stdin` keep their STDIN meaning at the CLI door, and ordinary
 * absolute or relative paths simply pass through — each door still owns
 * its own existence/readability wording.
 */
final class InputPath
{
    /**
     * Refuse a stream-wrapper URL, naming the scheme that was found.
     *
     * @return string the path unchanged, for fluent use at the door
     *
     * @throws \InvalidArgumentException when $path opens with `<scheme>://`
     */
    public static function refuseStreamWrapper(string $path): string
    {
        if (\preg_match('#\A([a-z][a-z0-9+.\-]*)://#i', $path, $m)) {
            // The scheme is bounded to wrapper-name characters by the regex,
            // so naming it here carries no terminal-escape risk.
            throw new \InvalidArgumentException(\sprintf(
                'File paths must be plain filesystem paths; the stream wrapper "%s" is not accepted — PHP deserializes archive metadata or switches channels the moment such a URL is touched. Pass a real path (or "-" for STDIN at the CLI).',
                $m[1] . '://',
            ));
        }

        return $path;
    }

    /**
     * True for the two documented STDIN spellings the CLI accepts.
     */
    public static function isStdinMarker(string $path): bool
    {
        return $path === '-' || $path === '/dev/stdin';
    }
}
