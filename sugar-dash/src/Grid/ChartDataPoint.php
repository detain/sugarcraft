<?php

declare(strict_types=1);

// Re-export for backward compatibility — canonical class moved to Plot\Chart\ChartDataPoint.
namespace SugarCraft\Dash\Grid;

class_alias(\SugarCraft\Dash\Plot\Chart\ChartDataPoint::class, __NAMESPACE__ . '\ChartDataPoint');
