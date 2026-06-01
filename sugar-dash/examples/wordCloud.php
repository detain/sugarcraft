<?php
declare(strict_types=1);
require_once __DIR__ . '/../vendor/autoload.php';

use SugarCraft\Dash\Plot\Chart\WordCloud;
use SugarCraft\Dash\Layout\Grid\ChartDataPoint;
use SugarCraft\Dash\Layout\Grid\Options;
use SugarCraft\Dash\Layout\Grid\ItemOptions;

// Word cloud
$component = WordCloud::new(["PHP" => 10, "JavaScript" => 8, "Python" => 6, "Go" => 5, "Rust" => 4]);
$component->setSize(60, 15);
echo $component->render();
