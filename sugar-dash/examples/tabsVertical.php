<?php
declare(strict_types=1);
require_once __DIR__ . '/../vendor/autoload.php';

use SugarCraft\Dash\Components\Tabs\TabsVertical;
use SugarCraft\Dash\Grid\ChartDataPoint;
use SugarCraft\Dash\Grid\Options;
use SugarCraft\Dash\Grid\ItemOptions;

// Vertical tabs
$component = TabsVertical::new([["label" => "Overview"], ["label" => "Settings"], ["label" => "Help"]]);
$component->setSize(60, 15);
echo $component->render();
