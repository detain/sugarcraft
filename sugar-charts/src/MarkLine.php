<?php

declare(strict_types=1);

namespace SugarCraft\Charts;

use SugarCraft\Charts\Support\Finite;
use SugarCraft\Charts\Support\UntrustedText;

/**
 * A horizontal reference-line annotation value object. Mirrors ntcharts'
 * `MarkLine` concept — a solid, dashed, or dotted line at a fixed Y value
 * (Min / Max / Average of the dataset) across the full plot width.
 *
 * MarkLine is an immutable value object consumed by LineChart: register
 * marks via `LineChart::withMarkLines()` (alias `markLines()`) and the
 * render pass paints each in-range mark as a `─`/`╌`/`┄` glyph run at
 * its mapped row; a non-empty label overwrites the right edge of that
 * row (mb-safe). Build marks with `MarkLine::at()` for an explicit value,
 * or `MarkLine::fromDataset()` — short-forms `min()`, `max()`,
 * `average()` — to derive one from data.
 *
 * Value and style are validated at construction, not at render time: a
 * non-finite value defeats the range maths it would be mapped through,
 * and a misspelt style would otherwise surface as a throw buried inside
 * somebody else's `view()` call.
 */
final class MarkLine
{
    /** Built-in line types. */
    public const STYLE_SOLID  = 'solid';
    public const STYLE_DASHED  = 'dashed';
    public const STYLE_DOTTED  = 'dotted';

    /** Standard reference-line identifiers used by {@see fromDataset()}. */
    public const MIN     = 'min';
    public const MAX     = 'max';
    public const AVERAGE = 'average';

    /** @var list<string> every accepted style spelling. */
    private const STYLES = [self::STYLE_SOLID, self::STYLE_DASHED, self::STYLE_DOTTED];

    private function __construct(
        public readonly float $value,
        public readonly string $label,
        public readonly string $style,
    ) {}

    /**
     * Create a mark line at an explicit value.
     *
     * @throws \InvalidArgumentException when $value is NaN/±INF or $style
     *                                  is not one of the STYLE_* constants
     */
    public static function at(float $value, string $label = '', string $style = self::STYLE_DASHED): self
    {
        return new self(Finite::assert($value), UntrustedText::guard($label), self::validatedStyle($style));
    }

    /**
     * Derive a MarkLine from a dataset's Min, Max, or Average.
     *
     * @param list<int|float> $data
     * @throws \InvalidArgumentException  if $type is not MIN/MAX/AVERAGE, data is empty,
     *                                    the derived value is non-finite, or $style is
     *                                    not one of the STYLE_* constants
     */
    public static function fromDataset(array $data, string $type, string $style = self::STYLE_DASHED): self
    {
        if ($data === []) {
            throw new \InvalidArgumentException('Cannot derive MarkLine from empty dataset');
        }
        if (!in_array($type, [self::MIN, self::MAX, self::AVERAGE], true)) {
            throw new \InvalidArgumentException(
                "Invalid MarkLine type: {$type} (expected one of: " . implode(', ', [self::MIN, self::MAX, self::AVERAGE]) . ')',
            );
        }

        $values = array_values($data);
        $value = match ($type) {
            self::MIN     => min($values),
            self::MAX     => max($values),
            self::AVERAGE => array_sum($values) / count($values),
        };
        $label = $type;

        return new self(Finite::assert((float) $value), $label, self::validatedStyle($style));
    }

    /**
     * Short-form factory for a minimum reference line.
     * @param list<int|float> $data
     */
    public static function min(array $data, string $style = self::STYLE_DASHED): self
    {
        return self::fromDataset($data, self::MIN, $style);
    }

    /**
     * Short-form factory for a maximum reference line.
     * @param list<int|float> $data
     */
    public static function max(array $data, string $style = self::STYLE_DASHED): self
    {
        return self::fromDataset($data, self::MAX, $style);
    }

    /**
     * Short-form factory for an average reference line.
     * @param list<int|float> $data
     */
    public static function average(array $data, string $style = self::STYLE_DASHED): self
    {
        return self::fromDataset($data, self::AVERAGE, $style);
    }

    /**
     * Reject style spellings the render pass could not rasterise, naming the
     * accepted set so the caller can fix it without opening this file.
     */
    private static function validatedStyle(string $style): string
    {
        if (!in_array($style, self::STYLES, true)) {
            throw new \InvalidArgumentException(
                "Invalid MarkLine style: {$style} (expected one of: " . implode(', ', self::STYLES) . ')',
            );
        }

        return $style;
    }
}
