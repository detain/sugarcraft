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
    public readonly bool $zebra;         // explicit odd-stripe opt-in (ST-28)

    private function __construct(
        RowData $data,
        string $style = '',
        bool $zebra = false,
    ) {
        $this->data   = $data;
        $this->style  = $style;
        $this->zebra  = $zebra;
    }

    public static function new(RowData $data): self
    {
        return new self(data: $data);
    }

    public function withStyle(string $ansiStyle): self
    {
        return new self(data: $this->data, style: $ansiStyle, zebra: $this->zebra);
    }

    /**
     * Opt this row into (or out of) the ODD zebra stripe, overriding its
     * positional parity.
     *
     * ST-28 (finding 028): this used to take an int "stripe selector" that
     * offered a fake two-state model — only 0 and 1 ever differed, every
     * other value behaved as 0, and 0 was indistinguishable from leaving an
     * even-position row at its positional stripe. A bool says exactly what
     * the state can mean: true pins the odd stripe, false restores
     * positional parity. Pre-1.0 hard switch (Go-parity port, consumer
     * census: no caller passed a non-default index). Table-level
     * {@see Table::withZebra()} must also be enabled to render any stripe.
     */
    public function withZebra(bool $on = true): self
    {
        return new self(data: $this->data, style: $this->style, zebra: $on);
    }
}
