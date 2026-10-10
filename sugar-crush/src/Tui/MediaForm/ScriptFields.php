<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tui\MediaForm;

use SugarCraft\Forms\Field;
use SugarCraft\Forms\Field\Input;
use SugarCraft\Forms\Field\NumberField;
use SugarCraft\Forms\Field\Select;
use SugarCraft\Forms\Field\Slider;

/**
 * Descriptors → widget projection for GET /sdapi/v1/script-info arguments
 * (crush-media W3.6, plan §4.4/§5.2).
 *
 * A script's UI on the server is a machine-readable list of argument
 * descriptors — `{label, value, minimum, maximum, step, choices}` — and this
 * class is the ONLY place that decides which SugarCraft widget an argument
 * becomes. The decision reads the MACHINE KEYS, never the label text: label
 * guessing would fork behaviour on every translation, and A1111 script
 * authors are free to label a slider "upscale amount" or "缩放因子".
 *
 * The ladder (documented priority, pinned in the suite):
 *
 * 1. non-empty `choices`  → Select. `choices` wins even when numeric bounds
 *    ride along — a server offering an enum means "pick one", and a Slider
 *    could not express values outside its lattice.
 * 2. non-numeric `value` (string, bool, array, null) → Input seeded with the
 *    textualised default; bools seed `True`/`False` and map back on collect.
 * 3. full numeric lattice (`minimum`+`maximum`+`step`, step > 0) AND the
 *    default sits exactly on that lattice → Slider.
 * 4. integer `value` → NumberField walking 1-step integer lattice across the
 *    declared span, defaulting to ±2·10⁹ where a bound is absent (see
 *    {@see self::INT_SPAN} for why not PHP_INT_MAX on the negative side).
 * 5. anything else → Input.
 *
 * Law behind steps 3/5 (the NO-MUTILATION ruling): both Slider and
 * NumberField clamp AND snap to their lattice at construction — measured:
 * a 0.35 default on a 0.05..1.0/0.05 walk silently became 0.35's lattice
 * neighbour once off-grid, and a 7 on a 0..10/2 walk became 8. A widget may
 * therefore only be built when it can hold the descriptor's own default
 * byte-for-byte; an off-lattice default (or an unbounded float, which has
 * no honest lattice to invent) falls to a text Input, which never rewrites
 * what it was given. The server stays the single validator (the DTO never
 * clamps either).
 */
final class ScriptFields
{
    public const KIND_SELECT = 'select';
    public const KIND_SLIDER = 'slider';
    public const KIND_NUMBER = 'number';
    public const KIND_INPUT = 'input';

    /** Float slop for the on-lattice test (fp accumulation in (v-min)/step). */
    private const LATTICE_EPSILON = 1e-9;

    /**
     * Span half-width for an int argument with a missing bound. The
     * candy-forms integer walk misbehaves at full PHP_INT width on the
     * negative side (measured: PHP_INT_MIN+1..PHP_INT_MAX-1 seeds garbage),
     * while ±2·10⁹ walks exactly — and no A1111 script arg legitimately
     * lives beyond 32-bit range. The server still validates; this is only
     * the widget's arrow-walk ceiling.
     */
    private const INT_SPAN = 2_000_000_000;

    private function __construct()
    {
    }

