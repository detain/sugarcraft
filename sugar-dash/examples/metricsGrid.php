<?php
declare(strict_types=1);
require_once __DIR__ . '/../vendor/autoload.php';

use SugarCraft\Dash\Grid\MetricsGrid;
use SugarCraft\Dash\Grid\ChartDataPoint;
use SugarCraft\Dash\Grid\Options;
use SugarCraft\Dash\Grid\ItemOptions;

// Metrics grid
$component = MetricsGrid::new([["label" => "Users", "value" => "1.2K"], ["label" => "Revenue", "value" => "\$45K"]]);
$component->setSize(60, 15);
echo $component->render();
