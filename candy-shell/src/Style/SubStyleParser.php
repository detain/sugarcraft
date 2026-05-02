<?php

declare(strict_types=1);

namespace CandyCore\Shell\Style;

use CandyCore\Sprinkles\Style;

/**
 * Parses gum-style per-element style sub-flags into a map of
 * element name → {@see Style}.
 *
 * Sub-flags are passed via the repeating `--style` option in
 * `<element>.<prop>=<value>` form:
 *
 *   --style cursor.foreground=#ff5fd2
 *   --style cursor.bold=true
 *   --style header.italic=true
 *   --style prompt.background=4
 *
 * Recognised props: `foreground`, `background`, `bold`, `italic`,
 * `underline`, `strikethrough`, `faint`, `blink`, `reverse`. Unknown
 * props raise an `InvalidArgumentException`.
 *
 * Element names are arbitrary; the consuming command picks which
 * keys to look up (e.g. ChooseCommand checks `cursor`, `header`,
 * `selected`, `unselected`).
 *
 * The element-name `*` (or no namespace) applies to a default base
 * style — useful for `--style bold=true` shorthand.
 */
final class SubStyleParser
{
    /**
     * Parse the array of `<elem>.<prop>=<value>` strings (or
     * `<prop>=<value>` for the global element) into element-keyed
     * styles.
     *
     * Multiple flags targeting the same element compose: e.g. passing
     * `cursor.foreground=#ff0000` and `cursor.bold=true` produces a
     * single Style that's both red AND bold.
     *
     * @param  list<string>          $flags  raw `--style` values
     * @return array<string,Style>           element name → resolved Style
     */
    public static function parse(array $flags): array
    {
        /** @var array<string, array<string,mixed>> $byElement */
        $byElement = [];
        foreach ($flags as $raw) {
            $raw = trim($raw);
            if ($raw === '') {
                continue;
            }
            $eq = strpos($raw, '=');
            if ($eq === false) {
                throw new \InvalidArgumentException(
                    "--style entries must be 'key=value' or 'element.prop=value'; got '{$raw}'",
                );
            }
            $key   = substr($raw, 0, $eq);
            $value = substr($raw, $eq + 1);
            $dot = strpos($key, '.');
            if ($dot === false) {
                $element = '*';
                $prop = $key;
            } else {
                $element = substr($key, 0, $dot);
                $prop    = substr($key, $dot + 1);
            }
            $byElement[$element][$prop] = $value;
        }
        $out = [];
        foreach ($byElement as $element => $props) {
            $out[$element] = StyleBuilder::fromFlags(self::normaliseFlags($props));
        }
        return $out;
    }

    /**
     * Look up the style for `$element`, falling back to the global
     * `*` style and finally an empty Style if neither was set.
     *
     * @param array<string,Style> $map
     */
    public static function get(array $map, string $element): Style
    {
        return $map[$element] ?? $map['*'] ?? Style::new();
    }

    /**
     * Convert SubStyleParser's `<prop> => <value>` map into the
     * shape {@see StyleBuilder::fromFlags()} expects (boolean attrs
     * stay as booleans; colours / sides stay as strings).
     *
     * @param  array<string,mixed>  $props
     * @return array<string,bool|string|null>
     */
    private static function normaliseFlags(array $props): array
    {
        $out = [];
        foreach ($props as $prop => $value) {
            $known = match ($prop) {
                'foreground', 'background',
                'border', 'border-foreground', 'border-background',
                'padding', 'margin', 'width', 'height', 'align' => 'string',
                'bold', 'italic', 'underline',
                'strikethrough', 'faint', 'blink', 'reverse'    => 'bool',
                default => null,
            };
            if ($known === null) {
                throw new \InvalidArgumentException("unknown style prop: '{$prop}'");
            }
            if ($known === 'bool') {
                $out[$prop] = self::truthy((string) $value);
            } else {
                $out[$prop] = (string) $value;
            }
        }
        return $out;
    }

    private static function truthy(string $v): bool
    {
        $v = strtolower(trim($v));
        return in_array($v, ['1', 'true', 'yes', 'on'], true);
    }
}
