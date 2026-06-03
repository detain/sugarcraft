<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Components\Card;

use SugarCraft\Core\Util\Ansi;
use SugarCraft\Core\Util\Color;
use SugarCraft\Core\Util\ColorProfile;
use SugarCraft\Core\Util\Width;

/**
 * A definition list: aligned `label : value` rows with a placeholder for
 * missing values.
 *
 * The label column is auto-aligned to the widest label so the separators
 * line up. A null value renders as the placeholder ("—" by default) in a
 * subdued color, which is the common "Unknown"/"missing" affordance in
 * the candy-query admin panels (ServerInfoCard, ServerStatusPage,
 * VariablesPage header rows).
 *
 * Implements Sizer so it composes directly inside a Card: the card sets
 * the available inner width and the value column truncates to fit.
 */
final class DefinitionList implements \SugarCraft\Dash\Foundation\Sizer
{
    private ?int $width = null;
    private ?int $height = null;

    /**
     * @param list<array{0:string,1:?string}> $rows label/value pairs
     */
    public function __construct(
        private readonly array $rows = [],
        private readonly string $separator = ' : ',
        private readonly string $placeholder = '—',
        private readonly ?Color $labelColor = null,
        private readonly ?Color $valueColor = null,
        private readonly ?Color $placeholderColor = null,
    ) {}

    /**
     * Create an empty definition list with default styling.
     */
    public static function new(): self
    {
        return new self(
            rows: [],
            separator: ' : ',
            placeholder: '—',
            labelColor: Color::hex('#6C7086'),
            valueColor: Color::hex('#CDD6F4'),
            placeholderColor: Color::hex('#45475A'),
        );
    }

    /**
     * Create a definition list from a label => value map.
     *
     * @param array<string,?string> $items
     */
    public static function fromMap(array $items): self
    {
        $rows = [];
        foreach ($items as $label => $value) {
            $rows[] = [(string) $label, $value];
        }
        return self::new()->withRows($rows);
    }

    /**
     * Append a label/value row. A null value renders as the placeholder.
     */
    public function row(string $label, ?string $value): self
    {
        $rows = $this->rows;
        $rows[] = [$label, $value];
        return $this->cloneWith(rows: $rows);
    }

    /**
     * Replace all rows.
     *
     * @param list<array{0:string,1:?string}> $rows
     */
    public function withRows(array $rows): self
    {
        return $this->cloneWith(rows: $rows);
    }

    /**
     * Set the label/value separator (default " : ").
     */
    public function withSeparator(string $separator): self
    {
        return $this->cloneWith(separator: $separator);
    }

    /**
     * Set the placeholder shown for null values (default "—").
     */
    public function withPlaceholder(string $placeholder): self
    {
        return $this->cloneWith(placeholder: $placeholder);
    }

    /**
     * Set the label color.
     */
    public function withLabelColor(?Color $color): self
    {
        return $this->cloneWith(labelColor: $color, labelColorSet: true);
    }

    /**
     * Set the value color.
     */
    public function withValueColor(?Color $color): self
    {
        return $this->cloneWith(valueColor: $color, valueColorSet: true);
    }

    /**
     * Set the color used for the null placeholder.
     */
    public function withPlaceholderColor(?Color $color): self
    {
        return $this->cloneWith(placeholderColor: $color, placeholderColorSet: true);
    }

    /**
     * Set the allocated dimensions for this list.
     */
    public function setSize(int $width, int $height): \SugarCraft\Dash\Foundation\Sizer
    {
        $clone = clone $this;
        $clone->width = $width;
        $clone->height = $height;
        return $clone;
    }

    /**
     * Calculate the natural dimensions of this list.
     *
     * @return array{0:int,1:int} [width, height]
     */
    public function getInnerSize(): array
    {
        $labelWidth = $this->labelColumnWidth();
        $sepWidth = Width::string($this->separator);

        $valueWidth = 0;
        foreach ($this->rows as [$label, $value]) {
            $shown = $value ?? $this->placeholder;
            $valueWidth = max($valueWidth, Width::string($shown));
        }

        $width = $this->width ?? ($labelWidth + $sepWidth + $valueWidth);
        $height = max(1, count($this->rows));

        return [$width, $height];
    }

    /**
     * Render the definition list, one row per line.
     */
    public function render(): string
    {
        if ($this->rows === []) {
            return '';
        }

        $labelWidth = $this->labelColumnWidth();
        $sepWidth = Width::string($this->separator);
        $valueBudget = null;
        if ($this->width !== null) {
            $valueBudget = max(0, $this->width - $labelWidth - $sepWidth);
        }

        $lines = [];
        foreach ($this->rows as [$label, $value]) {
            $labelPad = $labelWidth - Width::string($label);
            $labelStr = $label . ($labelPad > 0 ? str_repeat(' ', $labelPad) : '');
            $labelStr = $this->colored($labelStr, $this->labelColor);

            $isMissing = $value === null;
            $shown = $value ?? $this->placeholder;
            if ($valueBudget !== null && Width::string($shown) > $valueBudget) {
                $shown = Width::truncate($shown, $valueBudget);
            }
            $valueStr = $this->colored($shown, $isMissing ? $this->placeholderColor : $this->valueColor);

            $lines[] = $labelStr . $this->separator . $valueStr;
        }

        return implode("\n", $lines);
    }

    /**
     * Width of the widest label, used to align the separator column.
     */
    private function labelColumnWidth(): int
    {
        $max = 0;
        foreach ($this->rows as [$label]) {
            $max = max($max, Width::string($label));
        }
        return $max;
    }

    private function colored(string $str, ?Color $color): string
    {
        if ($color === null) {
            return $str;
        }
        return $color->toFg(ColorProfile::TrueColor) . $str . Ansi::reset();
    }

    /**
     * Clone with selected fields overridden. Nullable color fields use a
     * paired *Set flag so an explicit null is distinguishable from "keep".
     */
    private function cloneWith(
        ?array $rows = null,
        ?string $separator = null,
        ?string $placeholder = null,
        ?Color $labelColor = null,
        bool $labelColorSet = false,
        ?Color $valueColor = null,
        bool $valueColorSet = false,
        ?Color $placeholderColor = null,
        bool $placeholderColorSet = false,
    ): self {
        $clone = new self(
            rows: $rows ?? $this->rows,
            separator: $separator ?? $this->separator,
            placeholder: $placeholder ?? $this->placeholder,
            labelColor: $labelColorSet ? $labelColor : $this->labelColor,
            valueColor: $valueColorSet ? $valueColor : $this->valueColor,
            placeholderColor: $placeholderColorSet ? $placeholderColor : $this->placeholderColor,
        );
        $clone->width = $this->width;
        $clone->height = $this->height;
        return $clone;
    }
}
