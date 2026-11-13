<?php

declare(strict_types=1);

namespace SugarCraft\Charts;

/**
 * Line-art vocabulary for {@see MarkLine} reference annotations.
 *
 * The library already models its other closed vocabularies as enums
 * (Picture\Protocol, Chart\Position); mark-line styles were the odd one
 * out — string constants beside real enum types, a cognitive split the
 * type system could carry instead. LineStyle is the canonical form; the
 * legacy MarkLine::STYLE_* spellings remain accepted as inputs at every
 * style-taking surface (deprecated aliases, alias window per house law),
 * so old call sites keep rendering byte-identically.
 *
 * No upstream equivalent (ntcharts has no mark lines): SugarCraft
 * extension after the ECharts/Plotly lineStyle pattern.
 */
enum LineStyle: string
{
    case Solid  = 'solid';
    case Dashed = 'dashed';
    case Dotted = 'dotted';
}
