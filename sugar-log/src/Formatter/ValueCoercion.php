<?php

declare(strict_types=1);

namespace SugarCraft\Log\Formatter;

/**
 * Safe value stringification for log formatters.
 *
 * Handles nested arrays, objects without __toString, resources,
 * closures, and other edge cases that would otherwise cause TypeErrors.
 */
final class ValueCoercion
{
    private const MAX_DEPTH = 4;

    /**
     * Convert a value to a string representation.
     *
     * @param mixed  $v        The value to stringify.
     * @param int    $depth    Current recursion depth (internal use).
     * @param string $delim    Delimiter for array elements (default: space).
     * @return string
     */
    public static function stringify(mixed $v, int $depth = 0, string $delim = ' '): string
    {
        if ($depth > self::MAX_DEPTH) {
            return '[...]';
        }

        if (\is_bool($v)) {
            return $v ? 'true' : 'false';
        }

        if ($v === null) {
            return 'null';
        }

        if (\is_int($v)) {
            return (string) $v;
        }

        if (\is_float($v)) {
            return self::floatString($v);
        }

        if (\is_string($v)) {
            return $v;
        }

        if (\is_array($v)) {
            return self::stringifyArray($v, $depth, $delim);
        }

        if (\is_object($v)) {
            return self::stringifyObject($v);
        }

        if (\is_resource($v)) {
            return 'resource';
        }

        // Fallback for unknown types (closures arrive through the object arm
        // above and report their class name, e.g. 'Closure')
        return 'unknown';
    }

    /**
     * Shortest-roundtrip float rendering for the text/logfmt arms.
     *
     * A bare (string) cast honours php.ini `precision` (14 on some distros, 16
     * or 17 on others), so the same float could log different bytes on
     * different hosts - 1.0000000000000002 collapses to "1" at precision 14.
     * json_encode instead honours serialize_precision (-1 upstream default):
     * the fewest digits that read back as the identical double, which is what
     * the JSON arm already emits and what Go's %v prints. Non-finite floats
     * keep their NAN/INF/-INF names (json_encode would refuse them).
     */
    private static function floatString(float $v): string
    {
        if (!\is_finite($v)) {
            return \is_nan($v) ? 'NAN' : ($v > 0 ? 'INF' : '-INF');
        }

        $encoded = \json_encode($v);

        return \is_string($encoded) ? $encoded : (string) $v;
    }

    /**
     * Elements are joined POSITIONALLY inside brackets — associative keys
     * are deliberately NOT rendered (m17): a nested value has no field-name
     * channel of its own here, and inventing one would double-encode what the
     * record's top-level context already carries. Keep meaningful names by
     * passing them as top-level context entries (logfmt/JSON emit those as
     * real fields); stringify(['id' => 7]) renders '[7]', not 'id=7'.
     *
     * @param array<mixed> $arr
     */
    private static function stringifyArray(array $arr, int $depth, string $delim): string
    {
        $items = [];
        foreach ($arr as $i) {
            $items[] = self::stringify($i, $depth + 1, $delim);
        }
        return '[' . \implode($delim, $items) . ']';
    }

    private static function stringifyObject(object $v): string
    {
        // Objects with __toString — the cast is attacker-controlled code, so a
        // throwing or recursion-cycling __toString() must never abort the
        // record: contain it and fall through to the name representation.
        if ($v instanceof \Stringable || \method_exists($v, '__toString')) {
            try {
                return (string) $v;
            } catch (\Throwable) {
                return self::objectName($v);
            }
        }

        return self::objectName($v);
    }

    /**
     * Name representation of an object: anonymous classes collapse to
     * 'object' (their raw name embeds a source path and a NUL byte), named
     * classes report get_class().
     *
     * Detection rides the '@anonymous' marker rather than a 'class@anonymous'
     * prefix: PHP 8 prefixes the marker with the first implemented interface
     * or extended class name (e.g. 'Stringable@anonymous ...'), and '@' is
     * not a legal character in any declared class name.
     */
    private static function objectName(object $v): string
    {
        $class = \get_class($v);
        if (\str_contains($class, '@anonymous')) {
            return 'object';
        }

        return $class;
    }

    /**
     * Coerce a value for JSON encoding in JsonFormatter.
     *
     * Preserves native JSON types (int, bool) and JSON-safe floats while
     * converting everything else to formats json_encode cannot choke on:
     *  - non-finite floats (INF/NAN make json_encode fail and would otherwise
     *    destroy the whole record) become the strings 'INF'/'-INF'/'NAN';
     *  - nested arrays recurse with {@see self::MAX_DEPTH} as a hard depth
     *    cap, so a reference cycle in the context (`$ctx['self'] = &$ctx`)
     *    terminates with '[...]' instead of overflowing the stack;
     *  - resources become null (json_encode default behavior);
     *  - objects collapse to their name, or to the result of __toString()
     *    when that cast exists AND survives — a hostile __toString() that
     *    throws is contained and the object falls back to its name.
     *
     * @param mixed $v     The value to coerce.
     * @param int   $depth Internal recursion depth (appended, additive).
     * @return mixed   Coerced value suitable for json_encode.
     */
    public static function coerce(mixed $v, int $depth = 0): mixed
    {
        if (\is_bool($v) || \is_int($v)) {
            return $v;
        }

        if (\is_float($v)) {
            if (!\is_finite($v)) {
                return \is_nan($v) ? 'NAN' : ($v > 0 ? 'INF' : '-INF');
            }

            return $v;
        }

        if (\is_string($v)) {
            return $v;
        }

        if (\is_array($v)) {
            if ($depth >= self::MAX_DEPTH) {
                return '[...]';
            }

            return \array_map(static fn($i) => self::coerce($i, $depth + 1), $v);
        }

        if ($v === null) {
            return null;
        }

        // Resources cannot be JSON-encoded; json_encode converts them to null
        if (\is_resource($v)) {
            return null;
        }

        // Objects with __toString — guarded cast (see stringifyObject)
        if ($v instanceof \Stringable || \method_exists($v, '__toString')) {
            try {
                return (string) $v;
            } catch (\Throwable) {
                return self::objectName($v);
            }
        }

        // Object without __toString — anonymous names carry a path + NUL byte,
        // so they collapse to 'object' exactly like the stringify path does.
        return self::objectName($v);
    }
}
