<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Plot\Chart;

/**
 * Box-drawing border characters for bordered chart components.
 *
 * Literal-twin quintuple extracted from CandlestickChart, Funnel, OHLC,
 * Partition and Waterfall under E731 round-85 per-family follow-through:
 * all five copies (method AND docblock) were byte-identical. A trait
 * rather than a base class because every consumer is final (AGENTS.md:
 * public classes final unless extension is contract) and the helper is a
 * pure function of the using class's own private string $style property
 * — precedent: ChartGridGeometry (r7 `79abe8461`).
 *
 * The sibling border idiom in these five files — getInnerSize() — is
 * deliberately NOT here: each class returns its own default dimensions
 * (and Funnel/Waterfall compute a data-derived height), so only the
 * `?? 65`-style numerals look shared; merging would change behavior.
 */
trait ChartBorderStyle
{
    /**
     * Get the style characters for the border.
     *
     * @return array{0:string, 1:string, 2:string, 3:string, 4:string, 5:string}
     */
    private function getStyleChars(): array
    {
        return match ($this->style) {
            'double' => ['╔', '╗', '╚', '╝', '═', '║'],
            'rounded' => ['╭', '╮', '╰', '╯', '─', '│'],
            'single' => ['┌', '┐', '└', '┘', '─', '│'],
            'bold' => ['┏', '┓', '┗', '┛', '━', '┃'],
            'empty' => [' ', ' ', ' ', ' ', ' ', ' '],
            default => ['╭', '╮', '╰', '╯', '─', '│'],
        };
    }
}
