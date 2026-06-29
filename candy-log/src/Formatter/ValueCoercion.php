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

        if (\is_int($v) || \is_float($v)) {
            return (string) $v;
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

        if (\is_callable($v)) {
            return 'closure';
        }

        // Fallback for unknown types
        return 'unknown';
    }

    /**
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
        // Objects with __toString
        if ($v instanceof \Stringable || \method_exists($v, '__toString')) {
            return (string) $v;
        }

        // Anonymous class or regular object - return class name
        $class = \get_class($v);
        if (\strpos($class, 'class@anonymous') === 0) {
            return 'object';
        }
        return $class;
    }
}
