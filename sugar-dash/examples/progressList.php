<?php
declare(strict_types=1);
require_once __DIR__ . '/../vendor/autoload.php';

use SugarCraft\Dash\Components\System\ProgressList;
use SugarCraft\Dash\Grid\ChartDataPoint;
use SugarCraft\Dash\Layout\Grid\Options;
use SugarCraft\Dash\Layout\Grid\ItemOptions;

// Progress list
$component = ProgressList::new([["label" => "Task 1", "progress" => 100], ["label" => "Task 2", "progress" => 50], ["label" => "Task 3", "progress" => 75]]);
$component->setSize(60, 15);
echo $component->render();
