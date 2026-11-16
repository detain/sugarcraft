<?php

declare(strict_types=1);

namespace SugarCraft\Dashboard\Foundation;

/**
 * One implementation of the accept-both rule for knobs converging from a
 * stringly vocabulary onto the enum that already models it (D-147).
 *
 * The dashboard family spells alignment, trend, position and similar closed
 * vocabularies as enums (`HAlign`, `MetricTrend`, `Level`); the stringly
 * twins that predate them used to silently fall back to a default on unknown
 * values, which lets a typo masquerade as a valid appearance. From now on a
 * seat either takes the enum (the forward API, pass-through) or the legacy
 * string case value (accepted with an `E_USER_DEPRECATED` notice while
 * callers migrate). Anything else fails loudly at the boundary instead of
 * rendering the wrong thing three frames later.
 *
 * Only string-backed enums are supported; a pure enum has no stable wire
 * string to accept, and inventing one is the caller's decision, not this
 * helper's.
 */
final class EnumCoerce
{
    private function __construct() {}

    /**
     * Coerce a legacy string or pass through the enum case for $knobName.
     *
     * @template T of \BackedEnum
     *
     * @param \BackedEnum|string $value    enum case (forward API) or legacy case value
     * @param class-string<T>    $enumClass string-backed enum the knob names
     * @param string             $knobName  human-readable knob label used in messages
     *
     * @return T the resolved case
     *
     * @throws \InvalidArgumentException on an unknown string, a foreign enum case, or a non-backed enum class
     */
    public static function from(\BackedEnum|string $value, string $enumClass, string $knobName): \BackedEnum
    {
        if ($value instanceof $enumClass) {
            return $value;
        }

        if (!is_subclass_of($enumClass, \BackedEnum::class)) {
            throw new \InvalidArgumentException(sprintf(
                'EnumCoerce::from() requires a string-backed enum for %s, got %s.',
                $knobName,
                $enumClass,
            ));
        }

        /** @var class-string<\BackedEnum&string> $enumClass */
        if (is_string($value)) {
            $case = $enumClass::tryFrom($value);
            if ($case !== null) {
                trigger_error(
                    sprintf(
                        'Passing the string "%s" as %s is deprecated; pass %s::%s instead.',
                        $value,
                        $knobName,
                        $enumClass,
                        $case->name,
                    ),
                    E_USER_DEPRECATED,
                );

                return $case;
            }
        }

        throw new \InvalidArgumentException(sprintf(
            'Unknown %s "%s"; expected a %s case or one of its string values: %s.',
            $knobName,
            $value instanceof \BackedEnum ? $value::class . '::' . $value->name : $value,
            $enumClass,
            implode(', ', array_map(
                static fn (\BackedEnum $c): string => (string) $c->value,
                $enumClass::cases(),
            )),
        ));
    }
}
