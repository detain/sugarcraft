<?php

declare(strict_types=1);

namespace SugarCraft\Charts;

use SugarCraft\Charts\Lang;

use SugarCraft\Charts\Support\Finite;
use SugarCraft\Charts\Support\UntrustedText;

/**
 * A horizontal reference-line annotation value object. SugarCraft
 * extension after the Plotly/ECharts markLine pattern — the research
 * matrix lists mark lines in none of the surveyed terminal libraries
 * (docs/research/libraries/sugar-charts-research.md), so there is no
 * upstream twin to mirror. A solid, dashed, or dotted line at a fixed Y value
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
 * and an unknown style would otherwise surface as a throw buried inside
 * somebody else's `view()` call. The style vocabulary is the
 * {@see LineStyle} enum, so an unknown style is a type error at the call site.
 */
final class MarkLine
{
    /** Standard reference-line identifiers used by {@see fromDataset()}. */
    public const MIN     = 'min';
    public const MAX     = 'max';
    public const AVERAGE = 'average';

    private function __construct(
        public readonly float $value,
        public readonly string $label,
        public readonly LineStyle $style,
    ) {}

    /**
     * Create a mark line at an explicit value.
     *
     * @throws \InvalidArgumentException when $value is NaN/±INF
     */
    public static function at(float $value, string $label = '', LineStyle $style = LineStyle::Dashed): self
    {
        return new self(Finite::assert($value), UntrustedText::guard($label), $style);
    }

    /**
     * Derive a MarkLine from a dataset's Min, Max, or Average.
     *
     * @param list<int|float> $data
     * @throws \InvalidArgumentException  if $type is not MIN/MAX/AVERAGE, data is empty,
     *                                    or the derived value is non-finite
     */
    public static function fromDataset(array $data, string $type, LineStyle $style = LineStyle::Dashed): self
    {
        if ($data === []) {
            throw new \InvalidArgumentException(Lang::t('markline.empty_dataset'));
        }
        if (!in_array($type, [self::MIN, self::MAX, self::AVERAGE], true)) {
            throw new \InvalidArgumentException(Lang::t('markline.invalid_type', [
                'type' => $type,
                'allowed' => implode(', ', [self::MIN, self::MAX, self::AVERAGE]),
            ]));
        }

        $values = array_values($data);
        $value = match ($type) {
            self::MIN     => min($values),
            self::MAX     => max($values),
            self::AVERAGE => array_sum($values) / count($values),
        };
        $label = $type;

        return new self(Finite::assert((float) $value), $label, $style);
    }

    /**
     * Short-form factory for a minimum reference line.
     * @param list<int|float> $data
     */
    public static function min(array $data, LineStyle $style = LineStyle::Dashed): self
    {
        return self::fromDataset($data, self::MIN, $style);
    }

    /**
     * Short-form factory for a maximum reference line.
     * @param list<int|float> $data
     */
    public static function max(array $data, LineStyle $style = LineStyle::Dashed): self
    {
        return self::fromDataset($data, self::MAX, $style);
    }

    /**
     * Short-form factory for an average reference line.
     * @param list<int|float> $data
     */
    public static function average(array $data, LineStyle $style = LineStyle::Dashed): self
    {
        return self::fromDataset($data, self::AVERAGE, $style);
    }
}
