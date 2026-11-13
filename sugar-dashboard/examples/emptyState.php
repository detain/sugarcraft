<?php
declare(strict_types=1);
require_once __DIR__ . '/../vendor/autoload.php';

use SugarCraft\Dashboard\Components\Feedback\EmptyState;
use SugarCraft\Dashboard\Plot\Chart\ChartDataPoint;
use SugarCraft\Dashboard\Layout\Grid\Options;
use SugarCraft\Dashboard\Layout\Grid\ItemOptions;

// Empty state placeholder
$component = EmptyState::new("No data available");
$component = $component->setSize(60, 15);
echo $component->render();
