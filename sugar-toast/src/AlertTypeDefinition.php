<?php

declare(strict_types=1);

namespace SugarCraft\Toast;

use SugarCraft\Core\Util\Color;

/**
 * One custom alert type: the key hosts pass to alert()/progressToast(), the
 * glyph/prefix that leads the header, and an optional icon colour.
 *
 * Mirrors DaltonSW/bubbleup's `AlertDefinition{Key, ForeColor, Prefix}` as
 * consumed by `RegisterNewAlertType` — with one honest addition: a custom
 * type rides on a built-in {@see ToastType} ($base) for the things a
 * definition cannot sensibly own (the translated severity word under
 * `withAccessibleText()`, the monochrome ASCII fallback letter). Immutable
 * value object; validated at construction because the prefix is spliced into
 * the painted frame and must never carry control bytes.
 */
final class AlertTypeDefinition
{
    /** Longest prefix a header will carry before the message loses its room. */
    public const MAX_PREFIX_CELLS = 8;

    /**
     * @param string $key         Lower-case `[a-z][a-z0-9_-]*` — the string hosts pass as the type
     * @param string $prefix      Glyph or short prefix shown before the message (e.g. '🚀', '[D]')
     * @param Color|null $color   Icon colour (null = the base type's severity colour)
     * @param ToastType $base     Built-in type supplying the severity word and the ASCII fallback letter
     * @param string|null $label  Severity word for `withAccessibleText()` (null = the base type's translated label)
     * @param string|null $asciiPrefix  Prefix under SymbolSet::Ascii (null = the base type's `[E]`-style prefix)
     * @throws \InvalidArgumentException on a malformed key, or an empty / control-bearing / over-wide prefix
     */
    public function __construct(
        public readonly string $key,
        public readonly string $prefix,
        public readonly ?Color $color = null,
        public readonly ToastType $base = ToastType::Info,
        public readonly ?string $label = null,
        public readonly ?string $asciiPrefix = null,
    ) {
        if (\preg_match('/^[a-z][a-z0-9_-]*$/', $key) !== 1) {
            throw new \InvalidArgumentException(
                "AlertTypeDefinition key must match [a-z][a-z0-9_-]* (lookups are case-insensitive, stored lower-case), got '{$key}'"
            );
        }
        foreach (['prefix' => $prefix, 'label' => $label, 'asciiPrefix' => $asciiPrefix] as $field => $value) {
            if ($value === null) {
                continue;
            }
            if ($value === '' || \preg_match('/[\x00-\x1f\x7f\x{80}-\x{9f}\x{e000}-\x{f8ff}]/u', $value) === 1) {
                throw new \InvalidArgumentException(
                    "AlertTypeDefinition {$field} must be non-empty text with no control or private-use characters"
                );
            }
        }
        if (\SugarCraft\Core\Util\Width::string($prefix) > self::MAX_PREFIX_CELLS) {
            throw new \InvalidArgumentException(
                'AlertTypeDefinition prefix is wider than ' . self::MAX_PREFIX_CELLS . ' cells'
            );
        }
        if ($asciiPrefix !== null && \preg_match('/^[\x20-\x7e]+$/', $asciiPrefix) !== 1) {
            throw new \InvalidArgumentException('AlertTypeDefinition asciiPrefix must be printable ASCII');
        }
    }

    /** Factory mirroring the lib's `::new()` default root. */
    public static function new(
        string $key,
        string $prefix,
        ?Color $color = null,
        ToastType $base = ToastType::Info,
        ?string $label = null,
        ?string $asciiPrefix = null,
    ): self {
        return new self($key, $prefix, $color, $base, $label, $asciiPrefix);
    }
}