    /**
     * Parse the raw decoded endpoint payload into trusted descriptors,
     * failing loud on structurally junk entries (§Fail Fast — a malformed
     * descriptor must never reach a widget as a silent guess).
     *
     * @param list<mixed> $raw the JSON array from /sdapi/v1/script-info
     * @return list<array{name: string, is_alwayson: bool, is_img2img: bool, args: list<array{label: string, value: mixed, minimum: float|null, maximum: float|null, step: float|null, choices: list<string>}>}>
     */
    public static function normalize(array $raw): array
    {
        $descriptors = [];
        foreach ($raw as $entry) {
            if (!is_array($entry) || !isset($entry['name']) || !is_string($entry['name']) || $entry['name'] === '') {
                throw new \InvalidArgumentException('script-info entry without a usable name');
            }

            $args = [];
            $position = 0;
            foreach ((array) ($entry['args'] ?? []) as $rawArg) {
                $position++;
                if (!is_array($rawArg)) {
                    throw new \InvalidArgumentException('script ' . $entry['name'] . ' carries a non-descriptor argument');
                }

                $label = isset($rawArg['label']) && is_string($rawArg['label']) && $rawArg['label'] !== ''
                    ? $rawArg['label']
                    : 'argument ' . $position;
                $choices = [];
                if (isset($rawArg['choices']) && is_array($rawArg['choices']) && $rawArg['choices'] !== []) {
                    $choices = array_values(array_map(strval(...), $rawArg['choices']));
                }

                $args[] = [
                    'label' => $label,
                    'value' => $rawArg['value'] ?? null,
                    'minimum' => self::bound($rawArg['minimum'] ?? null),
                    'maximum' => self::bound($rawArg['maximum'] ?? null),
                    'step' => self::bound($rawArg['step'] ?? null),
                    'choices' => $choices,
                ];
            }

            $descriptors[] = [
                'name' => $entry['name'],
                'is_alwayson' => (bool) ($entry['is_alwayson'] ?? false),
                'is_img2img' => (bool) ($entry['is_img2img'] ?? false),
                'args' => $args,
            ];
        }

        return $descriptors;
    }

    /**
     * Which widget kind an argument projects onto (the ladder in the class
     * docblock; `choices` beats bounds).
     *
     * @param array{label: string, value: mixed, minimum: float|null, maximum: float|null, step: float|null, choices: list<string>} $arg
     */
    public static function kind(array $arg): string
    {
        if ($arg['choices'] !== []) {
            return self::KIND_SELECT;
        }

        $value = $arg['value'];
        if (!is_int($value) && !is_float($value)) {
            return self::KIND_INPUT;
        }

        if (self::lattice($arg) !== null) {
            return self::KIND_SLIDER;
        }

        if (is_int($value)) {
            return self::KIND_NUMBER;
        }

        return self::KIND_INPUT;
    }

    /**
     * Build the widget for one argument row, seeded from $seed when a draft
     * carries a prior value for it, else from the descriptor default.
     *
     * @param array{label: string, value: mixed, minimum: float|null, maximum: float|null, step: float|null, choices: list<string>} $arg
     */
    public static function field(string $key, array $arg, string $label, mixed $seed = null): Field
    {
        $effective = $seed ?? $arg['value'];

        return match (self::kind($arg)) {
            self::KIND_SELECT => Select::new($key)
                ->withOptions(...$arg['choices'])
                ->withSelected(self::text(is_string($effective) && in_array($effective, $arg['choices'], true)
                    ? $effective
                    : ($arg['choices'][0] ?? '')))
                ->withTitle($label),
            self::KIND_SLIDER => Slider::new(
                $key,
                (float) $arg['minimum'],
                (float) $arg['maximum'],
                (float) $arg['step'],
                self::num($effective) ?? (float) $arg['value'],
            )->withTitle($label),
            self::KIND_NUMBER => NumberField::new(
                $key,
                (int) ($arg['minimum'] ?? -self::INT_SPAN),
                (int) ($arg['maximum'] ?? self::INT_SPAN),
                self::intStep($arg),
                self::int($effective) ?? (int) $arg['value'],
            )->withTitle($label),
            default => Input::new($key)->withTitle($label)->withValue(self::text($effective)),
        };
    }

