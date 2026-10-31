<?php

declare(strict_types=1);

namespace SugarCraft\Pty\Windows;

use SugarCraft\Pty\PtyException;

/**
 * Builds the environment block `CreateProcessW` takes as `lpEnvironment`.
 *
 * ## The format, and the three ways to get it wrong
 *
 * The block is not a list of strings joined by newlines and it is not a map. It
 * is one UTF-16LE buffer holding `NAME=VALUE` entries, EACH terminated by its
 * own NUL code unit, with the WHOLE block closed by a second consecutive NUL —
 * so the last two bytes of a well-formed block are always `00 00` and the last
 * real entry ends one unit earlier. A missing second NUL makes the child read
 * past the end of the buffer, and the symptoms are a garbage variable or no
 * child at all, far from the mistake.
 *
 * Callers combine the result with {@see \SugarCraft\Pty\Windows\ConPtyApi::CREATE_UNICODE_ENVIRONMENT};
 * a UTF-16 block handed to a child that was not told to expect one parses as
 * ANSI and every value arrives shredded by NULs.
 *
 * ## Why it is sorted at all
 *
 * Windows hands the process's own environment back to it sorted, and a
 * replacement block handed to `CreateProcessW` is expected to be in the same
 * shape. Two consequences are concrete rather than stylistic: `cmd.exe`'s `SET`
 * prefix listing is documented to assume the ordering, and — the one that bites a
 * test suite — an insertion-ordered block makes the child's environment depend on
 * the order a PHP array happened to be built in, so the same program run twice
 * with the same inputs produces different bytes. Sorting is what makes the block
 * a function of its contents.
 *
 * ## What "case-insensitively" means here, precisely
 *
 * Windows compares environment names with `lstrcmpiW`-style semantics: case is
 * ignored, and the comparison is on UTF-16 code units. {@see sortKey()} gives
 * the same answer for the range that matters by upper-casing the NAME (never the
 * value) with Unicode case folding and comparing the wide bytes of that. Two
 * entries whose names differ only by case therefore land adjacent, in a stable
 * tie-broken order, instead of being scattered — and both are kept, because
 * Windows allows both to exist in one block even though the child will see the
 * later one win. That collision is disclosed by {@see encode()} rather than
 * hidden, since a caller who meant one variable and handed two has a bug this
 * class cannot fix on their behalf.
 *
 * ## What is refused
 *
 *  - A name containing `=` — the split point is the FIRST `=`, so an embedded one
 *    is not a name, it is a malformed entry that would reach the child as a
 *    different variable than the one named.
 *  - A name or value containing NUL — the same truncation hazard that makes
 *    {@see CommandLine::escape()} refuse it, in a format whose own delimiters are
 *    NULs.
 *  - An empty name.
 *
 * The per-drive "current directory" pseudo-variables (`=C:=C:\path`) are
 * therefore refused as well: their names begin with `=`. {@see fromProcess()}
 * drops them from a snapshot, with the reason in its comment, because the
 * alternative is a snapshot that throws on a perfectly ordinary Windows host.
 */
final class EnvBlock
{
    /**
     * Encode a variable map as a Windows environment block.
     *
     * @param array<string, string> $variables Name => value. Names are
     *                                         preserved as given; the block is
     *                                         ordered case-insensitively.
     * @return string Raw UTF-16LE bytes, each entry NUL-terminated, the block
     *                closed by a second NUL
     * @throws PtyException on an empty name, an `=` or NUL in a name, or a NUL
     *                      in a value
     */
    public static function encode(array $variables): string
    {
        $entries = [];
        $seen = [];

        foreach ($variables as $name => $value) {
            if (!\is_string($value)) {
                throw new PtyException(\sprintf(
                    'environment value for "%s" must be a string, %s given',
                    (string) $name,
                    \get_debug_type($value),
                ));
            }

            self::guardName((string) $name);
            self::guardValue((string) $name, $value);

            $upper = \mb_strtoupper((string) $name, 'UTF-8');
            $seen[$upper] = ($seen[$upper] ?? 0) + 1;

            $entries[] = ['name' => (string) $name, 'value' => $value];
        }

        // Case-folded comparison first, then the whole entry byte-wise, so a
        // case-colliding pair has a defined order instead of depending on input
        // order (which is what makes a byte-only sort unstable: `FOO=1` and
        // `foo=2` would arrive in either order and the child's winner with it).
        \usort($entries, static function (array $a, array $b): int {
            return self::sortKey($a['name']) <=> self::sortKey($b['name'])
                ?: \strcmp($a['name'] . '=' . $a['value'], $b['name'] . '=' . $b['value']);
        });

        $blocks = [];

        foreach ($entries as $entry) {
            $blocks[] = WideString::fromUtf8($entry['name'] . '=' . $entry['value']);
        }

        // Each entry carries its own terminator; the closing pair below is the
        // block's. Joining on a single NUL and appending two more would give the
        // last entry three terminators, which is how this format is usually
        // over-encoded.
        $body = $blocks === [] ? '' : \implode(WideString::NUL_UNIT, $blocks);

        return $body . WideString::NUL_UNIT . WideString::NUL_UNIT;
    }

