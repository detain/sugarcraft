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
 * somebody else's `view()` call. The canonical style vocabulary is the
 * {@see LineStyle} enum; the deprecated `STYLE_*` string constants are
 * still accepted and normalize to their case.
 */
final class MarkLine
{
    /**
     * Built-in line type spellings.
     *
     * @deprecated since CH-17 — the canonical vocabulary is the
     *             {@see LineStyle} enum. These strings stay accepted at
     *             every style-taking surface (alias window per house law)
     *             and are pinned to the enum's values by MarkLineStyleTest.
     */
    public const STYLE_SOLID  = 'solid';
    public const STYLE_DASHED  = 'dashed';
    public const STYLE_DOTTED  = 'dotted';

    /** Standard reference-line identifiers used by {@see fromDataset()}. */
    public const MIN     = 'min';
    public const MAX     = 'max';
    public const AVERAGE = 'average';

    /** @var list<string> every accepted legacy style spelling (error-message vocabulary). */
    private const STYLES = [self::STYLE_SOLID, self::STYLE_DASHED, self::STYLE_DOTTED];

    private function __construct(
        public readonly float $value,
        public readonly string $label,
        public readonly LineStyle $style,
    ) {}

    /**
     * Create a mark line at an explicit value.
     *
     * @param string|LineStyle $style canonical enum case, or a legacy
     *                                STYLE_* spelling (deprecated alias,
     *                                normalized here)
     * @throws \InvalidArgumentException when $value is NaN/±INF or $style
     *                                  is not a LineStyle case/spelling
     */
    public static function at(float $value, string $label = '', string|LineStyle $style = LineStyle::Dashed): self
    {
        return new self(Finite::assert($value), UntrustedText::guard($label), self::validatedStyle($style));
    }

    /**
     * Derive a MarkLine from a dataset's Min, Max, or Average.
     *
     * @param list<int|float> $data
     * @param string|LineStyle $style enum case or legacy STYLE_* spelling
     * @throws \InvalidArgumentException  if $type is not MIN/MAX/AVERAGE, data is empty,
     *                                    the derived value is non-finite, or $style is
     *                                    not a LineStyle case/spelling
     */
    public static function fromDataset(array $data, string $type, string|LineStyle $style = LineStyle::Dashed): self
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

        return new self(Finite::assert((float) $value), $label, self::validatedStyle($style));
    }

    /**
     * Short-form factory for a minimum reference line.
     * @param list<int|float> $data
     */
    public static function min(array $data, string|LineStyle $style = LineStyle::Dashed): self
    {
        return self::fromDataset($data, self::MIN, $style);
    }

    /**
     * Short-form factory for a maximum reference line.
     * @param list<int|float> $data
     */
    public static function max(array $data, string|LineStyle $style = LineStyle::Dashed): self
    {
        return self::fromDataset($data, self::MAX, $style);
    }

    /**
     * Short-form factory for an average reference line.
     * @param list<int|float> $data
     */
    public static function average(array $data, string|LineStyle $style = LineStyle::Dashed): self
    {
        return self::fromDataset($data, self::AVERAGE, $style);
    }

    /**
     * Reject style values the render pass could not rasterize, naming the
     * accepted set so the caller can fix it without opening this file.
     * Enum cases pass through; legacy string spellings are the deprecated
     * alias form and normalize to their case.
     */
    private static function validatedStyle(string|LineStyle $style): LineStyle
    {
        if ($style instanceof LineStyle) {
            return $style;
        }

        $case = LineStyle::tryFrom($style);
        if ($case === null) {
            throw new \InvalidArgumentException(Lang::t('markline.invalid_style', [
                'style' => $style,
                'allowed' => implode(', ', self::STYLES),
            ]));
        }

        return $case;
    }
}
