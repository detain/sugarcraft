<?php

declare(strict_types=1);

namespace SugarCraft\ScriptUi\Runtime;

use SugarCraft\ScriptUi\Lang;

/**
 * The strict numeric door for every int-ish flag.
 *
 * A silent (int) cast turns a typo'd numeric into a DIFFERENT MODE:
 * (int) 'abc' === 0, and the models read 0 as "unlimited"/"auto" —
 * `--limit=two` on a single-pick script became UNLIMITED MULTI-SELECT
 * with the wrong output shape. Every numeric option must pass through
 * here so a bad value refuses loudly (localized, exit 1) instead of
 * silently relocating the program.
 *
 * Addresses candy-scriptui-039 (scriptui-29).
 */
final class PositiveInt
{
    /** Non-negative whole numbers only; no sign, no whitespace, no separators. */
    private const PATTERN = '/^[0-9]+$/';

    /**
     * Parse a numeric flag value, refusing anything that is not a
     * non-negative whole number with a localized diagnostic.
     *
     * @param string $flag flag name for the diagnostic (e.g. "limit")
     * @param mixed  $raw  raw option value
     *
     * @throws \InvalidArgumentException with the localized user message
     */
    public static function parse(string $flag, mixed $raw): int
    {
        if (is_int($raw) && $raw >= 0) {
            return $raw;
        }
        if (is_string($raw) && preg_match(self::PATTERN, $raw) === 1) {
            return (int) $raw;
        }
        throw new \InvalidArgumentException(Lang::t('runtime.bad_number', [
            'flag'  => '--' . $flag,
            'value' => self::describe($raw),
        ]));
    }

    /**
     * Door for optional numeric flags: an unset/null value means
     * "not specified" and passes through as null; anything present
     * must still be a non-negative whole number.
     */
    public static function optional(string $flag, mixed $raw): ?int
    {
        if ($raw === null || $raw === '' || $raw === false) {
            return null;
        }
        return self::parse($flag, $raw);
    }

    /** Render an arbitrary raw value for the error message. */
    private static function describe(mixed $raw): string
    {
        if (is_string($raw) || is_int($raw)) {
            return (string) $raw;
        }
        if ($raw === null) {
            return '';
        }
        return get_debug_type($raw);
    }
}
