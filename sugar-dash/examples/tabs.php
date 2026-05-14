<?php
declare(strict_types=1);
require_once __DIR__ . '/../vendor/autoload.php';

use SugarCraft\Dash\Components\Tabs\Tabs;
use SugarCraft\Dash\Grid\ChartDataPoint;
use SugarCraft\Dash\Grid\Options;
use SugarCraft\Dash\Grid\ItemOptions;

// Tab navigation
$component = Tabs::new([["label" => "Tab 1"], ["label" => "Tab 2"], ["label" => "Tab 3"]]);
$component->setSize(60, 15);
echo $component->render();