    /**
     * The wire value a committed widget contributes for its argument, cast
     * back to the type the descriptor declared so a slider never posts "2"
     * where the server advertised 2, and a text escape-hatch (no-mutilation
     * fall-through) never posts a number-shaped string where a number was
     * promised — unless the edit itself left the lattice, which the server
     * validates (the client never guesses).
     *
     * @param array{label: string, value: mixed, minimum: float|null, maximum: float|null, step: float|null, choices: list<string>} $arg
     */
    public static function wireValue(Field $field, array $arg): mixed
    {
        return match (self::kind($arg)) {
            self::KIND_SELECT => (string) $field->value(),
            self::KIND_SLIDER => self::restoreNumeric((float) $field->value(), $arg['value']),
            self::KIND_NUMBER => (int) $field->value(),
            default => self::wireFromText((string) $field->value(), $arg['value']),
        };
    }

    /** Textual seed for an Input — the shape a human recognises as the default. */
    public static function text(mixed $value): string
    {
        if ($value === true) {
            return 'True';
        }

        if ($value === false) {
            return 'False';
        }

        if ($value === null) {
            return '';
        }

        if (is_array($value)) {
            return (string) json_encode($value);
        }

        if (is_string($value)) {
            return $value;
        }

        return (string) $value;
    }

    /**
     * The slider lattice an argument can honestly ride: full bounds, a
     * positive step, and the descriptor's own default sitting on that
     * lattice. Null means "no slider" (the caller falls onward/upward).
     *
     * @param array{value: mixed, minimum: float|null, maximum: float|null, step: float|null} $arg
     */
    private static function lattice(array $arg): ?array
    {
        $min = $arg['minimum'];
        $max = $arg['maximum'];
        $step = $arg['step'];
        if ($min === null || $max === null || $step === null || $step <= 0.0) {
            return null;
        }

        $value = self::num($arg['value']);
        if ($value === null || $value < $min - self::LATTICE_EPSILON || $value > $max + self::LATTICE_EPSILON) {
            return null;
        }

        $steps = ($value - $min) / $step;

        return abs($steps - round($steps)) < self::LATTICE_EPSILON ? [$min, $max, $step] : null;
    }

    /** Numeric-shaped value cast, else null (bool is deliberately NOT numeric). */
    private static function num(mixed $value): float|null
    {
        if (is_int($value) || is_float($value)) {
            return (float) $value;
        }

        return is_string($value) && is_numeric($value) ? $value + 0.0 : null;
    }

    private static function int(mixed $value): int|null
    {
        if (is_int($value)) {
            return $value;
        }

        return is_string($value) && filter_var($value, FILTER_VALIDATE_INT) !== false
            ? (int) $value
            : null;
    }

    /** Declared integer step, degraded to 1 when absent or fractional. */
    private static function intStep(array $arg): int
    {
        $step = $arg['step'];

        return is_float($step) && $step >= 1.0 && abs($step - round($step)) < self::LATTICE_EPSILON
            ? (int) round($step)
            : 1;
    }

    /** A whole float rides the wire as the int the descriptor advertised. */
    private static function restoreNumeric(float $value, mixed $declared): int|float
    {
        if (is_int($declared) && abs($value - round($value)) < self::LATTICE_EPSILON) {
            return (int) round($value);
        }

        return $value;
    }

    /** Re-type a text escape-hatch edit against the declared default type. */
    private static function wireFromText(string $text, mixed $declared): mixed
    {
        if (is_bool($declared)) {
            $parsed = filter_var($text, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);

            return $parsed ?? $text;
        }

        if (is_int($declared)) {
            $parsed = filter_var($text, FILTER_VALIDATE_INT);

            return $parsed === false ? $text : $parsed;
        }

        if (is_float($declared)) {
            return is_numeric($text) ? $text + 0.0 : $text;
        }

        if (is_array($declared)) {
            $decoded = json_decode($text, true);

            return json_last_error() === JSON_ERROR_NONE ? $decoded : $text;
        }

        return $text;
    }

    /** Bound read: numeric scalar or numeric string → float, anything else → absent. */
    private static function bound(mixed $value): float|null
    {
        if (is_int($value) || is_float($value)) {
            return (float) $value;
        }

        return is_string($value) && is_numeric($value) ? $value + 0.0 : null;
    }
}
