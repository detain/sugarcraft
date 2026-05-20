<?php

declare(strict_types=1);

namespace SugarCraft\Charts;

/**
 * A horizontal reference-line annotation for charts. Mirrors ntcharts'
 * `MarkLine` concept — renders a dashed or solid line at a fixed Y value
 * (Min / Max / Average of the dataset) across the full plot width.
 *
 * MarkLine is immutable and composes into any chart that accepts
 * annotation collections.
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

    private function __construct(
        public readonly float $value,
        public readonly string $label,
        public readonly string $style,
    ) {}

    /**
     * Create a mark line at an explicit value.
     */
    public static function at(float $value, string $label = '', string $style = self::STYLE_DASHED): self
    {
        return new self($value, $label, $style);
    }

    /**
     * Derive a MarkLine from a dataset's Min, Max, or Average.
     *
     * @param list<int|float> $data
     * @throws \InvalidArgumentException  if $type is not MIN/MAX/AVERAGE or data is empty
     */
    public static function fromDataset(array $data, string $type, string $style = self::STYLE_DASHED): self
    {
        if ($data === []) {
            throw new \InvalidArgumentException('Cannot derive MarkLine from empty dataset');
        }
        if (!in_array($type, [self::MIN, self::MAX, self::AVERAGE], true)) {
            throw new \InvalidArgumentException("Invalid MarkLine type: {$type}");
        }

        $values = array_values($data);
        $value = match ($type) {
            self::MIN     => min($values),
            self::MAX     => max($values),
            self::AVERAGE => array_sum($values) / count($values),
        };
        $label = $type;

        return new self((float) $value, $label, $style);
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
}