    /**
     * Snapshot this process's environment as a name => value map.
     *
     * Split from {@see encode()} because the two halves get combined differently
     * by different callers: a child that inherits everything changes one entry,
     * a child that is walled off starts from a literal allowlist. Reading
     * `$_ENV`/`getenv()` in `encode()` would force one of those shapes on both.
     *
     * `getenv()` with no argument is the portable source — `$_ENV` is empty
     * whenever `variables_order` excludes `E`, which is the default in many CLI
     * builds, and a snapshot that quietly returns `[]` there produces a child
     * with no PATH at all.
     *
     * @return array<string, string>
     */
    public static function fromProcess(): array
    {
        $all = \getenv();

        if (!\is_array($all)) {
            return [];
        }

        $out = [];

        foreach ($all as $name => $value) {
            // A name this class would refuse (the per-drive `=C:` entries a real
            // Windows environment carries) is dropped rather than thrown over:
            // the snapshot is a starting point, and refusing to build one
            // because the host has a drive-current-dir marker would be a
            // self-inflicted outage.
            if (self::nameIsEncodable((string) $name)) {
                $out[(string) $name] = (string) $value;
            }
        }

        return $out;
    }

    /**
     * True when no name in the map collides with another under case folding.
     *
     * The check is offered rather than enforced because the block Windows
     * accepts does allow `FOO` beside `foo`; what a caller wants to know is
     * whether the child's winner is accidental.
     *
     * @param array<string, string> $variables
     */
    public static function hasCaseCollisions(array $variables): bool
    {
        $seen = [];

        foreach (\array_keys($variables) as $name) {
            $upper = \mb_strtoupper((string) $name, 'UTF-8');

            if (isset($seen[$upper])) {
                return true;
            }

            $seen[$upper] = true;
        }

        return false;
    }

    /**
     * The comparison key: upper-cased name, as wide bytes.
     *
     * UTF-8 and UTF-16 encode code points in the same order, so this could be a
     * UTF-8 comparison and get the same answer. It is written in the wide form
     * because that is the byte order the BLOCK is in: a reader checking whether an
     * entry would be found by a child that scans the buffer compares the same
     * bytes both times, and a sort key spelled in a third encoding is one more
     * thing to keep honest.
     */
    private static function sortKey(string $name): string
    {
        return WideString::fromUtf8(\mb_strtoupper($name, 'UTF-8'));
    }

    private static function guardName(string $name): void
    {
        if ($name === '') {
            throw new PtyException('an environment variable name cannot be empty');
        }

        if (\str_contains($name, "\0")) {
            throw new PtyException(\sprintf(
                'environment variable name "%s" contains a NUL byte, which is also the block\'s entry terminator',
                $name,
            ));
        }

        if (\str_contains($name, '=')) {
            throw new PtyException(\sprintf(
                'environment variable name "%s" contains "="; the first "=" is the name/value split, '
                . 'so this entry would reach the child under a different name than the one given',
                $name,
            ));
        }
    }

    private static function guardValue(string $name, string $value): void
    {
        if (\str_contains($value, "\0")) {
            throw new PtyException(\sprintf(
                'environment value for "%s" contains a NUL byte, which would end the entry where '
                . 'the child reads it and leave the remainder of the block unaccounted for',
                $name,
            ));
        }
    }

    private static function nameIsEncodable(string $name): bool
    {
        return $name !== '' && !\str_contains($name, "\0") && !\str_contains($name, '=');
    }

    private function __construct() {}
}
