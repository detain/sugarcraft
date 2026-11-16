<?php

declare(strict_types=1);

namespace SugarCraft\Charts;

/**
 * Line-art vocabulary for {@see MarkLine} reference annotations.
 *
 * The library already models its other closed vocabularies as enums
 * (Picture\Protocol, Chart\Position); mark-line styles were the odd one
 * out — string constants beside real enum types, a cognitive split the
 * type system could carry instead. LineStyle is the only style vocabulary
 * the mark-line surfaces accept; the former MarkLine::STYLE_* string
 * spellings were retired after their alias window (sugar-charts C2).
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
