<?php

declare(strict_types=1);

namespace SugarCraft\Table;

/**
 * A single table row wrapping RowData + optional styles.
 *
 * Port of Evertras/bubble-table Row.
 *
 * @see https://github.com/Evertras/bubble-table
 */
final class Row
{
    public readonly RowData $data;
    public readonly string $style;       // row-level ANSI style
    public readonly bool $zebra;         // apply zebra striping
    public readonly ?int $zebraStyleIndex; // which alternating style

    private function __construct(
        RowData $data,
        string $style = '',
        bool $zebra = false,
        ?int $zebraStyleIndex = null,
    ) {
        $this->data             = $data;
        $this->style            = $style;
        $this->zebra            = $zebra;
        $this->zebraStyleIndex  = $zebraStyleIndex;
    }

    public static function new(RowData $data): self
    {
        return new self(data: $data);
    }

    public function withStyle(string $ansiStyle): self
    {
        return new self(data: $this->data, style: $ansiStyle, zebra: $this->zebra, zebraStyleIndex: $this->zebraStyleIndex);
    }

    /**
     * Enable zebra striping for this row with the given stripe selector.
     *
     * The model is two-stripe: $styleIndex 1 picks the odd stripe, index 0
     * (and any other value) picks the even stripe — an index of 2 or higher
     * behaves exactly like 0. Table-level {@see Table::withZebra()} must
     * also be enabled for any striping to render.
     */
    public function withZebra(int $styleIndex = 0): self
    {
        return new self(data: $this->data, style: $this->style, zebra: true, zebraStyleIndex: $styleIndex);
    }
}
