<?php
declare(strict_types=1);
require_once __DIR__ . '/../vendor/autoload.php';

use SugarCraft\Dash\Grid\WordCloud;
use SugarCraft\Dash\Grid\ChartDataPoint;
use SugarCraft\Dash\Grid\Options;
use SugarCraft\Dash\Grid\ItemOptions;

// Word cloud
$component = WordCloud::new(["PHP" => 10, "JavaScript" => 8, "Python" => 6, "Go" => 5, "Rust" => 4]);
$component->setSize(60, 15);
echo $component->render();
