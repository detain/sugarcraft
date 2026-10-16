<?php
declare(strict_types=1);
require_once __DIR__ . '/../vendor/autoload.php';

use SugarCraft\Dashboard\Plot\Chart\WordCloud;
use SugarCraft\Dashboard\Layout\Grid\ChartDataPoint;
use SugarCraft\Dashboard\Layout\Grid\Options;
use SugarCraft\Dashboard\Layout\Grid\ItemOptions;

// Word cloud
$component = WordCloud::new(["PHP" => 10, "JavaScript" => 8, "Python" => 6, "Go" => 5, "Rust" => 4]);
$component->setSize(60, 15);
echo $component->render();
