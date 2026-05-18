<?php

declare(strict_types=1);

/**
 * @deprecated Use SugarCraft\Dash\Plot\Chart\ChartDataPoint
 */
namespace SugarCraft\Dash\Grid;

use SugarCraft\Dash\Plot\Chart\ChartDataPoint as CanonicalChartDataPoint;

class_alias(CanonicalChartDataPoint::class, __NAMESPACE__ . '\ChartDataPoint');
